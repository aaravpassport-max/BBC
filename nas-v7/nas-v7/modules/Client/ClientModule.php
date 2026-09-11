<?php
namespace NAS\Modules\Client;

use NAS\Core\ModuleManager;
use NAS\Core\Database;
use NAS\Core\Security;
use NAS\Core\Cache;
use NAS\Core\Helpers;

class ClientModule extends \NAS\Core\Module {
    public function key(): string { return 'client'; }

    public function register(): void {
        // NOTE: nas_client_dashboard shortcode is owned by ClientDashboard::register()
        // which serves the React bundle. Do NOT add_shortcode here — it would
        // overwrite the React version with the old PHP template.

        // ── Profile (aliases for both old and new action names) ──────────────
        add_action( 'wp_ajax_nas_get_profile',             [ ClientController::class, 'get_profile' ] );
        add_action( 'wp_ajax_nas_get_client_profile',      [ ClientController::class, 'get_profile' ] );
        add_action( 'wp_ajax_nas_update_profile',          [ ClientController::class, 'update_profile' ] );
        add_action( 'wp_ajax_nas_update_client_profile',   [ ClientController::class, 'update_profile' ] );

        // ── Stats ────────────────────────────────────────────────────────────
        add_action( 'wp_ajax_nas_get_dashboard_stats',     [ ClientController::class, 'get_dashboard_stats' ] );

        // ── Bookings ─────────────────────────────────────────────────────────
        add_action( 'wp_ajax_nas_get_client_bookings',     [ ClientController::class, 'get_client_bookings' ] );
        add_action( 'wp_ajax_nas_get_booking_detail',      [ ClientController::class, 'get_booking_detail' ] );
        add_action( 'wp_ajax_nas_get_booking_timeline',    [ ClientController::class, 'get_booking_timeline' ] );
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)
        // NOTE: nas_initiate_payment stub removed. The real payment handler is
        // PaymentModule::nas_create_payment_order (Razorpay/PayU/Stripe). No template calls this action.

        // ── Materials / Proof ────────────────────────────────────────────────
        // nas_get_materials and nas_upload_material are owned by MaterialModule.
        // It uses nas_ad_materials table, version tracking, MaterialService (status + notifications).
        // ClientModule's old stubs returned {booking} instead of {materials:[]}, breaking the tab.
        add_action( 'wp_ajax_nas_upload_document',         [ ClientController::class, 'upload_document' ] );

        // ── Wallet ───────────────────────────────────────────────────────────
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)

        // ── Support tickets ──────────────────────────────────────────────────
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)

        // ── Chat / Messages ──────────────────────────────────────────────────
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)
        // Removed duplicate: this action is canonical in its own module (WalletModule/SupportTicketModule/ChatModule/InvoiceModule)

        // ── Notifications ────────────────────────────────────────────────────
        add_action( 'wp_ajax_nas_get_notifications',       [ ClientController::class, 'get_notifications' ] );
        add_action( 'wp_ajax_nas_mark_notification_read',  [ ClientController::class, 'mark_notification_read' ] );
    }

    public function boot(): void {}

    // TRACE: render_dashboard() — Trigger: wp_ajax_render_dashboard AJAX action.
    //        Steps: queries DB → returns JSON error response on failure → redirects user.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function render_dashboard( $atts ): string {
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/client-dashboard/'))) ); exit;
        }
        ob_start();
        include NAS_PATH . 'templates/client/dashboard.php';
        return ob_get_clean();
    }
}
class ClientRepository {
    private Database $db;

    public function __construct() {
        $this->db = Database::instance();
    }

