<?php

declare(strict_types=1);

namespace Slimify\Compression\Drivers;

use Slimify\Compression\CompressorInterface;
use Slimify\Support\BinaryProbe;
use Slimify\Support\Limits;
use Slimify\Support\PathGuard;
use Slimify\Support\Settings;

final readonly class ImagickDriver implements CompressorInterface
{
    /** Constructor. */
    public function __construct(
        private BinaryProbe $probe,
        private PathGuard $pathGuard,
        private Settings $settings,
    ) {
    }

    /** True if this driver handles the MIME type. */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
    }

    /** True if the driver is available. */
    public function isAvailable(): bool
    {
        return $this->probe->imagickAvailable();
    }

    /** Compress the file in place. */
    public function compress(string $filePath): bool
    {
        $this->pathGuard->assertAllowed($filePath);

        if (! $this->isAvailable()) {
            return false;
        }

        $before = filesize($filePath);
        if ($before === false || $before === 0) {
            return false;
        }

        $mime = (string) (wp_check_filetype($filePath)['type'] ?? '');
        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return false;
        }

        $dir = dirname($filePath);
        $temp = $dir . DIRECTORY_SEPARATOR . '.slimify-' . bin2hex(random_bytes(8)) . '.imagick.tmp';
        $image = null;

        try {
            Limits::applyImagickResourceLimits();
            $image = new \Imagick();
            $image->readImage($filePath);
            $format = strtolower((string) $image->getImageFormat());
            if (! in_array($format, ['jpeg', 'jpg', 'png'], true)) {
                return false;
            }
            $image->stripImage();
            $quality = $this->settings->jpegQuality();

            if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
                $image->setImageFormat('jpeg');
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($quality);
                $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            } else {
                $image->setImageFormat('png');
                $image->setImageCompressionQuality(max(0, min(9, (int) round((100 - $quality) / 10))));
                if (method_exists($image, 'setOption')) {
                    $image->setOption('png:compression-level', '9');
                }
            }

            if (! $image->writeImage($temp)) {
                return false;
            }

            clearstatcache(true, $temp);
            $after = filesize($temp);
            if ($after === false || $after === 0 || $after >= $before) {
                return false;
            }

            if (! @rename($temp, $filePath) && ! @copy($temp, $filePath)) {
                return false;
            }

            clearstatcache(true, $filePath);

            return is_file($filePath);
        } catch (\Throwable) {
            return false;
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
