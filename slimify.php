<?php

/**
 * Plugin Name: Slimify Media Compressor
 * Description: Local JPEG and PNG compression with optional WebP siblings. pngquant, MozJPEG, jpegoptim, and Imagick. No remote API.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.4
 * Author: Miro Sedlacek
 * Text Domain: slimify
 * Domain Path: /languages
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: false
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('SLIMIFY_VERSION', '1.0.0');
define('SLIMIFY_FILE', __FILE__);
define('SLIMIFY_PATH', plugin_dir_path(__FILE__));
define('SLIMIFY_URL', plugin_dir_url(__FILE__));

$slimifyAutoload = SLIMIFY_PATH . 'vendor/autoload.php';
if (is_readable($slimifyAutoload)) {
    require_once $slimifyAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Slimify\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = SLIMIFY_PATH . 'app/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

add_action('plugins_loaded', static function (): void {
    Slimify\Plugin::instance()->boot();
});
