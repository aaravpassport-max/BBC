<?php
namespace NAS\Modules\Admin;
if (!defined('ABSPATH')) exit;

use NAS\Core\{Database, Security};

/**
 * NAS Super Combo v3 — Standalone Admin AJAX Module
 * Every admin operation runs through this module — no WP admin dependency.
 * All pages are served via Router at /nas-admin/* URLs.
 */
class AdminModule {

    public static function register(): void {
        // ── Booking management ──────────────────────────────────────────────
        foreach ([
            'nas_admin_update_status',
            'nas_admin_update_pricing',
            'nas_admin_assign_vendor',
            'nas_admin_save_notes',
            'nas_admin_save_content',
            'nas_admin_save_payment',
            'nas_admin_reject_booking',
            'nas_admin_gen_pdf',
            'nas_admin_get_booking',
            'nas_admin_get_bookings',
            'nas_admin_export_bookings',
            // ── CRUD ──────────────────────────────────────────────────────
            'nas_admin_save_newspaper',
            'nas_admin_delete_newspaper',
            'nas_admin_save_vendor',
            'nas_admin_delete_vendor',
            'nas_admin_save_category',
            'nas_admin_save_template',
            'nas_admin_delete_template',
            'nas_admin_save_city',
            'nas_admin_delete_city',
            'nas_admin_save_sample_ad',
            'nas_admin_delete_sample_ad',
            'nas_admin_save_combo',
            'nas_admin_delete_combo',
            'nas_admin_save_quick_reply',
            'nas_admin_delete_quick_reply',
            // ── Settings ──────────────────────────────────────────────────
            'nas_admin_save_settings',
            'nas_admin_get_settings',
            // ── Analytics ─────────────────────────────────────────────────
            'nas_admin_get_analytics',
            // ── Data Manager ──────────────────────────────────────────────
            'nas_admin_bulk_import',
            'nas_admin_run_seeder',
            // ── Clients ───────────────────────────────────────────────────
            'nas_admin_get_clients',
            'nas_admin_get_client_detail',
            // ── Team Account Management ────────────────────────────────────
            'nas_admin_create_team_user',
            'nas_admin_update_team_user',
            'nas_admin_delete_team_user',
            'nas_admin_get_team_user',
            'nas_admin_reset_team_pwd',
        ] as $action) {
            add_action("wp_ajax_{$action}", [self::class, str_replace('nas_admin_', '', $action)]);
        }
    }

    // ── Auth helper ──────────────────────────────────────────────────────────
    // TRACE: auth() — Trigger: wp_ajax_auth AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function auth(): void {
        Security::check_nonce(Security::post('nonce') ?: Security::get('nonce'), 'nas_admin_nonce');
        if (!current_user_can('manage_options') && !current_user_can('nas_manage_bookings')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
    }

    private static function db(): Database { return Database::instance(); }

    // TRACE: money() — Trigger: wp_ajax_money AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: returns null/false on failure.
    private static function money(float $n): string {
        return '₹' . number_format($n, 2);
    }

    // TRACE: calc_profit() — Trigger: wp_ajax_calc_profit AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: returns null/false on failure.
    private static function calc_profit(float $client, float $vendor, float $gst_pct = 18): array {
        $profit  = $client - $vendor;
        $gst_amt = $client * ($gst_pct / 100);
        $total   = $client + $gst_amt;
        $margin  = $client > 0 ? round(($profit / $client) * 100, 1) : 0;
        return compact('profit','gst_amt','total','margin');
    }

    // TRACE: suggest_price() — Trigger: wp_ajax_suggest_price AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: returns null/false on failure.
    private static function suggest_price(int $newspaper_id, int $cat_id, string $ad_type): ?object {
        $db = self::db();
        $t  = $db->prefix('price_history');
        if (!$db->table_exists($t)) return null;
        return $db->row(
            "SELECT AVG(client_price) avg_client, AVG(vendor_cost) avg_vendor,
                    AVG(margin_pct) avg_margin, COUNT(*) samples
             FROM `$t` WHERE newspaper_id=%d AND ad_type=%s",
            [$newspaper_id, $ad_type], OBJECT
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // BOOKING MANAGEMENT
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: update_status() — Trigger: wp_ajax_update_status AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function update_status(): void {
        self::auth();
        $id     = (int) Security::post('booking_id', 'int');
        $status = Security::post('status');
        // TRACE: Status alias normalisation — admin UI sends legacy short keys;
        //        we map them to canonical BookingStatus constants before writing to DB.
        //        Precondition: $status from POST is a string (sanitised above).
        //        Postcondition: $status is a canonical BookingStatus constant or request is rejected.
        //        Edge case: unknown aliases → error returned, DB unchanged.
        $alias_map = [
            // Legacy admin-UI aliases → canonical constants
            'submitted'     => 'booking_received',
            'review'        => 'under_review',
            'price_shared'  => 'quotation_sent',
            'approved'      => 'ready_to_process',
            'sent_to_vendor'=> 'ad_processing',
            'material'      => 'material_uploaded',
            'processing'    => 'ad_processing',
            'proof'         => 'proof_ready',
            'submitted_pub' => 'submitted_to_pub',
            // Canonical values pass through unchanged
            'booking_received'    => 'booking_received',
            'under_review'        => 'under_review',
            'ready_to_process'    => 'ready_to_process',
            'documents_received'  => 'documents_received',
            'quotation_sent'      => 'quotation_sent',
            'payment_received'    => 'payment_received',
            'material_uploaded'   => 'material_uploaded',
            'ad_processing'       => 'ad_processing',
            'proof_ready'         => 'proof_ready',
            'submitted_to_pub'    => 'submitted_to_pub',
            'published'           => 'published',
            'completed'           => 'completed',
            'rejected'            => 'rejected',
            'cancelled'           => 'cancelled',
            'not_able_to_process' => 'not_able_to_process',
            'not_eligible'        => 'not_eligible',
            'no_service'          => 'no_service',
        ];
        if ( ! array_key_exists( $status, $alias_map ) ) {
            wp_send_json_error( [ 'message' => 'Invalid status: ' . esc_html( $status ) ] );
        }
        $status = $alias_map[ $status ]; // normalise to canonical

        $db = self::db(); $t = $db->prefix('bookings');
        $booking = $db->row("SELECT * FROM `$t` WHERE id=%d", [$id], OBJECT);
        if (!$booking) wp_send_json_error(['message' => 'Booking not found']);

        $old_status = $booking->status;
        $history = json_decode($booking->workflow_history ?? '[]', true) ?: [];
        $note    = Security::post('note') ?: '';
        $history[] = ['status' => $status, 'note' => $note, 'by' => get_current_user_id(), 'at' => gmdate('Y-m-d H:i:s')];

        $rows_affected = $db->update($t, ['status' => $status, 'workflow_history' => wp_json_encode($history)], ['id' => $id]);
        // 2.6-D: check return value — false = query failed (ghost success prevention)
        if ( $rows_affected === false ) {
            wp_send_json_error(['message' => 'Database error: status could not be updated. Please try again.'], 500);
        }

        // AUDIT TRAIL (Part 12-B): log every status change with old→new values
        if ( class_exists( '\NAS\Core\AuditLogger' ) ) {
            \NAS\Core\AuditLogger::status_change( 'bookings', $id, $old_status, $status, $note ?: "Status updated by admin" );
        }

        do_action('nas_status_changed', $id, $status);
        wp_send_json_success(['message' => 'Status updated to: ' . $status, 'status' => $status]);
    }

    // TRACE: update_pricing() — Trigger: wp_ajax_update_pricing AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function update_pricing(): void {
        self::auth();
        $id           = (int) Security::post('booking_id', 'int');
        $client_price = (float) Security::post('client_price', 'float');
        $vendor_cost  = (float) Security::post('vendor_cost', 'float');
        $gst_raw      = Security::post('gst_percentage');
        $gst_pct      = ($gst_raw !== null && $gst_raw !== '') ? (float)$gst_raw : 18.0;

        $calc = self::calc_profit($client_price, $vendor_cost, $gst_pct);
        $db   = self::db(); $t = $db->prefix('bookings');
        $db->update($t, [
            'client_price'   => $client_price,
            'vendor_cost'    => $vendor_cost,
            'profit'         => $calc['profit'],
            'gst_percentage' => $gst_pct,
            'gst_amount'     => $calc['gst_amt'],
            'total_amount'   => $calc['total'],
            'base_amount'    => $client_price,
        ], ['id' => $id]);

        // Record to price history for AI suggestions
        $booking = $db->row("SELECT * FROM `$t` WHERE id=%d", [$id], OBJECT);
        if ($booking && $client_price > 0) {
            $ht = $db->prefix('price_history');
            if ($db->table_exists($ht)) {
                $db->insert($ht, [
                    'newspaper_id' => $booking->newspaper_id,
                    'category_id'  => $booking->category_id,
                    'ad_type'      => $booking->ad_type,
                    'client_price' => $client_price,
                    'vendor_cost'  => $vendor_cost,
                    'profit'       => $calc['profit'],
                    'margin_pct'   => $calc['margin'],
                    'recorded_at'  => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }

        wp_send_json_success([
            'profit'  => self::money($calc['profit']),
            'gst_amt' => self::money($calc['gst_amt']),
            'total'   => self::money($calc['total']),
            'margin'  => $calc['margin'] . '%',
        ]);
    }

    // TRACE: assign_vendor() — Trigger: wp_ajax_assign_vendor AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function assign_vendor(): void {
        self::auth();
        $id  = (int) Security::post('booking_id', 'int');
        $vid = (int) Security::post('vendor_id', 'int');
        if (!$id || !$vid) wp_send_json_error(['message' => 'Booking and vendor required']);

        $db = self::db(); $t = $db->prefix('bookings'); $vt = $db->prefix('vendors');
        // TRACE: vendor assigned → canonical status 'ad_processing' (booking moves to vendor processing stage)
        $db->update($t, ['assigned_vendor_id' => $vid, 'status' => 'ad_processing', 'vendor_assigned_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        $db->raw()->query( $db->raw()->prepare( "UPDATE `$vt` SET total_orders=total_orders+1 WHERE id=%d", $vid ) );  // fixed: prepared statement

        $vendor = $db->row("SELECT * FROM `$vt` WHERE id=%d", [$vid], OBJECT);
        do_action('nas_vendor_assigned', $id, $vid);
        wp_send_json_success(['message' => 'Vendor assigned. ' . ($vendor ? $vendor->name . ' notified.' : '')]);
    }

    // TRACE: save_notes() — Trigger: wp_ajax_save_notes AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_notes(): void {
        self::auth();
        $id   = (int) Security::post('booking_id', 'int');
        $type = Security::post('note_type') === 'vendor' ? 'notes_vendor' : 'notes_admin';
        $note = Security::post('note', 'textarea');
        $db   = self::db();
        $db->update($db->prefix('bookings'), [$type => $note], ['id' => $id]);
        wp_send_json_success(['message' => 'Note saved']);
    }

    // TRACE: save_content() — Trigger: wp_ajax_save_content AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_content(): void {
        self::auth();
        $id      = (int) Security::post('booking_id', 'int');
        $content = Security::post('ad_content', 'textarea');
        $wc      = str_word_count(strip_tags($content));
        $db      = self::db();
        $db->update($db->prefix('bookings'), ['ad_content' => $content, 'word_count' => $wc], ['id' => $id]);
        wp_send_json_success(['word_count' => $wc, 'message' => 'Content saved. Word count: ' . $wc]);
    }

    // TRACE: save_payment() — Trigger: wp_ajax_save_payment AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_payment(): void {
        self::auth();
        $id     = (int) Security::post('booking_id', 'int');
        $status = Security::post('payment_status');
        $method = Security::post('payment_method') ?: 'manual';
        $ref    = Security::post('payment_ref');
        $db     = self::db();
        $db->update($db->prefix('bookings'), [
            'payment_status' => $status,
            'payment_method' => $method,
            'payment_ref'    => $ref,
        ], ['id' => $id]);
        wp_send_json_success(['message' => 'Payment status updated to: ' . $status]);
    }

    // TRACE: reject_booking() — Trigger: wp_ajax_reject_booking AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function reject_booking(): void {
        self::auth();
        $id     = (int) Security::post('booking_id', 'int');
        $reason = Security::post('reason', 'textarea') ?: 'Rejected by admin';
        $db = self::db(); $t = $db->prefix('bookings');
        $booking = $db->row("SELECT * FROM `$t` WHERE id=%d", [$id], OBJECT);
        if (!$booking) wp_send_json_error(['message' => 'Booking not found']);
        $history = json_decode($booking->workflow_history ?? '[]', true) ?: [];
        $history[] = ['status' => 'rejected', 'note' => $reason, 'by' => get_current_user_id(), 'at' => gmdate('Y-m-d H:i:s')];
        $db->update($t, ['status' => 'rejected', 'workflow_history' => wp_json_encode($history)], ['id' => $id]);
        do_action('nas_status_changed', $id, 'rejected');
        wp_send_json_success(['message' => 'Booking rejected']);
    }

    // TRACE: get_booking() — Trigger: wp_ajax_get_booking AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_booking(): void {
        self::auth();
        $id = (int) Security::post('id', 'int') ?: (int) Security::get('id');
        if (!$id) wp_send_json_error(['message' => 'ID required']);

        $db = self::db(); $bt = $db->prefix('bookings'); $ct = $db->prefix('clients');
        $nt = $db->prefix('newspapers'); $cat_t = $db->prefix('categories');
        $city_t = $db->prefix('cities'); $vt = $db->prefix('vendors');

        global $wpdb;
        $b = $wpdb->get_row($wpdb->prepare(
            "SELECT bk.*,
                    cl.name AS client_name, cl.phone AS client_phone, cl.email AS client_email,
                    cl.company_name AS client_company, cl.gst_number AS client_gst, cl.address AS client_address,
                    n.name AS newspaper_name, n.language AS newspaper_lang,
                    n.base_rate_classified, n.base_rate_display, n.min_charge AS newspaper_min,
                    cat.name AS category_name, cat.icon AS category_icon,
                    ci.name AS city_name, ci.state AS city_state,
                    v.name AS vendor_name, v.phone AS vendor_phone
             FROM `$bt` bk
             LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             LEFT JOIN `$cat_t` cat ON cat.id=bk.category_id
             LEFT JOIN `$city_t` ci ON ci.id=bk.city_id
             LEFT JOIN `$vt` v ON v.id=bk.assigned_vendor_id
             WHERE bk.id=%d", $id
        ), OBJECT);

        if (!$b) wp_send_json_error(['message' => 'Booking not found']);

        // AI price suggestion
        $suggest = self::suggest_price((int)$b->newspaper_id, (int)$b->category_id, $b->ad_type ?? 'classified');
        $b->ai_suggestion = $suggest && $suggest->samples >= 3 ? $suggest : null;

        wp_send_json_success($b);
    }

