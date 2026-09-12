<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<p><?php esc_html_e( 'Look up a booking ID to view dispatch offer timeline.', 'portmystuff' ); ?></p>
<form method="get" class="pms-inline-form">
	<input type="hidden" name="page" value="portmystuff-ops-dispatch" />
	<input type="text" name="booking_id" placeholder="Booking UUID" value="<?php echo esc_attr( $_GET['booking_id'] ?? '' ); ?>" class="regular-text" />
	<?php submit_button( __( 'Look up', 'portmystuff' ), 'secondary', '', false ); ?>
</form>
<?php
if ( ! empty( $_GET['booking_id'] ) ) {
	$log = Portmystuff_Dispatch_Service::get_dispatch_log( sanitize_text_field( wp_unslash( $_GET['booking_id'] ) ) );
	if ( $log ) {
		echo '<pre class="pms-code">' . esc_html( wp_json_encode( $log, JSON_PRETTY_PRINT ) ) . '</pre>';
	} else {
		echo '<p>' . esc_html__( 'Booking not found.', 'portmystuff' ) . '</p>';
	}
}
?>
