<?php

declare(strict_types=1);

namespace Slimify\Compression;

use Random\RandomException;
use Throwable;
use Slimify\Support\ConcurrencyLock;
use Slimify\Support\DeferredQueue;
use Slimify\Support\LibraryStats;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\ProtectedDirectory;
use Slimify\Support\Settings;
use Slimify\Support\Site;
use Slimify\Webp\WebpService;
use Slimify\Offload\OffloadAdapter;

final class CompressionService
{
    public const string META_FLAG = '_slimify_compressed';
    public const string META_STATS = '_slimify_compression_stats';
    public const string META_WEBP = '_slimify_webp';
    public const string META_ORIGINALS = '_slimify_original_backups';
    public const string META_RESTORED = '_slimify_restored_at';
    public const string META_PROCESSING = '_slimify_processing';
    public const string META_QUEUE_ATTEMPTS = '_slimify_queue_attempts';
    public const string OPTION_BACKUP_PATH = 'slimify_backup_path';

    private const int PROCESSING_STALE_SECONDS = 600;

    private const int QUEUE_MAX_ATTEMPTS = 3;

    private static bool $suppressMetadataCompress = false;

    private string|false|null $backupRootCache = null;

    private int $backupRootForBlog = -1;

    private string $lastOutcome = 'none';

    /** True while restore regenerates metadata. */
    public static function isMetadataCompressSuppressed(): bool
    {
        return self::$suppressMetadataCompress;
    }

    /** Run without re-queueing compress on metadata. */
    private static function withMetadataCompressSuppressed(callable $callback): mixed
    {
        $previous = self::$suppressMetadataCompress;
        self::$suppressMetadataCompress = true;
        try {
            return $callback();
        } finally {
            self::$suppressMetadataCompress = $previous;
        }
    }

    /** Constructor. */
    public function __construct(
        private readonly DriverResolver $resolver,
        private readonly WebpService $webpService,
        private readonly PathGuard $pathGuard,
        private readonly Settings $settings,
        private readonly ConcurrencyLock $lock = new ConcurrencyLock(),
        private readonly DeferredQueue $queue = new DeferredQueue(),
    ) {
    }

    /** Outcome of the last attachment run. */
    public function lastOutcome(): string
    {
        return $this->lastOutcome;
    }

    /** Mark the attachment as processing. */
    public function markProcessing(int $attachmentId): void
    {
        if ($attachmentId <= 0 || $this->isLocked($attachmentId)) {
            return;
        }

        update_post_meta($attachmentId, self::META_PROCESSING, (string) time());
    }

    /** Clear the processing marker. */
    public function clearProcessing(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        delete_post_meta($attachmentId, self::META_PROCESSING);
    }

    /** True if still processing. */
    public function isProcessing(int $attachmentId): bool
    {
        if ($attachmentId <= 0) {
            return false;
        }

        // Finished is not processing.
        if ($this->isFlagged($attachmentId)) {
            $stats = get_post_meta($attachmentId, self::META_STATS, true);
            if (! is_array($stats) || ($stats['status'] ?? '') !== 'partial') {
                $this->clearProcessing($attachmentId);

                return false;
            }

            return true;
        }

        $stats = get_post_meta($attachmentId, self::META_STATS, true);
        if (is_array($stats) && ($stats['status'] ?? '') === 'partial') {
            return true;
        }

        $raw = get_post_meta($attachmentId, self::META_PROCESSING, true);
        if ($raw === '' || $raw === false || $raw === null) {
            return false;
        }

        $started = is_numeric($raw) ? (int) $raw : 0;
        if ($started > 0 && (time() - $started) > self::PROCESSING_STALE_SECONDS) {
            $this->clearProcessing($attachmentId);

            return false;
        }

        return true;
    }

    /** Queue compression after upload. */
    public function queueUploadCompression(int $attachmentId): void
    {
        $this->lastOutcome = 'none';

        if ($attachmentId <= 0) {
            return;
        }

        if ($this->isLocked($attachmentId)) {
            $this->lastOutcome = 'already';
            $this->clearProcessing($attachmentId);

            return;
        }

        $mime = (string) get_post_mime_type($attachmentId);
        if (! Limits::isSupportedMime($mime)) {
            $this->clearProcessing($attachmentId);

            return;
        }

        $this->markProcessing($attachmentId);
        if (! $this->queue->push($attachmentId)) {
            // Enqueue failed: clear processing.
            $this->clearProcessing($attachmentId);
            $this->lastOutcome = 'failed';

            return;
        }
        $this->lastOutcome = 'deferred';
    }

    /** Deferred queue instance. */
    public function queue(): DeferredQueue
    {
        return $this->queue;
    }

