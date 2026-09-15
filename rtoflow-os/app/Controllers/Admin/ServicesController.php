<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class ServicesController
{
    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $category = Sanitiser::text($_GET['category'] ?? '');
        $search   = Sanitiser::text($_GET['search']   ?? '');
        $where    = ['1=1'];
        $params   = [];

        if ($category) { $where[] = 'category=%s'; $params[] = $category; }
        if ($search)   {
            $where[] = '(name LIKE %s OR description LIKE %s)';
            $s = '%' . $this->db->esc_like($search) . '%';
            $params[] = $s; $params[] = $s;
        }

        // FIX P0: real pagination — this previously loaded the entire
        // rto_services table on every request regardless of table size.
        // Same page-size/paged-param convention as LeadsController::index().
        $whereStr = implode(' AND ', $where);

        // CSV export: reuses the exact WHERE clause/params built above so the
        // export always matches the filters currently applied on screen, but
        // pulls ALL matching rows rather than one page. Detected here (rather
        // than via a dedicated Router.php dispatch arm, as LeadsController's
        // export uses) because 'services' routes unconditionally to index().
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportCsv($whereStr, $params);
            return;
        }

        $perPage  = 25;
        $page     = Sanitiser::int($_GET['paged'] ?? 1, 1);

        $total = $params
            ? (int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->p}rto_services WHERE {$whereStr}", $params))
            : (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->p}rto_services WHERE {$whereStr}");
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page     = min($page, $lastPage);
        $offset   = ($page - 1) * $perPage;

        $sql      = "SELECT * FROM {$this->p}rto_services WHERE {$whereStr} ORDER BY category, display_order LIMIT %d OFFSET %d";
        $services = $this->db->get_results($this->db->prepare($sql, [...$params, $perPage, $offset]), ARRAY_A) ?: [];

        $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];

        // Known Limitations audit fix: "An admin viewing the Services screen
        // alone cannot see whether a city-level override exists for any
        // given service ... a price that looks correct on the Services
        // screen may still show differently to a customer in a specific
        // city, with no visual cue pointing an admin toward that
        // possibility." One grouped query (not N+1 per row) counts distinct
        // cities overriding each service currently on this page.
        $overrideCounts = [];
        $serviceIds = array_column($services, 'id');
        if ($serviceIds) {
            $placeholders = implode(',', array_fill(0, count($serviceIds), '%d'));
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT service_id, COUNT(DISTINCT city_id) as n FROM {$this->p}rto_city_service_config
                 WHERE service_id IN ({$placeholders}) GROUP BY service_id",
                $serviceIds
            ), ARRAY_A) ?: [];
            foreach ($rows as $r) $overrideCounts[(int)$r['service_id']] = (int)$r['n'];
        }

        rto_view('admin.services.index', compact('services', 'categories', 'category', 'search', 'page', 'lastPage', 'total', 'overrideCounts'));
    }

    // ── Create form ───────────────────────────────────────────────────────────

    public function create(): void
    {
        $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];
        rto_view('admin.services.form', ['service' => null, 'categories' => $categories, 'mode' => 'create']);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(): void
    {
        if (!check_admin_referer('rtoflow_service_save', 'rtoflow_nonce')) {
            wp_die('Security check failed.', 'Error', ['response' => 403]);
        }

        $data = $this->extractFormData();
        $errs = $this->validate($data);
        if ($errs) {
            $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];
            rto_view('admin.services.form', ['service' => $data, 'categories' => $categories, 'mode' => 'create', 'errors' => $errs]);
            return;
        }

        $inserted = $this->db->insert($this->p . 'rto_services', $data + ['created_at' => current_time('mysql')]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always redirected to "?saved=1"
        // regardless of whether the INSERT actually happened (e.g. a
        // duplicate slug hitting a unique constraint) — the admin would see
        // a success page for a service that was never created.
        if (!$inserted) {
            $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];
            rto_view('admin.services.form', [
                'service' => $data, 'categories' => $categories, 'mode' => 'create',
                'errors' => ['general' => 'Could not create the service — it may already exist, or a database error occurred.'],
            ]);
            return;
        }
        AuditService::log('service.created', null, ['id' => (int)$this->db->insert_id, 'name' => $data['name'], 'category' => $data['category']]);
        delete_transient('rtofl_active_services');
        wp_redirect(home_url('/rto-admin/services/?saved=1'));
        exit;
    }

    // ── Edit form ─────────────────────────────────────────────────────────────

    public function edit(int $id): void
    {
        $service = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_services WHERE id=%d", $id
        ), ARRAY_A);
        if (!$service) wp_die('Service not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];
        rto_view('admin.services.form', compact('service', 'categories') + ['mode' => 'edit', 'errors' => []]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(int $id): void
    {
        if (!check_admin_referer('rtoflow_service_save', 'rtoflow_nonce')) {
            wp_die('Security check failed.', 'Error', ['response' => 403]);
        }

        $data = $this->extractFormData();
        $errs = $this->validate($data);
        if ($errs) {
            $categories = $this->db->get_col("SELECT DISTINCT category FROM {$this->p}rto_services ORDER BY category") ?: [];
            $service    = array_merge(['id' => $id], $data);
            rto_view('admin.services.form', compact('service', 'categories') + ['mode' => 'edit', 'errors' => $errs]);
            return;
        }

        $before = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_services WHERE id=%d", $id), ARRAY_A) ?: [];
        $this->db->update($this->p . 'rto_services', $data + ['updated_at' => current_time('mysql')], ['id' => $id]);
        AuditService::log('service.updated', null, $data + ['id' => $id], $before);
        delete_transient('rtofl_active_services');
        wp_redirect(home_url('/rto-admin/services/?saved=1'));
        exit;
    }

    // ── Toggle active ─────────────────────────────────────────────────────────

    public function toggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) {
            rto_json_err('Security check failed.', 403);
        }
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['id']  ?? 0, 1);
        $active = (int)(bool)($_POST['active'] ?? 0);
        $updated = $this->db->update($this->p . 'rto_services', ['is_active' => $active], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the service. Please try again.', 500);
        AuditService::log('service.status_changed', null, ['id' => $id, 'is_active' => $active]);
        delete_transient('rtofl_active_services');
        rto_json_ok(['active' => $active]);
    }

    // ── Bulk activate/deactivate (AJAX) ─────────────────────────────────────
    // TRACE: admin selects multiple services on list, picks Activate/Deactivate,
    //        submits → check_ajax_referer('rto_admin_lead',...) + rto_is_admin() →
    //        sanitises service_ids + active flag → loops UPDATE per id →
    //        clears rtofl_active_services transient once → rto_json_ok({updated}).
    public function bulkToggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) {
            rto_json_err('Security check failed.', 403);
        }
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $ids    = array_values(array_filter(array_map('intval', (array)($_POST['service_ids'] ?? []))));
        $active = (int)(bool)($_POST['active'] ?? 0);

        if (empty($ids)) rto_json_err('No services selected.');

        $updated = 0;
        foreach ($ids as $id) {
            if ($id < 1) continue;
            $ok = $this->db->update($this->p . 'rto_services', ['is_active' => $active], ['id' => $id]);
            if ($ok !== false) {
                $updated++;
                AuditService::log('service.status_changed', null, ['id' => $id, 'is_active' => $active]);
            }
        }
        delete_transient('rtofl_active_services');

        rto_json_ok(['updated' => $updated], "{$updated} service(s) " . ($active ? 'activated' : 'deactivated') . '.');
    }

    // ── CSV export (GET, ?export=csv on the services list) ──────────────────
    // TRACE: admin visits /rto-admin/services/?export=csv&category=&search= →
    //        index() detects export=csv and calls this with the SAME $whereStr/
    //        $params it just built → fetches ALL matching rows (capped 5000,
    //        same safety cap as LeadsController::exportCsv()) → streams CSV.
    private function exportCsv(string $whereStr, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $sql      = "SELECT * FROM {$this->p}rto_services WHERE {$whereStr} ORDER BY category, display_order LIMIT 5000";
        $services = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $services = $services ?: [];

        $filename = 'rtoflow-services-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Name','Category','Description','Base Price','GST Applicable','SLA Days','Vendor Share %','Active','Display Order','Created At']);

        foreach ($services as $svc) {
            fputcsv($out, [
                $svc['name'] ?? '',
                $svc['category'] ?? '',
                $svc['description'] ?? '',
                $svc['base_price'] ?? '0.00',
                $svc['gst_applicable'] ? 'Yes' : 'No',
                $svc['sla_days'] ?? '',
                $svc['vendor_share'] ?? '',
                $svc['is_active'] ? 'Yes' : 'No',
                $svc['display_order'] ?? '',
                $svc['created_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractFormData(): array
    {
        $cat = (Sanitiser::text($_POST['category_new'] ?? '')) ?: (Sanitiser::text($_POST['category'] ?? ''));
        return [
            'name'           => Sanitiser::text($_POST['name']           ?? ''),
            'category'       => $cat,
            'description'    => Sanitiser::text($_POST['description']    ?? '', 1000),
            'base_price'     => max(0, (float)($_POST['base_price']      ?? 0)),
            'gst_applicable' => isset($_POST['gst_applicable']) ? 1 : 0,
            'sla_days'       => max(1, (int)($_POST['sla_days']          ?? 7)),
            'vendor_share'   => max(0, min(100, (float)($_POST['vendor_share'] ?? 45))),
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
            'display_order'  => max(0, (int)($_POST['display_order']     ?? 0)),
        ];
    }

    private function validate(array $data): array
    {
        $errs = [];
        if (!$data['name'])     $errs['name']       = 'Service name is required.';
        if (!$data['category']) $errs['category']   = 'Category is required.';
        if ($data['base_price'] < 0) $errs['base_price'] = 'Price cannot be negative.';
        if ($data['sla_days']   < 1) $errs['sla_days']   = 'SLA must be at least 1 day.';
        return $errs;
    }
}
