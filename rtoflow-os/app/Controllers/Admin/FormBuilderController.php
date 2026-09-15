<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\FormEngineService;
use RTOFLOW\Services\FormFunnelService;
use RTOFLOW\Database\Seeds\RealFormSchemaSeeder;

if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for the Dynamic Form Engine (see FormEngineService).
 *
 * PART 4.10 RE-ARCHITECTURE: this controller used to manage ONE schema PER
 * REAL SERVICE (44 rows) — see git history / gap-fix-and-scalability-plan.md
 * Parts 4.5-4.9. After being shown a reference plugin's "one form, many
 * services inside it" pattern, this was rebuilt around the real unit the
 * user wants to edit: a CATEGORY (Driving License, RC Services,
 * HP/Hypothecation, NOC, Vehicle Services, Commercial Vehicle, Other
 * Services — the same 7 categories apply.php's own step-1 buttons use).
 * Every method below now takes a category KEY string ('dl','rc','hp','noc',
 * 'vehicle','commercial','other') instead of a service_id int. See
 * RealFormSchemaSeeder::categoryMap() for the canonical key => title/
 * services list this controller reads from.
 */
class FormBuilderController
{
    private \wpdb $db;
    private string $p;

    public function __construct(private FormEngineService $forms, private ?FormFunnelService $funnel = null)
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p = $wpdb->prefix;
        // Nullable + lazily defaulted (rather than a hard constructor
        // requirement) so this controller keeps working if resolved through
        // any older/partial container wiring that hasn't been updated to
        // pass a second argument — matches the defensive pattern already
        // used for VendorService's own optional coverage-repository param.
        $this->funnel ??= new FormFunnelService();
    }

    private function categoryMap(): array
    {
        return RealFormSchemaSeeder::categoryMap();
    }

    /** List all 7 real categories, and whether each already has a custom form schema. */
    public function index(): void
    {
        $categories = [];
        foreach ($this->categoryMap() as $key => $cat) {
            $row = $this->db->get_row($this->db->prepare(
                "SELECT id, name, version, updated_at FROM {$this->p}rto_form_schemas WHERE category=%s AND is_active=1 LIMIT 1",
                $key
            ), ARRAY_A);

            // Part 4.14: "is there a custom FORM?" (above) is a completely
            // separate question from "can a customer even PICK this category's
            // services on the live Apply page at all?" — the latter is gated
            // by rto_services.is_active, the exact same column every
            // front-end query (routeApply's service list, routeService(),
            // hasDynamicForm, the /rto-apply-form/ page) already filters on.
            // Read the real, current DB state here rather than tracking a
            // second parallel "category enabled" flag anywhere — with one
            // flag per service already wired into every renderer, a second
            // category-level flag that ALSO had to be checked everywhere
            // would be exactly the kind of two-sources-of-truth bug this
            // codebase has been burned by before (see Part 4.11/4.12).
            $names = $cat['services'];
            $placeholders = implode(',', array_fill(0, count($names), '%s'));
            $activeCount = $names
                ? (int)$this->db->get_var($this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->p}rto_services WHERE name IN ({$placeholders}) AND is_active=1", $names
                  ))
                : 0;

            $categories[] = [
                'key'              => $key,
                'title'            => $cat['title'],
                'service_count'    => count($cat['services']),
                'services'         => $cat['services'],
                'active_count'     => $activeCount,
                'is_visible'       => $activeCount > 0,
                'schema_id'        => $row['id'] ?? null,
                'schema_name'      => $row['name'] ?? null,
                'schema_version'   => $row['version'] ?? null,
                'schema_updated_at' => $row['updated_at'] ?? null,
            ];
        }

        rto_view('admin.forms.index', compact('categories'));
    }

    // ── AJAX: hide/show an ENTIRE category's real services from the live
    // Apply page in one click — this is a real gap Part 4.10-4.13's `toggle`/
    // `delete` methods never covered, since those only ever touched which
    // custom FORM SCHEMA is active for a category, never whether the
    // category's underlying services can be picked by a customer at all.
    // Implemented as a bulk write to rto_services.is_active — the exact
    // column every existing public-facing query already filters on (see
    // Router.php's routeApply()/routeService()/hasDynamicForm and
    // ServicesController's own per-row toggle) — rather than inventing a new
    // "category enabled" flag that every one of those call sites would then
    // ALSO need to check to actually take effect. ─────────────────────────
    public function toggleCategoryVisibility(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        $map = $this->categoryMap();
        if ($category === '' || !isset($map[$category])) rto_json_err('An unknown category was submitted.');

        $names = $map[$category]['services'];
        if (!$names) rto_json_err('This category has no services to toggle.');
        $placeholders = implode(',', array_fill(0, count($names), '%s'));

        $activeCount = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_services WHERE name IN ({$placeholders}) AND is_active=1", $names
        ));
        // Toggle on current state: any active service in the category means
        // customers can currently reach it, so the action is "hide the whole
        // category"; none active means the action is "show it again."
        $newState = $activeCount > 0 ? 0 : 1;

        $updated = $this->db->query($this->db->prepare(
            "UPDATE {$this->p}rto_services SET is_active=%d WHERE name IN ({$placeholders})",
            array_merge([$newState], $names)
        ));
        if ($updated === false) rto_json_err('Database update failed — no services were changed.', 500);

        // Same cache-invalidation ServicesController's own per-row toggle
        // already performs after writing rto_services — skipping this would
        // mean a hidden category could still appear to customers from a
        // stale cached list for as long as that transient's TTL.
        delete_transient('rtofl_active_services');

        \RTOFLOW\Services\AuditService::log('service_category.visibility_toggled', 0, [
            'category' => $category, 'is_active' => $newState, 'services_affected' => count($names),
        ]);

        rto_json_ok(
            ['is_active' => $newState, 'active_count' => $newState ? count($names) : 0, 'service_count' => count($names)],
            $newState
                ? 'This category is visible again — customers can pick its services on the Apply page.'
                : 'This category is now hidden — customers can no longer pick any of its services on the Apply page. Existing leads/submissions for it are untouched.'
        );
    }

    /** Visual field-list editor for one category's form. */
    public function edit(string $category): void
    {
        $map = $this->categoryMap();
        if (!isset($map[$category])) {
            wp_die('Unknown form category.');
        }
        $cat = $map[$category];

        // Same reasoning as the old getForServiceForAdmin(): admin editing
        // must never depend on the form_builder flag, which only gates the
        // public-facing renderer — an admin building or revising a form is
        // a purely internal action.
        $schema = $this->forms->getForCategoryForAdmin($category);
        $steps = $schema['steps'] ?? [];
        $documents = $schema['documents'] ?? [];
        $schemaName = $schema['name'] ?? ($cat['title'] . ' Inquiry Form');
        $history = $this->forms->historyForCategory($category);
        $sources = \RTOFLOW\Support\DependentSourceRegistry::registeredNames();
        $docTypes = $this->db->get_results(
            "SELECT id, name, category FROM {$this->p}rto_doc_types WHERE is_active=1 ORDER BY category, name", ARRAY_A
        ) ?: [];

        // The category's real service list — passed to the view so it can
        // show "this form covers these N real services" and can pre-fill a
        // brand-new form's 'selected_service' picker field automatically,
        // rather than an admin having to type all N service names by hand.
        $categoryTitle = $cat['title'];
        $categoryServices = $cat['services'];

        rto_view('admin.forms.edit', compact(
            'category', 'categoryTitle', 'categoryServices', 'schema', 'steps', 'documents',
            'schemaName', 'history', 'sources', 'docTypes'
        ));
    }

    // ── AJAX: save (create new version) for a category. Accepts the v2
    // {steps:[...], documents:[...]} schema shape — see
    // FormEngineService::normaliseSchema() for full validation rules. ─────
    public function save(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        $name     = Sanitiser::text($_POST['name'] ?? '', 200);
        $note     = Sanitiser::text($_POST['version_note'] ?? '', 500);

        $schemaRaw = $_POST['schema'] ?? '[]';
        $schema    = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;

        if ($category === '' || !isset($this->categoryMap()[$category])) rto_json_err('An unknown category was submitted.');
        if ($name === '') rto_json_err('A form name is required.');
        if (!is_array($schema)) rto_json_err('Form schema is malformed JSON.');

        try {
            $schemaId = $this->forms->saveForCategory($category, $name, $schema, get_current_user_id(), $note);
        } catch (\InvalidArgumentException $e) {
            rto_json_err($e->getMessage());
            return;
        } catch (\RuntimeException $e) {
            // Common-mistakes audit fix: saveForCategory() now throws
            // RuntimeException (not InvalidArgumentException) when the
            // old-version deactivation write itself fails — catch it here
            // too so that failure surfaces as a clean JSON error instead of
            // an uncaught exception / fatal error reaching the browser.
            error_log('[RTOFLOW] FormBuilderController::save() failed: ' . $e->getMessage());
            rto_json_err('Could not save the form. Please try again.', 500);
            return;
        }

        rto_json_ok(['schema_id' => $schemaId], 'Form saved as a new version.');
    }

    // ── AJAX: version history for a category ─────────────────────────────────
    public function history(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        $category = Sanitiser::text($_GET['category'] ?? $_POST['category'] ?? '', 100);
        if ($category === '') rto_json_err('A category is required.');
        rto_json_ok(['history' => $this->forms->historyForCategory($category)]);
    }

    // ── AJAX: roll a category's form back to an older saved version ─────────
    public function restore(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        $schemaId = Sanitiser::int($_POST['schema_id'] ?? 0, 1);
        if ($category === '' || !$schemaId) rto_json_err('Category and schema are both required.');

        // Common-mistakes audit fix: restoreVersionForCategory() delegates
        // to saveForCategory(), which can now throw RuntimeException if the
        // old-version deactivation write fails — catch it here too, same as
        // save() above, so it surfaces as a clean JSON error instead of an
        // uncaught exception reaching the browser.
        try {
            $newId = $this->forms->restoreVersionForCategory($category, $schemaId, get_current_user_id());
        } catch (\RuntimeException $e) {
            error_log('[RTOFLOW] FormBuilderController::restore() failed: ' . $e->getMessage());
            rto_json_err('Could not restore this version. Please try again.', 500);
            return;
        }
        if ($newId === null) rto_json_err('That version could not be found.', 404);

        rto_json_ok(['schema_id' => $newId], 'Restored as a new version.');
    }

    // ── AJAX: soft-delete every version of a category's form (falls back to
    // the static apply form for every service in that category; rows are
    // never hard-deleted, preserving audit/version history).
    public function delete(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        if ($category === '') rto_json_err('A category is required.');

        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this update()'s result was previously
        // discarded — an admin removing a custom form would be told "This
        // category now uses the default apply form fields" even if the
        // write failed and every version was in fact still active.
        // update() returning 0 here is not itself an error (the category
        // may already have had no active version, e.g. a double-click), so
        // only a hard false (query error) is treated as failure.
        $result = $this->db->update($this->p . 'rto_form_schemas', ['is_active' => 0], ['category' => $category]);
        if ($result === false) rto_json_err('Could not remove the custom form. Please try again.', 500);
        \RTOFLOW\Services\AuditService::log('form_schema.deactivated_all', 0, ['category' => $category]);
        rto_json_ok([], 'Custom form removed. This category now uses the default apply form fields.');
    }

    // ── AJAX: delete ONE specific saved version from a category's history
    // (distinct from delete() above, which deactivates every version of the
    // category at once). See FormEngineService::deleteVersionForCategory()
    // for the guard against deleting the currently-active version.
    public function deleteVersion(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        $schemaId = Sanitiser::int($_POST['schema_id'] ?? 0, 1);
        if ($category === '' || !$schemaId) rto_json_err('Category and schema are both required.');

        $result = $this->forms->deleteVersionForCategory($category, $schemaId);
        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ── Export the current admin-visible schema for a category as a
    // downloadable JSON file. ────────────────────────────────────────────────
    public function export(string $category): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        $schema = $this->forms->getForCategoryForAdmin($category);
        if (!$schema) wp_die('This category has no custom form to export.', 404);

        $payload = [
            'rtoflow_form_export' => 1,
            'exported_at' => current_time('mysql'),
            'category'    => $category,
            'name'        => $schema['name'],
            'meta'        => $schema['meta'],
            'steps'       => $schema['steps'],
            'documents'   => $schema['documents'],
        ];
        $filename = 'form-' . $category . '-v' . (int)($schema['version'] ?? 1) . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT);
        exit;
    }

    // ── AJAX: import a previously-exported (or hand-written) schema JSON as
    // a new version for a category. Same save() pipeline as the builder UI.
    public function import(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $category = Sanitiser::text($_POST['category'] ?? '', 100);
        if ($category === '' || !isset($this->categoryMap()[$category])) rto_json_err('An unknown category was submitted.');

        $raw = '';
        if (!empty($_FILES['import_file']['tmp_name']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            if (($_FILES['import_file']['size'] ?? 0) > 2 * 1024 * 1024) rto_json_err('Import file is too large (max 2MB).');
            $raw = (string)file_get_contents($_FILES['import_file']['tmp_name']);
        } elseif (!empty($_POST['import_json'])) {
            $raw = (string)$_POST['import_json'];
        }
        if ($raw === '') rto_json_err('No import file or JSON was provided.');

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) rto_json_err('That file is not valid JSON.');

        $name = Sanitiser::text($decoded['name'] ?? 'Imported Form', 200);
        $schema = [
            'meta'      => $decoded['meta'] ?? ['title' => $name],
            'steps'     => $decoded['steps'] ?? [],
            'documents' => $decoded['documents'] ?? [],
        ];

        try {
            $schemaId = $this->forms->saveForCategory($category, $name, $schema, get_current_user_id(), 'Imported from file');
        } catch (\InvalidArgumentException $e) {
            rto_json_err('Import failed validation: ' . $e->getMessage());
            return;
        }

        rto_json_ok(['schema_id' => $schemaId], 'Imported as a new version. Review it before activating.');
    }

    // ── Lightweight submission analytics for a category's dynamic form,
    // summed across every real service in that category (since one schema
    // now serves all of them). Same honest scope limit as before: counts
    // only, no funnel/abandonment tracking. ──────────────────────────────
    public function analytics(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        $category = Sanitiser::text($_GET['category'] ?? $_POST['category'] ?? '', 100);
        if ($category === '' || !isset($this->categoryMap()[$category])) rto_json_err('An unknown category was submitted.');

        $serviceNames = $this->categoryMap()[$category]['services'];
        $placeholders = implode(',', array_fill(0, count($serviceNames), '%s'));

        $total = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_form_submissions fs
             JOIN {$this->p}rto_leads l ON l.id = fs.lead_id
             JOIN {$this->p}rto_services s ON s.id = l.service_id
             WHERE s.name IN ({$placeholders})", $serviceNames
        ));
        $last30 = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_form_submissions fs
             JOIN {$this->p}rto_leads l ON l.id = fs.lead_id
             JOIN {$this->p}rto_services s ON s.id = l.service_id
             WHERE s.name IN ({$placeholders}) AND fs.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $serviceNames
        ));
        $byDay = $this->db->get_results($this->db->prepare(
            "SELECT DATE(fs.created_at) AS day, COUNT(*) AS count
             FROM {$this->p}rto_form_submissions fs
             JOIN {$this->p}rto_leads l ON l.id = fs.lead_id
             JOIN {$this->p}rto_services s ON s.id = l.service_id
             WHERE s.name IN ({$placeholders}) AND fs.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(fs.created_at) ORDER BY day ASC", $serviceNames
        ), ARRAY_A) ?: [];

        // FIX (Part 4.12 — real gap closed, not just documented): now that
        // one schema covers every service in the category, "how many
        // submissions did THIS category's form get" was never broken down
        // by WHICH of its services customers actually picked — meaningful
        // because a category form that works fine for one service could
        // still be broken/confusing for another and this was previously
        // invisible. rto_form_submissions.sub_service already stores the
        // exact real service name at submission time (see
        // submitApplyDynamic() in Router.php) — no new instrumentation
        // needed, just a query that hadn't been written yet.
        $byService = $this->db->get_results($this->db->prepare(
            "SELECT fs.sub_service AS service_name, COUNT(*) AS count
             FROM {$this->p}rto_form_submissions fs
             WHERE fs.sub_service IN ({$placeholders})
             GROUP BY fs.sub_service ORDER BY count DESC", $serviceNames
        ), ARRAY_A) ?: [];
        // Services with zero submissions so far don't appear in a GROUP BY
        // result at all — fill them in at 0 explicitly so the admin sees
        // "this service has never been submitted through this form" rather
        // than that service silently missing from the list, which reads as
        // "no data" rather than the more useful "zero, specifically."
        $seen = array_column($byService, 'count', 'service_name');
        foreach ($serviceNames as $name) {
            if (!array_key_exists($name, $seen)) $byService[] = ['service_name' => $name, 'count' => 0];
        }
        usort($byService, fn($a, $b) => (int)$b['count'] <=> (int)$a['count']);

        // FIX (checklist gap closed this pass — see FormFunnelService):
        // abandonment/drop-off/validation-failure rates were previously
        // reported as fundamentally impossible, since nothing recorded a
        // visitor reaching a step without finishing or hitting a field
        // validation error. rto_form_funnel_events + client-side beacons in
        // apply.php now supply that signal; this reports real, computed
        // figures once beacon data exists, and clear nulls/empty arrays
        // (never a misleading 0% or 100%) for a category with none yet.
        $abandonment = $this->funnel->getAbandonmentSummary($category, $serviceNames);
        $dropOff     = $this->funnel->getDropOffByStep($category, $serviceNames);
        $fieldFailures = $this->funnel->getValidationFailuresByField($category, $serviceNames);

        $note = $abandonment['step_reached'] > 0
            ? 'Submission counts summed across every real service in this category. Funnel metrics (abandonment, drop-off by step, per-field validation failures) are computed from real client-side beacon events.'
            : 'Submission counts summed across every real service in this category. Funnel metrics (abandonment, drop-off by step, per-field validation failures) require client-side beacon events, which have not been recorded yet for this category — figures below will populate as real visitors use the live form.';

        rto_json_ok([
            'total_submissions'          => $total,
            'submissions_last_30d'       => $last30,
            'by_day_last_14d'            => $byDay,
            'by_service'                 => $byService,
            'funnel_step_reached'        => $abandonment['step_reached'],
            'funnel_submitted'           => $abandonment['submitted'],
            'abandonment_rate_pct'       => $abandonment['abandonment_rate'],
            'drop_off_by_step'           => $dropOff,
            'validation_failures_by_field' => $fieldFailures,
            'note' => $note,
        ]);
    }

    // ── AJAX: toggle active/inactive ─────────────────────────────────────────
    public function toggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['schema_id'] ?? 0, 1);
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, is_active, category FROM {$this->p}rto_form_schemas WHERE id=%d", $id
        ), ARRAY_A);
        if (!$row) rto_json_err('Form schema not found.', 404);

        $newState = $row['is_active'] ? 0 : 1;
        if ($newState) {
            // Deactivating siblings first is a housekeeping side-write, not
            // the primary action this endpoint promises — its own failure
            // does not need to block the toggle below.
            $this->db->update($this->p . 'rto_form_schemas', ['is_active' => 0], ['category' => $row['category']]);
        }
        $updated = $this->db->update($this->p . 'rto_form_schemas', ['is_active' => $newState], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the form. Please try again.', 500);

        \RTOFLOW\Services\AuditService::log('form_schema.toggled', 0, ['schema_id' => $id, 'is_active' => $newState]);
        rto_json_ok(['is_active' => $newState], $newState ? 'Form enabled.' : 'Form disabled.');
    }
}