    // TRACE: find_by_wp_user() — Trigger: wp_ajax_find_by_wp_user AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find_by_wp_user( int $wp_user_id ): ?array {
        return $this->db->row( "SELECT * FROM {$this->db->t('clients')} WHERE wp_user_id = %d", $wp_user_id );
    }

    // TRACE: find() — Trigger: wp_ajax_find AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find( int $id ): ?array {
        return $this->db->row( "SELECT * FROM {$this->db->t('clients')} WHERE id = %d", $id );
    }

    // TRACE: create() — Trigger: wp_ajax_create AJAX action.
    //        Steps: inserts DB row → updates DB row → returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public function create( array $data ): int {
        $data['uid'] = Helpers::generate_uid( 'CLT' );
        $data['created_at'] = current_time( 'mysql' );
        $data['updated_at'] = current_time( 'mysql' );
        $this->db->insert( $this->db->t('clients'), $data );
        return (int) $this->db->last_insert_id();
    }

    // TRACE: Called by ClientController::update_profile() when an existing client row exists.
    //        Precondition: $id is a valid client row id; $data is sanitised.
    //        Postcondition: client row updated; updated_at refreshed; returns true if any row was changed.
    //        Edge case: empty $data → wpdb->update with only updated_at (harmless no-op effectively).
    // TRACE: update() — Trigger: wp_ajax_update AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function update( int $id, array $data ): bool {
        $data['updated_at'] = current_time( 'mysql' );
        return $this->db->update( $this->db->t('clients'), $data, [ 'id' => $id ] );
    }

    // TRACE: get_stats() — Trigger: wp_ajax_get_stats AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function get_stats( int $client_id ): array {
        $db = $this->db;
        $t  = $db->t( 'bookings' );
        return [
            'total'     => (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE client_id = %d", $client_id ),
            'active'    => (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE client_id = %d AND status NOT IN ('completed','rejected','not_able_to_process','not_eligible','no_service')", $client_id ),
            'completed' => (int) $db->scalar( "SELECT COUNT(*) FROM $t WHERE client_id = %d AND status = 'completed'", $client_id ),
            // TRACE: final_price column does not exist → canonical column is total_amount (fixed)
            'spent'     => (float) $db->scalar( "SELECT COALESCE(SUM(total_amount),0) FROM $t WHERE client_id = %d AND payment_status = 'paid'", $client_id ),
        ];
    }

    // TRACE: get_notifications() — Trigger: wp_ajax_get_notifications AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function get_notifications( int $wp_user_id, int $limit = 20 ): array {
        return $this->db->select(
            "SELECT * FROM {$this->db->t('notifications')} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $wp_user_id, $limit
        );
    }

    // TRACE: mark_notification_read() — Trigger: wp_ajax_mark_notification_read AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function mark_notification_read( int $id, int $user_id ): void {
        $this->db->update( $this->db->t('notifications'), [ 'is_read' => 1 ], [ 'id' => $id, 'user_id' => $user_id ] );
    }

    // TRACE: unread_notification_count() — Trigger: wp_ajax_unread_notification_count AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function unread_notification_count( int $user_id ): int {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM {$this->db->t('notifications')} WHERE user_id = %d AND is_read = 0",
            $user_id
        );
    }
}

