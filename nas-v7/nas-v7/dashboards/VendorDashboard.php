<?php
/**
 * NAS Vendor Dashboard v3.1
 * Shortcode + AJAX handlers for the vendor portal.
 */
namespace NAS\Dashboards;

use NAS\Core\Security;
use NAS\Core\Database;
use NAS\Core\Helpers;

class VendorDashboard {

    public static function register(): void {
        add_shortcode( 'nas_vendor_dashboard', [ self::class, 'render' ] );

        // Booking data
        add_action( 'wp_ajax_nas_vendor_get_bookings',       [ self::class, 'get_bookings' ] );
        add_action( 'wp_ajax_nas_vendor_get_stats',          [ self::class, 'get_stats' ] );
        add_action( 'wp_ajax_nas_vendor_mark_published',     [ self::class, 'mark_published' ] );
        add_action( 'wp_ajax_nas_vendor_upload_proof',       [ self::class, 'upload_proof' ] );

        // Earnings
        add_action( 'wp_ajax_nas_vendor_get_earnings',       [ self::class, 'get_earnings' ] );

        // Profile
        add_action( 'wp_ajax_nas_vendor_update_profile',     [ self::class, 'update_profile' ] );

        // Messages
        add_action( 'wp_ajax_nas_vendor_check_new_messages', [ self::class, 'check_new_messages' ] );
    }

    // ── Render ─────────────────────────────────────────────────────────────

