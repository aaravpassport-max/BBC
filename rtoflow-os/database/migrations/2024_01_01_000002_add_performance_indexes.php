<?php
/**
 * Migration: Performance Indexes + Data Integrity Constraints
 *
 * Fixes: P5-DB-004 (payout uniqueness), P5-DB-009 (composite indexes),
 *        P5-DB-010 (CHECK constraints), P5-DB-011 (doc_types indexes)
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddPerformanceIndexes extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // P5-DB-004 FIX: UNIQUE constraint prevents duplicate payouts for same vendor+period
        $this->addIndexSafe('rto_vendor_payouts', 'uq_vendor_period', 'vendor_id, period', true);

        // P5-DB-009 FIX: composite indexes for common multi-column queries

        // Client portal: WHERE client_id=? AND deleted_at IS NULL ORDER BY created_at DESC
        $this->addIndexSafe('rto_leads', 'idx_client_deleted_created', 'client_id, deleted_at, created_at');

        // Vendor job list: WHERE vendor_id=? AND status NOT IN (...) AND deleted_at IS NULL
        $this->addIndexSafe('rto_leads', 'idx_vendor_status_deleted', 'vendor_id, status, deleted_at');

        // Admin filter: WHERE status=? AND deleted_at IS NULL ORDER BY created_at DESC
        $this->addIndexSafe('rto_leads', 'idx_status_deleted_created', 'status, deleted_at, created_at');

        // Vendor lookup: WHERE status='active' AND kyc_status='verified' ORDER BY rating DESC
        $this->addIndexSafe('rto_vendors', 'idx_status_kyc_rating', 'status, kyc_status, rating');

        // Payment sum: WHERE lead_id=? AND status='completed'
        $this->addIndexSafe('rto_payments', 'idx_lead_status_amount', 'lead_id, status, amount');

        // P5-DB-011 FIX: doc_types was completely unindexed
        $this->addIndexSafe('rto_doc_types', 'idx_active',   'is_active');
        $this->addIndexSafe('rto_doc_types', 'idx_category', 'category');

        // P5-DB-010 FIX: CHECK constraints (MySQL 8.0.16+)
        $version = $wpdb->get_var('SELECT VERSION()');
        if (version_compare($version, '8.0.16', '>=')) {
            $constraints = [
                'rto_vendors'       => ['chk_rating',   'rating BETWEEN 0.00 AND 5.00'],
                'rto_invoices'      => ['chk_gst_rate', 'gst_rate IN (0, 5, 12, 18, 28)'],
                'rto_ratings'       => ['chk_score',    'score BETWEEN 1 AND 5'],
                'rto_leads'         => ['chk_priority', 'priority BETWEEN 1 AND 5'],
                'rto_vendor_payouts'=> ['chk_amounts',  'tds_amount >= 0 AND net_amount >= 0 AND gross_amount >= 0'],
            ];
            foreach ($constraints as $table => [$name, $expr]) {
                // Only add if not already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE()
                     AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s",
                    $p . $table, $name
                ));
                if (!$exists) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$p}{$table} ADD CONSTRAINT {$name} CHECK ({$expr})");
                }
            }
        }
    }

    public function down(): void
    {
        $this->dropIndexSafe('rto_vendor_payouts', 'uq_vendor_period');
        $this->dropIndexSafe('rto_leads',          'idx_client_deleted_created');
        $this->dropIndexSafe('rto_leads',          'idx_vendor_status_deleted');
        $this->dropIndexSafe('rto_leads',          'idx_status_deleted_created');
        $this->dropIndexSafe('rto_vendors',        'idx_status_kyc_rating');
        $this->dropIndexSafe('rto_payments',       'idx_lead_status_amount');
        $this->dropIndexSafe('rto_doc_types',      'idx_active');
        $this->dropIndexSafe('rto_doc_types',      'idx_category');
    }

    /** Add index only if it doesn't already exist */
    private function addIndexSafe(string $table, string $name, string $cols, bool $unique = false): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $p . $table, $name
        ));
        if (!$exists) {
            $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$p}{$table} ADD {$type} {$name} ({$cols})");
        }
    }

    private function dropIndexSafe(string $table, string $name): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $p . $table, $name
        ));
        if ($exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$p}{$table} DROP INDEX {$name}");
        }
    }
}
