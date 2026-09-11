<?php
namespace NAS\Dashboards;

use NAS\Core\Security;
use NAS\Core\Database;

class StaffDashboard {
    public static function register(): void {
        add_shortcode( 'nas_staff_dashboard', [ self::class, 'render' ] );
        add_action( 'wp_ajax_nas_staff_get_assigned_bookings', [ self::class, 'get_assigned_bookings' ] );
        add_action( 'wp_ajax_nas_staff_update_booking_status', [ self::class, 'update_booking_status' ] );
        add_action( 'wp_ajax_nas_staff_get_today_tasks',       [ self::class, 'get_today_tasks' ] );
        add_action( 'wp_ajax_nas_staff_mark_task_done',        [ self::class, 'mark_task_done' ] );
        // Aliases used by staff dashboard JS (also registered via AjaxAliases)
        add_action( 'wp_ajax_nas_get_assigned_bookings', [ self::class, 'get_assigned_bookings' ] );
        add_action( 'wp_ajax_nas_get_today_tasks',       [ self::class, 'get_today_tasks' ] );
        add_action( 'wp_ajax_nas_mark_task_done',        [ self::class, 'mark_task_done' ] );
        add_action( 'wp_ajax_nas_update_booking_status', [ self::class, 'update_booking_status' ] );
    }

