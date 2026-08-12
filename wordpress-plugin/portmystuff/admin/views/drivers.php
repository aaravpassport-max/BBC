<?php if ( ! defined( 'ABSPATH' ) ) exit;
$drivers = Portmystuff_Driver_Service::list_drivers_admin();
?>
<table class="widefat striped">
	<thead><tr><th>Name</th><th>Phone</th><th>KYC</th><th>Training</th><th>Online</th><th>Rating</th></tr></thead>
	<tbody>
	<?php foreach ( $drivers as $d ) : ?>
		<tr>
			<td><?php echo esc_html( $d['name'] ); ?></td>
			<td><?php echo esc_html( $d['phone'] ); ?></td>
			<td><?php echo esc_html( $d['kyc_status'] ); ?></td>
			<td><?php echo esc_html( $d['training_status'] ); ?></td>
			<td><?php echo $d['online_status'] ? '●' : '○'; ?></td>
			<td><?php echo esc_html( $d['rating_avg'] ?? '—' ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