    // TRACE: get_bookings() — Trigger: wp_ajax_get_bookings AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_bookings(): void {
        self::auth();
        global $wpdb;
        $status  = Security::post('status');
        $payment = Security::post('payment');
        $search  = Security::post('search');
        $page    = max(1, (int) Security::post('page', 'int'));
        $per     = 25;
        $offset  = ($page - 1) * $per;

        $db  = self::db();
        $bt  = $db->prefix('bookings');
        $ct  = $db->prefix('clients');
        $nt  = $db->prefix('newspapers');
        $cat = $db->prefix('categories');
        $cit = $db->prefix('cities');

        $where = ['1=1']; $params = [];
        if ($status)  { $where[] = 'bk.status=%s'; $params[] = $status; }
        if ($payment) { $where[] = 'bk.payment_status=%s'; $params[] = $payment; }
        if ($search) {
            $where[] = '(cl.name LIKE %s OR cl.phone LIKE %s OR bk.uid LIKE %s)';
            $s = '%'.$wpdb->esc_like($search).'%';
            $params = array_merge($params, [$s,$s,$s]);
        }
        $wsql = implode(' AND ', $where);

        $total = $params
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$bt` bk LEFT JOIN `$ct` cl ON cl.id=bk.client_id WHERE $wsql", ...$params))
            : $wpdb->get_var("SELECT COUNT(*) FROM `$bt` bk LEFT JOIN `$ct` cl ON cl.id=bk.client_id WHERE $wsql");

        $sql = "SELECT bk.*, cl.name client_name, cl.phone client_phone,
                       cat.name cat_name, n.name np_name, cit.name city_name
                FROM `$bt` bk
                LEFT JOIN `$ct` cl ON cl.id=bk.client_id
                LEFT JOIN `$cat` cat ON cat.id=bk.category_id
                LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
                LEFT JOIN `$cit` cit ON cit.id=bk.city_id
                WHERE $wsql ORDER BY bk.submitted_at DESC LIMIT $per OFFSET $offset";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), OBJECT) : $wpdb->get_results($sql, OBJECT);
        wp_send_json_success(['bookings' => $rows, 'total' => (int)$total, 'pages' => ceil($total/$per), 'page' => $page]);
    }

    // TRACE: export_bookings() — Trigger: wp_ajax_export_bookings AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function export_bookings(): void {
        self::auth();
        $db = self::db(); global $wpdb;
        // FIX (audit): this handler never read the 'type' param the frontend sends
        // (templates/admin/pages/data-manager.php dmExport()) — "Export Clients CSV" downloaded
        // a file named clients-*.csv that actually contained bookings data, every time.
        $type = Security::post('type') ?: 'bookings';

        if ( $type === 'clients' ) {
            $ct = $db->prefix('clients');
            $rows = $wpdb->get_results(
                "SELECT name, email, phone, company_name, gst_number, city, state, address, created_at, total_orders
                 FROM `$ct` ORDER BY created_at DESC", ARRAY_A
            );
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="clients-'.date('Y-m-d').'.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($rows[0] ?? ['no_data' => '']));
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        }

        $bt  = $db->prefix('bookings');  $ct = $db->prefix('clients');
        $nt  = $db->prefix('newspapers'); $cat = $db->prefix('categories');
        $rows = $wpdb->get_results(
            "SELECT bk.uid, cl.name client_name, cl.phone, cl.email, cat.name category,
                    n.name newspaper, bk.city_name, bk.ad_type, bk.word_count,
                    bk.publish_date, bk.client_price, bk.vendor_cost, bk.profit,
                    bk.gst_amount, bk.total_amount, bk.status, bk.payment_status, bk.submitted_at
             FROM `$bt` bk
             LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             ORDER BY bk.submitted_at DESC", ARRAY_A
        );
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bookings-'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array_keys($rows[0] ?? ['no_data' => '']));
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // PDF GENERATION
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: gen_pdf() — Trigger: wp_ajax_gen_pdf AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function gen_pdf(): void {
        // Auth via GET nonce
        if (!wp_verify_nonce(Security::get('nonce'), 'nas_pdf')) wp_die('Unauthorized');
        if (!current_user_can('manage_options') && !current_user_can('nas_manage_bookings')) wp_die('Unauthorized');

        $id   = (int) Security::get('id');
        $type = Security::get('type') ?: 'invoice';

        $db = self::db(); global $wpdb;
        $bt  = $db->prefix('bookings'); $ct = $db->prefix('clients');
        $nt  = $db->prefix('newspapers'); $cat = $db->prefix('categories');
        $cit = $db->prefix('cities');

        $b = $wpdb->get_row($wpdb->prepare(
            "SELECT bk.*, cl.name client_name, cl.phone client_phone, cl.email client_email,
                    cl.company_name, cl.gst_number,
                    n.name np_name, n.language np_lang,
                    cat.name cat_name, cit.name city_name
             FROM `$bt` bk
             LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             LEFT JOIN `$cit` cit ON cit.id=bk.city_id
             WHERE bk.id=%d", $id
        ), OBJECT);
        if (!$b) wp_die('Booking not found');

        // Generate invoice number
        $settings  = self::get_settings_array();
        $brand     = $settings['brand_name'] ?? get_bloginfo('name');
        $sym       = $settings['currency_symbol'] ?? '₹';
        $footer    = $settings['footer_text'] ?? '';
        $inv_pfx   = $settings['invoice_prefix'] ?? 'INV';
        $gst_no    = $settings['gst_number'] ?? '';

        if (!$b->invoice_number) {
            $inv_num = $inv_pfx . '-' . date('Y') . '-' . str_pad(wp_rand(1000, 9999), 5, '0', STR_PAD_LEFT);
            $db->update($bt, ['invoice_number' => $inv_num], ['id' => $id]);
            $b->invoice_number = $inv_num;
        }

        $is_vendor = $type === 'vendor';
        $title     = $is_vendor ? 'Release Order' : 'Tax Invoice';
        $doc_no    = $is_vendor ? 'RO-' . $b->uid : $b->invoice_number;

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo esc_html($title . ' - ' . $doc_no); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#1f2937;background:#fff;padding:30px;}
.doc-wrap{max-width:800px;margin:0 auto;}
.doc-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:20px;border-bottom:3px solid #1d4ed8;margin-bottom:24px;}
.brand h1{font-size:22px;color:#1d4ed8;font-weight:800;}
.brand p{color:#6b7280;font-size:12px;margin-top:4px;}
.doc-meta{text-align:right;}
.doc-meta h2{font-size:20px;text-transform:uppercase;letter-spacing:2px;color:#1f2937;}
.doc-meta .doc-no{font-size:16px;color:#1d4ed8;font-weight:700;margin:4px 0;}
.doc-meta .doc-date{color:#6b7280;font-size:12px;}
.parties{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
.party{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;}
.party h4{color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;}
.party strong{font-size:14px;display:block;margin-bottom:3px;}
.party span{color:#6b7280;font-size:12px;display:block;}
.ad-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin-bottom:20px;}
.ad-box h4{color:#1d4ed8;margin-bottom:10px;font-size:13px;}
.ad-text{background:#fff;border:1px solid #dbeafe;border-radius:6px;padding:12px;font-size:13px;line-height:1.7;min-height:80px;}
table{width:100%;border-collapse:collapse;margin-bottom:20px;}
th{background:#1d4ed8;color:#fff;padding:10px 12px;text-align:left;font-size:12px;}
td{padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;}
tr:last-child td{border:none;}
.totals{margin-left:auto;width:280px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
.tr{display:flex;justify-content:space-between;padding:8px 14px;border-bottom:1px solid #e5e7eb;}
.tr.grand{background:#1d4ed8;color:#fff;font-size:15px;font-weight:700;border:none;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase;}
.badge-green{background:#dcfce7;color:#16a34a;}
.badge-yellow{background:#fef9c3;color:#ca8a04;}
.footer{margin-top:30px;padding-top:15px;border-top:1px solid #e5e7eb;text-align:center;color:#9ca3af;font-size:11px;}
.no-print{display:none;}
@media print{.no-print{display:none!important;}}
.watermark{text-align:center;color:#dbeafe;font-size:40px;font-weight:900;letter-spacing:5px;margin:10px 0;}
.terms{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:16px 0;font-size:11px;color:#6b7280;}
</style>
</head>
<body>
<div class="doc-wrap">
  <div class="doc-header">
    <div class="brand">
      <h1>📰 <?php echo esc_html($brand); ?></h1>
      <p>Newspaper Advertisement Booking</p>
      <?php if ($gst_no): ?><p>GSTIN: <?php echo esc_html($gst_no); ?></p><?php endif; ?>
    </div>
    <div class="doc-meta">
      <h2><?php echo esc_html($title); ?></h2>
      <div class="doc-no"><?php echo esc_html($doc_no); ?></div>
      <div class="doc-date">Date: <?php echo date('d M Y'); ?></div>
      <?php if (!$is_vendor): ?>
      <div class="doc-date">Due Date: <?php echo date('d M Y', strtotime('+7 days')); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($is_vendor): ?>
  <div class="watermark">RELEASE ORDER</div>
  <?php endif; ?>

  <div class="parties">
    <div class="party">
      <h4><?php echo $is_vendor ? 'Publication / Vendor' : 'Bill To'; ?></h4>
      <?php if ($is_vendor): ?>
        <strong><?php echo esc_html($b->np_name); ?></strong>
        <span><?php echo esc_html($b->city_name); ?></span>
        <span>Edition: <?php echo esc_html($b->edition ?: 'Main Edition'); ?></span>
      <?php else: ?>
        <strong><?php echo esc_html($b->client_name); ?></strong>
        <span><?php echo esc_html($b->client_phone); ?></span>
        <?php if ($b->client_email): ?><span><?php echo esc_html($b->client_email); ?></span><?php endif; ?>
        <?php if ($b->company_name): ?><span><?php echo esc_html($b->company_name); ?></span><?php endif; ?>
        <?php if ($b->gst_number): ?><span>GSTIN: <?php echo esc_html($b->gst_number); ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="party">
      <h4><?php echo $is_vendor ? 'Details' : 'From'; ?></h4>
      <strong><?php echo esc_html($brand); ?></strong>
      <span>Newspaper Ad Booking Service</span>
      <span>Ref: <?php echo esc_html($b->uid); ?></span>
      <?php if (!$is_vendor && $b->client_name): ?>
        <span>Client: <?php echo esc_html($b->client_name); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ad Details -->
  <div class="ad-box">
    <h4>Advertisement Details</h4>
    <table>
      <tr>
        <th>Category</th><th>Newspaper</th><th>Edition</th>
        <th>Ad Type</th><th>Words<?php echo $b->ad_type === 'classified' ? '' : '/Size'; ?></th>
        <th>Pub. Date</th>
      </tr>
      <tr>
        <td><?php echo esc_html($b->cat_name); ?></td>
        <td><?php echo esc_html($b->np_name); ?></td>
        <td><?php echo esc_html($b->edition ?: 'Main'); ?></td>
        <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $b->ad_type ?? ''))); ?></td>
        <td><?php echo $b->ad_type === 'classified' ? $b->word_count . ' words' : ($b->width_cm ?? 0) . '×' . ($b->height_cm ?? 0) . ' cm'; ?></td>
        <td><strong><?php echo esc_html($b->publish_date); ?></strong></td>
      </tr>
    </table>
    <h4 style="margin-bottom:8px">Ad Content</h4>
    <div class="ad-text"><?php echo nl2br(esc_html($b->ad_content ?? '')); ?></div>
  </div>

  <!-- Pricing -->
  <table>
    <thead><tr><th>Description</th><th>Rate</th><th>Qty/Units</th><th>Amount</th></tr></thead>
    <tbody>
      <tr>
        <td>
          <?php echo esc_html($is_vendor ? 'Release / Publication' : 'Advertisement'); ?> –
          <?php echo esc_html($b->cat_name . ' · ' . $b->np_name . ' (' . $b->edition . ')'); ?>
        </td>
        <td>
          <?php if ($b->ad_type === 'classified'): ?>
            <?php echo $sym; ?><?php echo number_format((float)($b->base_rate_classified ?? 0), 2); ?>/word
          <?php else: ?>
            <?php echo $sym; ?><?php echo number_format((float)($b->base_rate_display ?? 0), 2); ?>/cm²
          <?php endif; ?>
        </td>
        <td>
          <?php echo $b->ad_type === 'classified' ? $b->word_count . ' words' : round(($b->width_cm ?? 0) * ($b->height_cm ?? 0), 2) . ' cm²'; ?>
        </td>
        <td><?php echo $sym; ?><?php echo number_format((float)($is_vendor ? $b->vendor_cost : $b->client_price), 2); ?></td>
      </tr>
    </tbody>
  </table>

  <div class="totals">
    <div class="tr"><span>Sub Total</span><span><?php echo $sym; ?><?php echo number_format((float)($is_vendor ? $b->vendor_cost : $b->client_price), 2); ?></span></div>
    <?php if (!$is_vendor): ?>
    <div class="tr"><span>GST (<?php echo $b->gst_percentage ?? 18; ?>%)</span><span><?php echo $sym; ?><?php echo number_format((float)$b->gst_amount, 2); ?></span></div>
    <?php endif; ?>
    <div class="tr grand">
      <span>Total</span>
      <span><?php echo $sym; ?><?php echo number_format((float)($is_vendor ? $b->vendor_cost : $b->total_amount), 2); ?></span>
    </div>
  </div>

  <?php if (!$is_vendor): ?>
  <div class="tr" style="margin-top:12px;padding:0">
    <span>Payment Status:</span>
    <span class="badge <?php echo $b->payment_status === 'paid' ? 'badge-green' : 'badge-yellow'; ?>"><?php echo esc_html($b->payment_status); ?></span>
  </div>
  <?php endif; ?>

  <div class="terms">
    <strong>Terms & Conditions:</strong>
    Payment due before publication. All ads subject to editorial approval. Rates subject to GST.
    <?php echo esc_html($footer); ?>
  </div>

  <div class="footer">
    Generated by <?php echo esc_html($brand); ?> · <?php echo date('d M Y, H:i'); ?>
    <br>This is a computer-generated document.
  </div>
</div>

<div class="no-print" style="text-align:center;margin-top:20px;display:block">
  <button onclick="window.print()" style="padding:10px 24px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-weight:600">
    🖨️ Print / Save PDF
  </button>
</div>
</body>
</html>
<?php
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    // NEWSPAPERS CRUD
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: save_newspaper() — Trigger: wp_ajax_save_newspaper AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_newspaper(): void {
        self::auth();
        $db   = self::db(); $t = $db->prefix('newspapers');
        $id   = (int) Security::post('id', 'int');
        $cities    = array_map('intval', (array)(json_decode(stripslashes(Security::post('cities') ?? '[]'), true) ?: []));
        $editions  = array_map('sanitize_text_field', (array)(json_decode(stripslashes(Security::post('editions') ?? '[]'), true) ?: []));
        $data = [
            'name'                   => Security::post('name'),
            'slug'                   => sanitize_title(Security::post('name')),
            'language'               => Security::post('language'),
            'description'            => Security::post('description', 'textarea'),
            'circulation'            => (int) Security::post('circulation', 'int'),
            'cities_supported'       => wp_json_encode($cities),
            'editions'               => wp_json_encode($editions),
            'base_rate_classified'   => (float) Security::post('rate_classified', 'float'),
            'base_rate_display'      => (float) Security::post('rate_display', 'float'),
            'base_rate_dc'           => (float) Security::post('rate_dc', 'float'),
            'min_charge'             => (float) Security::post('min_charge', 'float'),
            'is_active'              => 1,
        ];
        if ($id) { $db->update($t, $data, ['id' => $id]); }
        else     { $db->insert($t, $data); $id = $db->raw()->insert_id; }
        wp_send_json_success(['id' => $id, 'message' => 'Newspaper saved']);
    }

    // TRACE: delete_newspaper() — Trigger: wp_ajax_delete_newspaper AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_newspaper(): void {
        self::auth();
        $id = (int) Security::post('id', 'int');
        $db = self::db();
        $db->update($db->prefix('newspapers'), ['is_active' => 0], ['id' => $id]);
        wp_send_json_success(['message' => 'Newspaper deactivated']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // VENDORS CRUD
    // ════════════════════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════════════════════
    // TEAM ACCOUNT MANAGEMENT (Vendor / Staff / Manager — no wp-admin needed)
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: create_team_user() — Trigger: wp_ajax_create_team_user AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function create_team_user(): void {
        self::auth();
        $display_name = sanitize_text_field( Security::post('display_name') );
        $email        = sanitize_email( Security::post('email') );
        $username     = sanitize_user( Security::post('username'), true );
        $password     = Security::post('password');
        $role         = sanitize_key( Security::post('role') );

        $allowed_roles = [ 'nas_vendor', 'nas_staff', 'nas_manager' ];
        if ( ! in_array( $role, $allowed_roles ) ) {
            wp_send_json_error( [ 'message' => 'Invalid role selected.' ] ); return;
        }
        if ( ! $display_name ) { wp_send_json_error( [ 'message' => 'Full name is required.' ] ); return; }
        if ( ! is_email( $email ) )  { wp_send_json_error( [ 'message' => 'Valid email is required.' ] ); return; }
        if ( strlen( $password ) < 8 ) { wp_send_json_error( [ 'message' => 'Password must be at least 8 characters.' ] ); return; }

        // Check duplicates
        if ( email_exists( $email ) )    { wp_send_json_error( [ 'message' => 'An account with this email already exists.' ] ); return; }
        if ( username_exists( $username ) ) { wp_send_json_error( [ 'message' => 'This username is already taken. Try another.' ] ); return; }

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => $display_name,
            'user_pass'    => $password,
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] ); return;
        }

        // Auto-create vendor profile if role is nas_vendor
        if ( $role === 'nas_vendor' ) {
            $db  = self::db();
            $uid = 'VND-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );
            $db->insert( $db->prefix('vendors'), [
                'wp_user_id' => $user_id,
                'uid'        => $uid,
                'name'       => $display_name,
                'email'      => $email,
                'is_active'  => 1,
            ] );
            wp_send_json_success( [ 'id' => $user_id, 'message' => 'Vendor account created! Vendor profile auto-created and linked. They can now log in at /vendor-dashboard/' ] );
        } else {
            wp_send_json_success( [ 'id' => $user_id, 'message' => ucfirst( str_replace( 'nas_', '', $role ) ) . ' account created successfully!' ] );
        }
    }

    // TRACE: update_team_user() — Trigger: wp_ajax_update_team_user AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: id=0 rejected.
    public static function update_team_user(): void {
        self::auth();
        $id           = (int) Security::post('id', 'int');
        $display_name = sanitize_text_field( Security::post('display_name') );
        $email        = sanitize_email( Security::post('email') );
        $password     = Security::post('password');
        $role         = sanitize_key( Security::post('role') );

        if ( ! $id )            { wp_send_json_error( [ 'message' => 'User ID required.' ] ); return; }
        if ( ! $display_name )  { wp_send_json_error( [ 'message' => 'Full name is required.' ] ); return; }
        if ( ! is_email($email) ){ wp_send_json_error( [ 'message' => 'Valid email is required.' ] ); return; }

        // Check email not taken by another user
        $existing = get_user_by( 'email', $email );
        if ( $existing && (int) $existing->ID !== $id ) {
            wp_send_json_error( [ 'message' => 'This email is already in use by another account.' ] ); return;
        }

        $data = [ 'ID' => $id, 'user_email' => $email, 'display_name' => $display_name ];
        if ( $password ) {
            if ( strlen($password) < 8 ) { wp_send_json_error( [ 'message' => 'Password must be at least 8 characters.' ] ); return; }
            $data['user_pass'] = $password;
        }

        $result = wp_update_user( $data );
        if ( is_wp_error($result) ) { wp_send_json_error( [ 'message' => $result->get_error_message() ] ); return; }

        // Update role
        $allowed_roles = [ 'nas_vendor', 'nas_staff', 'nas_manager' ];
        if ( in_array( $role, $allowed_roles ) ) {
            $user = new \WP_User( $id );
            $user->set_role( $role );
        }

        wp_send_json_success( [ 'message' => 'Account updated successfully!' ] );
    }

    // TRACE: get_team_user() — Trigger: wp_ajax_get_team_user AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected.
    public static function get_team_user(): void {
        self::auth();
        $id   = (int) Security::post('id', 'int');
        $user = get_userdata( $id );
        if ( ! $user ) { wp_send_json_error( [ 'message' => 'User not found.' ] ); return; }

        $allowed_roles = [ 'nas_vendor', 'nas_staff', 'nas_manager' ];
        $role = array_values( array_intersect( (array) $user->roles, $allowed_roles ) )[0] ?? 'nas_vendor';

        wp_send_json_success( [
            'id'           => $user->ID,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'username'     => $user->user_login,
            'role'         => $role,
        ] );
    }

    // TRACE: delete_team_user() — Trigger: wp_ajax_delete_team_user AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected.
    public static function delete_team_user(): void {
        self::auth();
        $id = (int) Security::post('id', 'int');
        if ( ! $id ) { wp_send_json_error( [ 'message' => 'User ID required.' ] ); return; }

        // Don't delete admins
        if ( user_can( $id, 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Cannot delete an administrator account.' ] ); return;
        }

        // Unlink from vendor profile if linked
        $db = self::db();
        $db->update( $db->prefix('vendors'), [ 'wp_user_id' => 0 ], [ 'wp_user_id' => $id ] );

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $id );
        wp_send_json_success( [ 'message' => 'Account permanently deleted.' ] );
    }

    // TRACE: reset_team_pwd() — Trigger: wp_ajax_reset_team_pwd AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure → sends email via wp_mail.
    //        Output: success/error JSON response.
    //        Edge cases: WP_Error returned by external call handled.
    public static function reset_team_pwd(): void {
        self::auth();
        $id    = (int) Security::post('id', 'int');
        $email = sanitize_email( Security::post('email') );
        $user  = get_userdata( $id );
        if ( ! $user ) { wp_send_json_error( [ 'message' => 'User not found.' ] ); return; }

        // Use WordPress password reset
        $key  = get_password_reset_key( $user );
        if ( is_wp_error($key) ) { wp_send_json_error( [ 'message' => 'Could not generate reset key.' ] ); return; }

        $reset_url = home_url('/newspaper-ad-login/?step=reset&key=' . $key . '&login=' . rawurlencode($user->user_login));
        $message   = "Hi {$user->display_name},

A password reset was requested for your account.

Click the link below to reset your password:
{$reset_url}

This link expires in 24 hours.

— " . get_bloginfo('name');

        $sent = wp_mail( $user->user_email, 'Password Reset — ' . get_bloginfo('name'), $message );
        if ( $sent ) {
            wp_send_json_success( [ 'message' => "Password reset email sent to {$user->user_email}" ] );
        } else {
            wp_send_json_error( [ 'message' => 'Could not send email. Check your WordPress mail settings.' ] );
        }
    }

    // TRACE: save_vendor() — Trigger: wp_ajax_save_vendor AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_vendor(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('vendors');
        $id      = (int) Security::post('id', 'int');
        $wp_uid  = (int) Security::post('wp_user_id', 'int');
        $cities  = array_map('intval', (array)(json_decode(stripslashes(Security::post('cities') ?? '[]'), true) ?: []));
        $papers  = array_map('intval', (array)(json_decode(stripslashes(Security::post('newspapers') ?? '[]'), true) ?: []));

        // Prevent duplicate wp_user_id (same user can't be linked to 2 vendors)
        if ($wp_uid) {
            global $wpdb;
            $existing = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `$t` WHERE wp_user_id=%d AND id != %d", $wp_uid, $id)
            );
            if ($existing) {
                wp_send_json_error(['message' => 'This WordPress user is already linked to another vendor profile.']);
                return;
            }
        }

        $data = [
            'wp_user_id'           => $wp_uid ?: null,
            'name'                 => Security::post('name'),
            'phone'                => Security::post('phone'),
            'email'                => Security::post('email', 'email'),
            'company_name'         => Security::post('company'),
            'whatsapp_number'      => Security::post('whatsapp'),
            'gst_number'           => Security::post('gst_number'),
            'address'              => Security::post('address', 'textarea'),
            'cities_supported'     => wp_json_encode($cities),
            'newspapers_supported' => wp_json_encode($papers),
            'notes'                => Security::post('notes', 'textarea'),
            'is_active'            => 1,
        ];

        // Generate UID for new vendors
        if ( ! $id ) {
            $data['uid'] = 'VND-' . strtoupper( substr( md5( uniqid('', true) ), 0, 8 ) );
        }

        if ($id) { $db->update($t, $data, ['id' => $id]); }
        else     { $db->insert($t, $data); $id = $db->raw()->insert_id; }
        wp_send_json_success(['id' => $id, 'message' => 'Vendor saved']);
    }

    // TRACE: delete_vendor() — Trigger: wp_ajax_delete_vendor AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_vendor(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('vendors'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success(['message' => 'Vendor deactivated']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // CATEGORIES, TEMPLATES, CITIES
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: save_category() — Trigger: wp_ajax_save_category AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_category(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('categories');
        $id = (int) Security::post('id', 'int');
        $data = [
            'name'        => Security::post('name'),
            'slug'        => sanitize_title(Security::post('name')),
            'icon'        => Security::post('icon'),
            'description' => Security::post('description', 'textarea'),
            'is_active'   => 1,
            'sort_order'  => (int) Security::post('sort_order', 'int'),
        ];
        if ($id) { $db->update($t, $data, ['id' => $id]); }
        else     { $db->insert($t, $data); $id = $db->raw()->insert_id; }
        wp_send_json_success(['id' => $id, 'message' => 'Category saved']);
    }

    // TRACE: save_template() — Trigger: wp_ajax_save_template AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_template(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('templates');
        $id = (int) Security::post('id', 'int');
        // FIX (audit): templates table columns are 'name'/'content' (database/Schema.php:299-310).
        // 'template_name'/'template_content' never existed — every save silently failed to
        // persist the name/content fields (INSERT/UPDATE with unknown column = SQL error,
        // swallowed by Database::insert/update, success reported anyway).
        $data = [
            'name'             => Security::post('name'),
            'category_id'      => (int) Security::post('category_id', 'int'),
            'tone'             => Security::post('tone') ?: 'formal',
            'word_limit'       => (int) Security::post('word_limit', 'int') ?: 60,
            'content'          => Security::post('content', 'textarea'),
            'is_active'        => 1,
        ];
        if ($id) { $db->update($t, $data, ['id' => $id]); }
        else     { $db->insert($t, $data); $id = $db->raw()->insert_id; }
        wp_send_json_success(['id' => $id, 'message' => 'Template saved']);
    }

    // TRACE: delete_template() — Trigger: wp_ajax_delete_template AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_template(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('templates'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success();
    }

    // TRACE: save_city() — Trigger: wp_ajax_save_city AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_city(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('cities');
        $id = (int) Security::post('id', 'int');
        $data = [
            'name'       => Security::post('name'),
            'slug'       => sanitize_title(Security::post('name')),
            'state'      => Security::post('state'),
            'state_code' => strtoupper(substr(Security::post('state'), 0, 2)),
            'tier'       => (int) Security::post('tier', 'int') ?: 3,
            'population' => (int) Security::post('population', 'int'),
            'is_active'  => 1,
        ];
        if ($id) { $db->update($t, $data, ['id' => $id]); }
        else     { $db->insert($t, $data); $id = $db->raw()->insert_id; }
        wp_send_json_success(['id' => $id, 'message' => 'City saved']);
    }

    // TRACE: delete_city() — Trigger: wp_ajax_delete_city AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_city(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('cities'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SAMPLE ADS, COMBOS, QUICK REPLIES
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: save_sample_ad() — Trigger: wp_ajax_save_sample_ad AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_sample_ad(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('sample_ads');
        $id = (int) Security::post('id', 'int');
        $data = ['category_id' => (int) Security::post('category_id', 'int'), 'title' => Security::post('title'), 'content' => Security::post('content', 'textarea'), 'format_type' => Security::post('format_type') ?: 'classified', 'is_active' => 1];
        if ($id) $db->update($t, $data, ['id' => $id]); else $db->insert($t, $data);
        wp_send_json_success(['message' => 'Sample ad saved']);
    }

    // TRACE: delete_sample_ad() — Trigger: wp_ajax_delete_sample_ad AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_sample_ad(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('sample_ads'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success();
    }

    // TRACE: save_combo() — Trigger: wp_ajax_save_combo AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_combo(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('combo_offers');
        $id = (int) Security::post('id', 'int');
        $data = ['name' => Security::post('name'), 'discount_type' => Security::post('discount_type') ?: 'percentage', 'discount_value' => (float) Security::post('discount_value', 'float'), 'cities' => Security::post('cities') ?: '[]', 'newspapers' => Security::post('newspapers') ?: '[]', 'conditions' => Security::post('conditions', 'textarea'), 'valid_from' => Security::post('valid_from') ?: null, 'valid_to' => Security::post('valid_to') ?: null, 'is_active' => 1];
        if ($id) $db->update($t, $data, ['id' => $id]); else $db->insert($t, $data);
        wp_send_json_success(['message' => 'Combo saved']);
    }

    // TRACE: delete_combo() — Trigger: wp_ajax_delete_combo AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_combo(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('combo_offers'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success();
    }

    // TRACE: save_quick_reply() — Trigger: wp_ajax_save_quick_reply AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_quick_reply(): void {
        self::auth();
        $db = self::db(); $t = $db->prefix('quick_replies');
        $id = (int) Security::post('id', 'int');
        $data = ['title' => Security::post('title'), 'category' => Security::post('category') ?: 'general', 'content' => Security::post('content', 'textarea'), 'sort_order' => (int) Security::post('sort_order', 'int'), 'is_active' => 1];
        if ($id) $db->update($t, $data, ['id' => $id]); else $db->insert($t, $data);
        wp_send_json_success(['message' => 'Quick reply saved']);
    }

    // TRACE: delete_quick_reply() — Trigger: wp_ajax_delete_quick_reply AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public static function delete_quick_reply(): void {
        self::auth();
        $db = self::db();
        $db->update($db->prefix('quick_replies'), ['is_active' => 0], ['id' => (int) Security::post('id', 'int')]);
        wp_send_json_success();
    }

    // ════════════════════════════════════════════════════════════════════════
    // SETTINGS
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: get_settings_array() — Trigger: wp_ajax_get_settings_array AJAX action.
    //        Steps: queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    private static function get_settings_array(): array {
        $db = self::db(); $t = $db->prefix('settings');
        try {
            $rows = $db->select("SELECT setting_key, setting_value FROM `$t`");
            $out  = [];
            foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
            return $out;
        } catch (\Exception $e) {
            \NAS\Core\ErrorLogger::warning('AdminModule settings load failed', ['error'=>$e->getMessage()]);
            return [];
        }
    }

    // TRACE: get_settings() — Trigger: wp_ajax_get_settings AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_settings(): void {
        self::auth();
        wp_send_json_success(['settings' => self::get_settings_array()]);
    }

    // TRACE: save_settings() — Trigger: wp_ajax_save_settings AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_settings(): void {
        self::auth();
        global $wpdb;
        $t = $wpdb->prefix . 'nas_settings';

        // Map POST keys → actual DB column names
        $map = [
            'brand_name'          => Security::post('brand_name',        'text'),
            'brand_name'          => Security::post('brand_name',        'text'),
            'logo_url'            => Security::post('logo_url',          'url'),
            'email_sender_name'   => Security::post('email_sender_name', 'text'),
            'email_sender_addr'   => Security::post('email_sender_address', 'email'),
            'whatsapp_number'     => Security::post('whatsapp_number',   'text'),
            'callmebot_api_key'   => Security::post('whatsapp_api_key',  'textarea'),
            'gst_percentage'      => (float) Security::post('gst_rate',  'float'),
            'currency_symbol'     => Security::post('currency_symbol',   'text'),
            'invoice_prefix'      => Security::post('invoice_prefix',    'text'),
            'cutoff_days'         => (int)   Security::post('cutoff_days','int'),
            'footer_text'         => Security::post('footer_text',       'textarea'),
            'ai_provider'         => Security::post('ai_provider',       'text'),
            'ai_api_key'          => Security::post('ai_api_key',        'textarea'),
            'ai_model'            => Security::post('ai_model',          'text'),
            'smtp_host'           => Security::post('smtp_host',         'text'),
            'smtp_port'           => (int)   Security::post('smtp_port', 'int'),
            'smtp_user'           => Security::post('smtp_user',         'email'),
            'smtp_pass'           => Security::post('smtp_pass',         'textarea'),
            'smtp_encryption'     => Security::post('smtp_encryption',   'text'),
        ];

        // Remove nulls and build update data
        $data = array_filter($map, fn($v) => $v !== null && $v !== false);
        if (empty($data)) { wp_send_json_error(['message' => 'No valid settings to save.']); return; }

        // Check if row exists
        $exists = $wpdb->get_var("SELECT id FROM `$t` LIMIT 1");
        if ($exists) {
            $wpdb->update($t, $data, ['id' => $exists]);
        } else {
            $wpdb->insert($t, $data);
        }

        // Clear Config singleton so next page load picks up new values
        $ref = new \ReflectionClass('\NAS\Core\Config');
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        wp_send_json_success(['message' => '✅ Settings saved successfully!']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ANALYTICS
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: get_analytics() — Trigger: wp_ajax_get_analytics AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_analytics(): void {
        self::auth();
        $days  = (int) (Security::post('days') ?: 30);
        $db    = self::db(); global $wpdb;
        $bt    = $db->prefix('bookings'); $nt = $db->prefix('newspapers');
        $cat   = $db->prefix('categories'); $ct = $db->prefix('clients');
        $from  = date('Y-m-d', strtotime("-{$days} days"));
        $today = current_time('Y-m-d');
        $month = date('Y-m-01');

        $kpis = $wpdb->get_row(
            "SELECT COUNT(*) total, SUM(client_price) revenue, SUM(profit) profit,
                    AVG(client_price) avg_order,
                    SUM(CASE WHEN status IN('booking_received','under_review','ready_to_process','documents_received','quotation_sent','payment_received','material_uploaded','ad_processing','proof_ready','submitted_to_pub') THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) published,
                    SUM(CASE WHEN DATE(submitted_at)=CURDATE() THEN 1 ELSE 0 END) today_count,
                    SUM(CASE WHEN payment_status='pending' THEN total_amount ELSE 0 END) outstanding
             FROM `$bt` WHERE status NOT IN ('draft','rejected')", OBJECT
        );

        $month_rev = $wpdb->get_var("SELECT SUM(client_price) FROM `$bt` WHERE submitted_at >= '$month' AND status NOT IN ('draft','rejected')") ?? 0;

        $trend = $wpdb->get_results(
            "SELECT DATE(submitted_at) day, SUM(client_price) revenue, SUM(profit) profit, COUNT(*) orders
             FROM `$bt` WHERE submitted_at >= '$from' AND status NOT IN ('draft','rejected')
             GROUP BY DATE(submitted_at) ORDER BY day", OBJECT
        );

        $status_breakdown = $wpdb->get_results(
            "SELECT status, COUNT(*) cnt FROM `$bt` WHERE status NOT IN ('draft') GROUP BY status ORDER BY cnt DESC", OBJECT
        );

        $top_newspapers = $wpdb->get_results(
            "SELECT n.name, COUNT(*) bookings, SUM(bk.client_price) revenue
             FROM `$bt` bk LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             WHERE bk.submitted_at >= '$from'
             GROUP BY bk.newspaper_id ORDER BY bookings DESC LIMIT 5", OBJECT
        );

        $top_categories = $wpdb->get_results(
            "SELECT cat.name, COUNT(*) bookings FROM `$bt` bk LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             WHERE bk.submitted_at >= '$from' GROUP BY bk.category_id ORDER BY bookings DESC LIMIT 5", OBJECT
        );

        $total_clients  = $wpdb->get_var("SELECT COUNT(*) FROM `$ct`") ?? 0;
        $total_papers   = $wpdb->get_var("SELECT COUNT(*) FROM `$nt` WHERE is_active=1") ?? 0;

        // ── NEW: 10+ Additional Analytics ──────────────────────────────────
        $vt  = $db->prefix('vendors');
        $ct2 = $db->prefix('cities');

        // 1. Conversion rate: bookings that reached payment vs total submitted
        $total_submitted  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$bt`") ?: 1;
        $total_paid       = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$bt` WHERE payment_status='paid'");
        $conversion_rate  = round(($total_paid / $total_submitted) * 100, 1);

        // 2. Total leads generated (unique clients who submitted at least one booking)
        $total_leads = (int)$wpdb->get_var("SELECT COUNT(DISTINCT client_id) FROM `$bt` WHERE status NOT IN('draft')");

        // 3. Top-performing cities by revenue
        $top_cities = $wpdb->get_results(
            "SELECT ci.name city, COUNT(*) bookings, SUM(bk.client_price) revenue, SUM(bk.profit) profit
             FROM `$bt` bk LEFT JOIN `$ct2` ci ON ci.id=bk.city_id
             WHERE bk.submitted_at >= '$from' AND bk.status NOT IN('draft','rejected')
             GROUP BY bk.city_id ORDER BY revenue DESC LIMIT 8", OBJECT
        );

        // 4. Most viewed ad categories (by booking volume)
        $top_cats_full = $wpdb->get_results(
            "SELECT cat.name, COUNT(*) bookings, SUM(bk.client_price) revenue,
                    ROUND(AVG(bk.client_price),2) avg_price
             FROM `$bt` bk LEFT JOIN `$cat` cat ON cat.id=bk.category_id
             WHERE bk.submitted_at >= '$from'
             GROUP BY bk.category_id ORDER BY bookings DESC LIMIT 8", OBJECT
        );

        // 5. Vendor-wise performance comparison
        $vendor_perf = $wpdb->get_results(
            "SELECT v.name vendor, COUNT(*) assigned, 
                    SUM(CASE WHEN bk.status='published' THEN 1 ELSE 0 END) published,
                    SUM(bk.vendor_cost) total_cost,
                    ROUND(AVG(bk.vendor_cost),2) avg_cost
             FROM `$bt` bk LEFT JOIN `$vt` v ON v.id=bk.assigned_vendor_id
             WHERE bk.assigned_vendor_id > 0 AND bk.submitted_at >= '$from'
             GROUP BY bk.assigned_vendor_id ORDER BY published DESC LIMIT 8", OBJECT
        );

        // 6. Revenue by ad type (Classified vs Display vs DC)
        $revenue_by_type = $wpdb->get_results(
            "SELECT ad_type, COUNT(*) bookings, SUM(client_price) revenue, ROUND(AVG(client_price),2) avg_price
             FROM `$bt` WHERE status NOT IN('draft','rejected') AND submitted_at >= '$from'
             GROUP BY ad_type ORDER BY revenue DESC", OBJECT
        );

        // 7. Monthly revenue trend (last 12 months)
        $monthly_trend = $wpdb->get_results(
            "SELECT DATE_FORMAT(submitted_at,'%Y-%m') month_key,
                    DATE_FORMAT(submitted_at,'%b %Y') month_label,
                    COUNT(*) bookings, SUM(client_price) revenue, SUM(profit) profit
             FROM `$bt` WHERE status NOT IN('draft','rejected')
             AND submitted_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY month_key ORDER BY month_key", OBJECT
        );

        // 8. Average order value trend (weekly)
        $aov_trend = $wpdb->get_results(
            "SELECT YEARWEEK(submitted_at,1) wk,
                    MIN(DATE(submitted_at)) week_start,
                    ROUND(AVG(client_price),2) aov,
                    COUNT(*) orders
             FROM `$bt` WHERE status NOT IN('draft','rejected') AND submitted_at >= '$from'
             GROUP BY wk ORDER BY wk", OBJECT
        );

        // 9. Repeat clients (clients with >1 booking)
        $repeat_clients = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM (
               SELECT client_id FROM `$bt` WHERE status NOT IN('draft')
               GROUP BY client_id HAVING COUNT(*) > 1
             ) t"
        );
        $repeat_rate = $total_leads > 0 ? round(($repeat_clients / $total_leads) * 100, 1) : 0;

        // 10. Payment outstanding breakdown (overdue by age)
        $outstanding_aging = $wpdb->get_results(
            "SELECT
               SUM(CASE WHEN DATEDIFF(NOW(), submitted_at) <= 7 THEN client_price ELSE 0 END) age_0_7,
               SUM(CASE WHEN DATEDIFF(NOW(), submitted_at) BETWEEN 8 AND 30 THEN client_price ELSE 0 END) age_8_30,
               SUM(CASE WHEN DATEDIFF(NOW(), submitted_at) > 30 THEN client_price ELSE 0 END) age_30plus,
               COUNT(CASE WHEN DATEDIFF(NOW(), submitted_at) <= 7 THEN 1 END) cnt_0_7,
               COUNT(CASE WHEN DATEDIFF(NOW(), submitted_at) BETWEEN 8 AND 30 THEN 1 END) cnt_8_30,
               COUNT(CASE WHEN DATEDIFF(NOW(), submitted_at) > 30 THEN 1 END) cnt_30plus
             FROM `$bt` WHERE payment_status IN('pending','partial') AND status NOT IN('draft','rejected')",
            OBJECT
        );

        // 11. Top clients by lifetime value
        $top_clients = $wpdb->get_results(
            "SELECT cl.name client_name, cl.email, COUNT(*) bookings,
                    SUM(bk.client_price) lifetime_value, MAX(bk.submitted_at) last_booking
             FROM `$bt` bk LEFT JOIN `$ct` cl ON cl.id=bk.client_id
             WHERE bk.status NOT IN('draft','rejected')
             GROUP BY bk.client_id ORDER BY lifetime_value DESC LIMIT 8", OBJECT
        );

        // 12. Newspaper efficiency (revenue per booking)
        $newspaper_eff = $wpdb->get_results(
            "SELECT n.name, COUNT(*) bookings,
                    ROUND(AVG(bk.client_price),2) avg_order,
                    ROUND(AVG(bk.profit),2) avg_profit,
                    ROUND(AVG(CASE WHEN bk.client_price>0 THEN (bk.profit/bk.client_price)*100 ELSE 0 END),1) avg_margin
             FROM `$bt` bk LEFT JOIN `$nt` n ON n.id=bk.newspaper_id
             WHERE bk.submitted_at >= '$from' AND bk.status NOT IN('draft','rejected')
             GROUP BY bk.newspaper_id HAVING bookings >= 1 ORDER BY avg_margin DESC LIMIT 8", OBJECT
        );

        // 13. Cancellation/rejection rate
        $total_cancelled = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$bt` WHERE status IN('rejected','cancelled')");
        $rejection_rate  = round(($total_cancelled / $total_submitted) * 100, 1);

