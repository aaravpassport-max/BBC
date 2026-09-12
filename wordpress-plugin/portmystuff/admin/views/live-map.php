<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$drivers = $wpdb->get_results( "SELECT user_id, last_lat, last_lng, online_status, last_ping_at FROM " . Portmystuff_Database::table( 'driver_profiles' ) . " WHERE online_status = 1", ARRAY_A );
?>
<table class="widefat striped"><thead><tr><th>Driver</th><th>Lat</th><th>Lng</th><th>Last ping</th></tr></thead><tbody>
<?php if ( empty( $drivers ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No drivers online.', 'portmystuff' ); ?></td></tr><?php else : foreach ( $drivers as $d ) : ?>
<tr><td><?php echo esc_html( $d['user_id'] ); ?></td><td><?php echo esc_html( $d['last_lat'] ); ?></td><td><?php echo esc_html( $d['last_lng'] ); ?></td><td><?php echo esc_html( $d['last_ping_at'] ); ?></td></tr>
<?php endforeach; endif; ?></tbody></table>