    // TRACE: render() — Trigger: wp_ajax_render AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function render( $atts ): string {
        if ( ! current_user_can( 'nas_staff' ) && ! current_user_can( 'nas_manager' ) && ! current_user_can( 'manage_options' ) ) {
            return '<div class="nas-no-access"><p>Access denied. Staff role required.</p></div>';
        }
        ob_start();
        include NAS_PATH . 'templates/staff/dashboard.php';
        return ob_get_clean();
    }

    // TRACE: get_assigned_bookings() — Trigger: wp_ajax_get_assigned_bookings AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_assigned_bookings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        if ( ! Security::is_admin_or_staff() ) { wp_send_json_error( 'Unauthorized' ); return; }
        $db     = Database::instance();
        $uid    = get_current_user_id();
        // FIX (audit): frontend (templates/staff/dashboard.php spLoadBookings()) sends
        // status/search/page/per_page as POST FormData — $_GET is always empty for this
        // request, so the status filter, search, and pagination were all silent no-ops.
        $status   = sanitize_text_field( Security::post( 'status' ) );
        $search   = sanitize_text_field( Security::post( 'search' ) );
        $page     = max( 1, (int) Security::post( 'page', 'int' ) );
        $per_page = min( 50, max( 1, (int) ( Security::post( 'per_page', 'int' ) ?: 15 ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        $args     = [];

        // Build WHERE safely using prepared placeholders — no raw interpolation
        $conditions = [];
        if ( ! current_user_can( 'manage_options' ) ) {
            $conditions[] = 'b.assigned_to = %d';
            $args[]       = $uid;
        }
        if ( $status ) {
            $conditions[] = 'b.status = %s';  // fixed: was esc_sql() raw interpolation
            $args[]       = $status;
        }
        if ( $search ) {
            $conditions[] = '(b.uid LIKE %s OR b.client_name LIKE %s OR b.newspaper_name LIKE %s)';
            $like         = '%' . $db->esc_like( $search ) . '%';
            $args[]       = $like; $args[] = $like; $args[] = $like;
        }
        $where = $conditions ? implode( ' AND ', $conditions ) : '1=1';

        $total = (int) $db->scalar( "SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where", $args );

        $rows = $db->select(
            "SELECT b.*, cl.name as client_name, cl.phone as client_phone, n.name as newspaper_name, cat.name as category_name, c.name as city_name
             FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id
             LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
             LEFT JOIN {$db->t('cities')} c ON c.id = b.city_id
             WHERE $where ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",
            array_merge( $args, [ $per_page, $offset ] )
        );
        wp_send_json_success( [ 'bookings' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ] );
    }

    // TRACE: update_booking_status() — Trigger: wp_ajax_update_booking_status AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function update_booking_status(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        if ( ! Security::is_admin_or_staff() ) { wp_send_json_error( 'Unauthorized' ); return; }
        // Fixed: was calling do_action('wp_ajax_nas_update_booking_status') which double-fires
        // the AJAX action and sends headers twice. Perform the update directly instead.
        $db         = Database::instance();
        $booking_id = (int) Security::post('booking_id', 'int');
        $status     = Security::post('status', 'text');
        $note       = Security::post('note', 'textarea') ?: '';
        $allowed    = [ 'under_review','ready_to_process','ad_processing','submitted_to_pub','published','completed' ];
        if ( ! $booking_id || ! in_array( $status, $allowed, true ) ) {
            wp_send_json_error( ['message' => 'Invalid booking ID or status.'] );
            return;
        }
        $booking = $db->row( "SELECT workflow_history FROM {$db->t('bookings')} WHERE id = %d", $booking_id );
        if ( ! $booking ) { wp_send_json_error( ['message' => 'Booking not found.'] ); return; }
        $history   = json_decode( $booking['workflow_history'] ?? '[]', true ) ?: [];
        $history[] = [ 'status' => $status, 'note' => $note, 'by' => get_current_user_id(), 'at' => gmdate('Y-m-d H:i:s') ];
        $db->update( $db->t('bookings'), [
            'status'           => $status,
            'workflow_history' => json_encode( $history ),
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ], [ 'id' => $booking_id ] );
        do_action( 'nas_booking_status_updated', [ 'booking_id' => $booking_id, 'new_status' => $status ] );
        wp_send_json_success( ['message' => 'Status updated.'] );
    }

    // TRACE: get_today_tasks() — Trigger: wp_ajax_get_today_tasks AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_today_tasks(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        if ( ! Security::is_admin_or_staff() ) { wp_send_json_error( 'Unauthorized' ); return; }
        $db   = Database::instance();
        $uid  = get_current_user_id();
        $rows = $db->select(
            "SELECT f.*, b.uid as booking_uid, cl.name as client_name
             FROM {$db->t('followups')} f
             LEFT JOIN {$db->t('bookings')} b ON b.id = f.booking_id
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             WHERE f.next_followup_date <= %s AND f.status = 'pending'
             AND (f.assigned_to = %d OR %d = 1)
             ORDER BY f.next_followup_date ASC LIMIT 50",
            current_time( 'Y-m-d' ),
            $uid,
            current_user_can( 'manage_options' ) ? 1 : 0
        );
        wp_send_json_success( [ 'tasks' => $rows ] );
    }

    // TRACE: mark_task_done() — Trigger: wp_ajax_mark_task_done AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function mark_task_done(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        if ( ! Security::is_admin_or_staff() ) { wp_send_json_error( 'Unauthorized' ); return; }
        // FIX (audit): frontend (templates/staff/dashboard.php spDoneTask()) sends the key
        // 'task_id', not 'id'. Accept 'task_id' with a fallback to 'id' for compatibility.
        $id = (int) ( Security::post( 'task_id', 'int' ) ?: Security::post( 'id', 'int' ) );
        if ( ! $id ) { wp_send_json_error( [ 'message' => 'task_id required' ] ); return; }
        $db = Database::instance();
        // FIX (audit): the followups table (database/Schema.php) has no `updated_at` column —
        // only id, booking_id, type, last_contact_date, next_followup_date, status, assigned_to,
        // notes. Writing updated_at caused every call here to fail silently (ghost success).
        $affected = $db->update( $db->t('followups'), [ 'status' => 'done' ], [ 'id' => $id ] );
        if ( $affected === 0 ) {
            wp_send_json_error( [ 'message' => 'Task not found or already updated.' ] );
            return;
        }
        wp_send_json_success();
    }
}
