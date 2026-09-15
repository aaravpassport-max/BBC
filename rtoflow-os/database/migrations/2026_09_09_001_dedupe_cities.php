<?php
/**
 * Migration: De-duplicate rto_cities + prevent future duplicates
 *
 * ROOT CAUSE (user report: "remove the duplicate cities from the plugin"):
 * rto_cities was created with NO unique constraint on (state_id, name) —
 * only non-unique indexes on state_id, slug and is_active (see
 * database/migrations/2024_01_01_000001_create_core_tables.php). Three
 * separate seeders each insert their own city list into this same table
 * (IndiaDataSeeder::seedCities(), database/seeds/Seeder.php::cities(), and
 * CitySeeder.php's 300+ city catalog), all using "INSERT IGNORE" under the
 * belief that would make re-running them safe — but INSERT IGNORE only
 * skips a row when it collides with an existing UNIQUE/PRIMARY key. With no
 * such key on (state_id, name), it never actually skipped anything: the
 * same city (e.g. "Pune", Maharashtra) could be — and was — inserted as
 * multiple distinct rows with different ids across activations/updates.
 *
 * This migration is a genuine, permanent fix, not a one-off cleanup script:
 * 1. For every (state_id, LOWER(TRIM(name))) group with more than one row,
 *    keep exactly one (the lowest id — i.e. the original), move every real
 *    reference to every OTHER row in that group onto the kept row (leads,
 *    RTO offices, City/Service Pricing overrides, vendor coverage JSON —
 *    the exact same reference set MastersController::mergeCities() already
 *    moves for a manual single-pair merge), then delete the now-unreferenced
 *    duplicate rows.
 * 2. Adds a real UNIQUE KEY on (state_id, name), so the underlying bug
 *    (no constraint for INSERT IGNORE to key off) can never recreate this
 *    same mess on a future activation, update, or re-seed.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class DedupeCities extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        if (!$this->tableExists('rto_cities')) return;

        // Group existing rows by (state_id, normalised name).
        $rows = $wpdb->get_results(
            "SELECT id, state_id, name FROM {$p}rto_cities ORDER BY state_id, LOWER(TRIM(name)), id",
            ARRAY_A
        ) ?: [];

        $groups = [];
        foreach ($rows as $r) {
            $key = $r['state_id'] . '|' . mb_strtolower(trim($r['name']));
            $groups[$key][] = $r;
        }

        foreach ($groups as $group) {
            if (count($group) < 2) continue; // no duplicate for this city

            $keepId = (int) $group[0]['id']; // lowest id = the original row
            $dupIds = array_map(fn($r) => (int) $r['id'], array_slice($group, 1));

            foreach ($dupIds as $dupId) {
                // Leads — every status, so nothing is left pointing at a row
                // that's about to be deleted.
                $wpdb->update($p . 'rto_leads', ['city_id' => $keepId], ['city_id' => $dupId]);

                // RTO offices registered under the duplicate row.
                $wpdb->update($p . 'rto_rtos', ['city_id' => $keepId], ['city_id' => $dupId]);

                // City/Service Pricing overrides — move rows that don't
                // collide with one the kept city already has; drop the ones
                // that would (the kept row's own override wins).
                $configRows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, service_id FROM {$p}rto_city_service_config WHERE city_id=%d", $dupId
                ), ARRAY_A) ?: [];
                foreach ($configRows as $row) {
                    $collision = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$p}rto_city_service_config WHERE city_id=%d AND service_id=%d",
                        $keepId, $row['service_id']
                    ));
                    if ($collision) {
                        $wpdb->delete($p . 'rto_city_service_config', ['id' => $row['id']]);
                    } else {
                        $wpdb->update($p . 'rto_city_service_config', ['city_id' => $keepId], ['id' => $row['id']]);
                    }
                }

                // Vendor coverage — JSON array of city ids, needs a per-row
                // rewrite (same technique as MastersController::mergeCities()).
                if ($this->tableExists('rto_vendors')) {
                    $vendors = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, cities FROM {$p}rto_vendors WHERE JSON_CONTAINS(cities, %s)", (string) $dupId
                    ), ARRAY_A) ?: [];
                    foreach ($vendors as $v) {
                        $cityIds = json_decode($v['cities'] ?? '[]', true) ?: [];
                        $cityIds = array_values(array_unique(array_map(
                            fn($c) => (int) $c === $dupId ? $keepId : (int) $c,
                            $cityIds
                        )));
                        $wpdb->update($p . 'rto_vendors', ['cities' => wp_json_encode($cityIds)], ['id' => $v['id']]);
                    }
                }

                $wpdb->delete($p . 'rto_cities', ['id' => $dupId]);
            }
        }

        // Now that duplicates are gone, a UNIQUE key on (state_id, name) is
        // guaranteed not to collide with anything already in the table —
        // add it so INSERT IGNORE in every seeder finally does what its own
        // comments already claimed it did, and this can't happen again.
        $this->addIndex('rto_cities', 'uq_state_name', 'state_id, name', true);

        delete_transient('rtofl_active_states');
    }

    public function down(): void
    {
        $this->dropIndex('rto_cities', 'uq_state_name');
        // Deliberate no-op otherwise: deleted duplicate rows are not
        // reconstructable (and shouldn't be — they were the bug).
    }
}
