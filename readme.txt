=== Slimify ===
Contributors: mirosedlacek
Tags: images, compression, optimise, webp, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Local JPEG and PNG compression with optional WebP siblings. No remote API.

== Description ==

Slimify compresses JPEG and PNG uploads on your server using pngquant, MozJPEG, jpegoptim, and Imagick. Optional WebP siblings are created when smaller than the final image.

* Local tooling only — no third-party API, accounts, or telemetry
* Compress on upload, restore from originals, and bulk-process the library
* Private originals storage under uploads with nginx/Apache deny patterns
* Works with common offload plugins via filters

== Installation ==

1. Upload the `slimify` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen
3. Open Settings → Media Compressor to configure quality and WebP

== Screenshots ==

1. Settings → Media Compressor: library progress, JPEG quality, WebP siblings, and local binaries.

== Frequently Asked Questions ==

= Does Slimify send images to a third party? =

No. Compression runs on your server with local binaries or Imagick.

= Which formats are supported? =

JPEG and PNG inputs. Optional WebP siblings can be generated when beneficial.

== Changelog ==

= 1.0.0 =
* Initial release
