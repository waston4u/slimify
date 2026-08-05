<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Compression\CompressionService;
use Slimify\Offload\OffloadAdapter;
use Slimify\Support\Limits;
use WP_Post;

final class MediaLibrary
{
    private const string COLUMN = 'slimify';

    /** Constructor. */
    public function __construct(
        private readonly OffloadAdapter $offloadAdapter,
        private readonly CompressionService $compressionService,
    ) {
    }

    /** Register hooks. */
    public function register(): void
    {
        add_filter('manage_upload_columns', [$this, 'addColumn']);
        add_action('manage_media_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('media_row_actions', [$this, 'addRowAction'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'addAttachmentField'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_slimify_compress_attachment', [$this, 'ajaxCompressAttachment']);
        add_action('wp_ajax_slimify_restore_attachment', [$this, 'ajaxRestoreAttachment']);
        add_action('wp_ajax_slimify_attachment_status', [$this, 'ajaxAttachmentStatus']);
        add_action('wp_ajax_slimify_kick_drain', [$this, 'ajaxKickDrain']);
    }

    /** Enqueue media library assets on relevant screens. */
    public function enqueueAssets(string $hook): void
    {
        if (!AjaxAuth::canManage()) {
            return;
        }

        $allowed = [
            'upload.php',
            'media-new.php',
            'media.php',
            'post.php',
            'post-new.php',
        ];
        if (!in_array($hook, $allowed, true)) {
            return;
        }

        Assets::enqueueCommon(['jquery']);
        Assets::style('slimify-media-library', 'admin/media-library.css', ['slimify-admin-common']);
        Assets::script(
            'slimify-media-library',
            'admin/media-library.js',
            ['slimify-admin-common', 'jquery']
        );
        Assets::config('slimify-media-config', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('slimify_compress_attachment'),
            'i18n' => [
                'waitingQueue' => __('Slimify: Waiting in queue…', 'slimify'),
                'processing' => __('Slimify: Processing…', 'slimify'),
                'processingShort' => __('Processing…', 'slimify'),
                // translators: %s: upload progress percentage.
                'uploading' => __('Slimify: Uploading %s%…', 'slimify'),
                'done' => __('Slimify: Done', 'slimify'),
                'ready' => __('Slimify: Ready', 'slimify'),
                'restoring' => __('Restoring…', 'slimify'),
                'compressing' => __('Compressing…', 'slimify'),
                'fail' => __('Compression failed.', 'slimify'),
                'confirmRestore' => __(
                    'Restore the original upload? Image sizes will be regenerated and WebP siblings removed.',
                    'slimify'
                ),
            ],
        ]);
    }

    /** Add the Slimify media library column. */
    public function addColumn(array $columns): array
    {
        if (!AjaxAuth::canManage()) {
            return $columns;
        }

        $columns[self::COLUMN] = __('Slimify', 'slimify');

        return $columns;
    }

    /** Render the Slimify media library column. */
    public function renderColumn(string $columnName, int $attachmentId): void
    {
        if ($columnName !== self::COLUMN || !AjaxAuth::canManageAttachment($attachmentId)) {
            return;
        }

        echo $this->statusHtml($attachmentId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** Add compress or restore row actions. */
    public function addRowAction(array $actions, WP_Post $post): array
    {
        if (!$this->isEligible($post->ID) || !AjaxAuth::canManageAttachment($post->ID)) {
            return $actions;
        }

        if ($this->isProcessing($post->ID)) {
            $actions['slimify_processing'] = sprintf(
                '<span class="slimify-processing-label" data-attachment-id="%d">%s</span>',
                $post->ID,
                esc_html__('Processing…', 'slimify')
            );

            return $actions;
        }

        if ($this->isProcessed($post->ID) && $this->hasOriginalBackup($post->ID)) {
            $actions['slimify_restore'] = sprintf(
                '<a href="#" class="slimify-restore-one" data-attachment-id="%d">%s</a>',
                $post->ID,
                esc_html__('Restore original', 'slimify')
            );
        } elseif (!$this->isProcessed($post->ID)) {
            $actions['slimify'] = sprintf(
                '<a href="#" class="slimify-compress-one" data-attachment-id="%d">%s</a>',
                $post->ID,
                esc_html__('Compress image', 'slimify')
            );
        }

        return $actions;
    }

    /** Add the attachment edit field. */
    public function addAttachmentField(array $fields, WP_Post $post): array
    {
        if (!$this->isEligible($post->ID) || !AjaxAuth::canManageAttachment($post->ID)) {
            return $fields;
        }

        $fields['slimify_status'] = [
            'label' => __('Slimify', 'slimify'),
            'input' => 'html',
            'html' => $this->statusHtml($post->ID),
            'helps' => __('Local compression status for the original and generated sizes.', 'slimify'),
        ];

        return $fields;
    }

    /** AJAX: compress one attachment. */
    public function ajaxCompressAttachment(): void
    {
        AjaxAuth::requireManageOptions('slimify_compress_attachment');

        $attachmentId = $this->postAbsint('attachment_id');
        if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            wp_send_json_error(['message' => __('Invalid attachment.', 'slimify')], 400);
        }

        if (!AjaxAuth::canManageAttachment($attachmentId)) {
            wp_send_json_error(['message' => __('Forbidden.', 'slimify')], 403);
        }

        if (!$this->isEligible($attachmentId)) {
            wp_send_json_error([
                'message' => __('Only JPEG and PNG attachments are supported.', 'slimify'),
            ], 422);
        }

        if ($this->isProcessed($attachmentId)) {
            wp_send_json_error([
                'message' => __(
                    'This image is locked after its first compression. Restore the original before compressing again.',
                    'slimify'
                ),
                'html' => $this->statusHtml($attachmentId),
            ], 409);
        }

        if ($this->isProcessing($attachmentId)) {
            wp_send_json_error([
                'processing' => true,
                'message' => __('This image is still being processed. Please wait.', 'slimify'),
                'html' => $this->statusHtml($attachmentId),
            ], 409);
        }

        $this->compressionService->markProcessing($attachmentId);

        $result = $this->offloadAdapter->compressAttachment($attachmentId, false);
        if (!empty($result['busy'])) {
            $this->compressionService->markProcessing($attachmentId);
            wp_send_json_error([
                'busy' => true,
                'processing' => true,
                'message' => $result['message'] !== ''
                    ? $result['message']
                    : __('Another compression job is running. Try again shortly.', 'slimify'),
                'html' => $this->statusHtml($attachmentId),
            ], 409);
        }

        if (!$result['ok']) {
            if (!$this->isRunFinished($attachmentId) && !$this->isProcessing($attachmentId)) {
                $this->compressionService->clearProcessing($attachmentId);
            }
            wp_send_json_error([
                'message' => $result['message'],
                'html' => $this->statusHtml($attachmentId),
            ], 422);
        }

        // Partial budget runs keep work open; soft finishes (ineligible) must not look "still processing".
        if ($this->isIncompleteRun($attachmentId)) {
            $this->compressionService->markProcessing($attachmentId);
            wp_send_json_success([
                'attachment_id' => $attachmentId,
                'partial' => true,
                'processing' => true,
                'message' => $result['message'],
                'html' => $this->statusHtml($attachmentId),
            ]);
        }

        $locked = $this->isProcessed($attachmentId);
        $hasBackup = $this->hasOriginalBackup($attachmentId);

        wp_send_json_success([
            'attachment_id' => $attachmentId,
            'message' => $result['message'] !== ''
                ? $result['message']
                : __('Image compression finished.', 'slimify'),
            'action_label' => $locked && $hasBackup
                ? __('Restore original', 'slimify')
                : __('Compress image', 'slimify'),
            'next_action' => $locked && $hasBackup ? 'restore' : 'compress',
            'processing' => false,
            'html' => $this->statusHtml($attachmentId),
        ]);
    }

    /** AJAX: restore one attachment. */
    public function ajaxRestoreAttachment(): void
    {
        AjaxAuth::requireManageOptions('slimify_compress_attachment');

        $attachmentId = $this->postAbsint('attachment_id');
        if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            wp_send_json_error(['message' => __('Invalid attachment.', 'slimify')], 400);
        }

        if (!AjaxAuth::canManageAttachment($attachmentId)) {
            wp_send_json_error(['message' => __('Forbidden.', 'slimify')], 403);
        }

        if (!$this->isEligible($attachmentId)) {
            wp_send_json_error([
                'message' => __('Only JPEG and PNG attachments are supported.', 'slimify'),
            ], 422);
        }

        $result = $this->offloadAdapter->restoreAttachment($attachmentId);
        if (!$result['ok']) {
            wp_send_json_error([
                'message' => $result['message'],
                'html' => $this->statusHtml($attachmentId),
            ], 422);
        }

        wp_send_json_success([
            'attachment_id' => $attachmentId,
            'message' => $result['message'],
            'action_label' => __('Compress image', 'slimify'),
            'next_action' => 'compress',
            'processing' => false,
            'html' => $this->statusHtml($attachmentId),
        ]);
    }

    /** AJAX: status for attachment IDs. */
    public function ajaxAttachmentStatus(): void
    {
        AjaxAuth::requireManageOptions('slimify_compress_attachment');

        $raw = $this->postText('attachment_ids');
        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $raw)))));
        $ids = array_slice($ids, 0, 50);

        $items = [];
        foreach ($ids as $id) {
            if (
                $id <= 0
                || get_post_type($id) !== 'attachment'
                || !AjaxAuth::canManageAttachment($id)
                || !$this->isEligible($id)
            ) {
                continue;
            }
            $items[(string)$id] = [
                'html' => $this->statusHtml($id),
                'processing' => $this->isProcessing($id),
                'processed' => $this->isProcessed($id),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    /** AJAX: drain the deferred queue. */
    public function ajaxKickDrain(): void
    {
        AjaxAuth::requireManageOptions('slimify_compress_attachment');
        AjaxAuth::requireRateLimit('kick_drain', 2.0);

        $processed = $this->compressionService->drainQueue(3, $this->offloadAdapter);

        wp_send_json_success([
            'processed' => $processed,
            'remaining' => $this->compressionService->queue()->count(),
        ]);
    }


    /** Read an absint from POST after AjaxAuth nonce verification. */
    private function postAbsint(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        if (!isset($_POST[$key])) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        return absint(wp_unslash($_POST[$key]));
    }

    /** Read a sanitized string from POST after AjaxAuth nonce verification. */
    private function postText(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        if (!isset($_POST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        return sanitize_text_field(wp_unslash((string)$_POST[$key]));
    }

    /** HTML status for one attachment. */
    private function statusHtml(int $attachmentId): string
    {
        if (!$this->isEligible($attachmentId)) {
            return sprintf(
                '<span class="slimify-status" data-attachment-id="%d">%s</span>',
                $attachmentId,
                esc_html__('Not eligible', 'slimify')
            );
        }

        // One postmeta round-trip for the column (avoid 5+ keyed lookups per row).
        $meta = get_post_meta($attachmentId);
        $stats = $this->metaFirst($meta, CompressionService::META_STATS);
        $stats = is_array($stats) ? $stats : [];
        $statsStatus = (string)($stats['status'] ?? '');
        $webpMap = $this->metaFirst($meta, CompressionService::META_WEBP);
        $originals = $this->metaFirst($meta, CompressionService::META_ORIGINALS);
        $originalCount = is_array($originals) ? count($originals) : 0;
        $restoredAt = (int)($this->metaFirst($meta, CompressionService::META_RESTORED) ?? 0);
        $webpCount = is_array($webpMap) ? count($webpMap) : 0;

        $flag = $this->metaFirst($meta, CompressionService::META_FLAG);
        $flagged = $flag === '1' || $flag === 1 || $flag === true;
        $hasBackup = $originalCount > 0;
        // Finished with backup = hard lock.
        $processed = $flagged && $statsStatus !== 'ineligible' && $hasBackup;
        $softProcessed = $flagged && $statsStatus !== 'ineligible' && !$hasBackup && $statsStatus !== 'unavailable';
        $processing = !$flagged && (
                $statsStatus === 'partial'
                || $this->hasLiveProcessingMarker($this->metaFirst($meta, CompressionService::META_PROCESSING))
            );
        $lines = [];

        if ($flagged && $statsStatus === 'unavailable') {
            $lines[] = '<strong style="color:#b32d2e;">' . esc_html__('Unavailable', 'slimify') . '</strong>';
            $lines[] = esc_html__('Source is not available for compression.', 'slimify');
        } elseif ($processed) {
            $lines[] = '<strong style="color:#008a20;">' . esc_html__('Processed', 'slimify') . '</strong>';
            if (($stats['remote_push'] ?? '') === 'failed') {
                $lines[] = esc_html__('Local compress succeeded; remote/CDN push was not confirmed.', 'slimify');
            }
        } elseif ($softProcessed) {
            $lines[] = '<strong style="color:#008a20;">' . esc_html__('Processed', 'slimify') . '</strong>';
            $lines[] = esc_html__('No original backup — compress again is allowed.', 'slimify');
        } elseif ($processing) {
            $lines[] = '<strong style="color:#996800;">' . esc_html__('Processing…', 'slimify') . '</strong>';
            $lines[] = esc_html__(
                'In the compression queue (or running now). Compress now stays locked until this finishes.',
                'slimify'
            );
        } else {
            if ($statsStatus === 'ineligible') {
                $lines[] = '<strong style="color:#996800;">' . esc_html__('Over limits', 'slimify') . '</strong>';
                $lines[] = esc_html__(
                    'No sizes were within compression limits. Finished for bulk; you can compress again without restore.',
                    'slimify'
                );
            } else {
                $lines[] = '<strong style="color:#b32d2e;">' . esc_html__('Not compressed', 'slimify') . '</strong>';
            }
            if ($restoredAt > 0) {
                $lines[] = esc_html(
                    sprintf(
                    /* translators: %s: restore date */
                        __('Original restored %s', 'slimify'),
                        wp_date(
                            get_option('date_format') . ' ' . get_option('time_format'),
                            $restoredAt
                        )
                    )
                );
            }
        }

        if (!$processing && is_array($stats) && $stats !== []) {
            $before = isset($stats['bytes_before']) ? (int)$stats['bytes_before'] : 0;
            $saved = isset($stats['bytes_saved']) ? (int)$stats['bytes_saved'] : 0;
            if ($before > 0 && $saved > 0) {
                $percent = min(100, round(($saved / $before) * 100, 1));
                $lines[] = sprintf(
                /* translators: 1: saved file size, 2: percentage */
                    esc_html__('Saved %1$s (%2$s%%)', 'slimify'),
                    esc_html(size_format($saved, 1)),
                    esc_html((string)$percent)
                );
            } elseif ($processed || $softProcessed) {
                $lines[] = esc_html__('Original retained (no smaller output)', 'slimify');
            }

            if (!empty($stats['drivers']) && is_array($stats['drivers'])) {
                $drivers = array_map('sanitize_text_field', $stats['drivers']);
                $lines[] = esc_html(implode(', ', $drivers));
            }

            if (!empty($stats['processed_at'])) {
                $lines[] = esc_html(
                    wp_date(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        (int)$stats['processed_at']
                    )
                );
            }
        } elseif ($processed || $softProcessed) {
            $lines[] = esc_html__('Previous result — detailed statistics unavailable.', 'slimify');
        }

        if (!$processing) {
            $lines[] = $webpCount > 0
                ? esc_html(
                    sprintf(
                    /* translators: %d: number of WebP files */
                        _n('%d WebP generated', '%d WebP files generated', $webpCount, 'slimify'),
                        $webpCount
                    )
                )
                : esc_html__('No WebP generated', 'slimify');

            if ($originalCount > 0) {
                $lines[] = esc_html__('1 original backup (full file)', 'slimify');
            } else {
                $lines[] = esc_html__('No original backup', 'slimify');
            }
        }

        if (AjaxAuth::canManageAttachment($attachmentId)) {
            if ($processing) {
                $lines[] = sprintf(
                    '<button type="button" class="button button-small" disabled aria-disabled="true">%s</button>',
                    esc_html__('Processing…', 'slimify')
                );
            } elseif ($flagged && $statsStatus === 'unavailable') {
                // Unavailable finish; retry only if repairable.
                $reason = is_array($stats) ? (string)($stats['reason'] ?? '') : '';
                if ($reason === 'backup_root_unavailable') {
                    $lines[] = sprintf(
                        '<a href="#" class="button button-small slimify-compress-one" data-attachment-id="%d">%s</a>',
                        $attachmentId,
                        esc_html__('Retry compress', 'slimify')
                    );
                }
            } elseif (!$processed || $softProcessed || $statsStatus === 'ineligible') {
                $lines[] = sprintf(
                    '<a href="#" class="button button-small slimify-compress-one" data-attachment-id="%d">%s</a>',
                    $attachmentId,
                    esc_html($softProcessed ? __('Compress again', 'slimify') : __('Compress now', 'slimify'))
                );
                if ($softProcessed) {
                    $lines[] = '<em>' . esc_html__(
                            'No original backup — you can compress again without restore.',
                            'slimify'
                        ) . '</em>';
                }
            } elseif ($originalCount > 0) {
                $lines[] = sprintf(
                    '<a href="#" class="button button-small slimify-restore-one" data-attachment-id="%d">%s</a>',
                    $attachmentId,
                    esc_html__('Restore original', 'slimify')
                );
            }
        }

        return sprintf(
            '<div class="slimify-status" data-attachment-id="%d" data-processing="%s">%s</div>',
            $attachmentId,
            $processing ? '1' : '0',
            implode('<br />', $lines)
        );
    }

    /** True if the attachment is a supported image. */
    private function isEligible(int $attachmentId): bool
    {
        return Limits::isSupportedMime((string)get_post_mime_type($attachmentId));
    }

    /** True if restore is required before compressing again. */
    private function isProcessed(int $attachmentId): bool
    {
        return $this->compressionService->isLocked($attachmentId);
    }

    /** True if the compress run finished. */
    private function isRunFinished(int $attachmentId): bool
    {
        if (!$this->compressionService->isFlagged($attachmentId)) {
            return false;
        }

        $stats = get_post_meta($attachmentId, CompressionService::META_STATS, true);

        return !is_array($stats) || ($stats['status'] ?? '') !== 'partial';
    }

    /** True if compress work is still open. */
    private function isIncompleteRun(int $attachmentId): bool
    {
        if ($this->isRunFinished($attachmentId)) {
            return false;
        }

        $stats = get_post_meta($attachmentId, CompressionService::META_STATS, true);
        if (is_array($stats) && ($stats['status'] ?? '') === 'partial') {
            return true;
        }

        return $this->compressionService->isProcessing($attachmentId);
    }

    /** True if still processing. */
    private function isProcessing(int $attachmentId): bool
    {
        return $this->compressionService->isProcessing($attachmentId);
    }

    /** True if an original backup exists. */
    private function hasOriginalBackup(int $attachmentId): bool
    {
        return $this->compressionService->hasOriginalBackup($attachmentId);
    }

    /** First value from a get_post_meta map. */
    private function metaFirst(array $meta, string $key): mixed
    {
        if (!isset($meta[$key][0])) {
            return null;
        }

        $value = $meta[$key][0];

        return is_string($value) ? maybe_unserialize($value) : $value;
    }

    /** True if the processing marker is still live. */
    private function hasLiveProcessingMarker(mixed $raw): bool
    {
        if ($raw === '' || $raw === false || $raw === null) {
            return false;
        }

        $started = is_numeric($raw) ? (int)$raw : 0;

        return $started <= 0 || (time() - $started) <= 600;
    }
}
