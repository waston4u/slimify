<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Compression\CompressionService;
use Slimify\Offload\OffloadAdapter;
use Slimify\Support\DeferredQueue;
use Slimify\Support\LibraryStats;
use Slimify\Support\Limits;

final readonly class BulkCompression
{
    /** Constructor. */
    public function __construct(
        private OffloadAdapter $offloadAdapter,
        private DeferredQueue $queue,
    ) {
    }

    /** Register hooks. */
    public function register(): void
    {
        add_action('wp_ajax_slimify_bulk_status', [$this, 'ajaxStatus']);
        add_action('wp_ajax_slimify_bulk_compress', [$this, 'ajaxCompress']);
    }

    /** Bulk library counts (short-lived cache; invalidated on flag changes). */
    public function status(): array
    {
        $cached = LibraryStats::getCached();
        if ($cached !== null) {
            return $cached;
        }

        global $wpdb;

        $mimes = Limits::supportedMimes();
        $placeholders = implode(', ', array_fill(0, count($mimes), '%s'));
        $sql = "
            SELECT
                COUNT(DISTINCT p.ID) AS total,
                COUNT(DISTINCT done.post_id) AS compressed,
                COUNT(DISTINCT ignored.post_id) AS missing
            FROM $wpdb->posts p
            LEFT JOIN $wpdb->postmeta done
                ON done.post_id = p.ID
                AND done.meta_key = %s
                AND done.meta_value = '1'
            LEFT JOIN $wpdb->postmeta ignored
                ON ignored.post_id = p.ID
                AND ignored.meta_key = %s
                AND ignored.meta_value <> ''
            WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND p.post_mime_type IN ($placeholders)
        ";

        $args = array_merge(
            [CompressionService::META_FLAG, CompressionService::META_IGNORE],
            $mimes
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders are generated from a fixed mime list; prepared via $wpdb->prepare().
        $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);

        $total = isset($row['total']) ? (int)$row['total'] : 0;
        $compressed = isset($row['compressed']) ? (int)$row['compressed'] : 0;
        $missing = isset($row['missing']) ? (int)$row['missing'] : 0;

        $status = [
            'total' => $total,
            'compressed' => $compressed,
            'missing' => $missing,
            'pending' => max(0, $total - $compressed - $missing),
        ];

        LibraryStats::putCache($status);

        return $status;
    }

    /** AJAX: bulk progress status. */
    public function ajaxStatus(): void
    {
        $this->authorize();
        wp_send_json_success($this->status());
    }

    /** AJAX: compress the next pending attachment. */
    public function ajaxCompress(): void
    {
        $this->authorize();
        AjaxAuth::requireRateLimit('bulk_compress', 1.0);

        $attachmentId = $this->nextPendingAttachmentId();
        if ($attachmentId === null) {
            wp_send_json_success([
                'done' => true,
                'status' => $this->status(),
                'message' => __('All eligible images have been processed.', 'slimify'),
            ]);
        }

        $result = $this->offloadAdapter->compressAttachment($attachmentId, false);

        if (!empty($result['busy'])) {
            wp_send_json_error([
                'attachment_id' => $attachmentId,
                'busy' => true,
                'message' => $result['message'] !== ''
                    ? $result['message']
                    : __('Compression is busy with another image. Retrying shortly.', 'slimify'),
            ], 409);
        }

        if (!empty($result['ignored'])) {
            $status = $this->status();
            wp_send_json_success([
                'done' => $status['pending'] === 0,
                'attachment_id' => $attachmentId,
                'files' => $result['files'] ?? 0,
                'ignored' => true,
                'status' => $status,
                'message' => $result['message'] !== ''
                    ? $result['message']
                    : __('Missing file skipped; continuing with the library.', 'slimify'),
            ]);
        }

        $processed = get_post_meta($attachmentId, CompressionService::META_FLAG, true) === '1';

        if (!empty($result['permanent']) && $processed) {
            $status = $this->status();
            wp_send_json_success([
                'done' => $status['pending'] === 0,
                'attachment_id' => $attachmentId,
                'files' => $result['files'] ?? 0,
                'permanent' => true,
                'status' => $status,
                'message' => $result['message'] !== ''
                    ? $result['message']
                    : __('Attachment skipped permanently; continuing with the library.', 'slimify'),
            ]);
        }

        if (!$result['ok']) {
            wp_send_json_error([
                'attachment_id' => $attachmentId,
                'message' => $result['message'],
                'status' => $this->status(),
            ], 422);
        }

        if (!$processed) {
            wp_send_json_success([
                'done' => false,
                'attachment_id' => $attachmentId,
                'files' => $result['files'],
                'partial' => true,
                'message' => $result['message'],
                'status' => $this->status(),
            ]);
        }

        $status = $this->status();
        wp_send_json_success([
            'done' => $status['pending'] === 0,
            'attachment_id' => $attachmentId,
            'files' => $result['files'],
            'status' => $status,
            'message' => $result['message'] !== ''
                ? $result['message']
                : sprintf(
                /* translators: %d: attachment ID */
                    __('Processed attachment #%d.', 'slimify'),
                    $attachmentId
                ),
        ]);
    }

    private function authorize(): void
    {
        AjaxAuth::requireManageOptions('slimify_bulk_compression');
    }

    /** Next eligible attachment, excluding the deferred queue. */
    private function nextPendingAttachmentId(): ?int
    {
        global $wpdb;

        $skip = array_values(array_filter($this->queue->allIds(), static fn(int $id): bool => $id > 0));
        $mimes = Limits::supportedMimes();
        $mimeHolders = implode(', ', array_fill(0, count($mimes), '%s'));

        $notIn = '';
        $args = [CompressionService::META_FLAG, CompressionService::META_IGNORE];
        if ($skip !== []) {
            $idHolders = implode(', ', array_fill(0, count($skip), '%d'));
            $notIn = " AND p.ID NOT IN ($idHolders)";
            $args = array_merge($args, $skip);
        }
        $args = array_merge($args, $mimes);

        $sql = "
            SELECT p.ID
            FROM $wpdb->posts p
            LEFT JOIN $wpdb->postmeta done
                ON done.post_id = p.ID
                AND done.meta_key = %s
                AND done.meta_value = '1'
            LEFT JOIN $wpdb->postmeta ignored
                ON ignored.post_id = p.ID
                AND ignored.meta_key = %s
                AND ignored.meta_value <> ''
            WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                {$notIn}
                AND p.post_mime_type IN ($mimeHolders)
                AND done.post_id IS NULL
                AND ignored.post_id IS NULL
            ORDER BY p.ID ASC
            LIMIT 1
        ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders are generated from fixed lists; prepared via $wpdb->prepare().
        $id = $wpdb->get_var($wpdb->prepare($sql, ...$args));

        return $id !== null ? (int)$id : null;
    }
}
