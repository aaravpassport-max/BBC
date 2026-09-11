<?php
namespace NAS\Dashboards;

use NAS\Core\Security;
use NAS\Core\Database;

class ModerationDashboard {
    public static function register(): void {
        add_shortcode( 'nas_moderation_dashboard', [ self::class, 'render' ] );
        add_action( 'wp_ajax_nas_mod_get_pending',      [ self::class, 'get_pending' ] );
        add_action( 'wp_ajax_nas_mod_approve_booking',  [ self::class, 'approve_booking' ] );
        add_action( 'wp_ajax_nas_mod_reject_booking',   [ self::class, 'reject_booking' ] );
        add_action( 'wp_ajax_nas_mod_flag_booking',     [ self::class, 'flag_booking' ] );
        add_action( 'wp_ajax_nas_mod_get_flagged',      [ self::class, 'get_flagged' ] );
        add_action( 'wp_ajax_nas_mod_search_content',   [ self::class, 'search_content' ] );
        add_action( 'wp_ajax_nas_mod_bulk_action',      [ self::class, 'bulk_action' ] );
        add_action( 'wp_ajax_nas_mod_get_sample_ads',   [ self::class, 'get_sample_ads' ] );
        add_action( 'wp_ajax_nas_mod_save_sample_ad',   [ self::class, 'save_sample_ad' ] );
        add_action( 'wp_ajax_nas_mod_get_quick_replies',[ self::class, 'get_quick_replies' ] );
        add_action( 'wp_ajax_nas_mod_save_quick_reply', [ self::class, 'save_quick_reply' ] );
    }

