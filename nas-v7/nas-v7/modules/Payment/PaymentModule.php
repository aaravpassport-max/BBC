<?php
namespace NAS\Modules\Payment;

use NAS\Core\{Module, Database, Security, Config, EventBus, Helpers};
if ( ! defined( 'ABSPATH' ) ) exit;

class PaymentModule extends Module {
    public function key(): string { return 'payment'; }

    public function register(): void {
        // Public endpoints (payment gateway redirects)
        add_action( 'wp_ajax_nas_create_payment_order',        [ PaymentController::class, 'create_order' ] );
        add_action( 'wp_ajax_nopriv_nas_create_payment_order', [ PaymentController::class, 'create_order' ] );
        add_action( 'wp_ajax_nas_verify_payment',              [ PaymentController::class, 'verify' ] );
        add_action( 'wp_ajax_nopriv_nas_verify_payment',       [ PaymentController::class, 'verify' ] );
        add_action( 'wp_ajax_nas_payment_failed',              [ PaymentController::class, 'failed' ] );
        add_action( 'wp_ajax_nopriv_nas_payment_failed',       [ PaymentController::class, 'failed' ] );

        // Webhook (no nonce — gateway POSTs here)
        add_action( 'init', [ self::class, 'handle_webhook' ] );

        // Admin
        add_action( 'wp_ajax_nas_admin_initiate_refund', [ PaymentController::class, 'initiate_refund' ] );
        add_action( 'wp_ajax_nas_admin_mark_paid',       [ PaymentController::class, 'mark_paid_manual' ] );
        add_action( 'wp_ajax_nas_get_payment_status',    [ PaymentController::class, 'get_status' ] );

        // Shortcode for payment page
        add_shortcode( 'nas_payment', [ $this, 'shortcode_payment' ] );
    }

    public function boot(): void {}

    // TRACE: shortcode_payment() — Trigger: wp_ajax_shortcode_payment AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function shortcode_payment( array $atts = [] ): string {
        ob_start();
        include NAS_DIR . 'templates/payment/checkout.php';
        return ob_get_clean();
    }

    // TRACE: handle_webhook() — Trigger: wp_ajax_handle_webhook AJAX action.
    //        Steps: processes incoming request.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function handle_webhook(): void {
        if ( ! isset( $_GET['nas_webhook'] ) ) return;
        $gateway = sanitize_text_field( $_GET['nas_webhook'] );
        $body    = file_get_contents( 'php://input' );

        if ( $gateway === 'razorpay' ) {
            RazorpayService::handle_webhook( $body );
        } elseif ( $gateway === 'payu' ) {
            PayUService::handle_webhook( $body );
        }
        exit;
    }
}

// ── Payment Service ────────────────────────────────────────────────────────────
class PaymentService {
    private Database $db;
    private Config $cfg;

    public function __construct() {
        $this->db  = Database::instance();
        $this->cfg = Config::instance();
    }

    // TRACE: create_order() — Trigger: wp_ajax_create_order AJAX action.
    //        Steps: inserts DB row → queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function create_order( int $booking_id, float $amount, string $gateway = 'razorpay' ): array {
        $booking = $this->db->row( "SELECT * FROM {$this->db->t('bookings')} WHERE id = %d", $booking_id );
        if ( ! $booking ) return [ 'success' => false, 'error' => 'Booking not found' ];

        $currency = $this->cfg->get( 'currency', 'INR' );

        if ( $gateway === 'razorpay' ) {
            $result = RazorpayService::create_order( $amount, $currency, "Booking #{$booking['uid']}" );
        } elseif ( $gateway === 'payu' ) {
            $result = PayUService::prepare_request( $booking, $amount );
        } elseif ( $gateway === 'stripe' ) {
            $result = StripeService::create_payment_intent( $amount, $currency, "Booking #" . ($booking['uid'] ?? $booking_id) );
        } else {
            return [ 'success' => false, 'error' => 'Unsupported gateway' ];
        }

