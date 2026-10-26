<?php
/**
 * LeadCRM Outbound WhatsApp Module
 * Sends WhatsApp messages outbound via Meta Cloud API.
 * Also handles all AI AJAX endpoints: reply suggestion, morning brief, manual AI actions.
 *
 * Functions:
 *   lcrm_wa_send_message( $account_id, $to, $message )    → bool|WP_Error
 *   lcrm_wa_send_template( $account_id, $to, $template )  → bool|WP_Error
 *
 * AJAX actions:
 *   lcrm_wa_send_reply       — send an outbound WA reply from the lead panel
 *   lcrm_ai_get_suggestion   — get AI reply suggestion for a lead
 *   lcrm_ai_get_brief        — get AI morning brief on demand
 *   lcrm_ai_save_settings    — save AI configuration
 *   lcrm_ai_test             — test AI API key connectivity
 *   lcrm_ai_transcribe_note  — transcribe a stored voice note attachment
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── OUTBOUND MESSAGE SENDER ─────────────────────────────────────────────────

/**
 * lcrm_wa_send_message( $account_id, $to_phone, $message )
 * Sends a text message via Meta Cloud API to a given phone number.
 * Returns true on success, WP_Error on failure.
 *
 * TRACE: Loads WA account → validates phone_number_id and api_token →
 *        POSTs to graph.facebook.com/v19.0/{phone_number_id}/messages →
 *        Checks response for wamid (success indicator) →
 *        Returns true or WP_Error.
 * PRECONDITIONS: Account must have platform='cloud_api', phone_number_id, api_token set.
 * POSTCONDITIONS: Message delivered to Meta for sending; not guaranteed delivered to device.
 * EDGE CASES: Invalid phone format → Meta returns 400 (returned as WP_Error); account not found → WP_Error; inactive account → WP_Error; no token → WP_Error.
 */
function lcrm_wa_send_message( $account_id, $to_phone, $message ) {
    $account = lcrm_wa_get_account( (int) $account_id );

    if ( ! $account ) return new WP_Error( 'no_account', 'WhatsApp account not found.' );
    if ( ! $account->is_active ) return new WP_Error( 'inactive', 'WhatsApp account is inactive.' );
    if ( $account->platform !== 'cloud_api' ) return new WP_Error( 'wrong_platform', 'Outbound only supported on Cloud API accounts.' );
    if ( ! $account->phone_number_id ) return new WP_Error( 'no_phone_id', 'Phone Number ID not configured.' );
    if ( ! $account->api_token ) return new WP_Error( 'no_token', 'API token not configured.' );

    // Normalize phone — strip non-digits, ensure no leading +
    $to = preg_replace( '/[^\d]/', '', $to_phone );
    if ( strlen( $to ) < 10 ) return new WP_Error( 'invalid_phone', 'Invalid phone number: ' . $to_phone );
    // Ensure country code for Indian numbers
    if ( strlen( $to ) === 10 ) $to = '91' . $to;

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'text',
        'text'              => [ 'preview_url' => false, 'body' => $message ],
    ];

    $response = wp_remote_post(
        "https://graph.facebook.com/v19.0/{$account->phone_number_id}/messages",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $account->api_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $data['messages'] ) ) {
        $err = $data['error']['message'] ?? "Meta API error {$code}";
        return new WP_Error( 'send_failed', $err );
    }

    return true;
}

/**
 * lcrm_wa_send_template( $account_id, $to_phone, $template_name, $lang_code, $components )
 * Sends a pre-approved WhatsApp template message.
 * Used for automated follow-ups and reminders.
 *
 * TRACE: Same account validation as lcrm_wa_send_message →
 *        Builds template payload →
 *        POSTs to Cloud API messages endpoint.
 * EDGE CASES: Template not approved → Meta returns 400; template name misspelled → WP_Error.
 */
function lcrm_wa_send_template( $account_id, $to_phone, $template_name, $lang_code = 'en', $components = [] ) {
    $account = lcrm_wa_get_account( (int) $account_id );
    if ( ! $account || ! $account->is_active || $account->platform !== 'cloud_api' ) {
        return new WP_Error( 'account_error', 'Invalid or inactive Cloud API account.' );
    }

    $to = preg_replace( '/[^\d]/', '', $to_phone );
    if ( strlen( $to ) === 10 ) $to = '91' . $to;

    $template_obj = [ 'name' => $template_name, 'language' => [ 'code' => $lang_code ] ];
    if ( ! empty( $components ) ) $template_obj['components'] = $components;

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => $template_obj,
    ];

    $response = wp_remote_post(
        "https://graph.facebook.com/v19.0/{$account->phone_number_id}/messages",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $account->api_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) return $response;
    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code !== 200 || empty( $data['messages'] ) ) {
        return new WP_Error( 'send_failed', $data['error']['message'] ?? "Meta API error {$code}" );
    }
    return true;
}

