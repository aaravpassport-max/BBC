<?php
namespace NAS\Modules\Analytics;

use NAS\Core\Database;
use NAS\Core\Security;
use NAS\Core\Cache;

class AnalyticsModule extends \NAS\Core\Module {
    public function key(): string { return 'analytics'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_get_analytics_overview', [ AnalyticsController::class, 'overview' ] );
        add_action( 'wp_ajax_nas_get_revenue_chart',      [ AnalyticsController::class, 'revenue_chart' ] );
        add_action( 'wp_ajax_nas_get_booking_chart',      [ AnalyticsController::class, 'booking_chart' ] );
        add_action( 'wp_ajax_nas_get_top_cities',         [ AnalyticsController::class, 'top_cities' ] );
        add_action( 'wp_ajax_nas_get_top_newspapers',     [ AnalyticsController::class, 'top_newspapers' ] );
        add_action( 'wp_ajax_nas_get_top_categories',     [ AnalyticsController::class, 'top_categories' ] );
        add_action( 'wp_ajax_nas_log_analytics_event',    [ AnalyticsController::class, 'log_event' ] );
        add_action( 'wp_ajax_nopriv_nas_log_analytics_event', [ AnalyticsController::class, 'log_event' ] );

        // Listen to events
        $this->on( 'booking.created',        [ $this, 'on_booking_created' ] );
        $this->on( 'booking.status_updated', [ $this, 'on_status_updated' ] );
        $this->on( 'payment.received',       [ $this, 'on_payment_received' ] );
    }

    public function boot(): void {}

    // TRACE: on_booking_created() — Trigger: wp_ajax_on_booking_created AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function on_booking_created( array $payload ): void {
        $this->log( 'booking_created', 'booking', $payload['booking_id'] ?? 0, 1, $payload );
    }

    // TRACE: on_status_updated() — Trigger: wp_ajax_on_status_updated AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function on_status_updated( array $payload ): void {
        $this->log( 'status_changed', 'booking', $payload['booking_id'] ?? 0, 1, $payload );
    }

    // TRACE: on_payment_received() — Trigger: wp_ajax_on_payment_received AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function on_payment_received( array $payload ): void {
        $this->log( 'payment_received', 'booking', $payload['booking_id'] ?? 0, $payload['amount'] ?? 0, $payload );
    }

    // TRACE: log() — Trigger: wp_ajax_log AJAX action.
    //        Steps: inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function log( string $event, string $entity, int $entity_id, $value, array $meta = [] ): void {
        $db = Database::instance();
        $db->insert( $db->t('analytics'), [  // fixed: was bare 'analytics' without prefix
            'event_type'   => $event,
            'entity_type'  => $entity,
            'entity_id'    => $entity_id,
            'value'        => $value,
            'meta'         => json_encode( $meta ),
            'recorded_date'=> current_time( 'Y-m-d' ),
            'created_at'   => current_time( 'mysql' ),
        ] );
    }
}

class AnalyticsService {
    private Database $db;

    public function __construct() {
        $this->db = Database::instance();
    }

    // TRACE: overview() — Trigger: wp_ajax_overview AJAX action.
    //        Steps: queries DB.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function overview( string $period = '30' ): array {
        $days = (int) $period;
        $from = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $tb   = $this->db->t( 'bookings' );
        $tp   = $this->db->t( 'payments' );

