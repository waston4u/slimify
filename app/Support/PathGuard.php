<?php

declare(strict_types=1);

namespace Slimify\Support;

use InvalidArgumentException;

final class PathGuard
{
    /**
     * @var list<string>|null
     */
    private ?array $roots = null;

    private int $cachedForBlog = -1;

    /** True if the file is under allowed roots. */
    public function isAllowed(string $filePath): bool
    {
        $real = realpath($filePath);
        if ($real === false || ! is_file($real)) {
            return false;
        }

        foreach ($this->allowedRoots() as $root) {
            if ($root !== '' && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** Throw if the path is outside allowed roots. */
    public function assertAllowed(string $filePath): void
    {
        if (! $this->isAllowed($filePath)) {
            throw new InvalidArgumentException('File path is outside the allowed directories.');
        }
    }

    /** Allowed path roots. */
    private function allowedRoots(): array
    {
        $blogId = Site::id();
        if ($this->roots !== null && $this->cachedForBlog === $blogId) {
            return $this->roots;
        }

        $roots = [];

        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($basedir !== '') {
            $uploadReal = realpath($basedir);
            if ($uploadReal !== false) {
                $roots[] = $uploadReal;
            }
        }

        foreach ($this->temporaryRoots() as $tempRoot) {
            $roots[] = $tempRoot;
        }

        $this->roots = array_values(array_unique($roots));
        $this->cachedForBlog = $blogId;

        return $this->roots;
    }

    /** Allowed temp directories. */
    private function temporaryRoots(): array
    {
        $candidates = [sys_get_temp_dir()];
        if (function_exists('get_temp_dir')) {
            $candidates[] = get_temp_dir();
        }

        $roots = [];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $real = realpath($candidate);
            if ($real === false || Site::isInsidePublicWebRoot($real)) {
                continue;
            }

            $roots[] = $real;
        }

        return $roots;
    }
}
