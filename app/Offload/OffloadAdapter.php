<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- real media paths, flock locks, and CLI temp files.

namespace Slimify\Offload;

use Random\RandomException;
use Throwable;
use Slimify\Compression\CompressionService;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\RemoteUrlGuard;
use Slimify\Support\Site;
use Slimify\Webp\WebpService;

use function count;
use function function_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_object;
use function is_string;
use function strlen;


final class OffloadAdapter
{
    /**
     * Attachment IDs whose metadata was already saved this request.
     *
     * @var array<int, bool>
     */
    private static array $metaSynced = [];

    /** Constructor. */
    public function __construct(
        private readonly CompressionService $compressionService,
        private readonly PathGuard $pathGuard,
    ) {
    }

    /** Register hooks. */
    public function register(): void
    {
        // File-list filters from offload plugins (extend via slimify_offload_file_path_hooks).
        $hooks = apply_filters('slimify_offload_file_path_hooks', [
            'as3cf_attachment_file_paths', // WP Offload Media / AS3CF.
        ]);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        foreach ($hooks as $hook) {
            if (!is_string($hook) || $hook === '' || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $hook)) {
                continue;
            }
            add_filter($hook, [$this, 'mergeExtraFilePaths'], 10, 3);
        }

        // Track metadata saves so the universal push does not upload twice.
        add_filter('wp_update_attachment_metadata', [$this, 'recordMetaSync'], PHP_INT_MAX, 2);

        // Most offloaders re-upload when metadata is updated.
        add_filter('slimify_remote_push_handlers', function (mixed $handlers): array {
            $handlers = is_array($handlers) ? $handlers : [];
            $handlers[] = [$this, 'pushViaMetadataUpdate'];

            return $handlers;
        }, 50);

        // Confirm remote WebPs after a deferred offload finishes.
        add_action('slimify_reverify_remote', [$this, 'reverifyRemoteWebps']);

