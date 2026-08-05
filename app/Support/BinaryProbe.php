<?php

declare(strict_types=1);

namespace Slimify\Support;

final class BinaryProbe
{
    private const string TRANSIENT_KEY = 'slimify_binary_probe';
    private const int TRANSIENT_TTL = 3600;

    /**
     * @var array<string, string|false>|null
     */
    private ?array $cache = null;

    private ProcessRunner $runner;

    /** Constructor. */
    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
    }

    /** True if proc_open is available. */
    private function procOpenAvailable(): bool
    {
        return function_exists('proc_open');
    }

    /** True if Imagick is available. */
    public function imagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /** Path to pngquant, or false. */
    public function pngquant(): string|false
    {
        return $this->resolve('pngquant');
    }

    /** Path to MozJPEG cjpeg, or false. */
    public function cjpeg(): string|false
    {
        $resolved = $this->resolve('cjpeg');
        if ($resolved === false) {
            return false;
        }

        if (! $this->isMozjpegBinary($resolved)) {
            $this->forgetCached('cjpeg');
            $moz = $this->locateMozjpeg();
            $map = $this->cachedMap();
            if ($moz !== false) {
                $map['cjpeg'] = $moz;
                $this->cache = $map;
                set_transient(self::TRANSIENT_KEY, $map, self::TRANSIENT_TTL);

                return $moz;
            }

            $map['cjpeg'] = false;
            $this->cache = $map;
            set_transient(self::TRANSIENT_KEY, $map, self::TRANSIENT_TTL);

            return false;
        }

        return $resolved;
    }

    /** Path to jpegoptim, or false. */
    public function jpegoptim(): string|false
    {
        return $this->resolve('jpegoptim');
    }

    /** Path to cwebp, or false. */
    public function cwebp(): string|false
    {
        return $this->resolve('cwebp');
    }

    /** Probe all binaries. */
    private function probeAll(bool $fresh = false): array
    {
        if ($fresh) {
            $this->flush();
        }

        return [
            'proc_open' => $this->procOpenAvailable(),
            'imagick' => $this->imagickAvailable(),
            'pngquant' => $this->pngquant(),
            'cjpeg' => $this->cjpeg(),
            'jpegoptim' => $this->jpegoptim(),
            'cwebp' => $this->cwebp(),
        ];
    }

    /** Status report for the admin UI. */
    public function statusReport(bool $fresh = false): array
    {
        $probe = $this->probeAll($fresh);
        $manager = $this->packageManager();

        $items = [
            [
                'id' => 'proc_open',
                'label' => 'PHP proc_open()',
                'available' => (bool) $probe['proc_open'],
            ],
            [
                'id' => 'pngquant',
                'label' => 'pngquant',
                'available' => $probe['pngquant'] !== false,
            ],
            [
                'id' => 'cjpeg',
                'label' => 'MozJPEG (cjpeg)',
                'available' => $probe['cjpeg'] !== false,
            ],
            [
                'id' => 'jpegoptim',
                'label' => 'jpegoptim',
                'available' => $probe['jpegoptim'] !== false,
            ],
            [
                'id' => 'cwebp',
                'label' => 'cwebp',
                'available' => $probe['cwebp'] !== false,
            ],
            [
                'id' => 'imagick',
                'label' => 'Imagick (PHP extension)',
                'available' => (bool) $probe['imagick'],
            ],
        ];

        foreach ($items as &$item) {
            $item['install'] = $item['available']
                ? null
                : $this->installAdvice($item['id'], $manager);
        }
        unset($item);

        return $items;
    }

    /** Install hint for a missing tool. */
    private function installAdvice(string $tool, string $manager): string
    {
        if ($tool === 'proc_open') {
            return 'Remove proc_open from disable_functions in php.ini, then restart PHP-FPM or Apache.';
        }

        if ($tool === 'cjpeg') {
            return match ($manager) {
                'brew' => 'brew install mozjpeg — then put its bin directory on PATH (keg-only: brew --prefix mozjpeg)/bin.',
                'apt' => 'Install real MozJPEG (not libjpeg-turbo). Build from https://github.com/mozilla/mozjpeg or use a distribution package named mozjpeg, then ensure cjpeg is on PATH.',
                'apk' => 'Install MozJPEG from your Alpine packages or build from https://github.com/mozilla/mozjpeg. libjpeg-turbo cjpeg is not accepted.',
                'dnf', 'yum' => 'Install MozJPEG (mozjpeg) or build from https://github.com/mozilla/mozjpeg. libjpeg-turbo cjpeg is not accepted.',
                default => 'Install MozJPEG and put its cjpeg on PATH. Ordinary libjpeg-turbo cjpeg is rejected. Source: https://github.com/mozilla/mozjpeg',
            };
        }

        $pkg = $this->packagesFor($manager, [$tool]);
        $name = $pkg[0] ?? $tool;

        $cmd = match ($manager) {
            'apt' => 'sudo apt update && sudo apt install ' . $name,
            'apk' => 'sudo apk add ' . $name,
            'dnf' => 'sudo dnf install ' . $name,
            'yum' => 'sudo yum install ' . $name,
            'brew' => 'brew install ' . $name,
            default => 'Install package "' . $name . '" with your system package manager.',
        };

        if ($tool === 'imagick') {
            return $cmd . ' — then restart PHP-FPM and check with: php -m | grep -i imagick';
        }

        return $cmd;
    }

    /** Clear the binary probe cache. */
    private function flush(): void
    {
        $this->cache = null;
        delete_transient(self::TRANSIENT_KEY);
    }

    /** Resolve a binary path. */
    private function resolve(string $binary): string|false
    {
        $map = $this->cachedMap();
        if (array_key_exists($binary, $map)) {
            return $map[$binary];
        }

        $found = $this->locate($binary);
        $map[$binary] = $found;
        $this->cache = $map;
        set_transient(self::TRANSIENT_KEY, $map, self::TRANSIENT_TTL);

        return $found;
    }

    /** Cached probe results. */
    private function cachedMap(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_transient(self::TRANSIENT_KEY);
        if (is_array($stored)) {
            /**
             * @var array<string, string|false> $stored
             */
            $this->cache = $stored;

            return $this->cache;
        }

        $this->cache = [];

        return $this->cache;
    }

    /** Find a binary on the system. */
    private function locate(string $binary): string|false
    {
        if (! $this->procOpenAvailable()) {
            return false;
        }

        $candidates = $this->candidatePaths($binary);
        foreach ($candidates as $candidate) {
            if (! is_executable($candidate)) {
                continue;
            }
            if ($binary === 'cjpeg' && ! $this->isMozjpegBinary($candidate)) {
                continue;
            }

            return $candidate;
        }

        $which = $this->runner->run(['/usr/bin/env', 'which', $binary], Limits::PROBE_TIMEOUT_SECONDS);
        if ($which['ok']) {
            $path = trim($which['stdout']);
            if ($path !== '' && is_executable($path)) {
                if ($binary === 'cjpeg' && ! $this->isMozjpegBinary($path)) {
                    return false;
                }

                return $path;
            }
        }

        return false;
    }

    /** Candidate install paths for a binary. */
    private function candidatePaths(string $binary): array
    {
        $paths = [];

        if ($binary === 'cjpeg') {
            $paths = array_merge($paths, [
                '/opt/homebrew/opt/mozjpeg/bin/cjpeg',
                '/usr/local/opt/mozjpeg/bin/cjpeg',
                '/opt/mozjpeg/bin/cjpeg',
                '/usr/local/mozjpeg/bin/cjpeg',
            ]);
        }

        $paths = array_merge($paths, [
            '/opt/homebrew/bin/' . $binary,
            '/usr/local/bin/' . $binary,
            '/usr/bin/' . $binary,
            '/bin/' . $binary,
        ]);

        return $paths;
    }

    /** Find a MozJPEG cjpeg binary. */
    private function locateMozjpeg(): string|false
    {
        foreach ($this->candidatePaths('cjpeg') as $candidate) {
            if (is_executable($candidate) && $this->isMozjpegBinary($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    /** True if the path is real MozJPEG. */
    private function isMozjpegBinary(string $path): bool
    {
        $result = $this->runner->run([$path, '-version'], Limits::PROBE_TIMEOUT_SECONDS);
        $output = strtolower($result['stdout'] . "\n" . $result['stderr']);

        return str_contains($output, 'mozjpeg');
    }

    /** Drop one entry from the probe cache. */
    private function forgetCached(string $binary): void
    {
        $map = $this->cachedMap();
        unset($map[$binary]);
        $this->cache = $map;
    }

    /** Detected package manager name. */
    private function packageManager(): string
    {
        foreach ([
            'apt' => '/usr/bin/apt',
            'apk' => '/sbin/apk',
            'dnf' => '/usr/bin/dnf',
            'yum' => '/usr/bin/yum',
            'brew' => '/opt/homebrew/bin/brew',
            'brew-intel' => '/usr/local/bin/brew',
        ] as $manager => $path) {
            if (is_executable($path)) {
                return $manager === 'brew-intel' ? 'brew' : $manager;
            }
        }

        return 'unknown';
    }

    /** Package names for missing tools. */
    private function packagesFor(string $packageManager, array $missing): array
    {
        $map = match ($packageManager) {
            'apt' => [
                'pngquant' => 'pngquant',
                'jpegoptim' => 'jpegoptim',
                'cwebp' => 'webp',
                'imagick' => 'php-imagick',
            ],
            'apk' => [
                'pngquant' => 'pngquant',
                'jpegoptim' => 'jpegoptim',
                'cwebp' => 'libwebp-tools',
                'imagick' => 'php84-pecl-imagick',
            ],
            'dnf', 'yum' => [
                'pngquant' => 'pngquant',
                'jpegoptim' => 'jpegoptim',
                'cwebp' => 'libwebp-tools',
                'imagick' => 'php-pecl-imagick',
            ],
            'brew' => [
                'pngquant' => 'pngquant',
                'jpegoptim' => 'jpegoptim',
                'cwebp' => 'webp',
                'imagick' => 'imagemagick',
            ],
            default => [
                'pngquant' => 'pngquant',
                'jpegoptim' => 'jpegoptim',
                'cwebp' => 'WebP tools (cwebp)',
                'imagick' => 'ImageMagick and the PHP Imagick extension',
            ],
        };

        $packages = [];
        foreach ($missing as $tool) {
            if (isset($map[$tool])) {
                $packages[] = $map[$tool];
            }
        }

        return array_values(array_unique($packages));
    }
}