        wp_send_json_success(compact(
            'kpis','month_rev','trend','status_breakdown','top_newspapers','top_categories','total_clients','total_papers',
            // New metrics
            'conversion_rate','total_leads','top_cities','top_cats_full','vendor_perf',
            'revenue_by_type','monthly_trend','aov_trend','repeat_clients','repeat_rate',
            'outstanding_aging','top_clients','newspaper_eff','rejection_rate'
        ));
    }

    // ════════════════════════════════════════════════════════════════════════
    // DATA MANAGER — Bulk Import
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: bulk_import() — Trigger: wp_ajax_bulk_import AJAX action.
    //        Steps: reads sanitised POST input → inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function bulk_import(): void {
        self::auth();
        $type = Security::post('import_type');
        $csv  = Security::post('csv_data', 'textarea');
        if (!$csv) wp_send_json_error(['message' => 'No CSV data provided']);

        $db    = self::db(); global $wpdb;
        $lines = explode("\n", trim($csv));
        $header = str_getcsv(array_shift($lines));
        $count = 0; $errors = [];

        foreach ($lines as $i => $line) {
            if (!trim($line)) continue;
            $row = array_combine($header, str_getcsv($line));
            if (!$row) continue;
            try {
                switch ($type) {
                    case 'cities':
                        $inserted = $db->insert($db->prefix('cities'), [
                            'name'       => sanitize_text_field($row['name'] ?? ''),
                            'slug'       => sanitize_title($row['name'] ?? ''),
                            'state'      => sanitize_text_field($row['state'] ?? ''),
                            'tier'       => (int)($row['tier'] ?? 3),
                            'population' => (int)($row['population'] ?? 0),
                            'is_active'  => 1,
                        ]);
                        if ( $inserted ) { $count++; } else { $errors[] = "Row " . ($i+1) . ": city insert failed."; }
                        break;
                    case 'newspapers':
                        $inserted = $db->insert($db->prefix('newspapers'), [
                            'name'                 => sanitize_text_field($row['name'] ?? ''),
                            'slug'                 => sanitize_title($row['name'] ?? ''),
                            'language'             => sanitize_text_field($row['language'] ?? 'English'),
                            'base_rate_classified' => (float)($row['rate_classified'] ?? 0),
                            'base_rate_display'    => (float)($row['rate_display'] ?? 0),
                            'min_charge'           => (float)($row['min_charge'] ?? 0),
                            'circulation'          => (int)($row['circulation'] ?? 0),
                            'editions'             => wp_json_encode(array_filter(explode('|', $row['editions'] ?? ''))),
                            'cities_supported'     => '[]',
                            'is_active'            => 1,
                        ]);
                        if ( $inserted ) { $count++; } else { $errors[] = "Row " . ($i+1) . ": newspaper insert failed."; }
                        break;
                    case 'templates':
                        $cat = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$db->prefix('categories')}` WHERE name=%s LIMIT 1", $row['category'] ?? ''));
                        // FIX (audit): same column bug as save_template() — templates table uses
                        // 'name'/'content', not 'template_name'/'template_content'. This insert
                        // was failing silently on every row (unknown column), while $count still
                        // incremented below regardless, reporting successful imports that never
                        // actually wrote to the database.
                        $inserted = $db->insert($db->prefix('templates'), [
                            'name'             => sanitize_text_field($row['name'] ?? ''),
                            'category_id'      => (int)$cat,
                            'tone'             => sanitize_text_field($row['tone'] ?? 'formal'),
                            'word_limit'       => (int)($row['word_limit'] ?? 60),
                            'content'          => sanitize_textarea_field($row['content'] ?? ''),
                            'is_active'        => 1,
                        ]);
                        if ( $inserted ) { $count++; } else { $errors[] = "Row " . ($i+1) . ": template insert failed."; }
                        break;
                }
            } catch (\Exception $e) {
                $errors[] = "Row " . ($i+1) . ": " . $e->getMessage();
            }
        }
        wp_send_json_success(['imported' => $count, 'errors' => $errors, 'message' => "Successfully imported $count records."]);
    }

    // TRACE: run_seeder() — Trigger: wp_ajax_run_seeder AJAX action.
    //        Steps: returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function run_seeder(): void {
        self::auth();
        try {
            \NAS\Database\Seeder::run();
            wp_send_json_success(['message' => 'Seeder ran successfully. All default data has been populated.']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Seeder error: ' . $e->getMessage()]);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // CLIENTS
    // ════════════════════════════════════════════════════════════════════════

    // TRACE: get_clients() — Trigger: wp_ajax_get_clients AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_clients(): void {
        self::auth();
        global $wpdb;
        $db = self::db(); $ct = $db->prefix('clients'); $bt = $db->prefix('bookings');
        $clients = $wpdb->get_results(
            "SELECT c.*, COUNT(b.id) order_count, COALESCE(SUM(b.total_amount),0) total_spent_real
             FROM `$ct` c LEFT JOIN `$bt` b ON b.client_id=c.id
             GROUP BY c.id ORDER BY c.created_at DESC LIMIT 200", OBJECT
        );
        wp_send_json_success($clients);
    }

    // TRACE: get_client_detail() — Trigger: wp_ajax_get_client_detail AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_client_detail(): void {
        self::auth();
        $id = (int) Security::post('id', 'int');
        $db = self::db(); global $wpdb;
        $ct = $db->prefix('clients'); $bt = $db->prefix('bookings');
        $nt = $db->prefix('newspapers'); $cat = $db->prefix('categories'); $cit = $db->prefix('cities');
        $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$ct` WHERE id=%d", $id), OBJECT);
        if (!$client) wp_send_json_error(['message' => 'Client not found']);
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, n.name np_name, cat.name cat_name, cit.name city_name
             FROM `$bt` b
             LEFT JOIN `$nt` n ON n.id=b.newspaper_id
             LEFT JOIN `$cat` cat ON cat.id=b.category_id
             LEFT JOIN `$cit` cit ON cit.id=b.city_id
             WHERE b.client_id=%d ORDER BY b.submitted_at DESC", $id
        ), OBJECT);
        wp_send_json_success(['client' => $client, 'bookings' => $bookings]);
    }
}

