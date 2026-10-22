<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Compression\CompressionService;
use Slimify\Offload\OffloadAdapter;
use Slimify\Support\Limits;
use WP_Query;

/** Scan, list, and delete media-library rows whose files are not on disk. */
final readonly class MissingFiles
{
    /** Constructor. */
    public function __construct(
        private OffloadAdapter $offloadAdapter,
        private CompressionService $compressionService,
        private BulkCompression $bulkCompression,
    ) {
    }

    /** Register AJAX hooks. */
    public function register(): void
    {
        add_action('wp_ajax_slimify_missing_scan', [$this, 'ajaxScan']);
        add_action('wp_ajax_slimify_missing_list', [$this, 'ajaxList']);
        add_action('wp_ajax_slimify_missing_delete', [$this, 'ajaxDelete']);
    }

    /** AJAX: check one page of JPEG/PNG attachments for missing files. */
    public function ajaxScan(): void
    {
        AjaxAuth::requireManageOptions('slimify_missing_files');
        AjaxAuth::requireRateLimit('missing_scan', 0.4);

        $page = max(1, $this->postAbsint('page'));
        $ids = $this->queryImageIdsPage($page, Limits::MISSING_SCAN_PAGE);
        $found = 0;
        $cleared = 0;

        foreach ($ids as $id) {
            if (!$this->offloadAdapter->isMissingLocalGhost($id)) {
                if ($this->compressionService->isIgnored($id)) {
                    $this->compressionService->clearIgnored($id);
                    ++$cleared;
                }
                continue;
            }

            $this->compressionService->markIgnored($id, 'missing_file');
            ++$found;
        }

        $done = $ids === [] || count($ids) < Limits::MISSING_SCAN_PAGE;
        $status = $this->bulkCompression->status();

        wp_send_json_success([
            'page' => $page,
            'next_page' => $done ? $page : $page + 1,
            'scanned' => count($ids),
            'found' => $found,
            'cleared' => $cleared,
            'done' => $done,
            'status' => $status,
        ]);
    }

    /** AJAX: paged list of ignored attachments. */
    public function ajaxList(): void
    {
        AjaxAuth::requireManageOptions('slimify_missing_files');

        $page = max(1, $this->postAbsint('page'));
        $perPage = Limits::MISSING_LIST_PAGE;
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => Limits::supportedMimes(),
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
            'meta_query' => [
                [
                    'key' => CompressionService::META_IGNORE,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $rows[] = $this->rowFor((int)$post->ID, $post);
        }

        wp_send_json_success([
            'rows' => $rows,
            'page' => $page,
            'pages' => max(1, (int)$query->max_num_pages),
            'total' => (int)$query->found_posts,
            'status' => $this->bulkCompression->status(),
        ]);
    }

    /** AJAX: permanently delete missing attachments. */
    public function ajaxDelete(): void
    {
        AjaxAuth::requireManageOptions('slimify_missing_files');
        AjaxAuth::requireRateLimit('missing_delete', 0.5);

        $ids = $this->postIdList();
        if ($ids === []) {
            $ids = $this->nextIgnoredIds(Limits::MISSING_DELETE_PAGE);
        }

        $deleted = [];
        $failed = [];
        foreach ($ids as $id) {
            if ($id <= 0 || get_post_type($id) !== 'attachment') {
                $failed[] = $id;
                continue;
            }
            if (!current_user_can('delete_post', $id) || !$this->compressionService->isIgnored($id)) {
                $failed[] = $id;
                continue;
            }
            if (!$this->offloadAdapter->isMissingLocalGhost($id)) {
                $this->compressionService->clearIgnored($id);
                $failed[] = $id;
                continue;
            }

            $result = wp_delete_attachment($id, true);
            if ($result === false || $result === null) {
                $failed[] = $id;
                continue;
            }
            $deleted[] = $id;
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'failed' => $failed,
            'remaining' => $this->ignoredCount(),
            'status' => $this->bulkCompression->status(),
        ]);
    }

    /** Build a table row for an ignored attachment. */
    private function rowFor(int $id, \WP_Post $post): array
    {
        $attached = get_post_meta($id, '_wp_attached_file', true);
        $path = is_string($attached) ? $attached : '';

        return [
            'id' => $id,
            'title' => $post->post_title !== '' ? $post->post_title : sprintf('#%d', $id),
            'path' => $path,
            'edit' => get_edit_post_link($id, 'raw') ?: admin_url('post.php?post=' . $id . '&action=edit'),
        ];
    }

    /** Page of JPEG/PNG attachment IDs. */
    private function queryImageIdsPage(int $page, int $batchSize): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => Limits::supportedMimes(),
            'posts_per_page' => $batchSize,
            'paged' => $page,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return array_map('intval', $query->posts);
    }

    /** Next ignored attachment IDs. */
    private function nextIgnoredIds(int $limit): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => Limits::supportedMimes(),
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => CompressionService::META_IGNORE,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        return array_map('intval', $query->posts);
    }

    /** Count ignored JPEG/PNG attachments. */
    private function ignoredCount(): int
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => Limits::supportedMimes(),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => [
                [
                    'key' => CompressionService::META_IGNORE,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        return (int)$query->found_posts;
    }

    /** Read absint from POST after nonce verification. */
    private function postAbsint(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        if (!isset($_POST[$key])) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        return absint(wp_unslash($_POST[$key]));
    }

    /** Comma-separated attachment IDs from POST. */
    private function postIdList(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        if (!isset($_POST['ids'])) {
            return [];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers use AjaxAuth::requireManageOptions().
        $raw = sanitize_text_field(wp_unslash((string)$_POST['ids']));
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = absint($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
