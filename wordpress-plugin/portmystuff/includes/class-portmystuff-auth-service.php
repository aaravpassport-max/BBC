<?php
/**
 * OTP authentication service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Auth_Service {

	public static function request_otp( $phone, $country_code = '+91' ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'otp_requests' );

		$demo = Portmystuff_Utils::demo_otp_for_phone( $phone );
		$code = $demo ? $demo : Portmystuff_Utils::generate_otp();
		$id   = Portmystuff_Utils::uuid();

		$wpdb->insert(
			$table,
			array(
				'id'         => $id,
				'phone'      => $phone,
				'code_hash'  => wp_hash_password( $code ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[PORTMYSTUFF DEV] OTP for +{$country_code}{$phone}: {$code}" );
		}

		return array(
			'otp_id'                => $id,
			'expires_in_seconds'    => 600,
			'resend_after_seconds'  => 30,
		);
	}

	public static function verify_otp( $otp_id, $code, $device_id ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'otp_requests' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %s", $otp_id ), ARRAY_A );
		if ( ! $row || strtotime( $row['expires_at'] ) < time() ) {
			return Portmystuff_Utils::error( 'OTP_EXPIRED', 'This OTP has expired.', 400 );
		}

		if ( ! wp_check_password( $code, $row['code_hash'] ) ) {
			return Portmystuff_Utils::error( 'OTP_INVALID', 'Incorrect OTP.', 400 );
		}

		$user = self::find_or_create_user( $row['phone'] );
		$tokens = self::issue_tokens( $user['id'], $device_id );

		return array_merge(
			$tokens,
			array(
				'is_new_user' => ! empty( $user['is_new'] ),
				'user_id'     => (string) $user['id'],
			)
		);
	}

	private static function find_or_create_user( $phone ) {
		global $wpdb;
		$table = Portmystuff_Database::table( 'users' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE phone = %s", $phone ), ARRAY_A );
		if ( $row ) {
			return array( 'id' => (int) $row['id'], 'is_new' => false, 'account_type' => $row['account_type'] );
		}

		$account_type = ( '9000000002' === $phone ) ? 'driver' : 'customer';
		$wpdb->insert(
			$table,
			array(
				'phone'        => $phone,
				'account_type' => $account_type,
				'name'         => '9000000002' === $phone ? 'Demo Driver' : 'Demo Customer',
			),
			array( '%s', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		if ( 'driver' === $account_type ) {
			$wpdb->insert(
				Portmystuff_Database::table( 'driver_profiles' ),
				array(
					'user_id'         => $id,
					'kyc_status'      => '9000000002' === $phone ? 'approved' : 'pending',
					'training_status' => '9000000002' === $phone ? 'passed' : 'not_started',
					'vehicle_plate'   => '9000000002' === $phone ? 'KA01DE1234' : null,
					'vehicle_category'=> '9000000002' === $phone ? 'mini_truck' : null,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		$wpdb->insert(
			Portmystuff_Database::table( 'wallets' ),
			array( 'owner_id' => $id, 'owner_type' => $account_type, 'real_balance' => 500 ),
			array( '%d', '%s', '%f' )
		);

		return array( 'id' => $id, 'is_new' => true, 'account_type' => $account_type );
	}

	public static function issue_tokens( $user_id, $device_id ) {
		$access  = self::encode_jwt( $user_id );
		$refresh = wp_generate_password( 48, false );
		$id      = Portmystuff_Utils::uuid();

		global $wpdb;
		$wpdb->insert(
			Portmystuff_Database::table( 'sessions' ),
			array(
				'id'                  => $id,
				'user_id'             => $user_id,
				'refresh_token_hash'  => wp_hash_password( $refresh ),
				'device_id'           => $device_id,
				'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
		);
	}

	public static function encode_jwt( $user_id ) {
		$header  = base64_encode( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$payload = base64_encode(
			wp_json_encode(
				array(
					'sub' => (string) $user_id,
					'iat' => time(),
					'exp' => time() + DAY_IN_SECONDS,
				)
			)
		);
		$sig     = hash_hmac( 'sha256', "$header.$payload", wp_salt( 'auth' ), true );
		return "$header.$payload." . rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
	}

	public static function decode_jwt( $token ) {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		$payload = json_decode( base64_decode( $parts[1] ), true );
		if ( empty( $payload['sub'] ) || ( isset( $payload['exp'] ) && $payload['exp'] < time() ) ) {
			return null;
		}
		$sig = hash_hmac( 'sha256', "{$parts[0]}.{$parts[1]}", wp_salt( 'auth' ), true );
		$expected = rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return null;
		}
		return (int) $payload['sub'];
	}

	public static function get_current_user_id( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );
		if ( $header && preg_match( '/Bearer\s+(.+)/i', $header, $m ) ) {
			return self::decode_jwt( trim( $m[1] ) );
		}
		return null;
	}
}