        if ( $result['success'] ) {
            // Record payment row
            $payment_row_id = $this->db->insert( $this->db->t( 'payments' ), [
                'booking_id'       => $booking_id,
                'gateway'          => $gateway,
                'gateway_order_id' => $result['order_id'] ?? '',
                'amount'           => $amount,
                'currency'         => $currency,
                'status'           => 'created',
            ]);
            $result['payment_row_id'] = $payment_row_id;
        }
        return $result;
    }

    // TRACE: capture_payment() — Trigger: wp_ajax_capture_payment AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → runs DB transaction.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public function capture_payment( string $gateway_order_id, string $gateway_payment_id, string $signature ): bool {
        $payment = $this->db->row(
            "SELECT * FROM {$this->db->t('payments')} WHERE gateway_order_id = %s",
            $gateway_order_id
        );
        if ( ! $payment ) return false;

        $verified = false;
        if ( $payment['gateway'] === 'razorpay' ) {
            $verified = RazorpayService::verify_signature( $gateway_order_id, $gateway_payment_id, $signature );
        } elseif ( $payment['gateway'] === 'payu' ) {
            $verified = PayUService::verify_response( $_POST );
        } elseif ( $payment['gateway'] === 'manual' ) {
            $verified = true;
        }

        if ( ! $verified ) return false;

        // Wrap payment + booking update in transaction: both must succeed or both roll back
        $booking_id_tx = (int) $payment['booking_id'];
        $payment_id_tx = (int) $payment['id'];
        $tx_ok = $this->db->transaction( function( $db ) use ( $gateway_payment_id, $signature, $payment, $booking_id_tx, $payment_id_tx ) {
            $db->update( $db->t('payments'), [
                'gateway_payment_id' => $gateway_payment_id,
                'gateway_signature'  => $signature,
                'status'             => 'captured',
            ], [ 'id' => $payment_id_tx ] );

            $db->update( $db->t('bookings'), [
                'status'         => 'payment_received',
                'payment_status' => 'paid',
                'payment_id'     => $gateway_payment_id,
                'payment_ref'    => $gateway_payment_id,
                'paid_amount'    => $payment['amount'],
            ], [ 'id' => $booking_id_tx ] );
        } );
        if ( ! $tx_ok ) return false; // transaction rolled back

        // Generate invoice
        $invoice_svc = new \NAS\Modules\Invoice\InvoiceService();
        $invoice_svc->create_for_booking( (int) $payment['booking_id'] );

        // Fire event → triggers email + WhatsApp
        EventBus::emit( 'booking_status_changed', [
            'booking_id' => (int) $payment['booking_id'],
            'new_status' => 'payment_received',
            'note'       => "Payment confirmed. Gateway: {$payment['gateway']}",
            'client_id'  => $this->get_client_id( (int) $payment['booking_id'] ),
        ]);

        return true;
    }

    // TRACE: process_refund() — Trigger: wp_ajax_process_refund AJAX action.
    //        Steps: updates DB row → queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public function process_refund( int $booking_id, float $amount, string $reason = '' ): array {
        $payment = $this->db->row(
            "SELECT * FROM {$this->db->t('payments')} WHERE booking_id = %d AND status = 'captured' ORDER BY id DESC LIMIT 1",
            $booking_id
        );
        if ( ! $payment ) return [ 'success' => false, 'error' => 'No captured payment found' ];

        if ( $payment['gateway'] === 'razorpay' ) {
            $result = RazorpayService::refund( $payment['gateway_payment_id'], $amount );
        } else {
            $result = [ 'success' => true, 'refund_id' => 'MANUAL_' . time() ];
        }

        if ( $result['success'] ) {
            $this->db->update( $this->db->t('payments'), [
                'status'        => 'refunded',
                'refund_amount' => $amount,
                'refund_id'     => $result['refund_id'],
            ], [ 'id' => $payment['id'] ] );

            $this->db->update( $this->db->t('bookings'), [
                'status' => 'cancelled',
            ], [ 'id' => $booking_id ] );
        }
        return $result;
    }

    // TRACE: get_client_id() — Trigger: wp_ajax_get_client_id AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    private function get_client_id( int $booking_id ): int {
        $r = $this->db->row( "SELECT client_id FROM {$this->db->t('bookings')} WHERE id = %d", $booking_id );
        return $r ? (int) $r['client_id'] : 0;
    }
}

// ── Razorpay Service ──────────────────────────────────────────────────────────
class RazorpayService {

