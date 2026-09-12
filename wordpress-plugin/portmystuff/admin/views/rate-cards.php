<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$rows = $wpdb->get_results(
	'SELECT rc.*, vc.name AS category FROM ' . Portmystuff_Database::table( 'rate_cards' ) . ' rc
	JOIN ' . Portmystuff_Database::table( 'vehicle_categories' ) . ' vc ON vc.id = rc.vehicle_category_id',
	ARRAY_A
);
?>
<table class="widefat striped">
	<thead><tr><th>City</th><th>Vehicle</th><th>Base</th><th>Per km</th><th>Min fare</th><th>Status</th></tr></thead>
	<tbody>
	<?php foreach ( $rows as $r ) : ?>
		<tr>
			<td><?php echo esc_html( $r['city_name'] ); ?></td>
			<td><?php echo esc_html( $r['category'] ); ?></td>
			<td>₹<?php echo esc_html( $r['base_fare'] ); ?></td>
			<td>₹<?php echo esc_html( $r['per_km'] ); ?></td>
			<td>₹<?php echo esc_html( $r['min_fare'] ); ?></td>
			<td><?php echo esc_html( $r['status'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
