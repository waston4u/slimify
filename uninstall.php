<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- real media paths, flock locks, and CLI temp files.

use Slimify\Support\Site;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$slimifyUninstallAutoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($slimifyUninstallAutoload)) {
    require_once $slimifyUninstallAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Slimify\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

$slimifyUninstallSite = static function (): void {
    $targets = [];
    $blogId = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;

    $backupPath = get_option('slimify_backup_path', '');
    if (is_string($backupPath) && $backupPath !== '') {
        $targets[] = $backupPath;
    }

    if (class_exists(Site::class)) {
        Site::boot();
        $targets[] = Site::storageRoot();
        $targets = array_merge(
            $targets,
            Site::backupRootCandidates(),
            Site::runtimeRootCandidates()
        );
    }

    delete_option('slimify_settings');
    delete_option('slimify_backup_path');
    delete_option('slimify_deferred_queue');
    delete_option('slimify_has_webp');
    delete_option('slimify_document_root');
    delete_option('slimify_storage_secret');
    delete_option('slimify_storage_exposure');
    delete_option('slimify_storage_exposure_' . $blogId);
    delete_transient('slimify_binary_probe');
    delete_transient('slimify_storage_exposure_probe_' . $blogId);
    delete_transient('slimify_bulk_status_cache_' . $blogId);

    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE %s",
            $wpdb->esc_like('_slimify_') . '%'
        )
    );

    $targets = array_unique($targets);
    usort($targets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($targets as $target) {
        $real = realpath($target);
        if ($real === false || !is_dir($real)) {
            continue;
        }

        if (class_exists(Site::class) && Site::isCriticalDirectory($real)) {
            continue;
        }

        // Allowed: …/slimify, …/slimify/originals, or slimify-runtime-*
        $normalised = str_replace('\\', '/', $real);
        $allowed = preg_match(
                '#(?:'
                . '/slimify(?:/originals)?$'
                . '|/slimify-runtime-[a-f0-9]{16,64}$'
                . ')#',
                $normalised
            ) === 1;
        if (!$allowed) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /**
             * @var SplFileInfo $item
             */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                wp_delete_file($item->getPathname());
            }
        }

        @rmdir($real);
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $slimifySiteId) {
        switch_to_blog((int)$slimifySiteId);
        $slimifyUninstallSite();
        restore_current_blog();
    }
} else {
    $slimifyUninstallSite();
}
