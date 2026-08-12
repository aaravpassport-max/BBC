<?php if ( ! defined( 'ABSPATH' ) ) exit; global $wpdb;
$tickets = $wpdb->get_results( 'SELECT * FROM ' . Portmystuff_Database::table( 'support_tickets' ) . ' ORDER BY created_at DESC LIMIT 30', ARRAY_A );
?>
<table class="widefat striped"><thead><tr><th>Subject</th><th>Category</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php if ( empty( $tickets ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No tickets.', 'portmystuff' ); ?></td></tr><?php else : foreach ( $tickets as $t ) : ?>
<tr><td><?php echo esc_html( $t['subject'] ); ?></td><td><?php echo esc_html( $t['category'] ); ?></td><td><?php echo esc_html( $t['status'] ); ?></td><td><?php echo esc_html( $t['created_at'] ); ?></td></tr>
<?php endforeach; endif; ?></tbody></table>
