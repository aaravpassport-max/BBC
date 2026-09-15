<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 4, item 6 — "no notification preference
 * controls anywhere"): see migration 2024_01_01_000036 for the schema and
 * problem statement. get() defaults every channel to enabled when no row
 * exists yet, so this is purely additive — nobody's notifications silently
 * stop until they explicitly visit their own preferences screen and turn
 * something off.
 *
 * Scope, stated honestly: this build wires channel on/off filtering into
 * NotificationService::send() (see filterChannels() below, called from
 * send() before any template lookup). Quiet-hours columns exist and are
 * saveable, but are NOT enforced anywhere yet — doing so correctly needs a
 * deferred-send/retry-at-window mechanism the notification queue does not
 * have today (processQueue() is a simple due-now poll, not a scheduler).
 * Rather than silently ignore a saved quiet-hours value, callers should
 * treat it as "recorded for a future build," not "currently honored."
 */
class NotificationPreferenceService
{
    private const TABLE = 'rto_notification_preferences';

    public static function get(int $userId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE user_id=%d", $userId
        ), ARRAY_A);

        return [
            'email_enabled'     => $row ? (bool)$row['email_enabled']     : true,
            'sms_enabled'       => $row ? (bool)$row['sms_enabled']       : true,
            'whatsapp_enabled'  => $row ? (bool)$row['whatsapp_enabled']  : true,
            'quiet_hours_start' => $row['quiet_hours_start'] ?? null,
            'quiet_hours_end'   => $row['quiet_hours_end']   ?? null,
        ];
    }

    public static function save(int $userId, array $prefs): bool
    {
        global $wpdb;
        $data = [
            'email_enabled'     => !empty($prefs['email_enabled'])    ? 1 : 0,
            'sms_enabled'       => !empty($prefs['sms_enabled'])      ? 1 : 0,
            'whatsapp_enabled'  => !empty($prefs['whatsapp_enabled']) ? 1 : 0,
            'quiet_hours_start' => isset($prefs['quiet_hours_start']) && $prefs['quiet_hours_start'] !== '' ? max(0, min(23, (int)$prefs['quiet_hours_start'])) : null,
            'quiet_hours_end'   => isset($prefs['quiet_hours_end'])   && $prefs['quiet_hours_end']   !== '' ? max(0, min(23, (int)$prefs['quiet_hours_end']))   : null,
            'updated_at'        => current_time('mysql'),
        ];

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}" . self::TABLE . " WHERE user_id=%d", $userId
        ));

        if ($exists) {
            return $wpdb->update($wpdb->prefix . self::TABLE, $data, ['user_id' => $userId]) !== false;
        }
        $data['user_id'] = $userId;
        return (bool)$wpdb->insert($wpdb->prefix . self::TABLE, $data);
    }

    /**
     * Drop any channel the user has disabled from a requested channel list.
     * Called by NotificationService::send() before it does anything else —
     * an empty result means "send nothing," which send() must handle
     * gracefully (it already no-ops on an empty $channels loop).
     */
    public static function filterChannels(int $userId, array $channels): array
    {
        $prefs = self::get($userId);
        $map = [
            'email'    => $prefs['email_enabled'],
            'sms'      => $prefs['sms_enabled'],
            'whatsapp' => $prefs['whatsapp_enabled'],
        ];
        return array_values(array_filter($channels, fn($ch) => $map[$ch] ?? true));
    }
}