        // Confirm WebPs when WP Offload Media uploads after Slimify has compressed.
        add_action('as3cf_post_upload_item', [$this, 'onOffloaderUploaded']);
    }

    /** Re-verify WebPs after WP Offload Media finishes an upload. */
    public function onOffloaderUploaded(mixed $item): void
    {
        if (!is_object($item) || !method_exists($item, 'source_id')) {
            return;
        }
        if (method_exists($item, 'source_type') && $item->source_type() !== 'media-library') {
            return;
        }

        self::requestReverify((int)$item->source_id(), true);
    }

    /**
     * Queue a remote WebP re-verify.
     * Delivery-triggered requests are limited to once per hour per attachment.
     */
    public static function requestReverify(int $attachmentId, bool $force = false): void
    {
        static $requested = [];
        if ($attachmentId <= 0 || isset($requested[$attachmentId]) || !function_exists('wp_schedule_single_event')) {
            return;
        }

        // Wait until WebPs exist — the offloader may upload before they are ready.
        $map = get_post_meta($attachmentId, CompressionService::META_WEBP, true);
        if (!is_array($map) || $map === []) {
            return;
        }
        $requested[$attachmentId] = true;

        $args = [$attachmentId];
        if ($force) {
            wp_clear_scheduled_hook('slimify_reverify_remote', $args);
        } else {
            $checked = (int)get_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE_CHECKED, true);
            if ($checked > time() - HOUR_IN_SECONDS || wp_next_scheduled('slimify_reverify_remote', $args) !== false) {
                return;
            }
        }

        wp_schedule_single_event(time(), 'slimify_reverify_remote', $args);
    }

    /**
     * Add WebP paths for offload plugins.
     *
     * @param array<int|string, mixed>|mixed $paths
     * @param array<string, mixed>|mixed $meta
     *
     * @return list<string>|array<int|string, string>
     */
    public function mergeExtraFilePaths(mixed $paths, mixed $attachmentId, mixed $meta = []): array
    {
        $paths = is_array($paths) ? $paths : [];
        $attachmentId = (int)$attachmentId;
        $meta = is_array($meta) ? $meta : [];

        $normalised = [];
        foreach ($paths as $key => $path) {
            if (is_string($path) && $path !== '') {
                $normalised[$key] = $path;
            }
        }

        return $this->extraFilePaths($attachmentId, $normalised, $meta);
    }

    /** Paths list including WebP siblings. */
    private function extraFilePaths(int $attachmentId, array $paths = [], array $meta = []): array
    {
        $map = [];
        if (isset($meta['slimify_webp']) && is_array($meta['slimify_webp'])) {
            $map = $meta['slimify_webp'];
        } else {
            $stored = get_post_meta($attachmentId, CompressionService::META_WEBP, true);
            if (is_array($stored)) {
                $map = $stored;
            }
        }

        foreach ($paths as $key => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            if (is_string($webp) && $webp !== $path && is_file($webp) && !in_array($webp, $paths, true)) {
                $webpKey = is_string($key) && $key !== '' ? $key . '.webp' : 'webp_' . count($paths);
                $paths[$webpKey] = $webp;
            }
        }

        $attached = $this->registeredAttachedPath($attachmentId);
        $dir = $attached !== null ? dirname($attached) : '';
        if ($dir !== '') {
            foreach ($map as $sizeKey => $basename) {
                if (!is_string($basename) || $basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
                    continue;
                }
                $webp = $dir . DIRECTORY_SEPARATOR . $basename;
                if (is_file($webp) && !in_array($webp, $paths, true)) {
                    $webpKey = is_string($sizeKey) && $sizeKey !== '' ? $sizeKey . '.webp' : 'webp_' . count($paths);
                    $paths[$webpKey] = $webp;
                }
            }
        }

        $filtered = apply_filters('slimify_attachment_extra_file_paths', $paths, $attachmentId, $meta);

        return is_array($filtered) ? $this->stringifyPathList($filtered) : $this->stringifyPathList($paths);
    }

    /** Restore originals from backup. */
    public function restoreAttachment(int $attachmentId): array
    {
        $result = $this->compressionService->restoreAttachment($attachmentId);
        if (!$result['ok']) {
            return $result;
        }

        $needsRemote = $this->usesRemoteStorage($attachmentId);
        $ok = $this->pushToRemote($attachmentId, 'restore', $needsRemote);
        if ($needsRemote && !$ok) {
            return [
                'ok' => false,
                'message' => __('Originals restored locally, but remote storage could not be updated.', 'slimify'),
                'files' => $result['files'],
            ];
        }

        return [
            'ok' => true,
            'message' => $needsRemote
                ? __('Original restored, sizes regenerated, and remote storage notified.', 'slimify')
                : (string)($result['message'] ?? __('Original restored and image sizes regenerated.', 'slimify')),
            'files' => $result['files'],
        ];
    }

    /** Compress on upload (or offload).
     * @throws RandomException
     */
    public function compressAttachment(int $attachmentId, bool $dryRun = false): array
    {
        $this->compressionService->clearUnavailableIfRepairable($attachmentId);

        if ($this->compressionService->isLocked($attachmentId)) {
            return [
                'ok' => true,
                'message' => __('Already compressed. Restore the original before compressing again.', 'slimify'),
                'files' => 0,
            ];
        }

        // Clear soft flags for a fresh run.
        if ($this->compressionService->isFlagged($attachmentId)) {
            $this->compressionService->clearFinishedFlag($attachmentId);
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $fromRemote = false;

        // Prefer local files.
        if ($this->hasReadableLocalFile($attachmentId, $metadata)) {
            if ($dryRun) {
                return [
                    'ok' => true,
                    'message' => __('Local file present; would compress in place.', 'slimify'),
                    'files' => 1,
                ];
            }

            if (!$this->ensureRegisteredLocalCopy($attachmentId)) {
                return [
                    'ok' => false,
                    'message' => __('Unable to materialise a local copy of the attachment.', 'slimify'),
                    'files' => 0,
                ];
            }
        } else {
            if ($this->canFetchRemote($attachmentId)) {
                if ($dryRun) {
                    return [
                        'ok' => true,
                        'message' => __(
                            'Remote file detected; would fetch, compress, and push back via offload/CDN hooks.',
                            'slimify'
                        ),
                        'files' => 1,
                    ];
                }

                if (!$this->materializeRemoteAttachment($attachmentId, $metadata)) {
                    return [
                        'ok' => false,
                        'message' => __('Failed to fetch the remote original (offloader/URL).', 'slimify'),
                        'files' => 0,
                    ];
                }

                $fromRemote = true;
                $refreshed = wp_get_attachment_metadata($attachmentId);
                if (is_array($refreshed)) {
                    $metadata = $refreshed;
                }
            } else {
                return $this->skipMissingLocal($attachmentId, $dryRun);
            }
        }

        return $this->runLocalCompressPipeline($attachmentId, $metadata, $fromRemote);
    }

    /**
     * Download remote sizes onto the registered local paths for compression.
     * @throws RandomException
     */
    private function materializeRemoteAttachment(int $attachmentId, array $metadata): bool
    {
        Limits::raiseRequestTimeLimit();

        $staged = $this->fetchAttachmentSource($attachmentId, 'full', null);
        if ($staged === null) {
            return false;
        }

        $temps = [];
        if ($staged['owned']) {
            $temps[] = $staged['path'];
        }

        try {
            if (!$this->placePrimaryLocally($attachmentId, $staged['path'])) {
                return false;
            }

            $sizeProcessed = 0;
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $sizeName => $sizeData) {
                    if ($sizeProcessed >= Limits::OFFLOAD_MAX_SIZES) {
                        break;
                    }
                    if (!is_array($sizeData) || empty($sizeData['file'])) {
                        continue;
                    }

                    $sizeDest = $this->localSizeDestination($attachmentId, (string)$sizeData['file']);
                    if ($sizeDest === null) {
                        continue;
                    }

                    // Keep existing local sizes.
                    if (is_file($sizeDest) && filesize($sizeDest) > 0) {
                        ++$sizeProcessed;
                        continue;
                    }

                    $stagedSize = $this->fetchAttachmentSource(
                        $attachmentId,
                        (string)$sizeName,
                        (string)$sizeData['file']
                    );
                    if ($stagedSize === null) {
                        continue;
                    }
                    if ($stagedSize['owned']) {
                        $temps[] = $stagedSize['path'];
                    }

                    if ($this->placeFile($stagedSize['path'], $sizeDest)) {
                        ++$sizeProcessed;
                    }
                }
            }

            return $this->hasRealDiskFile($attachmentId);
        } finally {
            foreach ($temps as $temp) {
                if (!is_string($temp) || $temp === '' || !is_file($temp)) {
                    continue;
                }
                $attached = $this->registeredAttachedPath($attachmentId);
                $attachedReal = is_string($attached) ? realpath($attached) : false;
                $tempReal = realpath($temp);
                if ($tempReal !== false && $attachedReal !== false && $tempReal === $attachedReal) {
                    continue;
                }
                wp_delete_file($temp);
            }
        }
    }

    /** Run compressAttachmentMetadata + remote push handling. */
    private function runLocalCompressPipeline(int $attachmentId, array $metadata, bool $fromRemote): array
    {
        if ($this->compressionService->isIgnored($attachmentId)) {
            $this->compressionService->clearIgnored($attachmentId);
        }

        $metadata = $this->compressionService->compressAttachmentMetadata($metadata, $attachmentId);
        $outcome = $this->compressionService->lastOutcome();

        // Nothing changed — saving would only trigger a needless offload.
        if ($outcome === 'busy' || $outcome === 'deferred') {
            return [
                'ok' => false,
                'message' => __('Compression is busy with another image. Try again shortly.', 'slimify'),
                'files' => 0,
                'busy' => true,
            ];
        }

        wp_update_attachment_metadata($attachmentId, $metadata);

        $needsRemote = $fromRemote || $this->usesRemoteStorage($attachmentId);
        $pushReason = $fromRemote ? 'compress-remote' : 'compress-local';

        if ($outcome === 'partial') {
            $this->pushToRemote($attachmentId, $fromRemote ? 'compress-partial-remote' : 'compress-partial', $needsRemote);

            return [
                'ok' => true,
                'message' => __('Partial compression saved; remaining sizes can finish on the next run.', 'slimify'),
                'files' => 1,
            ];
        }

        if ($outcome === 'already') {
            return [
                'ok' => true,
                'message' => __('Already compressed. Restore the original before compressing again.', 'slimify'),
                'files' => 0,
            ];
        }

        if ($outcome === 'skipped') {
            $flagged = $this->compressionService->isFlagged($attachmentId);
            $locked = $this->compressionService->isLocked($attachmentId);
            $stats = get_post_meta($attachmentId, CompressionService::META_STATS, true);
            $status = is_array($stats) ? (string)($stats['status'] ?? '') : '';

            if ($flagged && $status === 'unavailable') {
                return [
                    'ok' => true,
                    'permanent' => true,
                    'message' => __('Attachment is not compressible (source unavailable); marked finished so bulk can continue.', 'slimify'),
                    'files' => 0,
                ];
            }

            if ($flagged && $status === 'ineligible') {
                return [
                    'ok' => true,
                    'message' => __(
                        'No sizes within compression limits for this attachment; marked finished (can re-try without restore).',
                        'slimify'
                    ),
                    'files' => 0,
                ];
            }

            if ($locked) {
                return [
                    'ok' => true,
                    'message' => __('Within limits but no smaller output; marked as processed.', 'slimify'),
                    'files' => 0,
                ];
            }

            return [
                'ok' => true,
                'message' => __('No compression was applied for this attachment.', 'slimify'),
                'files' => 0,
            ];
        }

        if ($outcome === 'failed' || $outcome === 'none') {
            return [
                'ok' => false,
                'message' => __('Could not keep a protected original backup. Compression was not started.', 'slimify'),
                'files' => 0,
            ];
        }

        if (get_post_meta($attachmentId, CompressionService::META_FLAG, true) !== '1') {
            return [
                'ok' => false,
                'message' => __('Compression did not complete for this attachment.', 'slimify'),
                'files' => 0,
            ];
        }

        $ok = $this->pushToRemote($attachmentId, $pushReason, $needsRemote);
        if (!$ok && $needsRemote) {
            $this->recordRemotePushFailed($attachmentId);

            return [
                'ok' => true,
                'push_ok' => false,
                'message' => $fromRemote
                    ? __(
                        'Compression succeeded locally, but remote push was not confirmed by the offload/CDN stack.',
                        'slimify'
                    )
                    : __(
                        'Compression succeeded, but remote/CDN storage was not confirmed. Local files are compressed.',
                        'slimify'
                    ),
                'files' => 1,
            ];
        }

        return [
            'ok' => true,
            'push_ok' => true,
            'message' => $fromRemote
                ? __('Remote attachment compressed and pushed for offload/CDN sync.', 'slimify')
                : __('Compressed local attachment files.', 'slimify'),
            'files' => 1,
        ];
    }

    /** Stage a local file to compress.
     * @throws RandomException
     */
    private function fetchAttachmentSource(int $attachmentId, string $sizeKey, ?string $sizeFile): ?array
    {
        $hintUrl = $this->sourceUrl($attachmentId, $sizeKey);
        $context = [
            'size' => $sizeKey,
            'size_file' => $sizeFile,
            'url' => $hintUrl,
            'attached' => $this->registeredAttachedPath($attachmentId),
        ];

        /**
         * Local path from a host fetch hook
         *
         * @param string|null $path
         * @param int $attachmentId
         * @param array{size: string, size_file: ?string, url: ?string, attached: ?string} $context
         */
        $provided = apply_filters('slimify_fetch_remote_file', null, $attachmentId, $context);
        if (is_string($provided) && $provided !== '') {
            $copied = $this->copyIntoJailTemp($provided, basename($provided));
            if ($copied !== null) {
                return $copied;
            }
        }

        $filteredAttached = get_attached_file($attachmentId, false);
        if ($sizeKey === 'full' && is_string($filteredAttached) && $filteredAttached !== '') {
            $copied = $this->copyIntoJailTemp($filteredAttached, basename($filteredAttached));
            if ($copied !== null) {
                return $copied;
            }
        }

        // Registered path may be a stream.
        $registered = $this->registeredAttachedPath($attachmentId);
        if ($sizeKey === 'full' && is_string($registered) && $registered !== '') {
            $copied = $this->copyIntoJailTemp($registered, basename(str_replace('\\', '/', $registered)));
            if ($copied !== null) {
                return $copied;
            }
        }

        $url = $hintUrl;
        /**
         * Signed or provider URL when the public URL is not usable
         *
         * @param string|null $url
         * @param int $attachmentId
         * @param array{size: string, size_file: ?string, url: ?string, attached: ?string} $context
         */
        $filteredUrl = apply_filters('slimify_remote_source_url', $url, $attachmentId, $context);
        if (is_string($filteredUrl) && $filteredUrl !== '') {
            $url = $filteredUrl;
        }

        if (!is_string($url) || $url === '') {
            return null;
        }

        if ($this->isSameSiteMediaUrl($url)) {
            return null;
        }

        $downloaded = $this->downloadUrlToJailTemp($url);
        if ($downloaded === null) {
            return null;
        }

        // Reject CDN responses that swap format behind a .jpg/.png URL.
        if (!$this->matchesAttachmentMime($downloaded['path'], $attachmentId)) {
            wp_delete_file($downloaded['path']);

            return null;
        }

        return $downloaded;
    }

    /** True if the file's detected image type matches the attachment's MIME. */
    private function matchesAttachmentMime(string $path, int $attachmentId): bool
    {
        $expected = strtolower((string)get_post_mime_type($attachmentId));
        if ($expected === '') {
            return true;
        }

        $info = @getimagesize($path);
        $actual = is_array($info) && isset($info['mime']) ? strtolower((string)$info['mime']) : '';

        return $actual === $expected;
    }

    /** Push compressed files to remote storage. */
    private function pushToRemote(int $attachmentId, string $reason, bool $needsRemoteWrite): bool
    {
        $local = $this->registeredAttachedPath($attachmentId) ?? '';
        $url = $this->sourceUrl($attachmentId, 'full') ?? '';
        $metadata = wp_get_attachment_metadata($attachmentId);
        $metadata = is_array($metadata) ? $metadata : [];
        $paths = $this->extraFilePaths($attachmentId, $local !== '' ? [$local] : [], $metadata);

        $context = [
            'reason' => $reason,
            'local' => $local,
            'url' => $url,
            'paths' => $paths,
            'metadata' => $metadata,
            'needs_remote_write' => $needsRemoteWrite,
        ];

        /**
         * true/false forces a result; null means not handled here
         *
         * @param bool|null $result
         * @param int $attachmentId
         * @param array<string, mixed> $context
         */
        $filtered = apply_filters('slimify_push_to_remote', null, $attachmentId, $context);
        // After push attempt.
        do_action('slimify_after_push_to_remote', $attachmentId, $context);

        // Optional host push handlers.
        $handlers = apply_filters('slimify_remote_push_handlers', [], $attachmentId, $context);
        $explicitResult = $filtered !== null;
        $acceptedByUniversal = false;
        if (is_array($handlers)) {
            $handlerAccepted = false;
            $handlerRejected = false;
            foreach ($handlers as $handler) {
                if (!is_callable($handler)) {
                    continue;
                }
                try {
                    $handlerResult = $handler($attachmentId, $context);
                    if ($handlerResult === true) {
                        $handlerAccepted = true;
                        $acceptedByUniversal = $handler === [$this, 'pushViaMetadataUpdate'];
                        break;
                    }
                    if ($handlerResult === false) {
                        $handlerRejected = true;
                    }
                } catch (Throwable) {
                    // Ignore handler errors.
                }
            }
            if ($handlerAccepted) {
                $filtered = true;
            } elseif ($filtered === null && $handlerRejected) {
                $filtered = false;
            }
        }

        if (is_bool($filtered)) {
            if ($needsRemoteWrite && $url !== '' && !$this->isSameSiteMediaUrl($url)) {
                $verified = $this->verifyRemoteWebps($attachmentId, $context);

                if ($filtered && $acceptedByUniversal && !$explicitResult && $verified === false) {
                    return false;
                }
            }

            return $filtered;
        }

        if (!$needsRemoteWrite) {
            return $local !== '' && is_file($local);
        }

        $requireConfirm = (bool)apply_filters(
            'slimify_require_remote_push_confirmation',
            true,
            $attachmentId,
            $context
        );

        if (!$requireConfirm) {
            return $local !== '' && is_file($local);
        }

        return false;
    }

    /** Record that an attachment's metadata was saved this request. */
    public function recordMetaSync(mixed $metadata, mixed $postId): mixed
    {
        self::$metaSynced[(int)$postId] = true;

        return $metadata;
    }

    /**
     * Ask any offload plugin that hooks metadata updates to re-upload.
     * WebP siblings are included via the offloader's own file-list filter.
     *
     * @param array<string, mixed> $context
     */
    public function pushViaMetadataUpdate(int $attachmentId, array $context): ?bool
    {
        if (!function_exists('wp_update_attachment_metadata') || !$this->hasExternalMetadataListeners()) {
            return null;
        }

        // Already uploaded this request — do not fire again.
        if (!empty(self::$metaSynced[$attachmentId])) {
            return true;
        }

        $metadata = isset($context['metadata']) && is_array($context['metadata'])
            ? $context['metadata']
            : wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata) || $metadata === []) {
            return null;
        }

        wp_update_attachment_metadata($attachmentId, $metadata);

        return true;
    }

    /** True if something other than Slimify listens on wp_update_attachment_metadata. */
    private function hasExternalMetadataListeners(): bool
    {
        global $wp_filter;

        $hook = $wp_filter['wp_update_attachment_metadata'] ?? null;
        if (!$hook instanceof \WP_Hook) {
            return false;
        }

        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;

                if (
                    is_array($function)
                    && is_object($function[0])
                    && ($function[0] instanceof WebpService || $function[0] instanceof self)
                ) {
                    continue;
                }

                if (is_string($function) && str_starts_with($function, 'Slimify\\')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Confirm each WebP exists on the remote host and store the confirmed set.
     *
     * @param array<string, mixed> $context
     * @return bool|null null if there was nothing to check; otherwise true if any WebP was confirmed
     */
    private function verifyRemoteWebps(int $attachmentId, array $context, bool $reschedule = true): ?bool
    {
        $url = is_string($context['url'] ?? null) ? $context['url'] : '';
        $host = $url !== '' ? wp_parse_url($url, PHP_URL_HOST) : null;
        $path = $url !== '' ? wp_parse_url($url, PHP_URL_PATH) : null;
        $scheme = $url !== '' ? wp_parse_url($url, PHP_URL_SCHEME) : null;
        if (!is_string($host) || $host === '' || !is_string($path) || !is_string($scheme)) {
            return null;
        }

        // Block private or local addresses.
        if (!RemoteUrlGuard::isSafe($scheme . '://' . $host . dirname($path) . '/')) {
            return null;
        }

        $map = get_post_meta($attachmentId, CompressionService::META_WEBP, true);
        if (!is_array($map) || $map === []) {
            return null;
        }

        update_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE_CHECKED, time());

        $previous = get_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, true);
        $known = is_array($previous) && ($previous['host'] ?? '') === $host && is_array($previous['files'] ?? null)
            ? $previous['files']
            : [];

        // Check unconfirmed files first so later runs move past the probe limit.
        $map = array_filter($map, 'is_string');
        $known = array_filter($known, 'is_string');
        $map = array_merge(array_diff($map, $known), array_intersect($map, $known));

        $dir = trailingslashit(dirname($path));
        $confirmed = [];
        $missing = 0;
        $probed = 0;
        $deadline = microtime(true) + Limits::REMOTE_VERIFY_BUDGET_SECONDS;

        foreach ($map as $basename) {
            if (!is_string($basename) || $basename === '' || wp_basename($basename) !== $basename) {
                continue;
            }
            if ($probed >= Limits::REMOTE_VERIFY_MAX_FILES || microtime(true) > $deadline) {
                // Keep known confirmations; treat the rest as missing for a later check.
                if (in_array($basename, $known, true)) {
                    $confirmed[] = $basename;
                } else {
                    ++$missing;
                }
                continue;
            }
            ++$probed;

            $remote = $scheme . '://' . $host . $dir . $basename;
            $response = wp_safe_remote_head($remote, [
                'timeout' => Limits::PROBE_TIMEOUT_SECONDS,
                'redirection' => 0,
            ]);
            if (!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response) === 200) {
                $confirmed[] = $basename;
            } else {
                ++$missing;
            }
        }

        if ($confirmed === []) {
            delete_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE);
        } else {
            update_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, [
                'host'  => $host,
                'files' => $confirmed,
            ]);
            $this->clearRemotePushFailed($attachmentId);
        }

        // Files may still be syncing — schedule one follow-up check.
        if ($missing > 0 && $reschedule && function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'slimify_reverify_remote', [$attachmentId]);
        } elseif ($missing === 0 && function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('slimify_reverify_remote', [$attachmentId]);
        }

        return $confirmed !== [];
    }

    /**
     * Cron follow-up for remote WebPs.
     * One deferred attempt; the next push will verify again if needed.
     */
    public function reverifyRemoteWebps(int $attachmentId): void
    {
        $url = $this->sourceUrl($attachmentId, 'full') ?? '';
        if ($url === '' || $this->isSameSiteMediaUrl($url)) {
            // Record the check so delivery does not keep re-scheduling.
            update_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE_CHECKED, time());
            return;
        }

        $this->verifyRemoteWebps($attachmentId, ['url' => $url], false);
    }

    /** True if a remote source can be fetched. */
    private function canFetchRemote(int $attachmentId): bool
    {
        $probe = apply_filters('slimify_can_fetch_remote', null, $attachmentId);
        if (is_bool($probe)) {
            return $probe;
        }

        $registered = $this->registeredAttachedPath($attachmentId);
        $url = $this->sourceUrl($attachmentId, 'full');
        $viaFilter = apply_filters('slimify_fetch_remote_file', null, $attachmentId, [
            'size' => 'full',
            'size_file' => null,
            'url' => $url,
            'attached' => $registered,
        ]);
        if (is_string($viaFilter) && $viaFilter !== '' && $this->isReadableSource($viaFilter)) {
            return true;
        }

        $filtered = get_attached_file($attachmentId, false);
        if (is_string($filtered) && $filtered !== '' && $this->isReadableSource($filtered)) {
            return true;
        }

        if (is_string($registered) && $registered !== '' && $this->isReadableSource($registered)) {
            return true;
        }

        $context = [
            'size' => 'full',
            'size_file' => null,
            'url' => $url,
            'attached' => $registered,
        ];
        $filteredUrl = apply_filters('slimify_remote_source_url', $url, $attachmentId, $context);
        if (is_string($filteredUrl) && $filteredUrl !== '') {
            $url = $filteredUrl;
        }

        return is_string($url) && $url !== '' && !$this->isSameSiteMediaUrl($url);
    }

    /** True if the attachment uses remote storage. */
    private function usesRemoteStorage(int $attachmentId): bool
    {
        $forced = apply_filters('slimify_uses_remote_storage', null, $attachmentId);
        if (is_bool($forced)) {
            return $forced;
        }

        if (!$this->hasRealDiskFile($attachmentId) && $this->canFetchRemote($attachmentId)) {
            return true;
        }

        return $this->urlIsOffLocalUploads($attachmentId);
    }

    /** True if a readable local or stream source exists. */
    public function hasLocalSource(int $attachmentId): bool
    {
        return $this->hasReadableLocalFile($attachmentId);
    }

    /** True if this row is a same-site library item with no file and no offload source. */
    public function isMissingLocalGhost(int $attachmentId): bool
    {
        return !$this->hasReadableLocalFile($attachmentId) && !$this->canFetchRemote($attachmentId);
    }

    /** True if a readable local file exists. */
    private function hasReadableLocalFile(int $attachmentId, ?array $metadata = null): bool
    {
        return $this->findLocalSourcePath($attachmentId, $metadata) !== null;
    }

    /** Local disk or stream path, or null. Miss-path fallbacks run only after is_file fails. */
    private function findLocalSourcePath(int $attachmentId, ?array $metadata = null): ?string
    {
        $registered = $this->registeredAttachedPath($attachmentId);
        if (is_string($registered) && $registered !== '' && is_file($registered)) {
            return $registered;
        }

        $filtered = get_attached_file($attachmentId, false);
        if (is_string($filtered) && $filtered !== '' && $this->isReadableSource($filtered)) {
            return $filtered;
        }

        if (is_string($registered) && $registered !== '' && $this->isReadableSource($registered)) {
            return $registered;
        }

        if (function_exists('wp_get_original_image_path')) {
            $original = wp_get_original_image_path($attachmentId);
            if (is_string($original) && $original !== '' && is_file($original)) {
                return $original;
            }
        }

        if ($metadata === null) {
            $loaded = wp_get_attachment_metadata($attachmentId);
            $metadata = is_array($loaded) ? $loaded : [];
        }

        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $rel = str_replace('\\', '/', $metadata['file']);
            if ($rel !== '' && !str_contains($rel, '..')) {
                $uploads = wp_upload_dir();
                $basedir = (string)($uploads['basedir'] ?? '');
                if ($basedir !== '') {
                    $candidate = $basedir . '/' . ltrim($rel, '/');
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /** True if a real disk file exists. */
    private function hasRealDiskFile(int $attachmentId): bool
    {
        $path = $this->registeredAttachedPath($attachmentId);

        return is_string($path) && $path !== '' && is_file($path);
    }

    /** Ensure the registered path has a local file.
     * @throws RandomException
     */
    private function ensureRegisteredLocalCopy(int $attachmentId): bool
    {
        $registered = $this->registeredAttachedPath($attachmentId);
        if ($registered === null) {
            return false;
        }

        if (is_file($registered)) {
            return true;
        }

        $source = get_attached_file($attachmentId, false);
        if (!is_string($source) || $source === '' || $source === $registered) {
            $source = $registered;
        }

        if (!$this->isReadableSource($source)) {
            $fetched = $this->fetchAttachmentSource($attachmentId, 'full', null);
            if ($fetched === null) {
                return false;
            }

            $ok = $this->placeFile($fetched['path'], $registered);
            if (
                $fetched['owned']
                && is_file($fetched['path'])
                && realpath($fetched['path']) !== realpath($registered)
            ) {
                wp_delete_file($fetched['path']);
            }

            return $ok;
        }

        return $this->placeFileFromSource($source, $registered);
    }

    /** Registered attached file path. */
    private function registeredAttachedPath(int $attachmentId): ?string
    {
        $path = get_attached_file($attachmentId, true);
        if (!is_string($path) || $path === '') {
            return null;
        }

        return $path;
    }

    /** Source URL for an attachment size. */
    private function sourceUrl(int $attachmentId, string $sizeKey): ?string
    {
        $url = WebpService::withoutRewriting(
            static fn() => $sizeKey === 'full'
                ? wp_get_attachment_url($attachmentId)
                : wp_get_attachment_image_url($attachmentId, $sizeKey)
        );

        if (!is_string($url) || $url === '') {
            return null;
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        // Host-less URLs (e.g. WP-CLI without --url) cannot be fetched or compared.
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }

    /** True if the URL is off local uploads. */
    private function urlIsOffLocalUploads(int $attachmentId): bool
    {
        $url = $this->sourceUrl($attachmentId, 'full');
        if ($url === null) {
            return false;
        }

        return !$this->isSameSiteMediaUrl($url);
    }

    /** True if this URL is this site's uploads or content host+path. */
    private function isSameSiteMediaUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $host = Site::normaliseHost((string)$parts['host']);
        $path = rawurldecode((string)($parts['path'] ?? ''));
        if ($host === '' || $path === '') {
            return false;
        }

        $bases = [];
        $uploads = wp_upload_dir();
        if (!empty($uploads['baseurl'])) {
            $bases[] = (string)$uploads['baseurl'];
        }
        if (function_exists('content_url')) {
            $bases[] = content_url();
        }

        foreach ($bases as $base) {
            $baseParts = wp_parse_url($base);
            if (!is_array($baseParts) || empty($baseParts['host'])) {
                continue;
            }

            if (Site::normaliseHost((string)$baseParts['host']) !== $host) {
                continue;
            }

            $basePath = rtrim(rawurldecode((string)($baseParts['path'] ?? '')), '/');
            if ($basePath === '') {
                continue;
            }

            if ($path === $basePath || str_starts_with($path, $basePath . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Skip a library row whose file is not on disk. */
    private function skipMissingLocal(int $attachmentId, bool $dryRun): array
    {
        if (!$dryRun) {
            $this->compressionService->markIgnored($attachmentId, 'missing_file');
        }

        return [
            'ok' => true,
            'ignored' => true,
            'files' => 0,
            'message' => $dryRun
                ? __('Listed in the media library but the original is not on the server; would skip.', 'slimify')
                : __('Listed in the media library but the original is not on the server. Skipped.', 'slimify'),
        ];
    }

    /** True if the source is readable. */
    private function isReadableSource(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (is_file($path) && is_readable($path)) {
            return true;
        }

        // Stream-readable sources.
        return @is_readable($path);
    }

    /** Copy a file into a jailed temp path.
     * @throws RandomException
     */
    private function copyIntoJailTemp(string $source, string $basename): ?array
    {
        if (!$this->isReadableSource($source)) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $basename));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            $basename = 'slimify-source.bin';
        }

        // Local files stay inside the jail.
        if (is_file($source)) {
            $real = realpath($source);
            if ($real === false || !$this->pathGuard->isAllowed($real)) {
                return null;
            }

            $temp = trailingslashit(get_temp_dir()) . 'slimify-src-' . bin2hex(random_bytes(8)) . '-' . $basename;
            if (@copy($real, $temp) && is_file($temp)) {
                if (!Limits::isFileWebpEligible($temp)) {
                    wp_delete_file($temp);

                    return null;
                }

                if ($this->pathGuard->isAllowed($temp)) {
                    return ['path' => $temp, 'owned' => true];
                }

                wp_delete_file($temp);
            }

            if (!Limits::isFileWebpEligible($real)) {
                return null;
            }

            return ['path' => $real, 'owned' => false];
        }

        $temp = trailingslashit(sys_get_temp_dir()) . 'slimify-' . bin2hex(random_bytes(8)) . '-' . $basename;
        if (!$this->placeFileFromSource($source, $temp)) {
            return null;
        }

        if (!$this->pathGuard->isAllowed($temp) || !Limits::isFileWebpEligible($temp)) {
            wp_delete_file($temp);

            return null;
        }

        return ['path' => $temp, 'owned' => true];
    }

    /** Download a URL into a jailed temp path.
     * @throws RandomException
     */
    private function downloadUrlToJailTemp(string $url): ?array
    {
        $tmp = $this->fetchRemoteToTemp($url);
        if ($tmp === null) {
            return null;
        }

        clearstatcache(true, $tmp);
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > Limits::MAX_FILE_BYTES) {
            wp_delete_file($tmp);

            return null;
        }

        if (!Limits::isFileWebpEligible($tmp)) {
            wp_delete_file($tmp);

            return null;
        }

        $real = realpath($tmp);
        if ($real === false) {
            wp_delete_file($tmp);

            return null;
        }

        if ($this->pathGuard->isAllowed($real)) {
            return ['path' => $real, 'owned' => true];
        }

        $copy = trailingslashit(sys_get_temp_dir()) . 'slimify-' . bin2hex(random_bytes(8)) . '-' . basename($real);
        if (!@copy($real, $copy)) {
            wp_delete_file($real);

            return null;
        }
        wp_delete_file($real);
        $jailed = realpath($copy) ?: $copy;

        if (!$this->pathGuard->isAllowed($jailed)) {
            wp_delete_file($jailed);

            return null;
        }

        return ['path' => $jailed, 'owned' => true];
    }


    /** Stream a remote URL to a temp file. */
    private function fetchRemoteToTemp(string $url): ?string
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $current = $url;
        $maxHops = 5;

        for ($hop = 0; $hop <= $maxHops; ++$hop) {
            if (!RemoteUrlGuard::isSafe($current)) {
                return null;
            }

            $tmp = wp_tempnam($current);
            if (!is_string($tmp) || $tmp === '') {
                return null;
            }

            $response = wp_safe_remote_get($current, [
                'timeout' => Limits::DOWNLOAD_TIMEOUT_SECONDS,
                'redirection' => 0,
                'stream' => true,
                'filename' => $tmp,
                'limit_response_size' => Limits::MAX_FILE_BYTES,
            ]);

            if (is_wp_error($response)) {
                wp_delete_file($tmp);

                return null;
            }

            $code = (int)wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                wp_delete_file($tmp);
                $location = wp_remote_retrieve_header($response, 'location');
                if (!is_string($location) || $location === '' || $hop === $maxHops) {
                    return null;
                }

                $current = $this->resolveRedirectUrl($current, $location);
                if ($current === null) {
                    return null;
                }

                continue;
            }

            if ($code < 200 || $code >= 300) {
                wp_delete_file($tmp);

                return null;
            }

            if (!RemoteUrlGuard::stillSafeAfterFetch($current)) {
                wp_delete_file($tmp);

                return null;
            }

            return $tmp;
        }

        return null;
    }

    /** Resolve a redirect Location header. */
    private function resolveRedirectUrl(string $current, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        $parts = wp_parse_url($current);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = (string)$parts['scheme'];
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = (string)($parts['path'] ?? '/');
        $base = preg_replace('#/[^/]*$#', '/', $path);
        if (!is_string($base) || $base === '') {
            $base = '/';
        }

        return $scheme . '://' . $host . $port . $base . $location;
    }

    /** Place file from source. */
    private function placeFileFromSource(string $source, string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        if (is_file($source)) {
            return $this->placeFile($source, $destination);
        }

        // Stream remote sources.
        return $this->streamCopyBounded($source, $destination);
    }

    /** Stream-copy a source with a size limit. */
    private function streamCopyBounded(string $source, string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        $in = @fopen($source, 'rb');
        if ($in === false) {
            return false;
        }

        $out = @fopen($destination, 'wb');
        if ($out === false) {
            fclose($in);

            return false;
        }

        $written = 0;
        $ok = true;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 256);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if ($chunk === '') {
                break;
            }
            $written += strlen($chunk);
            if ($written > Limits::MAX_FILE_BYTES) {
                $ok = false;
                break;
            }
            if (fwrite($out, $chunk) === false) {
                $ok = false;
                break;
            }
        }

        fclose($in);
        fclose($out);

        if (!$ok || $written === 0) {
            wp_delete_file($destination);

            return false;
        }

        clearstatcache(true, $destination);

        return is_file($destination) && filesize($destination) > 0;
    }

    /** Write the primary file onto the registered path. */
    private function placePrimaryLocally(int $attachmentId, string $localPath): bool
    {
        $attached = $this->registeredAttachedPath($attachmentId);
        if ($attached === null) {
            return false;
        }

        return $this->placeFile($localPath, $attached);
    }

    /** Local path for a size file. */
    private function localSizeDestination(int $attachmentId, string $sizeFile): ?string
    {
        $sizeFile = basename(str_replace('\\', '/', $sizeFile));
        if ($sizeFile === '' || $sizeFile === '.' || $sizeFile === '..') {
            return null;
        }

        $attached = $this->registeredAttachedPath($attachmentId);
        if ($attached === null) {
            return null;
        }

        $dir = dirname($attached);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return null;
        }

        return $dir . DIRECTORY_SEPARATOR . $sizeFile;
    }

    /** Move or copy a file into place. */
    private function placeFile(string $source, string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        if ($source === $destination) {
            return is_file($destination) && filesize($destination) > 0;
        }

        if (!@copy($source, $destination)) {
            return false;
        }

        clearstatcache(true, $destination);

        return is_file($destination) && filesize($destination) > 0;
    }

    /** Sanitise a list of file paths. */
    private function stringifyPathList(array $paths): array
    {
        $out = [];
        foreach ($paths as $key => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (str_contains($path, "\0")) {
                continue;
            }

            $segments = explode('/', str_replace('\\', '/', $path));
            if (in_array('..', $segments, true)) {
                continue;
            }

            $out[$key] = $path;
        }

        return $out;
    }

    /** Record that remote push failed after local success. */
    private function recordRemotePushFailed(int $attachmentId): void
    {
        $stats = get_post_meta($attachmentId, CompressionService::META_STATS, true);
        if (!is_array($stats)) {
            $stats = [
                'version' => 1,
                'processed_at' => time(),
            ];
        }
        $stats['remote_push'] = 'failed';
        update_post_meta($attachmentId, CompressionService::META_STATS, $stats);
    }

    /** Drop the push-failed marker once remote WebPs are confirmed. */
    private function clearRemotePushFailed(int $attachmentId): void
    {
        $stats = get_post_meta($attachmentId, CompressionService::META_STATS, true);
        if (!is_array($stats) || ($stats['remote_push'] ?? '') !== 'failed') {
            return;
        }
        unset($stats['remote_push']);
        update_post_meta($attachmentId, CompressionService::META_STATS, $stats);
    }
}