class ClientController {
    // TRACE: repo() — Trigger: wp_ajax_repo AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    private static function repo(): ClientRepository {
        return new ClientRepository();
    }

    // TRACE: get_profile() — Trigger: wp_ajax_get_profile AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_profile(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $uid    = get_current_user_id();
        $client = self::repo()->find_by_wp_user( $uid );
        $user   = wp_get_current_user();
        wp_send_json_success( [
            'client'     => $client,
            'wp_name'    => $user->display_name,
            'wp_email'   => $user->user_email,
        ] );
    }

    // TRACE: update_profile() — Trigger: wp_ajax_update_profile AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function update_profile(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $uid    = get_current_user_id();
        $client = self::repo()->find_by_wp_user( $uid );
        $data   = [
            'name'    => Security::post( 'name', 'text' ),
            'phone'   => Security::post( 'phone', 'text' ),
            'company' => Security::post( 'company', 'text' ),
            'gst'     => Security::post( 'gst', 'text' ),
            'address' => Security::post( 'address', 'textarea' ),
            'city'    => Security::post( 'city', 'text' ),
            'state'   => Security::post( 'state', 'text' ),
        ];
        if ( $client ) {
            self::repo()->update( $client['id'], $data );
        } else {
            $data['wp_user_id'] = $uid;
            $data['email']      = wp_get_current_user()->user_email;
            self::repo()->create( $data );
        }
        wp_send_json_success( [ 'message' => 'Profile updated.' ] );
    }

    // TRACE: get_notifications() — Trigger: wp_ajax_get_notifications AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_notifications(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $notes = self::repo()->get_notifications( get_current_user_id() );
        $count = self::repo()->unread_notification_count( get_current_user_id() );
        wp_send_json_success( [ 'notifications' => $notes, 'unread' => $count ] );
    }

    // TRACE: mark_notification_read() — Trigger: wp_ajax_mark_notification_read AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function mark_notification_read(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $id = (int) Security::post( 'id', 'int' );
        self::repo()->mark_notification_read( $id, get_current_user_id() );
        wp_send_json_success();
    }

    // TRACE: get_dashboard_stats() — Trigger: wp_ajax_get_dashboard_stats AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_dashboard_stats(): void {
        while ( ob_get_level() > 0 ) ob_end_clean();
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $db     = Database::instance();
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if ( ! $client ) {
            wp_send_json_success(['total_bookings'=>0,'active_bookings'=>0,'completed_bookings'=>0,'total_spent'=>0]);
            return;
        }
        $cid = (int)$client['id'];
        $t   = $db->t('bookings');
        $data = [
            'total_bookings'     => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE client_id=%d", $cid),
            'active_bookings'    => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE client_id=%d AND status NOT IN ('published','completed','rejected','cancelled')", $cid),
            'completed_bookings' => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE client_id=%d AND status IN ('published','completed')", $cid),
            'total_spent'        => (float)$db->scalar("SELECT COALESCE(SUM(total_amount),0) FROM $t WHERE client_id=%d AND payment_status='paid'", $cid),
        ];
        wp_send_json_success($data);
    }

    // TRACE: get_booking_timeline() — Trigger: wp_ajax_get_booking_timeline AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_booking_timeline(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $uid = Security::post( 'booking_uid', 'text' );
        $db  = Database::instance();
        $b   = $db->row( "SELECT * FROM {$db->t('bookings')} WHERE uid = %s", $uid );
        if ( ! $b ) { wp_send_json_error( 'Not found' ); return; }
        // verify ownership
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if ( ! $client || (int)$b['client_id'] !== (int)$client['id'] ) {
            wp_send_json_error( 'Unauthorized' ); return;
        }
        $history = json_decode( $b['workflow_history'] ?? '[]', true );
        wp_send_json_success( [
            'booking' => $b,
            'history' => $history,
            'stages'  => Helpers::workflow_stages(),
        ] );
    }

    // TRACE: upload_document() — Trigger: wp_ajax_upload_document AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: empty input values handled.
    public static function upload_document(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        if ( empty( $_FILES['file'] ) ) { wp_send_json_error( 'No file.' ); return; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload( $_FILES['file'], [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) { wp_send_json_error( $upload['error'] ); return; }
        wp_send_json_success( [ 'url' => $upload['url'], 'file' => $upload['file'] ] );
    }

    // TRACE: get_invoice() — Trigger: wp_ajax_get_invoice AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_invoice(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $uid = Security::post( 'booking_uid', 'text' );
        $db  = Database::instance();
        $b   = $db->row( "SELECT * FROM {$db->t('bookings')} WHERE uid = %s", $uid );
        if ( ! $b ) { wp_send_json_error( 'Not found' ); return; }
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if ( ! $client || (int)$b['client_id'] !== (int)$client['id'] ) {
            wp_send_json_error( 'Unauthorized' ); return;
        }
        wp_send_json_success( [ 'booking' => $b, 'client' => $client ] );
    }

        // initiate_payment() removed — real payment is handled by PaymentModule (Razorpay/PayU/Stripe)
// ── get_client_bookings ─────────────────────────────────────────────────
    // TRACE: get_client_bookings() — Trigger: wp_ajax_get_client_bookings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_client_bookings(): void {
        while ( ob_get_level() > 0 ) ob_end_clean();
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if ( ! $client ) { wp_send_json_success(['bookings'=>[],'total'=>0]); return; }

        $db       = Database::instance();
        $page     = max(1,(int)Security::post('page',1));
        $per_page = max(1,min(50,(int)Security::post('per_page',10)));
        $offset   = ($page-1) * $per_page;
        $search   = '%'.sanitize_text_field(Security::post('search','')).'%';
        $status   = sanitize_text_field(Security::post('status',''));
        $client_id= (int)$client['id'];

        $where = "b.client_id=%d";
        $args  = [$client_id];
        if ($status) { $where .= " AND b.status=%s"; $args[] = $status; }
        if (Security::post('search','')) { $where .= " AND (b.uid LIKE %s OR b.client_name LIKE %s OR b.newspaper_name LIKE %s)"; $args[]=$search;$args[]=$search;$args[]=$search; }

        $total    = (int)$db->scalar("SELECT COUNT(*) FROM {$db->t('bookings')} b WHERE $where", $args);
        $bookings = $db->select("SELECT b.*, b.uid AS booking_uid,
                n.name AS newspaper_name, c.name AS category_name, ci.name AS city_name
            FROM {$db->t('bookings')} b
            LEFT JOIN {$db->t('newspapers')} n  ON n.id=b.newspaper_id
            LEFT JOIN {$db->t('categories')} c  ON c.id=b.category_id
            LEFT JOIN {$db->t('cities')} ci      ON ci.id=b.city_id
            WHERE $where ORDER BY b.submitted_at DESC LIMIT %d OFFSET %d",
            array_merge($args,[$per_page,$offset]));

        wp_send_json_success(['bookings'=>$bookings,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
    }

    // ── get_booking_detail ──────────────────────────────────────────────────
    // Dashboard JS sends {booking_id: id}. BookingModule used 'id'. Accept both — always.
    // TRACE: get_booking_detail() — Trigger: wp_ajax_get_booking_detail AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: id=0 rejected.
    public static function get_booking_detail(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        // Accept 'booking_id' (sent by dashboard JS) OR 'id' (legacy / BookingModule compat)
        $id       = (int)( $_POST['booking_id'] ?? $_POST['id'] ?? 0 );
        $db       = Database::instance();
        $is_staff = current_user_can('nas_manage_bookings') || current_user_can('manage_options');

        if ( ! $id ) { wp_send_json_error(['message'=>'Booking ID required']); return; }

        if ( $is_staff ) {
            $booking = $db->row(
                "SELECT b.*, b.uid AS booking_uid,
                        n.name AS newspaper_name, cat.name AS category_name, ci.name AS city_name
                 FROM {$db->t('bookings')} b
                 LEFT JOIN {$db->t('newspapers')} n   ON n.id=b.newspaper_id
                 LEFT JOIN {$db->t('categories')} cat ON cat.id=b.category_id
                 LEFT JOIN {$db->t('cities')} ci       ON ci.id=b.city_id
                 WHERE b.id=%d", $id
            );
        } else {
            $client = self::repo()->find_by_wp_user( get_current_user_id() );
            if ( ! $client ) { wp_send_json_error(['message'=>'Client not found']); return; }
            $booking = $db->row(
                "SELECT b.*, b.uid AS booking_uid,
                        n.name AS newspaper_name, cat.name AS category_name, ci.name AS city_name
                 FROM {$db->t('bookings')} b
                 LEFT JOIN {$db->t('newspapers')} n   ON n.id=b.newspaper_id
                 LEFT JOIN {$db->t('categories')} cat ON cat.id=b.category_id
                 LEFT JOIN {$db->t('cities')} ci       ON ci.id=b.city_id
                 WHERE b.id=%d AND b.client_id=%d", $id, $client['id']
            );
        }

        if ( ! $booking ) { wp_send_json_error(['message'=>'Booking not found or access denied']); return; }

        $history  = json_decode( $booking['workflow_history'] ?? '[]', true ) ?: [];
        $payments = $db->select("SELECT * FROM {$db->t('payments')} WHERE booking_id=%d ORDER BY created_at DESC", $id);
        global $wpdb;
        $messages = $db->select(
            "SELECT m.*, u.display_name AS sender_name
             FROM {$db->t('messages')} m
             LEFT JOIN {$wpdb->users} u ON u.ID=m.sender_id
             WHERE m.booking_id=%d ORDER BY m.created_at ASC LIMIT 50", $id
        );

        wp_send_json_success([
            'booking'          => $booking,
            'payments'         => $payments,
            'messages'         => $messages,
            'workflow_history' => $history,
        ]);
    }

    // ── get_materials ───────────────────────────────────────────────────────
    // TRACE: get_materials() — Trigger: wp_ajax_get_materials AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_materials(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $id     = (int)Security::post('booking_id');
        $db     = Database::instance();
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if (!$id || !$client) { wp_send_json_success(['booking'=>null]); return; }

        $booking = $db->row("SELECT * FROM {$db->t('bookings')} WHERE id=%d AND client_id=%d", $id, $client['id']);
        wp_send_json_success(['booking'=>$booking]);
    }

    // ── upload_material ─────────────────────────────────────────────────────
    // TRACE: upload_material() — Trigger: wp_ajax_upload_material AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function upload_material(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $id      = (int)Security::post('booking_id');
        $db      = Database::instance();
        $client  = self::repo()->find_by_wp_user( get_current_user_id() );
        if (!$id || !$client) { wp_send_json_error(['message'=>'Invalid booking']); return; }

        $booking = $db->row("SELECT id FROM {$db->t('bookings')} WHERE id=%d AND client_id=%d", $id, $client['id']);
        if (!$booking) { wp_send_json_error(['message'=>'Booking not found']); return; }

        // Handle text upload
        $ad_text = sanitize_textarea_field(Security::post('ad_text',''));
        if ($ad_text) {
            $db->update($db->t('bookings'), ['ad_content'=>$ad_text,'updated_at'=>gmdate('Y-m-d H:i:s')], ['id'=>$id]);
            wp_send_json_success(['message'=>'Ad content saved successfully.']);
            return;
        }

        // Handle file upload
        // TRACE: material_url column does not exist on bookings. Files go into nas_ad_materials table.
        //        Precondition: booking verified as belonging to this client above.
        //        Postcondition: file saved via WP upload API; row inserted into ad_materials; success returned.
        //        Edge case: upload error → error message returned, no DB write.
        if (!empty($_FILES['material_file'])) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            $upload = wp_handle_upload($_FILES['material_file'],['test_form'=>false]);
            if (isset($upload['error'])) { wp_send_json_error(['message'=>$upload['error']]); return; }
            $db->insert($db->t('ad_materials'), [
                'booking_id'    => $id,
                'uploader_id'   => get_current_user_id(),
                'file_url'      => $upload['url'],
                'file_name'     => basename($upload['file']),
                'file_type'     => $_FILES['material_file']['type'] ?? '',
                'file_size'     => filesize($upload['file']),
                'material_type' => 'file',
                'status'        => 'pending',
                'version'       => 1,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
            wp_send_json_success(['url'=>$upload['url'],'message'=>'File uploaded successfully.']);
            return;
        }

        wp_send_json_error(['message'=>'No content or file provided.']);
    }

    // ── get_wallet ──────────────────────────────────────────────────────────
    // TRACE: get_wallet() — Trigger: wp_ajax_get_wallet AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_wallet(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db     = Database::instance();
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if (!$client) { wp_send_json_success(['balance'=>0,'transactions'=>[]]); return; }

        $wallet = $db->row("SELECT * FROM {$db->t('wallet')} WHERE client_id=%d", $client['id']);
        $txns   = $db->select("SELECT * FROM {$db->t('wallet_transactions')} WHERE client_id=%d ORDER BY created_at DESC LIMIT 50", $client['id']) ?: [];
        wp_send_json_success(['balance'=>(float)($wallet['balance']??0),'transactions'=>$txns]);
    }

    // ── get_my_tickets ──────────────────────────────────────────────────────
    // TRACE: get_my_tickets() — Trigger: wp_ajax_get_my_tickets AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_my_tickets(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $db     = Database::instance();
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if (!$client) { wp_send_json_success(['tickets'=>[]]); return; }

        $tickets = $db->select("SELECT * FROM {$db->t('support_tickets')} WHERE client_id=%d ORDER BY created_at DESC LIMIT 50", $client['id']) ?: [];
        wp_send_json_success(['tickets'=>$tickets]);
    }

    // ── get_ticket_detail ───────────────────────────────────────────────────
    // TRACE: get_ticket_detail() — Trigger: wp_ajax_get_ticket_detail AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_ticket_detail(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $id     = (int)Security::post('ticket_id');
        $db     = Database::instance();
        $client = self::repo()->find_by_wp_user( get_current_user_id() );
        if (!$id || !$client) { wp_send_json_error(['message'=>'Not found']); return; }

        $ticket  = $db->row("SELECT * FROM {$db->t('support_tickets')} WHERE id=%d AND client_id=%d", $id, $client['id']);
        if (!$ticket) { wp_send_json_error(['message'=>'Ticket not found']); return; }

        $replies = $db->select("SELECT * FROM {$db->t('ticket_replies')} WHERE ticket_id=%d ORDER BY created_at ASC", $id) ?: [];
        wp_send_json_success(['ticket'=>$ticket,'replies'=>$replies]);
    }

    // ── reply_ticket ────────────────────────────────────────────────────────
    // TRACE: reply_ticket() — Trigger: wp_ajax_reply_ticket AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function reply_ticket(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $ticket_id = (int)Security::post('ticket_id');
        $message   = sanitize_textarea_field(Security::post('message',''));
        $db        = Database::instance();
        $client    = self::repo()->find_by_wp_user( get_current_user_id() );

        if (!$ticket_id || !$message || !$client) { wp_send_json_error(['message'=>'Missing required fields']); return; }

        $ticket = $db->row("SELECT * FROM {$db->t('support_tickets')} WHERE id=%d AND client_id=%d", $ticket_id, $client['id']);
        if (!$ticket) { wp_send_json_error(['message'=>'Ticket not found']); return; }

        // Try inserting to ticket_replies table; if table doesn't exist, append to description
        $inserted = $db->insert($db->t('ticket_replies'), [
            'ticket_id'   => $ticket_id,
            'sender_type' => 'client',
            'sender_name' => wp_get_current_user()->display_name,
            'message'     => $message,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        // Notify admin
        wp_mail(get_option('admin_email'), "Reply on Ticket #{$ticket['ticket_uid']}",
            "Client replied to ticket #{$ticket['ticket_uid']}:\n\n{$message}");

        wp_send_json_success(['message'=>'Reply sent.']);
    }

    // ── get_messages ────────────────────────────────────────────────────────
    // Accepts both 'nas_action' (client/staff/moderation dashboards) and
    // 'nas_admin_nonce' (admin request-detail page) to prevent the 403 mismatch
    // that occurs when the admin booking detail page calls this endpoint.
    // TRACE: get_messages() — Trigger: wp_ajax_get_messages AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_messages(): void {
        $nonce = Security::post('nonce') ?: ( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'nas_action' ) && ! wp_verify_nonce( $nonce, 'nas_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ], 403 );
        }
        Security::require_login();

        $booking_id  = (int)Security::post('booking_id');
        $db          = Database::instance();
        $is_staff    = current_user_can('nas_manage_bookings') || current_user_can('manage_options');

        if ( ! $booking_id ) { wp_send_json_success(['messages'=>[]]); return; }

        // Staff/admin can read any booking's messages; client can only read own
        if ( ! $is_staff ) {
            $client  = self::repo()->find_by_wp_user( get_current_user_id() );
            if ( ! $client ) { wp_send_json_success(['messages'=>[]]); return; }
            $booking = $db->row("SELECT id FROM {$db->t('bookings')} WHERE id=%d AND client_id=%d", $booking_id, $client['id']);
            if ( ! $booking ) { wp_send_json_success(['messages'=>[]]); return; }
        }

        $messages = $db->select("SELECT * FROM {$db->t('messages')} WHERE booking_id=%d ORDER BY created_at ASC LIMIT 200", $booking_id) ?: [];
        wp_send_json_success(['messages'=>$messages]);
    }

    // ── send_message ────────────────────────────────────────────────────────
    // Same dual-nonce fix as get_messages — admin request-detail sends 'nas_admin_nonce'.
    // TRACE: send_message() — Trigger: wp_ajax_send_message AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function send_message(): void {
        $nonce = Security::post('nonce') ?: ( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'nas_action' ) && ! wp_verify_nonce( $nonce, 'nas_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ], 403 );
        }
        Security::require_login();

        $booking_id  = (int)Security::post('booking_id');
        $message     = sanitize_textarea_field(Security::post('message',''));
        $channel     = sanitize_key(Security::post('channel','platform'));
        $db          = Database::instance();
        $is_staff    = current_user_can('nas_manage_bookings') || current_user_can('manage_options');

        if (!$booking_id || !$message) { wp_send_json_error(['message'=>'Missing fields']); return; }

        // For non-staff: verify booking belongs to this client
        if ( ! $is_staff ) {
            $client  = self::repo()->find_by_wp_user( get_current_user_id() );
            if ( ! $client ) { wp_send_json_error(['message'=>'Client not found']); return; }
            $booking = $db->row("SELECT id FROM {$db->t('bookings')} WHERE id=%d AND client_id=%d", $booking_id, $client['id']);
            if ( ! $booking ) { wp_send_json_error(['message'=>'Booking not found']); return; }
        } else {
            // Staff: just verify booking exists
            $booking = $db->row("SELECT id FROM {$db->t('bookings')} WHERE id=%d", $booking_id);
            if ( ! $booking ) { wp_send_json_error(['message'=>'Booking not found']); return; }
        }

        $user        = wp_get_current_user();
        $sender_role = $is_staff ? 'admin' : 'client';
        $db->insert($db->t('messages'), [
            'booking_id'  => $booking_id,
            'sender_id'   => get_current_user_id(),
            'sender_name' => $user->display_name,
            'sender_role' => $sender_role,
            'message'     => $message,
            'channel'     => in_array($channel, ['platform','whatsapp','email'], true) ? $channel : 'platform',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        wp_send_json_success(['message'=>'Message sent.']);
    }
}