// ─── AJAX: SEND OUTBOUND REPLY ────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wa_send_reply', 'lcrm_ajax_wa_send_reply' );

/**
 * lcrm_ajax_wa_send_reply()
 * Sends an outbound WhatsApp reply from the lead detail panel.
 * Staff selects account, message is sent, and logged to conversation timeline.
 *
 * TRACE: Verifies nonce + login → loads lead → validates phone →
 *        Determines which WA account to use (from POST or lead's brand) →
 *        Calls lcrm_wa_send_message →
 *        Logs outbound message to lcrm_conversations + lcrm_wa_events →
 *        Returns success.
 * EDGE CASES: No phone on lead → error; send fails → returns error with Meta's message; account not found → error.
 */
function lcrm_ajax_wa_send_reply() {
    lcrm_verify();
    global $wpdb;

    $lead_id    = intval(    $_POST['lead_id']    ?? 0 );
    $account_id = intval(    $_POST['account_id'] ?? 0 );
    $message    = sanitize_textarea_field( $_POST['message'] ?? '' );

    if ( ! $lead_id )  wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );
    if ( ! $message )  wp_send_json_error( [ 'msg' => 'Message cannot be empty.' ] );

    $lead = lcrm_get_lead( $lead_id );
    if ( ! $lead ) wp_send_json_error( [ 'msg' => 'Lead not found.' ] );
    if ( ! $lead->client_phone ) wp_send_json_error( [ 'msg' => "This lead has no phone number. Add a phone number first." ] );

    // Determine account: use specified, or find by brand, or use first active
    if ( ! $account_id ) {
        $account_id = lcrm_wa_find_account_for_lead( $lead );
    }
    if ( ! $account_id ) wp_send_json_error( [ 'msg' => 'No active WhatsApp account found. Configure one in WA Settings.' ] );

    $result = lcrm_wa_send_message( $account_id, $lead->client_phone, $message );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'msg' => 'Send failed: ' . $result->get_error_message() ] );
    }

    // Log as outbound conversation entry
    $user     = wp_get_current_user();
    $log_msg  = "[WA Sent] " . mb_substr( $message, 0, 300 );
    lcrm_add_conversation( $lead_id, $log_msg, 'staff' );
    lcrm_log_activity( $lead_id, 'wa_sent', $log_msg );

    // Log to WA events table as outbound
    $account = lcrm_wa_get_account( $account_id );
    if ( $account ) {
        $wpdb->insert( "{$wpdb->prefix}lcrm_wa_events", [
            'lead_id'       => $lead_id,
            'wa_account_id' => $account_id,
            'wa_message_id' => 'sent_' . time(),
            'from_number'   => $account->wa_number,
            'to_number'     => lcrm_normalize_phone( $lead->client_phone ),
            'direction'     => 'outbound',
            'event_type'    => 'message',
            'message_body'  => mb_substr( $message, 0, 2000 ),
            'capture_status'=> 'captured',
            'capture_score' => 0,
            'raw_payload'   => '{}',
            'event_time'    => current_time( 'mysql' ),
        ] );
    }

    // Update last contact time
    $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'last_client_contact' => current_time( 'mysql' ) ], [ 'id' => $lead_id ] );

    wp_send_json_success( [
        'msg'  => 'Message sent.',
        'user' => $user->display_name,
        'time' => current_time( 'd M Y H:i' ),
        'text' => $message,
    ] );
}

/**
 * lcrm_wa_find_account_for_lead( $lead )
 * Finds the best WA account to use for sending to a lead.
 * Priority: 1) same brand as lead, 2) any active account.
 *
 * TRACE: If lead has brand_id → finds WA account with matching brand_id →
 *        Falls back to first active cloud_api account.
 */
function lcrm_wa_find_account_for_lead( $lead ) {
    global $wpdb;

    if ( ! empty( $lead->brand_id ) ) {
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lcrm_wa_accounts WHERE brand_id=%d AND is_active=1 AND platform='cloud_api' LIMIT 1",
            $lead->brand_id
        ) );
        if ( $id ) return (int) $id;
    }

    $id = $wpdb->get_var(
        "SELECT id FROM {$wpdb->prefix}lcrm_wa_accounts WHERE is_active=1 AND platform='cloud_api' ORDER BY id ASC LIMIT 1"
    );
    return $id ? (int) $id : 0;
}

// ─── AJAX: AI REPLY SUGGESTION ───────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_get_suggestion', 'lcrm_ajax_ai_get_suggestion' );

