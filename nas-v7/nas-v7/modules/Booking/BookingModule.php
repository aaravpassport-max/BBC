<?php
namespace NAS\Modules\Booking;
use NAS\Core\{Module, Database, Cache, EventBus, Security, Helpers, Queue};
if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════════════════════════
   BookingModule — Super Combo v3
   Registers all AJAX actions for the 11-step wizard and admin operations.
   ══════════════════════════════════════════════════════════════════════════════ */
class BookingModule extends Module {
    public function key(): string { return 'booking'; }

    public function register(): void {
        $actions = [
            // Public / guest wizard endpoints
            'nas_create_booking'        => ['nopriv'=>true,  'method'=>'create_booking'],
            'nas_get_booking'           => ['nopriv'=>true,  'method'=>'get_booking'],
            'nas_get_newspapers'        => ['nopriv'=>true,  'method'=>'get_newspapers'],
            'nas_get_categories'        => ['nopriv'=>true,  'method'=>'get_categories'],
            'nas_get_cities'            => ['nopriv'=>true,  'method'=>'get_cities'],
            'nas_get_editions'          => ['nopriv'=>true,  'method'=>'get_editions'],
            'nas_get_sample_ads'        => ['nopriv'=>true,  'method'=>'get_sample_ads'],
            'nas_get_templates'         => ['nopriv'=>true,  'method'=>'get_templates'],
            'nas_get_rate'              => ['nopriv'=>true,  'method'=>'get_rate'],
            'nas_get_combo_offers'      => ['nopriv'=>true,  'method'=>'get_combo_offers'],
            'nas_get_ad_sizes'          => ['nopriv'=>true,  'method'=>'get_ad_sizes'],
            'nas_validate_coupon'       => ['nopriv'=>true,  'method'=>'validate_coupon'],
            'nas_ai_transform'          => ['nopriv'=>true,  'method'=>'ai_transform'],
            // Client (logged-in) endpoints
            'nas_get_client_bookings'   => ['nopriv'=>false, 'method'=>'get_client_bookings'],
            // Admin-only endpoints
            'nas_update_booking_status' => ['nopriv'=>false, 'method'=>'update_status'],
            'nas_get_all_bookings'      => ['nopriv'=>false, 'method'=>'get_all_bookings'],
            'nas_update_booking'        => ['nopriv'=>false, 'method'=>'update_booking'],
            'nas_delete_booking'        => ['nopriv'=>false, 'method'=>'delete_booking'],
            'nas_assign_vendor'         => ['nopriv'=>false, 'method'=>'assign_vendor'],
            'nas_update_payment'        => ['nopriv'=>false, 'method'=>'update_payment'],
            'nas_get_booking_detail'    => ['nopriv'=>false, 'method'=>'get_booking_detail'],
            'nas_reject_booking'        => ['nopriv'=>false, 'method'=>'reject_booking'],
            'nas_send_quotation'        => ['nopriv'=>false, 'method'=>'send_quotation'],
            'nas_get_workflow_history'  => ['nopriv'=>false, 'method'=>'get_workflow_history'],
            'nas_upload_proof'          => ['nopriv'=>false, 'method'=>'upload_proof'],
            'nas_upload_tear_sheet'     => ['nopriv'=>false, 'method'=>'upload_tear_sheet'],
            'nas_get_booking_stats'     => ['nopriv'=>false, 'method'=>'get_booking_stats'],
        ];

        $controller = new BookingController();
        foreach ( $actions as $action => $cfg ) {
            add_action( "wp_ajax_{$action}", [ $controller, $cfg['method'] ] );
            if ( $cfg['nopriv'] ) add_action( "wp_ajax_nopriv_{$action}", [ $controller, $cfg['method'] ] );
        }

        add_shortcode( 'nas_booking',          [ $this, 'shortcode_booking' ] );
        add_shortcode( 'nas_confirmation',     [ $this, 'shortcode_confirmation' ] );
        add_shortcode( 'nas_pricing',          [ $this, 'shortcode_pricing' ] );
        add_shortcode( 'nas_about',            [ $this, 'shortcode_about' ] );
        add_shortcode( 'nas_support',          [ $this, 'shortcode_support' ] );
        add_shortcode( 'nas_cities_index',     [ $this, 'shortcode_cities_index' ] );
        add_shortcode( 'nas_newspapers_index', [ $this, 'shortcode_newspapers_index' ] );
    }

    public function boot(): void {
        EventBus::on( 'booking_status_changed', function( $p ) {
            Cache::delete( 'booking_' . $p['booking_id'] );
            Cache::delete( 'client_bookings_' . $p['client_id'] );
        });
    }

