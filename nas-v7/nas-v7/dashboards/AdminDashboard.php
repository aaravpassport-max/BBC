<?php
namespace NAS\Dashboards;

use NAS\Core\Security;

// FIX (audit): every AJAX handler in this class previously called
// Security::check_nonce($nonce, 'nas_action') — but every admin page that calls into these
// handlers (newspapers.php, settings.php, categories.php, etc.) reads its nonce from the shared
// nas-admin-config script tag in templates/admin/layout.php:47, which creates the nonce with
// action 'nas_admin_nonce' — a different string. wp_verify_nonce() requires an exact action-string
// match, so every single one of these 16 handlers failed with a 403 "Security check failed" on
// every call, regardless of what the handler body did afterward (this is why the newspaper-save
// and settings-save fixes made earlier in this same audit were necessary but not sufficient on
// their own — the nonce check died before ever reaching that code). Confirmed by checking two
// call sites directly (newspapers.php:79, settings.php:248) and by AdminModule::auth() in the
// sibling admin module already correctly using 'nas_admin_nonce' for the exact same shared config.
class AdminDashboard {
    public static function register(): void {
        add_shortcode( 'nas_admin_dashboard', [ self::class, 'render' ] );
        add_action( 'wp_ajax_nas_admin_get_settings',    [ self::class, 'get_settings' ] );
        add_action( 'wp_ajax_nas_admin_save_settings',   [ self::class, 'save_settings' ] );
        add_action( 'wp_ajax_nas_admin_get_all_clients', [ self::class, 'get_all_clients' ] );
        add_action( 'wp_ajax_nas_admin_get_newspapers',  [ self::class, 'get_newspapers' ] );
        add_action( 'wp_ajax_nas_admin_save_newspaper',  [ self::class, 'save_newspaper' ] );
        add_action( 'wp_ajax_nas_admin_get_cities',      [ self::class, 'get_cities' ] );
        add_action( 'wp_ajax_nas_admin_save_city',       [ self::class, 'save_city' ] );
        add_action( 'wp_ajax_nas_admin_get_categories',  [ self::class, 'get_categories' ] );
        add_action( 'wp_ajax_nas_admin_save_category',   [ self::class, 'save_category' ] );
        add_action( 'wp_ajax_nas_admin_get_followups',   [ self::class, 'get_followups' ] );
        add_action( 'wp_ajax_nas_admin_save_followup',   [ self::class, 'save_followup' ] );
        add_action( 'wp_ajax_nas_admin_get_feature_flags',  [ self::class, 'get_feature_flags' ] );
        add_action( 'wp_ajax_nas_admin_toggle_feature',     [ self::class, 'toggle_feature' ] );
        add_action( 'wp_ajax_nas_admin_export_csv',      [ self::class, 'export_csv' ] );
        add_action( 'wp_ajax_nas_admin_delete_newspaper', [ self::class, 'delete_newspaper' ] );
        add_action( 'wp_ajax_nas_admin_bulk_action',      [ self::class, 'bulk_booking_action' ] );
        add_action( 'wp_ajax_nas_get_all_bookings',       [ self::class, 'get_all_bookings' ] );
        add_action( 'wp_ajax_nas_admin_get_all_bookings', [ self::class, 'get_all_bookings' ] );
    }

