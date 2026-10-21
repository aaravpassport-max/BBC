<?php
/**
 * LeadCRM Outbound Webhooks Module
 * Fires user-configured webhook URLs when CRM events occur.
 * Enables integration with Zapier, Make, custom backends, Google Sheets, etc.
 *
 * Events supported:
 *   lead_created, lead_updated, stage_changed, payment_received,
 *   lead_won, lead_lost, alert_created, portal_message
 *
 * Payload format: JSON POST with X-LCRM-Signature HMAC header.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB ──────────────────────────────────────────────────────────────────────

// TRACE: Creates lcrm_webhooks table for webhook configurations.
//        POSTCONDITIONS: Table exists. EDGE CASES: Idempotent.
function lcrm_webhooks_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_webhooks (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name         VARCHAR(150)    NOT NULL DEFAULT '',
        url          VARCHAR(500)    NOT NULL DEFAULT '',
        events       TEXT            NOT NULL DEFAULT '',
        secret       VARCHAR(100)    NOT NULL DEFAULT '',
        is_active    TINYINT(1)      NOT NULL DEFAULT 1,
        last_fired_at DATETIME       DEFAULT NULL,
        last_status  SMALLINT        NOT NULL DEFAULT 0,
        fire_count   INT             NOT NULL DEFAULT 0,
        fail_count   INT             NOT NULL DEFAULT 0,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;" );
}

// ─── SUPPORTED EVENTS ────────────────────────────────────────────────────────

// TRACE: Returns the canonical list of webhook event names.
//        Used for settings UI checkboxes and event validation.
function lcrm_webhooks_get_events() {
    return [
        'lead_created'     => 'Lead Created',
        'lead_updated'     => 'Lead Updated',
        'stage_changed'    => 'Stage Changed',
        'payment_received' => 'Payment Received',
        'lead_won'         => 'Lead Won',
        'lead_lost'        => 'Lead Lost',
        'alert_created'    => 'Alert Created (high priority)',
        'portal_message'   => 'Client Portal Message',
    ];
}

// ─── FIRE ENGINE ─────────────────────────────────────────────────────────────

// TRACE: Main dispatch function — called from anywhere to fire all webhooks for an event.
//        Loads active webhooks subscribed to $event → builds payload → calls lcrm_webhooks_fire_one().
//        PRECONDITIONS: $event is one of lcrm_webhooks_get_events() keys.
//        POSTCONDITIONS: HTTP POSTs sent; webhook stats updated.
//        EDGE CASES: No webhooks for event → returns immediately; async via wp_schedule_single_event.
function lcrm_webhooks_fire( $event, $data ) {
    global $wpdb;
    $event = sanitize_key( $event );
    $valid_events = array_keys( lcrm_webhooks_get_events() );
    if ( ! in_array( $event, $valid_events, true ) ) return;

    $webhooks = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_webhooks WHERE is_active=1 AND events LIKE %s",
        '%' . $wpdb->esc_like( $event ) . '%'
    ) );
    if ( empty( $webhooks ) ) return;

    // Build standardised payload
    $payload = [
        'event'      => $event,
        'fired_at'   => current_time( 'c' ),
        'site_url'   => home_url(),
        'data'       => $data,
    ];

    foreach ( $webhooks as $webhook ) {
        // Double-check this webhook is subscribed to this exact event
        $subscribed = json_decode( $webhook->events, true );
        if ( ! is_array( $subscribed ) || ! in_array( $event, $subscribed, true ) ) continue;

        lcrm_webhooks_fire_one( $webhook, $payload );
    }
}

// TRACE: Fires a single webhook → signs payload with HMAC-SHA256 →
//        POSTs JSON to webhook URL → updates webhook stats (last_fired_at, last_status, counts).
//        PRECONDITIONS: $webhook has url and secret fields.
//        POSTCONDITIONS: HTTP POST sent; DB stats updated.
//        EDGE CASES: URL unreachable → WP HTTP error logged; non-2xx response → fail_count++.
function lcrm_webhooks_fire_one( $webhook, $payload ) {
    global $wpdb;
    $body      = wp_json_encode( $payload );
    $signature = hash_hmac( 'sha256', $body, $webhook->secret );

    $response = wp_remote_post( $webhook->url, [
        'headers' => [
            'Content-Type'       => 'application/json',
            'X-LCRM-Signature'   => $signature,
            'X-LCRM-Event'       => $payload['event'],
            'User-Agent'         => 'LeadCRM-Webhook/4.0',
        ],
        'body'    => $body,
        'timeout' => 10,
    ] );

    $status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    $success     = $status_code >= 200 && $status_code < 300;

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_webhooks
         SET last_fired_at = %s,
             last_status   = %d,
             fire_count    = fire_count + 1,
             fail_count    = fail_count + IF(%d=0, 1, 0)
         WHERE id = %d",
        current_time( 'mysql' ), $status_code,
        $success ? 1 : 0,
        (int) $webhook->id
    ) );

    if ( ! $success ) {
        $err = is_wp_error( $response ) ? $response->get_error_message() : "HTTP {$status_code}";
        error_log( "LeadCRM webhook #{$webhook->id} ({$webhook->name}) failed: {$err}" );
    }
}

// ─── HOOK INTO CRM EVENTS ────────────────────────────────────────────────────

// TRACE: Hooks into lcrm_create_lead — fires 'lead_created' webhook with sanitised lead data.
add_action( 'lcrm_after_create_lead', 'lcrm_webhooks_on_lead_created', 10, 2 );
function lcrm_webhooks_on_lead_created( $lead_id, $data ) {
    $lead = lcrm_get_lead( (int) $lead_id );
    if ( ! $lead ) return;
    lcrm_webhooks_fire( 'lead_created', lcrm_webhooks_lead_payload( $lead ) );
}

// TRACE: Hooks into lcrm_update_lead — fires 'lead_updated' or 'stage_changed'/'lead_won'/'lead_lost'.
add_action( 'lcrm_after_update_lead', 'lcrm_webhooks_on_lead_updated', 10, 3 );
function lcrm_webhooks_on_lead_updated( $lead_id, $new_data, $old_lead ) {
    $lead = lcrm_get_lead( (int) $lead_id );
    if ( ! $lead ) return;

    $payload = lcrm_webhooks_lead_payload( $lead );

    // Always fire generic update
    lcrm_webhooks_fire( 'lead_updated', $payload );

    // Check stage change
    if ( isset( $new_data['stage_id'] ) && (int) $new_data['stage_id'] !== (int) $old_lead->stage_id ) {
        $old_stage = lcrm_get_stage( (int) $old_lead->stage_id );
        $new_stage = lcrm_get_stage( (int) $new_data['stage_id'] );

        $stage_payload = array_merge( $payload, [
            'from_stage' => $old_stage ? $old_stage->name : '',
            'to_stage'   => $new_stage ? $new_stage->name : '',
        ] );

        lcrm_webhooks_fire( 'stage_changed', $stage_payload );

        // Won / Lost detection
        $won_patterns  = ['Payment Received', 'Work Completed', 'Delivered'];
        $lost_patterns = ['Lost', 'Not Able to Serve'];

        if ( $new_stage ) {
            foreach ( $won_patterns as $p ) {
                if ( stripos( $new_stage->name, $p ) !== false ) {
                    lcrm_webhooks_fire( 'lead_won', $stage_payload ); break;
                }
            }
            foreach ( $lost_patterns as $p ) {
                if ( stripos( $new_stage->name, $p ) !== false ) {
                    lcrm_webhooks_fire( 'lead_lost', $stage_payload ); break;
                }
            }
        }

        // Record in analytics
        lcrm_analytics_record_stage_change(
            $lead_id,
            $old_stage ? $old_stage->name : '',
            $new_stage ? $new_stage->name : '',
            get_current_user_id()
        );
    }

    // Payment received
    if ( isset( $new_data['pmt_received'] ) && floatval( $new_data['pmt_received'] ) > floatval( $old_lead->pmt_received ) ) {
        $diff = floatval( $new_data['pmt_received'] ) - floatval( $old_lead->pmt_received );
        lcrm_webhooks_fire( 'payment_received', array_merge( $payload, [ 'amount_received' => $diff ] ) );
    }
}

// TRACE: Hooks into lcrm_create_alert_deduped — fires 'alert_created' for high-priority alerts.
add_action( 'lcrm_after_create_alert', 'lcrm_webhooks_on_alert_created', 10, 3 );
function lcrm_webhooks_on_alert_created( $lead_id, $priority, $message ) {
    if ( $priority !== 'high' ) return;
    $lead = lcrm_get_lead( (int) $lead_id );
    lcrm_webhooks_fire( 'alert_created', [
        'lead_id'     => (int) $lead_id,
        'client_name' => $lead ? $lead->client_name : '',
        'priority'    => $priority,
        'message'     => $message,
    ] );
}

// TRACE: Fired from portal send_message → fires 'portal_message' webhook.
add_action( 'lcrm_after_portal_message', 'lcrm_webhooks_on_portal_message', 10, 2 );
function lcrm_webhooks_on_portal_message( $lead_id, $message ) {
    $lead = lcrm_get_lead( (int) $lead_id );
    lcrm_webhooks_fire( 'portal_message', [
        'lead_id'     => (int) $lead_id,
        'client_name' => $lead ? $lead->client_name : '',
        'message'     => mb_substr( $message, 0, 500 ),
    ] );
}

// TRACE: Builds a standardised lead payload for webhook delivery.
//        Excludes internal financial details unless explicitly included.
//        POSTCONDITIONS: Returns array with all public lead fields.
function lcrm_webhooks_lead_payload( $lead ) {
    return [
        'lead_id'     => (int) $lead->id,
        'client_name' => $lead->client_name,
        'client_phone'=> $lead->client_phone,
        'client_email'=> $lead->client_email,
        'work_type'   => $lead->work_type,
        'lead_source' => $lead->lead_source,
        'stage'       => $lead->stage_name ?? '',
        'temperature' => $lead->lead_temperature,
        'priority'    => $lead->priority,
        'assigned_to' => (int) $lead->assigned_to,
        'lead_date'   => $lead->lead_date,
        'created_at'  => $lead->created_at,
    ];
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_webhooks_save',   'lcrm_ajax_webhooks_save' );
add_action( 'wp_ajax_lcrm_webhooks_delete', 'lcrm_ajax_webhooks_delete' );
add_action( 'wp_ajax_lcrm_webhooks_list',   'lcrm_ajax_webhooks_list' );
add_action( 'wp_ajax_lcrm_webhooks_test',   'lcrm_ajax_webhooks_test' );

// TRACE: Saves or updates a webhook configuration.
//        PRECONDITIONS: Admin; valid URL; at least one event selected.
//        POSTCONDITIONS: Webhook row in DB; auto-generates secret if blank.
//        EDGE CASES: Invalid URL → error; no events → error.
function lcrm_ajax_webhooks_save() {
    lcrm_verify_admin();
    global $wpdb;

    $id       = intval( $_POST['id']       ?? 0 );
    $name     = sanitize_text_field( $_POST['name']   ?? '' );
    $url      = esc_url_raw( $_POST['url']            ?? '' );
    $events   = array_filter( array_map( 'sanitize_key', (array) ( $_POST['events'] ?? [] ) ) );
    $secret   = sanitize_text_field( $_POST['secret'] ?? '' ) ?: wp_generate_password( 32, false );
    $is_active= intval( $_POST['is_active'] ?? 1 );

    if ( ! $name ) wp_send_json_error( [ 'msg' => 'Webhook name required.' ] );
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) wp_send_json_error( [ 'msg' => 'Valid URL required.' ] );
    if ( empty( $events ) ) wp_send_json_error( [ 'msg' => 'Select at least one event.' ] );

    $valid_events = array_keys( lcrm_webhooks_get_events() );
    $events       = array_values( array_intersect( $events, $valid_events ) );
    if ( empty( $events ) ) wp_send_json_error( [ 'msg' => 'Invalid event selected.' ] );

    $data = [
        'name'      => $name,
        'url'       => $url,
        'events'    => wp_json_encode( $events ),
        'secret'    => $secret,
        'is_active' => $is_active,
    ];

    if ( $id ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_webhooks", $data, [ 'id' => $id ] );
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}lcrm_webhooks", $data );
        $id = (int) $wpdb->insert_id;
    }

    wp_send_json_success( [ 'msg' => 'Webhook saved.', 'id' => $id, 'secret' => $secret ] );
}

// TRACE: Deletes a webhook permanently.
function lcrm_ajax_webhooks_delete() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Webhook ID required.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_webhooks", [ 'id' => $id ] );
    wp_send_json_success( [ 'msg' => 'Webhook deleted.' ] );
}

// TRACE: Returns all webhooks for the settings UI.
function lcrm_ajax_webhooks_list() {
    lcrm_verify_admin();
    global $wpdb;
    $webhooks = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}lcrm_webhooks ORDER BY name ASC" );
    wp_send_json_success( [ 'webhooks' => array_map( fn($w) => [
        'id'           => (int) $w->id,
        'name'         => $w->name,
        'url'          => $w->url,
        'events'       => json_decode( $w->events, true ) ?: [],
        'is_active'    => (bool) $w->is_active,
        'last_fired'   => $w->last_fired_at,
        'last_status'  => (int) $w->last_status,
        'fire_count'   => (int) $w->fire_count,
        'fail_count'   => (int) $w->fail_count,
    ], (array) $webhooks ) ] );
}

// TRACE: Sends a test webhook payload to verify connectivity.
//        POSTCONDITIONS: Test payload sent; response code returned to UI.
//        EDGE CASES: Unreachable URL → error message with HTTP error.
function lcrm_ajax_webhooks_test() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Webhook ID required.' ] );

    $webhook = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lcrm_webhooks WHERE id=%d", $id ) );
    if ( ! $webhook ) wp_send_json_error( [ 'msg' => 'Webhook not found.' ] );

    $payload = [
        'event'    => 'test',
        'fired_at' => current_time( 'c' ),
        'site_url' => home_url(),
        'data'     => [ 'message' => 'This is a test payload from LeadCRM v4.' ],
    ];

    $body      = wp_json_encode( $payload );
    $signature = hash_hmac( 'sha256', $body, $webhook->secret );

    $response = wp_remote_post( $webhook->url, [
        'headers' => [
            'Content-Type'     => 'application/json',
            'X-LCRM-Signature' => $signature,
            'X-LCRM-Event'     => 'test',
        ],
        'body'    => $body,
        'timeout' => 10,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'msg' => 'Connection failed: ' . $response->get_error_message() ] );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        wp_send_json_success( [ 'msg' => "Test delivered. Server responded with HTTP {$code}." ] );
    } else {
        wp_send_json_error( [ 'msg' => "Server responded with HTTP {$code}. Check the endpoint URL." ] );
    }
}

// ─── ACTION ALIASES ──────────────────────────────────────────────────────────
// helpers.php fires 'lcrm_lead_created' and 'lcrm_lead_updated'.
// We listen on those and re-dispatch to our webhook functions.

add_action( 'lcrm_lead_created', function( $lead_id, $data ) {
    lcrm_webhooks_on_lead_created( $lead_id, $data );
}, 10, 2 );

add_action( 'lcrm_lead_updated', function( $lead_id, $new_data, $old_lead ) {
    lcrm_webhooks_on_lead_updated( $lead_id, $new_data, $old_lead );
}, 10, 3 );

// Wire alert webhook: automation.php's lcrm_create_alert_deduped returns bool.
// We hook via a custom action fired after insert.
add_action( 'lcrm_alert_created', function( $lead_id, $priority, $message ) {
    lcrm_webhooks_on_alert_created( $lead_id, $priority, $message );
}, 10, 3 );

// Wire portal message webhook
add_action( 'lcrm_portal_message_sent', function( $lead_id, $message ) {
    lcrm_webhooks_on_portal_message( $lead_id, $message );
}, 10, 2 );
