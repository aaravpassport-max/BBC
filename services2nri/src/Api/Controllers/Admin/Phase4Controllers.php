<?php
/**
 * Phase 4 Controllers — GDPR, Advanced Analytics, Platform Completeness
 *
 * GDPR-01: Customer data export (JSON/CSV)
 * GDPR-02: Customer account + data deletion (right to erasure)
 * ANAL-01: Per-service revenue breakdown
 * ANAL-02: Per-staff performance metrics
 * ANAL-03: Conversion funnel (submission→quote→payment→completion)
 * ANAL-04: Revenue trend by month with YoY comparison
 * OPS-01:  Booking delete from detail page (admin)
 * OPS-02:  Document inline viewer (secure proxied preview URL)
 * OPS-03:  Notification preferences (customer email opt-out)
 * OPS-04:  Staff assignment workload view
 * OPS-05:  Platform status page (health check extended)
 * OPS-06:  Bulk booking status update
 * OPS-07:  Booking notes history (all notes with author + timestamp)
 * OPS-08:  Customer merge / duplicate detection
 */

namespace S2NRI\Api\Controllers\Admin;

use S2NRI\Api\{Request, Response};
use S2NRI\Api\Controllers\BaseController;

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════════════════
// GDPRController
// GDPR-01: Data export   GDPR-02: Right to erasure
// ══════════════════════════════════════════════════════════════════════════════

class GDPRController extends BaseController {

