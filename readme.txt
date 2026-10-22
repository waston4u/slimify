=== Slimify ===
Contributors: mirosedlacek
Tags: images, compression, optimise, webp, performance
Requires at least: 6.4
Tested up to: 7.1.3
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
* Works with common offload plugins (WP Offload Media and others) — remote originals are fetched, compressed, and pushed back, and WebP siblings are only served from the CDN once confirmed there
* Missing files screen lists library entries whose original is gone, so bulk runs never stall on them

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

= Does it work with WP Offload Media or other offload plugins? =

Yes. If an original only exists in remote storage, Slimify downloads it, compresses it, and asks the offload plugin to re-upload. WebP siblings are added to WP Offload Media's upload list and are only served from the CDN after Slimify confirms they exist there; until then the local WebP is used.

= What is the Missing files tab? =

It lists media library entries whose original is neither on the server nor fetchable from remote storage. Bulk compression skips them. You can restore the file or delete the entries from there.

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
* Fix: the Missing files scan no longer flags offloaded attachments whose local copy was removed — deleting them would also have removed the files from remote storage
* Security: Missing files delete only accepts attachments flagged as missing and re-checks the file is still gone right before deleting
* Security: remote WebP verification uses safe HTTP requests with redirects disabled
* Performance: remote WebP verification only runs for offloaded attachments (no more HEAD requests to the site itself or re-verify cron events on sites without an offloader), with a 3 s timeout, a 12-file cap, and a 10 s budget per attachment
* Fix: "pushed to remote" is no longer reported when the offloader could not be confirmed; the warning clears once a later re-verify confirms the WebPs
* Fix: library stats no longer count an attachment as both compressed and missing
* Fix: a successful compress clears the missing-file flag
* Fix: a busy/queued compress no longer triggers a needless offloader re-upload
* Fix: remote downloads whose real format differs from the attachment (e.g. a CDN serving WebP behind a .jpg URL) are rejected
* Hardening: temporary source copies are written to the system temp directory instead of the public uploads folder
* Fix: WebP delivery treats www and non-www hosts as the same site
* Fix: uninstall now removes Slimify's scheduled cron events

= 1.0.0 =
* Initial release