// ── Create client account from booking confirmation ────────────────────────────
add_action('wp_ajax_nopriv_nas_create_client_account_from_booking', 'NAS\\Modules\\Client\\nas_create_client_from_booking');
add_action('wp_ajax_nas_create_client_account_from_booking',         'NAS\\Modules\\Client\\nas_create_client_from_booking');
// TRACE: nas_create_client_from_booking() — Trigger: wp_ajax_nas_create_client_from_booking file-scope handler.
//        Steps: verifies nonce → reads POST input → queries DB → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_create_client_from_booking(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_action');
    if (is_user_logged_in()) { wp_send_json_error(['message' => 'You are already logged in.']); }

    $booking_id = (int)\NAS\Core\Security::post('booking_id');
    $password   = $_POST['password'] ?? '';

    if (!$booking_id)       wp_send_json_error(['message' => 'Invalid booking.']);
    if (strlen($password) < 8) wp_send_json_error(['message' => 'Password must be at least 8 characters.']);

    $db      = \NAS\Core\Database::instance();
    $booking = $db->row("SELECT b.*, cl.name, cl.email, cl.phone FROM {$db->t('bookings')} b LEFT JOIN {$db->t('clients')} cl ON cl.id=b.client_id WHERE b.id=%d", $booking_id);
    if (!$booking) wp_send_json_error(['message' => 'Booking not found.']);

    $email = sanitize_email($booking['email'] ?? '');
    $name  = sanitize_text_field($booking['name'] ?? $booking['client_name'] ?? 'Client');

    if (!$email)   wp_send_json_error(['message' => 'No email address on this booking.']);
    if (email_exists($email)) wp_send_json_error(['message' => 'An account with this email already exists. Please log in.']);

    // Create username from name
    $username_base = sanitize_user(strtolower(str_replace(' ', '.', $name)));
    $username      = $username_base;
    $suffix        = 1;
    while (username_exists($username)) { $username = $username_base . $suffix++; }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) wp_send_json_error(['message' => $user_id->get_error_message()]);

    wp_update_user(['ID' => $user_id, 'display_name' => $name, 'role' => 'nas_client']);

    // Link WP user to client record
    if (!empty($booking['client_id'])) {
        $db->update($db->t('clients'), ['wp_user_id' => $user_id], ['id' => (int)$booking['client_id']]);
    }

    // Auto-login
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, false);

    wp_mail($email, 'Welcome to '.\NAS\Core\Config::instance()->get('brand_name',get_bloginfo('name')).' — Account Created',
        "Hi {$name},\n\nYour account has been created.\n\nEmail: {$email}\nUsername: {$username}\n\nYou can now log in to track your bookings at: ".home_url('/newspaper-ad-login/'));

    wp_send_json_success(['message' => 'Account created and you are now logged in.']);
}

