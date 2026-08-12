<?php
/**
 * Booking lifecycle service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Booking_Service {

	public static function create_from_quote( $user_id, $quote_id, $payment_method, $passenger_count = 1, $idempotency_key = '' ) {
		global $wpdb;

		$quote = Portmystuff_Pricing_Service::get_quote( $quote_id, $user_id );
		if ( ! $quote ) {
			return Portmystuff_Utils::error( 'QUOTE_EXPIRED', 'This price has expired.', 400 );
		}

		$idempotency_key = $idempotency_key ?: Portmystuff_Utils::uuid();
		$bookings_table  = Portmystuff_Database::table( 'bookings' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $bookings_table WHERE customer_id = %d AND idempotency_key = %s",
				$user_id,
				$idempotency_key
			)
		);
		if ( $existing ) {
			return self::format_booking( self::get_by_id( $existing ) );
		}

		$booking_id = Portmystuff_Utils::uuid();
		$fare       = json_decode( $quote['fare_breakdown'], true );

		$wpdb->insert(
			$bookings_table,
			array(
				'id'                  => $booking_id,
				'idempotency_key'     => $idempotency_key,
				'customer_id'         => $user_id,
				'booking_type'        => $quote['booking_type'],
				'passenger_count'     => $passenger_count,
				'status'              => 'searching',
				'vehicle_category_id' => $quote['vehicle_category_id'],
				'pickup_lat'          => $quote['pickup_lat'],
				'pickup_lng'          => $quote['pickup_lng'],
				'pickup_address'      => wp_json_encode( array( 'lat' => $quote['pickup_lat'], 'lng' => $quote['pickup_lng'] ) ),
				'pickup_otp'          => Portmystuff_Utils::pickup_otp(),
				'quote_id'            => $quote_id,
				'fare_breakdown'      => $quote['fare_breakdown'],
				'payment_method'      => $payment_method,
				'payment_status'      => 'upi' === $payment_method ? 'pending_collection' : 'paid',
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$drops = json_decode( $quote['drops_snapshot'] ?? '[]', true ) ?: array();
		foreach ( $drops as $i => $drop ) {
			$wpdb->insert(
				Portmystuff_Database::table( 'booking_stops' ),
				array(
					'id'                  => Portmystuff_Utils::uuid(),
					'booking_id'          => $booking_id,
					'sequence'            => $i + 1,
					'drop_lat'            => $drop['lat'],
					'drop_lng'            => $drop['lng'],
					'address_snapshot'    => wp_json_encode( $drop ),
					'delivery_preference' => $drop['delivery_preference'] ?? 'otp',
					'otp_code'            => Portmystuff_Utils::pickup_otp(),
				),
				array( '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s' )
			);
		}

		Portmystuff_Dispatch_Service::run_cycle( $booking_id );

		return self::format_booking( self::get_by_id( $booking_id ) );
	}

	public static function get_by_id( $booking_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Portmystuff_Database::table( 'bookings' ) . ' WHERE id = %s', $booking_id ),
			ARRAY_A
		);
	}

	public static function list_for_customer( $user_id, $page = 1, $page_size = 10, $status = null ) {
		global $wpdb;
		$table  = Portmystuff_Database::table( 'bookings' );
		$offset = ( $page - 1 ) * $page_size;

		if ( $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$user_id,
					$status,
					$page_size,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE customer_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$user_id,
					$page_size,
					$offset
				),
				ARRAY_A
			);
		}

		return array_map( array( __CLASS__, 'format_booking' ), $rows );
	}

	public static function format_booking( $row ) {
		if ( ! $row ) {
			return null;
		}
		global $wpdb;
		$stops = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Portmystuff_Database::table( 'booking_stops' ) . ' WHERE booking_id = %s ORDER BY sequence',
				$row['id']
			),
			ARRAY_A
		);

		$pickup = json_decode( $row['pickup_address'], true );

		return array(
			'id'                   => $row['id'],
			'status'               => $row['status'],
			'booking_type'         => $row['booking_type'],
			'passenger_count'      => (int) $row['passenger_count'],
			'vehicle_category_id'  => $row['vehicle_category_id'],
			'fare_breakdown'       => json_decode( $row['fare_breakdown'], true ),
			'driver_id'            => $row['driver_id'] ? (string) $row['driver_id'] : null,
			'created_at'           => $row['created_at'],
			'pickup_otp'           => $row['pickup_otp'],
			'pickup_lat'           => (float) $row['pickup_lat'],
			'pickup_lng'           => (float) $row['pickup_lng'],
			'pickup_address'       => $pickup,
			'stops'                => array_map(
				function ( $s ) {
					return array(
						'id'                  => $s['id'],
						'sequence'            => (int) $s['sequence'],
						'status'              => $s['status'],
						'otp_code'            => $s['otp_code'],
						'delivery_preference' => $s['delivery_preference'],
						'drop_lat'            => (float) $s['drop_lat'],
						'drop_lng'            => (float) $s['drop_lng'],
						'address_snapshot'    => json_decode( $s['address_snapshot'], true ),
					);
				},
				$stops
			),
		);
	}

	public static function cancel( $booking_id, $user_id ) {
		global $wpdb;
		$row = self::get_by_id( $booking_id );
		if ( ! $row || (int) $row['customer_id'] !== (int) $user_id ) {
			return Portmystuff_Utils::error( 'NOT_FOUND', 'Booking not found.', 404 );
		}
		$wpdb->update(
			Portmystuff_Database::table( 'bookings' ),
			array( 'status' => 'cancelled' ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%s' )
		);
		return self::format_booking( self::get_by_id( $booking_id ) );
	}
}
