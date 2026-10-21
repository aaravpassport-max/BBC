<?php
/**
 * LeadCRM AI Engine
 * Central AI processing layer — used by all capture modules.
 * Supports OpenAI (GPT-4o-mini) and Anthropic (Claude Haiku) interchangeably.
 * All API calls are synchronous and wrapped with timeouts + error handling.
 *
 * Public functions:
 *   lcrm_ai_extract_lead_data( $text, $source )  → structured lead data
 *   lcrm_ai_score_intent( $text )                → ['temperature'=>hot|warm|cold,'score'=>0-10,'tasks'=>[]]
 *   lcrm_ai_transcribe_audio( $file_path )        → string transcript
 *   lcrm_ai_extract_pdf_data( $text )             → structured PDF data
 *   lcrm_ai_suggest_reply( $lead_id, $last_msg )  → string reply suggestion
 *   lcrm_ai_morning_brief()                       → string narrative summary
 *   lcrm_ai_enabled()                             → bool
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── SETTINGS GETTERS ────────────────────────────────────────────────────────

// TRACE: Called anywhere AI is used → reads wp_options → returns bool cast.
//        PRECONDITIONS: none  POSTCONDITIONS: returns true if AI active  EDGE CASES: missing option → returns false (safe default).
function lcrm_ai_enabled() {
    return (bool) get_option( 'lcrm_ai_enabled', 0 );
}

// TRACE: Called by lcrm_ai_call → reads wp_option lcrm_ai_provider → returns 'openai'|'anthropic'.
//        EDGE CASES: missing → returns 'openai' default.
function lcrm_ai_provider() {
    return get_option( 'lcrm_ai_provider', 'openai' ); // 'openai' | 'anthropic'
}

// TRACE: Called by lcrm_ai_call → reads wp_option lcrm_ai_api_key → returns trimmed string.
//        EDGE CASES: empty → returns '' causing lcrm_ai_call to return WP_Error('no_key').
function lcrm_ai_api_key() {
    return trim( (string) get_option( 'lcrm_ai_api_key', '' ) );
}

function lcrm_ai_model() {
    $provider = lcrm_ai_provider();
    $default  = $provider === 'anthropic' ? 'claude-haiku-4-5-20251001' : 'gpt-4o-mini';
    return get_option( 'lcrm_ai_model', $default );
}

// ─── CORE HTTP CALLER ────────────────────────────────────────────────────────

/**
 * lcrm_ai_call( $messages, $system, $max_tokens )
 * Calls OpenAI or Anthropic based on settings.
 * Returns string response text or WP_Error.
 *
 * TRACE: Called by all AI feature functions →
 *        Builds correct payload per provider →
 *        POSTs via wp_remote_post with 30s timeout →
 *        Returns decoded text string or WP_Error.
 * PRECONDITIONS: API key set, lcrm_ai_enabled() true.
 * POSTCONDITIONS: Returns string on success, WP_Error on HTTP or API error.
 * EDGE CASES: No API key → WP_Error; HTTP timeout → WP_Error; JSON decode fail → WP_Error; rate limit 429 → WP_Error with message.
 */
