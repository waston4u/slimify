<?php

declare(strict_types=1);

namespace Slimify\Compression\Drivers;

use Slimify\Compression\CompressorInterface;
use Slimify\Support\BinaryProbe;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\ProcessRunner;
use Slimify\Support\Settings;

final readonly class JpegOptimDriver implements CompressorInterface
{
    /** Constructor. */
    public function __construct(
        private BinaryProbe $probe,
        private PathGuard $pathGuard,
        private Settings $settings,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /** True if this driver handles the MIME type. */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/jpg'], true);
    }

    /** True if the driver is available. */
    public function isAvailable(): bool
    {
        return $this->probe->jpegoptim() !== false;
    }

    /** Compress the file in place. */
    public function compress(string $filePath): bool
    {
        $this->pathGuard->assertAllowed($filePath);

        $binary = $this->probe->jpegoptim();
        if ($binary === false) {
            return false;
        }

        $before = filesize($filePath);
        if ($before === false || $before === 0) {
            return false;
        }

        $dir = dirname($filePath);
        $temp = $dir . DIRECTORY_SEPARATOR . '.slimify-' . bin2hex(random_bytes(8)) . '.jpg';

        try {
            if (! @copy($filePath, $temp)) {
                return false;
            }

            $quality = $this->settings->jpegQuality();
            $result = $this->runner->run([
                $binary,
                '--max=' . $quality,
                '--strip-all',
                '--all-progressive',
                '--quiet',
                $temp,
            ], Limits::PROCESS_TIMEOUT_SECONDS);

            clearstatcache(true, $temp);
            if (! $result['ok'] || ! is_file($temp)) {
                return false;
            }

            $after = filesize($temp);
            if ($after === false || $after === 0 || $after >= $before) {
                return false;
            }

            if (! @rename($temp, $filePath) && ! @copy($temp, $filePath)) {
                return false;
            }

            clearstatcache(true, $filePath);

            return is_file($filePath);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
