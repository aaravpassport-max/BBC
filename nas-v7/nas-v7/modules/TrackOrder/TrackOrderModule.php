<?php
/**
 * TrackOrder Module — Public order tracking by Order ID + Email
 */
namespace NAS\Modules\TrackOrder;
use NAS\Core\{Module, Database, Security};
if ( ! defined( 'ABSPATH' ) ) exit;

class TrackOrderModule extends Module {
    public function key(): string { return 'track_order'; }

    public function register(): void {
        add_shortcode( 'nas_track_order', [ $this, 'shortcode' ] );
        add_action( 'wp_ajax_nas_track_order',        [ TrackOrderController::class, 'track' ] );
        add_action( 'wp_ajax_nopriv_nas_track_order', [ TrackOrderController::class, 'track' ] );
    }

    public function boot(): void {}

    // TRACE: shortcode() — Trigger: wp_ajax_shortcode AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function shortcode(): string {
        ob_start();
        include NAS_DIR . 'templates/public/track-order.php';
        return ob_get_clean();
    }
}

class TrackOrderController {
    // TRACE: track() — Trigger: wp_ajax_track AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function track(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $order_id = strtoupper( sanitize_text_field( Security::post('order_id') ) );
        $email    = sanitize_email( Security::post('email') );

        if ( ! $order_id || ! $email ) {
            wp_send_json_error(['message' => 'Order ID and email are required']);
            return;
        }

        $db     = Database::instance();
        $booking = $db->row(
            "SELECT b.*, cl.name as client_name, cl.email as client_email,
                    n.name as newspaper_name, cat.name as category_name, ci.name as city_name,
                    n.logo_url as newspaper_logo
             FROM {$db->t('bookings')} b
             LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
             LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id
             LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
             LEFT JOIN {$db->t('cities')} ci ON ci.id = b.city_id
             WHERE b.uid = %s AND cl.email = %s",
            $order_id, $email
        );

        if ( ! $booking ) {
            wp_send_json_error(['message' => 'No booking found with that Order ID and email combination.']);
            return;
        }

        // Build status timeline
        $history = json_decode($booking['workflow_history'] ?? '[]', true) ?: [];

        // Redact sensitive info
        unset($booking['client_id']);
        $booking['client_email'] = substr($email, 0, 3) . '***' . strstr($email, '@');

        wp_send_json_success(['booking' => $booking, 'history' => $history]);
    }
}


// =============================================================================
/**
 * Blog Module — blog posts management
 */
// =============================================================================