/**
 * lcrm_ajax_ai_get_suggestion()
 * Returns an AI-generated reply suggestion for a lead.
 *
 * TRACE: Verifies nonce → loads lead → calls lcrm_ai_suggest_reply →
 *        Returns suggestion string.
 * EDGE CASES: AI disabled → error message; AI API error → returns error message to JS.
 */
// TRACE: wp_ajax_lcrm_ai_get_suggestion → verifies login → checks AI enabled →
//        calls lcrm_ai_suggest_reply(lead_id, last_message) → returns {suggestion: string}.
//        PRECONDITIONS: lead_id must exist.
//        POSTCONDITIONS: Suggestion string returned; no DB write.
//        EDGE CASES: AI disabled → 400 error; lead not found → WP_Error propagated; AI error → error to JS.
function lcrm_ajax_ai_get_suggestion() {
    lcrm_verify();

    $lead_id  = intval( $_POST['lead_id']  ?? 0 );
    $last_msg = sanitize_textarea_field( $_POST['last_message'] ?? '' );

    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );
    if ( ! lcrm_ai_enabled() ) wp_send_json_error( [ 'msg' => 'AI is not enabled. Enable it in Settings → AI.' ] );

    $suggestion = lcrm_ai_suggest_reply( $lead_id, $last_msg );

    if ( is_wp_error( $suggestion ) ) {
        wp_send_json_error( [ 'msg' => $suggestion->get_error_message() ] );
    }

    wp_send_json_success( [ 'suggestion' => $suggestion ] );
}

// ─── AJAX: AI MORNING BRIEF ──────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_get_brief', 'lcrm_ajax_ai_get_brief' );

// TRACE: wp_ajax_lcrm_ai_get_brief → verifies login → calls lcrm_ai_morning_brief() →
//        returns {brief: string}. Always returns text (static fallback if AI unavailable).
//        POSTCONDITIONS: No DB write; brief string in response.
function lcrm_ajax_ai_get_brief() {
    lcrm_verify();
    $brief = lcrm_ai_morning_brief();
    wp_send_json_success( [ 'brief' => $brief ] );
}

// ─── AJAX: AI SETTINGS ───────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_save_settings', 'lcrm_ajax_ai_save_settings' );

// TRACE: wp_ajax_lcrm_ai_save_settings → verifies admin → calls lcrm_ai_save_settings() →
//        returns {msg:"AI settings saved."}.
//        POSTCONDITIONS: 7 wp_options written. New API key active on next AI call.
function lcrm_ajax_ai_save_settings() {
    lcrm_verify_admin();
    lcrm_ai_save_settings();
    wp_send_json_success( [ 'msg' => 'AI settings saved.' ] );
}

// ─── AJAX: AI TEST ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_test', 'lcrm_ajax_ai_test' );

/**
 * lcrm_ajax_ai_test()
 * Tests AI API connectivity with a simple ping message.
 *
 * TRACE: Calls lcrm_ai_call with "Reply with: OK" →
 *        Returns success if response contains "OK".
 */
function lcrm_ajax_ai_test() {
    lcrm_verify_admin();

    $result = lcrm_ai_call(
        [ [ 'role' => 'user', 'content' => 'Reply with exactly the word: OK' ] ],
        '',
        10
    );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'msg' => $result->get_error_message() ] );
    }

    wp_send_json_success( [ 'msg' => 'AI API connected successfully. Response: ' . mb_substr( $result, 0, 50 ) ] );
}

// ─── AJAX: VOICE NOTE TRANSCRIPTION ─────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_transcribe_note', 'lcrm_ajax_ai_transcribe_note' );

/**
 * lcrm_ajax_ai_transcribe_note()
 * Transcribes an uploaded voice note (OGG/MP4/AAC) and saves transcript to conversation.
 * Called when a WA voice note event is shown in the lead panel.
 *
 * TRACE: Verifies nonce → downloads audio from Meta CDN using WA token →
 *        Saves to tmp file → calls lcrm_ai_transcribe_audio →
 *        Appends transcript to conversation → returns transcript text.
 * EDGE CASES: Media URL expired → 404 from Meta; file download fails → WP_Error; Whisper API key missing → error.
 */
