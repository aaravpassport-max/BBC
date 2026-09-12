<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$bookings_table = Portmystuff_Database::table( 'bookings' );
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $bookings_table" );
$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $bookings_table WHERE status IN ('searching','driver_assigned','in_progress')" );
$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $bookings_table WHERE status = 'completed'" );
$drivers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Portmystuff_Database::table( 'users' ) . " WHERE account_type = 'driver'" );
?>
<div class="pms-cards">
	<div class="pms-card"><div class="pms-card-label"><?php esc_html_e( 'Total bookings', 'portmystuff' ); ?></div><div class="pms-card-value"><?php echo esc_html( $total ); ?></div></div>
	<div class="pms-card"><div class="pms-card-label"><?php esc_html_e( 'Active trips', 'portmystuff' ); ?></div><div class="pms-card-value"><?php echo esc_html( $active ); ?></div></div>
	<div class="pms-card"><div class="pms-card-label"><?php esc_html_e( 'Completed', 'portmystuff' ); ?></div><div class="pms-card-value"><?php echo esc_html( $completed ); ?></div></div>
	<div class="pms-card"><div class="pms-card-label"><?php esc_html_e( 'Drivers', 'portmystuff' ); ?></div><div class="pms-card-value"><?php echo esc_html( $drivers ); ?></div></div>
</div>
<p class="description"><?php esc_html_e( 'Standalone logistics platform — rides + parcels. Use shortcodes on any page to embed customer or driver apps.', 'portmystuff' ); ?></p>
<ul>
	<li><code>[portmystuff_customer]</code> — <?php esc_html_e( 'Customer booking app', 'portmystuff' ); ?></li>
	<li><code>[portmystuff_driver]</code> — <?php esc_html_e( 'Driver partner app', 'portmystuff' ); ?></li>
</ul>