    /** Drain the deferred queue. */
    public function drainQueue(int $maxItems = Limits::QUEUE_DRAIN_MAX, ?OffloadAdapter $offload = null): int
    {
        $maxItems = max(1, min(20, $maxItems));
        $started = microtime(true);
        $processed = 0;

        while ($processed < $maxItems) {
            if ((microtime(true) - $started) >= Limits::QUEUE_DRAIN_BUDGET_SECONDS) {
                break;
            }

            $ids = $this->queue->peek(1);
            if ($ids === []) {
                break;
            }

            $attachmentId = $ids[0];
            if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
                $this->queue->remove($attachmentId);
                continue;
            }

            if ($this->isFlagged($attachmentId) && ! $this->isIncompleteFlag($attachmentId)) {
                $this->clearProcessing($attachmentId);
                $this->clearQueueAttempts($attachmentId);
                $this->queue->remove($attachmentId);
                ++$processed;
                continue;
            }

            $mime = (string) get_post_mime_type($attachmentId);
            if (! Limits::isSupportedMime($mime)) {
                $this->clearProcessing($attachmentId);
                $this->clearQueueAttempts($attachmentId);
                $this->queue->remove($attachmentId);
                ++$processed;
                continue;
            }

            if ($offload !== null) {
                $result = $offload->compressAttachment($attachmentId, false);
                $outcome = $this->mapOffloadDrainResult($attachmentId, $result);
            } else {
                $metadata = wp_get_attachment_metadata($attachmentId);
                if (! is_array($metadata)) {
                    $metadata = [];
                }

                $metadata = $this->compressAttachmentMetadata($metadata, $attachmentId);
                $outcome = $this->lastOutcome;

                if ($outcome === 'done' || $outcome === 'partial' || $outcome === 'already' || $outcome === 'skipped') {
                    wp_update_attachment_metadata($attachmentId, $metadata);
                }
            }

            // Partial stays queued.
            if ($outcome === 'busy' || $outcome === 'deferred') {
                break;
            }

            if ($outcome === 'partial') {
                $this->clearQueueAttempts($attachmentId);
                ++$processed;
                continue;
            }

            if ($outcome === 'failed' || $outcome === 'none') {
                if ($this->recordQueueFailure($attachmentId)) {
                    // Hit attempt limit.
                    ++$processed;
                    continue;
                }
                ++$processed;
                break;
            }

            $this->clearQueueAttempts($attachmentId);
            $this->queue->remove($attachmentId);
            ++$processed;
        }

        if ($this->queue->count() > 0) {
            $this->queue->scheduleDrain();
        }

