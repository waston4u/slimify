<?php

declare(strict_types=1);

namespace Slimify\Support;

/** SSRF checks for remote image downloads. */
final class RemoteUrlGuard
{
    /** Extra IPv4 ranges blocked for SSRF (metadata / shared hosts).
     * @var list<array{0: string, 1: int}>
     */
    private const array EXTRA_BLOCKED_V4 = [
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['198.18.0.0', 15],
    ];

    /** True if the URL is safe to fetch. */
    public static function isSafe(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            return false;
        }

        if (function_exists('wp_http_validate_url') && wp_http_validate_url($url) === false) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        // Reject URL userinfo.
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        foreach (['.local', '.localhost', '.internal', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        // Dual DNS resolve against rebinding.
        $addresses = self::resolveHostAddresses($host);
        if ($addresses === []) {
            return false;
        }

        $addressesAgain = self::resolveHostAddresses($host);
        sort($addresses);
        sort($addressesAgain);
        if ($addresses !== $addressesAgain) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-check the host still resolves only to public IPs.
     */
    public static function stillSafeAfterFetch(string $url): bool
    {
        return self::isSafe($url);
    }

    /** Resolve host A/AAAA records.
     * @return list<string>
     */
    private static function resolveHostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $field) {
                    if (isset($record[$field]) && is_string($record[$field]) && $record[$field] !== '') {
                        $addresses[] = $record[$field];
                    }
                }
            }
        }

        if ($addresses === []) {
            $resolved = @gethostbyname($host);
            if (is_string($resolved) && $resolved !== $host) {
                $addresses[] = $resolved;
            }
        }

        return array_values(array_unique($addresses));
    }

    /** True if the address is public. */
    private static function isPublicAddress(string $address): bool
    {
        if (stripos($address, '::ffff:') === 0) {
            $mapped = substr($address, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $address = $mapped;
            }
        }

        if (
            filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            return false;
        }

        return !self::isInExtraBlockedRange($address);
    }

    /** True if the address is in a blocked range. */
    private static function isInExtraBlockedRange(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ip = ip2long($address);
        if ($ip === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_V4 as [$network, $bits]) {
            $base = ip2long($network);
            if ($base === false) {
                continue;
            }
            $mask = -1 << (32 - $bits);
            if (($ip & $mask) === ($base & $mask)) {
                return true;
            }
        }

        return false;
    }
}