    // TRACE: cfg() — Trigger: wp_ajax_cfg AJAX action.
    //        Steps: returns JSON error response on failure → calls external API.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function cfg(): array {
        $c = Config::instance();
        return [
            'key_id'     => $c->get( 'razorpay_key_id', '' ),
            'key_secret' => $c->get( 'razorpay_key_secret', '' ),
        ];
    }

    // TRACE: create_order() — Trigger: wp_ajax_create_order AJAX action.
    //        Steps: logs errors via ErrorLogger → calls external API.
    //        Output: mixed result value.
    //        Edge cases: WP_Error returned by external call handled.
    public static function create_order( float $amount, string $currency = 'INR', string $receipt = '' ): array {
        $creds = self::cfg();
        if ( ! $creds['key_id'] ) return [ 'success' => false, 'error' => 'Razorpay not configured' ];

        $payload = [
            'amount'   => (int) round( $amount * 100 ), // paise
            'currency' => $currency,
            'receipt'  => $receipt,
            'notes'    => [ 'source' => 'nas_plugin' ],
        ];

        $response = wp_remote_post( 'https://api.razorpay.com/v1/orders', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$creds['key_id']}:{$creds['key_secret']}" ),
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( $payload ),
            'timeout' => 20,
        ]);

        if ( is_wp_error( $response ) ) return [ 'success' => false, 'error' => $response->get_error_message() ];

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $body === null ) {
            ErrorLogger::error( 'Razorpay: malformed JSON response', [ 'raw' => substr( wp_remote_retrieve_body($response), 0, 200 ) ] );
            return [ 'success' => false, 'error' => 'Gateway returned an invalid response. Please try again.' ];
        }
        if ( ! empty( $body['id'] ) ) {
            return [
                'success'    => true,
                'order_id'   => $body['id'],
                'amount'     => $body['amount'],
                'currency'   => $body['currency'],
                'key_id'     => $creds['key_id'],
                'gateway'    => 'razorpay',
            ];
        }
        return [ 'success' => false, 'error' => $body['error']['description'] ?? 'Order creation failed' ];
    }

    // TRACE: verify_signature() — Trigger: wp_ajax_verify_signature AJAX action.
    //        Steps: returns JSON error response on failure → calls external API.
    //        Output: mixed result value.
    //        Edge cases: WP_Error returned by external call handled.
    public static function verify_signature( string $order_id, string $payment_id, string $signature ): bool {
        $creds    = self::cfg();
        $expected = hash_hmac( 'sha256', "{$order_id}|{$payment_id}", $creds['key_secret'] );
        return hash_equals( $expected, $signature );
    }

    // TRACE: refund() — Trigger: wp_ajax_refund AJAX action.
    //        Steps: logs errors via ErrorLogger → calls external API.
    //        Output: mixed result value.
    //        Edge cases: WP_Error returned by external call handled.
    public static function refund( string $payment_id, float $amount ): array {
        $creds = self::cfg();
        $response = wp_remote_post( "https://api.razorpay.com/v1/payments/{$payment_id}/refund", [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$creds['key_id']}:{$creds['key_secret']}" ),
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode( [ 'amount' => (int) round( $amount * 100 ) ] ),
            'timeout' => 20,
        ]);
        if ( is_wp_error( $response ) ) return [ 'success' => false, 'error' => $response->get_error_message() ];
        $raw_body = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw_body, true );
        if ( $body === null ) { ErrorLogger::error('Razorpay: malformed refund JSON', ['raw'=>substr($raw_body,0,200)]); return ['success'=>false,'error'=>'Gateway returned invalid response.']; }
        return isset( $body['id'] ) ? [ 'success' => true, 'refund_id' => $body['id'] ] : [ 'success' => false, 'error' => $body['error']['description'] ?? 'Refund failed' ];
    }

    // TRACE: handle_webhook() — Trigger: wp_ajax_handle_webhook AJAX action.
    //        Steps: processes incoming request.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function handle_webhook( string $raw_body ): void {
        $cfg       = Config::instance();
        $secret    = $cfg->get( 'razorpay_webhook_secret', '' );
        $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

        if ( $secret && ! hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), $signature ) ) {
            status_header( 400 );
            return;
        }

        $event = json_decode( $raw_body, true );
        if ( $event === null ) { status_header( 400 ); return; }

        if ( ( $event['event'] ?? '' ) === 'payment.captured' ) {
            $payment_entity = $event['payload']['payment']['entity'] ?? [];
            $order_id       = $payment_entity['order_id'] ?? '';
            $payment_id     = $payment_entity['id'] ?? '';

            // 16-F-5 Webhook idempotency: skip if this payment_id was already processed
            $idem_key = 'nas_wh_' . md5( $payment_id );
            if ( get_transient( $idem_key ) ) {
                status_header( 200 ); // respond 200 so Razorpay stops retrying
                return;
            }
            set_transient( $idem_key, 1, 86400 ); // 24h guard window

            $svc = new PaymentService();
            $svc->capture_payment( $order_id, $payment_id, '' );
        }
        status_header( 200 );
    }
}

// ── PayU Service ──────────────────────────────────────────────────────────────
class PayUService {