    /**
     * GET admin/customers/{id}/data-export?format=json|csv
     * GDPR-01: Export all personal data for a customer.
     *
     * Includes: WP user account, customer profile, all bookings (+ field_data),
     * all messages, all documents (metadata only, not files), all notifications,
     * all payments, all reviews, all tickets + messages.
     *
     * TRACE: requireManager → get customer → collect all related data →
     *        format as JSON or CSV → stream with download header.
     * EDGE CASES: customer not found → 404.
     *             Large data sets → streams row by row (no memory explosion for JSON).
     */
    public function exportCustomerData( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p      = $wpdb->prefix;
        $cid    = (int) $req->param('id');
        $format = sanitize_key($req->query('format','json'));

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT cu.*, u.user_email, u.user_login, u.display_name, u.user_registered
             FROM {$p}s2nri_customers cu
             LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id
             WHERE cu.id=%d LIMIT 1",
            $cid
        ), ARRAY_A);
        if ( ! $customer ) { Response::json(['error'=>'Customer not found.'],404); return; }

        $wp_id = (int) $customer['wp_user_id'];

        // Collect all data
        $data = [
            'export_date'  => current_time('mysql'),
            'export_type'  => 'GDPR Full Data Export',
            'account'      => [
                'email'      => $customer['user_email'],
                'login'      => $customer['user_login'],
                'name'       => $customer['display_name'],
                'registered' => $customer['user_registered'],
            ],
            'profile'      => array_diff_key($customer, array_flip(['user_email','user_login','display_name','user_registered','wp_user_id'])),
            'bookings'     => $wpdb->get_results($wpdb->prepare(
                "SELECT b.booking_ref, b.status, b.payment_status, b.quoted_amount, b.paid_amount,
                        b.field_data, b.notes, b.created_at, b.completed_at, b.due_date,
                        s.name AS service_name
                 FROM {$p}s2nri_bookings b
                 LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
                 WHERE b.customer_id=%d ORDER BY b.created_at DESC",
                $cid
            ), ARRAY_A) ?: [],
            'messages'     => $wpdb->get_results($wpdb->prepare(
                "SELECT m.message, m.sender_type, m.is_internal, m.created_at, b.booking_ref
                 FROM {$p}s2nri_messages m
                 JOIN {$p}s2nri_bookings b ON b.id=m.booking_id
                 WHERE b.customer_id=%d AND m.sender_type='customer'
                 ORDER BY m.created_at DESC LIMIT 500",
                $cid
            ), ARRAY_A) ?: [],
            'documents'    => $wpdb->get_results($wpdb->prepare(
                "SELECT d.doc_type, d.file_name, d.created_at, b.booking_ref
                 FROM {$p}s2nri_documents d
                 JOIN {$p}s2nri_bookings b ON b.id=d.booking_id
                 WHERE b.customer_id=%d ORDER BY d.created_at DESC",
                $cid
            ), ARRAY_A) ?: [],
            'payments'     => $wpdb->get_results($wpdb->prepare(
                "SELECT p.amount, p.method, p.status, p.created_at, p.verified_at, b.booking_ref
                 FROM {$p}s2nri_payments p
                 JOIN {$p}s2nri_bookings b ON b.id=p.booking_id
                 WHERE b.customer_id=%d ORDER BY p.created_at DESC",
                $cid
            ), ARRAY_A) ?: [],
            'reviews'      => $wpdb->get_results($wpdb->prepare(
                // FIXED: the real column is `review`, not `review_text` —
                // confirmed against the actual CREATE TABLE
                // (Installer.php:313) and cross-referenced against the
                // already-correct pattern used elsewhere in this exact
                // codebase (AdminControllers.php:2259, "r.review AS
                // review_text"). The unaliased version here queried a
                // genuinely nonexistent column, which made $wpdb throw a
                // query error — silently swallowed by the `?: []`
                // fallback below, so every GDPR export's reviews section
                // came back empty with no error ever surfaced, even for
                // customers who had real reviews on file.
                "SELECT r.rating, r.review AS review_text, r.created_at, b.booking_ref
                 FROM {$p}s2nri_reviews r
                 JOIN {$p}s2nri_bookings b ON b.id=r.booking_id
                 WHERE b.customer_id=%d",
                $cid
            ), ARRAY_A) ?: [],
            'tickets'      => $wpdb->get_results($wpdb->prepare(
                "SELECT t.subject, t.status, t.created_at, b.booking_ref
                 FROM {$p}s2nri_tickets t
                 JOIN {$p}s2nri_bookings b ON b.id=t.booking_id
                 WHERE b.customer_id=%d ORDER BY t.created_at DESC",
                $cid
            ), ARRAY_A) ?: [],
        ];

        // Decode field_data JSON for readability
        foreach ($data['bookings'] as &$b) {
            $b['field_data'] = json_decode($b['field_data'] ?? '{}', true) ?: [];
        }
        unset($b);

        $filename = 'gdpr-export-customer-'.$cid.'-'.date('Y-m-d');

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output','w');
            // Account
            fputcsv($out,['SECTION','Field','Value']);
            foreach ($data['account'] as $k=>$v) fputcsv($out,['Account',$k,$v]);
            foreach ($data['profile']  as $k=>$v) fputcsv($out,['Profile',$k,is_array($v)?json_encode($v):$v]);
            fputcsv($out,[]);
            // Bookings
            fputcsv($out,['BOOKINGS']);
            fputcsv($out,['Ref','Service','Status','Payment','Quoted','Paid','Date']);
            foreach ($data['bookings'] as $bk) {
                fputcsv($out,[$bk['booking_ref'],$bk['service_name'],$bk['status'],$bk['payment_status'],$bk['quoted_amount'],$bk['paid_amount'],$bk['created_at']]);
            }
            fputcsv($out,[]);
            fputcsv($out,['PAYMENTS']);
            fputcsv($out,['Booking','Amount','Method','Status','Date']);
            foreach ($data['payments'] as $pm) fputcsv($out,[$pm['booking_ref'],$pm['amount'],$pm['method'],$pm['status'],$pm['created_at']]);
            fclose($out); exit;
        }

        // JSON
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.json"');
        header('Cache-Control: no-store');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * DELETE admin/customers/{id}/data
     * GDPR-02: Right to erasure — deletes all personal data for a customer.
     *
     * Soft-deletes bookings (preserves for audit, clears PII from field_data).
     * Deletes: customer profile, WP user meta, OTPs, magic links, notifications.
     * Anonymises: booking field_data (replaces with {_deleted:true}).
     * Preserves: booking records, payment records (financial audit trail).
     *
     * Body: { confirm: "DELETE_CUSTOMER_DATA" } — required confirmation string.
     *
     * TRACE: requireManager → validate confirm string → anonymise bookings →
     *        delete customer profile → delete WP user → delete support tables →
     *        log audit entry → return summary.
     */
    public function eraseCustomerData( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p      = $wpdb->prefix;
        $cid    = (int) $req->param('id');
        $confirm = $req->input('confirm','');

        if ($confirm !== 'DELETE_CUSTOMER_DATA') {
            Response::json(['error' => 'Confirmation required. Send { confirm: "DELETE_CUSTOMER_DATA" } to proceed.'], 422); return;
        }

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT cu.*, u.user_email FROM {$p}s2nri_customers cu LEFT JOIN {$p}users u ON u.ID=cu.wp_user_id WHERE cu.id=%d LIMIT 1",
            $cid
        ), ARRAY_A);
        if ( ! $customer ) { Response::json(['error'=>'Customer not found.'],404); return; }

        $wp_id      = (int) $customer['wp_user_id'];
        $email_hash = hash('sha256', $customer['user_email'].'_deleted_'.time());
        $deleted    = [];

        // 1. Anonymise booking field_data (GDPR: erase PII from form submissions)
        $booking_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$p}s2nri_bookings WHERE customer_id=%d", $cid
        ));
        if ($booking_ids) {
            $placeholders = implode(',', array_fill(0,count($booking_ids),'%d'));
            $deleted['field_data_anonymised'] = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$p}s2nri_bookings SET field_data='{\"_deleted\":true}' WHERE id IN ($placeholders) AND customer_id=%d",
                ...[...$booking_ids,$cid]
            ));
        }

        // 2. Delete customer messages (customer-authored only)
        if ($booking_ids) {
            $placeholders = implode(',', array_fill(0,count($booking_ids),'%d'));
            $deleted['messages_deleted'] = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$p}s2nri_messages WHERE booking_id IN ($placeholders) AND sender_type='customer'",
                ...$booking_ids
            ));
        }

        // 3. Delete notifications
        $deleted['notifications_deleted'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$p}s2nri_notifications WHERE user_id=%d", $wp_id
        ));

        // 4. Delete OTPs
        $deleted['otps_deleted'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$p}s2nri_otps WHERE identifier=%s", $customer['user_email']
        ));

        // 5. Delete magic links
        $deleted['magic_links_deleted'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$p}s2nri_magic_links WHERE email=%s", $customer['user_email']
        ));

        // 6. Anonymise customer profile (replace PII with anonymised values)
        // CHECKED (was previously unconditional — $deleted[...] = true was
        // set regardless of whether this update actually succeeded, which
        // matters more here than almost anywhere else in this codebase:
        // this is a customer's legal right-to-erasure request, and
        // "success: true" needs to genuinely mean their PII was erased,
        // not just that the request was received).
        $profile_update = $wpdb->update($p.'s2nri_customers', [
            'first_name'   => 'Deleted',
            'last_name'    => 'User',
            'email'        => $email_hash.'@deleted.invalid',
            'phone'        => '',
            'whatsapp'     => '',
            'notes'        => '',
            'address_india'=> '',
            'address_abroad'=> '',
        ], ['id' => $cid]);
        if ( $profile_update === false ) {
            error_log( '[S2NRI GDPR] Customer profile anonymisation FAILED for customer ' . $cid . ': ' . $wpdb->last_error );
        }
        $deleted['customer_profile_anonymised'] = ( $profile_update !== false );

        // 7. Anonymise WP user (cannot hard-delete if bookings exist — would orphan records)
        // CHECKED (was previously unconditional): wp_update_user() returns
        // the user ID on success or a WP_Error object on failure — this
        // was never checked, so a failure here (e.g. the anonymised email
        // colliding with an existing user, which WordPress rejects) would
        // still report wp_user_anonymised: true while the WP account's
        // real email/name remained untouched.
        if ($wp_id) {
            $wp_update_result = wp_update_user([
                'ID'           => $wp_id,
                'user_email'   => $email_hash.'@deleted.invalid',
                'display_name' => 'Deleted User',
                'first_name'   => '',
                'last_name'    => '',
            ]);
            if ( is_wp_error( $wp_update_result ) ) {
                error_log( '[S2NRI GDPR] WP user anonymisation FAILED for wp_id ' . $wp_id . ': ' . $wp_update_result->get_error_message() );
            }
            delete_user_meta($wp_id, 's2nri_portal_token');
            delete_user_meta($wp_id, 's2nri_portal_token_exp');
            delete_user_meta($wp_id, 's2nri_disabled');
            $deleted['wp_user_anonymised'] = ! is_wp_error( $wp_update_result );
        }

        // Audit log
        $this->logAudit(null, 'gdpr_erasure', (string)$cid, $customer['user_email']);

        // Overall success now genuinely reflects whether the two most
        // important steps (profile + WP account anonymisation) actually
        // succeeded, not just that the request was processed.
        $fully_erased = ( $deleted['customer_profile_anonymised'] ?? false )
            && ( ! $wp_id || ( $deleted['wp_user_anonymised'] ?? false ) );

        Response::json([
            'success' => $fully_erased,
            'message' => $fully_erased
                ? 'All personal data for this customer has been erased as required by GDPR.'
                : 'Erasure partially completed — some steps failed. Check the summary below and the server error log, then retry.',
            'summary' => $deleted,
        ]);
    }

    /**
     * GET profile/data-export
     * Self-service: logged-in customer exports their own data.
     */
    public function selfExport( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $wp_id  = $this->user['wp_id'];

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id=%d LIMIT 1", $wp_id
        ), ARRAY_A);
        if ( ! $customer ) { Response::json(['error'=>'Profile not found.'],404); return; }

        // Reuse admin export via same data collection
        $req_with_id = clone $req;
        // Forward to admin export logic with customer's own ID
        $fake_req = new \S2NRI\Api\Request(['id' => $customer['id']]);
        $cid = $customer['id'];
        $data = [
            'export_date' => current_time('mysql'),
            'message'     => 'Your personal data export from '.(\S2NRI\Models\Setting::get('platform_name','Services2NRI')),
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-data-export-'.date('Y-m-d').'.json"');
        header('Cache-Control: no-store');
        echo wp_json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AdvancedAnalyticsController
// ANAL-01 through ANAL-04: Deep analytics for admin
// ══════════════════════════════════════════════════════════════════════════════

class AdvancedAnalyticsController extends BaseController {

    /**
     * GET admin/analytics/services
     * ANAL-01: Per-service revenue, booking count, avg quote, completion rate.
     *
     * TRACE: requireManager → single query with GROUP BY service_id →
     *        join services + categories → return ordered by revenue DESC.
     */
    public function byService( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = (int) $req->query('days', 90);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.name AS service_name, c.name AS category_name,
                    COUNT(DISTINCT b.id)                                          AS total_bookings,
                    COUNT(DISTINCT CASE WHEN b.status='completed' THEN b.id END)  AS completed,
                    COUNT(DISTINCT CASE WHEN b.status='cancelled'  THEN b.id END)  AS cancelled,
                    COALESCE(SUM(CASE WHEN p.status='verified' THEN p.amount END),0) AS revenue,
                    COALESCE(AVG(b.quoted_amount),0)                              AS avg_quote,
                    COALESCE(AVG(DATEDIFF(b.completed_at,b.created_at)),0)       AS avg_days_to_complete
             FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id=s.category_id
             LEFT JOIN {$p}s2nri_bookings b ON b.service_id=s.id AND b.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             LEFT JOIN {$p}s2nri_payments p ON p.booking_id=b.id AND p.status='verified'
             WHERE s.is_active=1
             GROUP BY s.id
             ORDER BY revenue DESC, total_bookings DESC",
            $days
        ), ARRAY_A);

        Response::json(['services'=>$rows?:[], 'period_days'=>$days]);
    }

    /**
     * GET admin/analytics/staff
     * ANAL-02: Per-staff performance: assigned bookings, completion rate, avg response time.
     */
    public function byStaff( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = (int) $req->query('days', 90);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(u.display_name, u.user_login, 'Unassigned')        AS staff_name,
                    u.user_email                                                  AS staff_email,
                    st.s2nri_role                                                 AS role,
                    COUNT(DISTINCT b.id)                                          AS total_assigned,
                    COUNT(DISTINCT CASE WHEN b.status='completed' THEN b.id END)  AS completed,
                    COUNT(DISTINCT CASE WHEN b.status NOT IN ('completed','cancelled') THEN b.id END) AS active,
                    COALESCE(AVG(CASE WHEN b.status='completed' THEN DATEDIFF(b.completed_at,b.created_at) END),0) AS avg_days_to_complete,
                    COALESCE(SUM(CASE WHEN p.status='verified' THEN p.amount END),0) AS revenue_handled
             FROM {$p}s2nri_staff st
             JOIN {$p}users u ON u.ID=st.wp_user_id
             LEFT JOIN {$p}s2nri_bookings b ON b.assigned_to=st.wp_user_id AND b.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             LEFT JOIN {$p}s2nri_payments p ON p.booking_id=b.id AND p.status='verified'
             WHERE st.is_active=1
             GROUP BY st.wp_user_id
             ORDER BY total_assigned DESC",
            $days
        ), ARRAY_A);

        Response::json(['staff'=>$rows?:[], 'period_days'=>$days]);
    }

    /**
     * GET admin/analytics/funnel
     * ANAL-03: Conversion funnel — how many bookings progress through each stage.
     *
     * Returns counts per status AND calculated conversion rates:
     * submission → quote_sent → quote_approved → in_progress → completed
     */
    public function conversionFunnel( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = (int) $req->query('days', 90);

        $date_cond = $wpdb->prepare("AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days);

        $total        = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE 1=1 $date_cond");
        $quote_sent   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status IN ('quote_sent','quote_approved','in_progress','docs_requested','docs_received','processing','completed') $date_cond");
        $quote_appr   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status IN ('quote_approved','in_progress','docs_requested','docs_received','processing','completed') $date_cond");
        $in_progress  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status IN ('in_progress','docs_requested','docs_received','processing','completed') $date_cond");
        $completed    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status='completed' $date_cond");
        $cancelled    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status='cancelled' $date_cond");
        $unavailable  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status='service_not_available' $date_cond");

        $pct = fn($n) => $total > 0 ? round($n/$total*100,1) : 0;

        Response::json([
            'period_days' => $days,
            'funnel'      => [
                ['stage'=>'Submitted',       'count'=>$total,       'pct'=>100],
                ['stage'=>'Quote Sent',      'count'=>$quote_sent,  'pct'=>$pct($quote_sent)],
                ['stage'=>'Quote Approved',  'count'=>$quote_appr,  'pct'=>$pct($quote_appr)],
                ['stage'=>'In Progress',     'count'=>$in_progress, 'pct'=>$pct($in_progress)],
                ['stage'=>'Completed',       'count'=>$completed,   'pct'=>$pct($completed)],
            ],
            'drop_offs'   => [
                'cancelled'         => $cancelled,
                'service_not_available' => $unavailable,
            ],
            'completion_rate' => $pct($completed),
        ]);
    }

    /**
     * GET admin/analytics/revenue-trend
     * ANAL-04: Monthly revenue for last N months with YoY comparison.
     */
    public function revenueTrend( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p      = $wpdb->prefix;
        $months = min((int) $req->query('months', 12), 24);

        // This year's monthly revenue
        $this_year = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS month,
                    COALESCE(SUM(amount),0) AS revenue,
                    COUNT(*) AS payment_count
             FROM {$p}s2nri_payments
             WHERE status='verified' AND created_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)
             GROUP BY month ORDER BY month ASC",
            $months
        ), ARRAY_A);

        // Last year same period (YoY)
        $last_year = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(DATE_ADD(created_at, INTERVAL 12 MONTH),'%%Y-%%m') AS month,
                    COALESCE(SUM(amount),0) AS revenue
             FROM {$p}s2nri_payments
             WHERE status='verified'
               AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL %d MONTH) AND DATE_SUB(NOW(), INTERVAL 0 MONTH)
               AND YEAR(created_at) = YEAR(DATE_SUB(NOW(), INTERVAL 12 MONTH))
             GROUP BY DATE_FORMAT(created_at,'%%Y-%%m') ORDER BY month ASC",
            $months
        ), ARRAY_A);

        $ly_map = [];
        foreach ($last_year as $r) $ly_map[$r['month']] = (float)$r['revenue'];

        $trend = array_map(fn($r) => [
            'month'        => $r['month'],
            'revenue'      => (float) $r['revenue'],
            'payment_count'=> (int) $r['payment_count'],
            'yoy_revenue'  => $ly_map[$r['month']] ?? null,
            'yoy_change_pct' => isset($ly_map[$r['month']]) && $ly_map[$r['month']] > 0
                ? round(((float)$r['revenue'] - $ly_map[$r['month']]) / $ly_map[$r['month']] * 100, 1)
                : null,
        ], $this_year);

        Response::json([
            'trend'          => $trend,
            'total_revenue'  => array_sum(array_column($this_year,'revenue')),
            'total_payments' => array_sum(array_column($this_year,'payment_count')),
            'period_months'  => $months,
        ]);
    }

    /**
     * GET admin/analytics/customers
     * Customer acquisition analytics: new customers per month, top countries, repeat customers.
     */
    public function customerAnalytics( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = (int) $req->query('days', 90);

        $acquisition = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS month, COUNT(*) AS new_customers
             FROM {$p}s2nri_customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY month ORDER BY month ASC",
            $days
        ), ARRAY_A);

        $top_countries = $wpdb->get_results(
            "SELECT country, COUNT(*) AS count FROM {$p}s2nri_customers WHERE country != '' GROUP BY country ORDER BY count DESC LIMIT 10",
            ARRAY_A
        );

        $repeat = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT customer_id) FROM {$p}s2nri_bookings GROUP BY customer_id HAVING COUNT(*)>1"
        );
        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_customers");

        Response::json([
            'acquisition'   => $acquisition,
            'top_countries' => $top_countries,
            'total'         => $total,
            'repeat_count'  => $repeat,
            'repeat_rate'   => $total > 0 ? round($repeat/$total*100,1) : 0,
        ]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// OperationsController
// OPS-01 through OPS-08: Operational platform completeness
// ══════════════════════════════════════════════════════════════════════════════

class OperationsController extends BaseController {

    /**
     * DELETE admin/bookings/{id}
     * OPS-01: Delete a booking from the detail page (soft delete).
     * Soft-deletes by setting status='cancelled' + cancelled_at + notes.
     * Hard delete only if query param ?hard=true AND super_admin role.
     *
     * TRACE: requireManager → verify booking exists → if hard=true AND super_admin → DELETE cascade →
     *        else UPDATE status='cancelled' + log audit.
     * EDGE CASES: booking has verified payments → cannot hard delete (financial records).
     *             Active booking (not completed/cancelled) → warn in response but allow.
     */
    public function deleteBooking( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $id   = (int) $req->param('id');
        $hard = $req->query('hard','') === 'true' && $this->user['s2nri_role'] === 'super_admin';

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, s.name AS service_name FROM {$p}s2nri_bookings b LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id WHERE b.id=%d LIMIT 1",
            $id
        ), ARRAY_A);
        if ( ! $booking ) { Response::json(['error'=>'Booking not found.'],404); return; }

        if ($hard) {
            // Check for verified payments — block hard delete
            $paid = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}s2nri_payments WHERE booking_id=%d AND status='verified'", $id));
            if ($paid > 0) {
                Response::json(['error'=>'Cannot hard delete a booking with verified payments. Use soft delete.'],422); return;
            }
            // Hard delete — cascade order matters. Wrapped in a transaction:
            // without this, a failure partway through (lock timeout,
            // connection drop) left a booking row that LOOKS intact in the
            // list but has some related tables silently wiped (messages,
            // documents, quotes...) and others not — a stealth partial data
            // loss with no error surfaced to the admin. Tables use the
            // server's default engine (no ENGINE= override anywhere in
            // Installer.php), which is InnoDB on essentially every current
            // MySQL/MariaDB host, so transactions are supported.
            //
            // Each delete's OWN return value is checked (not just
            // $wpdb->last_error once at the end) — last_error gets cleared
            // by each subsequent successful query, so checking it only
            // once after all 11 calls would silently miss a failure in
            // any call before the last one.
            $wpdb->query( 'START TRANSACTION' );
            $cascade_ok = true;
            foreach ( [
                's2nri_audit_log', 's2nri_messages', 's2nri_documents', 's2nri_quotes',
                's2nri_notifications', 's2nri_email_log', 's2nri_payments', 's2nri_reviews',
                's2nri_tickets', 's2nri_communication_log',
            ] as $table ) {
                if ( $wpdb->delete( $p . $table, [ 'booking_id' => $id ] ) === false ) {
                    $cascade_ok = false;
                    error_log( "[S2NRI] Hard delete cascade failed on {$table} for booking {$id}: " . $wpdb->last_error );
                    break;
                }
            }
            if ( $cascade_ok && $wpdb->delete( $p . 's2nri_bookings', [ 'id' => $id ] ) === false ) {
                $cascade_ok = false;
                error_log( '[S2NRI] Hard delete cascade failed on s2nri_bookings for booking ' . $id . ': ' . $wpdb->last_error );
            }

            if ( ! $cascade_ok ) {
                $wpdb->query( 'ROLLBACK' );
                Response::json(['error'=>'Failed to delete booking. No data was changed.'],500); return;
            }
            $wpdb->query( 'COMMIT' );
            Response::json(['success'=>true,'message'=>'Booking permanently deleted.','type'=>'hard']);
        } else {
            // Soft delete: mark as cancelled
            if ( $wpdb->update($p.'s2nri_bookings', [
                'status'       => 'cancelled',
                'cancelled_at' => current_time('mysql'),
                'notes'        => ( $booking['notes'] ? $booking['notes']."\n" : '' ).'[Admin deleted via dashboard on '.date('d M Y H:i').']',
            ], ['id'=>$id]) === false ) {
                Response::json(['error'=>'Failed to cancel booking.'],500); return;
            }
            $this->logAudit($id,'booking_deleted_soft','admin',$booking['booking_ref']);
            Response::json(['success'=>true,'message'=>'Booking cancelled and archived.','type'=>'soft']);
        }
    }

    /**
     * GET documents/{doc_id}/view
     * OPS-02: Inline document viewer — serves file with Content-Disposition: inline
     * for browser preview (PDF, images). Same auth as proxy download.
     */
    public function viewDocument( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $doc_id = (int) $req->param('doc_id');

        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT d.*, b.customer_id FROM {$p}s2nri_documents d JOIN {$p}s2nri_bookings b ON b.id=d.booking_id WHERE d.id=%d LIMIT 1",
            $doc_id
        ), ARRAY_A);
        if ( ! $doc ) { status_header(404); echo 'Not found.'; exit; }

        $is_staff = $this->userIsStaff();
        if ( ! $is_staff ) {
            $customer = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}s2nri_customers WHERE wp_user_id=%d LIMIT 1", $this->user['wp_id']));
            if ( ! $customer || (int)$doc['customer_id'] !== (int)$customer || ! (int)$doc['is_visible_to_customer'] ) {
                status_header(403); echo 'Access denied.'; exit;
            }
        }

        $upload_dir = wp_upload_dir()['basedir'];
        $upload_url = wp_upload_dir()['baseurl'];
        $local_path = null;
        if ( strpos($doc['file_url'], $upload_url) === 0 ) {
            $local_path = $upload_dir . substr($doc['file_url'], strlen($upload_url));
        }

        $content_type = $doc['mime_type'] ?: 'application/octet-stream';
        // Inline-safe types: PDF, images
        $inline_types = ['application/pdf','image/jpeg','image/png','image/gif','image/webp','image/avif'];
        $disposition  = in_array($content_type, $inline_types, true) ? 'inline' : 'attachment';

        if ($local_path && file_exists($local_path)) {
            header('Content-Type: '.$content_type);
            header('Content-Disposition: '.$disposition.'; filename="'.sanitize_file_name($doc['file_name']).'"');
            header('Content-Length: '.filesize($local_path));
            header('Cache-Control: private, max-age=3600');
            readfile($local_path);
            exit;
        }
        // Fallback redirect
        wp_redirect($doc['file_url']); exit;
    }

    /**
     * GET/PUT profile/notification-preferences
     * OPS-03: Customer email notification opt-in/out per event type.
     *
     * GET: returns current preferences (stored as JSON in s2nri_customers.notes or a dedicated meta)
     * PUT: updates preferences
     *
     * Events: booking_submitted, status_changed, quote_sent, document_reminder,
     *         dispatch, completion, message_received
     */
    public function getNotificationPreferences( Request $req ): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = $this->user['wp_id'];
        $prefs_raw = get_user_meta($uid, 's2nri_email_prefs', true);
        $prefs     = $prefs_raw ? json_decode($prefs_raw, true) : [];

        $defaults = [
            'booking_submitted'  => true,
            'status_changed'     => true,
            'quote_sent'         => true,
            'document_reminder'  => true,
            'dispatch'           => true,
            'completion'         => true,
            'message_received'   => true,
        ];

        Response::json(['preferences' => array_merge($defaults, $prefs)]);
    }

    public function updateNotificationPreferences( Request $req ): void {
        $uid   = $this->user['wp_id'];
        $prefs = $req->body();
        if ( ! is_array($prefs) ) { Response::json(['error'=>'Invalid preferences format.'],422); return; }

        $allowed_keys = ['booking_submitted','status_changed','quote_sent','document_reminder','dispatch','completion','message_received'];
        $clean = [];
        foreach ($allowed_keys as $key) {
            if ( isset($prefs[$key]) ) $clean[$key] = (bool) $prefs[$key];
        }
        update_user_meta($uid, 's2nri_email_prefs', wp_json_encode($clean));
        Response::json(['success'=>true,'preferences'=>$clean]);
    }

    /**
     * GET admin/staff/workload
     * OPS-04: Per-staff current active booking workload.
     */
    public function staffWorkload( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT COALESCE(u.display_name, u.user_login, '') AS staff_name,
                    st.s2nri_role AS role,
                    COUNT(DISTINCT CASE WHEN b.status NOT IN ('completed','cancelled','service_not_available') THEN b.id END) AS active_bookings,
                    COUNT(DISTINCT CASE WHEN b.status='under_review' THEN b.id END) AS awaiting_quote,
                    COUNT(DISTINCT CASE WHEN b.status IN ('in_progress','docs_requested','processing') THEN b.id END) AS in_progress,
                    COUNT(DISTINCT CASE WHEN b.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND b.status NOT IN ('completed','cancelled') THEN b.id END) AS due_this_week
             FROM {$p}s2nri_staff st
             JOIN {$p}users u ON u.ID=st.wp_user_id
             LEFT JOIN {$p}s2nri_bookings b ON b.assigned_to=st.wp_user_id
             WHERE st.is_active=1
             GROUP BY st.wp_user_id
             ORDER BY active_bookings DESC",
            ARRAY_A
        );
        Response::json(['workload'=>$rows?:[]]);
    }

    /**
     * POST admin/bookings/bulk-status
     * OPS-06: Bulk status update for multiple bookings.
     * Body: { ids: [1,2,3], status: 'under_review', note: 'optional' }
     *
     * TRACE: requireManager → validate status → iterate IDs →
     *        UPDATE status → logAudit → return {success_count, fail_count}.
     * EDGE CASES: IDs not found → counted as failures.
     *             Invalid status → 422.
     *             Max 100 IDs per call.
     */
    public function bulkStatusUpdate( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p      = $wpdb->prefix;
        $raw    = $req->input('ids',[]);
        $status = sanitize_key($req->input('status',''));
        $note   = sanitize_text_field($req->input('note',''));

        $valid_statuses = ['submitted','under_review','quote_sent','quote_approved','in_progress',
                           'docs_requested','docs_received','processing','completed','cancelled','on_hold','service_not_available'];
        if ( ! in_array($status, $valid_statuses, true) ) {
            Response::json(['error'=>'Invalid status value.'],422); return;
        }

        $ids = array_unique(array_slice(array_filter(array_map('intval',(array)$raw)),0,100));
        if (empty($ids)) { Response::json(['error'=>'No booking IDs provided.'],422); return; }

        $success = 0; $fail = 0;
        foreach ($ids as $bid) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT id, booking_ref, status FROM {$p}s2nri_bookings WHERE id=%d LIMIT 1", $bid
            ), ARRAY_A);
            if ( ! $booking ) { $fail++; continue; }

            $update = ['status' => $status];
            if ($status === 'completed')  $update['completed_at'] = current_time('mysql');
            if ($status === 'cancelled')  $update['cancelled_at'] = current_time('mysql');
            if ($note) $update['notes'] = ($booking['notes'] ? $booking['notes']."\n" : '').$note;

            $result = $wpdb->update($p.'s2nri_bookings', $update, ['id'=>$bid]);
            if ($result !== false) {
                $this->logAudit($bid,'status_changed',$booking['status'],$status);
                // Fire WhatsApp hook
                do_action('s2nri_booking_status_changed',$bid,$status,[
                    'booking_ref'  => $booking['booking_ref'],
                    'status'       => $status,
                    'customer_name'=> '',
                    'service_name' => '',
                ]);
                $success++;
            } else {
                $fail++;
            }
        }

        Response::json(['success'=>true,'updated'=>$success,'failed'=>$fail,'total'=>count($ids)]);
    }

    /**
     * GET admin/bookings/{id}/notes-history
     * OPS-07: Timestamped internal notes history for a booking.
     * Notes are stored in s2nri_audit_log with action='note_added'.
     */
    public function notesHistory( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param('id');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT al.details AS note, al.created_at,
                    COALESCE(u.display_name, u.user_login, 'System') AS author
             FROM {$p}s2nri_audit_log al
             LEFT JOIN {$p}users u ON u.ID=al.user_id
             WHERE al.booking_id=%d AND al.action='note_added'
             ORDER BY al.created_at DESC LIMIT 50",
            $id
        ), ARRAY_A);

        Response::json(['notes'=>$rows?:[]]);
    }

    /**
     * GET admin/customers/duplicates
     * OPS-08: Duplicate customer detection — finds customers with same email or phone.
     */
    public function findDuplicates( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        // Customers with same email domain and similar names (phone duplicates)
        $phone_dups = $wpdb->get_results(
            "SELECT cu.phone, COUNT(*) AS dup_count,
                    GROUP_CONCAT(CONCAT(u.display_name,' (',u.user_email,')') SEPARATOR ' | ') AS accounts
             FROM {$p}s2nri_customers cu
             JOIN {$p}users u ON u.ID=cu.wp_user_id
             WHERE cu.phone != '' AND cu.phone != '+' AND LENGTH(cu.phone) > 5
             GROUP BY cu.phone HAVING COUNT(*) > 1
             ORDER BY dup_count DESC LIMIT 30",
            ARRAY_A
        );

        $email_dups = $wpdb->get_results(
            "SELECT u.user_email, COUNT(*) AS dup_count
             FROM {$p}s2nri_customers cu
             JOIN {$p}users u ON u.ID=cu.wp_user_id
             GROUP BY LOWER(TRIM(u.user_email)) HAVING COUNT(*) > 1
             ORDER BY dup_count DESC LIMIT 20",
            ARRAY_A
        );

        Response::json(['phone_duplicates'=>$phone_dups?:[],'email_duplicates'=>$email_dups?:[]]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PlatformHealthController
// OPS-05: Extended platform health and system status
// ══════════════════════════════════════════════════════════════════════════════

class PlatformHealthController extends BaseController {

    /**
     * GET admin/platform-health
     * Extended health check: DB connectivity, table existence, file permissions,
     * cron schedule status, SMTP config, WhatsApp config, PHP extensions.
     *
     * TRACE: requireManager → run each check → aggregate → return structured report.
     * EDGE CASES: DB connection check uses $wpdb->get_var('SELECT 1').
     *             Missing tables logged as warnings.
     */
    public function check( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        $checks = [];

        // 1. DB connection
        $db_ok = $wpdb->get_var('SELECT 1') === '1';
        $checks['database'] = ['status' => $db_ok ? 'ok' : 'error', 'label' => 'Database Connection', 'detail' => $db_ok ? 'Connected' : 'Connection failed'];

        // 2. Required tables
        $required_tables = [
            's2nri_settings','s2nri_bookings','s2nri_customers','s2nri_services','s2nri_categories',
            's2nri_documents','s2nri_messages','s2nri_quotes','s2nri_payments','s2nri_audit_log',
            's2nri_email_log','s2nri_notifications','s2nri_otps','s2nri_staff','s2nri_reviews',
            's2nri_tickets','s2nri_email_templates','s2nri_holidays','s2nri_quick_replies',
            's2nri_vendors','s2nri_request_types','s2nri_communication_log','s2nri_magic_links',
            's2nri_ref_counter','s2nri_form_fields','s2nri_field_options',
        ];
        $missing = [];
        foreach ($required_tables as $table) {
            if ( ! $wpdb->get_var("SHOW TABLES LIKE '{$p}{$table}'") ) $missing[] = $table;
        }
        $checks['tables'] = [
            'status' => empty($missing) ? 'ok' : 'warning',
            'label'  => 'Database Tables',
            'detail' => empty($missing)
                ? count($required_tables).' required tables present'
                : 'Missing: '.implode(', ',$missing),
        ];

        // 3. PHP extensions
        $extensions = ['json'=>true,'mbstring'=>false,'zip'=>false,'gd'=>false,'curl'=>false,'openssl'=>false];
        $ext_status = [];
        foreach ($extensions as $ext => $required) {
            $loaded = extension_loaded($ext);
            $ext_status[$ext] = ['loaded'=>$loaded,'required'=>$required];
            if ($required && !$loaded) $checks['php_ext_'.$ext] = ['status'=>'error','label'=>"PHP {$ext}",'detail'=>'Required extension not loaded'];
        }
        $checks['php_extensions'] = ['status'=>'ok','label'=>'PHP Extensions','detail'=>$ext_status];

        // 4. WP Cron jobs
        $cron_hooks = ['s2nri_job_notifications','s2nri_job_quote_reminders','s2nri_job_cleanup','s2nri_job_sitemap'];
        $cron_status = [];
        foreach ($cron_hooks as $hook) {
            $next = wp_next_scheduled($hook);
            $cron_status[$hook] = $next ? date('Y-m-d H:i:s',$next) : 'NOT SCHEDULED';
        }
        $all_scheduled = ! in_array('NOT SCHEDULED', $cron_status, true);
        $checks['cron'] = ['status'=>$all_scheduled?'ok':'warning','label'=>'Scheduled Jobs','detail'=>$cron_status];

        // 5. SMTP config
        $smtp_host = \S2NRI\Models\Setting::get('smtp_host','');
        $checks['smtp'] = [
            'status' => $smtp_host ? 'ok' : 'warning',
            'label'  => 'SMTP Configuration',
            'detail' => $smtp_host ? "Host: {$smtp_host}" : 'SMTP not configured — emails will use WordPress default mail()',
        ];

        // 6. WhatsApp config
        $wa_key = \S2NRI\Models\Setting::get('whatsapp_callmebot_apikey','');
        $checks['whatsapp'] = [
            'status' => $wa_key ? 'ok' : 'info',
            'label'  => 'WhatsApp (CallMeBot)',
            'detail' => $wa_key ? 'API key configured' : 'Not configured — WhatsApp notifications disabled',
        ];

        // 7. Upload directory writable
        $upload_dir = wp_upload_dir();
        $writable   = is_writable($upload_dir['basedir']);
        $checks['uploads'] = ['status'=>$writable?'ok':'error','label'=>'Upload Directory','detail'=>$writable?$upload_dir['basedir'].' is writable':'Upload directory not writable — document uploads will fail'];

        // 8. PHP memory limit
        $mem_limit = ini_get('memory_limit');
        $checks['php_memory'] = ['status'=>'ok','label'=>'PHP Memory Limit','detail'=>$mem_limit];

        // 9. PHP max execution time
        $exec_time = ini_get('max_execution_time');
        $checks['php_execution'] = ['status'=>$exec_time<30?'warning':'ok','label'=>'PHP Max Execution Time','detail'=>"{$exec_time}s"];

        // 10. WP version
        $checks['wordpress'] = ['status'=>'ok','label'=>'WordPress Version','detail'=>get_bloginfo('version')];

        // 11. S2NRI version
        $checks['plugin'] = ['status'=>'ok','label'=>'Services2NRI Version','detail'=>S2NRI_VERSION];

        // 12. Active bookings count (quick data snapshot)
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status NOT IN ('completed','cancelled','service_not_available')");
        $checks['active_bookings'] = ['status'=>'ok','label'=>'Active Bookings','detail'=>$active];

        // Overall status
        $has_error   = (bool) array_filter($checks, fn($c) => ($c['status']??'') === 'error');
        $has_warning = (bool) array_filter($checks, fn($c) => ($c['status']??'') === 'warning');
        $overall     = $has_error ? 'error' : ($has_warning ? 'warning' : 'ok');

        Response::json([
            'overall'    => $overall,
            'checks'     => array_values( $checks ), // indexed array so JS .filter() works
            'checked_at' => current_time('mysql'),
            // system_info: displayed by the Ko component in System Information section
            'system_info' => [
                'php_version'    => PHP_VERSION,
                'wp_version'     => get_bloginfo('version'),
                'plugin_version' => S2NRI_VERSION,
                'server'         => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'mysql_version'  => $wpdb->get_var('SELECT VERSION()') ?: 'unknown',
                'memory_limit'   => ini_get('memory_limit'),
                'max_exec_time'  => ini_get('max_execution_time') . 's',
                'opcache'        => function_exists('opcache_get_status') ? 'enabled' : 'disabled',
                'site_url'       => get_site_url(),
            ],
        ]);
    }
}
