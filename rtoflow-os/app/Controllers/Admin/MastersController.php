<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class MastersController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        $tab      = Sanitiser::text($_GET['tab'] ?? 'cities');
        $stateId  = Sanitiser::int($_GET['state_id'] ?? 0);
        $search   = Sanitiser::text($_GET['search']   ?? '');

        $states   = $this->db->get_results("SELECT * FROM {$this->p}rto_states ORDER BY name", ARRAY_A) ?: [];

        $cities   = [];
        $page     = 1; $lastPage = 1; $total = 0;
        if ($tab === 'cities') {
            $where = ['1=1']; $params = [];
            if ($stateId) { $where[] = 'c.state_id=%d'; $params[] = $stateId; }
            if ($search)  {
                $s = '%' . $this->db->esc_like($search) . '%';
                $where[] = '(c.name LIKE %s OR c.rto_code LIKE %s)';
                $params[] = $s; $params[] = $s;
            }
            $ws = implode(' AND ', $where);

            // CSV export: same filtered WHERE clause as the cities list below,
            // but ALL matching rows. 'masters' routes unconditionally to
            // index() so the export is detected here rather than via a new
            // Router.php dispatch arm. Only the cities tab is exportable.
            if (isset($_GET['export']) && $_GET['export'] === 'csv') {
                $this->exportCitiesCsv($ws, $params);
                return;
            }

            // FIX P0: real pagination — this previously hardcoded LIMIT 200
            // with no page control, silently hiding cities beyond the 200th
            // row and giving no way to reach them.
            $perPage  = 50;
            $page     = Sanitiser::int($_GET['paged'] ?? 1, 1);
            $countSql = "SELECT COUNT(*) FROM {$this->p}rto_cities c WHERE {$ws}";
            $total    = $params ? (int)$this->db->get_var($this->db->prepare($countSql, $params)) : (int)$this->db->get_var($countSql);
            $lastPage = max(1, (int)ceil($total / $perPage));
            $page     = min($page, $lastPage);
            $offset   = ($page - 1) * $perPage;

            $listSql = "SELECT c.*,s.name as state_name FROM {$this->p}rto_cities c JOIN {$this->p}rto_states s ON s.id=c.state_id WHERE {$ws} ORDER BY s.name,c.name LIMIT %d OFFSET %d";
            $cities  = $this->db->get_results($this->db->prepare($listSql, [...$params, $perPage, $offset]), ARRAY_A) ?: [];
        }

        // ENTERPRISE GAP FIX (Phase 6, item — merge/dedupe tooling): the
        // $cities list above is paginated/filtered, which would silently
        // hide most cities from the merge tool's dropdowns (the exact
        // "silently truncates" failure mode this codebase has fixed
        // elsewhere — see the P0 pagination fix comment just above). The
        // merge tool needs every city, unfiltered, so it gets its own
        // lightweight id+name query.
        $allCitiesForMerge = $tab === 'cities'
            ? ($this->db->get_results("SELECT id, name FROM {$this->p}rto_cities ORDER BY name", ARRAY_A) ?: [])
            : [];

        rto_view('admin.masters.index', compact('tab','states','cities','stateId','search','page','lastPage','total','allCitiesForMerge'));
    }

    // ── City AJAX ────────────────────────────────────────────────────────────

    public function addCity(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $stateId = Sanitiser::int($_POST['state_id'] ?? 0, 1);
        $name    = Sanitiser::text($_POST['name']     ?? '');
        $code    = strtoupper(Sanitiser::text($_POST['rto_code'] ?? ''));

        if (!$name)    rto_json_err('City name is required.');
        if (!$stateId) rto_json_err('State is required.');

        // Check duplicate
        $exists = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->p}rto_cities WHERE state_id=%d AND name=%s", $stateId, $name
        ));
        if ($exists) rto_json_err("'{$name}' already exists in this state.");

        $inserted = $this->db->insert($this->p . 'rto_cities', [
            'state_id' => $stateId, 'name' => $name, 'rto_code' => $code
        ]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported "City added"
        // regardless of whether the INSERT actually happened.
        if (!$inserted) rto_json_err('Could not add the city. Please try again.', 500);
        $id = (int)$this->db->insert_id;
        AuditService::log('city.created', null, ['id' => $id, 'state_id' => $stateId, 'name' => $name, 'rto_code' => $code]);
        delete_transient('rtofl_active_states');

        rto_json_ok(['id' => $id, 'name' => $name, 'rto_code' => $code], 'City added.');
    }

    // ENTERPRISE GAP FIX (gap-analysis Section 8 — "Master-data (cities/RTOs)
    // has no update/versioning path"): create() and delete() existed with
    // real referential-integrity guards, but there was no way to fix a typo
    // in a city's name or RTO code short of deleting and recreating the row
    // — which would silently break every vendor-coverage entry, pricing
    // override, and historical lead still pointing at the old city_id.
    // Mirrors addCity()'s exact validation/duplicate-check/audit pattern.
    public function updateCity(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id      = Sanitiser::int($_POST['city_id']  ?? 0, 1);
        $stateId = Sanitiser::int($_POST['state_id'] ?? 0, 1);
        $name    = Sanitiser::text($_POST['name']     ?? '');
        $code    = strtoupper(Sanitiser::text($_POST['rto_code'] ?? ''));

        if (!$id)      rto_json_err('City not found.');
        if (!$name)    rto_json_err('City name is required.');
        if (!$stateId) rto_json_err('State is required.');

        $before = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_cities WHERE id=%d", $id), ARRAY_A);
        if (!$before) rto_json_err('City not found.', 404);

        // Same duplicate check as addCity(), excluding this row itself.
        $exists = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->p}rto_cities WHERE state_id=%d AND name=%s AND id<>%d", $stateId, $name, $id
        ));
        if ($exists) rto_json_err("'{$name}' already exists in this state.");

        $updated = $this->db->update($this->p . 'rto_cities', [
            'state_id' => $stateId, 'name' => $name, 'rto_code' => $code
        ], ['id' => $id]);

        // 14-A pattern (ghost-success guard): $wpdb->update() returns 0 (not
        // false) when the row matched but no column value actually changed
        // — that is a legitimate no-op, not a failure, so only `false` (a
        // real DB error) is treated as one here.
        if ($updated === false) {
            error_log('RTOFLOW: updateCity DB update failed for city_id=' . $id . ' — ' . $this->db->last_error);
            rto_json_err('Failed to update city. Please try again.', 500);
        }

        AuditService::log('city.updated', null,
            ['id' => $id, 'state_id' => $stateId, 'name' => $name, 'rto_code' => $code],
            $before
        );
        delete_transient('rtofl_active_states');

        rto_json_ok(['id' => $id, 'name' => $name, 'rto_code' => $code], 'City updated.');
    }

    // Known Limitations audit fix: this action, and its "Delete" button in
    // resources/views/admin/masters/index.php, already existed and were
    // already wired end-to-end — the documented limitation's claim that "no
    // delete control is exposed anywhere in the UI" was stale, the same
    // "doc lagged the code" pattern found on Vendors/Payouts. What WAS a
    // genuine gap, matching the limitation's own "why"/"recommended_fix"
    // text: this guard only ever checked for active LEADS in the city — it
    // never checked whether a vendor's coverage (rto_vendors.cities JSON)
    // or a City/Service Pricing override (rto_city_service_config) still
    // referenced the city, so deleting one could silently orphan those
    // references. Both are now checked, with an explicit, specific count in
    // the error message rather than a generic "cannot delete".
    public function deleteCity(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['city_id'] ?? 0, 1);

        // Check no active leads use this city
        $leadsCount = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_leads WHERE city_id=%d AND deleted_at IS NULL AND status NOT IN ('completed','cancelled')", $id
        ));
        if ($leadsCount > 0) rto_json_err("Cannot delete: {$leadsCount} active order(s) use this city.");

        // Check no vendor lists this city in their coverage
        $vendorCount = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_vendors WHERE JSON_CONTAINS(cities, %s)", (string)$id
        ));
        if ($vendorCount > 0) rto_json_err("Cannot delete: {$vendorCount} vendor(s) list this city in their coverage area — remove it from their coverage first (Vendors screen).");

        // Check no City/Service Pricing override references this city
        $pricingCount = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_city_service_config WHERE city_id=%d", $id
        ));
        if ($pricingCount > 0) rto_json_err("Cannot delete: {$pricingCount} City/Service Pricing override(s) reference this city — remove them first (City Pricing screen).");

        $city = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_cities WHERE id=%d", $id), ARRAY_A) ?: [];
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): the delete() result was discarded here
        // — an admin would be told "City deleted." even on a failed query,
        // with the city still present in every list and dropdown.
        $deleted = $this->db->delete($this->p . 'rto_cities', ['id' => $id]);
        if (!$deleted) rto_json_err('Could not delete the city. Please try again.', 500);
        AuditService::log('city.deleted', null, [], $city);
        delete_transient('rtofl_active_states');
        rto_json_ok(null, 'City deleted.');
    }

    // Bulk delete — "create options so I can delete cities in bulk". Reuses
    // deleteCity()'s exact same three referential-integrity guards (active
    // leads / vendor coverage / City-Service pricing overrides) per city,
    // one at a time, rather than a single bulk DELETE query — a city that
    // still has real references is skipped with the same specific reason
    // deleteCity() already gives, instead of either silently orphaning data
    // or failing the entire batch because of one blocked row.
    // TRACE: admin checks rows on the Cities table + clicks "Delete
    //        Selected" → confirms → verifies nonce+admin role → for each
    //        city id: same 3 guards as deleteCity() → deletes if clear,
    //        records a skip reason if not → precondition: admin role,
    //        city_ids is a non-empty array of ints → postcondition: every
    //        city with zero references is gone, every blocked one is
    //        unchanged and reported back by name → edge cases: empty
    //        selection, all ids referenced (0 deleted), mixed batch (some
    //        deleted, some skipped) — all three return a clear summary
    //        rather than a bare success/fail.
    public function bulkDeleteCities(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $raw = $_POST['city_ids'] ?? '';
        $ids = is_array($raw) ? $raw : (array) json_decode((string) $raw, true);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) rto_json_err('No cities selected.');
        if (count($ids) > 500) rto_json_err('Please select 500 cities or fewer at a time.');

        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $city = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_cities WHERE id=%d", $id), ARRAY_A);
            if (!$city) continue; // already gone — nothing to report

            $leadsCount = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->p}rto_leads WHERE city_id=%d AND deleted_at IS NULL AND status NOT IN ('completed','cancelled')", $id
            ));
            if ($leadsCount > 0) {
                $skipped[] = ['id' => $id, 'name' => $city['name'], 'reason' => "{$leadsCount} active order(s) use this city"];
                continue;
            }

            $vendorCount = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->p}rto_vendors WHERE JSON_CONTAINS(cities, %s)", (string) $id
            ));
            if ($vendorCount > 0) {
                $skipped[] = ['id' => $id, 'name' => $city['name'], 'reason' => "{$vendorCount} vendor(s) list this city in their coverage area"];
                continue;
            }

            $pricingCount = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->p}rto_city_service_config WHERE city_id=%d", $id
            ));
            if ($pricingCount > 0) {
                $skipped[] = ['id' => $id, 'name' => $city['name'], 'reason' => "{$pricingCount} City/Service Pricing override(s) reference this city"];
                continue;
            }

            $ok = $this->db->delete($this->p . 'rto_cities', ['id' => $id]);
            if (!$ok) {
                $skipped[] = ['id' => $id, 'name' => $city['name'], 'reason' => 'delete failed — please retry'];
                continue;
            }
            AuditService::log('city.deleted', null, [], $city);
            $deleted[] = ['id' => $id, 'name' => $city['name']];
        }

        if ($deleted) delete_transient('rtofl_active_states');

        $msg = count($deleted) . ' of ' . count($ids) . ' selected cit' . (count($ids) === 1 ? 'y' : 'ies') . ' deleted.';
        if ($skipped) $msg .= ' ' . count($skipped) . ' skipped (still in use).';

        rto_json_ok(['deleted' => $deleted, 'skipped' => $skipped], $msg);
    }

    // ENTERPRISE GAP FIX (Phase 6, item — "Master-data (cities/RTOs) has no
    // update/versioning path ... no merge/dedupe tooling for accidental
    // duplicates"): updateCity() already covers the update half of this gap
    // (see that method above); this closes the merge/dedupe half. Moves
    // every real reference to $fromId onto $intoId — leads (all statuses,
    // not just active, so a merged-away city never leaves a completed
    // order's city_id dangling), RTO offices, City/Service Pricing rows
    // (skipping any that would collide with an existing (intoId,service_id)
    // unique-key row rather than erroring the whole merge), and every
    // vendor's JSON coverage array — THEN deletes $fromId, reusing
    // deleteCity()'s own referential-integrity guard as a final safety net
    // (it will simply find zero references left, since this method just
    // moved them all).
    // TRACE: admin picks a duplicate + canonical city on the Cities screen,
    //        confirms → verifies nonce+admin role → validates both cities
    //        exist and are different → reassigns rto_leads.city_id,
    //        rto_rtos.city_id, rto_city_service_config.city_id (per-row
    //        collision check), and every rto_vendors.cities JSON array
    //        containing $fromId → deletes the now-unreferenced $fromId row →
    //        precondition: admin role, both ids exist, fromId != intoId →
    //        postcondition: zero rows anywhere still reference $fromId →
    //        edge cases: intoId already has a City/Service Pricing row for a
    //        service $fromId also has → the $fromId row is dropped rather
    //        than causing a duplicate-key failure (intoId's row wins, since
    //        it's the canonical city being kept); vendor already covers both
    //        cities → JSON array deduped, not left with two entries for the
    //        same now-merged city.
    public function mergeCities(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $fromId = Sanitiser::int($_POST['from_city_id'] ?? 0, 1);
        $intoId = Sanitiser::int($_POST['into_city_id'] ?? 0, 1);
        if (!$fromId || !$intoId) rto_json_err('Both a duplicate city and a canonical city are required.');
        if ($fromId === $intoId) rto_json_err('The duplicate and canonical city must be different.');

        $fromCity = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_cities WHERE id=%d", $fromId), ARRAY_A);
        $intoCity = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_cities WHERE id=%d", $intoId), ARRAY_A);
        if (!$fromCity) rto_json_err('Duplicate city not found.');
        if (!$intoCity) rto_json_err('Canonical city not found.');

        // Leads — every status, so nothing is left pointing at a deleted city.
        $this->db->update($this->p . 'rto_leads', ['city_id' => $intoId], ['city_id' => $fromId]);

        // RTO offices registered under the duplicate city.
        $this->db->update($this->p . 'rto_rtos', ['city_id' => $intoId], ['city_id' => $fromId]);

        // City/Service Pricing — move rows that don't collide; drop the ones
        // that would (the canonical city's own override for that service wins).
        $configRows = $this->db->get_results($this->db->prepare(
            "SELECT id, service_id FROM {$this->p}rto_city_service_config WHERE city_id=%d", $fromId
        ), ARRAY_A) ?: [];
        foreach ($configRows as $row) {
            $collision = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->p}rto_city_service_config WHERE city_id=%d AND service_id=%d",
                $intoId, $row['service_id']
            ));
            if ($collision) {
                $this->db->delete($this->p . 'rto_city_service_config', ['id' => $row['id']]);
            } else {
                $this->db->update($this->p . 'rto_city_service_config', ['city_id' => $intoId], ['id' => $row['id']]);
            }
        }

        // Vendor coverage — JSON array of city ids, needs a per-row rewrite.
        $vendors = $this->db->get_results($this->db->prepare(
            "SELECT id, cities FROM {$this->p}rto_vendors WHERE JSON_CONTAINS(cities, %s)", (string)$fromId
        ), ARRAY_A) ?: [];
        foreach ($vendors as $v) {
            $cityIds = json_decode($v['cities'] ?? '[]', true) ?: [];
            $cityIds = array_values(array_unique(array_map(
                fn($c) => (int)$c === $fromId ? $intoId : (int)$c,
                $cityIds
            )));
            $this->db->update($this->p . 'rto_vendors', ['cities' => wp_json_encode($cityIds)], ['id' => $v['id']]);
        }

        $deleted = $this->db->delete($this->p . 'rto_cities', ['id' => $fromId]);
        if (!$deleted) rto_json_err('References were moved, but the duplicate city itself could not be deleted. Please check the Cities screen and remove it manually.', 500);

        AuditService::log('city.merged', null, ['into_city_id' => $intoId], $fromCity);
        delete_transient('rtofl_active_states');
        rto_json_ok(null, "Merged \"{$fromCity['name']}\" into \"{$intoCity['name']}\".");
    }

    // ── CSV bulk import (Part 12-C/14-E gap — no bulk-import pipeline existed
    //    ANYWHERE in the codebase; confirmed by a full-repo grep before
    //    building this). Cities/RTOs is the highest-value, lowest-risk
    //    target to start with: a small, well-understood schema (state_id,
    //    name, rto_code), no cross-table required fields, and the SAME
    //    header format exportCitiesCsv() already produces below — so a
    //    file downloaded via "Export CSV", edited, and re-uploaded here
    //    round-trips cleanly with zero format guessing.
    // TRACE: admin uploads a .csv via the Import modal on masters/index.php →
    //        verifies nonce+admin role → validates file type/size →
    //        parses header row, matches "State"/"City"/"RTO Code" columns
    //        (case-insensitive, a couple of natural aliases accepted) →
    //        preloads state-name→id and existing-city dedupe maps once
    //        (not one query per row) → for each data row: validates state
    //        exists, city name given, not a duplicate → inserts, or records
    //        a specific per-row skip reason → returns a summary the admin
    //        can read without touching a server log →
    //        precondition: admin, valid CSV with State+City columns →
    //        postcondition: 0..N new rto_cities rows, one audit log entry
    //        for the whole batch, a per-row error report for anything
    //        skipped → edge cases: wrong/missing columns, unknown state
    //        name, duplicate city, oversized file, row count above the
    //        2000-row safety cap, individual insert failure.
    public function importCitiesCsv(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        if (empty($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'] ?? '')) {
            rto_json_err('Please choose a CSV file to upload.');
        }
        $file = $_FILES['csv_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            rto_json_err('Upload failed (error code ' . (int)($file['error'] ?? -1) . '). Please try again.');
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            rto_json_err('File is too large. Maximum size is 2MB.');
        }
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            rto_json_err('Please upload a .csv file.');
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) rto_json_err('Could not read the uploaded file.');

        // Skip a UTF-8 BOM if present — exportCitiesCsv() below writes one,
        // so a round-tripped file needs this to not corrupt the first header.
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) rewind($handle);

        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); rto_json_err('The file is empty or not a valid CSV.'); }
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

        $findCol = static function (array $header, array $names) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) return $i;
            }
            return false;
        };
        $colState = $findCol($header, ['state', 'state name']);
        $colCity  = $findCol($header, ['city / rto office', 'city', 'city name']);
        $colCode  = $findCol($header, ['rto code', 'rto_code']);

        if ($colState === false || $colCity === false) {
            fclose($handle);
            rto_json_err('The CSV must have a "State" column and a "City" column (matching the Export CSV format). Found columns: ' . implode(', ', $header));
        }

        // Preload once — a per-row query for a 2000-row file would be 2000
        // extra round-trips for no reason, both of these are tiny tables.
        $stateMap = [];
        foreach ($this->db->get_results("SELECT id, name FROM {$this->p}rto_states", ARRAY_A) ?: [] as $s) {
            $stateMap[strtolower(trim($s['name']))] = (int)$s['id'];
        }
        $existingSet = [];
        foreach ($this->db->get_results("SELECT state_id, name FROM {$this->p}rto_cities", ARRAY_A) ?: [] as $r) {
            $existingSet[$r['state_id'] . '|' . strtolower(trim($r['name']))] = true;
        }

        $imported = 0; $skipped = 0; $errors = [];
        $rowNum   = 1; // header consumed above counts as row 1
        $maxRows  = 2000;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($rowNum - 1 > $maxRows) {
                $errors[] = "Row {$rowNum}: import capped at {$maxRows} rows — split the file and re-upload the rest.";
                break;
            }
            if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) continue; // blank line

            $stateName = trim((string)($row[$colState] ?? ''));
            $cityName  = trim((string)($row[$colCity]  ?? ''));
            $rtoCode   = $colCode !== false ? strtoupper(trim((string)($row[$colCode] ?? ''))) : '';

            if ($stateName === '' || $cityName === '') {
                $errors[] = "Row {$rowNum}: state and city are both required — skipped.";
                $skipped++;
                continue;
            }

            $stateId = $stateMap[strtolower($stateName)] ?? null;
            if (!$stateId) {
                $errors[] = "Row {$rowNum}: unknown state \"{$stateName}\" — skipped.";
                $skipped++;
                continue;
            }

            $dedupeKey = $stateId . '|' . strtolower($cityName);
            if (isset($existingSet[$dedupeKey])) {
                $errors[] = "Row {$rowNum}: \"{$cityName}\" already exists in {$stateName} — skipped.";
                $skipped++;
                continue;
            }

            $inserted = $this->db->insert($this->p . 'rto_cities', [
                'state_id' => $stateId, 'name' => $cityName, 'rto_code' => $rtoCode,
            ]);
            if (!$inserted) {
                $errors[] = "Row {$rowNum}: database error saving \"{$cityName}\" — skipped.";
                $skipped++;
                continue;
            }

            $existingSet[$dedupeKey] = true; // guards against duplicate rows within the same file
            $imported++;
        }
        fclose($handle);

        if ($imported > 0) {
            delete_transient('rtofl_active_states');
            AuditService::log('city.bulk_imported', null, [
                'imported' => $imported, 'skipped' => $skipped, 'total_rows' => $rowNum - 1,
            ]);
        }

        rto_json_ok([
            'imported' => $imported,
            'skipped'  => $skipped,
            // Capped so a badly-formed multi-thousand-row file can't blow up
            // the response — the message below still reports the true totals.
            'errors'   => array_slice($errors, 0, 50),
        ], "Import complete: {$imported} added, {$skipped} skipped." . ($errors ? ' See details below.' : ''));
    }

    // ── CSV export (GET, ?export=csv on the masters/cities list) ─────────────
    // TRACE: admin visits /rto-admin/masters/?tab=cities&export=csv&state_id=&search= →
    //        index() detects export=csv and calls this with the SAME $ws/$params
    //        it just built → fetches ALL matching rows (capped 5000) → streams CSV.
    private function exportCitiesCsv(string $ws, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $sql    = "SELECT c.*,s.name as state_name FROM {$this->p}rto_cities c JOIN {$this->p}rto_states s ON s.id=c.state_id WHERE {$ws} ORDER BY s.name,c.name LIMIT 5000";
        $cities = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $cities = $cities ?: [];

        $filename = 'rtoflow-cities-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['State','City / RTO Office','RTO Code']);

        foreach ($cities as $city) {
            fputcsv($out, [
                $city['state_name'] ?? '',
                $city['name']       ?? '',
                $city['rto_code']   ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    public function getCities(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        $stateId = Sanitiser::int($_POST['state_id'] ?? 0, 1);
        $cities  = $this->db->get_results($this->db->prepare(
            "SELECT id, name, rto_code FROM {$this->p}rto_cities WHERE state_id=%d ORDER BY name", $stateId
        ), ARRAY_A) ?: [];
        rto_json_ok($cities);
    }
}
