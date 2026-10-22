<?php

declare(strict_types=1);

namespace Slimify\Admin;

/** Admin CSS/JS loader; config printed as application/json only. */
final class Assets
{
    private const string BASE = 'assets/';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $pendingJson = [];

    private static bool $hooksBound = false;

    /** Absolute path to a plugin asset. */
    public static function path(string $relative): string
    {
        return SLIMIFY_PATH . self::BASE . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** Public URL for a plugin asset. */
    public static function url(string $relative): string
    {
        return SLIMIFY_URL . self::BASE . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** Cache-busting version from filemtime. */
    public static function version(string $relative): string
    {
        $path = self::path($relative);

        return is_readable($path) ? (string)filemtime($path) : SLIMIFY_VERSION;
    }

    /**
     * Enqueue a shared + page-specific CSS pair.
     *
     * @param list<string> $deps
     */
    public static function style(string $handle, string $relative, array $deps = []): void
    {
        wp_enqueue_style(
            $handle,
            self::url($relative),
            $deps,
            self::version($relative)
        );
    }

    /**
     * Enqueue a JS file (footer).
     *
     * @param list<string> $deps
     */
    public static function script(string $handle, string $relative, array $deps = [], bool $inFooter = true): void
    {
        wp_enqueue_script(
            $handle,
            self::url($relative),
            $deps,
            self::version($relative),
            $inFooter
        );
    }

    /**
     * Load shared admin CSS/JS (common.css + common.js).
     *
     * @param list<string> $scriptDeps Extra script dependencies (e.g. jquery).
     */
    public static function enqueueCommon(array $scriptDeps = []): void
    {
        self::style('slimify-admin-common', 'admin/common.css');
        self::script('slimify-admin-common', 'admin/common.js', $scriptDeps);
    }

    /**
     * Queue a JSON config blob for the current admin page.
     * Printed before footer scripts for readConfig(id).
     *
     * @param array<string, mixed> $data
     */
    public static function config(string $elementId, array $data): void
    {
        self::$pendingJson[$elementId] = $data;
        self::bindFooterHooks();
    }

    /** Print JSON configs before footer scripts. */
    private static function bindFooterHooks(): void
    {
        if (self::$hooksBound) {
            return;
        }

        self::$hooksBound = true;
        add_action('admin_print_footer_scripts', [self::class, 'printPendingJson'], 1);
    }

    /** Print pending application/json config tags. */
    public static function printPendingJson(): void
    {
        if (self::$pendingJson === []) {
            return;
        }

        foreach (self::$pendingJson as $elementId => $data) {
            $json = wp_json_encode(
                $data,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($json)) {
                continue;
            }

            printf(
                '<script type="application/json" id="%1$s">%2$s</script>' . "\n",
                esc_attr($elementId),
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* flags make the payload safe inside a JSON script tag.
                $json
            );
        }

        self::$pendingJson = [];
    }
}
