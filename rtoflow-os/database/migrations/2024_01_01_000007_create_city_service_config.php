<?php
/**
 * Migration 7: City-Service Pricing & Visibility Configuration
 *
 * Creates the rto_city_service_config table which stores per-city, per-service
 * configuration for:
 *   - Service visibility (show/hide this service on this city's page)
 *   - Price visibility (show/hide the price for this service in this city)
 *   - Government fee (city-specific govt fee override)
 *   - Service charge (city-specific our charge override)
 *
 * Default behaviour: no row = service not visible, no price shown.
 * This means nothing is displayed until explicitly configured.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateCityServiceConfig extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_city_service_config (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            city_id         INT UNSIGNED NOT NULL COMMENT 'FK rto_cities.id',
            service_id      INT UNSIGNED NOT NULL COMMENT 'FK rto_services.id',

            -- Service visibility: controls whether this service appears on the city page
            is_visible      TINYINT(1)    NOT NULL DEFAULT 0
                            COMMENT '1 = show service on this city page, 0 = hide',

            -- Price visibility: controls whether price is shown (independent of service visibility)
            show_price      TINYINT(1)    NOT NULL DEFAULT 0
                            COMMENT '1 = show price for this service in this city, 0 = hide price',

            -- City-specific fee overrides (NULL = use the service default)
            govt_fee        DECIMAL(10,2) NULL     DEFAULT NULL
                            COMMENT 'Government fee specific to this city (NULL = use service default)',
            service_charge  DECIMAL(10,2) NULL     DEFAULT NULL
                            COMMENT 'Our service charge for this city (NULL = use service default)',

            -- Optional display-only note for this config entry
            notes           VARCHAR(500)  NULL     DEFAULT NULL,

            -- Timestamps
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NULL     ON UPDATE CURRENT_TIMESTAMP,
            updated_by      BIGINT UNSIGNED NULL COMMENT 'WP user who last updated this config',

            -- One row per city+service combination
            UNIQUE KEY uq_city_service (city_id, service_id),
            INDEX idx_city    (city_id),
            INDEX idx_service (service_id),
            INDEX idx_visible (is_visible),
            INDEX idx_price   (show_price)
        ) {$c}");
    }

    public function down(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rto_city_service_config");
    }
}
