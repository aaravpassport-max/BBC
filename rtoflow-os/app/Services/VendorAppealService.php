<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
 * workflow for vendors"): see migration 2024_01_01_000035_create_vendor_appeals
 * for the full problem statement. Deliberately thin — a vendor can only ever
 * create a 'pending' appeal against their own vendor_id; only an admin can
 * move it to 'upheld'/'rejected' (see VendorsController::resolveAppeal()).
 */
class VendorAppealService
{
    private const TABLE = 'rto_vendor_appeals';

    public static function submit(int $vendorId, string $subjectType, int $subjectId, string $reason): int
    {
        global $wpdb;
        $inserted = $wpdb->insert($wpdb->prefix . self::TABLE, [
            'vendor_id'    => $vendorId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'reason'       => $reason,
            'status'       => 'pending',
            'created_at'   => current_time('mysql'),
        ]);
        return $inserted ? (int)$wpdb->insert_id : 0;
    }

    /** @return array{rows: array, total: int} */
    public static function pending(int $page = 1, int $perPage = 30): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}" . self::TABLE . " WHERE status='pending'");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, v.full_name, v.vendor_number
             FROM {$p}" . self::TABLE . " a
             JOIN {$p}rto_vendors v ON v.id = a.vendor_id
             WHERE a.status = 'pending'
             ORDER BY a.created_at ASC LIMIT %d OFFSET %d",
            $perPage, max(0, ($page - 1) * $perPage)
        ), ARRAY_A) ?: [];
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows: array, total: int} */
    public static function resolved(int $page = 1, int $perPage = 30): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}" . self::TABLE . " WHERE status != 'pending'");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, v.full_name, v.vendor_number
             FROM {$p}" . self::TABLE . " a
             JOIN {$p}rto_vendors v ON v.id = a.vendor_id
             WHERE a.status != 'pending'
             ORDER BY a.resolved_at DESC LIMIT %d OFFSET %d",
            $perPage, max(0, ($page - 1) * $perPage)
        ), ARRAY_A) ?: [];
        return ['rows' => $rows, 'total' => $total];
    }

    public static function resolve(int $appealId, string $status, string $adminResponse, int $adminUserId): bool
    {
        if (!in_array($status, ['upheld', 'rejected'], true)) return false;
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . self::TABLE, [
            'status'         => $status,
            'admin_response' => $adminResponse,
            'resolved_by'    => $adminUserId,
            'resolved_at'    => current_time('mysql'),
        ], ['id' => $appealId, 'status' => 'pending']);
        return $updated !== false && $updated > 0;
    }

    /** Has this vendor already got a pending appeal for this exact subject? Prevents duplicate spam-appeals. */
    public static function hasPending(int $vendorId, string $subjectType, int $subjectId): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE . "
             WHERE vendor_id=%d AND subject_type=%s AND subject_id=%d AND status='pending'",
            $vendorId, $subjectType, $subjectId
        ));
    }
}