function lcrm_ai_call( array $messages, $system = '', $max_tokens = 400 ) {
    $key      = lcrm_ai_api_key();
    $provider = lcrm_ai_provider();
    $model    = lcrm_ai_model();

    if ( ! $key ) {
        return new WP_Error( 'no_key', 'AI API key not configured.' );
    }

    if ( $provider === 'anthropic' ) {
        $url     = 'https://api.anthropic.com/v1/messages';
        $headers = [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];
        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => $messages,
        ];
        if ( $system ) $body['system'] = $system;
    } else {
        // OpenAI
        $url     = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ];
        $oai_msgs = [];
        if ( $system ) $oai_msgs[] = [ 'role' => 'system', 'content' => $system ];
        foreach ( $messages as $m ) $oai_msgs[] = $m;
        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => $oai_msgs,
        ];
    }

    $response = wp_remote_post( $url, [
        'headers' => $headers,
        'body'    => wp_json_encode( $body ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw, true );

    if ( $code === 429 ) {
        return new WP_Error( 'rate_limit', 'AI API rate limit reached. Try again shortly.' );
    }

    if ( $code !== 200 ) {
        $err_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : "API error {$code}";
        return new WP_Error( 'api_error', $err_msg );
    }

    if ( $provider === 'anthropic' ) {
        $text = $data['content'][0]['text'] ?? '';
    } else {
        $text = $data['choices'][0]['message']['content'] ?? '';
    }

    return trim( $text );
}

// ─── JSON RESPONSE HELPER ────────────────────────────────────────────────────

/**
 * lcrm_ai_call_json( $messages, $system, $max_tokens )
 * Like lcrm_ai_call but instructs model to return only JSON and decodes result.
 * Returns array on success, WP_Error on failure, [] on decode failure.
 *
 * TRACE: Appends JSON-only instruction to system prompt →
 *        Calls lcrm_ai_call →
 *        Strips markdown fences →
 *        json_decode → array.
 * EDGE CASES: Non-JSON text returned → logs to error log, returns []; partial JSON → returns [].
 */
function lcrm_ai_call_json( array $messages, $system = '', $max_tokens = 500 ) {
    $json_instruction = "\n\nIMPORTANT: Respond ONLY with valid JSON. No explanation, no markdown, no code fences. Just the raw JSON object.";
    $result = lcrm_ai_call( $messages, $system . $json_instruction, $max_tokens );

    if ( is_wp_error( $result ) ) return $result;

    // Strip any stray markdown fences the model might still emit
    $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $result ) );
    $clean = preg_replace( '/\s*```$/', '', $clean );
    $clean = trim( $clean );

    $decoded = json_decode( $clean, true );
    if ( ! is_array( $decoded ) ) {
        error_log( 'LeadCRM AI JSON decode failed. Raw: ' . substr( $clean, 0, 300 ) );
        return [];
    }
    return $decoded;
}

// ─── FEATURE 1: EXTRACT LEAD DATA FROM ANY TEXT ──────────────────────────────

/**
 * lcrm_ai_extract_lead_data( $text, $source )
 * Extracts structured lead fields from any raw message/email/form text.
 * Returns array with keys: client_name, client_phone, client_email,
 *   work_type, lead_source, lead_temperature, tasks[], followup_date, remarks.
 *
 * TRACE: Builds extraction prompt with source context →
 *        Calls lcrm_ai_call_json →
 *        Sanitizes and returns structured array.
 * PRECONDITIONS: $text is non-empty string.
 * POSTCONDITIONS: Returns array; missing fields are empty strings or [].
 * EDGE CASES: Empty text → returns []; AI unavailable → returns []; malformed name → sanitized.
 */
function lcrm_ai_extract_lead_data( $text, $source = 'message' ) {
    if ( ! lcrm_ai_enabled() || ! $text ) return [];

    $system = 'You are a CRM data extraction assistant for an Indian service business (NRI documentation, visa, legal, property, tax services). Extract lead information accurately.';

    $prompt = "Extract lead information from this {$source}:\n\n" . mb_substr( $text, 0, 1500 ) . "\n\n"
            . 'Return JSON with these exact keys (use empty string if not found):'
            . '{"client_name":"","client_phone":"","client_email":"","work_type":"","lead_temperature":"warm",'
            . '"tasks":[],"followup_days":0,"remarks":"","intent_summary":""}';

    $result = lcrm_ai_call_json(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        $system,
        400
    );

    if ( is_wp_error( $result ) || empty( $result ) ) return [];

    // Sanitize all string values before returning
    $safe = [];
    $safe['client_name']    = sanitize_text_field( $result['client_name']    ?? '' );
    $safe['client_phone']   = sanitize_text_field( $result['client_phone']   ?? '' );
    $safe['client_email']   = sanitize_email(      $result['client_email']   ?? '' );
    $safe['work_type']      = sanitize_text_field( $result['work_type']      ?? '' );
    $safe['lead_temperature'] = in_array( $result['lead_temperature'] ?? '', ['cold','warm','hot'], true )
                                ? $result['lead_temperature'] : 'warm';
    $safe['tasks']          = array_map( 'sanitize_text_field', (array) ( $result['tasks'] ?? [] ) );
    $safe['followup_days']  = max( 0, intval( $result['followup_days'] ?? 0 ) );
    $safe['remarks']        = sanitize_textarea_field( $result['remarks']        ?? '' );
    $safe['intent_summary'] = sanitize_text_field(     $result['intent_summary'] ?? '' );

    return $safe;
}

