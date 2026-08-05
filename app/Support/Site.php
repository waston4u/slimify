<?php

declare(strict_types=1);

namespace Slimify\Support;

use Random\RandomException;

/** Paths: originals under uploads; runtime (queue/locks) under temp. */
final class Site
{
    private static bool $booted = false;

    private static bool $multisite = false;

    private const string OPTION_STORAGE_SECRET = 'slimify_storage_secret';

    private const string OPTION_DOCUMENT_ROOT = 'slimify_document_root';

    /** Cache multisite flag. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$multisite = function_exists('is_multisite') && is_multisite();
        self::$booted = true;
    }

    /** True on multisite. */
    public static function isMultisite(): bool
    {
        if (! self::$booted) {
            self::boot();
        }

        return self::$multisite;
    }

    /** Current blog ID. */
    public static function id(): int
    {
        if (! self::isMultisite()) {
            return 1;
        }

        return get_current_blog_id();
    }

    /** Uploads base directory for this site. */
    private static function uploadsBase(): string
    {
        if (function_exists('wp_get_upload_dir')) {
            $uploads = wp_get_upload_dir();
            if (! empty($uploads['basedir'])) {
                return rtrim((string) $uploads['basedir'], '/\\');
            }
        }

        return rtrim(WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    }

    /** Plugin folder under uploads. */
    public static function storageContainer(): string
    {
        return self::uploadsBase() . DIRECTORY_SEPARATOR . 'slimify';
    }

    /** Site storage root (same as container). */
    public static function storageRoot(): string
    {
        return self::storageContainer();
    }

    /** Originals directory. */
    public static function backupRootCandidates(): array
    {
        return [self::storageRoot() . DIRECTORY_SEPARATOR . 'originals'];
    }

    /** True if path is under uploads/slimify/originals. */
    public static function isOwnedStoragePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $base = self::storageRoot() . DIRECTORY_SEPARATOR . 'originals';
        $baseReal = realpath($base);
        $pathReal = realpath($path);

        if ($baseReal !== false && $pathReal !== false) {
            return $pathReal === $baseReal
                || str_starts_with($pathReal, $baseReal . DIRECTORY_SEPARATOR);
        }

        $normPath = self::normalizePath($path);
        $normBase = self::normalizePath($base);

        return $normPath === $normBase || str_starts_with($normPath, $normBase . '/');
    }

    /** True if path looks like a private slimify-runtime directory. */
    public static function isRuntimePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $norm = self::normalizePath($path);
        if (preg_match('#/slimify-runtime-[a-f0-9]{16,64}$#', $norm) !== 1) {
            return false;
        }

        // Refuse public roots when resolved.
        $real = realpath($path);
        if ($real !== false && self::isInsidePublicWebRoot($real)) {
            return false;
        }

        return true;
    }

    /** HMAC secret for backup filenames. */
    public static function storageSecret(): string
    {
        if (! function_exists('get_option')) {
            return '';
        }

        $secret = get_option(self::OPTION_STORAGE_SECRET);
        if (is_string($secret) && preg_match('/^[a-f0-9]{64}$/', $secret) === 1) {
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (RandomException) {
            return '';
        }

        update_option(self::OPTION_STORAGE_SECRET, $secret, true);

        return $secret;
    }

    /** Temp directories for queue/locks (never under uploads).
     * @return list<string>
     */
    public static function runtimeRootCandidates(): array
    {
        $key = md5((defined('ABSPATH') ? ABSPATH : __DIR__) . '|blog:' . self::id());
        $suffix = 'slimify-runtime-' . $key;
        $candidates = [];

        if (function_exists('get_temp_dir')) {
            $wpTemp = get_temp_dir();
            if (is_string($wpTemp) && $wpTemp !== '') {
                $candidates[] = rtrim($wpTemp, '/\\') . DIRECTORY_SEPARATOR . $suffix;
            }
        }

        $temp = sys_get_temp_dir();
        if ($temp !== '') {
            $candidates[] = rtrim($temp, '/\\') . DIRECTORY_SEPARATOR . $suffix;
        }

        return array_values(array_unique($candidates));
    }

    /** True if path is a critical system directory. */
    public static function isCriticalDirectory(string $real): bool
    {
        $critical = [
            DIRECTORY_SEPARATOR,
            defined('ABSPATH') ? ABSPATH : '',
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            self::uploadsBase(),
        ];

        $docRoot = self::documentRoot();
        if ($docRoot !== null) {
            $critical[] = $docRoot;
        }

        foreach ($critical as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $resolved = realpath($candidate);
            if ($resolved !== false && $resolved === $real) {
                return true;
            }
        }

        return false;
    }

    /** True if path is under a public web root. */
    public static function isInsidePublicWebRoot(string $real): bool
    {
        foreach (self::publicWebRoots() as $root) {
            if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** Normalise a path for comparison. */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');

        return function_exists('wp_normalize_path') ? wp_normalize_path($path) : $path;
    }

    /** Public web roots for this site. */
    private static function publicWebRoots(): array
    {
        $candidates = [
            defined('ABSPATH') ? ABSPATH : '',
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
        ];

        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (! empty($uploads['basedir'])) {
                $candidates[] = (string) $uploads['basedir'];
            }
        }

        $roots = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $root = realpath($candidate);
            if ($root !== false) {
                $roots[] = $root;
            }
        }

        $docRoot = self::documentRoot();
        if ($docRoot !== null) {
            $roots[] = $docRoot;
        }

        return array_values(array_unique($roots));
    }

    /** Document root, or null. */
    private static function documentRoot(): ?string
    {
        $docRoot = self::documentRootFromRequest();

        if (! function_exists('get_option')) {
            return $docRoot;
        }

        if ($docRoot !== null) {
            if (get_option(self::OPTION_DOCUMENT_ROOT) !== $docRoot) {
                update_option(self::OPTION_DOCUMENT_ROOT, $docRoot, true);
            }

            return $docRoot;
        }

        $stored = get_option(self::OPTION_DOCUMENT_ROOT);

        return is_string($stored) && $stored !== '' && is_dir($stored) ? $stored : null;
    }

    /** Document root from the current request. */
    private static function documentRootFromRequest(): ?string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        $raw = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        if ($raw === '') {
            return null;
        }

        $docRoot = realpath($raw);
        if ($docRoot === false || $docRoot === DIRECTORY_SEPARATOR) {
            return null;
        }

        $abspath = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($abspath === false) {
            return null;
        }

        $servesThisSite = $abspath === $docRoot
            || str_starts_with($abspath, $docRoot . DIRECTORY_SEPARATOR);

        return $servesThisSite ? $docRoot : null;
    }
}
