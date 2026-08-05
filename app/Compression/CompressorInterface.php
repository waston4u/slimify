<?php

declare(strict_types=1);

namespace Slimify\Compression;

interface CompressorInterface
{
    /** True if this driver handles the MIME type. */
    public function supports(string $mimeType): bool;

    /** True if the driver is available. */
    public function isAvailable(): bool;

    /** Compress the file in place. */
    public function compress(string $filePath): bool;
}