// ── Client Management AJAX (appended) ────────────────────────────────────────

add_action('wp_ajax_nas_admin_update_client',         'NAS\\Modules\\Admin\\nas_admin_update_client_handler');
add_action('wp_ajax_nas_admin_send_password_reset',   'NAS\\Modules\\Admin\\nas_admin_send_reset_handler');
add_action('wp_ajax_nas_admin_get_client_wallet',     'NAS\\Modules\\Admin\\nas_admin_get_client_wallet_handler');
add_action('wp_ajax_nas_admin_get_client_notes',      'NAS\\Modules\\Admin\\nas_admin_get_client_notes_handler');
add_action('wp_ajax_nas_admin_save_client_notes',     'NAS\\Modules\\Admin\\nas_admin_save_client_notes_handler');

// TRACE: nas_admin_update_client_handler() — Trigger: wp_ajax_nas_admin_update_client_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_update_client_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $db = \NAS\Core\Database::instance();
    $id = (int)\NAS\Core\Security::post('client_id');
    if (!$id) { wp_send_json_error(['message'=>'Invalid client ID']); }
    $data = [
        'name'         => sanitize_text_field(\NAS\Core\Security::post('name')),
        'phone'        => sanitize_text_field(\NAS\Core\Security::post('phone')),
        'email'        => sanitize_email(\NAS\Core\Security::post('email')),
        'city'         => sanitize_text_field(\NAS\Core\Security::post('city')),
        'company_name' => sanitize_text_field(\NAS\Core\Security::post('company_name')),
        'gst_number'   => strtoupper(sanitize_text_field(\NAS\Core\Security::post('gst_number'))),
        'pan_number'   => strtoupper(sanitize_text_field(\NAS\Core\Security::post('pan_number'))),
        'address'      => sanitize_textarea_field(\NAS\Core\Security::post('address')),
        'updated_at'   => gmdate('Y-m-d H:i:s'),
    ];
    $db->update($db->t('clients'), $data, ['id' => $id]);
    // Sync email to WP user if linked
    $client = $db->row("SELECT wp_user_id FROM {$db->t('clients')} WHERE id=%d", $id);
    if ($client && $client['wp_user_id'] && $data['email']) {
        wp_update_user(['ID' => (int)$client['wp_user_id'], 'user_email' => $data['email']]);
    }
    wp_send_json_success(['message' => 'Client profile updated.']);
}

