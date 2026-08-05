# Slimify

Local JPEG and PNG compression for WordPress. No remote API, accounts, or telemetry.

| Output | Engine |
|--------|--------|
| PNG | `pngquant`, then Imagick |
| JPEG | MozJPEG `cjpeg`, then `jpegoptim`, then Imagick |
| WebP | Optional siblings via `cwebp` or Imagick |

WebP is encoded from pre-compress pixels and kept only when smaller than the final JPEG/PNG.

**Supported sources:** JPEG and PNG only. GIF, WebP, and AVIF are not compressed as inputs.

## Requirements

- WordPress 6.4+, PHP 8.4+
- Recommended: `pngquant`, MozJPEG `cjpeg`, `jpegoptim`, `cwebp` on `PATH`
- Imagick PHP extension as fallback

## Installation

1. Place in `wp-content/plugins/slimify` (all plugin code lives in this directory)
2. Activate **Slimify Media Compressor**
3. Open **Settings → Media Compressor**

Admin capability: **Administrator** (`manage_options`) for settings, bulk, media actions, and AJAX.

## Behaviour

- Compresses on `wp_generate_attachment_metadata` (priority 5)
- One concurrent job; uploads queue and finish via WP-Cron or the media library kick (`slimify_kick_drain`) — never from public front-end traffic
- If WP-Cron is disabled, use the media library kick drain or WP-CLI
- Queue drain uses the offload adapter (fetch → compress → push) so remote media matches bulk compress
- Entries stay queued until a definitive result (failed runs retry a few times, then mark unavailable)
- If the queue is full (500), new uploads are **not** left stuck in “Processing”; they can be bulk- or manually compressed later
- **Locking:** after real work (bytes reduced and/or WebP) **with a full original backup**, the attachment is locked until restore
- **No backup** (Keep originals off): finished but **can compress again** without restore
- **Ineligible (all sizes over size/pixel limits):** marked finished for bulk so the library sweep advances, but **not** hard-locked — Compress again works without restore
- **Missing source / no remote:** marked `unavailable` (finished) so bulk does not spin forever
- **Backup root repaired:** Compress again works after `backup_root_unavailable` if originals dir is writable again
- Oversized-only runs discard any temporary originals backup; no restore path until a real compress run keeps one
- Optional full-file original backups under uploads (not every intermediate size)
- Remote push failure after a successful local compress keeps the lock and reports success with a sync warning
- Bulk compress skips attachment IDs still sitting in the deferred queue so other images can advance
- Compress jobs are **serial** (one lock) on purpose for shared-host safety

### Limits

| Limit | Value |
|-------|--------|
| Max file size | 24 MiB |
| Max pixels | 36M (~6000 × 6000) |
| Process timeout | 15 s (WebP 10 s) |
| WebP encode budget | 12 s per file |
| WebP ladder (default) | 2 quality steps + 1 size target (filters can expand) |
| Attachment budget | 40 s (all sizes) |
| Max sizes per attachment | 10 |
| Queue drain | ≤5 items / ≤25 s per pass |
| Queue max entries | 500 (new IDs refused until drain frees space; no silent processing stuck) |
| Queue hard-fail cap | 3 attempts then unavailable |
| Imagick | 128 / 256 MiB, 1 thread |
| CLI batch | ≤50 |
| Admin rate limits | bulk compress ~1/s; kick drain ~1/2s |

### Storage (greenfield)

```
wp-content/uploads/
├── …site media + WebP siblings next to images…
└── slimify/
    └── originals/    one full pre-compress backup per attachment

# Outside uploads (system / WP temp — not web-servable):
…/slimify-runtime-{hash}/
├── queue.json
├── queue.lock
└── compress.lock
```

If the temp runtime directory is not writable, the queue falls back to a WordPress option (+ MySQL advisory lock).

Backups live under uploads so plugin updates cannot destroy them. Apache/IIS deny files are written under `slimify/originals`; nginx may need an optional deny rule if originals are web-reachable.

Override paths (trusted environment):

```php
add_filter('slimify_backup_directory', fn () => /* must resolve under uploads/slimify/originals */ …);
add_filter('slimify_runtime_directory', fn () => /* private non-web dir */ …);
```

### WebP delivery

Only recorded siblings are served. Missing files drop out of the map on next URL rewrite. Stale siblings are also purged on metadata update. `Vary: Accept` is sent only for WebP-capable clients when the site flag is set and generation is enabled. Disable:

```php
add_filter('slimify_send_vary_accept', '__return_false');
```

Leaner encode ladder filters:

```php
add_filter('slimify_webp_quality_ladder', fn () => [80, 55, 40]);
add_filter('slimify_webp_size_fractions', fn () => [0.55, 0.40]);
```

## Settings

**Settings → Media Compressor**

- Library progress and bulk compress
- Media library status, compress and restore
- Binary checks
- Options: use binaries, JPEG quality (default 80), WebP siblings, keep originals (full upload only)

Restore puts the original full file back, regenerates all image sizes, and deletes WebP siblings.

## WP-CLI

```bash
wp media compress --all
wp media compress --attachment=123
wp media compress --all --dry-run
wp media compress --all --batch-size=25
```

On multisite, pass `--url=`. Hard-locked attachments are skipped.

## CDN and offload

**Trusted code only:** path/fetch/push filters can move or upload files. Only load them from your own integration code.

Compress locally, then push via your offloader:

```php
add_filter('slimify_fetch_remote_file', function ($path, int $id, array $ctx) {
    return my_offloader_download($id, $ctx['size']) ?: $path;
}, 10, 3);

add_filter('slimify_remote_source_url', function ($url, int $id, array $ctx) {
    return my_signed_url($id, $ctx['size']) ?: $url;
}, 10, 3);

add_filter('slimify_push_to_remote', function (?bool $ok, int $id, array $ctx): bool {
    return my_offloader_upload($id, $ctx['paths']);
}, 10, 3);

add_filter('slimify_offload_file_path_hooks', fn (array $h) => [...$h, 'their_path_filter']);
```

Remote-only storage requires push confirmation by default (`slimify_require_remote_push_confirmation`). CDN with origin files on disk usually does not.

## Multisite

Network and per-site activation. Settings, locks and uploads follow blog context. Bulk stats and exposure probes are blog-scoped. Uninstall clears each site's `uploads/slimify/` originals tree and temp runtime for the site.

## Uninstall

Removes options, attachment meta, originals under `uploads/…/slimify/`, and temp `slimify-runtime-*` directories — only paths that match those names and are not critical system directories.

## Licence

GNU General Public License v3.0 or later. See [LICENSE](LICENSE) and <https://www.gnu.org/licenses/gpl-3.0.html>.
