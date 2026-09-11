<?php
namespace NAS\Modules\Vendor;

use NAS\Core\Database;
use NAS\Core\Security;
use NAS\Core\Helpers;

class VendorModule extends \NAS\Core\Module {
    public function key(): string { return 'vendor'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_get_vendors',       [ VendorController::class, 'get_vendors' ] );
        add_action( 'wp_ajax_nas_save_vendor',       [ VendorController::class, 'save_vendor' ] );
        add_action( 'wp_ajax_nas_delete_vendor',     [ VendorController::class, 'delete_vendor' ] );
        add_action( 'wp_ajax_nas_get_vendor',        [ VendorController::class, 'get_vendor' ] );
        add_action( 'wp_ajax_nas_get_vendor_cities', [ VendorController::class, 'get_vendor_cities' ] );
        add_action( 'wp_ajax_nas_get_available_vendors', [ VendorController::class, 'get_available' ] );
    }

    public function boot(): void {}
}

class VendorRepository {
    private Database $db;
    public function __construct() { $this->db = Database::instance(); }

    // TRACE: all() — Trigger: wp_ajax_all AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: empty input values handled.
    public function all( array $filters = [], int $per_page = 20, int $page = 1 ): array {
        $where = '1=1';
        $args  = [];
        if ( ! empty( $filters['city'] ) ) {
            $where .= " AND JSON_CONTAINS(cities_supported, %s)";
            $args[] = json_encode( $filters['city'] );
        }
        if ( ! empty( $filters['status'] ) ) {
            $where .= " AND status = %s";
            $args[] = $filters['status'];
        }
        $offset = ( $page - 1 ) * $per_page;
        $t      = $this->db->t( 'vendors' );
        // Fixed: Database::scalar/select expect $params as array, not variadic splat
        $total  = (int) $this->db->scalar( "SELECT COUNT(*) FROM $t WHERE $where", $args );
        $rows   = $this->db->select( "SELECT * FROM $t WHERE $where ORDER BY name ASC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) );
        return [ 'vendors' => $rows, 'total' => $total, 'pages' => ceil( $total / $per_page ) ];
    }

    // TRACE: find() — Trigger: wp_ajax_find AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find( int $id ): ?array {
        return $this->db->row( "SELECT * FROM {$this->db->t('vendors')} WHERE id = %d", $id );
    }

    // TRACE: Called by VendorController::save_vendor() on new vendor creation and VendorRegisterController::handle().
    //        Precondition: $data is sanitised array from POST; uid not yet set.
    //        Postcondition: Row inserted into nas_vendors; returns new vendor ID (>0) or 0 on DB failure.
    //        Edge cases: DB insert failure → returns 0; caller must check and report error.
    // TRACE: create() — Trigger: wp_ajax_create AJAX action.
    //        Steps: inserts DB row → updates DB row → deletes DB row → returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public function create( array $data ): int {
        $data['uid']        = Helpers::generate_uid( 'VND' );
        $data['created_at'] = current_time( 'mysql' );
        $data['updated_at'] = current_time( 'mysql' );
        $this->db->insert( $this->db->t('vendors'), $data );
        return (int) $this->db->last_insert_id();
    }

    // TRACE: update() — Trigger: wp_ajax_update AJAX action.
    //        Steps: updates DB row → deletes DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function update( int $id, array $data ): bool {
        $data['updated_at'] = current_time( 'mysql' );
        return $this->db->update( $this->db->t('vendors'), $data, [ 'id' => $id ] );
    }

    // TRACE: delete() — Trigger: wp_ajax_delete AJAX action.
    //        Steps: deletes DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function delete( int $id ): bool {
        return $this->db->delete( $this->db->t('vendors'), [ 'id' => $id ] );
    }

    // TRACE: find_for_newspaper() — Trigger: wp_ajax_find_for_newspaper AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function find_for_newspaper( int $newspaper_id, int $city_id ): array {
        $newspaper = $this->db->row( "SELECT slug FROM {$this->db->t('newspapers')} WHERE id = %d", $newspaper_id );
        $city      = $this->db->row( "SELECT name FROM {$this->db->t('cities')} WHERE id = %d", $city_id );
        if ( ! $newspaper || ! $city ) return [];
        return $this->db->select(
            "SELECT * FROM {$this->db->t('vendors')} WHERE status='active'
             AND JSON_CONTAINS(newspapers_supported, %s)
             AND JSON_CONTAINS(cities_supported, %s)
             ORDER BY rating DESC",
            [ json_encode( $newspaper['slug'] ), json_encode( $city['name'] ) ]
        );
    }
}

