<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views on any
 * list screen"): a generic, per-user, per-screen saved-filter primitive —
 * see migration 2024_01_01_000042_create_saved_filters.php for why it's
 * shaped as (screen, name, raw query string) rather than per-field columns.
 */
class SavedFilterService
{
    const MAX_PER_USER_SCREEN = 20;

    public static function list(int $userId, string $screen): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, query_string FROM {$wpdb->prefix}rto_saved_filters
             WHERE user_id=%d AND screen=%s ORDER BY created_at DESC",
            $userId, $screen
        ), ARRAY_A) ?: [];
    }

    public static function save(int $userId, string $screen, string $name, string $queryString): array
    {
        $name = trim($name);
        if ($name === '') return ['success' => false, 'message' => 'Please name this filter.'];

        global $wpdb;
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rto_saved_filters WHERE user_id=%d AND screen=%s",
            $userId, $screen
        ));
        if ($count >= self::MAX_PER_USER_SCREEN) {
            return ['success' => false, 'message' => 'You have reached the maximum of ' . self::MAX_PER_USER_SCREEN . ' saved filters for this screen. Delete one first.'];
        }

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_saved_filters', [
            'user_id'      => $userId,
            'screen'       => $screen,
            'name'         => mb_substr($name, 0, 100),
            'query_string' => mb_substr($queryString, 0, 500),
            'created_at'   => current_time('mysql'),
        ]);
        if (!$inserted) return ['success' => false, 'message' => 'Could not save this filter. Please try again.'];

        return ['success' => true, 'id' => (int)$wpdb->insert_id];
    }

    // Ownership-checked delete — a user can only ever delete their own
    // saved filter, never another user's, even by guessing an id.
    public static function delete(int $userId, int $id): bool
    {
        global $wpdb;
        return (bool)$wpdb->delete($wpdb->prefix . 'rto_saved_filters', ['id' => $id, 'user_id' => $userId]);
    }
}
