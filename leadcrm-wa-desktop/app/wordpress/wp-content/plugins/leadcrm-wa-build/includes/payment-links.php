<?php
/**
 * LeadCRM Payment Links Module
 * Generates Razorpay/Cashfree payment links and UPI QR codes.
 * Sends payment link to client via WhatsApp automatically.
 * On payment webhook confirmation: updates lead balance, records analytics event.
 *
 * Supports:
 *   - Razorpay Payment Links API
 *   - Cashfree Payment Links API
 *   - Static UPI deep link (works without API keys)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB ──────────────────────────────────────────────────────────────────────

// TRACE: Creates lcrm_payment_links table for payment link tracking.
//        POSTCONDITIONS: Table exists. EDGE CASES: Idempotent.
function lcrm_payments_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_payment_links (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id         BIGINT UNSIGNED NOT NULL,
        gateway         ENUM('razorpay','cashfree','upi') NOT NULL DEFAULT 'upi',
        gateway_link_id VARCHAR(200)    NOT NULL DEFAULT '',
        amount          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        currency        VARCHAR(5)      NOT NULL DEFAULT 'INR',
        description     VARCHAR(300)    NOT NULL DEFAULT '',
        short_url       VARCHAR(500)    NOT NULL DEFAULT '',
        status          ENUM('created','sent','paid','expired','cancelled') NOT NULL DEFAULT 'created',
        paid_at         DATETIME        DEFAULT NULL,
        paid_amount     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
        wa_sent         TINYINT(1)      NOT NULL DEFAULT 0,
        created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY status (status),
        KEY gateway_link_id (gateway_link_id)
    ) $c;" );
}

// ─── SETTINGS GETTERS ────────────────────────────────────────────────────────

// TRACE: Returns payment gateway settings from wp_options.
//        EDGE CASES: Missing keys → returns empty strings (gateway calls will fail gracefully).
function lcrm_payments_gateway() {
    return get_option( 'lcrm_payment_gateway', 'upi' );
}
function lcrm_payments_razorpay_key_id() {
    return trim( (string) get_option( 'lcrm_razorpay_key_id', '' ) );
}
function lcrm_payments_razorpay_key_secret() {
    return trim( (string) get_option( 'lcrm_razorpay_key_secret', '' ) );
}
function lcrm_payments_cashfree_client_id() {
    return trim( (string) get_option( 'lcrm_cashfree_client_id', '' ) );
}
function lcrm_payments_cashfree_client_secret() {
    return trim( (string) get_option( 'lcrm_cashfree_client_secret', '' ) );
}
function lcrm_payments_upi_id() {
    return trim( (string) get_option( 'lcrm_upi_id', '' ) );
}
function lcrm_payments_upi_name() {
    return trim( (string) get_option( 'lcrm_upi_name', get_bloginfo( 'name' ) ) );
}

// ─── LINK GENERATION ─────────────────────────────────────────────────────────

// TRACE: Main entry point — generates payment link based on configured gateway →
//        Stores link in DB → optionally sends via WA → returns {link_id, short_url, gateway}.
//        PRECONDITIONS: lead_id valid; amount > 0.
//        POSTCONDITIONS: Link row in DB; WA sent if $send_wa=true and phone exists.
//        EDGE CASES: Gateway API failure → WP_Error returned; UPI always works (no API needed).
function lcrm_payments_create_link( $lead_id, $amount, $description = '', $send_wa = true ) {
    $lead = lcrm_get_lead( (int) $lead_id );
    if ( ! $lead ) return new WP_Error( 'not_found', 'Lead not found.' );
    if ( $amount <= 0 ) return new WP_Error( 'invalid_amount', 'Amount must be greater than 0.' );

    $gateway = lcrm_payments_gateway();
    $result  = null;

    switch ( $gateway ) {
        case 'razorpay':
            $result = lcrm_payments_create_razorpay_link( $lead, $amount, $description );
            break;
        case 'cashfree':
            $result = lcrm_payments_create_cashfree_link( $lead, $amount, $description );
            break;
        case 'upi':
        default:
            $result = lcrm_payments_create_upi_link( $lead, $amount, $description );
            break;
    }

    if ( is_wp_error( $result ) ) return $result;

    // Save to DB
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_payment_links", [
        'lead_id'        => (int) $lead_id,
        'gateway'        => $gateway,
        'gateway_link_id'=> $result['gateway_link_id'] ?? '',
        'amount'         => floatval( $amount ),
        'currency'       => 'INR',
        'description'    => sanitize_text_field( $description ?: "Payment for {$lead->work_type}" ),
        'short_url'      => $result['short_url'],
        'status'         => 'created',
        'created_by'     => get_current_user_id(),
        'created_at'     => current_time( 'mysql' ),
    ] );

    $link_id = (int) $wpdb->insert_id;
    if ( ! $link_id ) return new WP_Error( 'db_error', 'Could not save payment link.' );

    lcrm_log_activity( $lead_id, 'payment_link', "Payment link created: ₹" . number_format( $amount, 0 ) . " via {$gateway}" );
    lcrm_analytics_record_payment( $lead_id, 0, get_current_user_id() ); // link created event

    // Send via WA
    if ( $send_wa && $lead->client_phone ) {
        $wa_msg = lcrm_payments_build_wa_message( $lead, $amount, $result['short_url'], $description );
        $account_id = lcrm_wa_find_account_for_lead( $lead );
        if ( $account_id ) {
            $wa_result = lcrm_wa_send_message( $account_id, $lead->client_phone, $wa_msg );
            if ( ! is_wp_error( $wa_result ) ) {
                global $wpdb;
                $wpdb->update( "{$wpdb->prefix}lcrm_payment_links", [ 'wa_sent' => 1, 'status' => 'sent' ], [ 'id' => $link_id ] );
                lcrm_add_conversation( $lead_id, "[Payment Link Sent] ₹" . number_format( $amount, 0 ) );
            }
        }
    }

    return [
        'link_id'   => $link_id,
        'short_url' => $result['short_url'],
        'gateway'   => $gateway,
        'amount'    => $amount,
    ];
}

// TRACE: Creates payment link via Razorpay Payment Links API v1.
//        POSTs to api.razorpay.com/v1/payment_links with Basic Auth (key_id:key_secret).
//        Returns {gateway_link_id, short_url} on success, WP_Error on failure.
//        EDGE CASES: No API keys → WP_Error; amount in paise (×100); phone must be 10 digits.
function lcrm_payments_create_razorpay_link( $lead, $amount, $description ) {
    $key_id     = lcrm_payments_razorpay_key_id();
    $key_secret = lcrm_payments_razorpay_key_secret();
    if ( ! $key_id || ! $key_secret ) {
        return new WP_Error( 'no_keys', 'Razorpay API keys not configured.' );
    }

    $phone = preg_replace( '/[^\d]/', '', $lead->client_phone );
    if ( strlen( $phone ) === 10 ) $phone = '91' . $phone;

    $payload = [
        'amount'      => (int) round( $amount * 100 ), // paise
        'currency'    => 'INR',
        'accept_partial'     => false,
        'description' => $description ?: "Payment for {$lead->work_type}",
        'customer'    => [
            'name'    => $lead->client_name,
            'contact' => '+' . $phone,
            'email'   => $lead->client_email ?: '',
        ],
        'notify'      => [ 'sms' => false, 'email' => (bool) $lead->client_email ],
        'reminder_enable' => true,
        'notes'       => [ 'lead_id' => (string) $lead->id ],
        'callback_url'    => rest_url( 'lcrm/v1/payment-webhook/razorpay' ),
        'callback_method' => 'get',
    ];

    $response = wp_remote_post( 'https://api.razorpay.com/v1/payment_links', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( $key_id . ':' . $key_secret ),
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 && $code !== 201 ) {
        $err = $data['error']['description'] ?? "Razorpay error {$code}";
        return new WP_Error( 'razorpay_error', $err );
    }

    return [
        'gateway_link_id' => $data['id'] ?? '',
        'short_url'       => $data['short_url'] ?? '',
    ];
}

// TRACE: Creates payment link via Cashfree Payment Links API.
//        POSTs to api.cashfree.com/pg/links with x-client-id and x-client-secret headers.
//        Returns {gateway_link_id, short_url} on success, WP_Error on failure.
//        EDGE CASES: No API keys → WP_Error; link_id must be unique — uses lead_id + timestamp.
function lcrm_payments_create_cashfree_link( $lead, $amount, $description ) {
    $client_id     = lcrm_payments_cashfree_client_id();
    $client_secret = lcrm_payments_cashfree_client_secret();
    if ( ! $client_id || ! $client_secret ) {
        return new WP_Error( 'no_keys', 'Cashfree API keys not configured.' );
    }

    $phone = preg_replace( '/[^\d]/', '', $lead->client_phone );
    if ( strlen( $phone ) === 10 ) $phone = '91' . $phone;

    $link_id = 'LCRM' . $lead->id . '_' . time();

    $payload = [
        'link_id'      => $link_id,
        'link_amount'  => floatval( $amount ),
        'link_currency'=> 'INR',
        'link_purpose' => $description ?: "Payment for {$lead->work_type}",
        'customer_details' => [
            'customer_name'  => $lead->client_name,
            'customer_phone' => $phone,
            'customer_email' => $lead->client_email ?: 'noreply@' . parse_url( home_url(), PHP_URL_HOST ),
        ],
        'link_notify'  => [ 'send_sms' => false, 'send_email' => (bool) $lead->client_email ],
        'link_meta'    => [ 'notify_url' => rest_url( 'lcrm/v1/payment-webhook/cashfree' ) ],
    ];

    $response = wp_remote_post( 'https://api.cashfree.com/pg/links', [
        'headers' => [
            'x-client-id'     => $client_id,
            'x-client-secret' => $client_secret,
            'x-api-version'   => '2022-09-01',
            'Content-Type'    => 'application/json',
        ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $err = $data['message'] ?? "Cashfree error {$code}";
        return new WP_Error( 'cashfree_error', $err );
    }

    return [
        'gateway_link_id' => $data['link_id'] ?? $link_id,
        'short_url'       => $data['link_url'] ?? '',
    ];
}

// TRACE: Creates a static UPI deep-link payment URL (no API required).
//        Builds upi://pay?pa=...&pn=...&am=...&tn=... URL.
//        Always succeeds if UPI ID is configured.
//        EDGE CASES: No UPI ID → WP_Error; client opens link in UPI app on their phone.
function lcrm_payments_create_upi_link( $lead, $amount, $description ) {
    $upi_id   = lcrm_payments_upi_id();
    $upi_name = lcrm_payments_upi_name();

    if ( ! $upi_id ) return new WP_Error( 'no_upi', 'UPI ID not configured in payment settings.' );

    $note    = $description ?: "Lead #{$lead->id} {$lead->work_type}";
    $upi_url = 'upi://pay?'
              . 'pa=' . rawurlencode( $upi_id )
              . '&pn=' . rawurlencode( $upi_name )
              . '&am=' . number_format( $amount, 2, '.', '' )
              . '&cu=INR'
              . '&tn=' . rawurlencode( mb_substr( $note, 0, 100 ) );

    return [
        'gateway_link_id' => '',
        'short_url'       => $upi_url,
    ];
}

// TRACE: Builds the WhatsApp message text for a payment link.
//        Returns formatted string with amount, description, and clickable URL.
function lcrm_payments_build_wa_message( $lead, $amount, $url, $description ) {
    $site_name = get_bloginfo( 'name' );
    $desc      = $description ?: $lead->work_type;
    $amt_fmt   = '₹' . number_format( $amount, 0 );

    return "Hello {$lead->client_name} ji!\n\n"
         . "Please find your payment link below:\n\n"
         . "Service: {$desc}\n"
         . "Amount: {$amt_fmt}\n\n"
         . "Pay here: {$url}\n\n"
         . "Thank you!\n{$site_name}";
}

// ─── PAYMENT WEBHOOKS ────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'lcrm_payments_register_webhooks' );

// TRACE: Registers webhook endpoints for Razorpay and Cashfree payment confirmations.
function lcrm_payments_register_webhooks() {
    register_rest_route( 'lcrm/v1', '/payment-webhook/(?P<gateway>razorpay|cashfree)', [
        'methods'             => 'POST',
        'callback'            => 'lcrm_payments_handle_webhook',
        'permission_callback' => '__return_true',
        'args'                => [ 'gateway' => [ 'sanitize_callback' => 'sanitize_text_field' ] ],
    ] );
}

// TRACE: Receives payment confirmation from gateway → verifies signature →
//        finds payment link by gateway_link_id → updates lead pmt_received and balance_payment →
//        records analytics event → sends receipt via WA → returns 200.
//        EDGE CASES: Invalid signature → 401; already processed → 200 (idempotent);
//                    link not found → logs error, returns 200 (gateway should not retry).
function lcrm_payments_handle_webhook( WP_REST_Request $request ) {
    $gateway = $request->get_param( 'gateway' );
    $body    = $request->get_body();
    $data    = json_decode( $body, true );

    if ( $gateway === 'razorpay' ) {
        // Verify Razorpay webhook signature
        $secret    = get_option( 'lcrm_razorpay_webhook_secret', '' );
        $signature = $request->get_header( 'X-Razorpay-Signature' );
        if ( $secret && $signature ) {
            $expected = hash_hmac( 'sha256', $body, $secret );
            if ( ! hash_equals( $expected, (string) $signature ) ) {
                return new WP_REST_Response( [ 'status' => 'invalid_signature' ], 401 );
            }
        }

        $event = $data['event'] ?? '';
        if ( $event !== 'payment_link.paid' ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $link_id   = $data['payload']['payment_link']['entity']['id'] ?? '';
        $amount    = ( $data['payload']['payment']['entity']['amount'] ?? 0 ) / 100; // paise → rupees
        $status    = 'paid';

    } elseif ( $gateway === 'cashfree' ) {
        // Cashfree uses x-webhook-signature header
        $signature = $request->get_header( 'x-webhook-signature' );
        $secret    = lcrm_payments_cashfree_client_secret();
        if ( $secret && $signature ) {
            $expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
            if ( ! hash_equals( $expected, (string) $signature ) ) {
                return new WP_REST_Response( [ 'status' => 'invalid_signature' ], 401 );
            }
        }

        $event = $data['type'] ?? '';
        if ( $event !== 'PAYMENT_LINK_EVENT' ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $link_id = $data['data']['link']['link_id']    ?? '';
        $amount  = floatval( $data['data']['payment']['payment_amount'] ?? 0 );
        $status  = strtolower( $data['data']['link']['link_status'] ?? '' ) === 'paid' ? 'paid' : 'created';

    } else {
        return new WP_REST_Response( [ 'status' => 'unknown_gateway' ], 200 );
    }

    if ( $status !== 'paid' || ! $link_id ) {
        return new WP_REST_Response( [ 'status' => 'not_paid' ], 200 );
    }

    lcrm_payments_process_confirmed_payment( $link_id, $amount, $gateway );
    return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
}

// TRACE: Updates the lead record and link row after confirmed payment.
//        Finds link by gateway_link_id → checks not already processed (idempotent) →
//        UPDATEs lcrm_leads pmt_received += amount and balance_payment -= amount →
//        UPDATEs lcrm_payment_links status = 'paid' →
//        Records analytics event → creates success alert → sends WA receipt.
//        EDGE CASES: Link already marked paid → returns early (idempotent);
//                    balance_payment floored at 0 (cannot go negative).
function lcrm_payments_process_confirmed_payment( $gateway_link_id, $amount, $gateway ) {
    global $wpdb;

    $link = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_payment_links WHERE gateway_link_id = %s LIMIT 1",
        $gateway_link_id
    ) );

    if ( ! $link || $link->status === 'paid' ) return; // Already processed

    $lead = lcrm_get_lead( (int) $link->lead_id );
    if ( ! $lead ) return;

    // Update payment link status
    $wpdb->update( "{$wpdb->prefix}lcrm_payment_links", [
        'status'      => 'paid',
        'paid_at'     => current_time( 'mysql' ),
        'paid_amount' => floatval( $amount ),
    ], [ 'id' => $link->id ] );

    // Update lead financials — atomic: pmt_received += amount, balance_payment = MAX(0, balance - amount)
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_leads
         SET pmt_received    = pmt_received + %f,
             balance_payment = GREATEST(0, balance_payment - %f)
         WHERE id = %d",
        floatval( $amount ), floatval( $amount ), (int) $link->lead_id
    ) );

    // Log analytics
    lcrm_analytics_record_payment( $link->lead_id, $amount );

    // Log activity
    lcrm_log_activity( $link->lead_id, 'payment_received',
        "Payment received ₹" . number_format( $amount, 0 ) . " via {$gateway} (link #{$link->id})" );

    // Alert
    lcrm_create_alert_deduped(
        $link->lead_id, 'payment_confirmed', 'high',
        "💰 Payment received: ₹" . number_format( $amount, 0 ) . " from {$lead->client_name}", 1
    );

    // Send WA receipt
    if ( $lead->client_phone ) {
        $receipt_msg = "Hello {$lead->client_name} ji! 🎉\n\n"
                     . "We have received your payment of ₹" . number_format( $amount, 0 ) . ".\n"
                     . "Service: {$lead->work_type}\n\n"
                     . "Thank you! Your work will be processed shortly.\n"
                     . get_bloginfo( 'name' );
        $account_id = lcrm_wa_find_account_for_lead( $lead );
        if ( $account_id ) {
            lcrm_wa_send_message( $account_id, $lead->client_phone, $receipt_msg );
            lcrm_add_conversation( $link->lead_id, "[Payment Receipt Sent] ₹" . number_format( $amount, 0 ) );
        }
    }
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_payment_create_link',   'lcrm_ajax_payment_create_link' );
add_action( 'wp_ajax_lcrm_payment_get_links',     'lcrm_ajax_payment_get_links' );
add_action( 'wp_ajax_lcrm_payment_resend_wa',     'lcrm_ajax_payment_resend_wa' );
add_action( 'wp_ajax_lcrm_payment_save_settings', 'lcrm_ajax_payment_save_settings' );
add_action( 'wp_ajax_lcrm_payment_manual_record', 'lcrm_ajax_payment_manual_record' );

// TRACE: Creates a payment link from the lead panel → validates amount → calls lcrm_payments_create_link.
//        Returns {link_id, short_url, gateway}.
//        EDGE CASES: Amount ≤ 0 → error; gateway API failure → error returned to JS.
function lcrm_ajax_payment_create_link() {
    lcrm_verify();
    $lead_id     = intval( $_POST['lead_id']     ?? 0 );
    $amount      = floatval( $_POST['amount']     ?? 0 );
    $description = sanitize_text_field( $_POST['description'] ?? '' );
    $send_wa     = intval( $_POST['send_wa']      ?? 1 );

    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );
    if ( $amount <= 0 ) wp_send_json_error( [ 'msg' => 'Please enter a valid amount.' ] );

    $result = lcrm_payments_create_link( $lead_id, $amount, $description, (bool) $send_wa );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'msg' => $result->get_error_message() ] );

    wp_send_json_success( $result );
}

// TRACE: Returns all payment links for a lead in reverse chronological order.
function lcrm_ajax_payment_get_links() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $links = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.*, u.display_name AS created_by_name
         FROM {$wpdb->prefix}lcrm_payment_links p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.created_by
         WHERE p.lead_id = %d
         ORDER BY p.created_at DESC",
        $lead_id
    ) );

    wp_send_json_success( [ 'links' => array_map( fn($l) => [
        'id'          => (int) $l->id,
        'gateway'     => $l->gateway,
        'amount'      => floatval( $l->amount ),
        'amount_fmt'  => '₹' . number_format( $l->amount, 0 ),
        'description' => $l->description,
        'short_url'   => $l->short_url,
        'status'      => $l->status,
        'wa_sent'     => (bool) $l->wa_sent,
        'paid_amount' => floatval( $l->paid_amount ),
        'paid_at'     => $l->paid_at,
        'created_at'  => $l->created_at,
        'created_by'  => $l->created_by_name ?: 'System',
    ], (array) $links ) ] );
}

// TRACE: Resends a payment link via WA for an existing link record.
//        PRECONDITIONS: link_id valid; lead has phone number.
//        POSTCONDITIONS: WA message sent; wa_sent flag set to 1.
function lcrm_ajax_payment_resend_wa() {
    lcrm_verify();
    global $wpdb;
    $link_id = intval( $_POST['link_id'] ?? 0 );
    if ( ! $link_id ) wp_send_json_error( [ 'msg' => 'Link ID required.' ] );

    $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lcrm_payment_links WHERE id=%d", $link_id ) );
    if ( ! $link ) wp_send_json_error( [ 'msg' => 'Link not found.' ] );

    $lead = lcrm_get_lead( (int) $link->lead_id );
    if ( ! $lead || ! $lead->client_phone ) wp_send_json_error( [ 'msg' => 'Lead has no phone number.' ] );

    $msg = lcrm_payments_build_wa_message( $lead, $link->amount, $link->short_url, $link->description );
    $account_id = lcrm_wa_find_account_for_lead( $lead );
    if ( ! $account_id ) wp_send_json_error( [ 'msg' => 'No WhatsApp account available.' ] );

    $result = lcrm_wa_send_message( $account_id, $lead->client_phone, $msg );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'msg' => $result->get_error_message() ] );

    $wpdb->update( "{$wpdb->prefix}lcrm_payment_links", [ 'wa_sent' => 1, 'status' => 'sent' ], [ 'id' => $link_id ] );
    lcrm_add_conversation( $link->lead_id, "[Payment Link Resent via WA] ₹" . number_format( $link->amount, 0 ) );

    wp_send_json_success( [ 'msg' => 'Payment link resent.' ] );
}

// TRACE: Saves payment gateway settings from the settings UI.
//        POSTCONDITIONS: All gateway wp_options updated.
function lcrm_ajax_payment_save_settings() {
    lcrm_verify_admin();
    $gateway = sanitize_text_field( $_POST['payment_gateway'] ?? 'upi' );
    if ( ! in_array( $gateway, ['razorpay','cashfree','upi'], true ) ) $gateway = 'upi';

    update_option( 'lcrm_payment_gateway',           $gateway );
    update_option( 'lcrm_razorpay_key_id',           sanitize_text_field( $_POST['razorpay_key_id']           ?? '' ) );
    update_option( 'lcrm_razorpay_key_secret',       sanitize_text_field( $_POST['razorpay_key_secret']       ?? '' ) );
    update_option( 'lcrm_razorpay_webhook_secret',   sanitize_text_field( $_POST['razorpay_webhook_secret']   ?? '' ) );
    update_option( 'lcrm_cashfree_client_id',        sanitize_text_field( $_POST['cashfree_client_id']        ?? '' ) );
    update_option( 'lcrm_cashfree_client_secret',    sanitize_text_field( $_POST['cashfree_client_secret']    ?? '' ) );
    update_option( 'lcrm_upi_id',                    sanitize_text_field( $_POST['upi_id']                    ?? '' ) );
    update_option( 'lcrm_upi_name',                  sanitize_text_field( $_POST['upi_name']                  ?? '' ) );

    wp_send_json_success( [ 'msg' => 'Payment settings saved.' ] );
}

// TRACE: Records a manual offline payment (cash, bank transfer) against a lead.
//        PRECONDITIONS: amount > 0; lead_id valid.
//        POSTCONDITIONS: Lead pmt_received += amount; balance_payment decremented; activity logged.
//        EDGE CASES: balance_payment cannot go below 0 (GREATEST check).
function lcrm_ajax_payment_manual_record() {
    lcrm_verify();
    global $wpdb;
    $lead_id     = intval( $_POST['lead_id']     ?? 0 );
    $amount      = floatval( $_POST['amount']     ?? 0 );
    $method      = sanitize_text_field( $_POST['method']      ?? 'Cash' );
    $description = sanitize_text_field( $_POST['description'] ?? '' );

    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );
    if ( $amount <= 0 ) wp_send_json_error( [ 'msg' => 'Amount must be greater than 0.' ] );

    $lead = lcrm_get_lead( $lead_id );
    if ( ! $lead ) wp_send_json_error( [ 'msg' => 'Lead not found.' ] );

    $result = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_leads
         SET pmt_received    = pmt_received + %f,
             balance_payment = GREATEST(0, balance_payment - %f)
         WHERE id = %d",
        $amount, $amount, $lead_id
    ) );

    if ( $result === false ) wp_send_json_error( [ 'msg' => 'Database error: ' . $wpdb->last_error ] );

    // Insert into payment_links as a manual record
    $wpdb->insert( "{$wpdb->prefix}lcrm_payment_links", [
        'lead_id'     => $lead_id,
        'gateway'     => 'upi',
        'amount'      => $amount,
        'description' => $description ?: "Manual payment ({$method})",
        'short_url'   => '',
        'status'      => 'paid',
        'paid_at'     => current_time( 'mysql' ),
        'paid_amount' => $amount,
        'created_by'  => get_current_user_id(),
    ] );

    lcrm_analytics_record_payment( $lead_id, $amount, get_current_user_id() );
    lcrm_log_activity( $lead_id, 'payment_manual',
        "Manual payment recorded: ₹" . number_format( $amount, 0 ) . " ({$method})" . ( $description ? " — {$description}" : '' )
    );
    lcrm_create_alert_deduped( $lead_id, 'payment_manual', 'medium',
        "Manual payment recorded: ₹" . number_format( $amount, 0 ), 1 );

    wp_send_json_success( [ 'msg' => "Payment of ₹" . number_format( $amount, 0 ) . " recorded." ] );
}