// TRACE: nas_admin_send_reset_handler() — Trigger: wp_ajax_nas_admin_send_reset_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_send_reset_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $email = sanitize_email(\NAS\Core\Security::post('email'));
    $user  = get_user_by('email', $email);
    if (!$user) { wp_send_json_error(['message'=>'No WordPress user found with this email.']); }
    $key = get_password_reset_key($user);
    if (is_wp_error($key)) { wp_send_json_error(['message'=>$key->get_error_message()]); }
    $reset_link = add_query_arg(['step'=>'reset','key'=>$key,'login'=>rawurlencode($user->user_login)], home_url('/newspaper-ad-login/'));
    // FIX (audit): wp_mail()'s return value wasn't checked — this reported success even if the
    // mail server rejected the message. The sibling reset_team_pwd() function (dashboards/
    // AdminDashboard.php) already correctly checks this same call; this one didn't.
    $sent = wp_mail($email, 'Reset Your Password — '.\NAS\Core\Config::instance()->get('brand_name',get_bloginfo('name')),
        "Hi {$user->display_name},\n\nReset your password:\n{$reset_link}\n\nLink expires in 24 hours.");
    if ( ! $sent ) { wp_send_json_error(['message'=>'Could not send reset email. Check SMTP settings.']); return; }
    wp_send_json_success(['message'=>'Password reset email sent.']);
}

