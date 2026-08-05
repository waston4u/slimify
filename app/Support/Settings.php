<?php

declare(strict_types=1);

namespace Slimify\Support;

final class Settings
{
    public const string OPTION_KEY = 'slimify_settings';

    private ?array $cache = null;

    /** All settings with defaults. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $this->cache = [
            'use_binaries' => !array_key_exists('use_binaries', $stored) || $stored['use_binaries'],
            'jpeg_quality' => $this->clampQuality(
                isset($stored['jpeg_quality']) ? (int)$stored['jpeg_quality'] : 80
            ),
            'generate_webp' => !array_key_exists('generate_webp', $stored) || $stored['generate_webp'],
            'keep_originals' => !array_key_exists('keep_originals', $stored) || $stored['keep_originals'],
        ];

        return $this->cache;
    }

    /** True if external binaries may be used. */
    public function useBinaries(): bool
    {
        return $this->all()['use_binaries'];
    }

    /** JPEG quality setting. */
    public function jpegQuality(): int
    {
        return $this->all()['jpeg_quality'];
    }

    /** True if WebP siblings should be generated. */
    public function generateWebp(): bool
    {
        return $this->all()['generate_webp'];
    }

    /** True if original backups should be kept. */
    public function keepOriginals(): bool
    {
        return $this->all()['keep_originals'];
    }

    /** Sanitise settings from form input. */
    public function sanitize(array $input): array
    {
        $clean = [
            'use_binaries' => !empty($input['use_binaries']),
            'jpeg_quality' => $this->clampQuality(
                isset($input['jpeg_quality']) ? (int)$input['jpeg_quality'] : 80
            ),
            'generate_webp' => !empty($input['generate_webp']),
            'keep_originals' => !empty($input['keep_originals']),
        ];

        $this->cache = $clean;

        if (!$clean['generate_webp'] && function_exists('delete_option')) {
            delete_option('slimify_has_webp');
        }

        return $clean;
    }

    /** Clamp JPEG quality to the allowed range. */
    private function clampQuality(int $quality): int
    {
        return max(60, min(95, $quality));
    }
}
