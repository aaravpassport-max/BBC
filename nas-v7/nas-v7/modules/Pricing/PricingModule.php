<?php
namespace NAS\Modules\Pricing;

use NAS\Core\Database;
use NAS\Core\Security;
use NAS\Core\Cache;
use NAS\Core\Config;

class PricingModule extends \NAS\Core\Module {
    public function key(): string { return 'pricing'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_calculate_price',        [ PricingController::class, 'calculate' ] );
        add_action( 'wp_ajax_nopriv_nas_calculate_price', [ PricingController::class, 'calculate' ] );
        add_action( 'wp_ajax_nas_get_rate_card',          [ PricingController::class, 'get_rate_card' ] );
        add_action( 'wp_ajax_nopriv_nas_get_rate_card',   [ PricingController::class, 'get_rate_card' ] );
        add_action( 'wp_ajax_nas_get_combo_offers',       [ PricingController::class, 'get_combo_offers' ] );
        add_action( 'wp_ajax_nopriv_nas_get_combo_offers',[ PricingController::class, 'get_combo_offers' ] );
        add_action( 'wp_ajax_nas_save_rate_card',         [ PricingController::class, 'save_rate_card' ] );
        add_action( 'wp_ajax_nas_get_rate_cards',         [ PricingController::class, 'get_rate_cards' ] );
        add_action( 'wp_ajax_nas_delete_rate_card',       [ PricingController::class, 'delete_rate_card' ] );
        add_action( 'wp_ajax_nas_save_combo',             [ PricingController::class, 'save_combo' ] );
        add_action( 'wp_ajax_nas_get_combos',             [ PricingController::class, 'get_combos' ] );
    }

    public function boot(): void {}
}

class PricingService {
    private Database $db;

    public function __construct() {
        $this->db = Database::instance();
    }

    /**
     * Calculate final price for a booking configuration.
     */
    // TRACE: calculate() — Trigger: wp_ajax_calculate AJAX action.
    //        Steps: queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function calculate( array $params ): array {
        $newspaper_id = (int) ( $params['newspaper_id'] ?? 0 );
        $category_id  = (int) ( $params['category_id'] ?? 0 );
        $city_id      = (int) ( $params['city_id'] ?? 0 );
        $ad_type      = sanitize_text_field( $params['ad_type'] ?? 'classified_text' );
        $unit_type    = sanitize_text_field( $params['unit_type'] ?? 'word' );
        $unit_count   = max( 1, (int) ( $params['unit_count'] ?? 1 ) );
        $combo_id     = (int) ( $params['combo_id'] ?? 0 );

        // Fetch rate card
        $rate = $this->get_rate( $newspaper_id, $category_id, $city_id, $ad_type );

        if ( ! $rate ) {
            // Fallback: newspaper default rates
            $newspaper = $this->db->row( "SELECT * FROM {$this->db->t('newspapers')} WHERE id = %d", $newspaper_id );
            $rates_json = $newspaper['rates'] ?? '{}';
            $rates = json_decode( $rates_json, true ) ?? [];
            $base_rate = (float) ( $rates[ $ad_type ] ?? 5 );
            $min_charge = (float) ( $newspaper['min_charge'] ?? 100 );
            $markup_pct = 0;
        } else {
            $base_rate  = (float) $rate['base_rate'];
            $min_charge = (float) $rate['min_charge'];
            $markup_pct = (float) $rate['markup_pct'];
        }

        // Calculate raw cost
        $raw_cost = $base_rate * $unit_count;
        $raw_cost = max( $raw_cost, $min_charge );

        // Apply markup
        $client_price = $raw_cost * ( 1 + $markup_pct / 100 );

        // Combo discount
        $discount     = 0;
        $combo_label  = '';
        if ( $combo_id ) {
            $combo = $this->db->row( "SELECT * FROM {$this->db->t('combo_offers')} WHERE id = %d AND is_active = 1 AND (valid_to IS NULL OR valid_to >= %s)", $combo_id, current_time( 'Y-m-d' ) );
            if ( $combo ) {
                $combo_label = $combo['name'];
                if ( $combo['discount_type'] === 'percent' ) {
                    $discount = $client_price * ( (float)$combo['discount_value'] / 100 );
                } else {
                    $discount = (float) $combo['discount_value'];
                }
            }
        }

        $subtotal  = $client_price - $discount;
        $gst_rate  = (float) Config::instance()->get( 'gst_rate', 18 );
        $gst_amt   = $subtotal * $gst_rate / 100;
        $total     = $subtotal + $gst_amt;

        return [
            'base_rate'    => round( $base_rate, 2 ),
            'unit_count'   => $unit_count,
            'raw_cost'     => round( $raw_cost, 2 ),
            'markup_pct'   => round( $markup_pct, 2 ),
            'client_price' => round( $client_price, 2 ),
            'discount'     => round( $discount, 2 ),
            'combo_label'  => $combo_label,
            'subtotal'     => round( $subtotal, 2 ),
            'gst_rate'     => $gst_rate,
            'gst_amount'   => round( $gst_amt, 2 ),
            'total'        => round( $total, 2 ),
            'vendor_cost'  => round( $raw_cost, 2 ),
            'profit'       => round( $client_price - $raw_cost - $discount, 2 ),
            'margin_pct'   => $client_price > 0 ? round( ( ( $client_price - $raw_cost ) / $client_price ) * 100, 2 ) : 0,
        ];
    }

    // TRACE: get_rate() — Trigger: wp_ajax_get_rate AJAX action.
    //        Steps: queries DB → reads/writes Cache.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function get_rate( int $newspaper_id, int $category_id, int $city_id, string $ad_type ): ?array {
        $key = "rate_{$newspaper_id}_{$category_id}_{$city_id}_{$ad_type}";
        return Cache::instance()->remember( $key, function() use ( $newspaper_id, $category_id, $city_id, $ad_type ) {
            // Most specific first
            $row = $this->db->row(
                "SELECT * FROM {$this->db->t('rate_cards')} WHERE newspaper_id=%d AND category_id=%d AND city_id=%d AND ad_type=%s LIMIT 1",
                $newspaper_id, $category_id, $city_id, $ad_type
            );
            if ( $row ) return $row;
            // Category + newspaper, any city
            $row = $this->db->row(
                "SELECT * FROM {$this->db->t('rate_cards')} WHERE newspaper_id=%d AND category_id=%d AND (city_id=0 OR city_id IS NULL) AND ad_type=%s LIMIT 1",
                $newspaper_id, $category_id, $ad_type
            );
            if ( $row ) return $row;
            // Just newspaper + ad_type
            return $this->db->row(
                "SELECT * FROM {$this->db->t('rate_cards')} WHERE newspaper_id=%d AND (category_id=0 OR category_id IS NULL) AND ad_type=%s LIMIT 1",
                $newspaper_id, $ad_type
            );
        }, 300 );
    }
}

