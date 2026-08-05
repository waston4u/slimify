<?php

declare(strict_types=1);

namespace Slimify\Support;

final class ProcessRunner
{
    /** Run a process with a timeout. */
    public function run(array $command, int $timeoutSeconds = Limits::PROCESS_TIMEOUT_SECONDS): array
    {
        if ($command === [] || ! function_exists('proc_open')) {
            return [
                'ok' => false,
                'code' => 127,
                'stdout' => '',
                'stderr' => 'proc_open unavailable',
            ];
        }

        $timeoutSeconds = max(1, min(Limits::PROCESS_TIMEOUT_HARD_CAP, $timeoutSeconds));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null, [
            'bypass_shell' => true,
        ]);

        if (! is_resource($process)) {
            return [
                'ok' => false,
                'code' => 127,
                'stdout' => '',
                'stderr' => 'Failed to start process',
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $status = ['running' => true, 'exitcode' => -1];

        while (true) {
            $status = proc_get_status($process);
            $stdout = $this->appendCapped($stdout, (string) stream_get_contents($pipes[1]));
            $stderr = $this->appendCapped($stderr, (string) stream_get_contents($pipes[2]));

            if (! $status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                $this->terminateProcess($process);
                // Drain pipes briefly after kill.
                $drainUntil = microtime(true) + 2.0;
                while (microtime(true) < $drainUntil) {
                    $status = proc_get_status($process);
                    $stdout = $this->appendCapped($stdout, (string) @stream_get_contents($pipes[1]));
                    $stderr = $this->appendCapped($stderr, (string) @stream_get_contents($pipes[2]));
                    if (empty($status['running'])) {
                        break;
                    }
                    usleep(50_000);
                }
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            // Wait up to 100ms for output (no busy loop).
            @stream_select($read, $write, $except, 0, 100000);
        }

        $stdout = $this->appendCapped($stdout, (string) @stream_get_contents($pipes[1]));
        $stderr = $this->appendCapped($stderr, (string) @stream_get_contents($pipes[2]));
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        if ($timedOut) {
            $this->terminateProcess($process, true);
        }

        $code = @proc_close($process);
        if (! $timedOut && isset($status['exitcode']) && (int) $status['exitcode'] >= 0) {
            $code = (int) $status['exitcode'];
        }

        if ($timedOut) {
            return [
                'ok' => false,
                'code' => 124,
                'stdout' => $stdout,
                'stderr' => trim($stderr . "\nProcess timed out"),
            ];
        }

        return [
            'ok' => $code === 0,
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** Stop a running process. */
    private function terminateProcess(mixed $process, bool $force = false): void
    {
        $status = proc_get_status($process);
        if (empty($status['running']) && empty($status['pid'])) {
            return;
        }

        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
        if ($pid > 0 && function_exists('posix_kill')) {
            if (! $force) {
                @posix_kill($pid, 15);
                @posix_kill(-$pid, 15);
                usleep(200_000);
                $status = proc_get_status($process);
                if (empty($status['running'])) {
                    return;
                }
            }
            @posix_kill($pid, 9);
            @posix_kill(-$pid, 9);
            @proc_terminate($process, 9);

            return;
        }

        @proc_terminate($process, $force ? 9 : 15);
        if (! $force) {
            usleep(200_000);
            $status = proc_get_status($process);
            if (! empty($status['running'])) {
                @proc_terminate($process, 9);
            }
        }
    }

    /** Append process output up to the size cap. */
    private function appendCapped(string $existing, string $chunk): string
    {
        if ($chunk === '') {
            return $existing;
        }

        $room = Limits::PROCESS_OUTPUT_CAP_BYTES - strlen($existing);
        if ($room <= 0) {
            return $existing;
        }

        if (strlen($chunk) > $room) {
            $chunk = substr($chunk, 0, $room);
        }

        return $existing . $chunk;
    }
}