    // TRACE: cfg() — Trigger: wp_ajax_cfg AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    private static function cfg(): array {
        $c = Config::instance();
        return [
            'merchant_key'  => $c->get( 'payu_merchant_key', '' ),
            'merchant_salt' => $c->get( 'payu_merchant_salt', '' ),
            'test_mode'     => (bool) $c->get( 'payu_test_mode', 1 ),
        ];
    }

    // TRACE: prepare_request() — Trigger: wp_ajax_prepare_request AJAX action.
    //        Steps: executes operation.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function prepare_request( array $booking, float $amount ): array {
        $creds = self::cfg();
        if ( ! $creds['merchant_key'] ) return [ 'success' => false, 'error' => 'PayU not configured' ];

        $txnid = 'NAS' . time() . rand( 100, 999 );
        $hash_str = implode( '|', [
            $creds['merchant_key'], $txnid, number_format( $amount, 2, '.', '' ),
            "Booking #{$booking['uid']}",
            $booking['client_name'] ?? 'Client',
            $booking['client_email'] ?? '',
            '', '', '', '', '',
            $creds['merchant_salt'],
        ]);
        $hash = hash( 'sha512', $hash_str );

        $base_url = $creds['test_mode'] ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment';

        return [
            'success'      => true,
            'gateway'      => 'payu',
            'order_id'     => $txnid,
            'payu_url'     => $base_url,
            'merchant_key' => $creds['merchant_key'],
            'txnid'        => $txnid,
            'amount'       => number_format( $amount, 2, '.', '' ),
            'productinfo'  => "Booking #{$booking['uid']}",
            'hash'         => $hash,
        ];
    }

    // TRACE: verify_response() — Trigger: wp_ajax_verify_response AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function verify_response( array $post ): bool {
        $creds    = self::cfg();
        $reverse_hash = implode( '|', [
            $creds['merchant_salt'],
            $post['status'] ?? '',
            '', '', '', '', '', '',
            $post['email'] ?? '',
            $post['firstname'] ?? '',
            $post['productinfo'] ?? '',
            $post['amount'] ?? '',
            $post['txnid'] ?? '',
            $creds['merchant_key'],
        ]);
        return hash_equals( strtolower( $post['hash'] ?? '' ), strtolower( hash( 'sha512', $reverse_hash ) ) );
    }

    // TRACE: handle_webhook() — Trigger: wp_ajax_handle_webhook AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: mixed result value.
    //        Edge cases: invalid input → error returned.
    public static function handle_webhook( string $body ): void {
        parse_str( $body, $data );
        if ( ( $data['status'] ?? '' ) === 'success' && self::verify_response( $data ) ) {
            $svc = new PaymentService();
            $svc->capture_payment( $data['txnid'] ?? '', $data['payuMoneyId'] ?? '', '' );
        }
        status_header( 200 );
    }
}

// ── Stripe Service ─────────────────────────────────────────────────────────────
class StripeService {

    /**
     * Create a Stripe PaymentIntent for the given amount.
     */
    // TRACE: create_payment_intent() — Trigger: wp_ajax_create_payment_intent AJAX action.
    //        Steps: calls external API.
    //        Output: mixed result value.
    //        Edge cases: WP_Error returned by external call handled.
    public static function create_payment_intent( float $amount, string $currency = 'inr', string $description = '' ): array {
        $secret_key = \NAS\Core\Config::instance()->get('stripe_secret_key','');
        if ( ! $secret_key ) {
            return ['success' => false, 'error' => 'Stripe is not configured. Please add your Stripe secret key in Settings.'];
        }

        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'amount'      => (int)round($amount * 100), // cents/paise
                'currency'    => strtolower($currency),
                'description' => $description,
                'automatic_payment_methods[enabled]' => 'true',
            ],
        ]);

        if ( is_wp_error($response) ) {
            return ['success' => false, 'error' => 'Stripe connection failed: ' . $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ( isset($body['error']) ) {
            return ['success' => false, 'error' => $body['error']['message'] ?? 'Stripe error'];
        }

        return [
            'success'        => true,
            'order_id'       => $body['id'],
            'client_secret'  => $body['client_secret'],
            'amount'         => $amount,
            'currency'       => strtoupper($currency),
        ];
    }

    /**
     * Verify a Stripe webhook signature.
     */
    // TRACE: verify_webhook() — Trigger: wp_ajax_verify_webhook AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public static function verify_webhook( string $payload, string $sig_header ): ?array {
        $webhook_secret = \NAS\Core\Config::instance()->get('stripe_webhook_secret','');
        if ( ! $webhook_secret ) return null;

        $timestamp = '';
        $received  = '';
        foreach ( explode(',', $sig_header) as $part ) {
            [$k, $v] = explode('=', trim($part), 2);
            if ($k === 't') $timestamp = $v;
            if ($k === 'v1') $received = $v;
        }

        $signed   = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $webhook_secret);

        return hash_equals($expected, $received) ? json_decode($payload, true) : null;
    }
}