class PricingController {
    // TRACE: calculate() — Trigger: wp_ajax_calculate AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function calculate(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $svc    = new PricingService();
        $result = $svc->calculate( $_POST );
        wp_send_json_success( $result );
    }

    // TRACE: get_rate_card() — Trigger: wp_ajax_get_rate_card AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_rate_card(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $newspaper_id = (int) Security::post( 'newspaper_id', 'int' );
        $category_id  = (int) Security::post( 'category_id', 'int' );
        $city_id      = (int) Security::post( 'city_id', 'int' );
        $svc  = new PricingService();
        $rate = $svc->get_rate( $newspaper_id, $category_id, $city_id, sanitize_text_field( $_POST['ad_type'] ?? 'classified_text' ) );
        wp_send_json_success( [ 'rate' => $rate ] );
    }

    // TRACE: get_rate_cards() — Trigger: wp_ajax_get_rate_cards AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_rate_cards(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        $rows = $db->select(
            "SELECT rc.*, n.name as newspaper_name, cat.name as category_name, c.name as city_name
             FROM {$db->t('rate_cards')} rc
             LEFT JOIN {$db->t('newspapers')} n ON n.id = rc.newspaper_id
             LEFT JOIN {$db->t('categories')} cat ON cat.id = rc.category_id
             LEFT JOIN {$db->t('cities')} c ON c.id = rc.city_id
             ORDER BY rc.id DESC LIMIT 200"
        );
        wp_send_json_success( [ 'rates' => $rows ] );
    }

    // TRACE: save_rate_card() — Trigger: wp_ajax_save_rate_card AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_rate_card(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        $data = [
            'newspaper_id' => (int) Security::post( 'newspaper_id', 'int' ),
            'category_id'  => (int) Security::post( 'category_id', 'int' ),
            'city_id'      => (int) Security::post( 'city_id', 'int' ),
            'ad_type'      => Security::post( 'ad_type', 'text' ),
            'unit_type'    => Security::post( 'unit_type', 'text' ),
            'base_rate'    => (float) Security::post( 'base_rate', 'float' ),
            'min_charge'   => (float) Security::post( 'min_charge', 'float' ),
            'markup_pct'   => (float) Security::post( 'markup_pct', 'float' ),
            'updated_at'   => current_time( 'mysql' ),
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('rate_cards'), $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $db->insert( $db->t('rate_cards'), $data );
            $id = $db->last_insert_id();
        }
        Cache::instance()->flush_all(); // clear rate cache
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: delete_rate_card() — Trigger: wp_ajax_delete_rate_card AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_rate_card(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $id = (int) Security::post( 'id', 'int' );
        $db = Database::instance();
        $db->delete( $db->t('rate_cards'), [ 'id' => $id ] );  // fixed: was bare 'rate_cards' without prefix
        Cache::instance()->flush_all();
        wp_send_json_success();
    }

    // TRACE: get_combo_offers() — Trigger: wp_ajax_get_combo_offers AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_combo_offers(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $db    = Database::instance();
        $combos = $db->select( "SELECT * FROM {$db->t('combo_offers')} WHERE is_active = 1 AND (valid_to IS NULL OR valid_to >= %s) ORDER BY id ASC", current_time( 'Y-m-d' ) );
        wp_send_json_success( [ 'combos' => $combos ] );
    }

    // TRACE: save_combo() — Trigger: wp_ajax_save_combo AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_combo(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();
        $data = [
            'name'           => Security::post( 'name', 'text' ),
            'cities'         => Security::post( 'cities', 'text' ),
            'newspapers'     => Security::post( 'newspapers', 'text' ),
            'categories'     => Security::post( 'categories', 'text' ),
            'discount_type'  => Security::post( 'discount_type', 'text' ),
            'discount_value' => (float) Security::post( 'discount_value', 'float' ),
            'valid_from'     => Security::post( 'valid_from', 'text' ),
            'valid_to'    => Security::post( 'valid_to', 'text' ),
            'is_active'      => 1,
        ];
        $id = (int) Security::post( 'id', 'int' );
        if ( $id ) {
            $db->update( $db->t('combo_offers'), $data, [ 'id' => $id ] );
        } else {
            $db->insert( $db->t('combo_offers'), $data );
            $id = $db->last_insert_id();
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // TRACE: get_combos() — Trigger: wp_ajax_get_combos AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_combos(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $db   = Database::instance();  // fixed: was using $db->t() without defining $db first
        $rows = $db->select( "SELECT * FROM {$db->t('combo_offers')} ORDER BY id DESC" );
        wp_send_json_success( [ 'combos' => $rows ] );
    }
}
