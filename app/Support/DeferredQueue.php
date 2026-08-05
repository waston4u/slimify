<?php

declare(strict_types=1);

namespace Slimify\Support;

use Exception;
use Random\RandomException;

final class DeferredQueue
{
    private const string FILE_NAME = 'queue.json';
    private const string LOCK_NAME = 'queue.lock';
    private const string OPTION_NAME = 'slimify_deferred_queue';

    private const int MAX_ENTRIES = 500;

    private const int OPTION_LOCK_RETRIES = 3;

    /**
     * @var array<string, resource>
     */
    private static array $optionLockHandles = [];

    /** Add an attachment ID to the queue.
     * @return bool False if full or lock failed.
     */
    public function push(int $attachmentId): bool
    {
        if ($attachmentId <= 0) {
            return false;
        }

        $ok = false;

        $this->mutate(function (array $ids) use ($attachmentId, &$ok): array {
            $key = (string) $attachmentId;
            if (isset($ids[$key])) {
                $ids[$key] = time();
                $ok = true;

                return $ids;
            }

            if (count($ids) >= self::MAX_ENTRIES) {
                $ok = false;

                return $ids;
            }

            $ids[$key] = time();
            $ok = true;

            return $ids;
        }, $ok);

        if ($ok) {
            $this->scheduleDrain();
        }

        return $ok;
    }

    /** Peek at the front of the queue. */
    public function peek(int $limit = 1): array
    {
        $limit = max(1, min(20, $limit));
        $keys = array_slice(array_keys($this->read()), 0, $limit);

        return array_map(static fn (string|int $key): int => (int) $key, $keys);
    }

    /** Queued attachment IDs, oldest first. */
    public function allIds(): array
    {
        return array_map(static fn (string|int $key): int => (int) $key, array_keys($this->read()));
    }

    /** Whether the queue contains an attachment. */
    public function contains(int $attachmentId): bool
    {
        if ($attachmentId <= 0) {
            return false;
        }

        return isset($this->read()[(string) $attachmentId]);
    }

    /** Remove an attachment from the queue. */
    public function remove(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $this->mutate(static function (array $ids) use ($attachmentId): array {
            unset($ids[(string) $attachmentId]);

            return $ids;
        });
    }

    /** Return queue length. */
    public function count(): int
    {
        return count($this->read());
    }

    /** Schedule a queue drain event. */
    public function scheduleDrain(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        if (wp_next_scheduled('slimify_drain_queue')) {
            return;
        }

        wp_schedule_single_event(time() + 1, 'slimify_drain_queue');
    }

    /** Queue backend: file or option. */
    public function storage(): string
    {
        return $this->directory() === null ? 'option' : 'file';
    }

    /** Mutate the queue under lock.
     * @param callable(array): array $mutator
     */
    private function mutate(callable $mutator, ?bool &$successFlag = null): void
    {
        $dir = $this->directory();
        if ($dir === null) {
            $this->mutateOption($mutator, $successFlag);

            return;
        }

        $this->mutateFile($dir, $mutator, $successFlag);
    }

    /**
     * @param callable(array): array $mutator
     */
    private function mutateFile(string $dir, callable $mutator, ?bool &$successFlag = null): void
    {
        $lockPath = $dir . DIRECTORY_SEPARATOR . self::LOCK_NAME;
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            $this->mutateOption($mutator, $successFlag);

            return;
        }

        $locked = false;

        try {
            if (! flock($handle, LOCK_EX)) {
                fclose($handle);
                $handle = null;
                $this->mutateOption($mutator, $successFlag);

                return;
            }

            $locked = true;

            $file = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;
            $ids = $this->normalise($this->decodeFile($file));
            $ids = $this->normalise($mutator($ids));

            $json = wp_json_encode($ids);
            if (! is_string($json)) {
                if ($successFlag !== null) {
                    $successFlag = false;
                }

                return;
            }

            $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($temp, $json) === false) {
                if ($successFlag !== null) {
                    $successFlag = false;
                }

                return;
            }
            if (! @rename($temp, $file)) {
                @unlink($temp);
                if ($successFlag !== null) {
                    $successFlag = false;
                }
            }
        } catch (RandomException | Exception) {
            if ($successFlag !== null) {
                $successFlag = false;
            }
        } finally {
            if (is_resource($handle)) {
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
        }
    }

    /**
     * @param callable(array): array $mutator
     */
    private function mutateOption(callable $mutator, ?bool &$successFlag = null): void
    {
        $lockName = 'slimify_queue_' . Site::id();
        $gotLock = $this->acquireOptionLock($lockName);
        if (! $gotLock) {
            if ($successFlag !== null) {
                $successFlag = false;
            }

            return;
        }

        try {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::OPTION_NAME, 'options');
            }

            $ids = $this->normalise($this->readOption());
            $ids = $this->normalise($mutator($ids));

            if ($ids === []) {
                delete_option(self::OPTION_NAME);

                return;
            }

            update_option(self::OPTION_NAME, $ids, false);
        } finally {
            $this->releaseOptionLock($lockName);
        }
    }

    /** Acquire a MySQL / file lock with retries. */
    private function acquireOptionLock(string $name): bool
    {
        for ($i = 0; $i < self::OPTION_LOCK_RETRIES; ++$i) {
            if ($this->tryAcquireOptionLock($name)) {
                return true;
            }
            usleep(200_000);
        }

        return false;
    }

    private function tryAcquireOptionLock(string $name): bool
    {
        global $wpdb;

        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $name));

            return (string) $result === '1';
        }

        $path = trailingslashit(sys_get_temp_dir()) . $name . '.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$optionLockHandles[$name] = $handle;

                return true;
            }
            usleep(25_000);
        }

        fclose($handle);

        return false;
    }

    private function releaseOptionLock(string $name): void
    {
        global $wpdb;

        if (isset(self::$optionLockHandles[$name]) && is_resource(self::$optionLockHandles[$name])) {
            flock(self::$optionLockHandles[$name], LOCK_UN);
            fclose(self::$optionLockHandles[$name]);
            unset(self::$optionLockHandles[$name]);

            return;
        }

        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private function read(): array
    {
        $dir = $this->directory();
        if ($dir === null) {
            return $this->normalise($this->readOption());
        }

        return $this->normalise($this->decodeFile($dir . DIRECTORY_SEPARATOR . self::FILE_NAME));
    }

    private function readOption(): array
    {
        $stored = get_option(self::OPTION_NAME, []);

        return is_array($stored) ? $stored : [];
    }

    private function decodeFile(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function normalise(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $id = (int) $key;
            if ($id > 0) {
                $out[(string) $id] = is_int($value) ? $value : time();
            }
        }

        return $out;
    }

    private function directory(): ?string
    {
        return RuntimePaths::directory();
    }
}