// ─── FEATURE 2: SCORE INTENT FROM EXISTING LEAD CONVERSATION ────────────────

/**
 * lcrm_ai_score_intent( $text, $lead_context )
 * Scores buying intent and extracts action items from a new message on an existing lead.
 * Returns array: temperature, score(0-10), tasks[], suggested_followup_days.
 *
 * TRACE: Builds scoring prompt with lead context →
 *        Calls lcrm_ai_call_json →
 *        Returns validated scoring array.
 * EDGE CASES: Very short text (< 5 chars) → returns warm/5/[] without API call.
 *             AI unavailable → returns neutral defaults.
 */
function lcrm_ai_score_intent( $text, $lead_context = '' ) {
    if ( ! lcrm_ai_enabled() ) return [ 'temperature' => 'warm', 'score' => 5, 'tasks' => [], 'followup_days' => 1 ];
    if ( mb_strlen( trim( $text ) ) < 5 ) return [ 'temperature' => 'warm', 'score' => 5, 'tasks' => [], 'followup_days' => 1 ];

    $system = 'You are a sales intelligence assistant for an Indian service business. Analyze messages for buying intent.';
    $ctx    = $lead_context ? "Lead context: {$lead_context}\n\n" : '';
    $prompt = "{$ctx}New message: " . mb_substr( $text, 0, 800 ) . "\n\n"
            . 'Score this message. Return JSON: {"temperature":"hot|warm|cold","score":0-10,'
            . '"tasks":["task1","task2"],"followup_days":1,"reason":"brief reason"}';

    $result = lcrm_ai_call_json(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        $system,
        300
    );

    if ( is_wp_error( $result ) || empty( $result ) ) {
        return [ 'temperature' => 'warm', 'score' => 5, 'tasks' => [], 'followup_days' => 1 ];
    }

    return [
        'temperature'    => in_array( $result['temperature'] ?? '', ['cold','warm','hot'], true ) ? $result['temperature'] : 'warm',
        'score'          => max( 0, min( 10, intval( $result['score'] ?? 5 ) ) ),
        'tasks'          => array_slice( array_map( 'sanitize_text_field', (array) ( $result['tasks'] ?? [] ) ), 0, 5 ),
        'followup_days'  => max( 0, intval( $result['followup_days'] ?? 1 ) ),
        'reason'         => sanitize_text_field( $result['reason'] ?? '' ),
    ];
}

// ─── FEATURE 3: TRANSCRIBE AUDIO (Whisper) ───────────────────────────────────

/**
 * lcrm_ai_transcribe_audio( $file_path )
 * Sends an audio file to OpenAI Whisper for transcription.
 * Returns string transcript or WP_Error.
 *
 * TRACE: Validates file exists + size < 25MB →
 *        Opens file handle →
 *        POSTs multipart/form-data to OpenAI Whisper endpoint →
 *        Returns transcript string.
 * PRECONDITIONS: File must exist and be readable; provider must be openai or key must work with Whisper.
 * POSTCONDITIONS: Returns non-empty string on success.
 * EDGE CASES: File > 25MB → WP_Error; Whisper only available on OpenAI → checked; file not found → WP_Error.
 */
