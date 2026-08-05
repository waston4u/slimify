<?php

declare(strict_types=1);

namespace Slimify\Support;

final class ProtectedDirectory
{
    private const string HTACCESS = "Require all denied\nDeny from all\nOptions -Indexes\n";

    private const string WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . '<configuration><system.webServer><authorization>'
    . "<deny users=\"*\" /></authorization></system.webServer></configuration>\n";

    private const string INDEX = "<?php\n// Silence is golden.\n";

    /** Harden this directory and owned parents. */
    public static function hardenStorageTree(string $directory): void
    {
        self::harden($directory);

        $resolved = realpath($directory);
        if ($resolved === false) {
            return;
        }

        foreach ([Site::storageRoot(), Site::storageContainer()] as $owned) {
            $ownedReal = realpath($owned);
            if ($ownedReal === false) {
                continue;
            }

            if (
                $resolved === $ownedReal
                || str_starts_with($resolved, $ownedReal . DIRECTORY_SEPARATOR)
            ) {
                self::harden($ownedReal);
            }
        }
    }

    /** Write deny files for Apache and IIS. */
    public static function harden(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        foreach (
            [
                '.htaccess' => self::HTACCESS,
                'web.config' => self::WEB_CONFIG,
                'index.php' => self::INDEX,
            ] as $name => $contents
        ) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                @file_put_contents($path, $contents);
            }
        }
    }
}
