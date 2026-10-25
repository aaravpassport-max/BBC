<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<form method="post" action="options.php">
	<?php settings_fields( 'portmystuff_settings' ); ?>
	<table class="form-table">
		<tr><th><?php esc_html_e( 'Default city', 'portmystuff' ); ?></th><td><input name="portmystuff_default_city" value="<?php echo esc_attr( get_option( 'portmystuff_default_city', 'Bengaluru' ) ); ?>" class="regular-text" /></td></tr>
		<tr><th><?php esc_html_e( 'Demo OTP mode', 'portmystuff' ); ?></th><td><label><input type="checkbox" name="portmystuff_demo_otp" value="1" <?php checked( get_option( 'portmystuff_demo_otp', '1' ), '1' ); ?> /> <?php esc_html_e( 'Log OTPs to debug.log (dev)', 'portmystuff' ); ?></label></td></tr>
	</table>
	<?php submit_button(); ?>
</form>
<p><strong><?php esc_html_e( 'REST API base:', 'portmystuff' ); ?></strong> <code><?php echo esc_html( rest_url( 'portmystuff/v1' ) ); ?></code></p>