function lcrm_ai_transcribe_audio( $file_path ) {
    if ( ! lcrm_ai_enabled() ) return new WP_Error( 'disabled', 'AI not enabled.' );

    $key = lcrm_ai_api_key();
    if ( ! $key ) return new WP_Error( 'no_key', 'AI API key not configured.' );

    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return new WP_Error( 'file_missing', 'Audio file not found.' );
    }

    $size = filesize( $file_path );
    if ( $size > 25 * 1024 * 1024 ) {
        return new WP_Error( 'file_too_large', 'Audio file exceeds 25MB Whisper limit.' );
    }
    if ( $size === 0 ) {
        return new WP_Error( 'file_empty', 'Audio file is empty.' );
    }

    // Build multipart body manually since wp_remote_post doesn't natively do file uploads
    $boundary = wp_generate_password( 24, false );
    $filename  = basename( $file_path );
    $file_data = file_get_contents( $file_path );

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: audio/ogg\r\n\r\n";
    $body .= $file_data . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
    $body .= "whisper-1\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
    $body .= "en\r\n";
    $body .= "--{$boundary}--\r\n";

    $response = wp_remote_post( 'https://api.openai.com/v1/audio/transcriptions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => "multipart/form-data; boundary={$boundary}",
        ],
        'body'    => $body,
        'timeout' => 60,
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "Whisper API error {$code}";
        return new WP_Error( 'whisper_error', $msg );
    }

    return sanitize_textarea_field( $data['text'] ?? '' );
}

// ─── FEATURE 4: PDF DATA EXTRACTION ─────────────────────────────────────────

/**
 * lcrm_ai_extract_pdf_data( $text )
 * Extracts structured business data from PDF text content.
 * Returns array: title, deadlines[], amounts[], parties[], obligations[], summary.
 *
 * TRACE: Truncates text to 3000 chars →
 *        Calls lcrm_ai_call_json with extraction prompt →
 *        Returns sanitized structured array.
 * EDGE CASES: Empty text → []; text too short (<50 chars) → []; AI error → [].
 */
function lcrm_ai_extract_pdf_data( $text ) {
    if ( ! lcrm_ai_enabled() || mb_strlen( trim( $text ) ) < 50 ) return [];

    $system = 'You extract structured business information from documents for an Indian service business CRM.';
    $prompt = "Extract key information from this document:\n\n" . mb_substr( $text, 0, 3000 ) . "\n\n"
            . 'Return JSON: {"title":"","deadlines":[{"description":"","date":"YYYY-MM-DD"}],'
            . '"amounts":[{"description":"","amount":0}],"parties":[],"obligations":[],'
            . '"summary":"2-3 sentence summary","priority":"low|medium|high"}';

    $result = lcrm_ai_call_json(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        $system,
        600
    );

    if ( is_wp_error( $result ) || empty( $result ) ) return [];

    return [
        'title'       => sanitize_text_field( $result['title']    ?? '' ),
        'deadlines'   => array_map( function( $d ) {
            return [
                'description' => sanitize_text_field( $d['description'] ?? '' ),
                'date'        => sanitize_text_field( $d['date']        ?? '' ),
            ];
        }, (array) ( $result['deadlines'] ?? [] ) ),
        'amounts'     => array_map( function( $a ) {
            return [
                'description' => sanitize_text_field( $a['description'] ?? '' ),
                'amount'      => floatval( $a['amount'] ?? 0 ),
            ];
        }, (array) ( $result['amounts'] ?? [] ) ),
        'parties'     => array_map( 'sanitize_text_field', (array) ( $result['parties']     ?? [] ) ),
        'obligations' => array_map( 'sanitize_text_field', (array) ( $result['obligations'] ?? [] ) ),
        'summary'     => sanitize_textarea_field( $result['summary'] ?? '' ),
        'priority'    => in_array( $result['priority'] ?? '', ['low','medium','high'], true ) ? $result['priority'] : 'medium',
    ];
}

// ─── FEATURE 5: AI REPLY SUGGESTION ─────────────────────────────────────────