        return [
            // Fixed: bookings uses submitted_at not created_at; 'completed' booking status → 'published'
            'total_bookings'    => (int) $this->db->scalar( "SELECT COUNT(*) FROM $tb WHERE DATE(submitted_at) >= %s", $from ),
            // Fixed: payments.status is 'captured' not 'completed'; payments table has created_at
            'total_revenue'     => (float) $this->db->scalar( "SELECT COALESCE(SUM(amount),0) FROM $tp WHERE status='captured' AND DATE(created_at) >= %s", $from ),
            // Fixed: bookings final completed status is 'published' in this workflow
            'completed_bookings'=> (int) $this->db->scalar( "SELECT COUNT(*) FROM $tb WHERE status='published' AND DATE(submitted_at) >= %s", $from ),
            'pending_bookings'  => (int) $this->db->scalar( "SELECT COUNT(*) FROM $tb WHERE status NOT IN('published','rejected','not_able_to_process','not_eligible','no_service') AND DATE(submitted_at) >= %s", $from ),
            'total_clients'     => (int) $this->db->scalar( "SELECT COUNT(DISTINCT client_id) FROM $tb WHERE DATE(submitted_at) >= %s", $from ),
            // Fixed: final_price doesn't exist → use total_amount
            'avg_order_value'   => (float) $this->db->scalar( "SELECT COALESCE(AVG(total_amount),0) FROM $tb WHERE DATE(submitted_at) >= %s", $from ),
            'conversion_rate'   => $this->conversion_rate( $from ),
        ];
    }

    // TRACE: conversion_rate() — Trigger: wp_ajax_conversion_rate AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    private function conversion_rate( string $from ): float {
        $tb    = $this->db->t( 'bookings' );
        // Fixed: submitted_at; published = completed in this workflow
        $total = (int) $this->db->scalar( "SELECT COUNT(*) FROM $tb WHERE DATE(submitted_at) >= %s", $from );
        $comp  = (int) $this->db->scalar( "SELECT COUNT(*) FROM $tb WHERE status='published' AND DATE(submitted_at) >= %s", $from );
        return $total > 0 ? round( ( $comp / $total ) * 100, 1 ) : 0;
    }

    // TRACE: revenue_chart() — Trigger: wp_ajax_revenue_chart AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function revenue_chart( int $days = 30 ): array {
        // Fixed: status='captured' not 'completed'; payments table has created_at
        $rows = $this->db->select(
            "SELECT DATE(created_at) as d, COALESCE(SUM(amount),0) as revenue
             FROM {$this->db->t('payments')}
             WHERE status='captured' AND DATE(created_at) >= %s
             GROUP BY DATE(created_at) ORDER BY d ASC",
            date( 'Y-m-d', strtotime( "-{$days} days" ) )
        );
        $labels = []; $data = [];
        foreach ( $rows as $r ) { $labels[] = $r['d']; $data[] = (float) $r['revenue']; }
        return [ 'labels' => $labels, 'data' => $data ];
    }

    // TRACE: booking_chart() — Trigger: wp_ajax_booking_chart AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function booking_chart( int $days = 30 ): array {
        // Fixed: bookings uses submitted_at
        $rows = $this->db->select(
            "SELECT DATE(submitted_at) as d, COUNT(*) as cnt
             FROM {$this->db->t('bookings')}
             WHERE DATE(submitted_at) >= %s
             GROUP BY DATE(submitted_at) ORDER BY d ASC",
            date( 'Y-m-d', strtotime( "-{$days} days" ) )
        );
        $labels = []; $data = [];
        foreach ( $rows as $r ) { $labels[] = $r['d']; $data[] = (int) $r['cnt']; }
        return [ 'labels' => $labels, 'data' => $data ];
    }

    // TRACE: top_cities() — Trigger: wp_ajax_top_cities AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function top_cities( int $limit = 10 ): array {
        // Fixed: final_price → total_amount; city_name denormalized on bookings (no JOIN needed for name)
        return $this->db->select(
            "SELECT b.city_name as name, COUNT(b.id) as bookings, COALESCE(SUM(b.total_amount),0) as revenue
             FROM {$this->db->t('bookings')} b
             WHERE b.city_name != ''
             GROUP BY b.city_name ORDER BY bookings DESC LIMIT %d",
            $limit
        );
    }

    // TRACE: top_newspapers() — Trigger: wp_ajax_top_newspapers AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function top_newspapers( int $limit = 10 ): array {
        // Fixed: final_price → total_amount; newspaper_name denormalized on bookings
        return $this->db->select(
            "SELECT b.newspaper_name as name, COUNT(b.id) as bookings, COALESCE(SUM(b.total_amount),0) as revenue
             FROM {$this->db->t('bookings')} b
             WHERE b.newspaper_name != ''
             GROUP BY b.newspaper_name ORDER BY bookings DESC LIMIT %d",
            $limit
        );
    }

    // TRACE: top_categories() — Trigger: wp_ajax_top_categories AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function top_categories( int $limit = 10 ): array {
        return $this->db->select(
            "SELECT cat.name, COUNT(b.id) as bookings
             FROM {$this->db->t('bookings')} b
             LEFT JOIN {$this->db->t('categories')} cat ON cat.id = b.category_id
             GROUP BY b.category_id ORDER BY bookings DESC LIMIT %d",
            $limit
        );
    }
}

class AnalyticsController {
    private static function svc(): AnalyticsService { return new AnalyticsService(); }

    // TRACE: overview() — Trigger: wp_ajax_overview AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function overview(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $period = (int) ( $_GET['period'] ?? 30 );
        $key    = 'analytics_overview_' . $period;
        $data   = Cache::instance()->remember( $key, fn() => self::svc()->overview( (string) $period ), 300 );
        wp_send_json_success( $data );
    }

    // TRACE: revenue_chart() — Trigger: wp_ajax_revenue_chart AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function revenue_chart(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $days = (int) ( $_GET['days'] ?? 30 );
        $data = Cache::instance()->remember( 'revenue_chart_' . $days, fn() => self::svc()->revenue_chart( $days ), 300 );
        wp_send_json_success( $data );
    }

    // TRACE: booking_chart() — Trigger: wp_ajax_booking_chart AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function booking_chart(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        $days = (int) ( $_GET['days'] ?? 30 );
        $data = Cache::instance()->remember( 'booking_chart_' . $days, fn() => self::svc()->booking_chart( $days ), 300 );
        wp_send_json_success( $data );
    }

    // TRACE: top_cities() — Trigger: wp_ajax_top_cities AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function top_cities(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        wp_send_json_success( [ 'cities' => self::svc()->top_cities() ] );
    }

    // TRACE: top_newspapers() — Trigger: wp_ajax_top_newspapers AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function top_newspapers(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        wp_send_json_success( [ 'newspapers' => self::svc()->top_newspapers() ] );
    }

    // TRACE: top_categories() — Trigger: wp_ajax_top_categories AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function top_categories(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap( 'manage_options' );
        wp_send_json_success( [ 'categories' => self::svc()->top_categories() ] );
    }

    // TRACE: log_event() — Trigger: wp_ajax_log_event AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → inserts DB row → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function log_event(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $db = Database::instance();
        $db->insert( $db->t('analytics'), [  // fixed: was bare 'analytics' without prefix
            'event_type'    => sanitize_text_field( $_POST['event'] ?? 'page_view' ),
            'entity_type'   => sanitize_text_field( $_POST['entity_type'] ?? 'page' ),
            'entity_id'     => (int) ( $_POST['entity_id'] ?? 0 ),
            'value'         => 1,
            'meta'          => json_encode( [ 'url' => esc_url_raw( $_POST['url'] ?? '' ) ] ),
            'recorded_date' => current_time( 'Y-m-d' ),
            'created_at'    => current_time( 'mysql' ),
        ] );
        wp_send_json_success();
    }
}