        return $processed;
    }

    /** Map an offload result to a queue outcome. */
    private function mapOffloadDrainResult(int $attachmentId, array $result): string
    {
        if (! empty($result['busy'])) {
            return 'busy';
        }

        if ($this->isFlagged($attachmentId)) {
            // Full finish (including soft-ineligible / unavailable) — slot can leave the queue.
            $this->clearProcessing($attachmentId);

            return 'done';
        }

        $stats = get_post_meta($attachmentId, self::META_STATS, true);
        if (is_array($stats) && ($stats['status'] ?? '') === 'partial') {
            return 'partial';
        }

        if ($result['ok']) {
            // No finish flag (e.g. empty work with no meta write).
            return 'skipped';
        }

        $this->clearProcessing($attachmentId);

        if (! empty($result['permanent'])) {
            LibraryStats::invalidate();

            return 'skipped';
        }

        return 'failed';
    }

    /** Compress one file and optionally create WebP. */
    private function compressFile(string $filePath, bool $generateWebp = true, ?string $webpEncodeFrom = null): array
    {
        $result = [
            'compressed' => false,
            'webp' => null,
            'bytes_before' => 0,
            'bytes_after' => 0,
            'skipped' => false,
            'driver' => null,
        ];

        if (! $this->pathGuard->isAllowed($filePath)) {
            $result['skipped'] = true;

            return $result;
        }

        clearstatcache(true, $filePath);
        $before = filesize($filePath);
        $result['bytes_before'] = $before !== false ? $before : 0;
        $result['bytes_after'] = $result['bytes_before'];

        if (! Limits::isFileWithinLimits($filePath)) {
            $result['skipped'] = true;

            return $result;
        }

        $mime = (string) (wp_check_filetype($filePath)['type'] ?? '');
        if ($mime === '') {
            $finfo = function_exists('mime_content_type') ? mime_content_type($filePath) : false;
            $mime = is_string($finfo) ? $finfo : '';
        }

        if ($mime === '' || ! Limits::isSupportedMime($mime)) {
            $result['skipped'] = true;

            return $result;
        }

        $wantWebp = $generateWebp && $this->settings->generateWebp();
        $encodeFrom = null;
        $ownedSnapshot = null;

        if ($wantWebp) {
            if (
                is_string($webpEncodeFrom)
                && $webpEncodeFrom !== ''
                && is_file($webpEncodeFrom)
                && is_readable($webpEncodeFrom)
            ) {
                $encodeFrom = $webpEncodeFrom;
            } else {
                $ownedSnapshot = $this->snapshotForWebpEncode($filePath, $mime);
                $encodeFrom = $ownedSnapshot;
            }
        }

        try {
            $driver = $this->resolver->resolve($mime);
            if ($driver !== null) {
                try {
                    $result['compressed'] = $driver->compress($filePath);
                    if ($result['compressed']) {
                        $result['driver'] = $this->driverName($driver::class);
                    }
                } catch ( Throwable) {
                    $result['compressed'] = false;
                }
            }

            clearstatcache(true, $filePath);
            $after = filesize($filePath);
            $result['bytes_after'] = $after !== false ? $after : $result['bytes_before'];

            if ($wantWebp && is_string($encodeFrom) && $encodeFrom !== '') {
                $webp = $this->webpService->generateSibling($filePath, $encodeFrom);
                if ($webp !== null) {
                    $result['webp'] = $webp;
                }
            }
        } finally {
            if (is_string($ownedSnapshot) && $ownedSnapshot !== '' && is_file($ownedSnapshot)) {
                @unlink($ownedSnapshot);
            }
        }

        return $result;
    }

    /** Snapshot a file for WebP encoding. */
    private function snapshotForWebpEncode(string $filePath, string $mime): ?string
    {
        $sourceReal = realpath($filePath);
        if ($sourceReal === false || ! is_file($sourceReal) || ! is_readable($sourceReal)) {
            return null;
        }

        $ext = '.bin';
        if (str_contains($mime, 'png')) {
            $ext = '.png';
        } elseif (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) {
            $ext = '.jpg';
        } else {
            $fromName = strtolower((string) pathinfo($sourceReal, PATHINFO_EXTENSION));
            if ($fromName !== '') {
                $ext = '.' . $fromName;
            }
        }

        $base = function_exists('wp_tempnam')
            ? wp_tempnam('slimify-webp-src')
            : (string) tempnam(sys_get_temp_dir(), 'slimify-webp-src');

        if ($base === '' || $base === '0') {
            return null;
        }

        $dest = $base . $ext;
        if ($dest !== $base && is_file($base)) {
            @unlink($base);
        }

        if (! @copy($sourceReal, $dest)) {
            @unlink($dest);

            return null;
        }

        return $dest;
    }

    /** Compress an attachment and its sizes. */
    public function compressAttachmentMetadata(array $metadata, int $attachmentId): array
    {
        $this->lastOutcome = 'none';

        if ($attachmentId > 0 && $this->isLocked($attachmentId)) {
            $this->lastOutcome = 'already';
            $this->clearProcessing($attachmentId);

            return $metadata;
        }

        if (! $this->lock->acquire(false)) {
            if ($attachmentId > 0) {
                $this->markProcessing($attachmentId);
                if ($this->queue->push($attachmentId)) {
                    $this->lastOutcome = 'deferred';
                } else {
                    $this->clearProcessing($attachmentId);
                    $this->lastOutcome = 'busy';
                }
            } else {
                $this->lastOutcome = 'busy';
            }

            return $metadata;
        }

        $started = microtime(true);
        Limits::raiseRequestTimeLimit();

        try {
            if ($attachmentId > 0 && $this->isLocked($attachmentId)) {
                $this->lastOutcome = 'already';
                $this->clearProcessing($attachmentId);

                return $metadata;
            }

            if ($attachmentId > 0) {
                $this->markProcessing($attachmentId);
            }

            $file = isset($metadata['file']) ? (string) $metadata['file'] : '';
            if ($file === '') {
                $attached = get_attached_file($attachmentId);
                if (is_string($attached) && $attached !== '') {
                    $file = $attached;
                }
            }

            $uploads = wp_upload_dir();
            $basedir = (string) ($uploads['basedir'] ?? '');
            $baseReal = $basedir !== '' ? realpath($basedir) : false;
            if ($basedir === '' || $baseReal === false) {
                if ($attachmentId > 0) {
                    $this->clearProcessing($attachmentId);
                }
                $this->lastOutcome = 'failed';

                return $metadata;
            }

            $paths = [];
            $webpMap = [];
            $storedOriginals = get_post_meta($attachmentId, self::META_ORIGINALS, true);
            $originals = is_array($storedOriginals) ? $storedOriginals : [];

            $priorStats = get_post_meta($attachmentId, self::META_STATS, true);
            $processedKeys = [];
            if (is_array($priorStats) && isset($priorStats['processed_keys']) && is_array($priorStats['processed_keys'])) {
                foreach ($priorStats['processed_keys'] as $k) {
                    if (is_string($k) || is_int($k)) {
                        $processedKeys[(string) $k] = true;
                    }
                }
            }

            $stats = [
                'version' => 1,
                'run_id' => bin2hex(random_bytes(8)),
                'processed_at' => time(),
                'files' => 0,
                'compressed_files' => 0,
                'skipped_files' => 0,
                'bytes_before' => 0,
                'bytes_after' => 0,
                'bytes_saved' => 0,
                'webp_files' => 0,
                'drivers' => [],
                'duration_ms' => 0,
                'original_backups' => 0,
                'processed_keys' => array_keys($processedKeys),
            ];

            $priorWebp = get_post_meta($attachmentId, self::META_WEBP, true);
            if (is_array($priorWebp)) {
                foreach ($priorWebp as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $webpMap[$k] = $v;
                    }
                }
            }

            $fullPath = $this->resolveFullPath($baseReal, $file);
            if ($fullPath !== null) {
                $paths['full'] = $fullPath;
            }

            // Backup full original before mutation.
            if ($this->settings->keepOriginals() && $attachmentId > 0) {
                if ($this->backupRoot() === null) {
                    $this->markUnavailable(
                        $attachmentId,
                        'backup_root_unavailable'
                    );

                    return $metadata;
                }

                $backupSource = $this->resolveFullSourcePath($attachmentId, $fullPath);
                if ($backupSource === null) {
                    $this->clearProcessing($attachmentId);
                    $this->lastOutcome = 'failed';

                    return $metadata;
                }

                if (! isset($originals['full']) || ! is_array($originals['full'])) {
                    $backup = $this->backupOriginal($basedir, $backupSource);
                    if ($backup === null) {
                        $this->clearProcessing($attachmentId);
                        $this->lastOutcome = 'failed';

                        return $metadata;
                    }
                    $originals = ['full' => $backup];
                } else {
                    // Full-only backup.
                    $fullOnly = ['full' => $originals['full']];
                    $this->unlinkOrphanOriginals($originals, $fullOnly);
                    $originals = $fullOnly;
                }
            } else {
                $originals = [];
            }

            $subdir = '';
            if ($fullPath !== null) {
                $subdir = trailingslashit(dirname(ltrim(str_replace($baseReal, '', $fullPath), '/\\')));
                if ($subdir === './' || $subdir === '/') {
                    $subdir = '';
                }
            } elseif ($file !== '' && str_contains($file, '/')) {
                $subdir = trailingslashit(dirname($file));
            }

            $sizeCount = 0;
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $sizeName => $sizeData) {
                    if ($sizeCount >= Limits::MAX_SIZES_PER_ATTACHMENT) {
                        break;
                    }
                    if (! is_array($sizeData) || empty($sizeData['file'])) {
                        continue;
                    }
                    $sizeFile = (string) $sizeData['file'];
                    if ($sizeFile === '' || str_contains($sizeFile, '..')) {
                        continue;
                    }
                    $candidate = $baseReal . '/' . $subdir . $sizeFile;
                    $real = realpath($candidate);
                    if (
                        $real !== false
                        && is_file($real)
                        && str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)
                    ) {
                        $paths[(string) $sizeName] = $real;
                        ++$sizeCount;
                    }
                }
            }

            $budgetExceeded = false;
            foreach ($paths as $key => $path) {
                if ((microtime(true) - $started) >= Limits::ATTACHMENT_BUDGET_SECONDS) {
                    $budgetExceeded = true;
                    break;
                }

                if (isset($processedKeys[$key])) {
                    continue;
                }

                // Full encodes from backup; sizes use a temp snapshot.
                $webpEncodeFrom = $key === 'full'
                    ? $this->resolveOriginalBackupPath($originals['full'] ?? null)
                    : null;
                $outcome = $this->compressFile($path, true, $webpEncodeFrom);
                ++$stats['files'];
                $stats['bytes_before'] += $outcome['bytes_before'];
                $stats['bytes_after'] += $outcome['bytes_after'];
                if ($outcome['compressed']) {
                    ++$stats['compressed_files'];
                }
                if ($outcome['skipped']) {
                    ++$stats['skipped_files'];
                }
                if ($outcome['driver'] !== null) {
                    $stats['drivers'][$outcome['driver']] = true;
                }
                if ($outcome['webp'] !== null) {
                    $webpMap[$key] = basename($outcome['webp']);
                    ++$stats['webp_files'];
                }

                $processedKeys[$key] = true;

                if ($key !== 'full' && isset($metadata['sizes'][$key]) && is_array($metadata['sizes'][$key])) {
                    clearstatcache(true, $path);
                    $sizeBytes = filesize($path);
                    if ($sizeBytes !== false) {
                        $metadata['sizes'][$key]['filesize'] = $sizeBytes;
                    }
                }
            }

            if ($fullPath !== null) {
                clearstatcache(true, $fullPath);
                $fullBytes = filesize($fullPath);
                if ($fullBytes !== false) {
                    $metadata['filesize'] = $fullBytes;
                }
            }

            if ($webpMap !== []) {
                $metadata['slimify_webp'] = $webpMap;
            } else {
                unset($metadata['slimify_webp']);
            }

            if ($attachmentId > 0 && ($stats['files'] > 0 || $processedKeys !== [] || $originals !== [])) {
                if (is_array($priorStats) && isset($priorStats['status']) && $priorStats['status'] === 'partial') {
                    $stats['bytes_before'] += (int) ($priorStats['bytes_before'] ?? 0);
                    $stats['bytes_after'] += (int) ($priorStats['bytes_after'] ?? 0);
                    $stats['compressed_files'] += (int) ($priorStats['compressed_files'] ?? 0);
                    $stats['skipped_files'] += (int) ($priorStats['skipped_files'] ?? 0);
                    $stats['files'] += (int) ($priorStats['files'] ?? 0);
                    $stats['webp_files'] = count($webpMap);
                    if (isset($priorStats['drivers']) && is_array($priorStats['drivers'])) {
                        foreach ($priorStats['drivers'] as $d) {
                            if (is_string($d)) {
                                $stats['drivers'][$d] = true;
                            }
                        }
                    }
                }

                $stats['bytes_saved'] = max(0, $stats['bytes_before'] - $stats['bytes_after']);
                $stats['drivers'] = array_keys($stats['drivers']);
                $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                $stats['original_backups'] = isset($originals['full']) ? 1 : 0;
                $stats['processed_keys'] = array_keys($processedKeys);

                $allDone = ! $budgetExceeded && count($processedKeys) >= count($paths);
                $hadWork = $stats['compressed_files'] > 0 || $stats['webp_files'] > 0;
                $attempted = max(0, $stats['files'] - $stats['skipped_files']);

                if ($budgetExceeded && ! $allDone) {
                    $stats['status'] = 'partial';
                    $this->lastOutcome = 'partial';
                    update_post_meta($attachmentId, self::META_STATS, $stats);
                    $this->markProcessing($attachmentId);
                    if (! $this->queue->push($attachmentId)) {
                        // Partial kept for a later drain.
                        $this->queue->scheduleDrain();
                    }
                    if ($originals !== []) {
                        update_post_meta($attachmentId, self::META_ORIGINALS, $originals);
                    }
                    if ($webpMap !== []) {
                        update_post_meta($attachmentId, self::META_WEBP, $webpMap);
                    }
                } elseif ($hadWork || $attempted > 0) {
                    // Mark finished (lock until restore when a backup exists).
                    $stats['status'] = $hadWork ? 'done' : 'skipped';
                    if (! $hadWork) {
                        $stats['reason'] = 'no_gain';
                    }
                    update_post_meta($attachmentId, self::META_FLAG, '1');
                    update_post_meta($attachmentId, self::META_STATS, $stats);
                    delete_post_meta($attachmentId, self::META_RESTORED);
                    $this->clearProcessing($attachmentId);
                    if ($originals !== []) {
                        update_post_meta($attachmentId, self::META_ORIGINALS, $originals);
                    } else {
                        delete_post_meta($attachmentId, self::META_ORIGINALS);
                    }
                    if ($webpMap !== []) {
                        update_post_meta($attachmentId, self::META_WEBP, $webpMap);
                    } else {
                        delete_post_meta($attachmentId, self::META_WEBP);
                    }
                    $this->lastOutcome = $hadWork ? 'done' : 'skipped';
                } else {
                    // Over limits: finish for bulk without a hard lock.
                    $stats['status'] = 'ineligible';
                    $stats['reason'] = 'limits';
                    $stats['original_backups'] = 0;
                    update_post_meta($attachmentId, self::META_FLAG, '1');
                    update_post_meta($attachmentId, self::META_STATS, $stats);
                    $this->clearProcessing($attachmentId);
                    if ($originals !== []) {
                        $this->unlinkOrphanOriginals($originals, []);
                        delete_post_meta($attachmentId, self::META_ORIGINALS);
                    }
                    delete_post_meta($attachmentId, self::META_WEBP);
                    $this->lastOutcome = 'skipped';
                }
                LibraryStats::invalidate();
            } elseif ($attachmentId > 0) {
                // No sources: finish so the queue moves on.
                $this->markUnavailable($attachmentId, 'no_source_files');
            }

            return $metadata;
        } finally {
            $this->lock->release();
        }
    }

    /** Restore the full original and regenerate sizes. */
    public function restoreAttachment(int $attachmentId): array
    {
        $stored = get_post_meta($attachmentId, self::META_ORIGINALS, true);
        if (! is_array($stored) || $stored === []) {
            return [
                'ok' => false,
                'message' => __('No original backup is available for this attachment.', 'slimify'),
                'files' => 0,
            ];
        }

        $fullEntry = isset($stored['full']) && is_array($stored['full']) ? $stored['full'] : null;
        if ($fullEntry === null) {
            return [
                'ok' => false,
                'message' => __(
                    'This backup is incomplete under the full-only policy. Re-upload or compress again with Keep originals enabled.',
                    'slimify'
                ),
                'files' => 0,
            ];
        }

        if (! $this->lock->acquire(false)) {
            return [
                'ok' => false,
                'message' => __('Another compression job is running. Try again shortly.', 'slimify'),
                'files' => 0,
            ];
        }

        Limits::raiseRequestTimeLimit();

        $temporaryFiles = [];

        try {
            $uploads = wp_upload_dir();
            $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            $baseReal = $basedir !== '' ? realpath($basedir) : false;
            $backupRoot = $this->backupRoot();
            if ($baseReal === false || $backupRoot === null) {
                return [
                    'ok' => false,
                    'message' => __('The uploads or protected backup directory is unavailable.', 'slimify'),
                    'files' => 0,
                ];
            }

            if (empty($fullEntry['backup']) || empty($fullEntry['target'])) {
                return [
                    'ok' => false,
                    'message' => __('Original backup metadata is invalid.', 'slimify'),
                    'files' => 0,
                ];
            }

            $backup = $this->safePath($backupRoot, (string) $fullEntry['backup'], true);
            $target = $this->safePath($baseReal, (string) $fullEntry['target'], false);
            if ($backup === null || $target === null || ! is_file($backup)) {
                return [
                    'ok' => false,
                    'message' => __('An original backup file is missing.', 'slimify'),
                    'files' => 0,
                ];
            }

            // Remove WebPs before restore.
            $this->deleteAttachmentWebps($attachmentId, $baseReal);

            $temp = dirname($target) . DIRECTORY_SEPARATOR . '.slimify-restore-' . bin2hex(random_bytes(8));
            if (! @copy($backup, $temp)) {
                return [
                    'ok' => false,
                    'message' => __('Unable to stage an original file for restoration.', 'slimify'),
                    'files' => 0,
                ];
            }
            $temporaryFiles[] = $temp;

            if (! wp_mkdir_p(dirname($target))) {
                return [
                    'ok' => false,
                    'message' => __('Unable to prepare the restore destination.', 'slimify'),
                    'files' => 0,
                ];
            }

            if (! @rename($temp, $target)) {
                return [
                    'ok' => false,
                    'message' => __('Unable to replace a compressed file with its original.', 'slimify'),
                    'files' => 0,
                ];
            }
            array_pop($temporaryFiles);

            // Re-point attachment if required.
            $attached = get_attached_file($attachmentId, true);
            $attachedReal = is_string($attached) && $attached !== '' ? realpath($attached) : false;
            $targetReal = realpath($target);
            if ($attachedReal === false || $targetReal === false || $attachedReal !== $targetReal) {
                update_attached_file($attachmentId, $target);
            }

            $newMeta = self::withMetadataCompressSuppressed(static function () use ($attachmentId, $target) {
                return wp_generate_attachment_metadata($attachmentId, $target);
            });

            if (! is_array($newMeta)) {
                $newMeta = [];
            }
            unset($newMeta['slimify_webp']);
            wp_update_attachment_metadata($attachmentId, $newMeta);

            // Full-only backup meta.
            $this->unlinkOrphanOriginals($stored, ['full' => $fullEntry]);
            update_post_meta($attachmentId, self::META_ORIGINALS, ['full' => $fullEntry]);

            delete_post_meta($attachmentId, self::META_FLAG);
            delete_post_meta($attachmentId, self::META_STATS);
            delete_post_meta($attachmentId, self::META_WEBP);
            update_post_meta($attachmentId, self::META_RESTORED, time());
            $this->clearProcessing($attachmentId);
            LibraryStats::invalidate();

            return [
                'ok' => true,
                'message' => __('Original restored and image sizes regenerated.', 'slimify'),
                'files' => 1,
            ];
        } finally {
            $this->cleanupFiles($temporaryFiles);
            $this->lock->release();
        }
    }

    /** Delete WebP siblings for an attachment. */
    private function deleteAttachmentWebps(int $attachmentId, string $baseReal): void
    {
        $map = get_post_meta($attachmentId, self::META_WEBP, true);
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (! is_array($map) && is_array($metadata) && isset($metadata['slimify_webp']) && is_array($metadata['slimify_webp'])) {
            $map = $metadata['slimify_webp'];
        }

        $paths = [];
        $attached = get_attached_file($attachmentId, true);
        if (is_string($attached) && $attached !== '') {
            $paths[] = $attached;
        }
        if (is_array($metadata) && ! empty($metadata['file'])) {
            $full = $this->resolveFullPath($baseReal, (string) $metadata['file']);
            if ($full !== null) {
                $paths[] = $full;
            }
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                $subdir = '';
                if (str_contains((string) $metadata['file'], '/')) {
                    $subdir = trailingslashit(dirname((string) $metadata['file']));
                }
                foreach ($metadata['sizes'] as $sizeData) {
                    if (! is_array($sizeData) || empty($sizeData['file'])) {
                        continue;
                    }
                    $sizeFile = (string) $sizeData['file'];
                    if ($sizeFile === '' || str_contains($sizeFile, '..')) {
                        continue;
                    }
                    $candidate = $baseReal . '/' . $subdir . $sizeFile;
                    $real = realpath($candidate);
                    if ($real !== false) {
                        $paths[] = $real;
                    }
                }
            }
        }

        if (is_array($map)) {
            $dir = is_string($attached) && $attached !== '' ? dirname($attached) : '';
            foreach ($map as $basename) {
                if (! is_string($basename) || $basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
                    continue;
                }
                if ($dir !== '') {
                    $paths[] = $dir . DIRECTORY_SEPARATOR . $basename;
                }
            }
        }

        foreach (array_unique($paths) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            if (is_string($webp) && $webp !== $path && is_file($webp)) {
                @unlink($webp);
            }
            if (str_ends_with(strtolower($path), '.webp') && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Resolve the full file used for backup. */
    private function resolveFullSourcePath(int $attachmentId, ?string $fullPath): ?string
    {
        if (function_exists('wp_get_original_image_path')) {
            $original = wp_get_original_image_path($attachmentId);
            if (is_string($original) && $original !== '' && is_file($original)) {
                $real = realpath($original);
                if ($real !== false) {
                    return $real;
                }
            }
        }

        if ($fullPath !== null && is_file($fullPath)) {
            return $fullPath;
        }

        $attached = get_attached_file($attachmentId, true);
        if (is_string($attached) && $attached !== '' && is_file($attached)) {
            $real = realpath($attached);

            return $real !== false ? $real : null;
        }

        return null;
    }

    /** Remove backup files not kept in meta. */
    private function unlinkOrphanOriginals(array $previous, array $keep): void
    {
        $backupRoot = $this->backupRoot();
        if ($backupRoot === null) {
            return;
        }

        $keepPaths = [];
        foreach ($keep as $entry) {
            if (is_array($entry) && ! empty($entry['backup'])) {
                $keepPaths[(string) $entry['backup']] = true;
            }
        }

        foreach ($previous as $entry) {
            if (! is_array($entry) || empty($entry['backup'])) {
                continue;
            }
            $rel = (string) $entry['backup'];
            if (isset($keepPaths[$rel])) {
                continue;
            }
            $path = $this->safePath($backupRoot, $rel, true);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /** Remove backups and plugin meta. */
    public function purgeAttachmentArtifacts(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $stored = get_post_meta($attachmentId, self::META_ORIGINALS, true);
        $backupRoot = $this->backupRoot();
        if (is_array($stored) && $backupRoot !== null) {
            foreach ($stored as $entry) {
                if (! is_array($entry) || empty($entry['backup'])) {
                    continue;
                }
                $path = $this->safePath($backupRoot, (string) $entry['backup'], true);
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }

        delete_post_meta($attachmentId, self::META_FLAG);
        delete_post_meta($attachmentId, self::META_STATS);
        delete_post_meta($attachmentId, self::META_WEBP);
        delete_post_meta($attachmentId, self::META_ORIGINALS);
        delete_post_meta($attachmentId, self::META_RESTORED);
        delete_post_meta($attachmentId, self::META_PROCESSING);
        delete_post_meta($attachmentId, self::META_QUEUE_ATTEMPTS);
        LibraryStats::invalidate();
    }

    /** Mark finished so bulk/queue can move on without real compress. */
    public function markUnavailable(int $attachmentId, string $reason): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        update_post_meta($attachmentId, self::META_FLAG, '1');
        update_post_meta($attachmentId, self::META_STATS, [
            'version' => 1,
            'processed_at' => time(),
            'status' => 'unavailable',
            'reason' => $reason,
            'files' => 0,
        ]);
        $this->clearProcessing($attachmentId);
        $this->lastOutcome = 'skipped';
        LibraryStats::invalidate();
    }

    /** Resolved backup root, or null. */
    public function resolvedBackupRoot(): ?string
    {
        return $this->backupRoot();
    }

    /** True if the attachment is marked finished. */
    public function isFlagged(int $attachmentId): bool
    {
        $flag = get_post_meta($attachmentId, self::META_FLAG, true);

        return $flag === '1' || $flag === 1 || $flag === true;
    }

    /** True if another compress needs a restore first. */
    public function isLocked(int $attachmentId): bool
    {
        if (! $this->isFlagged($attachmentId)) {
            return false;
        }

        $stats = get_post_meta($attachmentId, self::META_STATS, true);
        $status = is_array($stats) ? (string) ($stats['status'] ?? '') : '';

        if ($status === 'ineligible') {
            return false;
        }

        if ($status === 'unavailable') {
            return true;
        }

        // No backup: unlock for re-compress.
        if (! $this->hasOriginalBackup($attachmentId)) {
            return false;
        }

        return true;
    }

    /** True if a full original backup exists. */
    public function hasOriginalBackup(int $attachmentId): bool
    {
        if ($attachmentId <= 0) {
            return false;
        }

        $stored = get_post_meta($attachmentId, self::META_ORIGINALS, true);
        if (! is_array($stored) || empty($stored['full']) || ! is_array($stored['full'])) {
            return false;
        }

        return ! empty($stored['full']['backup']);
    }

    /** Clear the finished flag so compress can run again. */
    public function clearFinishedFlag(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        delete_post_meta($attachmentId, self::META_FLAG);
    }

    /** Clear unavailable when backup directory is writable again. */
    public function clearUnavailableIfRepairable(int $attachmentId): bool
    {
        if ($attachmentId <= 0 || ! $this->isFlagged($attachmentId)) {
            return false;
        }

        $stats = get_post_meta($attachmentId, self::META_STATS, true);
        if (! is_array($stats) || ($stats['status'] ?? '') !== 'unavailable') {
            return false;
        }

        $reason = (string) ($stats['reason'] ?? '');
        if ($reason !== 'backup_root_unavailable') {
            return false;
        }

        if ($this->backupRoot() === null) {
            return false;
        }

        $this->clearFinishedFlag($attachmentId);
        delete_post_meta($attachmentId, self::META_STATS);
        $this->clearProcessing($attachmentId);
        $this->clearQueueAttempts($attachmentId);
        LibraryStats::invalidate();

        return true;
    }

    /** True if the run is only partial. */
    private function isIncompleteFlag(int $attachmentId): bool
    {
        $stats = get_post_meta($attachmentId, self::META_STATS, true);

        return is_array($stats) && ($stats['status'] ?? '') === 'partial';
    }

    /** Count a hard failure; after the limit mark unavailable and dequeue.
     * @return bool True if terminal.
     */
    private function recordQueueFailure(int $attachmentId): bool
    {
        $attempts = (int) get_post_meta($attachmentId, self::META_QUEUE_ATTEMPTS, true);
        ++$attempts;
        update_post_meta($attachmentId, self::META_QUEUE_ATTEMPTS, $attempts);

        if ($attempts < self::QUEUE_MAX_ATTEMPTS) {
            return false;
        }

        $this->markUnavailable($attachmentId, 'queue_exhausted');
        $this->clearQueueAttempts($attachmentId);
        $this->queue->remove($attachmentId);

        return true;
    }

    private function clearQueueAttempts(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        delete_post_meta($attachmentId, self::META_QUEUE_ATTEMPTS);
    }

    /** Resolve the full file path under uploads. */
    private function resolveFullPath(string $baseReal, string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:\\\\#', $file) === 1) {
            $real = realpath($file);
            if (
                $real === false
                || ! is_file($real)
                || ! str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)
            ) {
                return null;
            }

            return $real;
        }

        $candidate = $baseReal . '/' . ltrim($file, '/');
        $real = realpath($candidate);
        if (
            $real === false
            || ! is_file($real)
            || ! str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $real;
    }

    /** Resolve a stored backup path. */
    private function resolveOriginalBackupPath(mixed $entry): ?string
    {
        if (! is_array($entry) || empty($entry['backup'])) {
            return null;
        }

        if (! isset($entry['storage']) || $entry['storage'] !== 'protected') {
            return null;
        }

        $relative = (string) $entry['backup'];
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $root = $this->backupRoot();
        if ($root === null || $root === '') {
            return null;
        }

        $path = $this->safePath($root, $relative, true);
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    /** Back up an original before compression.
     * @throws RandomException
     */
    private function backupOriginal(string $basedir, string $source): ?array
    {
        $baseReal = realpath($basedir);
        $sourceReal = realpath($source);
        $backupRoot = $this->backupRoot();
        if (
            $baseReal === false
            || $sourceReal === false
            || $backupRoot === null
            || ! str_starts_with($sourceReal, $baseReal . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        $targetRelative = ltrim(str_replace('\\', '/', substr($sourceReal, strlen($baseReal))), '/');
        $backupRelative = $this->backupRelativePath($targetRelative);
        $backupPath = $backupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $backupRelative);

        if (! is_file($backupPath)) {
            if (! wp_mkdir_p(dirname($backupPath))) {
                return null;
            }

            $temp = $backupPath . '.tmp-' . bin2hex(random_bytes(6));
            if (! @copy($sourceReal, $temp) || ! @rename($temp, $backupPath)) {
                @unlink($temp);

                return null;
            }
        }

        return [
            'backup' => $backupRelative,
            'target' => $targetRelative,
            'storage' => 'protected',
        ];
    }

    /** Relative path for a backup file.
     * @throws RandomException
     */
    private function backupRelativePath(string $targetRelative): string
    {
        $secret = Site::storageSecret();
        if ($secret === '') {
            return $targetRelative . '.original';
        }

        return $targetRelative . '.' . substr(hash_hmac('sha256', $targetRelative, $secret), 0, 16) . '.original';
    }

    /** Resolved backup root, or null. */
    private function backupRoot(): ?string
    {
        $blogId = Site::id();
        if ($this->backupRootCache !== null && $this->backupRootForBlog === $blogId) {
            return $this->backupRootCache === false ? null : $this->backupRootCache;
        }

        $stored = get_option(self::OPTION_BACKUP_PATH, '');
        if (is_string($stored) && $stored !== '' && ! Site::isOwnedStoragePath($stored)) {
            delete_option(self::OPTION_BACKUP_PATH);
        }

        $default = Site::backupRootCandidates()[0];
        $candidate = (string) apply_filters('slimify_backup_directory', $default, $blogId);
        if ($candidate === '' || ! Site::isOwnedStoragePath($candidate)) {
            $candidate = $default;
        }

        $real = $this->prepareBackupRoot($candidate);
        if ($real === null) {
            $this->backupRootCache = false;
            $this->backupRootForBlog = $blogId;

            return null;
        }

        if (get_option(self::OPTION_BACKUP_PATH, '') !== $real) {
            update_option(self::OPTION_BACKUP_PATH, $real, false);
        }
        $this->backupRootCache = $real;
        $this->backupRootForBlog = $blogId;

        return $real;
    }

    /** Create and harden the backup root. */
    private function prepareBackupRoot(string $candidate): ?string
    {
        if ($candidate === '' || ! Site::isOwnedStoragePath($candidate)) {
            return null;
        }

        if (! is_dir($candidate) && ! wp_mkdir_p($candidate)) {
            return null;
        }

        $real = realpath($candidate);
        if ($real === false || ! is_writable($real) || Site::isCriticalDirectory($real)) {
            return null;
        }

        if (! Site::isOwnedStoragePath($real)) {
            return null;
        }

        ProtectedDirectory::hardenStorageTree($real);

        return $real;
    }

    /** Resolve a path under a base directory. */
    private function safePath(string $baseReal, string $relative, bool $mustExist): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains('/' . $relative . '/', '/../')) {
            return null;
        }

        $path = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($mustExist) {
            $real = realpath($path);

            return (
                $real !== false
                && str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)
            ) ? $real : null;
        }

        $parent = realpath(dirname($path));
        if (
            $parent === false
            || ($parent !== $baseReal && ! str_starts_with($parent, $baseReal . DIRECTORY_SEPARATOR))
        ) {
            return null;
        }

        return $path;
    }

    /** Delete temporary files. */
    private function cleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** Short name for a driver class. */
    private function driverName(string $className): string
    {
        $shortName = strrchr($className, '\\');
        $shortName = $shortName !== false ? substr($shortName, 1) : $className;

        return preg_replace('/Driver$/', '', $shortName) ?: $shortName;
    }
}
