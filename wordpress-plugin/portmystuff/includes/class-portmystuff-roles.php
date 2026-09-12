<?php
/**
 * WordPress roles and capabilities for admin / ops surfaces.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Roles {

	public static function install() {
		add_role(
			'pms_ops_admin',
			__( 'PORTMYSTUFF Ops Admin', 'portmystuff' ),
			self::caps()
		);

		add_role(
			'pms_control_room',
			__( 'PORTMYSTUFF Control Room', 'portmystuff' ),
			array(
				'read'                   => true,
				'pms_ops_sos_respond'    => true,
				'pms_ops_dispatch'       => true,
				'pms_analytics_view'     => true,
			)
		);

		add_role(
			'pms_driver',
			__( 'PORTMYSTUFF Driver', 'portmystuff' ),
			array( 'read' => true, 'pms_driver_app' => true )
		);

		add_role(
			'pms_customer',
			__( 'PORTMYSTUFF Customer', 'portmystuff' ),
			array( 'read' => true, 'pms_customer_app' => true )
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::caps() as $cap => $grant ) {
				$admin->add_cap( $cap, $grant );
			}
		}
	}

	public static function caps() {
		return array(
			'read'                      => true,
			'pms_pricing_edit'          => true,
			'pms_driver_suspend'        => true,
			'pms_driver_kyc_review'     => true,
			'pms_fraud_review'          => true,
			'pms_support_manage'        => true,
			'pms_analytics_view'        => true,
			'pms_rbac_manage'           => true,
			'pms_marketing_cms'         => true,
			'pms_ops_sos_respond'       => true,
			'pms_ops_dispatch'          => true,
			'pms_finance_review'        => true,
			'pms_finance_approve'       => true,
		);
	}

	public static function user_can( $cap ) {
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}
}
