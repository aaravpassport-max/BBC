<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * Activity Logger
 *
 * Writes audit-trail entries to the rto_logs table.
 * Used by Services and Controllers to record all state-changing actions.
 */
class Logger
{
    public function log(string $action, int $lead_id = 0, mixed $old = null, mixed $new = null): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rto_logs', [
            'user_id'    => get_current_user_id() ?: null,
            'lead_id'    => $lead_id ?: null,
            'action'     => $action,
            'old_value'  => $old !== null ? wp_json_encode($old) : null,
            'new_value'  => $new !== null ? wp_json_encode($new) : null,
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    }
}
