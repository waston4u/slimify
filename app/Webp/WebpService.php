<?php

declare(strict_types=1);

namespace Slimify\Webp;

use Throwable;
use Slimify\Compression\CompressionService;
use Slimify\Support\BinaryProbe;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\ProcessRunner;
use Slimify\Support\Settings;
use Slimify\Support\Site;


final class WebpService
{
    private const string OPTION_HAS_WEBP = 'slimify_has_webp';

    /**
     * @var bool|null
     */
    private static ?bool $acceptsWebp = null;

    private static bool $suspended = false;

    /**
     * @var array<int, array<string, string>>
     */
    private static array $mapCache = [];

    /**
     * @var array<string, string|null>
     */
    private static array $serveCache = [];

    /** Constructor. */
    public function __construct(
        private readonly Settings $settings,
        private readonly BinaryProbe $probe,
        private readonly PathGuard $pathGuard,
        private readonly ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /** Register front-end WebP URL filters. */
    public function registerDeliveryFilters(): void
    {
        // Run after offloaders' URL rewrites (AS3CF swaps to the bucket URL at
        // priority 99) so the extension swap applies to the final URL.
        add_filter('wp_get_attachment_url', [$this, 'filterAttachmentUrl'], 999, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'filterImageSrc'], 999, 4);
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterImageAttributes'], 999, 3);
        add_filter('wp_calculate_image_srcset', [$this, 'filterSrcset'], 999, 5);
        add_action('send_headers', [$this, 'sendVaryAccept']);
    }

    /** Register WebP maintenance hooks. */
    public function registerMaintenanceHooks(): void
    {
        add_filter('wp_update_attachment_metadata', [$this, 'purgeStaleSiblings'], 5, 2);
    }

