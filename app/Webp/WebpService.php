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
     * @var array<string, bool>
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
        add_filter('wp_get_attachment_url', [$this, 'filterAttachmentUrl'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'filterImageSrc'], 10, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'filterSrcset'], 10, 5);
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

        foreach ($map as $sizeKey => $basename) {
            $webpPath = $dir . DIRECTORY_SEPARATOR . $basename;
            $source = $this->sourceSiblingPath($webpPath);

            if ($source === null || !is_file($source)) {
                continue;
            }

            if (!is_file($webpPath)) {
                unset($map[$sizeKey]);
                $changed = true;
                continue;
            }

            if ($this->isStale($webpPath, $source)) {
                $this->purgeWebp($webpPath);
                unset($map[$sizeKey]);
                $changed = true;
            }
        }

        if ($changed) {
            if ($map === []) {
                delete_post_meta($attachmentId, CompressionService::META_WEBP);
            } else {
                update_post_meta($attachmentId, CompressionService::META_WEBP, $map);
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

        if (!$this->pathGuard->isAllowed($deliverablePath) || !Limits::isFileWithinLimits($deliverablePath)) {
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

        if (!Limits::isFileWithinLimits($encodeFrom)) {
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
            $webpUrl = $this->swapUrlExtension((string)$source['url']);
            if ($webpUrl !== null && $this->shouldServeWebp((int)$attachmentId, $webpUrl)) {
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

        $swapped = $this->swapUrlExtension($originalUrl);
        if ($swapped === null) {
            return null;
        }

        return $this->shouldServeWebp($attachmentId, $swapped) ? $swapped : null;
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

    /** True if a WebP URL should be served. */
    private function shouldServeWebp(int $attachmentId, string $webpUrl): bool
    {
        $key = $attachmentId . '|' . $webpUrl;
        if (isset(self::$serveCache[$key])) {
            return self::$serveCache[$key];
        }

        return self::$serveCache[$key] = $this->resolveShouldServeWebp($attachmentId, $webpUrl);
    }

    /** Serve WebP only if mapped and the file exists. */
    private function resolveShouldServeWebp(int $attachmentId, string $webpUrl): bool
    {
        $path = (string)(wp_parse_url($webpUrl, PHP_URL_PATH) ?? '');
        $basename = $path !== '' ? basename($path) : '';
        if (
            $basename === ''
            || str_contains($basename, '..')
            || !str_ends_with(strtolower($basename), '.webp')
        ) {
            return false;
        }

        $map = $this->webpMap($attachmentId, []);
        if (!in_array($basename, $map, true)) {
            return false;
        }

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
