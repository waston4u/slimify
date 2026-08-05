<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- atomic temp-to-media file replace after CLI encode.

namespace Slimify\Compression\Drivers;

use Slimify\Compression\CompressorInterface;
use Slimify\Support\BinaryProbe;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\ProcessRunner;


final readonly class PngquantDriver implements CompressorInterface
{
    /** Constructor. */
    public function __construct(
        private BinaryProbe $probe,
        private PathGuard $pathGuard,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /** True if this driver handles the MIME type. */
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'image/png';
    }

    /** True if the driver is available. */
    public function isAvailable(): bool
    {
        return $this->probe->pngquant() !== false;
    }

    /** Compress the file in place. */
    public function compress(string $filePath): bool
    {
        $this->pathGuard->assertAllowed($filePath);

        $binary = $this->probe->pngquant();
        if ($binary === false) {
            return false;
        }

        $before = filesize($filePath);
        if ($before === false || $before === 0) {
            return false;
        }

        $dir = dirname($filePath);
        $temp = $dir . DIRECTORY_SEPARATOR . '.slimify-' . bin2hex(random_bytes(8)) . '.png';

        try {
            if (!copy($filePath, $temp)) {
                return false;
            }

            $result = $this->runner->run([
                $binary,
                // Palette quality.
                '--quality=60-80',
                '--speed',
                '3',
                '--skip-if-larger',
                '--strip',
                '--ext',
                '.png',
                '--force',
                $temp,
            ], Limits::PROCESS_TIMEOUT_SECONDS);

            clearstatcache(true, $temp);
            if (!is_file($temp)) {
                return false;
            }

            if ($result['code'] === 98) {
                return false;
            }

            if (!$result['ok']) {
                return false;
            }

            $after = filesize($temp);
            if ($after === false || $after === 0 || $after >= $before) {
                return false;
            }

            if (!@rename($temp, $filePath) && !@copy($temp, $filePath)) {
                return false;
            }

            clearstatcache(true, $filePath);

            return is_file($filePath);
        } finally {
            if (is_file($temp)) {
                wp_delete_file($temp);
            }
        }
    }
}