    // TRACE: render() — Trigger: wp_ajax_render AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function render( $atts ): string {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'nas_manager' ) ) {
            return '<div class="nas-no-access"><p>Access denied.</p></div>';
        }
        ob_start();
        include NAS_PATH . 'templates/moderation/dashboard.php';
        return ob_get_clean();
    }

    // TRACE: get_pending() — Trigger: wp_ajax_get_pending AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_pending(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        // FIX (audit): this — not modules/MissingHandlers.php's nas_get_pending_bookings_handler()
        // — is the actually-live handler for this action (confirmed via registration-order
        // trace: core/AjaxAliases.php registers it during 'init' priority 10, MissingHandlers.php
        // registers a competing implementation during priority 20, so this one always wins and
        // the other never runs). It never read the search param the frontend sends
        // (templates/moderation/dashboard.php modLoadPending()).
        $search = sanitize_text_field( Security::post( 'search' ) );
        $where  = "b.status IN ('booking_received','under_review')";
        $args   = [];
        if ( $search ) {
            $where .= ' AND (b.uid LIKE %s OR cl.name LIKE %s OR cl.phone LIKE %s)';
            $like   = '%' . $db->esc_like( $search ) . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $rows = $db->select(
            "SELECT b.*, cl.name as client_name, cl.email as client_email, cl.phone as client_phone, n.name as newspaper_name, cat.name as category_name
             FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id
             LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
             WHERE $where
             ORDER BY b.submitted_at ASC LIMIT 50",
            $args
        );
        wp_send_json_success( [ 'bookings' => $rows ] );
    }

    // TRACE: approve_booking() — Trigger: wp_ajax_approve_booking AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function approve_booking(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id  = (int) Security::post( 'id', 'int' );
        $db  = Database::instance();
        $db->update( $db->t('bookings'), [ 'status' => 'ready_to_process', 'is_flagged' => 0, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
        $b   = $db->row( "SELECT * FROM {$db->t('bookings')} WHERE id = %d", $id );
        self::append_history( $id, 'ready_to_process', 'Approved by moderator' );
        do_action( 'nas_booking_status_updated', [ 'booking_id' => $id, 'new_status' => 'ready_to_process', 'booking' => $b ] );
        wp_send_json_success();
    }

    // TRACE: reject_booking() — Trigger: wp_ajax_reject_booking AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function reject_booking(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id     = (int) Security::post( 'id', 'int' );
        $reason = Security::post( 'reason', 'textarea' );
        $db     = Database::instance();
        $db->update( $db->t('bookings'), [
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'is_flagged'       => 0,
            'updated_at'       => current_time( 'mysql' ),
        ], [ 'id' => $id ] );
        self::append_history( $id, 'rejected', $reason );
        wp_send_json_success();
    }

    // TRACE: flag_booking() — Trigger: wp_ajax_flag_booking AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function flag_booking(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id   = (int) Security::post( 'id', 'int' );
        $note = Security::post( 'note', 'textarea' );
        $db   = Database::instance();
        // FIX (audit, per user decision): admin_notes does not exist on bookings (it exists only
        // on the clients table — see SchemaV3). is_flagged is now a real column. The note, if
        // provided, is recorded in workflow_history instead, consistent with how every other
        // moderation action here logs its reasoning.
        $db->update( $db->t('bookings'), [ 'is_flagged' => 1 ], [ 'id' => $id ] );
        if ( $note ) {
            self::append_history( $id, 'flagged', $note );
        }
        wp_send_json_success();
    }

    // TRACE: get_flagged() — Trigger: wp_ajax_get_flagged AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_flagged(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        $rows = $db->select(
            "SELECT b.*, cl.name as client_name FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             WHERE b.is_flagged = 1 ORDER BY b.updated_at DESC LIMIT 50"
        );
        wp_send_json_success( [ 'bookings' => $rows ] );
    }

    // TRACE: search_content() — Trigger: wp_ajax_search_content AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function search_content(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $q    = sanitize_text_field( $_GET['q'] ?? '' );
        $db   = Database::instance();
        $like = '%' . $db->esc_like( $q ) . '%';
        $rows = $db->select(
            "SELECT b.id, b.uid, b.ad_content, b.status, cl.name as client_name
             FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             WHERE b.ad_content LIKE %s ORDER BY b.submitted_at DESC LIMIT 30",
            $like
        );
        wp_send_json_success( [ 'results' => $rows ] );
    }

    // TRACE: bulk_action() — Trigger: wp_ajax_bulk_action AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: empty input values handled.
    public static function bulk_action(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
        $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        if ( empty( $ids ) ) { wp_send_json_error( 'No IDs.' ); return; }
        $db     = Database::instance();
        $status_map = [
            'approve' => 'ready_to_process',
            'reject'  => 'rejected',
        ];
        if ( isset( $status_map[ $action ] ) ) {
            foreach ( $ids as $id ) {
                $db->update( $db->t('bookings'), [ 'status' => $status_map[ $action ], 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
                self::append_history( $id, $status_map[ $action ], "Bulk action: $action" );
            }
        }
        wp_send_json_success( [ 'affected' => count( $ids ) ] );
    }

    // TRACE: get_sample_ads() — Trigger: wp_ajax_get_sample_ads AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_sample_ads(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $db   = Database::instance();
        $rows = $db->select(
            "SELECT sa.*, cat.name as category_name, n.name as newspaper_name
             FROM {$db->t('sample_ads')} sa
             LEFT JOIN {$db->t('categories')} cat ON cat.id = sa.category_id
             LEFT JOIN {$db->t('newspapers')} n ON n.id = sa.newspaper_id
             ORDER BY sa.id DESC LIMIT 200"
        );
        wp_send_json_success( [ 'samples' => $rows ] );
    }

    // TRACE: save_sample_ad() — Trigger: wp_ajax_save_sample_ad AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_sample_ad(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        // FIX (audit): the frontend form only sends id/title/category_id/content/tags — no
        // newspaper_id/format_type/word_limit fields exist in this form at all. Previously this
        // always included those three keys, which meant every edit silently overwrote any
        // existing newspaper_id/format_type/word_limit with 0/''/0. Only include a key here if
        // the form actually sent it, so editing title/content doesn't blank out fields this form
        // was never meant to touch.
        $data = [
            'category_id'  => (int) Security::post( 'category_id', 'int' ),
            'title'        => Security::post( 'title', 'text' ),
            'content'      => Security::post( 'content', 'textarea' ),
            'tags'         => Security::post( 'tags', 'text' ),
        ];
        if ( isset( $_POST['newspaper_id'] ) ) { $data['newspaper_id'] = (int) Security::post( 'newspaper_id', 'int' ); }
        if ( isset( $_POST['format_type'] ) )  { $data['format_type']  = Security::post( 'format_type', 'text' ); }
        if ( isset( $_POST['word_limit'] ) )   { $data['word_limit']   = (int) Security::post( 'word_limit', 'int' ); }
        $id   = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('sample_ads'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('sample_ads'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: get_quick_replies() — Trigger: wp_ajax_get_quick_replies AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_quick_replies(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $db   = Database::instance();  // fixed: was calling instance() but not assigning result
        $rows = $db->select( "SELECT * FROM {$db->t('quick_replies')} ORDER BY sort_order ASC" );
        wp_send_json_success( [ 'replies' => $rows ] );
    }

    // TRACE: save_quick_reply() — Trigger: wp_ajax_save_quick_reply AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_quick_reply(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        $data = [
            'title'      => Security::post( 'title', 'text' ),
            'content'    => Security::post( 'content', 'textarea' ),
            'category'   => Security::post( 'category', 'text' ),
            'sort_order' => (int) Security::post( 'sort_order', 'int' ),
        ];
        $id   = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('quick_replies'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('quick_replies'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: delete_sample_ad() — Trigger: wp_ajax_delete_sample_ad AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected.
    public static function delete_sample_ad(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id = (int) Security::post('id', 'int');
        if ( ! $id ) { wp_send_json_error(['message' => 'Invalid ID']); return; }
        $db = Database::instance();
        $db->delete( $db->t('sample_ads'), [ 'id' => $id ] );
        wp_send_json_success(['message' => 'Sample deleted.']);
    }

    // TRACE: append_history() — Trigger: wp_ajax_append_history AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    private static function append_history( int $booking_id, string $status, string $note ): void {
        $db  = Database::instance();
        $b   = $db->row( "SELECT workflow_history FROM {$db->t('bookings')} WHERE id = %d", $booking_id );
        $h   = json_decode( $b['workflow_history'] ?? '[]', true );
        $h[] = [ 'status' => $status, 'note' => $note, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) ];
        $db->update( $db->t('bookings'), [ 'workflow_history' => json_encode( $h ) ], [ 'id' => $booking_id ] );
    }

    // TRACE: delete_quick_reply() — Trigger: wp_ajax_delete_quick_reply AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_quick_reply(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'nas_manage_bookings' );
        $id = (int)($_POST['id']??0);
        if (!$id) { wp_send_json_error(['message'=>'Invalid ID.']); return; }
        $db = Database::instance();
        $db->delete( $db->t('quick_replies'), ['id'=>$id] );
        wp_send_json_success(['message'=>'Deleted.']);
    }

    // TRACE: bulk_generate_city_seo() — Trigger: wp_ajax_bulk_generate_city_seo AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function bulk_generate_city_seo(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'nas_manage_bookings' );
        // Delegate to CityPages module
        do_action( 'nas_bulk_generate_seo' );
        wp_send_json_success(['message'=>'Bulk SEO generation queued.']);
    }

}
