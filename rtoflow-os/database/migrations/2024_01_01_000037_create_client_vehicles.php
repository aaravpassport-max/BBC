<?php
/**
 * Migration 37: Saved Vehicles for Repeat Clients (rto_client_vehicles)
 *
 * ENTERPRISE GAP FIX (Phase 4, item 2 — "no saved addresses or vehicle
 * registry for repeat clients"): every new service request requires
 * re-entering vehicle/address details from scratch. This is the storage
 * for a client's own reusable vehicle list, referenced from the client
 * dashboard's "My Vehicles" screen — see Client\DashboardController.
 *
 * Scope, stated honestly: the public Apply form (resources/views/public/
 * apply.php) is a dynamic, per-service-category schema (FormEngineService),
 * not a fixed field set — auto-filling it from a saved vehicle safely
 * requires mapping this table's columns to whatever the active schema for
 * that category calls its vehicle-number/address fields, which is a
 * genuinely separate integration task from building the registry itself.
 * This migration and the CRUD screen it backs are real and independently
 * useful (a client can look up their own vehicle/address details without
 * digging through past orders) even before that follow-up wiring exists.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateClientVehicles extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_client_vehicles (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id        BIGINT UNSIGNED NOT NULL,
            label            VARCHAR(100) NULL,
            vehicle_number   VARCHAR(20) NULL,
            make_model       VARCHAR(150) NULL,
            address          TEXT NULL,
            created_at       DATETIME NOT NULL,
            KEY idx_client (client_id)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