    /**
     * Remove stale WebP siblings after edits.
     *
     * @param array<string, mixed>|mixed $metadata
     * @return array<string, mixed>|mixed
     */
    public function purgeStaleSiblings(mixed $metadata, int|string $attachmentId = 0): mixed
    {
        $attachmentId = (int)$attachmentId;
        if ($attachmentId <= 0) {
            return $metadata;
        }

        $map = $this->webpMap($attachmentId, []);
        if ($map === []) {
            return $metadata;
        }

        $attached = get_attached_file($attachmentId, true);
        if (!is_string($attached) || $attached === '' || !is_file($attached)) {
            return $metadata;
        }

        $dir = dirname($attached);
        $changed = false;
        $removed = [];

        foreach ($map as $sizeKey => $basename) {
            $webpPath = $dir . DIRECTORY_SEPARATOR . $basename;
            $source = $this->sourceSiblingPath($webpPath);

            if ($source === null || !is_file($source)) {
                continue;
            }

            if (!is_file($webpPath)) {
                unset($map[$sizeKey]);
                $removed[] = $basename;
                $changed = true;
                continue;
            }

            if ($this->isStale($webpPath, $source)) {
                $this->purgeWebp($webpPath);
                unset($map[$sizeKey]);
                $removed[] = $basename;
                $changed = true;
            }
        }

        if ($changed) {
            if ($map === []) {
                delete_post_meta($attachmentId, CompressionService::META_WEBP);
            } else {
                update_post_meta($attachmentId, CompressionService::META_WEBP, $map);
            }

            $remote = get_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, true);
            if (is_array($remote) && is_array($remote['files'] ?? null)) {
                $remote['files'] = array_values(array_diff($remote['files'], $removed));
                if ($remote['files'] === []) {
                    delete_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE);
                } else {
                    update_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, $remote);
                }
            }

            unset(self::$mapCache[$attachmentId]);
            self::$serveCache = [];
        }

        return $metadata;
    }

    /** True if the WebP is older than its source. */
    private function isStale(string $webpPath, string $sourcePath): bool
    {
        clearstatcache(true, $webpPath);
        clearstatcache(true, $sourcePath);

        $webpTime = @filemtime($webpPath);
        $sourceTime = @filemtime($sourcePath);

        return $webpTime !== false && $sourceTime !== false && $sourceTime > $webpTime;
    }

    /** Send Vary: Accept when needed. */
    public function sendVaryAccept(): void
    {
        if (headers_sent() || $this->isNonWebpContext() || !$this->settings->generateWebp()) {
            return;
        }

        if (!$this->siteHasWebp()) {
            return;
        }

        if (!$this->clientAcceptsWebp()) {
            return;
        }

        /**
         * Full-page caches key on the raw Accept header, which fragments the cache badly
         * @param bool $send
         */
        if (!apply_filters('slimify_send_vary_accept', true)) {
            return;
        }

        header('Vary: Accept', false);
    }

    /** True if the site has any WebP siblings. */
    private function siteHasWebp(): bool
    {
        return get_option(self::OPTION_HAS_WEBP) === '1';
    }

    /** Record that the site has WebP. */
    private function markSiteHasWebp(): void
    {
        if (!$this->siteHasWebp()) {
            update_option(self::OPTION_HAS_WEBP, '1', true);
        }
    }

    /** Clear the site has-WebP flag. */
    public static function clearHasWebpFlag(): void
    {
        delete_option(self::OPTION_HAS_WEBP);
    }

    /** Create a WebP sibling if it is smaller. */
    public function generateSibling(string $deliverablePath, string $encodeFrom): ?string
    {
        $webpPath = $this->createSibling($deliverablePath, $encodeFrom);
        if ($webpPath !== null) {
            $this->markSiteHasWebp();
        }

        return $webpPath;
    }

    /** True if encode source is under allowed roots. */
    private function isAllowedEncodeSource(string $encodeFrom): bool
    {
        if ($this->pathGuard->isAllowed($encodeFrom)) {
            return true;
        }

        $real = realpath($encodeFrom);
        if ($real === false) {
            return false;
        }

        return Site::isOwnedStoragePath($real);
    }

    /** Encode a WebP next to the deliverable. */
    private function createSibling(string $deliverablePath, string $encodeFrom): ?string
    {
        if (!$this->settings->generateWebp()) {
            return null;
        }

        if (!$this->pathGuard->isAllowed($deliverablePath) || !Limits::isFileWebpEligible($deliverablePath)) {
            return null;
        }

        if (
            !is_file($encodeFrom)
            || !is_readable($encodeFrom)
            || !$this->isAllowedEncodeSource($encodeFrom)
        ) {
            return null;
        }

        // Refuse to encode from the post-compress file.
        $deliverReal = realpath($deliverablePath);
        $encodeReal = realpath($encodeFrom);
        if (
            $deliverReal === false
            || $encodeReal === false
            || $deliverReal === $encodeReal
        ) {
            return null;
        }

        if (!Limits::isFileWebpEligible($encodeFrom)) {
            return null;
        }

        $mime = (string)(wp_check_filetype($deliverablePath)['type'] ?? '');
        if ($mime === '') {
            $mime = (string)(wp_check_filetype($encodeFrom)['type'] ?? '');
        }
        if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return null;
        }

        $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $deliverablePath);
        if (!is_string($webpPath) || $webpPath === $deliverablePath) {
            $webpPath = $deliverablePath . '.webp';
        }

        clearstatcache(true, $deliverablePath);
        $deliverableSize = filesize($deliverablePath);
        if ($deliverableSize === false || $deliverableSize <= 0) {
            return null;
        }

        $deadline = microtime(true) + Limits::WEBP_ENCODE_BUDGET_SECONDS;
        $hasCwebp = $this->probe->cwebp() !== false;
        $imagickUsed = 0;

        $qualities = $this->qualityLadder();
        /** @var list<int> $qualities */
        $qualities = apply_filters('slimify_webp_quality_ladder', $qualities);
        if (!is_array($qualities)) {
            $qualities = $this->qualityLadder();
        }

        foreach ($qualities as $quality) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $quality = (int)$quality;
            $this->purgeWebp($webpPath);
            $created = $this->createWithCwebpQuality($encodeFrom, $webpPath, $quality, $mime);

            if (!$created && (!$hasCwebp || $imagickUsed < 2)) {
                if ($this->createWithImagick($encodeFrom, $webpPath, $quality)) {
                    $created = true;
                    ++$imagickUsed;
                }
            }

            if ($created && $this->webpBeatsDeliverable($webpPath, $deliverableSize)) {
                return $webpPath;
            }
        }

        if ($hasCwebp) {
            $fractions = apply_filters('slimify_webp_size_fractions', [0.40]);
            if (!is_array($fractions)) {
                $fractions = [0.40];
            }
            foreach ($fractions as $fraction) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $fraction = (float)$fraction;
                $target = max(512, (int)floor($deliverableSize * $fraction));
                if ($target >= $deliverableSize) {
                    continue;
                }
                $this->purgeWebp($webpPath);
                if (
                    $this->createWithCwebpTargetSize($encodeFrom, $webpPath, $target, $mime)
                    && $this->webpBeatsDeliverable($webpPath, $deliverableSize)
                ) {
                    return $webpPath;
                }
            }
        }

        $this->purgeWebp($webpPath);

        return null;
    }

    /** True if WebP is smaller than the final file. */
    private function webpBeatsDeliverable(string $webpPath, int $deliverableSize): bool
    {
        if ($deliverableSize <= 0 || !is_file($webpPath)) {
            return false;
        }

        clearstatcache(true, $webpPath);
        $webpSize = filesize($webpPath);

        return $webpSize !== false && $webpSize > 0 && $webpSize < $deliverableSize;
    }

    /** Delete a WebP file. */
    private function purgeWebp(string $webpPath): void
    {
        if (is_file($webpPath)) {
            wp_delete_file($webpPath);
        }
    }

    /** Encode WebP with cwebp to a target size. */
    private function createWithCwebpTargetSize(string $source, string $dest, int $targetBytes, string $mime): bool
    {
        $binary = $this->probe->cwebp();
        if ($binary === false || $targetBytes < 1) {
            return false;
        }

        $command = [
            $binary,
            '-size',
            (string)$targetBytes,
            '-pass',
            '4',
            '-m',
            '4',
            '-metadata',
            'none',
            '-quiet',
        ];

        if ($mime === 'image/png' || str_ends_with(strtolower($source), '.png')) {
            $command[] = '-alpha_q';
            $command[] = '90';
        }

        $command = array_merge($command, [
            $source,
            '-o',
            $dest,
        ]);

        $result = $this->runner->run($command, Limits::WEBP_TIMEOUT_SECONDS);

        if (!$result['ok'] || !is_file($dest)) {
            return false;
        }

        clearstatcache(true, $dest);
        $size = filesize($dest);

        return $size !== false && $size > 0 && $size <= $targetBytes;
    }

    /** Encode WebP with cwebp at a quality. */
    private function createWithCwebpQuality(string $source, string $dest, int $quality, string $mime): bool
    {
        $binary = $this->probe->cwebp();
        if ($binary === false) {
            return false;
        }

        $command = [
            $binary,
            '-q',
            (string)max(0, min(100, $quality)),
            '-m',
            '6',
            '-metadata',
            'none',
            '-quiet',
        ];

        if ($mime === 'image/png' || str_ends_with(strtolower($source), '.png')) {
            $command[] = '-alpha_q';
            $command[] = '90';
        }

        $command = array_merge($command, [
            $source,
            '-o',
            $dest,
        ]);

        $result = $this->runner->run($command, Limits::WEBP_TIMEOUT_SECONDS);

        return $result['ok'] && is_file($dest) && filesize($dest) > 0;
    }

    /** Encode WebP with Imagick. */
    private function createWithImagick(string $source, string $dest, int $quality): bool
    {
        if (!$this->probe->imagickAvailable()) {
            return false;
        }

        try {
            Limits::applyImagickResourceLimits();
            $imagickClass = '\\Imagick';
            $image = new $imagickClass();
            $image->readImage($source);
            $format = strtolower($image->getImageFormat());
            if (!in_array($format, ['jpeg', 'jpg', 'png'], true)) {
                $image->clear();

                return false;
            }
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(max(0, min(100, $quality)));
            if (method_exists($image, 'setOption')) {
                $image->setOption('webp:method', '4');
            }
            $image->stripImage();
            $ok = $image->writeImage($dest);
            $image->clear();

            return $ok && is_file($dest) && filesize($dest) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Default WebP quality steps (filters can extend). */
    private function qualityLadder(): array
    {
        $base = max(50, min(90, $this->settings->jpegQuality()));
        $start = max(48, $base - 5);

        return array_values(array_unique(array_map('intval', [
            $start,
            max(32, $start - 18),
        ])));
    }

    /** Rewrite attachment URL to WebP when appropriate. */
    public function filterAttachmentUrl(mixed $url, mixed $attachmentId): string
    {
        $url = (string)$url;
        $attachmentId = (int)$attachmentId;

        if (
            $attachmentId <= 0
            || !$this->settings->generateWebp()
            || $this->isNonWebpContext()
            || !$this->clientAcceptsWebp()
        ) {
            return $url;
        }

        $webpUrl = $this->webpUrlForAttachment($attachmentId, $url);

        return $webpUrl ?? $url;
    }

    /**
     * Rewrite image src to WebP when appropriate.
     *
     * @param array{0?: string, 1?: int, 2?: int, 3?: bool}|mixed $image
     * @return array{0?: string, 1?: int, 2?: int, 3?: bool}|mixed
     */
    public function filterImageSrc(mixed $image, mixed $attachmentId, mixed $size, mixed $icon): mixed
    {
        if (
            !is_array($image)
            || empty($image[0])
            || !$this->settings->generateWebp()
            || $this->isNonWebpContext()
            || !$this->clientAcceptsWebp()
        ) {
            return $image;
        }

        $webpUrl = $this->webpUrlForAttachment((int)$attachmentId, (string)$image[0]);
        if ($webpUrl !== null) {
            $image[0] = $webpUrl;
        }

        return $image;
    }

    /**
     * Rewrite <img> src/srcset attributes to WebP when appropriate.
     *
     * Runs after offloaders rebuild attributes (AS3CF regenerates src at
     * priority 99), so swapped values aren't clobbered.
     *
     * @param array<string, string>|mixed $attr
     * @return array<string, string>|mixed
     */
    public function filterImageAttributes(mixed $attr, mixed $attachment, mixed $size): mixed
    {
        if (
            !is_array($attr)
            || !$this->settings->generateWebp()
            || $this->isNonWebpContext()
            || !$this->clientAcceptsWebp()
        ) {
            return $attr;
        }

        $attachmentId = is_object($attachment) && isset($attachment->ID) ? (int)$attachment->ID : 0;
        if ($attachmentId <= 0) {
            return $attr;
        }

        if (isset($attr['src']) && is_string($attr['src'])) {
            $webp = $this->serveUrlFor($attachmentId, $attr['src']);
            if ($webp !== null) {
                $attr['src'] = $webp;
            }
        }

        if (isset($attr['srcset']) && is_string($attr['srcset'])) {
            $attr['srcset'] = $this->srcsetUrlsToWebp($attachmentId, $attr['srcset']);
        }

        return $attr;
    }

    /** Swap each srcset candidate URL for its servable WebP variant. */
    private function srcsetUrlsToWebp(int $attachmentId, string $srcset): string
    {
        $parts = explode(',', $srcset);
        foreach ($parts as $i => $part) {
            $piece = trim((string)$part);
            if ($piece === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $piece);
            if (!is_array($bits) || $bits === []) {
                continue;
            }
            $webp = $this->serveUrlFor($attachmentId, $bits[0]);
            if ($webp !== null) {
                $bits[0] = $webp;
                $parts[$i] = implode(' ', $bits);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Rewrite srcset candidates to WebP when appropriate.
     *
     * @param array<int|string, array<string, mixed>>|mixed $sources
     * @return array<int|string, array<string, mixed>>
     */
    public function filterSrcset(
        mixed $sources,
        mixed $sizeArray,
        mixed $imageSrc,
        mixed $imageMeta,
        mixed $attachmentId
    ): array {
        if (
            !is_array($sources)
            || !$this->settings->generateWebp()
            || $this->isNonWebpContext()
            || !$this->clientAcceptsWebp()
        ) {
            return is_array($sources) ? $sources : [];
        }

        $map = $this->webpMap((int)$attachmentId, is_array($imageMeta) ? $imageMeta : []);
        if ($map === []) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            if (!is_array($source) || empty($source['url'])) {
                continue;
            }
            $webpUrl = $this->serveUrlFor((int)$attachmentId, (string)$source['url']);
            if ($webpUrl !== null) {
                $sources[$width]['url'] = $webpUrl;
            }
        }

        return $sources;
    }

    /** True if the client accepts WebP. */
    private function clientAcceptsWebp(): bool
    {
        if (self::$acceptsWebp !== null) {
            return self::$acceptsWebp;
        }

        if ($this->isNonWebpContext()) {
            self::$acceptsWebp = false;

            return false;
        }

        $accept = '';
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = sanitize_text_field(wp_unslash((string)$_SERVER['HTTP_ACCEPT']));
        }
        self::$acceptsWebp = str_contains($accept, 'image/webp');

        return self::$acceptsWebp;
    }

    /** Run a callback without WebP rewriting. */
    public static function withoutRewriting(callable $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $previous;
        }
    }

    /** True when this request must not serve WebP. */
    private function isNonWebpContext(): bool
    {
        if (self::$suspended) {
            return true;
        }

        if (is_admin() && !wp_doing_ajax()) {
            return true;
        }

        if (function_exists('is_feed') && is_feed()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return false;
    }

    /** WebP map for an attachment. */
    private function webpMap(int $attachmentId, array $metadata): array
    {
        if (isset($metadata['slimify_webp']) && is_array($metadata['slimify_webp'])) {
            /**
             * @var array<string, string>
             */
            return array_map('strval', $metadata['slimify_webp']);
        }

        if (isset(self::$mapCache[$attachmentId])) {
            return self::$mapCache[$attachmentId];
        }

        $meta = get_post_meta($attachmentId, '_slimify_webp', true);
        if (is_array($meta)) {
            /**
             * @var array<string, string> $mapped
             */
            $mapped = array_map('strval', $meta);
            self::$mapCache[$attachmentId] = $mapped;

            return $mapped;
        }

        self::$mapCache[$attachmentId] = [];

        return [];
    }

    /** WebP URL for an attachment URL, or null. */
    private function webpUrlForAttachment(int $attachmentId, string $originalUrl): ?string
    {
        if ($this->webpMap($attachmentId, []) === []) {
            return null;
        }

        return $this->serveUrlFor($attachmentId, $originalUrl);
    }

    /** Swap image extension for .webp in a URL. */
    private function swapUrlExtension(string $url): ?string
    {
        $webp = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url);
        if (!is_string($webp) || $webp === $url) {
            return null;
        }

        return $webp;
    }

    /** Resolved WebP URL for an original URL, or null (cached). */
    private function serveUrlFor(int $attachmentId, string $originalUrl): ?string
    {
        $key = $attachmentId . '|' . $originalUrl;
        if (array_key_exists($key, self::$serveCache)) {
            return self::$serveCache[$key];
        }

        return self::$serveCache[$key] = $this->resolveServeUrl($attachmentId, $originalUrl);
    }

    /**
     * Resolve the WebP URL that can actually be served for an original URL:
     *
     * - Local URL → local sibling must exist on disk.
     * - Remote URL + confirmed offloaded → remote .webp (CDN).
     * - Remote URL + unconfirmed → local sibling URL from origin, if the file
     *   exists. Universal fallback: works with any offloader, no plugin API —
     *   and never serves a remote .webp that isn't really there.
     */
    private function resolveServeUrl(int $attachmentId, string $originalUrl): ?string
    {
        $webpUrl = $this->swapUrlExtension($originalUrl);
        if ($webpUrl === null) {
            return null;
        }

        $path = (string)(wp_parse_url($webpUrl, PHP_URL_PATH) ?? '');
        $basename = $path !== '' ? basename($path) : '';
        if (
            $basename === ''
            || str_contains($basename, '..')
            || !str_ends_with(strtolower($basename), '.webp')
        ) {
            return null;
        }

        $map = $this->webpMap($attachmentId, []);
        if (!in_array($basename, $map, true)) {
            return null;
        }

        if ($this->isLocalMediaUrl($webpUrl)) {
            return $this->localWebpExists($attachmentId, $basename) ? $webpUrl : null;
        }

        if ($this->remoteWebpConfirmed($attachmentId, $webpUrl, $basename)) {
            return $webpUrl;
        }

        return $this->localWebpUrl($attachmentId, $basename);
    }

    /** True when the URL points at this site's own uploads domain. */
    private function isLocalMediaUrl(string $url): bool
    {
        $uploads = wp_upload_dir();
        $baseHost = wp_parse_url((string)($uploads['baseurl'] ?? ''), PHP_URL_HOST);
        $urlHost = wp_parse_url($url, PHP_URL_HOST);

        return is_string($baseHost) && $baseHost !== '' && is_string($urlHost)
            && Site::normaliseHost($urlHost) === Site::normaliseHost($baseHost);
    }

    /** Local-file gate for WebP serving; drops the mapping when the file is gone. */
    private function localWebpExists(int $attachmentId, string $basename): bool
    {
        $attached = get_attached_file($attachmentId, true);
        if (!is_string($attached) || $attached === '') {
            return false;
        }

        $webpPath = dirname($attached) . DIRECTORY_SEPARATOR . $basename;
        if (is_file($webpPath)) {
            return true;
        }

        // Missing file: drop from meta.
        $this->dropWebpBasename($attachmentId, $basename);

        return false;
    }

    /** Remote gate: only serve WebPs confirmed pushed for this delivery host. */
    private function remoteWebpConfirmed(int $attachmentId, string $webpUrl, string $basename): bool
    {
        $remote = get_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, true);
        if (
            !is_array($remote)
            || !is_string($remote['host'] ?? null)
            || !is_array($remote['files'] ?? null)
        ) {
            return false;
        }

        $host = wp_parse_url($webpUrl, PHP_URL_HOST);

        return $host === $remote['host'] && in_array($basename, $remote['files'], true);
    }

    /**
     * Origin URL for a local WebP sibling, or null when the file isn't there.
     * Used as the universal fallback for offloaded attachments whose WebP
     * hasn't been confirmed on the remote storage yet.
     */
    private function localWebpUrl(int $attachmentId, string $basename): ?string
    {
        $attached = get_attached_file($attachmentId, true);
        if (!is_string($attached) || $attached === '') {
            return null;
        }

        $webpPath = dirname($attached) . DIRECTORY_SEPARATOR . $basename;
        if (!is_file($webpPath)) {
            return null; // Remote file may still exist — meta is untouched.
        }

        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? wp_normalize_path((string)$uploads['basedir']) : '';
        $baseurl = isset($uploads['baseurl']) ? (string)$uploads['baseurl'] : '';
        $normalised = wp_normalize_path($webpPath);
        if ($basedir === '' || $baseurl === '' || !str_starts_with($normalised, $basedir)) {
            return null;
        }

        // baseurl can be host-relative or malformed (e.g. 'http:///app/uploads'
        // in some CLI/docroot configs) — rebuild it from home_url's path.
        $baseHost = wp_parse_url($baseurl, PHP_URL_HOST);
        if (!is_string($baseHost) || $baseHost === '') {
            $basePath = preg_replace('#^[a-z][a-z0-9+.-]*://*#i', '', $baseurl);
            $baseurl = home_url('/' . ltrim((string)$basePath, '/'));
            if (!is_string(wp_parse_url($baseurl, PHP_URL_HOST))) {
                return null;
            }
        }

        return $baseurl . substr($normalised, strlen($basedir));
    }

    /** Remove a missing WebP basename from meta. */
    private function dropWebpBasename(int $attachmentId, string $basename): void
    {
        $map = get_post_meta($attachmentId, CompressionService::META_WEBP, true);
        if (!is_array($map)) {
            return;
        }

        $changed = false;
        foreach ($map as $key => $value) {
            if ($value === $basename) {
                unset($map[$key]);
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        if ($map === []) {
            delete_post_meta($attachmentId, CompressionService::META_WEBP);
        } else {
            update_post_meta($attachmentId, CompressionService::META_WEBP, $map);
        }

        $remote = get_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, true);
        if (is_array($remote) && is_array($remote['files'] ?? null)) {
            $files = array_values(array_diff($remote['files'], [$basename]));
            if ($files === []) {
                delete_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE);
            } else {
                $remote['files'] = $files;
                update_post_meta($attachmentId, CompressionService::META_WEBP_REMOTE, $remote);
            }
        }

        unset(self::$mapCache[$attachmentId]);
    }

    /** JPEG or PNG path next to a WebP file. */
    private function sourceSiblingPath(string $webpPath): ?string
    {
        foreach (['.jpg', '.jpeg', '.png'] as $ext) {
            $candidate = preg_replace('/\.webp$/i', $ext, $webpPath);
            if (is_string($candidate) && $candidate !== $webpPath && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