// ── Newsletter subscription ────────────────────────────────────────────────────
add_action('wp_ajax_nas_subscribe_newsletter',        'NAS\\Modules\\Client\\nas_subscribe_newsletter_handler');
add_action('wp_ajax_nopriv_nas_subscribe_newsletter', 'NAS\\Modules\\Client\\nas_subscribe_newsletter_handler');
// TRACE: nas_subscribe_newsletter_handler() — Trigger: wp_ajax_nas_subscribe_newsletter_handler file-scope handler.
//        Steps: verifies nonce → reads POST input → returns JSON success → returns JSON error on failure.
//        Output: JSON success/error response.
//        Edge cases: nonce failure → 403; missing params → error returned.
function nas_subscribe_newsletter_handler(): void {
    \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_action');
    $email = sanitize_email(\NAS\Core\Security::post('email'));
    if (!is_email($email)) wp_send_json_error(['message' => 'Invalid email address.']);
    $subscribers = (array)json_decode(get_option('nas_newsletter_subscribers','[]'), true);
    if (in_array($email, $subscribers)) wp_send_json_error(['message' => 'Already subscribed!']);
    $subscribers[] = $email;
    update_option('nas_newsletter_subscribers', wp_json_encode($subscribers), false);
    wp_mail(get_option('admin_email'), 'New Newsletter Subscriber — '.\NAS\Core\Config::instance()->get('brand_name',''), "New subscriber: {$email}");
    wp_send_json_success(['message' => 'Subscribed successfully!']);
}
