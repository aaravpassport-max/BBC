<?php
/**
 * Pricing and quote service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Pricing_Service {

	public static function get_vehicle_categories( $booking_type = 'parcel' ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'vehicle_categories' );

		$rows = $wpdb->get_results( "SELECT * FROM $table WHERE status = 'active'", ARRAY_A );
		$out  = array();

		foreach ( $rows as $row ) {
			$types = array_map( 'trim', explode( ',', $row['booking_types'] ?? 'parcel' ) );
			if ( ! in_array( $booking_type, $types, true ) ) {
				continue;
			}
			$out[] = array(
				'id'                  => $row['id'],
				'name'                => $row['name'],
				'capacity_descriptor' => $row['capacity_descriptor'],
				'booking_types'       => $types,
			);
		}
		return $out;
	}

	public static function create_quote( $user_id, $params ) {
		global $wpdb;

		$pickup = $params['pickup'];
		$drops  = $params['drops'];
		$booking_type = $params['booking_type'] ?? 'parcel';
		$vehicle = $params['vehicle_category'] ?? null;

		$categories = self::get_vehicle_categories( $booking_type );
		if ( empty( $categories ) ) {
			return Portmystuff_Utils::error( 'NO_VEHICLES', 'No vehicles available for this booking type.', 400 );
		}

		$quotes = array();
		foreach ( $categories as $cat ) {
			if ( $vehicle && $cat['name'] !== $vehicle && $cat['id'] !== $vehicle ) {
				continue;
			}
			$fare = self::calculate_fare( $cat['id'], $pickup, $drops );
			$id   = Portmystuff_Utils::uuid();
			$wpdb->insert(
				Portmystuff_Database::table( 'quotes' ),
				array(
					'id'                  => $id,
					'user_id'             => $user_id,
					'vehicle_category_id' => $cat['id'],
					'booking_type'        => $booking_type,
					'pickup_lat'          => $pickup['lat'],
					'pickup_lng'          => $pickup['lng'],
					'fare_breakdown'      => wp_json_encode( $fare ),
					'drops_snapshot'      => wp_json_encode( $drops ),
					'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + 900 ),
				),
				array( '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s' )
			);

			$quotes[] = array(
				'quote_id'          => $id,
				'vehicle_category'  => $cat['name'],
				'expires_at'        => gmdate( 'c', time() + 900 ),
				'surge_multiplier'  => 1,
				'fare_breakdown'    => $fare,
			);
		}

		return array( 'quotes' => $quotes );
	}

	public static function calculate_fare( $category_id, $pickup, $drops ) {
		global $wpdb;
		$rate = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Portmystuff_Database::table( 'rate_cards' ) . ' WHERE vehicle_category_id = %s AND status = %s LIMIT 1',
				$category_id,
				'published'
			),
			ARRAY_A
		);

		if ( ! $rate ) {
			$rate = array( 'base_fare' => 60, 'per_km' => 15, 'per_min' => 1.5, 'min_fare' => 49, 'platform_fee_pct' => 10, 'tax_pct' => 5 );
		}

		$distance = 0;
		$prev     = $pickup;
		foreach ( $drops as $drop ) {
			$distance += Portmystuff_Utils::distance_km( $prev['lat'], $prev['lng'], $drop['lat'], $drop['lng'] );
			$prev = $drop;
		}
		$distance = max( 1, round( $distance, 1 ) );
		$eta_min  = max( 5, (int) round( $distance * 3 ) );

		$base      = (float) $rate['base_fare'];
		$distance_charge = round( $distance * (float) $rate['per_km'], 2 );
		$time_charge     = round( $eta_min * (float) $rate['per_min'], 2 );
		$subtotal        = max( (float) $rate['min_fare'], $base + $distance_charge + $time_charge );
		$platform_fee    = round( $subtotal * ( (float) $rate['platform_fee_pct'] / 100 ), 2 );
		$tax             = round( ( $subtotal + $platform_fee ) * ( (float) $rate['tax_pct'] / 100 ), 2 );
		$final           = round( $subtotal + $platform_fee + $tax, 2 );

		return array(
			'base_fare'           => $base,
			'distance_charge'     => $distance_charge,
			'time_charge'         => $time_charge,
			'waiting_charge'      => 0,
			'toll_pass_through'   => 0,
			'night_surcharge'     => 0,
			'surge_multiplier'    => 1,
			'platform_fee'        => $platform_fee,
			'tax'                 => $tax,
			'coupon_discount'     => 0,
			'subscription_benefit'=> 0,
			'loyalty_discount'    => 0,
			'final_fare'          => $final,
		);
	}

	public static function get_quote( $quote_id, $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT q.*, vc.name AS category_name FROM ' . Portmystuff_Database::table( 'quotes' ) . ' q
				LEFT JOIN ' . Portmystuff_Database::table( 'vehicle_categories' ) . ' vc ON vc.id = q.vehicle_category_id
				WHERE q.id = %s AND q.user_id = %d',
				$quote_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $row || strtotime( $row['expires_at'] ) < time() ) {
			return null;
		}
		return $row;
	}
}
