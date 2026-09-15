<?php
/**
 * Migration 30: Granular Staff RBAC
 *
 * ENTERPRISE GAP FIX (Phase 1, item 6 — "Only two staff roles exist
 * platform-wide (rto_admin / rto_staff)"): Router::loadStaff() genuinely
 * only ever queried role__in => ['rto_admin','rto_staff'] — there was no
 * field- or module-level permission granularity anywhere. A finance-only
 * role, a support-only role, or a read-only auditor role could not be
 * created; every rto_staff account had near-identical reach across
 * Payments, Payouts, Vendors, and PII.
 *
 * Design choice: rto_admin remains an unconditional super-admin (every
 * existing rto_is_admin() gate across ~20 controllers keeps working
 * unchanged — this migration does not touch or weaken any of them). The
 * new granularity applies to rto_staff accounts specifically, via a
 * role/permission-matrix layer ON TOP of the existing WP role, not a
 * replacement for it:
 *   - rto_staff_roles: named roles an admin can define (e.g. "Finance",
 *     "Support", "Auditor"), seeded with one system role, 'full_access',
 *     that has every permission — this is the role every EXISTING
 *     rto_staff account is treated as having until an admin explicitly
 *     assigns them something more restricted (see Permissions::can() —
 *     "no role assigned" defaults to full_access, not to deny-everything,
 *     so this migration does not silently lock out any current staff
 *     member the moment it runs).
 *   - rto_role_permissions: one row per (role, module) with four
 *     independent booleans (view/edit/export/approve) — matches the exact
 *     granularity the gap analysis asked for ("permission matrix per
 *     module: view/edit/export/approve").
 * A staff user's assigned role is stored as user meta
 * ('rtoflow_staff_role_id'), not a new column on wp_users — no core-table
 * schema change needed for that half.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateRbac extends Migration
{
    /** Every module Permissions::can() and the Staff Roles screen recognise.
     *  Kept here (not just in Permissions.php) so the seed data below and
     *  the enforcement layer are guaranteed to agree on the exact same list. */
    public const MODULES = [
        'leads', 'vendors', 'vendors_pii', 'payments', 'payouts',
        'settings', 'staff', 'reports', 'complaints', 'ratings',
    ];

    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_staff_roles (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug        VARCHAR(50)  NOT NULL,
            name        VARCHAR(100) NOT NULL,
            description TEXT NULL,
            is_system   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = cannot be deleted (full_access)',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_slug (slug)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_role_permissions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_id    INT UNSIGNED NOT NULL,
            module     VARCHAR(50) NOT NULL,
            can_view   TINYINT(1) NOT NULL DEFAULT 0,
            can_edit   TINYINT(1) NOT NULL DEFAULT 0,
            can_export TINYINT(1) NOT NULL DEFAULT 0,
            can_approve TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_role_module (role_id, module),
            INDEX idx_role (role_id)
        ) {$c}");

        // Seed exactly once — if 'full_access' already exists, a prior run
        // of this migration (or a re-run after a partial failure) already
        // seeded everything; don't duplicate rows.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}rto_staff_roles WHERE slug=%s", 'full_access'
        ));
        if ($exists) return;

        $roles = [
            'full_access' => ['Full Access', 'Everything an rto_staff account could always do — the default for every staff member until an admin assigns a more restricted role.', 1],
            'finance'     => ['Finance', 'Payments, payouts, and financial reporting only — no vendor PII, no staff management.', 0],
            'support'     => ['Support', 'Leads, complaints, and ratings — day-to-day customer support without financial or PII access.', 0],
            'auditor'     => ['Auditor', 'Read-only across every module — for compliance review without the ability to change anything.', 0],
        ];

        $roleIds = [];
        foreach ($roles as $slug => [$name, $desc, $isSystem]) {
            $wpdb->insert($p . 'rto_staff_roles', [
                'slug' => $slug, 'name' => $name, 'description' => $desc, 'is_system' => $isSystem,
            ]);
            $roleIds[$slug] = (int)$wpdb->insert_id;
        }

        foreach (self::MODULES as $module) {
            // full_access: everything on, every module.
            $wpdb->insert($p . 'rto_role_permissions', [
                'role_id' => $roleIds['full_access'], 'module' => $module,
                'can_view' => 1, 'can_edit' => 1, 'can_export' => 1, 'can_approve' => 1,
            ]);

            // auditor: view everything, change nothing.
            $wpdb->insert($p . 'rto_role_permissions', [
                'role_id' => $roleIds['auditor'], 'module' => $module,
                'can_view' => 1, 'can_edit' => 0, 'can_export' => 1, 'can_approve' => 0,
            ]);

            // finance: full control of payments/payouts/reports only.
            $financeModules = ['payments', 'payouts', 'reports'];
            $canFinance = in_array($module, $financeModules, true);
            $wpdb->insert($p . 'rto_role_permissions', [
                'role_id' => $roleIds['finance'], 'module' => $module,
                'can_view' => $canFinance ? 1 : 0, 'can_edit' => $canFinance ? 1 : 0,
                'can_export' => $canFinance ? 1 : 0, 'can_approve' => $canFinance ? 1 : 0,
            ]);

            // support: leads/complaints/ratings only, no approve on any of them by default.
            $supportModules = ['leads', 'complaints', 'ratings'];
            $canSupport = in_array($module, $supportModules, true);
            $wpdb->insert($p . 'rto_role_permissions', [
                'role_id' => $roleIds['support'], 'module' => $module,
                'can_view' => $canSupport ? 1 : 0, 'can_edit' => $canSupport ? 1 : 0,
                'can_export' => $canSupport ? 1 : 0, 'can_approve' => 0,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
