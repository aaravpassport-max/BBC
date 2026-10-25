<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$rows = $wpdb->get_results( 'SELECT * FROM ' . Portmystuff_Database::table( 'bookings' ) . ' ORDER BY created_at DESC LIMIT 50', ARRAY_A );
?>
<table class="widefat striped">
	<thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Customer</th><th>Created</th></tr></thead>
	<tbody>
	<?php if ( empty( $rows ) ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'No bookings yet.', 'portmystuff' ); ?></td></tr>
	<?php else : foreach ( $rows as $r ) : ?>
		<tr>
			<td><code><?php echo esc_html( substr( $r['id'], 0, 8 ) ); ?></code></td>
			<td><?php echo esc_html( $r['booking_type'] ); ?></td>
			<td><?php echo esc_html( $r['status'] ); ?></td>
			<td><?php echo esc_html( $r['customer_id'] ); ?></td>
			<td><?php echo esc_html( $r['created_at'] ); ?></td>
		</tr>
	<?php endforeach; endif; ?>
	</tbody>
</table>
