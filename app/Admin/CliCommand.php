<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Compression\CompressionService;
use Slimify\Offload\OffloadAdapter;
use Slimify\Support\Limits;
use WP_Query;

final readonly class CliCommand
{
    /** Constructor. */
    public function __construct(
        private CompressionService $compressionService,
        private OffloadAdapter $offloadAdapter,
    ) {
    }

    /** Register hooks. */
    public static function register(CompressionService $compressionService, OffloadAdapter $offloadAdapter): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('media compress', new self($compressionService, $offloadAdapter));
    }

    /**
     * Compress media attachments (pngquant / MozJPEG / Imagick).
     *
     * ## OPTIONS
     *
     * [--all]
     * : Compress all image attachments.
     *
     * [--attachment=<id>]
     * : Compress a single attachment ID.
     *
     * [--dry-run]
     * : Report actions without writing files.
     *
     * [--batch-size=<n>]
     * : Attachments per database page (default 50, max 50).
     *
     * ## EXAMPLES
     *
     *     wp media compress --all
     *     wp media compress --attachment=123
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $all = isset($assocArgs['all']);
        $dryRun = isset($assocArgs['dry-run']);
        $attachmentId = isset($assocArgs['attachment']) ? absint($assocArgs['attachment']) : 0;
        $batchSize = isset($assocArgs['batch-size'])
            ? max(1, min(Limits::CLI_BATCH_SIZE_MAX, absint($assocArgs['batch-size'])))
            : Limits::CLI_BATCH_SIZE;

        if (!$all && $attachmentId <= 0) {
            \WP_CLI::error('Provide --all or --attachment=<id>.');
        }

        $ok = 0;
        $fail = 0;
        $skipped = 0;

        if ($attachmentId > 0) {
            $result = $this->processOne($attachmentId, $dryRun);
            if ($result === 'ok') {
                ++$ok;
            } elseif ($result === 'skip') {
                ++$skipped;
            } else {
                ++$fail;
            }
            \WP_CLI::success(sprintf('Done. ok=%d fail=%d skipped=%d%s', $ok, $fail, $skipped, $dryRun ? ' (dry-run)' : ''));

            return;
        }

        $page = 1;
        $totalEstimate = $this->countImages();
        $progress = \WP_CLI\Utils\make_progress_bar('Compressing media', max(1, $totalEstimate));

        while (true) {
            $ids = $this->queryImageIdsPage($page, $batchSize);
            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                if ($this->compressionService->isLocked($id)) {
                    ++$skipped;
                    $progress->tick();
                    continue;
                }
                $result = $this->processOne($id, $dryRun);
                if ($result === 'ok') {
                    ++$ok;
                } elseif ($result === 'skip') {
                    ++$skipped;
                } else {
                    ++$fail;
                    \WP_CLI::warning(sprintf('#%d failed', $id));
                }
                $progress->tick();
                usleep(Limits::CLI_ITEM_PAUSE_US);
            }

            // Drop runtime cache between pages.
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            usleep(Limits::CLI_BATCH_PAUSE_US);
            ++$page;

            if (count($ids) < $batchSize) {
                break;
            }
        }

        $progress->finish();

        if (!$dryRun) {
            $safety = 0;
            while ($this->compressionService->queue()->count() > 0 && $safety < 200) {
                $this->compressionService->drainQueue(Limits::QUEUE_DRAIN_MAX, $this->offloadAdapter);
                ++$safety;
            }
        }

        \WP_CLI::success(sprintf('Done. ok=%d fail=%d skipped=%d%s', $ok, $fail, $skipped, $dryRun ? ' (dry-run)' : ''));
    }

    /** Compress one attachment (CLI). */
    private function processOne(int $id, bool $dryRun): string
    {
        if ($this->compressionService->isLocked($id)) {
            return 'skip';
        }

        $result = $this->offloadAdapter->compressAttachment($id, $dryRun);
        if (!empty($result['busy'])) {
            // Wait briefly for the compress lock.
            if (!$dryRun) {
                usleep(200_000);
                $retry = $this->offloadAdapter->compressAttachment($id, false);
                if (!empty($retry['busy'])) {
                    $this->compressionService->queue()->push($id);

                    return 'skip';
                }
                $result = $retry;
            } else {
                return 'skip';
            }
        }

        return $result['ok'] ? 'ok' : 'fail';
    }

    /** Count eligible images. */
    private function countImages(): int
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => Limits::supportedMimes(),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        return $query->found_posts;
    }

    /** Page of eligible attachment IDs. */
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

        /**
         * @var list<int>
         */
        return array_map('intval', $query->posts);
    }
}
