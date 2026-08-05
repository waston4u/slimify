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
use Slimify\Webp\WebpService;


final readonly class OffloadAdapter
{
    /** Constructor. */
    public function __construct(
        private CompressionService $compressionService,
        private PathGuard $pathGuard,
    ) {
    }

    /** Register hooks. */
    public function register(): void
    {
        // Host-supplied path list filter names.
        $hooks = apply_filters('slimify_offload_file_path_hooks', []);
        if (!is_array($hooks)) {
            return;
        }

        foreach ($hooks as $hook) {
            if (!is_string($hook) || $hook === '' || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $hook)) {
                continue;
            }
            add_filter($hook, [$this, 'mergeExtraFilePaths'], 10, 3);
        }
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

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            if (is_string($webp) && $webp !== $path && is_file($webp) && !in_array($webp, $paths, true)) {
                $paths[] = $webp;
            }
        }

        $attached = $this->registeredAttachedPath($attachmentId);
        $dir = $attached !== null ? dirname($attached) : '';
        if ($dir !== '') {
            foreach ($map as $basename) {
                if (!is_string($basename) || $basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
                    continue;
                }
                $webp = $dir . DIRECTORY_SEPARATOR . $basename;
                if (is_file($webp) && !in_array($webp, $paths, true)) {
                    $paths[] = $webp;
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
        if ($this->hasReadableLocalFile($attachmentId)) {
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
            if (!$this->canFetchRemote($attachmentId)) {
                if (!$dryRun) {
                    $this->compressionService->markUnavailable($attachmentId, 'no_remote_source');
                }

                return [
                    'ok' => false,
                    'permanent' => true,
                    'message' => __('Local file missing and no third-party/remote source is available.', 'slimify'),
                    'files' => 0,
                ];
            }

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

            // Materialise remote then compress.
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
        }

        return $this->runLocalCompressPipeline($attachmentId, $metadata, $fromRemote);
    }

    /**
     * Fetch remote full + intermediate sizes onto the registered local paths
     * so CompressionService can back up and compress like a normal attachment.
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
        $metadata = $this->compressionService->compressAttachmentMetadata($metadata, $attachmentId);
        wp_update_attachment_metadata($attachmentId, $metadata);

        $outcome = $this->compressionService->lastOutcome();
        $needsRemote = $fromRemote || $this->usesRemoteStorage($attachmentId);
        $pushReason = $fromRemote ? 'compress-remote' : 'compress-local';

        if ($outcome === 'busy' || $outcome === 'deferred') {
            return [
                'ok' => false,
                'message' => __('Compression is busy with another image. Try again shortly.', 'slimify'),
                'files' => 0,
                'busy' => true,
            ];
        }

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

        return $this->downloadUrlToJailTemp($url);
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

    /** True if a remote source can be fetched. */
    private function canFetchRemote(int $attachmentId): bool
    {
        $probe = apply_filters('slimify_can_fetch_remote', null, $attachmentId);
        if (is_bool($probe)) {
            return $probe;
        }

        if ($this->sourceUrl($attachmentId, 'full') !== null) {
            return true;
        }

        $filtered = get_attached_file($attachmentId, false);
        if (is_string($filtered) && $filtered !== '' && $this->isReadableSource($filtered)) {
            return true;
        }

        $registered = $this->registeredAttachedPath($attachmentId);
        if (is_string($registered) && $registered !== '' && $this->isReadableSource($registered)) {
            return true;
        }

        $viaFilter = apply_filters('slimify_fetch_remote_file', null, $attachmentId, [
            'size' => 'full',
            'size_file' => null,
            'url' => null,
            'attached' => $registered,
        ]);

        return is_string($viaFilter) && $viaFilter !== '' && $this->isReadableSource($viaFilter);
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

    /** True if a readable local file exists. */
    private function hasReadableLocalFile(int $attachmentId): bool
    {
        if ($this->hasRealDiskFile($attachmentId)) {
            return true;
        }

        // Soft-local or stream sources.
        $filtered = get_attached_file($attachmentId, false);
        if (is_string($filtered) && $this->isReadableSource($filtered)) {
            return true;
        }

        $registered = $this->registeredAttachedPath($attachmentId);

        return is_string($registered) && $this->isReadableSource($registered);
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

        return $url;
    }

    /** True if the URL is off local uploads. */
    private function urlIsOffLocalUploads(int $attachmentId): bool
    {
        $url = $this->sourceUrl($attachmentId, 'full');
        if ($url === null) {
            return false;
        }

        $uploads = wp_upload_dir();
        $baseurl = (string)($uploads['baseurl'] ?? '');

        return $baseurl === '' || !str_starts_with($url, $baseurl);
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

            $temp = dirname($real) . DIRECTORY_SEPARATOR . '.slimify-src-' . bin2hex(random_bytes(8)) . '-' . $basename;
            if (@copy($real, $temp) && is_file($temp)) {
                if (!Limits::isFileWithinLimits($temp)) {
                    wp_delete_file($temp);

                    return null;
                }

                return ['path' => $temp, 'owned' => true];
            }

            if (!Limits::isFileWithinLimits($real)) {
                return null;
            }

            return ['path' => $real, 'owned' => false];
        }

        $temp = trailingslashit(sys_get_temp_dir()) . 'slimify-' . bin2hex(random_bytes(8)) . '-' . $basename;
        if (!$this->placeFileFromSource($source, $temp)) {
            return null;
        }

        if (!$this->pathGuard->isAllowed($temp) || !Limits::isFileWithinLimits($temp)) {
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

        if (!Limits::isFileWithinLimits($tmp)) {
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
}