// TRACE: nas_admin_get_client_wallet_handler() — Trigger: wp_ajax_nas_admin_get_client_wallet_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_get_client_wallet_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $client_id = (int)\NAS\Core\Security::post('client_id');
    $db = \NAS\Core\Database::instance();
    $wallet = $db->row("SELECT balance FROM {$db->t('wallet')} WHERE client_id=%d", $client_id);
    $txns   = $db->select("SELECT * FROM {$db->t('wallet_transactions')} WHERE client_id=%d ORDER BY created_at DESC LIMIT 30", $client_id);
    wp_send_json_success(['balance'=>(float)($wallet['balance']??0), 'transactions'=>$txns]);
}

// TRACE: nas_admin_get_client_notes_handler() — Trigger: wp_ajax_nas_admin_get_client_notes_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_get_client_notes_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $db = \NAS\Core\Database::instance();
    $client = $db->row("SELECT admin_notes FROM {$db->t('clients')} WHERE id=%d", (int)\NAS\Core\Security::post('client_id'));
    wp_send_json_success(['notes' => $client['admin_notes'] ?? '']);
}

// TRACE: nas_admin_save_client_notes_handler() — Trigger: wp_ajax_nas_admin_save_client_notes_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → updates DB row.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_save_client_notes_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $db = \NAS\Core\Database::instance();
    $db->update($db->t('clients'), ['admin_notes' => sanitize_textarea_field(\NAS\Core\Security::post('notes'))], ['id' => (int)\NAS\Core\Security::post('client_id')]);
    wp_send_json_success(['message'=>'Notes saved.']);
}