    // TRACE: render() — Trigger: wp_ajax_render AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function render( $atts ): string {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'nas_manage_bookings' ) ) {
            return '<div class="nas-no-access"><p>Access denied. Admin or Manager role required.</p></div>';
        }

        // Ensure rewrite rules are flushed so the Router path (/admin-dashboard/)
        // is always used. This is idempotent — runs once per plugin version.
        if ( get_option('nas_rewrite_flushed') !== NAS_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'nas_rewrite_flushed', NAS_VERSION );
        }

        // The proper admin UI is served by Router::serve_admin() via the rewrite rule
        // ^admin-dashboard/?$ → nas_page=admin_dashboard → layout.php + pages/*.php.
        // If we reach here it means the shortcode path was hit (rewrite rules stale,
        // page visited before flush, etc.). Redirect to the router URL so the correct
        // full-page template loads — with CSS, sidebar, and all AJAX handlers intact.
        $page = sanitize_key( $_GET['nas_admin'] ?? 'dashboard' );
        $id   = absint( $_GET['id'] ?? 0 );
        $url  = home_url( '/admin-dashboard/' ) . '?nas_admin=' . $page . ( $id ? '&id=' . $id : '' );

        if ( ! headers_sent() ) {
            wp_safe_redirect( $url, 302 );
            exit;
        }
        // Fallback: headers already sent (unusual) — JS redirect
        return '<script>window.location.href=' . wp_json_encode( $url ) . ';</script>';
    }

    // TRACE: get_settings() — Trigger: wp_ajax_get_settings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_settings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        // FIX (audit): settings is a single-row table with each setting as its own named column
        // (database/Schema.php:430-445) — brand_name, logo_url, gst_percentage, etc. — not a
        // key_value structure. This query referenced setting_key/setting_value columns that
        // don't exist, so it always failed and returned an empty settings object. Currently
        // dormant (templates/admin/pages/settings.php reads settings server-side via direct PHP
        // and only calls this action's save counterpart), but fixing for correctness since
        // anything else calling this action would get silently empty data.
        $db  = \NAS\Core\Database::instance();
        $row = $db->row( "SELECT * FROM {$db->t('settings')} LIMIT 1" );
        wp_send_json_success( [ 'settings' => $row ?: [] ] );
    }

    // TRACE: save_settings() — Trigger: wp_ajax_save_settings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_settings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        // FIX (audit): rebuilt against the definitive, complete field list actually rendered by
        // templates/admin/pages/settings.php (every sv2()/$s[] read on that page), now that
        // stSave() sends every one of them using the real column name directly. Removed the
        // Config::save_many() indirection entirely — its separately-maintained whitelist used
        // yet a third set of names and silently dropped most fields. Every name below is a real
        // column, verified against database/Schema.php + SchemaV3.php + SchemaV4.php.
        $columns = [
            'brand_name', 'logo_url', 'footer_text',
            'gst_percentage', 'gst_number', 'currency_symbol', 'currency',
            'invoice_prefix', 'cutoff_days',
            'email_sender_name', 'email_sender_addr',
            'whatsapp_number', 'callmebot_api_key',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption',
            'ai_provider', 'ai_api_key', 'ai_enabled',
            'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
            'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'bank_transfer_details',
            'founded_year', 'tagline', 'mission', 'vision',
            'brand_address', 'brand_phone', 'brand_email',
            'vendor_auto_approve',
        ];
        $data = [];
        foreach ( $columns as $col ) {
            if ( isset( $_POST[ $col ] ) ) {
                $data[ $col ] = sanitize_text_field( $_POST[ $col ] );
            }
        }
        if ( empty( $data ) ) { wp_send_json_success( [ 'message' => 'Nothing to save.' ] ); return; }

        $db  = \NAS\Core\Database::instance();
        $row = $db->row( "SELECT id FROM {$db->t('settings')} LIMIT 1" );
        if ( $row ) {
            $db->update( $db->t('settings'), $data, [ 'id' => $row['id'] ] );
        } else {
            $db->insert( $db->t('settings'), $data );
        }
        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    // TRACE: get_all_clients() — Trigger: wp_ajax_get_all_clients AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_all_clients(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $page = (int) ( $_GET['page'] ?? 1 );
        $limit= 20;
        $off  = ( $page - 1 ) * $limit;
        $search = sanitize_text_field( $_GET['search'] ?? '' );
        $where = '1=1';
        $args  = [];
        if ( $search ) {
            $like   = $db->esc_like( $search );
            $where .= " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $args   = [ "%$like%", "%$like%", "%$like%" ];
        }
        $total = (int) $db->scalar( "SELECT COUNT(*) FROM {$db->t('clients')} WHERE $where", $args );
        $rows  = $db->select( "SELECT * FROM {$db->t('clients')} WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $args, [ $limit, $off ] ) );
        wp_send_json_success( [ 'clients' => $rows, 'total' => $total, 'pages' => ceil( $total / $limit ) ] );
    }

    // TRACE: get_newspapers() — Trigger: wp_ajax_get_newspapers AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_newspapers(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $rows = $db->select( "SELECT * FROM {$db->t('newspapers')} ORDER BY name ASC" );
        wp_send_json_success( [ 'newspapers' => $rows ] );
    }

    // TRACE: save_newspaper() — Trigger: wp_ajax_save_newspaper AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_newspaper(): void {
        $n = Security::post('nonce');
        if ( ! wp_verify_nonce( $n, 'nas_action' ) && ! wp_verify_nonce( $n, 'nas_admin_nonce' ) ) {
            wp_send_json_error(['message' => 'Security check failed.']); return;
        }
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        // FIX (audit): 'rates' column never existed on newspapers (only base_rate_classified/
        // base_rate_display/base_rate_dc do — database/Schema.php:100-122). Including it in the
        // SET data made the entire query fail (unknown column), so every newspaper save silently
        // did nothing while still reporting success below. Removed — the three base_rate_* fields
        // already store this exact data individually.
        $data = [
            'name'                  => Security::post( 'name', 'text' ),
            'slug'                  => sanitize_title( Security::post( 'slug', 'text' ) ?: Security::post( 'name', 'text' ) ),
            'language'              => Security::post( 'language', 'text' ),
            'logo_url'              => Security::post( 'logo_url', 'url' ) ?: '',
            'cities_supported'      => Security::post( 'cities', 'text' ) ?: Security::post( 'cities_supported', 'text' ) ?: '[]',
            'editions'              => Security::post( 'editions', 'text' ) ?: '[]',
            'categories_supported'  => Security::post( 'categories_supported', 'text' ) ?: '[]',
            'base_rate_classified'  => (float) Security::post( 'rate_classified', 'float' ),
            'base_rate_display'     => (float) Security::post( 'rate_display', 'float' ),
            'base_rate_dc'          => (float) Security::post( 'rate_dc', 'float' ),
            'min_charge'            => (float) Security::post( 'min_charge', 'float' ),
            'circulation'           => (int) Security::post( 'circulation', 'int' ),
            'description'           => Security::post( 'description', 'textarea' ) ?: '',
            'is_active'             => 1,
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            // NOTE: $wpdb->update()'s return value is 0 both when no row matched AND when the
            // row matched but the new data is identical to what's stored — those aren't
            // distinguishable from the return value alone, so checking it here would falsely
            // report an error on a harmless resave with no actual changes. Verifying existence
            // instead, which is unambiguous.
            $exists = $db->scalar( "SELECT id FROM {$db->t('newspapers')} WHERE id = %d", $id );
            if ( ! $exists ) { wp_send_json_error( [ 'message' => 'Newspaper not found.' ] ); return; }
            $db->update( $db->t('newspapers'), $data, [ 'id' => $id ] );
            wp_send_json_success( [ 'id' => $id ] );
        } else {
            $inserted = $db->insert( $db->t('newspapers'), $data );
            if ( ! $inserted ) { wp_send_json_error( [ 'message' => 'Could not create newspaper.' ] ); return; }
            wp_send_json_success( [ 'id' => $db->last_insert_id() ] );
        }
    }

    // TRACE: get_cities() — Trigger: wp_ajax_get_cities AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_cities(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $rows = $db->select( "SELECT * FROM {$db->t('cities')} ORDER BY name ASC" );
        wp_send_json_success( [ 'cities' => $rows ] );
    }

    // TRACE: save_city() — Trigger: wp_ajax_save_city AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_city(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $data = [
            'name'       => Security::post( 'name', 'text' ),
            'slug'       => sanitize_title( Security::post( 'name', 'text' ) ),
            'state'      => Security::post( 'state', 'text' ),
            'tier'       => (int) Security::post( 'tier', 'int' ),
            'population' => (int) Security::post( 'population', 'int' ),
            'is_active'  => 1,
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('cities'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('cities'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: get_categories() — Trigger: wp_ajax_get_categories AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_categories(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        $db   = \NAS\Core\Database::instance();
        $rows = $db->select( "SELECT * FROM {$db->t('categories')} ORDER BY sort_order ASC" );
        wp_send_json_success( [ 'categories' => $rows ] );
    }

    // TRACE: save_category() — Trigger: wp_ajax_save_category AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_category(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $data = [
            'name'       => Security::post( 'name', 'text' ),
            'slug'       => sanitize_title( Security::post( 'name', 'text' ) ),
            'description'=> Security::post( 'description', 'textarea' ),
            'icon'       => Security::post( 'icon', 'text' ),
            'sort_order' => (int) Security::post( 'sort_order', 'int' ),
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('categories'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('categories'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: get_followups() — Trigger: wp_ajax_get_followups AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_followups(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $rows = $db->select(
            "SELECT f.*, b.uid as booking_uid FROM {$db->t('followups')} f
             LEFT JOIN {$db->t('bookings')} b ON b.id = f.booking_id
             WHERE f.status = 'pending' ORDER BY f.next_followup_date ASC LIMIT 50"
        );
        wp_send_json_success( [ 'followups' => $rows ] );
    }

    // TRACE: save_followup() — Trigger: wp_ajax_save_followup AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_followup(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $data = [
            'booking_id'         => (int) Security::post( 'booking_id', 'int' ),
            'type'               => Security::post( 'type', 'text' ),
            'next_followup_date' => Security::post( 'next_followup_date', 'text' ),
            'status'             => Security::post( 'status', 'text' ) ?: 'pending',
            'notes'              => Security::post( 'notes', 'textarea' ),
            'assigned_to'        => (int) Security::post( 'assigned_to', 'int' ),
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('followups'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('followups'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: get_feature_flags() — Trigger: wp_ajax_get_feature_flags AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_feature_flags(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $db   = \NAS\Core\Database::instance();
        $rows = $db->select( "SELECT * FROM {$db->t('feature_flags')} ORDER BY feature_key ASC" );
        wp_send_json_success( [ 'flags' => $rows ] );
    }

    // TRACE: toggle_feature() — Trigger: wp_ajax_toggle_feature AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function toggle_feature(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $key     = Security::post( 'key', 'text' );
        $enabled = (int) Security::post( 'enabled', 'int' );
        \NAS\Core\Config::instance()->toggle_feature( $key, (bool) $enabled );
        wp_send_json_success();
    }

    // TRACE: export_csv() — Trigger: wp_ajax_export_csv AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function export_csv(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $type = sanitize_text_field( $_GET['type'] ?? 'bookings' );
        $db   = \NAS\Core\Database::instance();
        header( 'Content-Type: text/csv' );
        header( "Content-Disposition: attachment; filename={$type}-export-" . date('Y-m-d') . '.csv' );
        $out = fopen( 'php://output', 'w' );
        if ( $type === 'bookings' ) {
            $rows = $db->select( "SELECT b.uid, b.status, b.ad_type, b.base_amount, b.client_price, b.total_amount, b.payment_status, b.submitted_at, cl.name as client_name, cl.email as client_email, n.name as newspaper, cat.name as category, c.name as city FROM {$db->t('bookings')} b LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id LEFT JOIN {$db->t('cities')} c ON c.id = b.city_id ORDER BY b.submitted_at DESC LIMIT 5000" );  // fixed: base_price→base_amount, final_price→total_amount, created_at→submitted_at
            if ( $rows ) {
                fputcsv( $out, array_keys( $rows[0] ) );
                foreach ( $rows as $r ) fputcsv( $out, $r );
            }
        }
        fclose( $out );
        exit;
    }

    // TRACE: delete_newspaper() — Trigger: wp_ajax_delete_newspaper AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected; empty input values handled.
    public static function delete_newspaper(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'manage_options' );
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) { wp_send_json_error(['message'=>'Invalid ID.']); return; }
        // FIX (audit): the frontend confirm() dialog (templates/admin/pages/newspapers.php
        // nnDelete()) tells the admin this "deactivates" the newspaper and it will "no longer
        // appear in bookings" — implying a reversible soft-delete. The code was doing a hard
        // DELETE FROM instead, permanently removing the row. Any existing booking referencing
        // this newspaper_id would then silently show blank newspaper data forever (broken FK,
        // no error), with no way to undo. Changed to match what the UI actually tells the admin.
        \NAS\Core\Database::instance()->update( \NAS\Core\Database::instance()->t('newspapers'), ['is_active' => 0], ['id'=>$id] );
        wp_send_json_success(['message'=>'Deactivated.']);
    }

    // TRACE: bulk_booking_action() — Trigger: wp_ajax_bulk_booking_action AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: empty input values handled.
    public static function bulk_booking_action(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'nas_manage_bookings' );
        $action = sanitize_text_field( $_POST['action_type'] ?? $_POST['action'] ?? '' );
        $ids    = array_map('intval', (array)( $_POST['ids'] ?? [] ));
        if ( empty($ids) ) { wp_send_json_error(['message'=>'No IDs.']); return; }
        $db = \NAS\Core\Database::instance();
        foreach ( $ids as $id ) {
            if ( $action === 'delete' ) {
                $db->delete( $db->t('bookings'), ['id'=>$id] );
            } else {
                $db->update( $db->t('bookings'), ['status'=>$action], ['id'=>$id] );
            }
        }
        wp_send_json_success(['message'=>'Done.','count'=>count($ids)]);
    }

    // TRACE: get_all_bookings() — Trigger: wp_ajax_get_all_bookings AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function get_all_bookings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap( 'nas_manage_bookings' );
        $db      = \NAS\Core\Database::instance();
        $page    = max(1,(int)($_POST['page']??1));
        $limit   = min(50,(int)($_POST['per_page']??15));
        $status  = sanitize_text_field($_POST['status']??'');
        $search  = sanitize_text_field($_POST['search']??'');
        $where   = '1=1'; $args = [];
        if ($status) { $where .= ' AND b.status=%s'; $args[]=$status; }
        if ($search) {
            $like=$db->esc_like($search);
            $where .= ' AND (b.booking_uid LIKE %s OR cl.name LIKE %s OR cl.email LIKE %s)';
            $args[]="%$like%"; $args[]="%$like%"; $args[]="%$like%";
        }
        $result = $db->paginate(
            "SELECT COUNT(*) FROM {$db->t('bookings')} b LEFT JOIN {$db->t('clients')} cl ON cl.id=b.client_id WHERE $where",
            "SELECT b.*,cl.name AS client_name,cl.email AS client_email,cl.phone AS client_phone,n.name AS newspaper_name,c.name AS city_name,cat.name AS category_name FROM {$db->t('bookings')} b LEFT JOIN {$db->t('clients')} cl ON cl.id=b.client_id LEFT JOIN {$db->t('newspapers')} n ON n.id=b.newspaper_id LEFT JOIN {$db->t('cities')} c ON c.id=b.city_id LEFT JOIN {$db->t('categories')} cat ON cat.id=b.category_id WHERE $where ORDER BY b.submitted_at DESC",
            $args, $page, $limit
        );
        wp_send_json_success($result);
    }

}
