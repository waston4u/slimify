<?php

declare(strict_types=1);

namespace Slimify\Compression;

use Slimify\Compression\Drivers\ImagickDriver;
use Slimify\Compression\Drivers\JpegOptimDriver;
use Slimify\Compression\Drivers\MozjpegDriver;
use Slimify\Compression\Drivers\PngquantDriver;
use Slimify\Support\BinaryProbe;
use Slimify\Support\PathGuard;
use Slimify\Support\Settings;

final class DriverResolver
{
    /**
     * @var list<CompressorInterface>
     */
    private array $drivers;

    /** Constructor. */
    public function __construct(
        private readonly Settings $settings,
        BinaryProbe $probe,
        ?PathGuard $pathGuard = null,
    ) {
        $pathGuard ??= new PathGuard();

        $this->drivers = [
            new PngquantDriver($probe, $pathGuard),
            new MozjpegDriver($probe, $pathGuard, $settings),
            new JpegOptimDriver($probe, $pathGuard, $settings),
            new ImagickDriver($probe, $pathGuard, $settings),
        ];
    }

    /** Resolve a binary path. */
    public function resolve(string $mimeType): ?CompressorInterface
    {
        $useBinaries = $this->settings->useBinaries();

        foreach ($this->drivers as $driver) {
            if (! $driver->supports($mimeType)) {
                continue;
            }

            if ($driver instanceof ImagickDriver) {
                if ($driver->isAvailable()) {
                    return $driver;
                }
                continue;
            }

            if (! $useBinaries) {
                continue;
            }

            if ($driver->isAvailable()) {
                return $driver;
            }
        }

        return null;
    }
}
