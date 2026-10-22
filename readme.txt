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
* Fix: auto-offload failed when a theme registers a numeric size name (e.g. add_image_size('680', …)) — WordPress stores it under key 0 and reports it missing forever; Slimify treats it as present, uses a safe offload key, and keeps its srcset entry correct
* Fix: images under 2 KB were marked "Over limits" and skipped WebP — compression and WebP eligibility are now separate
* Fix: "Over limits" attachments could never recover — skipped files are no longer locked in the ledger
* Fix: offload file-path hooks are collected on `init` so themes and mu-plugins can register them
* Fix: WebP is only served from the CDN once confirmed there; otherwise a local WebP URL is used
* Fix: delivery filters run last so offloaders cannot undo the WebP swap
* Improvement: WebP siblings in offload manifests use named keys (`size.webp`)
* Feature: after compress/restore, any offload plugin that hooks metadata updates is asked to re-upload; WebPs are HEAD-checked before CDN delivery
* Feature: Missing files screen lists attachments with no local or remote source instead of stalling bulk runs
* Fix: Missing files scan no longer flags offloaded items whose local copy was removed
* Security: Missing files delete only accepts flagged attachments and re-checks before deleting
* Security: remote WebP checks use safe HTTP requests with redirects disabled
* Performance: remote WebP checks only run for offloaded attachments (3 s timeout, 12-file cap, 10 s budget)
* Fix: "pushed to remote" is not reported until WebPs are confirmed
* Fix: library stats no longer count an attachment as both compressed and missing
* Fix: a successful compress clears the missing-file flag
* Fix: a busy compress no longer triggers a needless offload
* Fix: remote downloads that swap format behind a .jpg/.png URL are rejected
* Hardening: temporary copies go to the system temp directory, not public uploads
* Fix: WebP delivery treats www and non-www hosts as the same site
* Fix: uninstall removes Slimify cron events
* Fix: WebP clients keep a responsive srcset (rebuilt when Core drops it for a .webp src)
* Fix: host-less upload URLs under WP-CLI without --url are no longer treated as remotely fetchable
* Fix: images offloaded after compression switch to CDN WebP once confirmed (offloader upload + hourly delivery re-check)
* Fix: new uploads queue that re-check only after WebPs exist
* Fix: the deferred queue waits for the upload request to finish before compressing
* Fix: remote WebP checks with more than 12 files make progress on each run

= 1.0.0 =
* Initial release
