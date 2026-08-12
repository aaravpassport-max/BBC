<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$banners = $wpdb->get_results( 'SELECT * FROM ' . Portmystuff_Database::table( 'banners' ) . ' ORDER BY created_at DESC', ARRAY_A );
?>
<table class="widefat striped"><thead><tr><th>Headline</th><th>Status</th><th>Segment</th></tr></thead><tbody>
<?php if ( empty( $banners ) ) : ?><tr><td colspan="3"><?php esc_html_e( 'No banners yet.', 'portmystuff' ); ?></td></tr><?php else : foreach ( $banners as $b ) : ?>
<tr><td><?php echo esc_html( $b['headline'] ); ?></td><td><?php echo esc_html( $b['status'] ); ?></td><td><?php echo esc_html( $b['segment'] ?? 'all' ); ?></td></tr>
<?php endforeach; endif; ?></tbody></table>
