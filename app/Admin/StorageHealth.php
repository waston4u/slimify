<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Random\RandomException;
use Slimify\Compression\CompressionService;
use Slimify\Support\DeferredQueue;
use Slimify\Support\RuntimePaths;
use Slimify\Support\Settings;
use Slimify\Support\Site;


/** Backup path and exposure probes for the settings page. */
final class StorageHealth
{
    private const string OPTION_EXPOSURE_PREFIX = 'slimify_storage_exposure';

    private const string TRANSIENT_EXPOSURE_PREFIX = 'slimify_storage_exposure_probe';

    private const int EXPOSURE_TTL = 12 * HOUR_IN_SECONDS;

    private static function optionKey(): string
    {
        return self::OPTION_EXPOSURE_PREFIX . '_' . Site::id();
    }

    private static function transientKey(): string
    {
        return self::TRANSIENT_EXPOSURE_PREFIX . '_' . Site::id();
    }

    /** Constructor. */
    public function __construct(
        private readonly CompressionService $compressionService,
        private readonly Settings $settings,
        private readonly DeferredQueue $queue,
    ) {
    }

    /** Resolved backup root, or null. */
    public function backupRoot(): ?string
    {
        return $this->compressionService->resolvedBackupRoot();
    }

    /** True if keep-originals is enabled. */
    public function backupsRequested(): bool
    {
        return $this->settings->keepOriginals();
    }

    /** True if originals are required but unavailable. */
    public function backupsBroken(): bool
    {
        return $this->backupsRequested() && $this->backupRoot() === null;
    }

    /** Runtime directory, or null. */
    public function runtimeDirectory(): ?string
    {
        return RuntimePaths::directory();
    }

    /** Queue storage mode: file or option. */
    public function queueStorage(): string
    {
        return $this->queue->storage();
    }

    /** Backup root candidates. */
    public function attemptedBackupRoots(): array
    {
        return Site::backupRootCandidates();
    }

    /** Last known storage exposure status. */
    private function exposure(): string
    {
        $stored = get_option(self::optionKey());

        return in_array($stored, ['protected', 'exposed'], true) ? $stored : 'unknown';
    }

    /** Probe whether originals are publicly served.
     * @throws RandomException
     */
    public function probeExposure(bool $force = false): string
    {
        if (!$force && get_transient(self::transientKey()) !== false) {
            return $this->exposure();
        }

        $result = $this->runExposureProbe();

        set_transient(self::transientKey(), 1, self::EXPOSURE_TTL);
        update_option(self::optionKey(), $result, false);

        return $result;
    }

    /** Run the storage exposure self-test.
     * @throws RandomException
     */
    private function runExposureProbe(): string
    {
        $root = $this->backupRoot();
        if ($root === null) {
            return 'unknown';
        }

        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? realpath((string)$uploads['basedir']) : false;
        $baseurl = isset($uploads['baseurl']) ? (string)$uploads['baseurl'] : '';

        if ($basedir === false || $baseurl === '' || !str_starts_with($root, $basedir . DIRECTORY_SEPARATOR)) {
            return Site::isInsidePublicWebRoot($root) ? 'unknown' : 'protected';
        }

        $token = bin2hex(random_bytes(16));
        $name = 'slimify-probe-' . $token . '.original';
        $canary = $root . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($canary, $token) === false) {
            return 'unknown';
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($root, strlen($basedir)));
        $url = rtrim($baseurl, '/') . '/' . trim($relative, '/') . '/' . $name;

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => str_starts_with($url, 'https://'),
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);

        wp_delete_file($canary);

        if (is_wp_error($response)) {
            return 'unknown';
        }

        return str_contains(wp_remote_retrieve_body($response), $token) ? 'exposed' : 'protected';
    }

    /** Recommended nginx deny rule. */
    public function nginxRule(): string
    {
        return "location ~* /uploads/(sites/[0-9]+/)?slimify/ {\n    deny all;\n}";
    }

    /** Register admin storage notices. */
    public function registerNotice(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        add_action('admin_notices', [$this, 'renderNotice']);
    }

    /** Render admin storage notices. */
    public function renderNotice(): void
    {
        if (!AjaxAuth::canManage()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen->id === 'settings_page_slimify') {
            return;
        }

        $link = sprintf(
            '<p><a href="%s">%s</a></p>',
            esc_url(admin_url('options-general.php?page=slimify')),
            esc_html__('Review Media Compressor storage', 'slimify')
        );

        if ($this->backupsBroken()) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p>%s</div>',
                esc_html__('Slimify: originals are not being kept.', 'slimify'),
                esc_html__(
                    'The originals directory under uploads could not be created, so "Keep originals" is inactive and compression cannot be undone.',
                    'slimify'
                ),
                $link // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
            );
        }

        if ($this->exposure() === 'exposed') {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p>%s</div>',
                esc_html__('Slimify: your kept originals are downloadable.', 'slimify'),
                esc_html__(
                    'This server serves files from the plugin storage directory, so anyone who learns the path can fetch every original. Add the server rule shown on the settings page.',
                    'slimify'
                ),
                $link // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
            );
        }
    }
}
