<?php
/**
 * WordPress REST API — mirrors the Node.js logistics API under portmystuff/v1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Rest_Api {

	public static function register_routes() {
		$ns = 'portmystuff/v1';

		// Auth
		register_rest_route( $ns, '/auth/otp/request', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'otp_request' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/auth/otp/verify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'otp_verify' ),
			'permission_callback' => '__return_true',
		) );

		// Pricing
		register_rest_route( $ns, '/pricing/vehicle-categories', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'vehicle_categories' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/pricing/quote', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_quote' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// Bookings
		register_rest_route( $ns, '/bookings', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_booking' ),
				'permission_callback' => array( __CLASS__, 'auth_required' ),
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_bookings' ),
				'permission_callback' => array( __CLASS__, 'auth_required' ),
			),
		) );
		register_rest_route( $ns, '/bookings/(?P<id>[a-f0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_booking' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/bookings/(?P<id>[a-f0-9\-]+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'cancel_booking' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// Driver
		register_rest_route( $ns, '/driver/status', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'driver_status' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/location', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'driver_location' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/profile', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'driver_profile' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/dashboard', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'driver_dashboard' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/jobs/pending-offer', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'driver_pending_offer' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/jobs/active', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'driver_active_job' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/jobs/offers/(?P<offer_id>[a-f0-9\-]+)/accept', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'driver_accept_offer' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/jobs/offers/(?P<offer_id>[a-f0-9\-]+)/reject', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'driver_reject_offer' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );
		register_rest_route( $ns, '/driver/jobs/(?P<id>[a-f0-9\-]+)/status', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'driver_job_status' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// SOS
		register_rest_route( $ns, '/bookings/(?P<id>[a-f0-9\-]+)/sos', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'trigger_sos' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// Wallet
		register_rest_route( $ns, '/wallet', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'wallet_balance' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// Profile
		register_rest_route( $ns, '/profile', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'user_profile' ),
			'permission_callback' => array( __CLASS__, 'auth_required' ),
		) );

		// Geo stubs
		register_rest_route( $ns, '/geo/serviceability', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'geo_serviceability' ),
			'permission_callback' => '__return_true',
		) );

		// Admin namespace
		register_rest_route( 'portmystuff/admin/v1', '/drivers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_drivers' ),
			'permission_callback' => array( __CLASS__, 'admin_required' ),
		) );
		register_rest_route( 'portmystuff/admin/v1', '/bookings', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_bookings' ),
			'permission_callback' => array( __CLASS__, 'admin_required' ),
		) );
		register_rest_route( 'portmystuff/admin/v1', '/analytics/revenue', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_revenue' ),
			'permission_callback' => array( __CLASS__, 'admin_required' ),
		) );

		// Ops namespace
		register_rest_route( 'portmystuff/ops/v1', '/sos/queue', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'ops_sos_queue' ),
			'permission_callback' => array( __CLASS__, 'ops_required' ),
		) );
		register_rest_route( 'portmystuff/ops/v1', '/bookings/(?P<id>[a-f0-9\-]+)/dispatch-log', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'ops_dispatch_log' ),
			'permission_callback' => array( __CLASS__, 'ops_required' ),
		) );
		register_rest_route( 'portmystuff/ops/v1', '/live-map/drivers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'ops_live_drivers' ),
			'permission_callback' => array( __CLASS__, 'ops_required' ),
		) );
	}

	public static function auth_required( WP_REST_Request $request ) {
		return (bool) Portmystuff_Auth_Service::get_current_user_id( $request );
	}

	public static function admin_required() {
		return Portmystuff_Roles::user_can( 'pms_analytics_view' );
	}

	public static function ops_required() {
		return Portmystuff_Roles::user_can( 'pms_ops_sos_respond' );
	}

	private static function user_id( WP_REST_Request $request ) {
		return Portmystuff_Auth_Service::get_current_user_id( $request );
	}

	public static function otp_request( WP_REST_Request $request ) {
		$phone = sanitize_text_field( $request->get_param( 'phone' ) );
		if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
			return Portmystuff_Utils::error( 'INVALID_PHONE', 'Enter a valid 10-digit phone number.' );
		}
		return Portmystuff_Utils::json_response( Portmystuff_Auth_Service::request_otp( $phone ), 202 );
	}

	public static function otp_verify( WP_REST_Request $request ) {
		$result = Portmystuff_Auth_Service::verify_otp(
			sanitize_text_field( $request->get_param( 'otp_id' ) ),
			sanitize_text_field( $request->get_param( 'code' ) ),
			sanitize_text_field( $request->get_param( 'device_id' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function vehicle_categories( WP_REST_Request $request ) {
		$type = $request->get_param( 'booking_type' ) === 'ride' ? 'ride' : 'parcel';
		return Portmystuff_Utils::json_response( Portmystuff_Pricing_Service::get_vehicle_categories( $type ) );
	}

	public static function create_quote( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$result = Portmystuff_Pricing_Service::create_quote(
			self::user_id( $request ),
			array(
				'pickup'           => $body['pickup'],
				'drops'            => $body['drops'] ?? array(),
				'booking_type'     => $body['booking_type'] ?? 'parcel',
				'vehicle_category' => $body['vehicle_category'] ?? null,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function create_booking( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$key  = $request->get_header( 'idempotency-key' );
		$result = Portmystuff_Booking_Service::create_from_quote(
			self::user_id( $request ),
			sanitize_text_field( $body['quote_id'] ),
			sanitize_text_field( $body['payment_method'] ?? 'upi' ),
			(int) ( $body['passenger_count'] ?? 1 ),
			$key ? sanitize_text_field( $key ) : ''
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result, 201 );
	}

	public static function list_bookings( WP_REST_Request $request ) {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$size = min( 50, max( 1, (int) ( $request->get_param( 'page_size' ) ?: 10 ) ) );
		$items = Portmystuff_Booking_Service::list_for_customer( self::user_id( $request ), $page, $size, $request->get_param( 'status' ) );
		return Portmystuff_Utils::json_response( array( 'items' => $items, 'page' => $page ) );
	}

	public static function get_booking( WP_REST_Request $request ) {
		$row = Portmystuff_Booking_Service::get_by_id( $request['id'] );
		if ( ! $row || (int) $row['customer_id'] !== self::user_id( $request ) ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Booking not found.', 404 );
		}
		return Portmystuff_Utils::json_response( Portmystuff_Booking_Service::format_booking( $row ) );
	}

	public static function cancel_booking( WP_REST_Request $request ) {
		$result = Portmystuff_Booking_Service::cancel( $request['id'], self::user_id( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function driver_status( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return Portmystuff_Utils::json_response(
			Portmystuff_Driver_Service::set_online( self::user_id( $request ), ! empty( $body['online'] ) )
		);
	}

	public static function driver_location( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return Portmystuff_Utils::json_response(
			Portmystuff_Driver_Service::update_location( self::user_id( $request ), (float) $body['lat'], (float) $body['lng'] )
		);
	}

	public static function driver_profile( WP_REST_Request $request ) {
		return Portmystuff_Utils::json_response( Portmystuff_Driver_Service::get_profile( self::user_id( $request ) ) );
	}

	public static function driver_dashboard( WP_REST_Request $request ) {
		return Portmystuff_Utils::json_response( Portmystuff_Driver_Service::get_dashboard( self::user_id( $request ) ) );
	}

	public static function driver_pending_offer( WP_REST_Request $request ) {
		$offer = Portmystuff_Driver_Service::get_pending_offer( self::user_id( $request ) );
		return Portmystuff_Utils::json_response( $offer );
	}

	public static function driver_active_job( WP_REST_Request $request ) {
		$job = Portmystuff_Driver_Service::get_active_job( self::user_id( $request ) );
		return Portmystuff_Utils::json_response( $job );
	}

	public static function driver_accept_offer( WP_REST_Request $request ) {
		$result = Portmystuff_Driver_Service::accept_offer( self::user_id( $request ), $request['offer_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function driver_reject_offer( WP_REST_Request $request ) {
		$result = Portmystuff_Driver_Service::reject_offer( self::user_id( $request ), $request['offer_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function driver_job_status( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$result = Portmystuff_Driver_Service::advance_job_status(
			self::user_id( $request ),
			$request['id'],
			sanitize_text_field( $body['status'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return Portmystuff_Utils::json_response( $result );
	}

	public static function trigger_sos( WP_REST_Request $request ) {
		global $wpdb;
		$booking = Portmystuff_Booking_Service::get_by_id( $request['id'] );
		$user_id = self::user_id( $request );
		if ( ! $booking || (int) $booking['customer_id'] !== $user_id && (int) $booking['driver_id'] !== $user_id ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Booking not found.', 404 );
		}
		$body = $request->get_json_params();
		$id   = Portmystuff_Utils::uuid();
		$wpdb->insert(
			Portmystuff_Database::table( 'sos_events' ),
			array(
				'id'                => $id,
				'booking_id'        => $request['id'],
				'triggered_by_role' => (int) $booking['driver_id'] === $user_id ? 'driver' : 'customer',
				'trigger_lat'       => $body['lat'] ?? null,
				'trigger_lng'       => $body['lng'] ?? null,
				'status'            => 'open',
			),
			array( '%s', '%s', '%s', '%f', '%f', '%s' )
		);
		return Portmystuff_Utils::json_response( array( 'sos_id' => $id, 'status' => 'open' ), 201 );
	}

	public static function wallet_balance( WP_REST_Request $request ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Portmystuff_Database::table( 'wallets' ) . ' WHERE owner_id = %d AND owner_type = %s',
				self::user_id( $request ),
				'customer'
			),
			ARRAY_A
		);
		return Portmystuff_Utils::json_response(
			array(
				'real_money_balance'       => (float) ( $row['real_balance'] ?? 0 ),
				'promotional_credit_balance'=> (float) ( $row['promo_balance'] ?? 0 ),
			)
		);
	}

	public static function user_profile( WP_REST_Request $request ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Portmystuff_Database::table( 'users' ) . ' WHERE id = %d', self::user_id( $request ) ),
			ARRAY_A
		);
		return Portmystuff_Utils::json_response(
			array(
				'id'    => (string) $row['id'],
				'name'  => $row['name'],
				'phone' => $row['phone'],
				'email' => $row['email'],
				'gstin' => $row['gstin'],
			)
		);
	}

	public static function geo_serviceability( WP_REST_Request $request ) {
		return Portmystuff_Utils::json_response(
			array(
				'serviceable' => true,
				'city'        => 'Bengaluru',
				'zone'        => 'central',
			)
		);
	}

	public static function admin_drivers() {
		return Portmystuff_Utils::json_response( array( 'items' => Portmystuff_Driver_Service::list_drivers_admin() ) );
	}

	public static function admin_bookings( WP_REST_Request $request ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'bookings' );
		$status = $request->get_param( 'status' );
		$limit  = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		if ( $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY created_at DESC LIMIT %d", $status, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A );
		}
		return Portmystuff_Utils::json_response(
			array( 'items' => array_map( array( 'Portmystuff_Booking_Service', 'format_booking' ), $rows ) )
		);
	}

	public static function admin_revenue() {
		global $wpdb;
		$table = Portmystuff_Database::table( 'bookings' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'completed'" );
		return Portmystuff_Utils::json_response(
			array(
				'total_trips'    => $total,
				'gross_revenue'  => $total * 180,
				'platform_fees'  => $total * 18,
				'period'         => 'all_time',
			)
		);
	}

	public static function ops_sos_queue() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . Portmystuff_Database::table( 'sos_events' ) . " WHERE status IN ('open','acknowledged') ORDER BY created_at DESC LIMIT 50",
			ARRAY_A
		);
		return Portmystuff_Utils::json_response( $rows );
	}

	public static function ops_dispatch_log( WP_REST_Request $request ) {
		$log = Portmystuff_Dispatch_Service::get_dispatch_log( $request['id'] );
		if ( ! $log ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Booking not found.', 404 );
		}
		return Portmystuff_Utils::json_response( $log );
	}

	public static function ops_live_drivers() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT user_id AS driver_id, last_lat AS lat, last_lng AS lng, online_status, last_ping_at
			FROM ' . Portmystuff_Database::table( 'driver_profiles' ) . ' WHERE online_status = 1',
			ARRAY_A
		);
		return Portmystuff_Utils::json_response( $rows );
	}
}
