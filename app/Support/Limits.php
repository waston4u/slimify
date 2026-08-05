<?php

declare(strict_types=1);

namespace Slimify\Support;

final class Limits
{
    /** Maximum file size (bytes). */
    public const int MAX_FILE_BYTES = 25_165_824; // 24 MiB

    /** Maximum width × height (header check only). */
    public const int MAX_PIXELS = 36_000_000; // ~6000×6000

    /** Skip files smaller than this (bytes) */
    public const int MIN_FILE_BYTES = 2048;

    /** Timeout per external process (seconds) */
    public const int PROCESS_TIMEOUT_SECONDS = 15;

    /** Binary detection timeout (seconds) */
    public const int PROBE_TIMEOUT_SECONDS = 3;

    /** WebP process timeout (seconds) */
    public const int WEBP_TIMEOUT_SECONDS = 10;

    /** Wall-clock budget for all WebP attempts on one file */
    public const float WEBP_ENCODE_BUDGET_SECONDS = 12.0;

    /** Maximum time per attachment across all sizes (seconds) */
    public const float ATTACHMENT_BUDGET_SECONDS = 40.0;

    /** Maximum intermediate sizes per attachment */
    public const int MAX_SIZES_PER_ATTACHMENT = 10;

    /** Imagick memory limit (bytes) */
    public const int|float IMAGICK_MEMORY_BYTES = 128 * 1024 * 1024;

    /** Imagick memory map limit (bytes) */
    public const int|float IMAGICK_MAP_BYTES = 256 * 1024 * 1024;

    /** Imagick time limit (seconds) */
    public const int IMAGICK_TIME_SECONDS = 15;

    /** Maximum captured process output (bytes) */
    public const int PROCESS_OUTPUT_CAP_BYTES = 65_536;

    /** CLI attachments per query page */
    public const int CLI_BATCH_SIZE = 50;

    /** CLI maximum --batch-size */
    public const int CLI_BATCH_SIZE_MAX = 50;

    /** CLI pause between pages (microseconds) */
    public const int CLI_BATCH_PAUSE_US = 100_000;

    /** CLI pause between attachments (microseconds) */
    public const int CLI_ITEM_PAUSE_US = 50_000;

    /** Offload maximum intermediate sizes */
    public const int OFFLOAD_MAX_SIZES = 8;

    /** Offload download timeout (seconds) */
    public const int DOWNLOAD_TIMEOUT_SECONDS = 30;

    /** Request time limit while a job holds the lock (seconds) */
    public const int REQUEST_TIME_LIMIT_SECONDS = 90;

    /** Absolute process timeout cap (seconds) */
    public const int PROCESS_TIMEOUT_HARD_CAP = 30;

    /** Deferred queue: maximum items per drain pass */
    public const int QUEUE_DRAIN_MAX = 5;

    /** Deferred queue: wall-clock budget per drain (seconds) */
    public const float QUEUE_DRAIN_BUDGET_SECONDS = 25.0;

    /** Supported MIME types. */
    public static function supportedMimes(): array
    {
        return ['image/jpeg', 'image/jpg', 'image/png'];
    }

    /** True if the MIME type is supported. */
    public static function isSupportedMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));

        return in_array($mime, self::supportedMimes(), true);
    }

    /** True if the file is within size and pixel limits. */
    public static function isFileWithinLimits(string $filePath): bool
    {
        if ($filePath === '' || ! is_file($filePath) || ! is_readable($filePath)) {
            return false;
        }

        clearstatcache(true, $filePath);
        $size = filesize($filePath);
        if ($size === false || $size < self::MIN_FILE_BYTES || $size > self::MAX_FILE_BYTES) {
            return false;
        }

        $info = @getimagesize($filePath);
        if (! is_array($info) || ! isset($info[0], $info[1])) {
            // Invalid image header.
            return false;
        }

        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        // Cap extreme dimensions.
        if ($w > 10000 || $h > 10000) {
            return false;
        }

        return ($w * $h) <= self::MAX_PIXELS;
    }

    /** Raise the PHP time limit for a long job. */
    public static function raiseRequestTimeLimit(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit(self::REQUEST_TIME_LIMIT_SECONDS);
    }

    /** Apply Imagick resource limits. */
    public static function applyImagickResourceLimits(): void
    {
        if (! class_exists(\Imagick::class)) {
            return;
        }

        try {
            if (defined('Imagick::RESOURCETYPE_MEMORY')) {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, self::IMAGICK_MEMORY_BYTES);
            }
            if (defined('Imagick::RESOURCETYPE_MAP')) {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, self::IMAGICK_MAP_BYTES);
            }
            if (defined('Imagick::RESOURCETYPE_AREA')) {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_PIXELS);
            }
            if (defined('Imagick::RESOURCETYPE_TIME')) {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, self::IMAGICK_TIME_SECONDS);
            }
            if (defined('Imagick::RESOURCETYPE_THREAD')) {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
            }
        } catch (\Throwable) {
            // Best effort.
        }
    }
}
