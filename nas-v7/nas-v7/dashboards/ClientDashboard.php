<?php
namespace NAS\Dashboards;

use NAS\Core\Database;

/**
 * Client Dashboard — mobile-first PHP app shell
 *
 * Renders templates/client/dashboard.php with server-side initial stats
 * and a fixed bottom tab bar on mobile (Bookings · Profile · Wallet · Support).
 *
 * Data injection: stats and first page of bookings are server-rendered
 * so the initial paint shows real data instantly — no spinner, no AJAX
 * needed for the initial view.
 */
class ClientDashboard {

    // TRACE: register() — Registers shortcode and script enqueue.
    public static function register(): void {
        add_shortcode( 'nas_client_dashboard', [ self::class, 'render' ] );
        add_action( 'wp_enqueue_scripts',      [ self::class, 'enqueue' ] );
    }

    // TRACE: enqueue() — Client dashboard uses the PHP template (mobile-first).
    public static function enqueue(): void {
        // Assets enqueued by NAS\Core\Enqueue::frontend_assets() on dashboard pages.
    }

    // TRACE: render() — Shortcode callback. Outputs the React mount point + config.
    //        PHP queries initial data so the first paint is instant.
    //        Edge cases: not logged in → redirect to login page.
    public static function render( $atts ): string {
        if ( ! is_user_logged_in() ) {
            $redirect = urlencode( home_url( '/client-dashboard/' ) );
            wp_redirect( nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' ) . '?redirect_to=' . $redirect );
            exit;
        }

        $db  = Database::instance();
        $uid = (int) get_current_user_id();
        $cfg = \NAS\Core\Config::instance();

        // ── Client row ────────────────────────────────────────────────────
        $client = $db->row(
            "SELECT * FROM `{$db->t('clients')}` WHERE wp_user_id = %d",
            $uid
        );
        $cid = $client ? (int) $client['id'] : 0;

        // ── Server-side initial data (cached 90s per user) ─────────────────
        $cache_key = 'nas_dash_' . $uid . '_' . $cid;
        $cached    = $cid ? get_transient( $cache_key ) : false;

        if ( $cached !== false ) {
            $stats         = $cached['stats'];
            $bookings_data = $cached['bookings_data'];
        } else {
            $stats = [ 'total_bookings'=>0, 'active_bookings'=>0, 'completed_bookings'=>0, 'total_spent'=>0 ];
            $bookings_data = [ 'bookings'=>[], 'total'=>0 ];

            if ( $cid ) {
                $t   = $db->t('bookings');
                $agg = $db->row(
                    "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN status NOT IN ('published','completed','rejected','cancelled') THEN 1 ELSE 0 END) AS active,
                        SUM(CASE WHEN status IN ('published','completed') THEN 1 ELSE 0 END) AS completed,
                        COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) AS spent
                     FROM `$t` WHERE client_id=%d",
                    $cid
                );
                if ( $agg ) {
                    $stats = [
                        'total_bookings'     => (int)   $agg['total'],
                        'active_bookings'    => (int)   $agg['active'],
                        'completed_bookings' => (int)   $agg['completed'],
                        'total_spent'        => (float) $agg['spent'],
                    ];
                }
                $bookings = $db->select(
                    "SELECT b.id, b.uid, b.uid AS booking_uid, b.status, b.payment_status,
                            b.total_amount, b.submitted_at, b.publish_date,
                            n.name AS newspaper_name, cat.name AS category_name
                     FROM `$t` b
                     LEFT JOIN `{$db->t('newspapers')}` n   ON n.id = b.newspaper_id
                     LEFT JOIN `{$db->t('categories')}` cat ON cat.id = b.category_id
                     WHERE b.client_id = %d
                     ORDER BY b.submitted_at DESC LIMIT 20",
                    $cid
                );
                $bookings_data = [ 'bookings' => $bookings ?: [], 'total' => $stats['total_bookings'] ];
                set_transient( $cache_key, [ 'stats'=>$stats, 'bookings_data'=>$bookings_data ], 90 );
            }
        }

        $initial_tickets = [ 'tickets' => [] ];
        if ( $cid ) {
            $initial_tickets['tickets'] = $db->select(
                "SELECT * FROM `{$db->t('support_tickets')}` WHERE client_id = %d ORDER BY created_at DESC LIMIT 50",
                $cid
            ) ?: [];
        }

        $initial_stats    = wp_json_encode( $stats );
        $initial_bookings = wp_json_encode( $bookings_data );
        $initial_tickets  = wp_json_encode( $initial_tickets );

        ob_start();
        include NAS_DIR . 'templates/client/dashboard.php';
        return ob_get_clean();
    }

    // Invalidate cache when a booking status changes
    public static function invalidate_cache( int $booking_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.client_id, cl.wp_user_id FROM {$wpdb->prefix}nas_bookings b
             LEFT JOIN {$wpdb->prefix}nas_clients cl ON cl.id = b.client_id
             WHERE b.id = %d", $booking_id
        ) );
        if ( $row ) {
            delete_transient( 'nas_dash_' . (int)$row->wp_user_id . '_' . (int)$row->client_id );
        }
    }
}
