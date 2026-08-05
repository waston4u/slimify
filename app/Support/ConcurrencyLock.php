<?php

declare(strict_types=1);

namespace Slimify\Support;

final class ConcurrencyLock
{
    /**
     * @var resource|null
     */
    private $handle = null;

    /** Acquire the lock. */
    public function acquire(bool $blocking = false): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $path = $this->lockPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            return false;
        }

        ProtectedDirectory::harden($dir);

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        $flags = LOCK_EX;
        if (! $blocking) {
            $flags |= LOCK_NB;
        }

        if (! flock($handle, $flags)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return true;
    }

    /** Release the lock. */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /** Path of the compress lock file. */
    private function lockPath(): string
    {
        $runtime = RuntimePaths::directory();
        if ($runtime !== null) {
            return $runtime . DIRECTORY_SEPARATOR . 'compress.lock';
        }

        $dir = trailingslashit(sys_get_temp_dir());
        if (function_exists('get_temp_dir')) {
            $wpTemp = get_temp_dir();
            if (is_string($wpTemp) && $wpTemp !== '' && is_dir($wpTemp) && is_writable($wpTemp)) {
                $dir = trailingslashit($wpTemp);
            }
        }

        $key = defined('ABSPATH') ? ABSPATH : $dir;
        if (Site::isMultisite()) {
            $key .= '|blog:' . Site::id();
        }

        return $dir . 'slimify-compress-' . md5($key) . '.lock';
    }
}
