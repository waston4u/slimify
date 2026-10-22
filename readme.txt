=== Slimify ===
Contributors: mirosedlacek
Tags: images, compression, optimise, webp, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.4
Stable tag: 1.0.1
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

= 1.0.1 =
* Fix: valid images below the 2KB compression floor were reported "Over limits" and skipped WebP generation — compression and WebP eligibility are now separate checks
* Fix: attachments marked "Over limits" could never recover — the processed-keys ledger no longer records skipped files, and an ineligible result clears it
* Fix: offload file-path hooks are now collected on `init` so theme/mu-plugin code can register them (they previously ran at plugins_loaded, before theme functions.php loads)
* Fix: WebP URLs could be served for objects not on the CDN — delivery now requires offload confirmation and falls back to a local WebP URL, so an unpushed remote .webp never 404s
* Fix: offloaders' attachment-URL filters (priority 99+) could clobber the WebP swap — Slimify's delivery filters now run last
* Improvement: WebP siblings passed to offload manifests carry named keys (`size.webp`) instead of numeric ones
* Feature: universal remote push — after compress/restore, a metadata-update nudge asks the active offload plugin (any plugin that hooks `wp_update_attachment_metadata`) to re-upload, then each WebP's remote URL is HEAD-verified before it is served from the CDN
* Feature: Missing files admin — attachments with no local or remote source are marked ignored and listed under Media Compressor → Missing files instead of stalling bulk runs

= 1.0.0 =
* Initial release
