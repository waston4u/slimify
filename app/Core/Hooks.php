<?php

declare(strict_types=1);

namespace Slimify\Core;

use Slimify\Compression\CompressionService;
use Slimify\Offload\OffloadAdapter;
use Slimify\Support\Limits;

final readonly class Hooks
{
    /** Constructor. */
    public function __construct(
        private CompressionService $compressionService,
        private OffloadAdapter $offloadAdapter,
    ) {
    }

    /** Register hooks. */
    public function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'compressAttachment'], 5, 2);
        add_action('add_attachment', [$this, 'markAttachmentProcessing'], 5, 1);
        add_action('delete_attachment', [$this, 'cleanupAttachment'], 10, 1);
        add_action('slimify_drain_queue', [$this, 'drainQueue']);
    }

    /** Mark a new attachment as processing. */
    public function markAttachmentProcessing(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $mime = (string) get_post_mime_type($attachmentId);
        if (! Limits::isSupportedMime($mime)) {
            return;
        }

        $this->compressionService->markProcessing($attachmentId);
    }

    /** Drain the deferred queue. */
    public function drainQueue(): void
    {
        $this->compressionService->drainQueue(Limits::QUEUE_DRAIN_MAX, $this->offloadAdapter);
    }

    /** Compress on upload (or offload). */
    public function compressAttachment(array $metadata, int $attachmentId): array
    {
        if ($attachmentId <= 0 || CompressionService::isMetadataCompressSuppressed()) {
            return $metadata;
        }

        $mime = (string) get_post_mime_type($attachmentId);
        if (! Limits::isSupportedMime($mime)) {
            return $metadata;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return $this->compressionService->compressAttachmentMetadata($metadata, $attachmentId);
        }

        $this->compressionService->queueUploadCompression($attachmentId);

        return $metadata;
    }

    /** Clean up when an attachment is deleted. */
    public function cleanupAttachment(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }

        $map = get_post_meta($attachmentId, CompressionService::META_WEBP, true);
        if (! is_array($map)) {
            $metadata = wp_get_attachment_metadata($attachmentId);
            $map = (is_array($metadata) && isset($metadata['slimify_webp']) && is_array($metadata['slimify_webp']))
                ? $metadata['slimify_webp']
                : [];
        }

        $file = get_attached_file($attachmentId, true);
        $dir = is_string($file) && $file !== '' ? dirname($file) : '';

        if ($dir !== '' && is_array($map)) {
            foreach ($map as $basename) {
                if (! is_string($basename) || $basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $basename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        if (is_string($file) && $file !== '' && is_file($file)) {
            $sibling = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
            if (is_string($sibling) && $sibling !== $file && is_file($sibling)) {
                @unlink($sibling);
            }
        }

        $this->compressionService->purgeAttachmentArtifacts($attachmentId);
    }
}
