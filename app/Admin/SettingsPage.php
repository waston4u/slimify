<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Support\BinaryProbe;
use Slimify\Support\Settings;

final readonly class SettingsPage
{
    /** Constructor. */
    public function __construct(
        private Settings $settings,
        private BinaryProbe $binaryProbe,
        private BulkCompression $bulkCompression,
        private StorageHealth $storageHealth,
    ) {
    }

    /** Register admin hooks. */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_slimify_test_binaries', [$this, 'ajaxTestBinaries']);
        add_filter(
            'plugin_action_links_' . plugin_basename(SLIMIFY_FILE),
            [$this, 'addSettingsLink']
        );
    }

    /** Enqueue settings assets on the Media Compressor page. */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_slimify') {
            return;
        }

        Assets::enqueueCommon();
        Assets::style('slimify-settings', 'admin/settings.css', ['slimify-admin-common']);
        Assets::script('slimify-settings', 'admin/settings.js', ['slimify-admin-common']);
        Assets::config('slimify-settings-config', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'bulkNonce' => wp_create_nonce('slimify_bulk_compression'),
            'binaryNonce' => wp_create_nonce('slimify_test_binaries'),
            'missingNonce' => wp_create_nonce('slimify_missing_files'),
            'i18n' => [
                'requestFailed' => __('Request failed.', 'slimify'),
                'imageProcessed' => __('Image processed.', 'slimify'),
                'allDone' => __('All eligible images are processed.', 'slimify'),
                'stopped' => __('Stopped. You can resume at any time.', 'slimify'),
                'errorPrefix' => __('Error:', 'slimify'),
                'stoppedErrors' => __('Stopped after repeated errors:', 'slimify'),
                'starting' => __('Starting compression…', 'slimify'),
                'stopping' => __('Stopping…', 'slimify'),
                // translators: %d: number of images still needing compression.
                'pendingCount' => __('%d image(s) still need compression.', 'slimify'),
                'testingTools' => __('Testing server tools…', 'slimify'),
                'noResults' => __('No results.', 'slimify'),
                'available' => __('available', 'slimify'),
                'toolAvailable' => __('Available', 'slimify'),
                'toolMissing' => __('Missing', 'slimify'),
                'askHost' => __('Ask your host to install this tool.', 'slimify'),
                'scanning' => __('Scanning the library…', 'slimify'),
                'scanDone' => __('Scan finished.', 'slimify'),
                'noMissing' => __('No missing files recorded. Scan the library or run Compress remaining.', 'slimify'),
                'deleteConfirm' => __('Permanently delete the selected attachments from the media library? The files are already missing from disk.', 'slimify'),
                'deleteAllConfirm' => __('Permanently delete all missing-file attachments from the media library?', 'slimify'),
                'deletedCount' => __('Deleted %d attachment(s).', 'slimify'),
                'untitled' => __('(no title)', 'slimify'),
                'viewInMedia' => __('View', 'slimify'),
                'missingCount' => __('%d missing file(s) recorded.', 'slimify'),
            ],
        ]);
    }

    /** Add Settings on the plugins list. */
    public function addSettingsLink(array $links): array
    {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=slimify')),
            esc_html__('Settings', 'slimify')
        );

        return ['settings' => $settings] + $links;
    }

    /** Add the settings menu item. */
    public function addMenu(): void
    {
        add_options_page(
            __('Media Compressor', 'slimify'),
            __('Media Compressor', 'slimify'),
            'manage_options',
            'slimify',
            [$this, 'renderPage']
        );
    }

    /** Register the settings group. */
    public function registerSettings(): void
    {
        register_setting('slimify', Settings::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this->settings, 'sanitize'],
            'default' => [
                'use_binaries' => true,
                'jpeg_quality' => 80,
                'generate_webp' => true,
                'keep_originals' => true,
            ],
        ]);
    }

    /** Render the system / storage panel. */
    private function renderSystemCard(): void
    {
        $backupRoot = $this->storageHealth->backupRoot();
        $runtimeDir = $this->storageHealth->runtimeDirectory();
        $broken = $this->storageHealth->backupsBroken();
        $exposure = $backupRoot !== null ? $this->storageHealth->probeExposure() : 'unknown';
        $queueMode = $this->storageHealth->queueStorage();
        ?>
        <section class="slimify-panel" aria-labelledby="slimify-system-title">
            <header class="slimify-panel__head">
                <h2 id="slimify-system-title"><?php echo esc_html__('System', 'slimify'); ?></h2>
                <p><?php echo esc_html__('Storage path, access check, and queue backend.', 'slimify'); ?></p>
            </header>

            <?php if ($broken) : ?>
                <div class="slimify-callout slimify-callout--error">
                    <strong><?php echo esc_html__('Originals cannot be kept', 'slimify'); ?></strong>
                    <p>
                        <?php
                        echo esc_html__(
                            'The originals directory is not writable. Compression cannot be undone until this is fixed.',
                            'slimify'
                        );
                        ?>
                    </p>
                    <?php foreach ($this->storageHealth->attemptedBackupRoots() as $attempted) : ?>
                        <code class="slimify-path"><?php echo esc_html($attempted); ?></code>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($exposure === 'exposed') : ?>
                <div class="slimify-callout slimify-callout--warn">
                    <strong><?php echo esc_html__('Originals are publicly reachable', 'slimify'); ?></strong>
                    <p>
                        <?php
                        echo esc_html__(
                            'Add this nginx rule (Apache/IIS deny files are already written):',
                            'slimify'
                        );
                        ?>
                    </p>
                    <pre class="slimify-code"><?php echo esc_html($this->storageHealth->nginxRule()); ?></pre>
                </div>
            <?php endif; ?>

            <dl class="slimify-meta">
                <div>
                    <dt><?php echo esc_html__('Direct access', 'slimify'); ?></dt>
                    <dd>
                        <?php if ($exposure === 'protected') : ?>
                            <span class="slimify-tag slimify-tag--ok"><?php echo esc_html__('Blocked', 'slimify'); ?></span>
                        <?php elseif ($exposure === 'exposed') : ?>
                            <span class="slimify-tag slimify-tag--bad"><?php echo esc_html__('Served', 'slimify'); ?></span>
                        <?php else : ?>
                            <span class="slimify-tag"><?php echo esc_html__('Unknown', 'slimify'); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Upload queue', 'slimify'); ?></dt>
                    <dd>
                        <?php if ($queueMode === 'file') : ?>
                            <span class="slimify-tag slimify-tag--ok"><?php echo esc_html__('File lock', 'slimify'); ?></span>
                        <?php else : ?>
                            <span class="slimify-tag slimify-tag--warn"><?php echo esc_html__('Database', 'slimify'); ?></span>
                            <span class="slimify-meta__note"><?php echo esc_html__('Slower fallback', 'slimify'); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="slimify-meta__wide">
                    <dt><?php echo esc_html__('Originals', 'slimify'); ?></dt>
                    <dd>
                        <?php if ($backupRoot !== null) : ?>
                            <code class="slimify-path"><?php echo esc_html($backupRoot); ?></code>
                        <?php elseif ($this->storageHealth->backupsRequested()) : ?>
                            <span class="slimify-tag slimify-tag--bad"><?php echo esc_html__('Unavailable', 'slimify'); ?></span>
                        <?php else : ?>
                            <span class="slimify-meta__note"><?php echo esc_html__('Disabled in settings', 'slimify'); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="slimify-meta__wide">
                    <dt><?php echo esc_html__('Runtime', 'slimify'); ?></dt>
                    <dd>
                        <?php if ($runtimeDir !== null) : ?>
                            <code class="slimify-path"><?php echo esc_html($runtimeDir); ?></code>
                        <?php else : ?>
                            <span class="slimify-meta__note"><?php echo esc_html__('Unavailable', 'slimify'); ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </section>
        <?php
    }

    /** Render the settings page. */
    public function renderPage(): void
    {
        if (!AjaxAuth::canManage()) {
            return;
        }

        $opts = $this->settings->all();
        $status = $this->bulkCompression->status();
        $accounted = $status['compressed'] + $status['missing'];
        $pct = $status['total'] > 0
            ? (int)round(($accounted / $status['total']) * 100)
            : 100;
        $settingsKey = Settings::OPTION_KEY;
        ?>
        <div class="wrap slimify-wrap">
            <header class="slimify-header">
                <div class="slimify-header__title">
                    <h1><?php echo esc_html__('Media Compressor', 'slimify'); ?></h1>
                    <span class="slimify-header__ver"><?php echo esc_html(SLIMIFY_VERSION); ?></span>
                </div>
                <p class="slimify-header__lede">
                    <?php echo esc_html__('Local JPEG and PNG compression with optional WebP. No remote API. Requires Administrator.', 'slimify'); ?>
                </p>
            </header>

            <?php settings_errors(); ?>

            <div class="slimify-layout">
                <div class="slimify-layout__main">
                    <nav class="nav-tab-wrapper slimify-tabs" aria-label="<?php echo esc_attr__('Media Compressor sections', 'slimify'); ?>">
                        <a href="#library" class="nav-tab nav-tab-active" id="slimify-tab-library" data-slimify-tab="library">
                            <?php echo esc_html__('Library', 'slimify'); ?>
                        </a>
                        <a href="#missing" class="nav-tab" id="slimify-tab-missing" data-slimify-tab="missing">
                            <?php echo esc_html__('Missing files', 'slimify'); ?>
                            <span class="slimify-tab-count" id="slimify-missing-tab-count"><?php echo esc_html((string)$status['missing']); ?></span>
                        </a>
                    </nav>

                    <div id="slimify-panel-library" data-slimify-panel="library">
                    <section class="slimify-panel" id="slimify-bulk" aria-labelledby="slimify-bulk-title">
                        <header class="slimify-panel__head slimify-panel__head--row">
                            <div>
                                <h2 id="slimify-bulk-title"><?php echo esc_html__('Library', 'slimify'); ?></h2>
                                <p><?php echo esc_html__('Compress existing JPEG and PNG files, one attachment at a time.', 'slimify'); ?></p>
                            </div>
                            <span class="slimify-running" id="slimify-run-pill" hidden>
                                <span class="slimify-running__dot" aria-hidden="true"></span>
                                <?php echo esc_html__('Running', 'slimify'); ?>
                            </span>
                        </header>

                        <div class="slimify-metrics" aria-label="<?php echo esc_attr__('Library progress', 'slimify'); ?>">
                            <div class="slimify-metric">
                                <span class="slimify-metric__value" id="slimify-total"><?php echo esc_html((string)$status['total']); ?></span>
                                <span class="slimify-metric__label"><?php echo esc_html__('Eligible', 'slimify'); ?></span>
                            </div>
                            <div class="slimify-metric">
                                <span class="slimify-metric__value" id="slimify-compressed"><?php echo esc_html((string)$status['compressed']); ?></span>
                                <span class="slimify-metric__label"><?php echo esc_html__('Done', 'slimify'); ?></span>
                            </div>
                            <div class="slimify-metric">
                                <span class="slimify-metric__value" id="slimify-pending"><?php echo esc_html((string)$status['pending']); ?></span>
                                <span class="slimify-metric__label"><?php echo esc_html__('Left', 'slimify'); ?></span>
                            </div>
                            <div class="slimify-metric">
                                <span class="slimify-metric__value" id="slimify-missing"><?php echo esc_html((string)$status['missing']); ?></span>
                                <span class="slimify-metric__label"><?php echo esc_html__('Missing', 'slimify'); ?></span>
                            </div>
                        </div>

                        <div class="slimify-track">
                            <div class="slimify-track__meta">
                                <span><?php echo esc_html__('Progress', 'slimify'); ?></span>
                                <span id="slimify-progress-label"><?php echo esc_html((string)$pct); ?>%</span>
                            </div>
                            <div class="slimify-track__bar" role="progressbar"
                                 aria-valuemin="0"
                                 aria-valuemax="<?php echo esc_attr((string)max(1, $status['total'])); ?>"
                                 aria-valuenow="<?php echo esc_attr((string)$accounted); ?>">
                                <div
                                    class="slimify-track__fill"
                                    id="slimify-progress-bar"
                                    style="width: <?php echo esc_attr((string)$pct); ?>%;"
                                ></div>
                            </div>
                            <progress
                                id="slimify-progress"
                                class="slimify-track__native"
                                value="<?php echo esc_attr((string)$accounted); ?>"
                                max="<?php echo esc_attr((string)max(1, $status['total'])); ?>"
                                hidden
                            ></progress>
                        </div>

                        <div class="slimify-toolbar">
                            <button
                                type="button"
                                class="button button-primary"
                                id="slimify-compress-missing"
                                <?php disabled($status['pending'], 0); ?>
                            >
                                <?php echo esc_html__('Compress remaining', 'slimify'); ?>
                            </button>
                            <button type="button" class="button" id="slimify-stop" disabled aria-disabled="true">
                                <?php echo esc_html__('Stop', 'slimify'); ?>
                            </button>
                            <button type="button" class="button" id="slimify-refresh-count">
                                <?php echo esc_html__('Refresh', 'slimify'); ?>
                            </button>
                        </div>

                        <p class="slimify-live" id="slimify-bulk-message" aria-live="polite">
                            <?php if ($status['pending'] === 0) : ?>
                                <?php echo esc_html__('All eligible images are processed.', 'slimify'); ?>
                            <?php else : ?>
                                <?php
                                echo esc_html(
                                    sprintf(
                                    /* translators: %d: pending image count */
                                        _n(
                                            '%d image still needs compression.',
                                            '%d images still need compression.',
                                            $status['pending'],
                                            'slimify'
                                        ),
                                        $status['pending']
                                    )
                                );
                                ?>
                            <?php endif; ?>
                        </p>
                        <p class="slimify-help">
                            <?php echo esc_html__('Safe for shared hosts: one attachment per request with a short pause. Leave the page or press Stop at any time.', 'slimify'); ?>
                        </p>
                    </section>

                    <section class="slimify-panel" aria-labelledby="slimify-settings-title">
                        <header class="slimify-panel__head">
                            <h2 id="slimify-settings-title"><?php echo esc_html__('Settings', 'slimify'); ?></h2>
                            <p><?php echo esc_html__('Defaults suit production. Change these only when you know your host.', 'slimify'); ?></p>
                        </header>

                        <form method="post" action="options.php" class="slimify-form">
                            <?php settings_fields('slimify'); ?>

                            <div class="slimify-fields">
                                <label class="slimify-field">
                                    <span class="slimify-field__copy">
                                        <span class="slimify-field__title"><?php echo esc_html__('Server binaries', 'slimify'); ?></span>
                                        <span class="slimify-field__desc"><?php echo esc_html__('Use pngquant, MozJPEG and jpegoptim when installed. Imagick is the fallback.', 'slimify'); ?></span>
                                    </span>
                                    <input type="checkbox" name="<?php echo esc_attr($settingsKey); ?>[use_binaries]" value="1" <?php checked($opts['use_binaries']); ?> />
                                </label>

                                <label class="slimify-field">
                                    <span class="slimify-field__copy">
                                        <span class="slimify-field__title"><?php echo esc_html__('WebP siblings', 'slimify'); ?></span>
                                        <span class="slimify-field__desc"><?php echo esc_html__(
                                                'Create .webp next to each JPEG/PNG file and serve it when the browser accepts WebP. GIF/WebP/AVIF sources are not compressed.',
                                                'slimify'
                                            ); ?></span>
                                    </span>
                                    <input type="checkbox" name="<?php echo esc_attr($settingsKey); ?>[generate_webp]" value="1" <?php checked($opts['generate_webp']); ?> />
                                </label>

                                <label class="slimify-field">
                                    <span class="slimify-field__copy">
                                        <span class="slimify-field__title"><?php echo esc_html__('Keep originals', 'slimify'); ?></span>
                                        <span class="slimify-field__desc"><?php echo esc_html__(
                                                'Store one protected backup of the original upload so you can restore (sizes regenerate from it). Recommended in production.',
                                                'slimify'
                                            ); ?></span>
                                    </span>
                                    <input type="checkbox" name="<?php echo esc_attr($settingsKey); ?>[keep_originals]" value="1" <?php checked($opts['keep_originals']); ?> />
                                </label>

                                <div class="slimify-field slimify-field--range">
                                    <div class="slimify-field__copy">
                                        <label class="slimify-field__title" for="slimify_jpeg_quality">
                                            <?php echo esc_html__('JPEG quality', 'slimify'); ?>
                                        </label>
                                        <span class="slimify-field__desc"><?php echo esc_html__('Default 80. Used by MozJPEG, jpegoptim, Imagick and WebP.', 'slimify'); ?></span>
                                    </div>
                                    <div class="slimify-range">
                                        <div class="slimify-range__row">
                                            <input
                                                type="range"
                                                id="slimify_jpeg_quality"
                                                name="<?php echo esc_attr($settingsKey); ?>[jpeg_quality]"
                                                min="60"
                                                max="95"
                                                value="<?php echo esc_attr((string)$opts['jpeg_quality']); ?>"
                                            />
                                            <output class="slimify-range__value" id="slimify_jpeg_quality_val" for="slimify_jpeg_quality"><?php echo esc_html((string)$opts['jpeg_quality']); ?></output>
                                        </div>
                                        <div class="slimify-range__ends">
                                            <span><?php echo esc_html__('Smaller', 'slimify'); ?></span>
                                            <span><?php echo esc_html__('Sharper', 'slimify'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="slimify-form__actions">
                                <?php submit_button(__('Save settings', 'slimify'), 'primary', 'submit', false); ?>
                            </div>
                        </form>
                    </section>
                    </div>

                    <div id="slimify-panel-missing" data-slimify-panel="missing" hidden>
                        <section class="slimify-panel" aria-labelledby="slimify-missing-title">
                            <header class="slimify-panel__head">
                                <h2 id="slimify-missing-title"><?php echo esc_html__('Missing files', 'slimify'); ?></h2>
                                <p>
                                    <?php echo esc_html__('These attachments are in the media library but the original is not on the server. Compression skips them until you restore the file or delete the entry.', 'slimify'); ?>
                                </p>
                            </header>

                            <div class="slimify-toolbar">
                                <button type="button" class="button" id="slimify-missing-scan">
                                    <?php echo esc_html__('Scan library', 'slimify'); ?>
                                </button>
                                <button type="button" class="button" id="slimify-missing-delete-selected" disabled>
                                    <?php echo esc_html__('Delete selected', 'slimify'); ?>
                                </button>
                                <button type="button" class="button" id="slimify-missing-delete-all" disabled>
                                    <?php echo esc_html__('Delete all listed', 'slimify'); ?>
                                </button>
                            </div>
                            <p class="slimify-live" id="slimify-missing-message" aria-live="polite">
                                <?php
                                if ($status['missing'] === 0) {
                                    echo esc_html__('No missing files recorded. Scan the library or run Compress remaining.', 'slimify');
                                } else {
                                    echo esc_html(
                                        sprintf(
                                        /* translators: %d: missing attachment count */
                                            _n(
                                                '%d missing file recorded.',
                                                '%d missing files recorded.',
                                                $status['missing'],
                                                'slimify'
                                            ),
                                            $status['missing']
                                        )
                                    );
                                }
                                ?>
                            </p>
                            <div class="slimify-missing-table-wrap">
                                <table class="widefat striped slimify-missing-table" id="slimify-missing-table">
                                    <thead>
                                        <tr>
                                            <td class="check-column">
                                                <input type="checkbox" id="slimify-missing-select-all" />
                                            </td>
                                            <th scope="col"><?php echo esc_html__('Title', 'slimify'); ?></th>
                                            <th scope="col"><?php echo esc_html__('ID', 'slimify'); ?></th>
                                            <th scope="col"><?php echo esc_html__('Expected path', 'slimify'); ?></th>
                                            <th scope="col"><?php echo esc_html__('Media', 'slimify'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="slimify-missing-rows">
                                        <tr class="slimify-missing-empty">
                                            <td colspan="5"><?php echo esc_html__('No missing files recorded. Scan the library or run Compress remaining.', 'slimify'); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>

                <aside class="slimify-layout__aside">
                    <?php $this->renderSystemCard(); ?>

                    <section class="slimify-panel" aria-labelledby="slimify-binary-title">
                        <header class="slimify-panel__head slimify-panel__head--row">
                            <div>
                                <h2 id="slimify-binary-title"><?php echo esc_html__('Binaries', 'slimify'); ?></h2>
                                <p><?php echo esc_html__('Check what this server can run.', 'slimify'); ?></p>
                            </div>
                            <button type="button" class="button" id="slimify-test-binaries">
                                <?php echo esc_html__('Run test', 'slimify'); ?>
                            </button>
                        </header>
                        <div id="slimify-binary-results" class="slimify-binary-results" hidden></div>
                        <p class="slimify-help" id="slimify-binary-empty">
                            <?php echo esc_html__('Probes pngquant, MozJPEG, jpegoptim, cwebp, Imagick and proc_open.', 'slimify'); ?>
                        </p>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    /** AJAX: re-probe server binaries. */
    public function ajaxTestBinaries(): void
    {
        AjaxAuth::requireManageOptions('slimify_test_binaries');

        wp_send_json_success([
            'tools' => $this->binaryProbe->statusReport(true),
        ]);
    }

}
