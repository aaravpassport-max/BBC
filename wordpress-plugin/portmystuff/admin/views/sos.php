<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$sos = $wpdb->get_results( "SELECT * FROM " . Portmystuff_Database::table( 'sos_events' ) . " WHERE status IN ('open','acknowledged') ORDER BY created_at DESC", ARRAY_A );
?>
<table class="widefat striped"><thead><tr><th>Booking</th><th>Role</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php if ( empty( $sos ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No active SOS events.', 'portmystuff' ); ?></td></tr><?php else : foreach ( $sos as $s ) : ?>
<tr><td><code><?php echo esc_html( substr( $s['booking_id'], 0, 8 ) ); ?></code></td><td><?php echo esc_html( $s['triggered_by_role'] ); ?></td><td><?php echo esc_html( $s['status'] ); ?></td><td><?php echo esc_html( $s['created_at'] ); ?></td></tr>
<?php endforeach; endif; ?></tbody></table>
