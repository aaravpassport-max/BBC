<?php
/**
 * Driver dispatch service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Dispatch_Service {

	public static function run_cycle( $booking_id ) {
		global $wpdb;

		$booking = Portmystuff_Booking_Service::get_by_id( $booking_id );
		if ( ! $booking || 'searching' !== $booking['status'] ) {
			return;
		}

		$drivers_table = Portmystuff_Database::table( 'driver_profiles' );
		$driver        = $wpdb->get_row(
			"SELECT user_id, last_lat, last_lng FROM $drivers_table
			 WHERE online_status = 1 AND kyc_status = 'approved' AND training_status = 'passed'
			 ORDER BY last_ping_at DESC LIMIT 1",
			ARRAY_A
		);

		if ( ! $driver ) {
			$wpdb->update(
				Portmystuff_Database::table( 'bookings' ),
				array( 'status' => 'no_drivers_found' ),
				array( 'id' => $booking_id ),
				array( '%s' ),
				array( '%s' )
			);
			return;
		}

		$offer_id = Portmystuff_Utils::uuid();
		$wpdb->insert(
			Portmystuff_Database::table( 'dispatch_offers' ),
			array(
				'id'         => $offer_id,
				'booking_id' => $booking_id,
				'driver_id'  => $driver['user_id'],
				'status'     => 'offered',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function get_dispatch_log( $booking_id ) {
		global $wpdb;
		$booking = Portmystuff_Booking_Service::get_by_id( $booking_id );
		if ( ! $booking ) {
			return null;
		}
		$offers = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Portmystuff_Database::table( 'dispatch_offers' ) . ' WHERE booking_id = %s ORDER BY offered_at ASC',
				$booking_id
			),
			ARRAY_A
		);
		return array(
			'booking' => array(
				'id'              => $booking['id'],
				'status'          => $booking['status'],
				'driver_id'       => $booking['driver_id'],
				'booking_type'    => $booking['booking_type'],
				'passenger_count' => (int) $booking['passenger_count'],
			),
			'offers'  => $offers,
		);
	}

	public static function expire_offers() {
		global $wpdb;
		$table = Portmystuff_Database::table( 'dispatch_offers' );
		$wpdb->query(
			"UPDATE $table SET status = 'expired', responded_at = NOW()
			 WHERE status = 'offered' AND expires_at < NOW()"
		);
	}
}