    // TRACE: shortcode_booking() — Trigger: wp_ajax_shortcode_booking AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public function shortcode_booking( array $atts = [] ): string {
        ob_start();
        include NAS_PLUGIN_DIR . 'templates/booking/wizard.php';
        return ob_get_clean();
    }

    // TRACE: shortcode_confirmation() — Trigger: wp_ajax_shortcode_confirmation AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: file existence checked before access.
    public function shortcode_confirmation( array $atts = [] ): string {
        ob_start();
        include NAS_PLUGIN_DIR . 'templates/booking/confirmation.php';
        return ob_get_clean();
    }

    public function shortcode_pricing( array $atts = [] ): string          { return $this->serve_page('pricing'); }
    public function shortcode_about( array $atts = [] ): string            { return $this->serve_page('about'); }
    public function shortcode_support( array $atts = [] ): string          { return $this->serve_page('support'); }
    public function shortcode_cities_index( array $atts = [] ): string     { return $this->serve_page('cities-index'); }
    public function shortcode_newspapers_index( array $atts = [] ): string { return $this->serve_page('newspapers-index'); }

    // TRACE: serve_page() — Trigger: wp_ajax_serve_page AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access.
    private function serve_page( string $page ): string {
        $file = NAS_PLUGIN_DIR . "templates/pages/{$page}.php";
        if ( ! file_exists( $file ) ) return '<p class="nas-error">Page content not found.</p>';
        ob_start();
        include $file;
        return ob_get_clean();
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   BookingRepository
   ══════════════════════════════════════════════════════════════════════════════ */
class BookingRepository {
    private Database $db;

    public function __construct() { $this->db = Database::instance(); }

    // TRACE: create() — Trigger: wp_ajax_create AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function create( array $data ): int {
        $t = $this->db->prefix('bookings');

        // ── Field-name mapping: wizard keys → actual DB column names ──────────
        if ( isset($data['client_notes']) ) {
            $data['notes_client'] = $data['client_notes'];
            unset($data['client_notes']);
        }
        // Sync client_price = total_amount so admin revenue analytics work
        if ( ! isset($data['client_price']) && isset($data['total_amount']) ) {
            $data['client_price'] = $data['total_amount'];
        }

        // ── Normalize ad_type: wizard sends 'classified_text', ENUM only allows
        // 'classified', 'display', 'display_classified'. MySQL strict mode rejects
        // unknown ENUM values silently (returns 0 from insert) -> booking fails.
        if ( isset($data['ad_type']) ) {
            $ad_type_map = [
                'classified_text'      => 'classified',
                'classified'           => 'classified',
                'display'              => 'display',
                'display_classified'   => 'display_classified',
            ];
            $data['ad_type'] = $ad_type_map[ $data['ad_type'] ] ?? 'classified';
        }

        // ── Whitelist: only columns that exist in nas_bookings ────────────────
        $allowed = [
            'uid', 'client_id',   'client_name',    // client_name: denormalized for fast search (SchemaV3)
            'category_id', 'category_name',
            'city_id',     'city_name',
            'newspaper_id','newspaper_name',
            'edition',     'ad_type',
            'ad_title',    'ad_content',    'ad_preview_html',
            'word_count',  'width_cm',      'height_cm',
            'publish_date',
            'status',      'workflow_history',
            'base_amount', 'client_price',  'total_amount',
            'gst_amount',  'discount_amount',
            'combo_offer_id', 'coupon_code',
            'notes_client', 'client_company', 'client_gst', 'whatsapp_optin',
            'multi_newspapers',
            'submitted_at', 'source',
        ];
        $data = array_intersect_key( $data, array_flip($allowed) );

        // ── Set server-side fields ─────────────────────────────────────────────
        $data['uid']          = Helpers::generate_uid('BK');
        $data['submitted_at'] = gmdate('Y-m-d H:i:s');
        $data['status']       = 'booking_received';
        $data['workflow_history'] = json_encode([[
            'status' => 'booking_received',
            'note'   => 'Booking received from wizard.',
            'by'     => $data['client_id'] ?? 0,
            'at'     => gmdate('Y-m-d H:i:s'),
        ]]);
        return (int) $this->db->insert( $t, $data );
    }

    // TRACE: find() — Trigger: wp_ajax_find AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find( int $id ): ?object {
        $result = $this->db->row(
            "SELECT * FROM `{$this->db->prefix('bookings')}` WHERE id = %d", [$id], OBJECT
        );
        return $result instanceof \stdClass ? $result : null;
    }

    // TRACE: find_by_uid() — Trigger: wp_ajax_find_by_uid AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find_by_uid( string $uid ): ?object {
        return $this->db->row(
            "SELECT * FROM `{$this->db->prefix('bookings')}` WHERE uid = %s", [$uid]
        );
    }

    // TRACE: get_for_client() — Trigger: wp_ajax_get_for_client AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: empty input values handled.
    public function get_for_client( int $client_id, int $page = 1, int $per_page = 20 ): array {
        $t      = $this->db->prefix('bookings');
        $offset = ($page - 1) * $per_page;
        return $this->db->select(
            "SELECT * FROM `$t` WHERE client_id = %d ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            [$client_id, $per_page, $offset]
        );
    }

    // TRACE: get_all() — Trigger: wp_ajax_get_all AJAX action.
    //        Steps: fetches and returns data.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public function get_all( array $filters = [], int $page = 1, int $per_page = 25 ): array {
        $t      = $this->db->prefix('bookings');
        $where  = ['1=1'];
        $params = [];

        if ( ! empty($filters['status']) )      { $where[] = 'status = %s';               $params[] = $filters['status']; }
        if ( ! empty($filters['city_name']) )   { $where[] = 'city_name LIKE %s';         $params[] = '%' . $filters['city_name'] . '%'; }
        if ( ! empty($filters['newspaper_id'])) { $where[] = 'newspaper_id = %d';         $params[] = $filters['newspaper_id']; }
        if ( ! empty($filters['ad_type']) )     { $where[] = 'ad_type = %s';              $params[] = $filters['ad_type']; }
        if ( ! empty($filters['date_from']) )   { $where[] = 'submitted_at >= %s';        $params[] = $filters['date_from']; }
        if ( ! empty($filters['date_to']) )     { $where[] = 'submitted_at <= %s';        $params[] = $filters['date_to'] . ' 23:59:59'; }
        if ( ! empty($filters['search']) )      {
            // client_name is denormalized onto bookings (SchemaV3 migration) — safe in WHERE without JOIN alias conflict
            $where[]  = '(b.uid LIKE %s OR b.client_name LIKE %s OR b.city_name LIKE %s OR b.newspaper_name LIKE %s)';
            $s = '%' . $filters['search'] . '%';
            array_push( $params, $s, $s, $s, $s );
        }

        // JOIN clients for authoritative contact info; b.client_name used for search (denormalized)
        $sql = "SELECT b.*, c.name as client_name, c.phone as client_phone, c.email as client_email
                FROM `$t` b LEFT JOIN `{$this->db->prefix('clients')}` c ON c.id = b.client_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY b.submitted_at DESC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = ($page - 1) * $per_page;
        return $this->db->select($sql, $params);
    }

    // TRACE: update_status() — Trigger: wp_ajax_update_status AJAX action.
    //        Steps: updates DB row → returns JSON error response on failure → emits EventBus event.
    //        Output: bool (true on success, false on failure).
    //        Edge cases: returns null/false on failure; explicit false check on DB op.
    public function update_status( int $id, string $status, string $note = '', int $by = 0 ): bool {
        $t       = $this->db->prefix('bookings');
        $booking = $this->find($id);
        if ( ! $booking ) return false;

        $history   = json_decode($booking->workflow_history ?? '[]', true) ?: [];
        $history[] = ['status'=>$status, 'note'=>$note, 'by'=>$by, 'at'=>gmdate('Y-m-d H:i:s')];

        $ok = $this->db->update( $t, [
            'status'           => $status,
            'workflow_history' => json_encode($history),
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);

        EventBus::emit('booking_status_changed', ['booking_id'=>$id,'status'=>$status,'client_id'=>$booking->client_id]);
        return $ok !== false;
    }

    // TRACE: stats() — Trigger: wp_ajax_stats AJAX action.
    //        Steps: queries DB.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function stats(): array {
        $t = $this->db->prefix('bookings');
        $now = gmdate('Y-m-d H:i:s');
        return [
            'total'        => (int) $this->db->scalar("SELECT COUNT(*) FROM `$t`"),
            'this_month'   => (int) $this->db->scalar("SELECT COUNT(*) FROM `$t` WHERE MONTH(submitted_at)=MONTH(%s) AND YEAR(submitted_at)=YEAR(%s)", [$now,$now]),
            'pending'      => (int) $this->db->scalar("SELECT COUNT(*) FROM `$t` WHERE status IN('booking_received','under_review','ready_to_process','documents_received','quotation_sent','payment_received','material_uploaded','ad_processing','proof_ready','submitted_to_pub')"),
            'completed'    => (int) $this->db->scalar("SELECT COUNT(*) FROM `$t` WHERE status='published'"),
            'revenue'      => (float)$this->db->scalar("SELECT COALESCE(SUM(total_amount),0) FROM `$t` WHERE status='published'"),
        ];
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   BookingService — business logic layer
   ══════════════════════════════════════════════════════════════════════════════ */
class BookingService {
    private BookingRepository $repo;

    public function __construct() { $this->repo = new BookingRepository(); }

    /**
     * Create or find client, auto-login, create booking, queue notifications.
     */
    // TRACE: create_booking_for_guest() — Trigger: wp_ajax_create_booking_for_guest AJAX action.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: WP_Error returned by external call handled; empty input values handled.
    public function create_booking_for_guest( array $data, string $email, string $name, string $phone ): array {
        $db     = Database::instance();
        $ct     = $db->prefix('clients');
        $client = $db->row("SELECT * FROM `$ct` WHERE email = %s", [$email], OBJECT);
        $wp_uid = 0;

        if ( ! $client ) {
            $user_exists = get_user_by('email', $email);
            if ( ! $user_exists ) {
                $pass = wp_generate_password(12, false);
                // Build a clean username from email: keep only safe chars, ensure uniqueness
                $base_login = sanitize_user( strtolower( preg_replace('/[^a-zA-Z0-9]/', '_', strstr($email, '@', true) ) ), true );
                if ( empty($base_login) ) $base_login = 'nas_client';
                $login = $base_login;
                $i = 1;
                while ( username_exists($login) ) { $login = $base_login . '_' . $i++; }

                $wp_uid = wp_create_user( $login, $pass, $email );
                if ( is_wp_error($wp_uid) ) {
                    // Username collision race — use the existing account
                    $existing = get_user_by( 'email', $email );
                    if ( $existing ) {
                        $wp_uid = $existing->ID;
                    } else {
                        throw new \Exception( 'Could not create user account: ' . $wp_uid->get_error_message() );
                    }
                } else {
                    $update_result = wp_update_user(['ID'=>$wp_uid,'display_name'=>$name,'role'=>'nas_client']);
                    if ( is_wp_error($update_result) ) {
                        // Role may not exist yet — assign subscriber as safe fallback
                        wp_update_user(['ID'=>$wp_uid,'display_name'=>$name,'role'=>'subscriber']);
                    }
                }
                // Send welcome email immediately with login credentials
                $login_url  = home_url('/newspaper-ad-login/');
                $site_name  = get_bloginfo('name');
                $subject    = "Welcome to {$site_name} — Your Login Details";
                $body       = "Hi {$name},\n\n"
                    . "Your booking has been placed successfully! An account has been created for you.\n\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "Your Login Details\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "Login URL : {$login_url}\n"
                    . "Username  : {$login}\n"
                    . "Email     : {$email}\n"
                    . "Password  : {$pass}\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "You can track your booking, upload ad material, and chat with our team by logging in to your portal.\n\n"
                    . "Please change your password after first login for security.\n\n"
                    . "Best regards,\n"
                    . $site_name;
                wp_mail( $email, $subject, $body );
            } else {
                $wp_uid = $user_exists->ID;
            }
            $client_id = $db->insert( $ct, [
                'wp_user_id'=>$wp_uid,'uid'=>Helpers::generate_uid('CL'),
                'name'=>$name,'email'=>$email,'phone'=>$phone,
                'city'=>$data['city_name'] ?? '','created_at'=>gmdate('Y-m-d H:i:s'),
            ]);
            if ( ! $client_id ) throw new \Exception('Client record could not be created.');
        } else {
            $client_id = $client->id;
            $wp_uid    = $client->wp_user_id;
        }

        wp_set_auth_cookie( $wp_uid, true );
        wp_set_current_user( $wp_uid );

        // Wrap DB writes in a transaction: booking insert + client counter must be atomic
        $data['client_id']   = $client_id;
        $data['client_name'] = $name;  // denormalized for fast search (SchemaV3 column)
        $data['client_phone'] = $phone;  // FIX (audit): denormalized alongside client_name — see SchemaV3
        $booking_id = 0;
        $tx_ok = $db->transaction( function( $db_tx ) use ( &$booking_id, $data, $ct, $client_id ) {
            $booking_id = (new BookingRepository( $db_tx ))->create( $data );
            if ( ! $booking_id ) throw new \Exception('Booking could not be created.');
            global $wpdb;
            $wpdb->query( $wpdb->prepare( "UPDATE `{$db_tx->prefix('clients')}` SET total_orders = total_orders + 1 WHERE id = %d", $client_id ) );
        } );
        if ( ! $tx_ok || ! $booking_id ) throw new \Exception('Booking could not be saved. Please try again.');
        global $wpdb;// required for UPDATE query already executed inside transaction

        // Fire booking_created via EventBus (bridges to NotificationsModule::on_booking_submitted)
        // Also fire WP action for backward compat with any external plugins listening
        EventBus::emit( 'booking_created', [ 'booking_id' => $booking_id, 'client_id' => $client_id ] );
        do_action( 'nas_booking_submitted', $booking_id );

        Queue::push('\NAS\Modules\Notifications\NotificationJob', [
            'type'=>'booking_created','booking_id'=>$booking_id,
        ], 0, 'high');

        $is_new_user = isset($pass); // $pass only set for newly created accounts
        return [
            'id'           => $booking_id,
            'uid'          => $this->repo->find($booking_id)->uid ?? '',
            'client_id'    => $client_id,
            'wp_user_id'   => $wp_uid,
            'new_user'     => $is_new_user ? 1 : 0,
            'redirect_url' => nas_get_page_url('nas_page_client_dashboard','/client-dashboard/'),
        ];
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   BookingController — all AJAX handlers
   ══════════════════════════════════════════════════════════════════════════════ */
class BookingController {
    private BookingRepository $repo;
    private BookingService    $service;
    private Database          $db;

    public function __construct() {
        $this->repo    = new BookingRepository();
        $this->service = new BookingService();
        $this->db      = Database::instance();
    }

    /* ── create_booking ──────────────────────────────────────────────────── */
    // TRACE: create_booking() — Trigger: wp_ajax_create_booking AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function create_booking(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_wizard_nonce' );

        // RATE LIMITING (Part 12-D / 15-A): Max N bookings per IP per hour (configurable)
        if ( class_exists('\NAS\Core\RateLimit') ) {
            $db          = Database::instance();
            $rate_limit  = (int) ( $db->scalar("SELECT rate_limit_bookings FROM `{$db->prefix('settings')}` LIMIT 1") ?: 5 );
            $client_ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
            if ( $rate_limit > 0 && ! \NAS\Core\RateLimit::check( 'booking', $client_ip, $rate_limit, HOUR_IN_SECONDS ) ) {
                wp_send_json_error(['message' => 'Too many booking submissions. Please wait before trying again.'], 429);
            }
        }

        // IDEMPOTENCY CHECK (Part 15-A): Prevent double-submit from rapid re-clicks
        $idem_key = sanitize_key( Security::post('idempotency_key') ?: '' );
        if ( $idem_key && class_exists('\NAS\Core\RateLimit') && ! \NAS\Core\RateLimit::is_new_idempotency_key($idem_key, 300) ) {
            wp_send_json_error(['message' => 'This booking has already been submitted. Please check your bookings.'], 409);
        }

        $email = Security::post('client_email', 'email');
        $name  = Security::post('client_name');
        $phone = Security::post('client_phone');

        if ( ! Security::validate_email($email) ) wp_send_json_error(['message'=>'Valid email is required.']);
        if ( ! $name )                            wp_send_json_error(['message'=>'Your name is required.']);
        if ( ! preg_match('/^[6-9][0-9]{9}$/', preg_replace('/\D/','',$phone ?? '')) )
            wp_send_json_error(['message'=>'Valid 10-digit Indian mobile number is required.']);

        $newspaper_id = Security::post('newspaper_id','int');
        $city_name    = Security::post('city_name');
        $ad_content   = Security::post('ad_content','textarea');

        if ( ! $newspaper_id ) wp_send_json_error(['message'=>'Please select a newspaper.']);
        if ( ! $city_name )    wp_send_json_error(['message'=>'Please select a city.']);
        if ( ! $ad_content )   wp_send_json_error(['message'=>'Ad content is required.']);

        $data = [
            'newspaper_id'   => $newspaper_id,
            'newspaper_name' => Security::post('newspaper_name'),
            'category_id'    => Security::post('category_id','int'),
            'category_name'  => Security::post('category_name'),
            'city_id'        => Security::post('city_id','int'),
            'city_name'      => $city_name,
            'edition'        => Security::post('edition'),
            'ad_type'        => Security::post('ad_type') ?: 'classified_text',
            'ad_title'       => Security::post('ad_title'),
            'ad_content'     => $ad_content,
            'word_count'     => Security::post('word_count','int'),
            'width_cm'       => Security::post('width_cm','float'),
            'height_cm'      => Security::post('height_cm','float'),
            'publish_date'   => Security::post('publish_date'),
            'base_amount'    => Security::post('base_amount','float'),
            'discount_amount'=> Security::post('discount_amount','float'),
            'gst_amount'     => Security::post('gst_amount','float'),
            'total_amount'   => Security::post('total_amount','float'),
            'combo_offer_id' => Security::post('combo_id','int') ?: null,
            'coupon_code'    => Security::post('coupon_code'),
            'client_company' => Security::post('client_company'),
            'client_gst'     => Security::post('client_gst'),
            'client_notes'   => Security::post('client_notes','textarea'),
            'multi_newspapers'=> Security::post('multi_newspapers'),   // JSON array of selected papers
            'whatsapp_optin' => Security::post('whatsapp_optin','int'),
        ];

        try {
            $result = $this->service->create_booking_for_guest($data, $email, $name, $phone);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message'=>$e->getMessage()]);
        }
    }

    /* ── get_newspapers ──────────────────────────────────────────────────── */
    // TRACE: get_newspapers() — Trigger: wp_ajax_get_newspapers AJAX action.
    //        Steps: reads sanitised POST input → queries DB → reads/writes Cache.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure; explicit false check on DB op; empty input values handled.
    public function get_newspapers(): void {
        $city_name  = strtolower(trim(Security::post('city_name') ?? Security::get('city_name','')));
        $city_id    = Security::post('city_id','int');
        $cat_id     = Security::post('category_id','int');
        $cache_key  = 'papers_' . md5($city_name . '_' . $cat_id);

        $papers = Cache::remember($cache_key, function() use ($city_name, $city_id, $cat_id) {
            $t = $this->db->prefix('newspapers');
            $all = $this->db->select("SELECT * FROM `$t` WHERE is_active=1 ORDER BY sort_order ASC, name ASC");

            // Filter by city
            if ($city_name) {
                $all = array_filter($all, function($p) use ($city_name) {
                    $cities = json_decode($p['cities_supported'] ?? '[]', true) ?: [];
                    if (empty($cities)) return true; // no restriction = show everywhere
                    foreach ($cities as $c) {
                        if (strpos(strtolower((string)$c), $city_name) !== false) return true;
                    }
                    return false;
                });
            }

            // Filter by category
            if ($cat_id) {
                $all = array_filter($all, function($p) use ($cat_id) {
                    $cats = json_decode($p['categories_supported'] ?? '[]', true) ?: [];
                    return empty($cats) || in_array($cat_id, $cats) || in_array((string)$cat_id, $cats);
                });
            }

            return array_values($all ?: []);
        }, 300);

        wp_send_json_success($papers ?: []);
    }

    /* ── get_categories ──────────────────────────────────────────────────── */
    // TRACE: get_categories() — Trigger: wp_ajax_get_categories AJAX action.
    //        Steps: queries DB → reads/writes Cache.
    //        Output: array of DB rows.
    //        Edge cases: empty input values handled.
    public function get_categories(): void {
        $t    = $this->db->prefix('categories');
        $cats = Cache::remember('nas_categories', function() use ($t) {
            return $this->db->select("SELECT * FROM `$t` WHERE is_active=1 ORDER BY sort_order ASC, name ASC", [], OBJECT);
        }, 3600);

        // If DB is empty, run seeder and retry once
        if ( empty($cats) ) {
            try {
                \NAS\Database\Seeder::run();
                Cache::delete('nas_categories');
                $cats = $this->db->select("SELECT * FROM `$t` WHERE is_active=1 ORDER BY sort_order ASC, name ASC", [], OBJECT);
            } catch (\Exception $e) { \NAS\Core\ErrorLogger::warning('Category fetch failed in BookingModule', ['error'=>$e->getMessage()]); $cats = []; }
        }

        // Absolute fallback — built-in default categories
        if ( empty($cats) ) {
            $cats = [
                (object)['id'=>1,'name'=>'Matrimonial','icon'=>'💍','description'=>'Marriage ads'],
                (object)['id'=>2,'name'=>'Property','icon'=>'🏠','description'=>'Real estate ads'],
                (object)['id'=>3,'name'=>'Recruitment','icon'=>'💼','description'=>'Job vacancy ads'],
                (object)['id'=>4,'name'=>'Public Notice','icon'=>'📣','description'=>'Official notices'],
                (object)['id'=>5,'name'=>'Obituary','icon'=>'💐','description'=>'Death notices'],
                (object)['id'=>6,'name'=>'Education','icon'=>'📚','description'=>'Education ads'],
                (object)['id'=>7,'name'=>'Business','icon'=>'📰','description'=>'Business ads'],
                (object)['id'=>8,'name'=>'Vehicles','icon'=>'🚗','description'=>'Vehicle ads'],
                (object)['id'=>9,'name'=>'Legal','icon'=>'⚖️','description'=>'Legal notices'],
                (object)['id'=>10,'name'=>'Achievements','icon'=>'🎓','description'=>'Achievement notices'],
                (object)['id'=>11,'name'=>'Healthcare','icon'=>'🏥','description'=>'Medical ads'],
                (object)['id'=>12,'name'=>'Announcements','icon'=>'🎉','description'=>'General announcements'],
            ];
        }

        wp_send_json_success($cats);
    }

    /* ── get_cities ──────────────────────────────────────────────────────── */
    // TRACE: get_cities() — Trigger: wp_ajax_get_cities AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → reads/writes Cache.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function get_cities(): void {
        $search = Security::post('q') ?: Security::get('q','');
        $t      = $this->db->prefix('cities');

        if ($search) {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $cities = $this->db->select(
                "SELECT id, name, state, state_code, tier, population FROM `$t`
                 WHERE is_active=1 AND (name LIKE %s OR state LIKE %s)
                 ORDER BY tier ASC, population DESC LIMIT 30",
                [$like, $like]
            );
        } else {
            $cities = Cache::remember('nas_cities_all', function() use ($t) {
                return $this->db->select(
                    "SELECT id, name, state, state_code, tier, population FROM `$t`
                     WHERE is_active=1 ORDER BY tier ASC, population DESC"
                );
            }, 3600);
        }
        wp_send_json_success($cities ?: []);
    }

    /* ── get_editions ────────────────────────────────────────────────────── */
    // TRACE: get_editions() — Trigger: wp_ajax_get_editions AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: single DB row as associative array or null.
    //        Edge cases: explicit false check on DB op.
    public function get_editions(): void {
        $newspaper_id = Security::post('newspaper_id','int');
        $city_name    = Security::post('city_name');

        if ( ! $newspaper_id ) { wp_send_json_success([]); return; }

        $paper = $this->db->row(
            "SELECT editions, cities_supported FROM `{$this->db->prefix('newspapers')}` WHERE id = %d",
            [$newspaper_id]
        );

        if ( ! $paper ) { wp_send_json_success([]); return; }

        $editions = json_decode($paper['editions'] ?? '[]', true) ?: [];

        // If city provided and there's a matching edition, prioritize it
        if ($city_name && $editions) {
            usort($editions, function($a, $b) use ($city_name) {
                $aMatch = stripos((string)$a, $city_name) !== false ? 0 : 1;
                $bMatch = stripos((string)$b, $city_name) !== false ? 0 : 1;
                return $aMatch - $bMatch;
            });
        }

        wp_send_json_success($editions);
    }

    /* ── get_sample_ads ──────────────────────────────────────────────────── */
    // TRACE: get_sample_ads() — Trigger: wp_ajax_get_sample_ads AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_sample_ads(): void {
        $cat_id = Security::post('category_id','int');
        $t      = $this->db->prefix('sample_ads');

        $samples = $this->db->select(
            "SELECT * FROM `$t` WHERE is_active=1" .
            ($cat_id ? " AND (category_id=%d OR category_id=0)" : "") .
            " ORDER BY sort_order ASC, id DESC LIMIT 12",
            $cat_id ? [$cat_id] : []
        );
        wp_send_json_success($samples ?: []);
    }

    /* ── get_templates ───────────────────────────────────────────────────── */
    // TRACE: get_templates() — Trigger: wp_ajax_get_templates AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_templates(): void {
        $cat_id = Security::post('category_id','int');
        $t      = $this->db->prefix('templates');

        $templates = $cat_id
            ? $this->db->select("SELECT * FROM `$t` WHERE is_active=1 AND (category_id=%d OR category_id=0) ORDER BY sort_order ASC, id ASC LIMIT 20", [$cat_id])
            : $this->db->select("SELECT * FROM `$t` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 30");

        wp_send_json_success($templates ?: []);
    }

    /* ── get_rate ────────────────────────────────────────────────────────── */
    // TRACE: get_rate() — Trigger: wp_ajax_get_rate AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_rate(): void {
        $newspaper_id = Security::post('newspaper_id','int');
        if ( ! $newspaper_id ) { wp_send_json_error(['message'=>'Newspaper required.']); return; }

        $paper = $this->db->row(
            "SELECT base_rate_classified, base_rate_display, base_rate_dc, min_charge FROM `{$this->db->prefix('newspapers')}` WHERE id=%d",
            [$newspaper_id]
        );
        $paper ? wp_send_json_success($paper) : wp_send_json_error(['message'=>'Newspaper not found.']);
    }

    /* ── get_combo_offers ────────────────────────────────────────────────── */
    // TRACE: get_combo_offers() — Trigger: wp_ajax_get_combo_offers AJAX action.
    //        Steps: reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure; empty input values handled.
    public function get_combo_offers(): void {
        $city_name    = Security::post('city_name');
        $newspaper_id = Security::post('newspaper_id','int');
        $t            = $this->db->prefix('combo_offers');
        $today        = current_time('Y-m-d');

        $combos = $this->db->select(
            "SELECT * FROM `$t` WHERE is_active=1
             AND (valid_from IS NULL OR valid_from <= %s)
             AND (valid_to IS NULL OR valid_to >= %s)
             ORDER BY discount_value DESC",
            [$today, $today]
        );

        // Filter by city/newspaper relevance
        if ($city_name || $newspaper_id) {
            $combos = array_filter($combos, function($c) use ($city_name, $newspaper_id) {
                if ($city_name) {
                    $cities = json_decode($c['cities'] ?? '[]', true) ?: [];
                    if (!empty($cities) && !in_array($city_name, $cities)) return false;
                }
                if ($newspaper_id) {
                    $papers = json_decode($c['newspapers'] ?? '[]', true) ?: [];
                    if (!empty($papers) && !in_array($newspaper_id, $papers) && !in_array((string)$newspaper_id, $papers)) return false;
                }
                return true;
            });
        }

        wp_send_json_success(array_values($combos ?: []));
    }

    /* ── get_ad_sizes ────────────────────────────────────────────────────── */
    // TRACE: get_ad_sizes() — Trigger: wp_ajax_get_ad_sizes AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function get_ad_sizes(): void {
        $ad_type = Security::post('ad_type') ?: 'display';
        $t       = $this->db->prefix('ad_sizes');
        $sizes   = Cache::remember("ad_sizes_{$ad_type}", function() use ($t, $ad_type) {
            return $this->db->select(
                "SELECT * FROM `$t` WHERE is_active=1 AND (ad_type=%s OR ad_type='both') ORDER BY sort_order ASC",
                [$ad_type]
            );
        }, 3600);
        wp_send_json_success($sizes ?: []);
    }

    /* ── validate_coupon ─────────────────────────────────────────────────── */
    // TRACE: validate_coupon() — Trigger: wp_ajax_validate_coupon AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function validate_coupon(): void {
        $code   = strtoupper(trim(Security::post('code') ?? ''));
        $amount = Security::post('amount','float');

        if ( ! $code ) { wp_send_json_error(['message'=>'Coupon code is required.']); return; }

        $t      = $this->db->prefix('coupons');
        $coupon = $this->db->row("SELECT * FROM `$t` WHERE code=%s AND is_active=1", [$code], OBJECT);
        $today  = current_time('Y-m-d');

        if ( ! $coupon )
            { wp_send_json_error(['message'=>'Invalid coupon code.']); return; }
        if ( $coupon->valid_from && $coupon->valid_from > $today )
            { wp_send_json_error(['message'=>'Coupon is not yet active.']); return; }
        if ( $coupon->valid_to && $coupon->valid_to < $today )
            { wp_send_json_error(['message'=>'This coupon has expired.']); return; }
        if ( $coupon->max_uses > 0 && $coupon->used_count >= $coupon->max_uses )
            { wp_send_json_error(['message'=>'Coupon usage limit reached.']); return; }
        if ( $amount < $coupon->min_order )
            { wp_send_json_error(['message'=>"Minimum order of ₹{$coupon->min_order} required."]); return; }

        $discount = $coupon->discount_type === 'percentage'
            ? ($amount * $coupon->discount_value) / 100
            : $coupon->discount_value;

        wp_send_json_success(['discount'=>round($discount,2),'type'=>$coupon->discount_type,'value'=>$coupon->discount_value]);
    }

    /* ── ai_transform ────────────────────────────────────────────────────── */
    // TRACE: ai_transform() — Trigger: wp_ajax_ai_transform AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function ai_transform(): void {
        // ── Rate limit: max 10 AI requests per IP per 60 seconds ─────────
        $ip      = preg_replace('/[^0-9a-fA-F.:,]/', '', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $rl_key  = 'nas_ai_rl_' . md5( $ip );
        $rl_hits = (int) get_transient( $rl_key );
        if ( $rl_hits >= 10 ) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment and try again.'], 429);
            return;
        }
        set_transient( $rl_key, $rl_hits + 1, 60 );

        $content  = Security::post('content','textarea');
        $mode     = Security::post('mode') ?: 'improve'; // improve | shorten | formalise
        $category = Security::post('category');
        $ad_type  = Security::post('ad_type');

        if ( ! $content ) { wp_send_json_error(['message'=>'Content is required.']); return; }

        $prompts = [
            'improve'   => "You are an expert Indian newspaper ad copywriter. Improve the following ad to be more compelling, clear and effective for a newspaper classified. Keep the same meaning but make it more persuasive. Category: {$category}. Output ONLY the improved ad text, nothing else:\n\n{$content}",
            'shorten'   => "Shorten the following newspaper ad text to be more concise while keeping all key information. Remove filler words. Output ONLY the shortened ad text:\n\n{$content}",
            'formalise' => "Rewrite the following newspaper ad in a professional, formal tone suitable for a leading Indian newspaper. Output ONLY the rewritten text:\n\n{$content}",
        ];

        $prompt = $prompts[$mode] ?? $prompts['improve'];

        // Use OpenAI or fallback to basic text transformation
        $api_key = get_option('nas_openai_key','');
        if ($api_key) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => ['Authorization'=>"Bearer $api_key",'Content-Type'=>'application/json'],
                'body'    => json_encode(['model'=>'gpt-4o-mini','max_tokens'=>400,'messages'=>[['role'=>'user','content'=>$prompt]]]),
                'timeout' => 20,
            ]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $text = $body['choices'][0]['message']['content'] ?? '';
                if ($text) { wp_send_json_success(['content'=>trim($text)]); return; }
            }
        }

        // Fallback: basic transformation
        $out = $content;
        if ($mode === 'shorten') {
            $words = explode(' ', $content);
            if (count($words) > 30) {
                $out = implode(' ', array_slice($words, 0, (int)(count($words)*0.7))) . '…';
            }
        } elseif ($mode === 'formalise') {
            $out = ucfirst(rtrim($content,'.!?')) . '. For further information, please contact us.';
        }
        wp_send_json_success(['content'=>$out]);
    }

    /* ── get_booking ─────────────────────────────────────────────────────── */
    // TRACE: get_booking() — Trigger: wp_ajax_get_booking AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_booking(): void {
        $uid = Security::post('uid') ?: Security::get('uid','');
        $b   = $uid ? $this->repo->find_by_uid($uid) : null;
        $b ? wp_send_json_success($b) : wp_send_json_error(['message'=>'Booking not found.']);
    }

    /* ── get_client_bookings ─────────────────────────────────────────────── */
    // TRACE: get_client_bookings() — Trigger: wp_ajax_get_client_bookings AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_client_bookings(): void {
        // FIX (audit): every other handler in this class calls Security::check_nonce() first;
        // this one was missing it entirely, despite doing an unauthenticated-nonce read of the
        // logged-in user's booking list. Adding it for consistency with the rest of the file.
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $db   = Database::instance();
        $uid  = get_current_user_id();
        $ct   = $db->prefix('clients');
        $bt   = $db->prefix('bookings');
        $page = Security::post('page','int') ?: 1;

        $client = $db->row("SELECT id FROM `$ct` WHERE wp_user_id=%d LIMIT 1", [$uid], OBJECT);
        if ( ! $client ) { wp_send_json_success(['bookings'=>[],'total'=>0]); return; }

        $bookings = $this->repo->get_for_client($client->id, $page);
        $total    = (int) $db->scalar("SELECT COUNT(*) FROM `$bt` WHERE client_id=%d", [$client->id]);
        wp_send_json_success(['bookings'=>$bookings,'total'=>$total,'page'=>$page]);
    }

    /* ── get_all_bookings ────────────────────────────────────────────────── */
    // TRACE: get_all_bookings() — Trigger: wp_ajax_get_all_bookings AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_all_bookings(): void {
        Security::require_cap('nas_manage_bookings');
        $filters = [
            'status'      => Security::post('status'),
            'city_name'   => Security::post('city_name'),
            'search'      => Security::post('search'),
            'ad_type'     => Security::post('ad_type'),
            'date_from'   => Security::post('date_from'),
            'date_to'     => Security::post('date_to'),
        ];
        $page     = Security::post('page','int') ?: 1;
        $bookings = $this->repo->get_all($filters, $page);
        wp_send_json_success(['bookings'=>$bookings,'page'=>$page]);
    }

    /* ── update_status ───────────────────────────────────────────────────── */
    // TRACE: update_status() — Trigger: wp_ajax_update_status AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected.
    public function update_status(): void {
        Security::require_cap('nas_manage_bookings');
        $id     = Security::post('booking_id','int');
        $status = Security::post('status');
        $note   = Security::post('note','textarea') ?: '';
        if ( ! $id || ! $status ) { wp_send_json_error(['message'=>'Booking ID and status required.']); return; }
        $ok = $this->repo->update_status($id, $status, $note, get_current_user_id());
        $ok ? wp_send_json_success(['message'=>'Status updated.']) : wp_send_json_error(['message'=>'Update failed.']);
    }

    /* ── get_booking_detail ──────────────────────────────────────────────── */
    // TRACE: get_booking_detail() — Trigger: wp_ajax_get_booking_detail AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public function get_booking_detail(): void {
        Security::require_login();
        $id      = Security::post('id','int') ?: Security::post('booking_id','int');
        $booking = $id ? $this->repo->find($id) : null;

        if ( ! $booking ) { wp_send_json_error(['message'=>'Booking not found.']); return; }

        // Client can only see own bookings
        if ( ! current_user_can('nas_manage_bookings') ) {
            $db          = Database::instance();
            $client_row  = $db->row("SELECT id FROM `{$db->prefix('clients')}` WHERE wp_user_id=%d", [get_current_user_id()]);
            $booking_arr = is_object($booking) ? (array)$booking : (array)$booking;
            $client_id   = $client_row['id'] ?? 0;
            $booking_cid = $booking_arr['client_id'] ?? 0;
            if ( ! $client_row || (int)$client_id !== (int)$booking_cid )
                { wp_send_json_error(['message'=>'Access denied.']); return; }
        }

        // Cast to array for consistent access and enrichment
        $booking = is_object($booking) ? (array) $booking : (array) $booking;
        $db = Database::instance();

        // Enrich with newspaper & category names
        if ( ! empty($booking['newspaper_id']) ) {
            $p = $db->row("SELECT name,language,logo_url FROM `{$db->prefix('newspapers')}` WHERE id=%d", [$booking['newspaper_id']]);
            if ($p) {
                $booking['newspaper_name']     = $p['name'] ?? '';
                $booking['newspaper_language'] = $p['language'] ?? '';
                $booking['newspaper_logo']     = $p['logo_url'] ?? '';
            }
        }
        if ( ! empty($booking['city_id']) ) {
            $city = $db->row("SELECT name,state FROM `{$db->prefix('cities')}` WHERE id=%d", [$booking['city_id']]);
            if ($city) $booking['city_name'] = $city['name'] ?? '';
        }
        if ( ! empty($booking['category_id']) ) {
            $cat = $db->row("SELECT name FROM `{$db->prefix('categories')}` WHERE id=%d", [$booking['category_id']]);
            if ($cat) $booking['category_name'] = $cat['name'] ?? '';
        }
        if ( ! empty($booking['uid']) ) {
            $booking['booking_uid'] = $booking['uid'];
        }
        // Include payments and messages for completeness
        $payments = $db->select("SELECT * FROM `{$db->prefix('payments')}` WHERE booking_id=%d ORDER BY created_at DESC", [$booking['id']]);
        global $wpdb;
        $messages = $db->select(
            "SELECT m.*, u.display_name AS sender_name FROM `{$db->prefix('messages')}` m LEFT JOIN `{$wpdb->users}` u ON u.ID=m.sender_id WHERE m.booking_id=%d ORDER BY m.created_at ASC LIMIT 50",
            [$booking['id']]
        );
        $workflow_history = [];
        if (!empty($booking['workflow_history'])) {
            $workflow_history = json_decode($booking['workflow_history'], true) ?: [];
        }

        wp_send_json_success([
            'booking'          => $booking,
            'payments'         => $payments,
            'messages'         => $messages,
            'workflow_history' => $workflow_history,
        ]);
    }

    /* ── update_booking ──────────────────────────────────────────────────── */
    // TRACE: update_booking() — Trigger: wp_ajax_update_booking AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: explicit false check on DB op.
    public function update_booking(): void {
        Security::require_cap('nas_manage_bookings');
        $id   = Security::post('id','int');
        $data = [];
        foreach (['ad_content','ad_title','ad_type','edition','publish_date','total_amount','proof_url','tear_sheet_url','admin_notes'] as $field) {
            $v = Security::post($field, in_array($field,['ad_content','admin_notes']) ? 'textarea' : 'text');
            if ($v !== null && $v !== '') $data[$field] = $v;
        }
        if (!$id || !$data) { wp_send_json_error(['message'=>'Nothing to update.']); return; }
        $db = Database::instance();
        $ok = $db->update($db->prefix('bookings'), $data, ['id'=>$id]);
        if ($ok === false) { wp_send_json_error(['message'=>'Failed to save. Please try again.']); return; }
        wp_send_json_success(['message'=>'Booking updated.']);
    }

    /* ── assign_vendor ───────────────────────────────────────────────────── */
    // TRACE: assign_vendor() — Trigger: wp_ajax_assign_vendor AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: explicit false check on DB op.
    public function assign_vendor(): void {
        Security::require_cap('nas_manage_bookings');
        $id  = Security::post('booking_id','int');
        $vid = Security::post('vendor_id','int');
        if (!$id || !$vid) { wp_send_json_error(['message'=>'Booking and vendor required.']); return; }
        $db = Database::instance();
        $ok = $db->update($db->prefix('bookings'), ['assigned_vendor_id'=>$vid], ['id'=>$id]);
        if ($ok === false) { wp_send_json_error(['message'=>'Failed to assign vendor. Please try again.']); return; }
        $this->repo->update_status($id,'ad_processing','Vendor assigned — booking now in processing.',get_current_user_id()); // fixed: 'vendor_assigned' is non-canonical; canonical is 'ad_processing'
        wp_send_json_success(['message'=>'Vendor assigned.']);
    }

    /* ── update_payment ──────────────────────────────────────────────────── */
    // TRACE: update_payment() — Trigger: wp_ajax_update_payment AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: explicit false check on DB op.
    public function update_payment(): void {
        Security::require_cap('nas_manage_bookings');
        $id     = Security::post('booking_id','int');
        $status = Security::post('payment_status');
        $method = Security::post('payment_method','text') ?: 'manual';
        $ref    = Security::post('payment_ref');
        if (!$id) { wp_send_json_error(['message'=>'Booking required.']); return; }
        $db = Database::instance();
        $ok = $db->update($db->prefix('bookings'),['payment_status'=>$status,'payment_method'=>$method,'payment_ref'=>$ref],['id'=>$id]);
        if ($ok === false) { wp_send_json_error(['message'=>'Failed to update payment. Please try again.']); return; }
        wp_send_json_success(['message'=>'Payment updated.']);
    }

    /* ── delete_booking ──────────────────────────────────────────────────── */
    // TRACE: delete_booking() — Trigger: wp_ajax_delete_booking AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → deletes DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function delete_booking(): void {
        Security::require_cap('nas_manage_bookings');
        $id = Security::post('id','int');
        if (!$id) { wp_send_json_error(['message'=>'ID required.']); return; }
        $db = Database::instance();
        $db->delete( $db->prefix('bookings'), ['id' => $id] );  // fixed: use prepared delete() not raw query
        wp_send_json_success(['message'=>'Booking deleted.']);
    }

    /* ── reject_booking ──────────────────────────────────────────────────── */
    // TRACE: reject_booking() — Trigger: wp_ajax_reject_booking AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function reject_booking(): void {
        // FIX (audit): this handler had no nonce check at all — every other method in this class
        // does. Also: the Moderation dashboard's Reject button (templates/moderation/dashboard.php
        // modReject()) sends the booking id under the key 'id', not 'booking_id' — this handler
        // only ever read 'booking_id', so $id was always 0 and every reject attempt from
        // Moderation failed immediately with "Booking required," every time, with no exception.
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $id     = (int) ( Security::post('booking_id','int') ?: Security::post('id','int') );
        $reason = Security::post('reason','textarea') ?: 'No reason provided.';
        if (!$id) { wp_send_json_error(['message'=>'Booking required.']); return; }
        $this->repo->update_status($id,'rejected',$reason,get_current_user_id());
        // FIX (audit): clear is_flagged on rejection, matching the same fix already applied to
        // the dead-code sibling in dashboards/ModerationDashboard.php — a flagged booking that
        // gets rejected should leave the Flagged tab.
        $db = Database::instance();
        $db->update( $db->prefix('bookings'), [ 'is_flagged' => 0 ], [ 'id' => $id ] );
        wp_send_json_success(['message'=>'Booking rejected.']);
    }

    /* ── send_quotation ──────────────────────────────────────────────────── */
    // TRACE: send_quotation() — Trigger: wp_ajax_send_quotation AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function send_quotation(): void {
        Security::require_cap('nas_manage_bookings');
        $id    = Security::post('booking_id','int');
        $quote = Security::post('quote_amount','float');
        $note  = Security::post('note','textarea');
        if (!$id || !$quote) { wp_send_json_error(['message'=>'Booking and quote amount required.']); return; }

        $db = Database::instance();
        $db->update($db->prefix('bookings'),['quoted_amount'=>$quote,'quote_note'=>$note,'status'=>'quotation_sent'],['id'=>$id]);
        $this->repo->update_status($id,'quotation_sent',"Quote: ₹{$quote}. {$note}",get_current_user_id());

        Queue::push('\NAS\Modules\Notifications\NotificationJob',['type'=>'quotation_sent','booking_id'=>$id,'quote'=>$quote],0,'high');
        wp_send_json_success(['message'=>'Quotation sent.']);
    }

    /* ── get_workflow_history ────────────────────────────────────────────── */
    // TRACE: get_workflow_history() — Trigger: wp_ajax_get_workflow_history AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_workflow_history(): void {
        Security::require_login();
        $id      = Security::post('booking_id','int');
        $booking = $id ? $this->repo->find($id) : null;
        if (!$booking) { wp_send_json_error(['message'=>'Booking not found.']); return; }
        wp_send_json_success(json_decode($booking->workflow_history ?? '[]', true) ?: []);
    }

    /* ── upload_proof ────────────────────────────────────────────────────── */
    // TRACE: upload_proof() — Trigger: wp_ajax_upload_proof AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function upload_proof(): void {
        // FIX (audit): no nonce check existed on this mutation endpoint. Currently unused by any
        // frontend (confirmed — no caller found anywhere in templates/ or assets/), but it's a
        // live, callable, state-changing endpoint, so fixing for defense-in-depth.
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('nas_manage_bookings');
        $id  = Security::post('booking_id','int');
        $url = Security::post('proof_url');
        if (!$id || !$url) { wp_send_json_error(['message'=>'Booking and URL required.']); return; }
        $db = Database::instance();
        $db->update($db->prefix('bookings'),['proof_url'=>$url],['id'=>$id]);
        $this->repo->update_status($id,'proof_ready','Proof uploaded.',get_current_user_id());
        Queue::push('\NAS\Modules\Notifications\NotificationJob',['type'=>'proof_ready','booking_id'=>$id],0,'high');
        wp_send_json_success(['message'=>'Proof updated.']);
    }

    /* ── upload_tear_sheet ───────────────────────────────────────────────── */
    // TRACE: upload_tear_sheet() — Trigger: wp_ajax_upload_tear_sheet AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → updates DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function upload_tear_sheet(): void {
        Security::require_cap('nas_manage_bookings');
        $id  = Security::post('booking_id','int');
        $url = Security::post('tear_sheet_url');
        if (!$id || !$url) { wp_send_json_error(['message'=>'Booking and URL required.']); return; }
        $db = Database::instance();
        $db->update($db->prefix('bookings'),['tear_sheet_url'=>$url,'status'=>'published'],['id'=>$id]);
        $this->repo->update_status($id,'published','Tear sheet uploaded. Booking complete.',get_current_user_id());
        wp_send_json_success(['message'=>'Tear sheet saved. Booking marked as published.']);
    }

    /* ── get_booking_stats ───────────────────────────────────────────────── */
    // TRACE: get_booking_stats() — Trigger: wp_ajax_get_booking_stats AJAX action.
    //        Steps: checks permissions → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_booking_stats(): void {
        Security::require_cap('nas_manage_bookings');
        wp_send_json_success($this->repo->stats());
    }
}
