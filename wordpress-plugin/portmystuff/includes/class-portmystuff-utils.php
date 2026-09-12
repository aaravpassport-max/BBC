<?php
/**
 * Shared helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Utils {

	public static function uuid() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}

	public static function json_response( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	public static function error( $code, $message, $status = 400, $details = array() ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'  => $status,
				'details' => $details,
			)
		);
	}

	public static function api_error_shape( WP_Error $error ) {
		return array(
			'error' => array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'details' => $error->get_error_data(),
			),
		);
	}

	public static function distance_km( $lat1, $lng1, $lat2, $lng2 ) {
		$earth = 6371;
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );
		$a     = sin( $d_lat / 2 ) * sin( $d_lat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $d_lng / 2 ) * sin( $d_lng / 2 );
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $earth * $c;
	}

	public static function generate_otp() {
		return str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	public static function demo_otp_for_phone( $phone ) {
		$map = array(
			'9000000001' => '111111',
			'9000000002' => '222222',
		);
		return $map[ $phone ] ?? null;
	}

	public static function pickup_otp() {
		return str_pad( (string) wp_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
	}
}
