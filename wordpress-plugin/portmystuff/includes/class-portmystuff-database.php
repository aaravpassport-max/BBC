<?php
/**
 * Custom database tables (MySQL) — standalone, no external Postgres required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Portmystuff_Database {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pms_' . $name;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE " . self::table( 'users' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NULL,
			phone VARCHAR(20) NOT NULL,
			country_code VARCHAR(5) NOT NULL DEFAULT '+91',
			name VARCHAR(100) NULL,
			email VARCHAR(100) NULL,
			account_type VARCHAR(20) NOT NULL DEFAULT 'customer',
			gstin VARCHAR(20) NULL,
			referral_code VARCHAR(20) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY wp_user_id (wp_user_id),
			KEY account_type (account_type)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'otp_requests' ) . " (
			id CHAR(36) NOT NULL,
			phone VARCHAR(20) NOT NULL,
			code_hash VARCHAR(255) NOT NULL,
			expires_at DATETIME NOT NULL,
			attempts SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY phone (phone)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'sessions' ) . " (
			id CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			refresh_token_hash VARCHAR(255) NOT NULL,
			device_id VARCHAR(100) NOT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'vehicle_categories' ) . " (
			id CHAR(36) NOT NULL,
			name VARCHAR(50) NOT NULL,
			capacity_descriptor VARCHAR(100) NULL,
			booking_types TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY name (name)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'rate_cards' ) . " (
			id CHAR(36) NOT NULL,
			city_name VARCHAR(100) NOT NULL DEFAULT 'Bengaluru',
			vehicle_category_id CHAR(36) NOT NULL,
			base_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
			per_km DECIMAL(10,2) NOT NULL DEFAULT 0,
			per_min DECIMAL(10,2) NOT NULL DEFAULT 0,
			min_fare DECIMAL(10,2) NOT NULL DEFAULT 0,
			platform_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 10,
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 5,
			status VARCHAR(20) NOT NULL DEFAULT 'published',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY vehicle_category_id (vehicle_category_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'quotes' ) . " (
			id CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			vehicle_category_id CHAR(36) NOT NULL,
			booking_type VARCHAR(10) NOT NULL DEFAULT 'parcel',
			pickup_lat DECIMAL(10,7) NOT NULL,
			pickup_lng DECIMAL(10,7) NOT NULL,
			fare_breakdown LONGTEXT NOT NULL,
			drops_snapshot LONGTEXT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'bookings' ) . " (
			id CHAR(36) NOT NULL,
			idempotency_key VARCHAR(100) NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			driver_id BIGINT UNSIGNED NULL,
			booking_type VARCHAR(10) NOT NULL DEFAULT 'parcel',
			passenger_count SMALLINT NULL DEFAULT 1,
			status VARCHAR(30) NOT NULL DEFAULT 'searching',
			vehicle_category_id CHAR(36) NULL,
			pickup_lat DECIMAL(10,7) NOT NULL,
			pickup_lng DECIMAL(10,7) NOT NULL,
			pickup_address LONGTEXT NOT NULL,
			pickup_otp VARCHAR(10) NULL,
			quote_id CHAR(36) NULL,
			fare_breakdown LONGTEXT NULL,
			payment_method VARCHAR(20) NULL,
			payment_status VARCHAR(20) NULL DEFAULT 'pending_collection',
			scheduled_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idempotency (customer_id, idempotency_key),
			KEY customer_id (customer_id),
			KEY driver_id (driver_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'booking_stops' ) . " (
			id CHAR(36) NOT NULL,
			booking_id CHAR(36) NOT NULL,
			sequence SMALLINT NOT NULL,
			drop_lat DECIMAL(10,7) NOT NULL,
			drop_lng DECIMAL(10,7) NOT NULL,
			address_snapshot LONGTEXT NOT NULL,
			instructions VARCHAR(255) NULL,
			delivery_preference VARCHAR(20) NOT NULL DEFAULT 'otp',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			otp_code VARCHAR(10) NULL,
			otp_attempts SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY booking_id (booking_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'dispatch_offers' ) . " (
			id CHAR(36) NOT NULL,
			booking_id CHAR(36) NOT NULL,
			driver_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'offered',
			offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			responded_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY booking_id (booking_id),
			KEY driver_id (driver_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'driver_profiles' ) . " (
			user_id BIGINT UNSIGNED NOT NULL,
			kyc_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			training_status VARCHAR(20) NOT NULL DEFAULT 'not_started',
			online_status TINYINT(1) NOT NULL DEFAULT 0,
			rating_avg DECIMAL(3,2) NULL,
			rating_count INT NOT NULL DEFAULT 0,
			last_lat DECIMAL(10,7) NULL,
			last_lng DECIMAL(10,7) NULL,
			last_ping_at DATETIME NULL,
			vehicle_plate VARCHAR(20) NULL,
			vehicle_category VARCHAR(50) NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'wallets' ) . " (
			owner_id BIGINT UNSIGNED NOT NULL,
			owner_type VARCHAR(20) NOT NULL DEFAULT 'customer',
			real_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
			promo_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (owner_id, owner_type)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'wallet_transactions' ) . " (
			id CHAR(36) NOT NULL,
			owner_id BIGINT UNSIGNED NOT NULL,
			owner_type VARCHAR(20) NOT NULL,
			entry_type VARCHAR(10) NOT NULL,
			amount DECIMAL(12,2) NOT NULL,
			balance_after DECIMAL(12,2) NOT NULL,
			reason VARCHAR(50) NOT NULL,
			reference_id CHAR(36) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY owner (owner_id, owner_type)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'sos_events' ) . " (
			id CHAR(36) NOT NULL,
			booking_id CHAR(36) NOT NULL,
			triggered_by_role VARCHAR(20) NOT NULL,
			trigger_lat DECIMAL(10,7) NULL,
			trigger_lng DECIMAL(10,7) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			acknowledged_at DATETIME NULL,
			escalated_at DATETIME NULL,
			resolved_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'support_tickets' ) . " (
			id CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(50) NOT NULL,
			subject VARCHAR(200) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			priority VARCHAR(20) NOT NULL DEFAULT 'normal',
			linked_booking_id CHAR(36) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'banners' ) . " (
			id CHAR(36) NOT NULL,
			headline VARCHAR(200) NOT NULL,
			image_url TEXT NULL,
			cta_text VARCHAR(100) NULL,
			cta_deep_link VARCHAR(100) NULL,
			segment VARCHAR(50) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;";

		$sql[] = "CREATE TABLE " . self::table( 'saved_addresses' ) . " (
			id CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			label VARCHAR(100) NOT NULL,
			lat DECIMAL(10,7) NOT NULL,
			lng DECIMAL(10,7) NOT NULL,
			address_line TEXT NULL,
			contact_name VARCHAR(100) NULL,
			contact_phone VARCHAR(20) NULL,
			is_favourite TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
