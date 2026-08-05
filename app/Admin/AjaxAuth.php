<?php

declare(strict_types=1);

namespace Slimify\Admin;

use Slimify\Support\Site;

/** Admin AJAX capability and nonce checks. */
final class AjaxAuth
{
    /** Require manage_options and a valid nonce. */
    public static function requireManageOptions(string $nonceAction, string $queryArg = 'nonce'): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden.', 'slimify')], 403);
        }

        check_ajax_referer($nonceAction, $queryArg);
    }

    /** Rate-limit an AJAX action (per user and site); dies with 429 if limited. */
    public static function requireRateLimit(string $action, float $minSeconds): void
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $key = 'slimify_rl_' . $action . '_' . Site::id() . '_' . $userId;
        $now = microtime(true);
        $last = get_transient($key);
        if (is_numeric($last) && ($now - (float) $last) < $minSeconds) {
            wp_send_json_error([
                'busy' => true,
                'message' => __('Please wait a moment and try again.', 'slimify'),
            ], 429);
        }

        // TTL at least 1s; floor minSeconds for expiry.
        set_transient($key, (string) $now, max(1, (int) ceil($minSeconds * 2)));
    }

    /** True if the user may manage the plugin. */
    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** True if the user may edit this attachment. */
    public static function canManageAttachment(int $attachmentId): bool
    {
        return $attachmentId > 0
            && self::canManage()
            && current_user_can('edit_post', $attachmentId);
    }
}
