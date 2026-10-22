<?php

declare(strict_types=1);

namespace Slimify;

use Slimify\Admin\BulkCompression;
use Slimify\Admin\CliCommand;
use Slimify\Admin\MediaLibrary;
use Slimify\Admin\MissingFiles;
use Slimify\Admin\SettingsPage;
use Slimify\Admin\StorageHealth;
use Slimify\Compression\CompressionService;
use Slimify\Compression\DriverResolver;
use Slimify\Core\Hooks;
use Slimify\Offload\OffloadAdapter;
use Slimify\Offload\SizeKeyRepair;
use Slimify\Support\BinaryProbe;
use Slimify\Support\PathGuard;
use Slimify\Support\Settings;
use Slimify\Support\Site;
use Slimify\Webp\WebpService;

final class Plugin
{
    private static ?self $instance = null;

    private Settings $settings;
    private BinaryProbe $binaryProbe;
    private PathGuard $pathGuard;
    private DriverResolver $driverResolver;
    private CompressionService $compressionService;
    private WebpService $webpService;
    private OffloadAdapter $offloadAdapter;

    /** Singleton instance. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Constructor. */
    private function __construct()
    {
        Site::boot();

        $this->settings = new Settings();
        $this->binaryProbe = new BinaryProbe();
        $this->pathGuard = new PathGuard();
        $this->driverResolver = new DriverResolver($this->settings, $this->binaryProbe, $this->pathGuard);
        $this->webpService = new WebpService($this->settings, $this->binaryProbe, $this->pathGuard);
        $this->compressionService = new CompressionService(
            $this->driverResolver,
            $this->webpService,
            $this->pathGuard,
            $this->settings
        );
        $this->offloadAdapter = new OffloadAdapter($this->compressionService, $this->pathGuard);
    }

    /** Register hooks and services. */
    public function boot(): void
    {
        new Hooks($this->compressionService, $this->offloadAdapter)->register();
        $this->webpService->registerDeliveryFilters();
        $this->webpService->registerMaintenanceHooks();
        new SizeKeyRepair()->register();
        // Wait for init so themes and mu-plugins can register offload hooks.
        add_action('init', [$this->offloadAdapter, 'register']);

        $bulkCompression = new BulkCompression(
            $this->offloadAdapter,
            $this->compressionService->queue()
        );
        $bulkCompression->register();
        new MediaLibrary($this->offloadAdapter, $this->compressionService)->register();
        new MissingFiles(
            $this->offloadAdapter,
            $this->compressionService,
            $bulkCompression
        )->register();

        $storageHealth = new StorageHealth(
            $this->compressionService,
            $this->settings,
            $this->compressionService->queue()
        );
        $storageHealth->registerNotice();

        // Settings UI registers only in admin.
        new SettingsPage($this->settings, $this->binaryProbe, $bulkCompression, $storageHealth)->register();

        if (defined('WP_CLI') && WP_CLI) {
            CliCommand::register($this->compressionService, $this->offloadAdapter);
        }
    }
}