// ── Bookings CSV Export ───────────────────────────────────────────────────────
add_action('wp_ajax_nas_admin_export_bookings_csv', 'NAS\\Modules\\Admin\\nas_admin_export_bookings_csv_handler');
// TRACE: nas_admin_export_bookings_csv_handler() — Trigger: wp_ajax_nas_admin_export_bookings_csv_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_export_bookings_csv_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');

    $db        = \NAS\Core\Database::instance();
    $date_from = sanitize_text_field($_POST['date_from'] ?? '');
    $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

    $where = '1=1';
    $args  = [];
    if ($date_from) { $where .= ' AND DATE(b.submitted_at) >= %s'; $args[] = $date_from; }
    if ($date_to)   { $where .= ' AND DATE(b.submitted_at) <= %s'; $args[] = $date_to;   }

    $bookings = $db->select(
        "SELECT b.uid, b.client_name, cl.email AS client_email, cl.phone AS client_phone,
                b.city_name, b.newspaper_name, b.category_id, b.ad_type, b.total_amount,
                b.status, b.submitted_at, b.publish_date
         FROM {$db->t('bookings')} b
         LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
         WHERE $where ORDER BY b.submitted_at DESC LIMIT 5000",
        $args
    );

    $headers = ['Order ID','Client Name','Email','Phone','City','Newspaper','Category','Ad Type','Amount','Status','Submitted','Publish Date'];
    $rows    = [$headers];
    foreach ($bookings as $b) {
        $rows[] = [
            $b['uid'] ?? '', $b['client_name'] ?? '', $b['client_email'] ?? '',
            $b['client_phone'] ?? '', $b['city_name'] ?? '', $b['newspaper_name'] ?? '',
            $b['category_id'] ?? '', $b['ad_type'] ?? '',
            number_format((float)($b['total_amount'] ?? 0), 2),
            $b['status'] ?? '', $b['submitted_at'] ?? '', $b['publish_date'] ?? '',
        ];
    }

    $csv = implode("\n", array_map(function($r) {
        return implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"', $r));
    }, $rows));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nas-bookings-' . date('Y-m-d') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// ── Vendor Payout Management AJAX handlers ────────────────────────────────────
add_action('wp_ajax_nas_admin_get_vendor_payout_bookings', 'NAS\\Modules\\Admin\\nas_admin_vendor_payout_bookings_handler');
add_action('wp_ajax_nas_admin_mark_vendor_all_paid',       'NAS\\Modules\\Admin\\nas_admin_mark_vendor_all_paid_handler');
add_action('wp_ajax_nas_admin_record_vendor_payment',      'NAS\\Modules\\Admin\\nas_admin_record_vendor_payment_handler');
add_action('wp_ajax_nas_admin_vendor_statement',           'NAS\\Modules\\Admin\\nas_admin_vendor_statement_handler');
add_action('wp_ajax_nas_admin_get_calendar_bookings',      'NAS\\Modules\\Admin\\nas_admin_calendar_bookings_handler');

// TRACE: nas_admin_vendor_payout_bookings_handler() — Trigger: wp_ajax_nas_admin_vendor_payout_bookings_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_vendor_payout_bookings_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $db        = \NAS\Core\Database::instance();
    $vendor_id = (int)\NAS\Core\Security::post('vendor_id');
    $bookings  = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.newspaper_name,b.city_name,b.vendor_cost,b.vendor_payment_status,b.status,b.submitted_at
         FROM {$db->t('bookings')} b
         WHERE b.assigned_vendor_id=%d AND b.vendor_cost>0 AND b.status NOT IN ('rejected','cancelled')
         ORDER BY b.submitted_at DESC LIMIT 100",
        $vendor_id
    );
    wp_send_json_success(['bookings'=>$bookings]);
}

// TRACE: nas_admin_mark_vendor_all_paid_handler() — Trigger: wp_ajax_nas_admin_mark_vendor_all_paid_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → returns JSON success.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_mark_vendor_all_paid_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_admin_nonce');
    \NAS\Core\Security::require_cap('manage_options');
    $db        = \NAS\Core\Database::instance();
    $vendor_id = (int)\NAS\Core\Security::post('vendor_id');
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$db->t('bookings')} SET vendor_payment_status='paid',vendor_payment_amount=vendor_cost WHERE assigned_vendor_id=%d AND vendor_payment_status='pending'",$vendor_id));
    wp_send_json_success(['message'=>'All bookings marked as paid.']);
}

// TRACE: nas_admin_record_vendor_payment_handler() — Trigger: wp_ajax_nas_admin_record_vendor_payment_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_record_vendor_payment_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_admin_nonce');
    \NAS\Core\Security::require_cap('manage_options');
    $db            = \NAS\Core\Database::instance();
    $vendor_id     = (int)\NAS\Core\Security::post('vendor_id');
    $amount        = (float)\NAS\Core\Security::post('amount');
    $payment_ref   = sanitize_text_field(\NAS\Core\Security::post('payment_ref'));
    $payment_method= sanitize_text_field(\NAS\Core\Security::post('payment_method'));
    if ($amount<=0) wp_send_json_error(['message'=>'Invalid amount.']);

    // FIX (audit, per user decision): fold in any existing credit balance from a prior
    // overpayment before applying this new payment, so accumulated credit actually gets used
    // down over time rather than sitting untouched while new cash payments are recorded.
    $vendor = $db->row("SELECT vendor_credit_balance FROM {$db->t('vendors')} WHERE id=%d", $vendor_id);
    $credit = (float) ($vendor['vendor_credit_balance'] ?? 0);

    // Mark bookings as paid up to the specified amount (FIFO)
    $pending = $db->select(
        "SELECT id,vendor_cost FROM {$db->t('bookings')} WHERE assigned_vendor_id=%d AND vendor_payment_status='pending' AND vendor_cost>0 ORDER BY submitted_at ASC",
        $vendor_id
    );
    $remaining = $amount + $credit;
    foreach ($pending as $b) {
        if ($remaining <= 0) break;
        $cost = (float)$b['vendor_cost'];
        if ($cost <= $remaining) {
            $db->update($db->t('bookings'),['vendor_payment_status'=>'paid','vendor_payment_amount'=>$cost,'payment_ref'=>$payment_ref],['id'=>(int)$b['id']]);
            $remaining -= $cost;
        }
    }
    // FIX (audit): whatever couldn't be applied to a whole pending booking (including any
    // portion of the pre-existing credit) is now stored back as the vendor's credit balance,
    // instead of being silently dropped.
    $db->update($db->t('vendors'), ['vendor_credit_balance' => $remaining], ['id' => $vendor_id]);

    wp_send_json_success(['message'=>'Payment of '.number_format($amount,2).' recorded. Credit balance: '.number_format($remaining,2).'.']);
}

// TRACE: nas_admin_vendor_statement_handler() — Trigger: wp_ajax_nas_admin_vendor_statement_handler file-scope handler.
//        Steps: verifies nonce → checks cap → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_vendor_statement_handler(): void {
    if (!wp_verify_nonce($_GET['nonce']??'','nas_action') || !current_user_can('nas_manage_bookings')) wp_die('Unauthorized');
    $db        = \NAS\Core\Database::instance();
    $vendor_id = (int)($_GET['vendor_id']??0);
    $vendor    = $db->row("SELECT * FROM {$db->t('vendors')} WHERE id=%d", $vendor_id);
    if (!$vendor) wp_die('Vendor not found');
    $bookings  = $db->select("SELECT * FROM {$db->t('bookings')} WHERE assigned_vendor_id=%d AND vendor_cost>0 ORDER BY submitted_at DESC LIMIT 200",$vendor_id);
    $total_due = array_sum(array_map(fn($b)=>(float)$b['vendor_cost'],array_filter($bookings,fn($b)=>$b['vendor_payment_status']==='pending')));
    $total_paid= array_sum(array_map(fn($b)=>(float)$b['vendor_cost'],array_filter($bookings,fn($b)=>$b['vendor_payment_status']==='paid')));
    $brand     = \NAS\Core\Config::instance()->get('brand_name',get_bloginfo('name'));
    $sym       = \NAS\Core\Config::instance()->get('currency_symbol','₹');
    $rows_html = '';
    foreach ($bookings as $b) {
        $payStatus = $b['vendor_payment_status']==='paid' ? '<span style="color:#059669;font-weight:700">✅ Paid</span>' : '<span style="color:#dc2626;font-weight:700">⏳ Pending</span>';
        $rows_html .= "<tr><td style='padding:8px 12px;border:1px solid #e2e8f0'>{$b['uid']}</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>".htmlspecialchars($b['client_name']??'')."</td><td style='padding:8px 12px;border:1px solid #e2e8f0'>".htmlspecialchars($b['newspaper_name']??'')."</td><td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:right'>{$sym}".number_format((float)$b['vendor_cost'],2)."</td><td style='padding:8px 12px;border:1px solid #e2e8f0;text-align:center'>{$payStatus}</td></tr>";
    }
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Vendor Statement</title></head><body style='font-family:Arial,sans-serif;padding:30px;color:#1e293b'>
    <h2 style='color:#6c47ff'>{$brand}</h2><h3>Vendor Payment Statement</h3>
    <p><strong>Vendor:</strong> {$vendor['name']}<br><strong>Phone:</strong> {$vendor['phone']}<br><strong>Period:</strong> All time<br><strong>Generated:</strong> ".date('d M Y')."</p>
    <table style='width:100%;border-collapse:collapse;margin:16px 0'>
    <thead><tr style='background:#6c47ff;color:#fff'><th style='padding:10px 12px;text-align:left'>Order ID</th><th style='padding:10px 12px'>Client</th><th style='padding:10px 12px'>Newspaper</th><th style='padding:10px 12px;text-align:right'>Amount</th><th style='padding:10px 12px'>Payment</th></tr></thead>
    <tbody>{$rows_html}</tbody></table>
    <table style='float:right;border-collapse:collapse;margin-top:8px'>
    <tr><td style='padding:6px 12px'>Total Paid</td><td style='padding:6px 12px;text-align:right;color:#059669;font-weight:700'>{$sym}".number_format($total_paid,2)."</td></tr>
    <tr><td style='padding:6px 12px'>Outstanding</td><td style='padding:6px 12px;text-align:right;color:#dc2626;font-weight:700'>{$sym}".number_format($total_due,2)."</td></tr>
    </table></body></html>";
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="vendor-statement-{$vendor_id}.html"');
    echo $html;
    exit;
}

