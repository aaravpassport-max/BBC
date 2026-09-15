<?php

namespace RTOFLOW\Security;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 1, item 6 — "Only two staff roles exist
 * platform-wide"): a real permission-matrix layer for rto_staff accounts,
 * on top of (not instead of) the existing rto_is_admin()/rto_is_staff()
 * WP-role gates. rto_admin stays an unconditional super-admin everywhere —
 * this class is never consulted for an admin account, matching the design
 * note in migration 2024_01_01_000030_create_rbac.php.
 *
 * Usage at a call site that used to be a bare rto_is_staff() gate:
 *   if (!Permissions::can(get_current_user_id(), 'payouts', 'approve')) rto_json_err('Access denied.', 403);
 *
 * Rollout scope, stated honestly: this is wired into the highest-risk
 * financial/PII actions the gap analysis specifically named (payout
 * generate/mark-paid, refund initiate/approve/reject, vendor bank
 * verification, vendor PII/bank-detail visibility) — not into all ~20
 * admin controllers in one pass. Every remaining controller can adopt the
 * exact same Permissions::can() call; the matrix and admin UI to manage it
 * are already real and general-purpose, so extending coverage is
 * mechanical follow-up work, not a redesign.
 */
class Permissions
{
    /** Must match CreateRbac::MODULES exactly — see that migration's docblock. */
    public const MODULES = [
        'leads', 'vendors', 'vendors_pii', 'payments', 'payouts',
        'settings', 'staff', 'reports', 'complaints', 'ratings',
    ];

    private const USER_META_KEY = 'rtoflow_staff_role_id';

    /**
     * @param string $action One of 'view', 'edit', 'export', 'approve'.
     */
    public static function can(int $userId, string $module, string $action): bool
    {
        if (rto_is_admin($userId)) return true; // super-admin, unconditional — see class docblock
        if (!rto_is_staff($userId)) return false; // vendors/clients never reach staff-only actions via this check

        $roleId = self::roleIdFor($userId);
        if (!$roleId) return true; // no role assigned yet — grandfathered as full_access, see migration docblock

        global $wpdb;
        $col = 'can_' . $action;
        if (!in_array($col, ['can_view', 'can_edit', 'can_export', 'can_approve'], true)) return false;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$col} FROM {$wpdb->prefix}rto_role_permissions WHERE role_id=%d AND module=%s",
            $roleId, $module
        ), ARRAY_A);

        // No row for this (role, module) pair at all — deny rather than
        // silently allow; a restricted role with a gap in its matrix should
        // fail closed, not open.
        return $row ? (bool)$row[$col] : false;
    }

    public static function roleIdFor(int $userId): ?int
    {
        $val = get_user_meta($userId, self::USER_META_KEY, true);
        return $val ? (int)$val : null;
    }

    public static function assignRole(int $userId, int $roleId): bool
    {
        return (bool)update_user_meta($userId, self::USER_META_KEY, $roleId);
    }

    public static function clearRole(int $userId): bool
    {
        return delete_user_meta($userId, self::USER_META_KEY);
    }

    /** @return array<int, array{id:int,slug:string,name:string,description:string,is_system:bool}> */
    public static function allRoles(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, slug, name, description, is_system FROM {$wpdb->prefix}rto_staff_roles ORDER BY is_system DESC, name",
            ARRAY_A
        ) ?: [];
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'description' => $r['description'], 'is_system' => (bool)$r['is_system'],
        ], $rows);
    }

    /** @return array<string, array{can_view:bool,can_edit:bool,can_export:bool,can_approve:bool}> keyed by module */
    public static function matrixForRole(int $roleId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT module, can_view, can_edit, can_export, can_approve FROM {$wpdb->prefix}rto_role_permissions WHERE role_id=%d",
            $roleId
        ), ARRAY_A) ?: [];

        $matrix = [];
        foreach (self::MODULES as $m) {
            $matrix[$m] = ['can_view' => false, 'can_edit' => false, 'can_export' => false, 'can_approve' => false];
        }
        foreach ($rows as $r) {
            $matrix[$r['module']] = [
                'can_view' => (bool)$r['can_view'], 'can_edit' => (bool)$r['can_edit'],
                'can_export' => (bool)$r['can_export'], 'can_approve' => (bool)$r['can_approve'],
            ];
        }
        return $matrix;
    }
}