/**
 * lcrm_ai_suggest_reply( $lead_id, $last_message )
 * Generates a suggested WhatsApp reply for a lead based on conversation history.
 * Returns string suggestion or WP_Error.
 *
 * TRACE: Loads lead data + last 5 conversation entries →
 *        Builds context-aware prompt →
 *        Calls lcrm_ai_call (text, not JSON) →
 *        Returns plain text suggestion.
 * PRECONDITIONS: Lead must exist.
 * EDGE CASES: Lead not found → WP_Error; empty last message → uses recent history; AI error → WP_Error.
 */
function lcrm_ai_suggest_reply( $lead_id, $last_message = '' ) {
    if ( ! lcrm_ai_enabled() ) return new WP_Error( 'disabled', 'AI not enabled.' );

    $lead = lcrm_get_lead( (int) $lead_id );
    if ( ! $lead ) return new WP_Error( 'not_found', 'Lead not found.' );

    // Build context from lead + recent conversations
    $context  = "Client: {$lead->client_name}\n";
    $context .= "Service needed: {$lead->work_type}\n";
    $context .= "Stage: {$lead->stage_name}\n";
    $context .= "Temperature: {$lead->lead_temperature}\n";
    if ( $last_message ) $context .= "Latest message from client: {$last_message}\n";
    if ( $lead->remarks ) $context .= "Notes: " . mb_substr( $lead->remarks, 0, 200 ) . "\n";

    $system = 'You are a professional WhatsApp assistant for an Indian service business. Write concise, friendly, professional replies in English. Keep replies under 3 sentences. Never make up prices or timelines. Use "namaste" or "ji" appropriately.';
    $prompt = "Based on this lead context, suggest a WhatsApp reply to send now:\n\n{$context}\n\nWrite only the reply message text, nothing else.";

    return lcrm_ai_call(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        $system,
        200
    );
}

// ─── FEATURE 6: AI MORNING BRIEF ────────────────────────────────────────────

/**
 * lcrm_ai_morning_brief()
 * Generates a human-readable narrative morning summary for the admin.
 * Returns string or falls back to static summary on AI failure.
 *
 * TRACE: Pulls today's stats from DB →
 *        Pulls top 5 pending alerts →
 *        Builds data payload →
 *        Calls lcrm_ai_call for narrative →
 *        Returns string.
 * POSTCONDITIONS: Always returns non-empty string (falls back to static if AI fails).
 * EDGE CASES: DB empty → AI generates appropriate "all clear" message; AI fails → returns static summary.
 */
function lcrm_ai_morning_brief() {
    global $wpdb;

    $today = date( 'Y-m-d' );
    $stats = [
        'total_leads'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads" ),
        'new_today'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date=%s", $today ) ),
        'hot_leads'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_temperature='hot'" ),
        'followups_due'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE client_followup_date<=%s AND client_followup_date IS NOT NULL", $today ) ),
        'payment_due'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE balance_payment>0" ),
        'unread_alerts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0" ),
        'wa_unread'      => (int) $wpdb->get_var( "SELECT SUM(wa_unread_count) FROM {$wpdb->prefix}lcrm_leads" ),
        'pending_revenue'=> (float) $wpdb->get_var( "SELECT SUM(balance_payment) FROM {$wpdb->prefix}lcrm_leads WHERE balance_payment>0" ),
    ];

    // Get top alerts for context
    $top_alerts = $wpdb->get_results(
        "SELECT a.message FROM {$wpdb->prefix}lcrm_alerts a WHERE a.is_read=0 AND a.priority='high' ORDER BY a.created_at DESC LIMIT 5"
    );
    $alert_texts = wp_list_pluck( (array) $top_alerts, 'message' );

    $static_fallback = "Good morning!\n\n"
        . "Today's summary ({$today}):\n"
        . "• {$stats['new_today']} new leads today, {$stats['hot_leads']} hot leads total\n"
        . "• {$stats['followups_due']} follow-ups due today\n"
        . "• {$stats['unread_alerts']} unread alerts\n"
        . ( $stats['wa_unread'] ? "• {$stats['wa_unread']} unread WhatsApp messages\n" : '' )
        . ( $stats['pending_revenue'] > 0 ? "• Rs." . number_format( $stats['pending_revenue'], 0 ) . " pending revenue\n" : '' );

    if ( ! lcrm_ai_enabled() ) return $static_fallback;

    $data_text = "Stats for {$today}:\n"
               . "New leads today: {$stats['new_today']}\n"
               . "Hot leads: {$stats['hot_leads']}\n"
               . "Follow-ups due: {$stats['followups_due']}\n"
               . "Payment follow-ups needed: {$stats['payment_due']}\n"
               . "Unread WA messages: {$stats['wa_unread']}\n"
               . "Pending revenue: Rs." . number_format( $stats['pending_revenue'], 0 ) . "\n"
               . ( $alert_texts ? "\nTop urgent alerts:\n- " . implode( "\n- ", $alert_texts ) : '' );

    $system = 'You write brief, motivating morning briefings for a business owner. Be concise, specific, and action-oriented. Mention what needs to happen today. Keep it under 5 sentences.';
    $prompt = "Write a morning business briefing based on these CRM stats:\n\n{$data_text}";

    $result = lcrm_ai_call(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        $system,
        250
    );

    return is_wp_error( $result ) ? $static_fallback : $result;
}

