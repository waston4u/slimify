<?php

declare(strict_types=1);

namespace Slimify\Support;

/** Queue and compress lock under a private temp directory. */
final class RuntimePaths
{
    private static string|false|null $cache = null;

    private static int $cacheBlog = -1;

    /** Clear the resolved runtime directory cache. */
    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cacheBlog = -1;
    }

    /** Runtime directory for the queue, or null. */
    public static function directory(): ?string
    {
        $blogId = Site::id();
        if (self::$cache !== null && self::$cacheBlog === $blogId) {
            return self::$cache === false ? null : self::$cache;
        }

        $candidates = Site::runtimeRootCandidates();

        $filtered = (string) apply_filters('slimify_runtime_directory', $candidates[0] ?? '', $blogId);
        if ($filtered !== '' && ($candidates === [] || $filtered !== ($candidates[0] ?? ''))) {
            array_unshift($candidates, $filtered);
        }

        self::$cacheBlog = $blogId;

        foreach (array_unique($candidates) as $candidate) {
            $real = self::prepare($candidate);
            if ($real !== null) {
                self::$cache = $real;

                return $real;
            }
        }

        self::$cache = false;

        return null;
    }

    /** Prepare a private temp runtime directory. */
    private static function prepare(string $candidate): ?string
    {
        if ($candidate === '') {
            return null;
        }

        // Refuse paths under uploads before creating the directory.
        $uploads = function_exists('wp_upload_dir') ? (string) (wp_upload_dir()['basedir'] ?? '') : '';
        if ($uploads !== '') {
            $normCand = Site::normalizePath($candidate);
            $normUp = Site::normalizePath($uploads);
            if ($normCand === $normUp || str_starts_with($normCand, $normUp . '/')) {
                return null;
            }
        }

        if (! is_dir($candidate) && ! wp_mkdir_p($candidate)) {
            return null;
        }

        $real = realpath($candidate);
        if ($real === false || ! is_writable($real) || Site::isCriticalDirectory($real)) {
            return null;
        }

        if (Site::isInsidePublicWebRoot($real)) {
            return null;
        }

        ProtectedDirectory::harden($real);

        return $real;
    }
}