function lcrm_ajax_ai_transcribe_note() {
    lcrm_verify();

    $lead_id    = intval( $_POST['lead_id']    ?? 0 );
    $event_id   = intval( $_POST['event_id']   ?? 0 );
    $media_url  = sanitize_url( $_POST['media_url'] ?? '' );
    $account_id = intval( $_POST['account_id'] ?? 0 );

    if ( ! $lead_id || ! $media_url ) wp_send_json_error( [ 'msg' => 'Lead ID and media URL required.' ] );
    if ( ! lcrm_ai_enabled() ) wp_send_json_error( [ 'msg' => 'AI not enabled.' ] );

    // Get auth token for media download
    $account = $account_id ? lcrm_wa_get_account( $account_id ) : null;
    $auth    = $account ? 'Bearer ' . $account->api_token : '';

    // Download audio to tmp
    $tmp_file = get_temp_dir() . 'lcrm_voice_' . $lead_id . '_' . time() . '.ogg';
    $download = wp_remote_get( $media_url, [
        'headers' => $auth ? [ 'Authorization' => $auth ] : [],
        'timeout' => 30,
    ] );

    if ( is_wp_error( $download ) ) wp_send_json_error( [ 'msg' => 'Could not download audio: ' . $download->get_error_message() ] );
    if ( wp_remote_retrieve_response_code( $download ) !== 200 ) wp_send_json_error( [ 'msg' => 'Audio URL no longer accessible.' ] );

    file_put_contents( $tmp_file, wp_remote_retrieve_body( $download ) );

    $transcript = lcrm_ai_transcribe_audio( $tmp_file );
    @unlink( $tmp_file );

    if ( is_wp_error( $transcript ) ) wp_send_json_error( [ 'msg' => $transcript->get_error_message() ] );
    if ( ! $transcript ) wp_send_json_error( [ 'msg' => 'No speech detected in voice note.' ] );

    // Append to conversation
    lcrm_add_conversation( $lead_id, "[Voice Note Transcript] " . $transcript );
    lcrm_log_activity( $lead_id, 'voice_transcript', "Voice note transcribed: " . mb_substr( $transcript, 0, 100 ) );

    // AI task extraction from transcript
    if ( get_option( 'lcrm_ai_auto_tasks', 1 ) ) {
        $intent = lcrm_ai_score_intent( $transcript );
        if ( ! is_wp_error( $intent ) && ! empty( $intent['tasks'] ) ) {
            lcrm_ai_insert_tasks_as_alerts( $lead_id, $intent['tasks'] );
        }
    }

    wp_send_json_success( [ 'transcript' => $transcript ] );
}

// ─── SCHEDULED OUTBOUND: FOLLOW-UP REMINDERS ────────────────────────────────

/**
 * lcrm_wa_send_scheduled_followups()
 * Called by daily cron. Sends WA follow-up nudges for leads that are:
 *   - Temperature = hot AND no follow-up in 24h
 *   - Follow-up date = today AND phone number set
 *
 * Only runs if lcrm_wa_auto_followup = 1 (opt-in setting).
 *
 * TRACE: Checks auto-followup enabled → queries qualifying leads →
 *        For each: finds WA account → sends pre-set follow-up message template →
 *        Logs result.
 * EDGE CASES: Lead has no WA account match → skips; send fails → logs error, continues; no qualifying leads → returns early.
 */
function lcrm_wa_send_scheduled_followups() {
    if ( ! get_option( 'lcrm_wa_auto_followup', 0 ) ) return;

    global $wpdb;
    $today = date( 'Y-m-d' );

    $leads = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, client_name, client_phone, work_type, brand_id
         FROM {$wpdb->prefix}lcrm_leads
         WHERE client_followup_date = %s
           AND client_phone != ''
           AND stage_id NOT IN (
               SELECT id FROM {$wpdb->prefix}lcrm_stages
               WHERE name IN ('Work Completed','Delivered','Not Able to Serve','High Charges - Lost','Late Response - Lost')
           )
         LIMIT 20",
        $today
    ) );

    if ( empty( $leads ) ) return;

    $follow_up_template = get_option( 'lcrm_wa_followup_template',
        "Hello {name}, ji! Just following up regarding your {work} inquiry. Please let us know if you have any questions. We are here to help!"
    );

    foreach ( $leads as $lead ) {
        $account_id = lcrm_wa_find_account_for_lead( $lead );
        if ( ! $account_id ) continue;

        $msg = str_replace(
            [ '{name}', '{work}' ],
            [ $lead->client_name, $lead->work_type ?: 'service' ],
            $follow_up_template
        );

        $result = lcrm_wa_send_message( $account_id, $lead->client_phone, $msg );

        if ( is_wp_error( $result ) ) {
            error_log( "LeadCRM auto-followup failed lead #{$lead->id}: " . $result->get_error_message() );
        } else {
            lcrm_add_conversation( $lead->id, "[Auto Follow-up Sent] " . mb_substr( $msg, 0, 300 ) );
            lcrm_log_activity( $lead->id, 'wa_auto_sent', "Auto follow-up sent." );
        }
    }
}