class VendorController {
    private static function repo(): VendorRepository { return new VendorRepository(); }

    // TRACE: get_vendors() — Trigger: wp_ajax_get_vendors AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_vendors(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $filters  = [
            'city'   => sanitize_text_field( $_GET['city'] ?? '' ),
            'status' => sanitize_text_field( $_GET['status'] ?? '' ),
        ];
        $per_page = (int) ( $_GET['per_page'] ?? 20 );
        $page     = (int) ( $_GET['page'] ?? 1 );
        wp_send_json_success( self::repo()->all( $filters, $per_page, $page ) );
    }

    // TRACE: get_vendor() — Trigger: wp_ajax_get_vendor AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_vendor(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id = (int) Security::post( 'id', 'int' );
        $v  = self::repo()->find( $id );
        $v ? wp_send_json_success( [ 'vendor' => $v ] ) : wp_send_json_error( 'Not found' );
    }

    // TRACE: save_vendor() — Trigger: wp_ajax_save_vendor AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_vendor(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $data = [
            'name'                  => Security::post( 'name', 'text' ),
            'phone'                 => Security::post( 'phone', 'text' ),
            'email'                 => Security::post( 'email', 'email' ),
            'whatsapp_number'       => Security::post( 'whatsapp_number', 'text' ),
            'cities_supported'      => Security::post( 'cities_supported', 'text' ),
            'newspapers_supported'  => Security::post( 'newspapers_supported', 'text' ),
            'categories_supported'  => Security::post( 'categories_supported', 'text' ),
            'rating'                => (float) Security::post( 'rating', 'float' ),
            'status'                => Security::post( 'status', 'text' ) ?: 'active',
            'notes'                 => Security::post( 'notes', 'textarea' ),
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            self::repo()->update( $id, $data );
            wp_send_json_success( [ 'id' => $id, 'message' => 'Vendor updated.' ] );
        } else {
            $new_id = self::repo()->create( $data );
            wp_send_json_success( [ 'id' => $new_id, 'message' => 'Vendor added.' ] );
        }
    }

    // TRACE: Trigger: wp_ajax_nas_delete_vendor.
    //        Precondition: manage_options cap + valid nonce. $id > 0.
    //        Postcondition: vendor row deleted from nas_vendors. Success response returned.
    //        Edge cases: $id=0 → guards, no delete. Cascade: assigned_vendor_id on bookings becomes orphaned (FK not enforced; acceptable).
    // TRACE: delete_vendor() — Trigger: wp_ajax_delete_vendor AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: id=0 rejected.
    public static function delete_vendor(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id = (int) Security::post( 'id', 'int' );
        if ( ! $id ) { wp_send_json_error( [ 'message' => 'Vendor ID required.' ] ); return; }

        // 12-B Orphan prevention: nullify vendor assignment on open bookings before deleting vendor
        $db = Database::instance();
        $db->raw()->query( $db->raw()->prepare(
            "UPDATE `{$db->t('bookings')}` SET assigned_vendor_id=0, status='under_review', updated_at=NOW() WHERE assigned_vendor_id=%d AND status NOT IN ('published','completed','rejected','cancelled')",
            $id
        ) );

        self::repo()->delete( $id );
        \NAS\Core\AuditLogger::deleted( 'vendors', $id, ['id'=>$id], 'Vendor deleted; open bookings returned to under_review' );
        wp_send_json_success( [ 'message' => 'Vendor deleted. Open bookings returned to under_review for reassignment.' ] );
    }

    // TRACE: get_vendor_cities() — Trigger: wp_ajax_get_vendor_cities AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_vendor_cities(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $db = Database::instance();  // fixed: was using $this->db in a static method (fatal error)
        wp_send_json_success( [ 'cities' => $db->select( "SELECT id, name, state FROM {$db->t('cities')} ORDER BY name ASC" ) ] );
    }

    // TRACE: get_available() — Trigger: wp_ajax_get_available AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_available(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $newspaper_id = (int) Security::post( 'newspaper_id', 'int' );
        $city_id      = (int) Security::post( 'city_id', 'int' );
        $vendors      = self::repo()->find_for_newspaper( $newspaper_id, $city_id );
        wp_send_json_success( [ 'vendors' => $vendors ] );
    }
}

// ── Self-Registration Controller (appended to VendorModule.php) ──────────────

class VendorRegisterController {

    public static function register(): void {
        // Allow non-logged-in users to register
        add_action( 'wp_ajax_nas_vendor_register',        [ self::class, 'handle' ] );
        add_action( 'wp_ajax_nopriv_nas_vendor_register', [ self::class, 'handle' ] );
        add_shortcode( 'nas_vendor_register', function() {
            ob_start();
            include NAS_PATH . 'templates/public/vendor-register.php';
            return ob_get_clean();
        });
    }

