<?php

declare(strict_types=1);

namespace Slimify\Offload;

use function abs;
use function is_array;
use function is_int;
use function is_string;
use function strlen;
use function substr;

/**
 * Fixes numeric image size names (e.g. add_image_size('680', …)).
 */
final class SizeKeyRepair
{
    private const string OBJECT_KEY_PREFIX = 'slimify-size-';

    /** Register hooks. */
    public function register(): void
    {
        add_filter('wp_get_missing_image_subsizes', [$this, 'filterMissing'], 10, 2);
        // Offloaders treat key 0 as the full-size file and renumber integer keys.
        add_filter('as3cf_attachment_file_paths', [$this, 'filterOffloadPaths'], PHP_INT_MAX);
        // After the offloader (10), before WebP delivery (999).
        add_filter('wp_calculate_image_srcset', [$this, 'filterSrcset'], 20, 5);
    }

    /**
     * Point srcset entries for integer-keyed sizes at their own file.
     *
     * @param array<int, array<string, mixed>>|mixed $sources
     * @param array<string, mixed>|mixed $imageMeta
     * @return array<int, array<string, mixed>>|mixed
     */
    public function filterSrcset(
        mixed $sources,
        mixed $sizeArray = null,
        mixed $imageSrc = null,
        mixed $imageMeta = null,
        mixed $attachmentId = 0
    ): mixed {
        if (!is_array($sources) || !is_array($imageMeta) || !is_array($imageMeta['sizes'] ?? null)) {
            return $sources;
        }

        $fullName = wp_basename((string)($imageMeta['file'] ?? ''));
        if ($fullName === '') {
            return $sources;
        }

        foreach ($imageMeta['sizes'] as $key => $size) {
            if (!is_int($key) || !is_array($size) || empty($size['file'])) {
                continue;
            }

            $width = (int)($size['width'] ?? 0);
            $url = $sources[$width]['url'] ?? null;
            if (!is_string($url)) {
                continue;
            }

            $path = (string)wp_parse_url($url, PHP_URL_PATH);
            if (wp_basename($path) !== $fullName || $width === (int)($imageMeta['width'] ?? 0)) {
                continue;
            }

            $sources[$width]['url'] = str_replace(
                $path,
                substr($path, 0, -strlen($fullName)) . wp_basename((string)$size['file']),
                $url
            );
        }

        return $sources;
    }

    /**
     * @param array<int|string, string>|mixed $paths
     * @return array<int|string, string>|mixed
     */
    public function filterOffloadPaths(mixed $paths): mixed
    {
        if (!is_array($paths)) {
            return $paths;
        }

        $result = [];
        foreach ($paths as $key => $path) {
            $result[is_int($key) ? self::OBJECT_KEY_PREFIX . $key : $key] = $path;
        }

        return $result;
    }

    /**
     * Drop numeric sizes that already exist under an orphaned integer key.
     *
     * @param array<int|string, array<string, mixed>>|mixed $missing
     * @param array<string, mixed>|mixed $imageMeta
     * @return array<int|string, array<string, mixed>>|mixed
     */
    public function filterMissing(mixed $missing, mixed $imageMeta = []): mixed
    {
        if (!is_array($missing) || $missing === [] || !is_array($imageMeta)) {
            return $missing;
        }

        $sizes = $imageMeta['sizes'] ?? null;
        $width = (int)($imageMeta['width'] ?? 0);
        $height = (int)($imageMeta['height'] ?? 0);
        if (!is_array($sizes) || $sizes === [] || $width <= 0 || $height <= 0) {
            return $missing;
        }

        $registered = wp_get_registered_image_subsizes();
        $orphans = [];
        foreach ($sizes as $key => $entry) {
            if (is_int($key) && !isset($registered[$key]) && is_array($entry)) {
                $orphans[$key] = $entry;
            }
        }
        if ($orphans === []) {
            return $missing;
        }

        foreach ($missing as $name => $size) {
            if (!is_int($name) || !is_array($size)) {
                continue;
            }

            $dims = image_resize_dimensions(
                $width,
                $height,
                (int)($size['width'] ?? 0),
                (int)($size['height'] ?? 0),
                $size['crop'] ?? false
            );
            if (!is_array($dims)) {
                continue;
            }

            foreach ($orphans as $key => $entry) {
                if (
                    abs((int)($entry['width'] ?? 0) - (int)$dims[4]) <= 1
                    && abs((int)($entry['height'] ?? 0) - (int)$dims[5]) <= 1
                ) {
                    unset($missing[$name], $orphans[$key]);
                    break;
                }
            }
        }

        return $missing;
    }
}