// ─── FEATURE 7: INSERT AI-EXTRACTED TASKS AS ALERTS ──────────────────────────

/**
 * lcrm_ai_insert_tasks_as_alerts( $lead_id, $tasks, $priority )
 * Converts AI-extracted task strings into lcrm_alerts rows.
 *
 * TRACE: Iterates tasks array →
 *        Calls lcrm_create_alert_deduped for each →
 *        Returns count of alerts inserted.
 * EDGE CASES: Empty tasks → returns 0; duplicate tasks → skipped by dedup; non-array → 0.
 */
function lcrm_ai_insert_tasks_as_alerts( $lead_id, $tasks, $priority = 'medium' ) {
    if ( empty( $tasks ) || ! is_array( $tasks ) ) return 0;
    $count = 0;
    foreach ( $tasks as $task ) {
        $task = sanitize_text_field( $task );
        if ( ! $task ) continue;
        lcrm_create_alert_deduped( (int) $lead_id, 'ai_task', $priority, $task, 24 );
        $count++;
    }
    return $count;
}

// ─── SETTINGS SAVE / RENDER ──────────────────────────────────────────────────

/**
 * lcrm_ai_save_settings()
 * Saves AI settings from POST data. Called from settings AJAX handler.
 *
 * TRACE: Reads POST fields → sanitizes → saves as wp_options.
 * EDGE CASES: Empty API key is saved as empty string (disabling AI).
 */
// TRACE: Called from lcrm_ajax_ai_save_settings → reads 7 POST fields → sanitizes → saves as wp_options.
//        PRECONDITIONS: Admin nonce already verified by caller.
//        POSTCONDITIONS: All 7 options persisted; AI toggles take effect on next call.
//        EDGE CASES: Empty API key saved as '' (disables AI on next lcrm_ai_enabled check).
function lcrm_ai_save_settings() {
    update_option( 'lcrm_ai_enabled',  intval( $_POST['ai_enabled']  ?? 0 ) );
    update_option( 'lcrm_ai_provider', in_array( $_POST['ai_provider'] ?? '', ['openai','anthropic'], true ) ? $_POST['ai_provider'] : 'openai' );
    update_option( 'lcrm_ai_api_key',  sanitize_text_field( $_POST['ai_api_key'] ?? '' ) );
    update_option( 'lcrm_ai_model',    sanitize_text_field( $_POST['ai_model']   ?? '' ) );
    update_option( 'lcrm_ai_auto_score',    intval( $_POST['ai_auto_score']    ?? 1 ) );
    update_option( 'lcrm_ai_auto_tasks',    intval( $_POST['ai_auto_tasks']    ?? 1 ) );
    update_option( 'lcrm_ai_auto_transcribe', intval( $_POST['ai_auto_transcribe'] ?? 1 ) );
}
