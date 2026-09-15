<?php
/**
 * Migration 11: Generic Configuration Versioning
 *
 * The Customizer brief calls for enterprise-grade version control (draft/
 * publish, rollback, audit) across several independent configuration
 * surfaces — Eligibility Rules, Matching config, Feature Flags, City/Service
 * pricing — that currently each save directly to their own storage (a
 * dedicated table for eligibility, wp_options JSON blobs for matching config
 * and feature flags, an upsert table for city pricing) with no history and
 * no draft state.
 *
 * Rather than bolt version history onto each surface separately, this
 * migration creates one reusable, config-agnostic version store keyed by an
 * arbitrary `config_key` string (e.g. 'matching_config', 'feature_flags',
 * 'eligibility_rules', 'city_service_pricing'). Each row is a full snapshot
 * of that surface's payload at a point in time — draft, published, or
 * archived — so any current or future config screen can plug into the same
 * ConfigVersionService without its own bespoke history table.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateConfigVersions extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_config_versions (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            config_key      VARCHAR(64)  NOT NULL COMMENT 'Which config surface this snapshot belongs to, e.g. matching_config, feature_flags, eligibility_rules, city_service_pricing',
            version_number  INT UNSIGNED NOT NULL COMMENT 'Sequential per config_key, starting at 1',
            payload_json    LONGTEXT     NOT NULL COMMENT 'Full JSON snapshot of the config payload for this version',
            status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            created_by      BIGINT UNSIGNED NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            published_at    DATETIME NULL,
            notes           VARCHAR(500) NULL,

            INDEX idx_config_key (config_key),
            INDEX idx_status     (status),
            UNIQUE KEY uniq_config_version (config_key, version_number)
        ) {$c}");
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_config_versions");
    }
}