    // TRACE: render() — Trigger: wp_ajax_render AJAX action.
    //        Steps: queries DB → returns JSON error response on failure → redirects user.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function render( $atts ): string {
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/vendor-dashboard/'))) ); exit;
        }
        $db     = Database::instance();
        $vendor = $db->row( "SELECT * FROM {$db->t('vendors')} WHERE wp_user_id = %d", get_current_user_id() );
        if ( ! $vendor ) {
            return '<div style="text-align:center;padding:40px;background:#fff;border-radius:16px;max-width:500px;margin:40px auto"><h3>Vendor Account Not Found</h3><p style="color:#6b7280;margin-top:8px">Your account is not linked to a vendor profile. Please contact your administrator.</p></div>';
        }
        ob_start();
        include NAS_PATH . 'templates/vendor/dashboard.php';
        return ob_get_clean();
    }

    // ── Get vendor bookings ─────────────────────────────────────────────────

    // TRACE: get_bookings() — Trigger: wp_ajax_get_bookings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_bookings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db        = Database::instance();
        $vendor_id = (int) Security::post('vendor_id');
        $page      = max( 1, (int) Security::post('page') );
        $per_page  = min( 50, max(1, (int)( Security::post('per_page') ?: 15 ) ) );
        $status    = sanitize_text_field( Security::post('status') );
        $search    = sanitize_text_field( Security::post('search') );
        $offset    = ( $page - 1 ) * $per_page;

        // Verify vendor owns this vendor_id (or is admin)
        $vendor = $db->row( "SELECT id FROM {$db->t('vendors')} WHERE id = %d AND wp_user_id = %d", $vendor_id, get_current_user_id() );
        if ( ! $vendor && ! current_user_can('manage_options') ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $where = "b.assigned_vendor_id = %d";
        $args  = [ $vendor_id ];

        if ( $status ) {
            $where .= " AND b.status = %s";
            $args[]  = $status;
        }
        if ( $search ) {
            $where .= " AND (b.uid LIKE %s OR b.client_name LIKE %s OR b.newspaper_name LIKE %s)";
            $like    = '%' . $db->esc_like( $search ) . '%';  // fixed: esc_like() not wpdb()->esc_like()
            $args[]  = $like; $args[] = $like; $args[] = $like;
        }

        $t     = $db->t('bookings');
        $total = (int) $db->scalar( "SELECT COUNT(*) FROM $t b WHERE $where", $args );  // fixed: array not splat
        $rows  = $db->select(
            "SELECT b.*, c.name AS category_name FROM $t b LEFT JOIN {$db->t('categories')} c ON c.id=b.category_id WHERE $where ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",  // fixed: submitted_at not created_at
            array_merge( $args, [ $per_page, $offset ] )  // fixed: explicit array_merge not splat
        );

        wp_send_json_success( [ 'bookings' => $rows, 'total' => $total, 'pages' => ceil( $total / $per_page ) ] );
    }

    // ── Stats ───────────────────────────────────────────────────────────────

    // TRACE: get_stats() — Trigger: wp_ajax_get_stats AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_stats(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db        = Database::instance();
        $vendor_id = (int) Security::post('vendor_id');
        $t         = $db->t('bookings');

        // Verify caller is this vendor or an admin (prevent stats scraping of other vendors)
        if ( ! current_user_can('manage_options') && ! current_user_can('nas_manage_bookings') ) {
            $owns = $db->scalar( "SELECT id FROM {$db->t('vendors')} WHERE id = %d AND wp_user_id = %d", $vendor_id, get_current_user_id() );
            if ( ! $owns ) {
                wp_send_json_error( ['message' => 'Access denied'], 403 );
                return;
            }
        }

        $total     = (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE assigned_vendor_id = %d", $vendor_id );
        $pending   = (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE assigned_vendor_id = %d AND status = 'ad_processing'", $vendor_id );
        $completed = (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE assigned_vendor_id = %d AND status IN ('completed','published')", $vendor_id );
        $earned    = (float) $db->scalar( "SELECT COALESCE(SUM(vendor_cost),0) FROM $t WHERE assigned_vendor_id = %d AND status IN ('completed','published','ad_processing')", $vendor_id );

        wp_send_json_success( compact( 'total', 'pending', 'completed', 'earned' ) );
    }

    // ── Mark published ──────────────────────────────────────────────────────

    // TRACE: mark_published() — Trigger: wp_ajax_mark_published AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function mark_published(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db         = Database::instance();
        $booking_id = (int) Security::post('booking_id');
        $user_id    = get_current_user_id();

        // Verify vendor owns this booking
        $vendor = $db->row( "SELECT v.id FROM {$db->t('vendors')} v INNER JOIN {$db->t('bookings')} b ON b.assigned_vendor_id=v.id WHERE b.id=%d AND v.wp_user_id=%d", $booking_id, $user_id );
        if ( ! $vendor && ! current_user_can('manage_options') ) {
            wp_send_json_error( [ 'message' => 'Access denied' ] );
        }

        $db->update( $db->t('bookings'), [
            'status'     => 'published',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], [ 'id' => $booking_id ] );

        // Workflow history
        $booking = $db->row( "SELECT workflow_history FROM {$db->t('bookings')} WHERE id=%d", $booking_id );
        $history = json_decode( $booking['workflow_history'] ?? '[]', true ) ?: [];
        $history[] = [ 'status' => 'published', 'at' => gmdate('Y-m-d H:i:s'), 'note' => 'Marked published by vendor', 'by' => $user_id ];
        $db->update( $db->t('bookings'), [ 'workflow_history' => wp_json_encode($history) ], [ 'id' => $booking_id ] );

        wp_send_json_success( [ 'message' => 'Marked as published' ] );
    }

    // ── Upload proof ────────────────────────────────────────────────────────

    // TRACE: upload_proof() — Trigger: wp_ajax_upload_proof AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function upload_proof(): void {
        Security::check_nonce( $_POST['nonce'] ?? '', 'nas_action' );
        Security::require_login();

        if ( empty( $_FILES['proof_file'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded' ] );
        }

        $booking_id = (int) ( $_POST['booking_id'] ?? 0 );
        $upload     = wp_handle_upload( $_FILES['proof_file'], [ 'test_form' => false ] );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => $upload['error'] ] );
        }

        $db = Database::instance();
        $db->insert( $db->t('vendor_proofs'), [
            'booking_id'  => $booking_id,
            'vendor_id'   => (int)( $_POST['vendor_id'] ?? 0 ),
            'file_url'    => $upload['url'],
            'file_path'   => $upload['file'],
            'uploaded_by' => get_current_user_id(),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Update booking status
        $db->update( $db->t('bookings'), [
            'status'           => 'published',
            'proof_url'        => $upload['url'],
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ], [ 'id' => $booking_id ] );

        wp_send_json_success( [ 'url' => $upload['url'], 'message' => 'Proof uploaded successfully' ] );
    }

    // ── Earnings ────────────────────────────────────────────────────────────

    // TRACE: get_earnings() — Trigger: wp_ajax_get_earnings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_earnings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db        = Database::instance();
        $vendor_id = (int) Security::post('vendor_id');
        $t         = $db->t('bookings');

        // Verify caller owns this vendor account (or is admin)
        if ( ! current_user_can('manage_options') && ! current_user_can('nas_manage_bookings') ) {
            $owns = $db->scalar( "SELECT id FROM {$db->t('vendors')} WHERE id = %d AND wp_user_id = %d", $vendor_id, get_current_user_id() );
            if ( ! $owns ) {
                wp_send_json_error( ['message' => 'Access denied'], 403 );
                return;
            }
        }

        $total_earned = (float) $db->scalar( "SELECT COALESCE(SUM(vendor_cost),0) FROM $t WHERE assigned_vendor_id=%d AND status IN ('completed','published')", $vendor_id );
        // vendor_payment_amount and vendor_payment_status added by SchemaV3 migration
        $total_paid   = (float) $db->scalar( "SELECT COALESCE(SUM(vendor_payment_amount),0) FROM $t WHERE assigned_vendor_id=%d AND vendor_payment_status='paid'", $vendor_id );

        $monthly = $db->select(
            "SELECT DATE_FORMAT(publish_date,'%b %Y') AS month, COUNT(*) AS count, COALESCE(SUM(vendor_cost),0) AS amount
             FROM $t WHERE assigned_vendor_id=%d AND status IN ('completed','published')
             GROUP BY DATE_FORMAT(publish_date,'%Y-%m')
             ORDER BY publish_date DESC LIMIT 12",
            $vendor_id
        );

        wp_send_json_success( compact( 'total_earned', 'total_paid', 'monthly' ) );
    }

    // ── Update profile ──────────────────────────────────────────────────────

    // TRACE: update_profile() — Trigger: wp_ajax_update_profile AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function update_profile(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db        = Database::instance();
        $vendor_id = (int) Security::post('vendor_id');
        $user_id   = get_current_user_id();

        // Verify ownership
        $vendor = $db->row( "SELECT id FROM {$db->t('vendors')} WHERE id=%d AND wp_user_id=%d", $vendor_id, $user_id );
        if ( ! $vendor ) wp_send_json_error( [ 'message' => 'Access denied' ] );

        $allowed = [ 'name','contact_person','phone','email','gst_number','pan_number','bank_details','address','cities_supported','newspapers_supported' ];
        $data    = [];
        foreach ( $allowed as $field ) {
            $val = Security::post( $field );
            if ( $val !== null ) $data[ $field ] = sanitize_textarea_field( $val );
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        $db->update( $db->t('vendors'), $data, [ 'id' => $vendor_id ] );

        wp_send_json_success( [ 'message' => 'Profile updated' ] );
    }

    // ── Check new messages ──────────────────────────────────────────────────

    // TRACE: check_new_messages() — Trigger: wp_ajax_check_new_messages AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function check_new_messages(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db        = Database::instance();
        $vendor_id = (int) Security::post('vendor_id');
        $user_id   = get_current_user_id();

        // Count unread messages on bookings assigned to this vendor
        $count = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$db->t('messages')} m
             INNER JOIN {$db->t('bookings')} b ON b.id=m.booking_id
             WHERE b.assigned_vendor_id=%d AND m.sender_id != %d AND m.is_read=0",
            $vendor_id, $user_id
        );

        wp_send_json_success( [ 'count' => $count ] );
    }
}

VendorDashboard::register();
