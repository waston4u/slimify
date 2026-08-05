<?php

declare(strict_types=1);

namespace Slimify\Compression\Drivers;

use Slimify\Compression\CompressorInterface;
use Slimify\Support\BinaryProbe;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\ProcessRunner;
use Slimify\Support\Settings;

final readonly class MozjpegDriver implements CompressorInterface
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
        return $this->probe->cjpeg() !== false;
    }

    /** Compress the file in place. */
    public function compress(string $filePath): bool
    {
        $this->pathGuard->assertAllowed($filePath);

        $binary = $this->probe->cjpeg();
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
            $quality = $this->settings->jpegQuality();
            $command = [
                $binary,
                '-quality',
                (string) $quality,
                '-optimize',
                '-progressive',
                '-outfile',
                $temp,
                $filePath,
            ];

            $result = $this->runner->run($command, Limits::PROCESS_TIMEOUT_SECONDS);
            if (! $result['ok'] || ! is_file($temp)) {
                return false;
            }

            clearstatcache(true, $temp);
            $after = filesize($temp);
            if ($after === false || $after === 0 || $after >= $before) {
                return false;
            }

            $mime = (string) (wp_check_filetype($temp)['type'] ?? '');
            if ($mime !== '' && ! in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
                // Verify JPEG SOI if MIME is unclear.
                $fh = fopen($temp, 'rb');
                $header = $fh !== false ? (string) fread($fh, 3) : '';
                if ($fh !== false) {
                    fclose($fh);
                }
                if ($header === '' || ! str_starts_with($header, "\xFF\xD8\xFF")) {
                    return false;
                }
            }

            if (! rename($temp, $filePath)) {
                if (! copy($temp, $filePath)) {
                    return false;
                }
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