    // TRACE: handle() — Trigger: wp_ajax_handle AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function handle(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );

        // Collect and validate
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $contact    = sanitize_text_field( $_POST['contact'] ?? '' );
        $phone      = preg_replace( '/\D/', '', $_POST['phone'] ?? '' );
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $username   = sanitize_user( $_POST['username'] ?? '' );
        $password   = $_POST['password'] ?? '';
        $whatsapp   = sanitize_text_field( $_POST['whatsapp'] ?? '' );
        $gst        = strtoupper( sanitize_text_field( $_POST['gst'] ?? '' ) );
        $pan        = strtoupper( sanitize_text_field( $_POST['pan'] ?? '' ) );
        $bank       = sanitize_textarea_field( $_POST['bank'] ?? '' );
        $notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );
        $cities_raw = sanitize_text_field( $_POST['cities'] ?? '[]' );
        $papers_raw = sanitize_text_field( $_POST['newspapers'] ?? '[]' );

        if ( ! $name )     wp_send_json_error(['message' => 'Business name is required.']);
        if ( ! $phone || strlen($phone) < 10 ) wp_send_json_error(['message' => 'Valid phone number required.']);
        if ( ! is_email($email) )    wp_send_json_error(['message' => 'Valid email address required.']);
        if ( ! $username ) wp_send_json_error(['message' => 'Username is required.']);
        if ( strlen($password) < 8 ) wp_send_json_error(['message' => 'Password must be at least 8 characters.']);

        // Check for duplicate
        if ( username_exists($username) )  wp_send_json_error(['message' => 'This username is already taken. Please choose another.']);
        if ( email_exists($email) )        wp_send_json_error(['message' => 'An account with this email already exists. Please log in or use a different email.']);

        // Create WP user
        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error($user_id) ) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Set role and display name
        $user = new \WP_User($user_id);
        $user->set_role('nas_vendor');
        wp_update_user(['ID' => $user_id, 'display_name' => $name . ' (' . $contact . ')']);

        // Create vendor profile in database
        $db     = Database::instance();
        $cities = json_decode($cities_raw, true) ?: [];
        $papers = json_decode($papers_raw, true) ?: [];

        $vendor_id = $db->insert( $db->t('vendors'), [
            'uid'                  => \NAS\Core\Helpers::generate_uid('VND'),
            'wp_user_id'           => $user_id,
            'name'                 => $name,
            'email'                => $email,
            'phone'                => $phone,
            'whatsapp_number'      => $whatsapp ?: $phone,
            'gst_number'           => $gst,
            'pan_number'           => $pan,
            'bank_details'         => $bank,
            'notes'                => $notes,
            'cities_supported'     => wp_json_encode($cities),
            'newspapers_supported' => wp_json_encode($papers),
            'categories_supported' => '[]',
            'status'               => 'pending', // Admin must activate
            'rating'               => 5.0,
            'created_at'           => gmdate('Y-m-d H:i:s'),
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ]);

        if ( ! $vendor_id ) {
            // Roll back WP user if vendor insert fails
            wp_delete_user($user_id);
            wp_send_json_error(['message' => 'Failed to create vendor profile. Please try again.']);
        }

        // Send welcome email
        $cfg   = \NAS\Core\Config::instance();
        $brand = $cfg->get('brand_name', get_bloginfo('name'));
        $login = home_url('/newspaper-ad-login/');

        wp_mail(
            $email,
            "Welcome to {$brand} — Vendor Registration Received",
            "Hi {$name},\n\nThank you for registering as a vendor partner with {$brand}.\n\nYour account is currently under review. We will activate your account within 24 hours and send you a confirmation.\n\nYour login: {$username}\nLogin page: {$login}\n\nThank you!\n— {$brand} Team"
        );

        // Notify admin
        wp_mail(
            get_option('admin_email'),
            "[{$brand}] New Vendor Registration: {$name}",
            "A new vendor has registered:\n\nName: {$name}\nContact: {$contact}\nPhone: {$phone}\nEmail: {$email}\nUsername: {$username}\nCities: " . implode(', ', $cities) . "\n\nPlease review and activate from the Admin Panel > Vendors section."
        );

        wp_send_json_success([
            'message'  => 'Account created successfully! Please check your email for details.',
            'redirect' => home_url('/newspaper-ad-login/'),
        ]);
    }
}

// Register the vendor registration on init
add_action('init', function() {
    VendorRegisterController::register();
}, 15);
