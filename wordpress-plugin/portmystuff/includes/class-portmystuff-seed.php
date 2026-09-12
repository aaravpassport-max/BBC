<?php
/**
 * Demo seed data on activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Seed {

	public static function run() {
		global $wpdb;

		$categories = Portmystuff_Database::table( 'vehicle_categories' );
		$rate_cards = Portmystuff_Database::table( 'rate_cards' );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $categories" );
		if ( $count > 0 ) {
			return;
		}

		$cats = array(
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'two_wheeler', 'booking_types' => 'parcel' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'three_wheeler', 'booking_types' => 'parcel' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'mini_truck', 'booking_types' => 'parcel' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'auto', 'booking_types' => 'ride' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'hatchback', 'booking_types' => 'ride' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'sedan', 'booking_types' => 'ride' ),
			array( 'id' => Portmystuff_Utils::uuid(), 'name' => 'suv', 'booking_types' => 'ride' ),
		);

		foreach ( $cats as $cat ) {
			$wpdb->insert(
				$categories,
				array(
					'id'             => $cat['id'],
					'name'           => $cat['name'],
					'booking_types'  => $cat['booking_types'],
					'status'         => 'active',
				),
				array( '%s', '%s', '%s', '%s' )
			);

			$wpdb->insert(
				$rate_cards,
				array(
					'id'                  => Portmystuff_Utils::uuid(),
					'city_name'           => 'Bengaluru',
					'vehicle_category_id' => $cat['id'],
					'base_fare'           => in_array( $cat['name'], array( 'auto', 'hatchback', 'sedan', 'suv' ), true ) ? 40 : 60,
					'per_km'              => in_array( $cat['name'], array( 'auto' ), true ) ? 12 : 15,
					'per_min'             => 1.5,
					'min_fare'            => 49,
					'status'              => 'published',
				),
				array( '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s' )
			);
		}

		// Demo users table rows (linked to WP users if created later).
		$users_table = Portmystuff_Database::table( 'users' );
		$demos       = array(
			array( 'phone' => '9000000001', 'name' => 'Demo Customer', 'account_type' => 'customer' ),
			array( 'phone' => '9000000002', 'name' => 'Demo Driver', 'account_type' => 'driver' ),
		);

		foreach ( $demos as $demo ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $users_table WHERE phone = %s", $demo['phone'] ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$users_table,
					array(
						'phone'        => $demo['phone'],
						'name'         => $demo['name'],
						'account_type' => $demo['account_type'],
						'referral_code'=> strtoupper( substr( $demo['account_type'], 0, 3 ) ) . '01',
					),
					array( '%s', '%s', '%s', '%s' )
				);
				$user_id = (int) $wpdb->insert_id;

				$wpdb->insert(
					Portmystuff_Database::table( 'wallets' ),
					array(
						'owner_id'     => $user_id,
						'owner_type'   => $demo['account_type'],
						'real_balance' => 500,
					),
					array( '%d', '%s', '%f' )
				);

				if ( 'driver' === $demo['account_type'] ) {
					$wpdb->insert(
						Portmystuff_Database::table( 'driver_profiles' ),
						array(
							'user_id'          => $user_id,
							'kyc_status'       => 'approved',
							'training_status'  => 'passed',
							'vehicle_plate'    => 'KA01DE1234',
							'vehicle_category' => 'mini_truck',
							'rating_avg'       => 4.8,
							'rating_count'     => 42,
						),
						array( '%d', '%s', '%s', '%s', '%s', '%f', '%d' )
					);
				}
			}
		}
	}
}
