<?php
/**
 * Driver partner service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Driver_Service {

	public static function get_profile( $user_id ) {
		global $wpdb;
		$user = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Portmystuff_Database::table( 'users' ) . ' WHERE id = %d', $user_id ),
			ARRAY_A
		);
		$profile = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Portmystuff_Database::table( 'driver_profiles' ) . ' WHERE user_id = %d', $user_id ),
			ARRAY_A
		);
		if ( ! $user ) {
			return null;
		}
		return array(
			'id'              => (string) $user['id'],
			'name'            => $user['name'],
			'phone'           => $user['phone'],
			'email'           => $user['email'],
			'kyc_status'      => $profile['kyc_status'] ?? 'pending',
			'training_status' => $profile['training_status'] ?? 'not_started',
			'online_status'   => (bool) ( $profile['online_status'] ?? false ),
			'rating_avg'      => $profile['rating_avg'] ? (float) $profile['rating_avg'] : null,
			'rating_count'    => (int) ( $profile['rating_count'] ?? 0 ),
			'vehicle'         => $profile['vehicle_plate'] ? array(
				'plate'    => $profile['vehicle_plate'],
				'category' => $profile['vehicle_category'],
				'make'     => null,
				'model'    => null,
			) : null,
		);
	}

	public static function set_online( $user_id, $online ) {
		global $wpdb;
		$wpdb->update(
			Portmystuff_Database::table( 'driver_profiles' ),
			array( 'online_status' => $online ? 1 : 0 ),
			array( 'user_id' => $user_id ),
			array( '%d' ),
			array( '%d' )
		);
		return array( 'online' => (bool) $online );
	}

	public static function update_location( $user_id, $lat, $lng ) {
		global $wpdb;
		$wpdb->update(
			Portmystuff_Database::table( 'driver_profiles' ),
			array(
				'last_lat'     => $lat,
				'last_lng'     => $lng,
				'last_ping_at' => current_time( 'mysql', true ),
			),
			array( 'user_id' => $user_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);
		return array( 'acknowledged' => true );
	}

	public static function get_dashboard( $user_id ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'bookings' );
		$today = gmdate( 'Y-m-d' );
		$trips = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE driver_id = %d AND status = 'completed' AND DATE(created_at) = %s",
				$user_id,
				$today
			)
		);
		$wallet = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Portmystuff_Database::table( 'wallets' ) . ' WHERE owner_id = %d AND owner_type = %s',
				$user_id,
				'driver'
			),
			ARRAY_A
		);
		return array(
			'trips_today'           => $trips,
			'active_trips'          => 0,
			'gross_earnings_today'  => $trips * 150,
			'wallet_credits_today'  => $trips * 150,
			'rating_avg'            => 4.8,
			'rating_count'          => 42,
			'online_status'         => false,
		);
	}

	public static function get_pending_offer( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.*, b.booking_type, b.passenger_count, b.pickup_lat, b.pickup_lng, b.pickup_address, b.fare_breakdown, b.vehicle_category_id
				FROM " . Portmystuff_Database::table( 'dispatch_offers' ) . " o
				JOIN " . Portmystuff_Database::table( 'bookings' ) . " b ON b.id = o.booking_id
				WHERE o.driver_id = %d AND o.status = 'offered' AND o.expires_at > NOW()
				ORDER BY o.offered_at DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$fare = json_decode( $row['fare_breakdown'], true );
		return array(
			'offer_id'                => $row['id'],
			'booking_id'              => $row['booking_id'],
			'expires_at'              => gmdate( 'c', strtotime( $row['expires_at'] ) ),
			'booking_type'            => $row['booking_type'],
			'passenger_count'         => (int) $row['passenger_count'],
			'fare_breakdown'          => $fare,
			'pickup_lat'              => (float) $row['pickup_lat'],
			'pickup_lng'              => (float) $row['pickup_lng'],
			'pickup_address_snapshot' => json_decode( $row['pickup_address'], true ),
			'vehicle_category_id'     => $row['vehicle_category_id'],
		);
	}

	public static function list_drivers_admin() {
		global $wpdb;
		$users   = Portmystuff_Database::table( 'users' );
		$profile = Portmystuff_Database::table( 'driver_profiles' );
		return $wpdb->get_results(
			"SELECT u.id, u.phone, u.name, u.status, p.kyc_status, p.training_status, p.online_status, p.rating_avg
			FROM $users u
			JOIN $profile p ON p.user_id = u.id
			WHERE u.account_type = 'driver'
			ORDER BY u.created_at DESC LIMIT 100",
			ARRAY_A
		);
	}

	public static function accept_offer( $user_id, $offer_id ) {
		global $wpdb;
		$offers_table = Portmystuff_Database::table( 'dispatch_offers' );
		$offer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $offers_table WHERE id = %s AND driver_id = %d AND status = 'offered' AND expires_at > NOW()",
				$offer_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $offer ) {
			return Portmystuff_Utils::error( 'OFFER_EXPIRED', 'This offer is no longer available.', 400 );
		}

		$wpdb->update(
			$offers_table,
			array( 'status' => 'accepted', 'responded_at' => current_time( 'mysql', true ) ),
			array( 'id' => $offer_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		$wpdb->update(
			Portmystuff_Database::table( 'bookings' ),
			array(
				'driver_id' => $user_id,
				'status'    => 'driver_assigned',
			),
			array( 'id' => $offer['booking_id'] ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $offers_table SET status = 'expired', responded_at = NOW()
				WHERE booking_id = %s AND id != %s AND status = 'offered'",
				$offer['booking_id'],
				$offer_id
			)
		);

		return Portmystuff_Booking_Service::format_booking( Portmystuff_Booking_Service::get_by_id( $offer['booking_id'] ) );
	}

	public static function reject_offer( $user_id, $offer_id ) {
		global $wpdb;
		$offers_table = Portmystuff_Database::table( 'dispatch_offers' );
		$offer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $offers_table WHERE id = %s AND driver_id = %d AND status = 'offered'",
				$offer_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $offer ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Offer not found.', 404 );
		}

		$wpdb->update(
			$offers_table,
			array( 'status' => 'rejected', 'responded_at' => current_time( 'mysql', true ) ),
			array( 'id' => $offer_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		Portmystuff_Dispatch_Service::run_cycle( $offer['booking_id'] );

		return array( 'rejected' => true );
	}

	public static function get_active_job( $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Portmystuff_Database::table( 'bookings' ) . "
				WHERE driver_id = %d AND status IN ('driver_assigned','driver_arriving','driver_arrived','in_progress')
				ORDER BY updated_at DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		return $row ? Portmystuff_Booking_Service::format_booking( $row ) : null;
	}

	public static function advance_job_status( $user_id, $booking_id, $new_status ) {
		global $wpdb;
		$row = Portmystuff_Booking_Service::get_by_id( $booking_id );
		if ( ! $row || (int) $row['driver_id'] !== (int) $user_id ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Active job not found.', 404 );
		}

		$allowed = array(
			'driver_assigned'  => array( 'driver_arriving' ),
			'driver_arriving'  => array( 'driver_arrived' ),
			'driver_arrived'   => array( 'in_progress' ),
			'in_progress'      => array( 'completed' ),
		);
		$current = $row['status'];
		if ( empty( $allowed[ $current ] ) || ! in_array( $new_status, $allowed[ $current ], true ) ) {
			return Portmystuff_Utils::error( 'INVALID_TRANSITION', "Cannot move from $current to $new_status.", 400 );
		}

		$wpdb->update(
			Portmystuff_Database::table( 'bookings' ),
			array( 'status' => $new_status ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%s' )
		);

		if ( 'completed' === $new_status ) {
			$fare = json_decode( $row['fare_breakdown'], true );
			$amount = (float) ( $fare['final_fare'] ?? 150 );
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Portmystuff_Database::table( 'wallets' ) . ' SET real_balance = real_balance + %f WHERE owner_id = %d AND owner_type = %s',
					$amount * 0.8,
					$user_id,
					'driver'
				)
			);
		}

		return Portmystuff_Booking_Service::format_booking( Portmystuff_Booking_Service::get_by_id( $booking_id ) );
	}
}
