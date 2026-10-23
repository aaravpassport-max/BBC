<?php
/**
 * LeadCRM WhatsApp — AJAX Handlers
 * All actions prefixed lcrm_wa_ to avoid collision with existing lcrm_ handlers.
 * Every handler uses the existing lcrm_verify() / lcrm_verify_admin() pattern.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── WA ACCOUNT MANAGEMENT ───────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_save_account',   'lcrm_wa_ajax_save_account' );
add_action( 'wp_ajax_lcrm_wa_delete_account', 'lcrm_wa_ajax_delete_account' );
add_action( 'wp_ajax_lcrm_wa_get_accounts',   'lcrm_wa_ajax_get_accounts' );

function lcrm_wa_ajax_get_accounts() {
    lcrm_verify_admin();
    $accounts = lcrm_wa_get_all_accounts();
    wp_send_json_success( [ 'accounts' => $accounts ] );
}

function lcrm_wa_ajax_save_account() {
    lcrm_verify_admin();
    global $wpdb;

    $id          = intval( $_POST['id'] ?? 0 );
    $brand_id    = intval( $_POST['brand_id'] ?? 0 );
    $wa_number   = sanitize_text_field( $_POST['wa_number'] ?? '' );
    $display     = sanitize_text_field( $_POST['display_name'] ?? '' );
    $platform    = in_array( $_POST['platform'] ?? '', [ 'cloud_api', 'generic' ], true )
                   ? $_POST['platform'] : 'cloud_api';
    $phone_id    = sanitize_text_field( $_POST['phone_number_id'] ?? '' );
    $token       = sanitize_text_field( $_POST['api_token'] ?? '' );
    $verify_tok  = sanitize_text_field( $_POST['webhook_verify_token'] ?? '' );
    $is_active   = intval( $_POST['is_active'] ?? 1 );

    if ( ! $wa_number || ! $brand_id ) {
        wp_send_json_error( [ 'msg' => 'WhatsApp number and Brand are required.' ] );
    }

    $data = [
        'brand_id'             => $brand_id,
        'wa_number'            => $wa_number,
        'display_name'         => $display ?: $wa_number,
        'platform'             => $platform,
        'phone_number_id'      => $phone_id,
        'api_token'            => $token,
        'webhook_verify_token' => $verify_tok ?: wp_generate_password( 24, false ),
        'is_active'            => $is_active,
    ];

    if ( $id ) {
        // Don't overwrite token if blank was sent (means "keep existing")
        if ( ! $token ) unset( $data['api_token'] );
        $result = $wpdb->update( "{$wpdb->prefix}lcrm_wa_accounts", $data, [ 'id' => $id ] );
    } else {
        $result = $wpdb->insert( "{$wpdb->prefix}lcrm_wa_accounts", $data );
        $id     = (int) $wpdb->insert_id;
    }

    if ( $result === false ) {
        wp_send_json_error( [ 'msg' => 'Save failed: ' . $wpdb->last_error ] );
    }

    $account = lcrm_wa_get_account( $id );
    wp_send_json_success( [ 'account' => $account ] );
}

function lcrm_wa_ajax_delete_account() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Invalid ID.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_wa_accounts", [ 'id' => $id ] );
    wp_send_json_success();
}

// ─── PENDING REVIEW QUEUE ────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_get_pending',    'lcrm_wa_ajax_get_pending' );
add_action( 'wp_ajax_lcrm_wa_approve_event',  'lcrm_wa_ajax_approve_event' );
add_action( 'wp_ajax_lcrm_wa_reject_event',   'lcrm_wa_ajax_reject_event' );
add_action( 'wp_ajax_lcrm_wa_count_pending',  'lcrm_wa_ajax_count_pending' );

function lcrm_wa_ajax_count_pending() {
    lcrm_verify();
    global $wpdb;
    $count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_wa_events WHERE capture_status='pending_review'"
    );
    wp_send_json_success( [ 'count' => $count ] );
}

function lcrm_wa_ajax_get_pending() {
    lcrm_verify();
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT e.*, a.display_name AS account_name, a.wa_number AS account_number,
                b.name AS brand_name
         FROM {$wpdb->prefix}lcrm_wa_events e
         LEFT JOIN {$wpdb->prefix}lcrm_wa_accounts a ON a.id = e.wa_account_id
         LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id = a.brand_id
         WHERE e.capture_status = 'pending_review'
         ORDER BY e.event_time DESC LIMIT 100"
    );
    wp_send_json_success( [ 'events' => $rows ] );
}

function lcrm_wa_ajax_approve_event() {
    lcrm_verify();
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( ! $event_id ) wp_send_json_error( [ 'msg' => 'Invalid event.' ] );

    // Allow passing a custom name to override the WA+number default
    $custom_name = sanitize_text_field( $_POST['client_name'] ?? '' );

    $ok = lcrm_wa_approve_event( $event_id );
    if ( ! $ok ) wp_send_json_error( [ 'msg' => 'Approval failed or already processed.' ] );

    // Apply custom name if provided
    if ( $custom_name ) {
        global $wpdb;
        // Find the lead just created (phone match)
        $ev = $wpdb->get_row( $wpdb->prepare(
            "SELECT from_number FROM {$wpdb->prefix}lcrm_wa_events WHERE id=%d", $event_id
        ) );
        if ( $ev ) {
            $lead_id = lcrm_wa_find_lead_by_phone( $ev->from_number );
            if ( $lead_id ) {
                $wpdb->update( "{$wpdb->prefix}lcrm_leads",
                    [ 'client_name' => $custom_name ],
                    [ 'id' => $lead_id ]
                );
            }
        }
    }

    wp_send_json_success( [ 'msg' => 'Lead created from WhatsApp message.' ] );
}

function lcrm_wa_ajax_reject_event() {
    lcrm_verify();
    global $wpdb;
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( ! $event_id ) wp_send_json_error( [ 'msg' => 'Invalid event.' ] );
    $wpdb->update(
        "{$wpdb->prefix}lcrm_wa_events",
        [ 'capture_status' => 'rejected' ],
        [ 'id' => $event_id ]
    );
    wp_send_json_success();
}

// ─── WA EVENTS PER LEAD ──────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_get_lead_events', 'lcrm_wa_ajax_get_lead_events' );
add_action( 'wp_ajax_lcrm_wa_mark_read',       'lcrm_wa_ajax_mark_read' );

function lcrm_wa_ajax_get_lead_events() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Invalid lead.' ] );

    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT e.*, a.display_name AS account_name
         FROM {$wpdb->prefix}lcrm_wa_events e
         LEFT JOIN {$wpdb->prefix}lcrm_wa_accounts a ON a.id = e.wa_account_id
         WHERE e.lead_id = %d
         ORDER BY e.event_time ASC LIMIT 200",
        $lead_id
    ) );
    wp_send_json_success( [ 'events' => $events ] );
}

function lcrm_wa_ajax_mark_read() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Invalid lead.' ] );
    $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'wa_unread_count' => 0 ], [ 'id' => $lead_id ] );
    $wpdb->update( "{$wpdb->prefix}lcrm_wa_events", [ 'is_read' => 1 ],
        [ 'lead_id' => $lead_id, 'is_read' => 0 ] );
    wp_send_json_success();
}

// ─── CALL LOGS ───────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_log_call',      'lcrm_wa_ajax_log_call' );
add_action( 'wp_ajax_lcrm_wa_get_call_logs', 'lcrm_wa_ajax_get_call_logs' );

function lcrm_wa_ajax_log_call() {
    lcrm_verify();
    global $wpdb;
    $lead_id     = intval( $_POST['lead_id'] ?? 0 );
    $call_type   = in_array( $_POST['call_type'] ?? '', [ 'incoming', 'outgoing', 'missed' ], true )
                   ? $_POST['call_type'] : 'incoming';
    $duration    = intval( $_POST['duration_sec'] ?? 0 );
    $phone       = sanitize_text_field( $_POST['phone'] ?? '' );
    $notes       = sanitize_textarea_field( $_POST['notes'] ?? '' );
    $call_at     = sanitize_text_field( $_POST['call_at'] ?? current_time( 'mysql' ) );
    $mk_reminder = intval( $_POST['make_reminder'] ?? 0 );

    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $wpdb->insert( "{$wpdb->prefix}lcrm_call_logs", [
        'lead_id'          => $lead_id,
        'wa_account_id'    => null,
        'call_type'        => $call_type,
        'duration_sec'     => $duration,
        'phone_number'     => $phone,
        'call_at'          => $call_at,
        'notes'            => $notes,
        'created_by'       => get_current_user_id(),
        'reminder_created' => $mk_reminder ? 1 : 0,
    ] );

    // Log to activity timeline
    $u     = wp_get_current_user();
    $label = ucfirst( $call_type ) . ' call';
    if ( $duration ) $label .= ' (' . gmdate( 'i:s', $duration ) . ')';
    if ( $notes )    $label .= ' — ' . mb_substr( $notes, 0, 80 );
    lcrm_log_activity( $lead_id, 'call_' . $call_type, $label );

    // Update last_client_contact
    $wpdb->update( "{$wpdb->prefix}lcrm_leads",
        [ 'last_client_contact' => $call_at ],
        [ 'id' => $lead_id ]
    );

    // Create follow-up reminder if requested
    $reminder_id = null;
    if ( $mk_reminder ) {
        $remind_at = date( 'Y-m-d H:i:s', strtotime( $call_at ) + 86400 ); // +24h
        $wpdb->insert( "{$wpdb->prefix}lcrm_reminders", [
            'lead_id'    => $lead_id,
            'remind_at'  => $remind_at,
            'note'       => 'Callback after ' . $call_type . ' call on ' . date( 'd M Y', strtotime( $call_at ) ),
            'sent'       => 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
        ] );
        $reminder_id = (int) $wpdb->insert_id;
        $wpdb->update( "{$wpdb->prefix}lcrm_call_logs",
            [ 'reminder_created' => 1 ],
            [ 'id' => (int) $wpdb->insert_id - 1 ] // last inserted call log
        );
    }

    wp_send_json_success( [ 'reminder_id' => $reminder_id ] );
}

function lcrm_wa_ajax_get_call_logs() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT cl.*, u.display_name AS created_by_name
         FROM {$wpdb->prefix}lcrm_call_logs cl
         LEFT JOIN {$wpdb->users} u ON u.ID = cl.created_by
         WHERE cl.lead_id = %d
         ORDER BY cl.call_at DESC LIMIT 100",
        $lead_id
    ) );
    wp_send_json_success( [ 'logs' => $logs ] );
}

// ─── BLOCKLIST MANAGEMENT ─────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_get_blocklist',    'lcrm_wa_ajax_get_blocklist' );
add_action( 'wp_ajax_lcrm_wa_save_blocklist',   'lcrm_wa_ajax_save_blocklist' );
add_action( 'wp_ajax_lcrm_wa_blocklist_number', 'lcrm_wa_ajax_blocklist_number' );

function lcrm_wa_ajax_get_blocklist() {
    lcrm_verify_admin();
    $list = (array) get_option( 'lcrm_wa_blocklist', [] );
    wp_send_json_success( [ 'list' => $list ] );
}

function lcrm_wa_ajax_save_blocklist() {
    lcrm_verify_admin();
    $raw  = sanitize_textarea_field( $_POST['numbers'] ?? '' );
    $list = array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $raw ) ) );
    update_option( 'lcrm_wa_blocklist', array_values( $list ) );
    wp_send_json_success( [ 'count' => count( $list ) ] );
}

function lcrm_wa_ajax_blocklist_number() {
    lcrm_verify();
    $phone = sanitize_text_field( $_POST['phone'] ?? '' );
    if ( ! $phone ) wp_send_json_error( [ 'msg' => 'Phone required.' ] );
    $list   = (array) get_option( 'lcrm_wa_blocklist', [] );
    $list[] = $phone;
    $list   = array_unique( $list );
    update_option( 'lcrm_wa_blocklist', $list );
    wp_send_json_success();
}

// ─── SETTINGS SAVE ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_save_settings', 'lcrm_wa_ajax_save_settings' );

function lcrm_wa_ajax_save_settings() {
    lcrm_verify_admin();
    $mode       = in_array( $_POST['capture_mode'] ?? '', [ 'smart', 'all', 'manual' ], true )
                  ? $_POST['capture_mode'] : 'smart';
    $threshold  = intval( $_POST['capture_threshold'] ?? 2 );
    $delay_mins = intval( $_POST['missed_call_reminder_mins'] ?? 60 );

    update_option( 'lcrm_wa_capture_mode',              $mode );
    update_option( 'lcrm_wa_capture_threshold',         max( 0, min( 8, $threshold ) ) );
    update_option( 'lcrm_wa_missed_call_reminder_mins', max( 5, min( 1440, $delay_mins ) ) );
    wp_send_json_success( [ 'msg' => 'Settings saved.' ] );
}

// ─── NO-REPLY AUTOMATION RULE (hooked into automation.php engine) ─────────────

add_filter( 'lcrm_automation_extra_rules', 'lcrm_wa_no_reply_rule' );
function lcrm_wa_no_reply_rule( $leads_processed ) {
    global $wpdb;
    $hours = (int) get_option( 'lcrm_wa_no_reply_hours', 24 );
    $cutoff = date( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

    // Leads that have an inbound WA message but no outbound WA event since
    $leads = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT e.lead_id
         FROM {$wpdb->prefix}lcrm_wa_events e
         WHERE e.direction = 'inbound'
           AND e.event_type = 'message'
           AND e.event_time < %s
           AND e.lead_id IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}lcrm_wa_events o
               WHERE o.lead_id = e.lead_id
                 AND o.direction = 'outbound'
                 AND o.event_time > e.event_time
           )
         LIMIT 50",
        $cutoff
    ) );

    foreach ( $leads as $row ) {
        if ( function_exists( 'lcrm_create_alert_deduped' ) ) {
            lcrm_create_alert_deduped(
                $row->lead_id,
                'wa_no_reply',
                'high',
                'WhatsApp message has no reply for ' . $hours . '+ hours — follow up now.',
                $hours // cooldown same as no-reply window
            );
        }
    }
    return $leads_processed;
}