// TRACE: nas_admin_calendar_bookings_handler() — Trigger: wp_ajax_nas_admin_calendar_bookings_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_calendar_bookings_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_action');
    \NAS\Core\Security::require_cap('nas_manage_bookings');
    $db        = \NAS\Core\Database::instance();
    $date_from = sanitize_text_field(\NAS\Core\Security::post('date_from'));
    $date_to   = sanitize_text_field(\NAS\Core\Security::post('date_to'));
    $bookings  = $db->select(
        "SELECT b.id,b.uid,b.client_name,b.client_id,b.newspaper_name,b.city_name,b.status,b.publish_date,b.submitted_at,b.total_amount
         FROM {$db->t('bookings')} b
         WHERE (b.publish_date BETWEEN %s AND %s OR (b.publish_date IS NULL AND DATE(b.submitted_at) BETWEEN %s AND %s))
         ORDER BY COALESCE(b.publish_date,DATE(b.submitted_at)) ASC LIMIT 500",
        $date_from,$date_to,$date_from,$date_to
    );
    $admin_url = nas_get_page_url('nas_page_admin_dashboard','/admin-dashboard/');
    wp_send_json_success(['bookings'=>$bookings,'adminUrl'=>$admin_url]);
}

// FIX (audit): 11 standalone nas_admin_*_handler() functions below previously checked
// Security::check_nonce($nonce, 'nas_action'), but their callers (clients.php, vendor-payouts.php,
// analytics.php — verified individually by checking each page's actual nonce source) all read
// their nonce from the shared nas-admin-config script tag (templates/admin/layout.php:47), which
// creates it with action 'nas_admin_nonce'. Every one of these 11 actions has been failing with a
// 403 on every call. Deliberately did NOT change nas_admin_calendar_bookings_handler — verified
// calendar.php creates its own separate local nonce with action 'nas_action', so that one was
// already correct. Do not "fix" it to match the others without re-checking calendar.php first.
// NOTE: nas_admin_get_tickets is intentionally NOT registered here.
// It is the canonical responsibility of SupportTicketModule::TicketController::admin_list.
// Registering it here a second time would create a double-handler conflict.
add_action('wp_ajax_nas_admin_credit_wallet', 'NAS\\Modules\\Admin\\nas_admin_credit_wallet_handler');
add_action('wp_ajax_nas_admin_debit_wallet',  'NAS\\Modules\\Admin\\nas_admin_debit_wallet_handler');

// TRACE: nas_admin_credit_wallet_handler() — Trigger: wp_ajax_nas_admin_credit_wallet_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → updates DB row.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_credit_wallet_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_admin_nonce');
    \NAS\Core\Security::require_cap('manage_options');
    $client_id  = (int)\NAS\Core\Security::post('client_id');
    $amount     = (float)\NAS\Core\Security::post('amount');
    $desc       = sanitize_text_field(\NAS\Core\Security::post('description') ?: 'Admin credit');
    if (!$client_id || $amount <= 0) wp_send_json_error(['message'=>'Invalid input.']);
    $db = \NAS\Core\Database::instance();
    // Upsert wallet balance
    global $wpdb;
    $wallet = $db->row("SELECT id,balance FROM {$db->t('wallet')} WHERE client_id=%d", $client_id);
    if ($wallet) {
        $db->update($db->t('wallet'), ['balance' => (float)$wallet['balance'] + $amount], ['client_id'=>$client_id]);
    } else {
        $db->insert($db->t('wallet'), ['client_id'=>$client_id,'balance'=>$amount,'currency'=>'INR','updated_at'=>gmdate('Y-m-d H:i:s')]);
    }
    // Log transaction
    $db->insert($db->t('wallet_transactions'), [
        'client_id'=>$client_id,'type'=>'credit','amount'=>$amount,
        'description'=>$desc,'created_at'=>gmdate('Y-m-d H:i:s'),
    ]);
    wp_send_json_success(['message'=>'₹'.number_format($amount,2).' credited successfully.']);
}

// TRACE: nas_admin_debit_wallet_handler() — Trigger: wp_ajax_nas_admin_debit_wallet_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → updates DB row.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_debit_wallet_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'),'nas_admin_nonce');
    \NAS\Core\Security::require_cap('manage_options');
    $client_id = (int)\NAS\Core\Security::post('client_id');
    $amount    = (float)\NAS\Core\Security::post('amount');
    $desc      = sanitize_text_field(\NAS\Core\Security::post('description') ?: 'Admin debit');
    if (!$client_id || $amount <= 0) wp_send_json_error(['message'=>'Invalid input.']);
    $db     = \NAS\Core\Database::instance();
    $wallet = $db->row("SELECT id,balance FROM {$db->t('wallet')} WHERE client_id=%d", $client_id);
    $bal    = (float)($wallet['balance'] ?? 0);
    if ($amount > $bal) wp_send_json_error(['message'=>'Insufficient wallet balance (₹'.number_format($bal,2).' available).']);
    $db->update($db->t('wallet'), ['balance' => $bal - $amount], ['client_id'=>$client_id]);
    $db->insert($db->t('wallet_transactions'), [
        'client_id'=>$client_id,'type'=>'debit','amount'=>$amount,
        'description'=>$desc,'created_at'=>gmdate('Y-m-d H:i:s'),
    ]);
    wp_send_json_success(['message'=>'₹'.number_format($amount,2).' debited successfully.']);
}

// ── Multi-channel client message sender ──────────────────────────────────────
add_action('wp_ajax_nas_admin_send_client_message', 'NAS\\Modules\\Admin\\nas_admin_send_client_message_handler');
// TRACE: nas_admin_send_client_message_handler() — Trigger: wp_ajax_nas_admin_send_client_message_handler file-scope handler.
//        Steps: verifies nonce → checks cap → reads POST input → queries DB.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_admin_send_client_message_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
    \NAS\Core\Security::require_cap('nas_manage_bookings');

    $booking_id = (int)\NAS\Core\Security::post('booking_id');
    $message    = sanitize_textarea_field(\NAS\Core\Security::post('message'));
    $channel    = sanitize_key(\NAS\Core\Security::post('channel', 'email'));

    if (!$booking_id || !$message) wp_send_json_error(['message' => 'Missing booking ID or message.']);
    if (!in_array($channel, ['email','sms'], true)) wp_send_json_error(['message' => 'Invalid channel.']);

    $db      = \NAS\Core\Database::instance();
    $booking = $db->row(
        "SELECT b.*,cl.name AS client_name,cl.email AS client_email,cl.phone AS client_phone
         FROM {$db->t('bookings')} b
         LEFT JOIN {$db->t('clients')} cl ON cl.id=b.client_id
         WHERE b.id=%d", $booking_id
    );
    if (!$booking) wp_send_json_error(['message' => 'Booking not found.']);

    $cfg   = \NAS\Core\Config::instance();
    $brand = $cfg->get('brand_name', get_bloginfo('name'));
    $sent  = false;

    if ($channel === 'email') {
        $email = sanitize_email($booking['client_email'] ?? '');
        if (!$email) wp_send_json_error(['message' => 'No email address for this client.']);

        $subject = "[{$brand}] Message about your booking #{$booking['uid']}";
        $body    = "Hi {$booking['client_name']},\n\n{$message}\n\nBooking Reference: #{$booking['uid']}\n\n— {$brand} Team";
        $sent    = wp_mail($email, $subject, $body);

        if (!$sent) wp_send_json_error(['message' => 'Email delivery failed. Check SMTP settings.']);
    }

    if ($channel === 'sms') {
        $sms_api_key  = $cfg->get('sms_api_key', '');   // admin can set via Settings → SMS API Key
        $sms_sender   = $cfg->get('sms_sender_id', 'NASADS');
        $phone        = preg_replace('/\D/', '', $booking['client_phone'] ?? '');
        if ( ! $phone ) wp_send_json_error(['message' => 'No phone number for this client.']);

        if ( $sms_api_key && strlen($phone) >= 10 ) {
            // MSG91 REST API
            $response = wp_remote_post( 'https://api.msg91.com/api/v5/flow/', [
                'headers' => [
                    'authkey'      => $sms_api_key,
                    'Content-Type' => 'application/json',
                ],
                'body'    => json_encode([
                    'sender'   => $sms_sender,
                    'route'    => '4',
                    'country'  => '91',
                    'sms'      => [[
                        'message' => substr($message, 0, 160),
                        'to'      => [ '91' . substr($phone, -10) ],
                    ]],
                ]),
                'timeout' => 10,
            ]);
            $sent = ! is_wp_error($response) && in_array( wp_remote_retrieve_response_code($response), [200, 202], true );
            if ( ! $sent ) {
                wp_send_json_error(['message' => 'SMS delivery failed. Check SMS API key in Settings or try email instead.']);
            }
        } else {
            // No SMS provider configured — tell the admin clearly instead of silently logging
            wp_send_json_error(['message' => 'SMS provider not configured. Add your MSG91 API key in Settings → Integrations, or use Email to contact the client.']);
        }
    }

    // Log message in platform chat regardless of channel
    $db->insert($db->t('messages'), [
        'booking_id'  => $booking_id,
        'sender_id'   => get_current_user_id(),
        'sender_name' => wp_get_current_user()->display_name,
        'sender_role' => 'admin',
        'message'     => "[{$channel}] {$message}",
        'channel'     => $channel,
        'created_at'  => gmdate('Y-m-d H:i:s'),
    ]);

    wp_send_json_success(['message' => ucfirst($channel).' sent successfully.', 'channel' => $channel]);
}
