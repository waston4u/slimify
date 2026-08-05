<?php

declare(strict_types=1);

namespace Slimify\Support;

/** Per-site bulk compress counts for the admin UI. */
final class LibraryStats
{
    private const string TRANSIENT_PREFIX = 'slimify_bulk_status_cache';

    private const int TTL = 30;

    /** Transient key for this site. */
    private static function key(): string
    {
        return self::TRANSIENT_PREFIX . '_' . Site::id();
    }

    /** Clear the bulk status cache. */
    public static function invalidate(): void
    {
        if (function_exists('delete_transient')) {
            delete_transient(self::key());
        }
    }

    /**
     * Return cached status or null.
     *
     * @return array{total: int, compressed: int, pending: int}|null
     */
    public static function getCached(): ?array
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $stored = get_transient(self::key());
        if (
            !is_array($stored)
            || !isset($stored['total'], $stored['compressed'], $stored['pending'])
        ) {
            return null;
        }

        return [
            'total' => (int)$stored['total'],
            'compressed' => (int)$stored['compressed'],
            'pending' => (int)$stored['pending'],
        ];
    }

    /**
     * @param array{total: int, compressed: int, pending: int} $status
     */
    public static function putCache(array $status): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient(self::key(), [
            'total' => (int)$status['total'],
            'compressed' => (int)$status['compressed'],
            'pending' => (int)$status['pending'],
        ], self::TTL);
    }
}
