<?php
/**
 * LeadCRM WhatsApp Capture Engine
 * Handles: DB schema, REST webhook endpoint, smart lead quality filter, event logging
 * Supports: Meta Cloud API (primary) + generic webhook (Baileys / any bridge)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

function lcrm_wa_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // WA Accounts — one per WhatsApp number, linked to a Brand
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_wa_accounts (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        brand_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wa_number            VARCHAR(30)      NOT NULL DEFAULT '',
        display_name         VARCHAR(150)     NOT NULL DEFAULT '',
        platform             ENUM('cloud_api','generic') NOT NULL DEFAULT 'cloud_api',
        phone_number_id      VARCHAR(120)     NOT NULL DEFAULT '',
        api_token            TEXT             NOT NULL DEFAULT '',
        webhook_verify_token VARCHAR(100)     NOT NULL DEFAULT '',
        is_active            TINYINT(1)       NOT NULL DEFAULT 1,
        last_synced_at       DATETIME         DEFAULT NULL,
        created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY brand_id (brand_id),
        UNIQUE KEY wa_number (wa_number)
    ) $c;" );

    // WA Events — every inbound/outbound event with smart filter metadata
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_wa_events (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id        BIGINT UNSIGNED DEFAULT NULL,
        wa_account_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wa_message_id  VARCHAR(200)    NOT NULL DEFAULT '',
        from_number    VARCHAR(30)     NOT NULL DEFAULT '',
        to_number      VARCHAR(30)     NOT NULL DEFAULT '',
        direction      ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
        event_type     ENUM('message','call_incoming','call_outgoing','call_missed','read','status') NOT NULL DEFAULT 'message',
        message_body   TEXT            NOT NULL DEFAULT '',
        media_type     VARCHAR(30)     NOT NULL DEFAULT '',
        call_duration  INT             NOT NULL DEFAULT 0,
        is_read        TINYINT(1)      NOT NULL DEFAULT 0,
        capture_status ENUM('captured','pending_review','rejected','blocklisted','updated') NOT NULL DEFAULT 'captured',
        capture_score  INT             NOT NULL DEFAULT 0,
        raw_payload    LONGTEXT        NOT NULL DEFAULT '{}',
        event_time     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY wa_account_id (wa_account_id),
        KEY from_number (from_number),
        KEY event_time (event_time),
        KEY capture_status (capture_status)
    ) $c;" );

    // Call Logs — structured call records (manual or auto from webhook)
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_call_logs (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id          BIGINT UNSIGNED NOT NULL,
        wa_account_id    BIGINT UNSIGNED DEFAULT NULL,
        call_type        ENUM('incoming','outgoing','missed') NOT NULL DEFAULT 'incoming',
        duration_sec     INT             NOT NULL DEFAULT 0,
        phone_number     VARCHAR(30)     NOT NULL DEFAULT '',
        call_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes            TEXT            NOT NULL DEFAULT '',
        created_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reminder_created TINYINT(1)      NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY call_type (call_type),
        KEY call_at (call_at)
    ) $c;" );

    // New columns on lcrm_leads
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0 );
    if ( ! empty( $cols ) ) {
        $add = [];
        if ( ! in_array( 'wa_unread_count', $cols, true ) ) $add[] = "ADD COLUMN wa_unread_count INT NOT NULL DEFAULT 0";
        if ( ! in_array( 'last_wa_message',  $cols, true ) ) $add[] = "ADD COLUMN last_wa_message VARCHAR(300) NOT NULL DEFAULT ''";
        if ( ! in_array( 'last_wa_time',     $cols, true ) ) $add[] = "ADD COLUMN last_wa_time DATETIME DEFAULT NULL";
        if ( ! empty( $add ) ) $wpdb->query( "ALTER TABLE {$wpdb->prefix}lcrm_leads " . implode( ', ', $add ) );
    }

    update_option( 'lcrm_wa_db_version', '1.0' );
}

// ─── REST WEBHOOK ENDPOINT ──────────────────────────────────────────────────

add_action( 'rest_api_init', 'lcrm_wa_register_routes' );
function lcrm_wa_register_routes() {
    // GET  — Meta Cloud API webhook verification
    // POST — Incoming event from any platform
    register_rest_route( 'lcrm/v1', '/wa-webhook/(?P<account_id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'lcrm_wa_webhook_verify',
            'permission_callback' => '__return_true',
            'args'                => [ 'account_id' => [ 'sanitize_callback' => 'absint' ] ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'lcrm_wa_webhook_receive',
            'permission_callback' => '__return_true',
            'args'                => [ 'account_id' => [ 'sanitize_callback' => 'absint' ] ],
        ],
    ] );
}

/**
 * GET handler — Meta Cloud API sends a GET to verify the webhook URL.
 */
function lcrm_wa_webhook_verify( WP_REST_Request $request ) {
    $account_id  = $request->get_param( 'account_id' );
    $mode        = $request->get_param( 'hub_mode' );
    $token       = $request->get_param( 'hub_verify_token' );
    $challenge   = $request->get_param( 'hub_challenge' );

    $account = lcrm_wa_get_account( $account_id );
    if ( ! $account ) {
        return new WP_REST_Response( 'Account not found', 403 );
    }

    if ( $mode === 'subscribe' && hash_equals( $account->webhook_verify_token, $token ) ) {
        return new WP_REST_Response( (int) $challenge, 200 );
    }

    return new WP_REST_Response( 'Forbidden', 403 );
}

/**
 * POST handler — receives incoming WhatsApp events.
 * Supports Meta Cloud API JSON structure AND a simple generic format.
 */
function lcrm_wa_webhook_receive( WP_REST_Request $request ) {
    $account_id = $request->get_param( 'account_id' );
    $account    = lcrm_wa_get_account( $account_id );

    if ( ! $account || ! $account->is_active ) {
        return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
    }

    $body = $request->get_body();
    $data = json_decode( $body, true );
    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( [ 'status' => 'invalid_json' ], 200 );
    }

    // Detect payload format
    if ( isset( $data['object'] ) && $data['object'] === 'whatsapp_business_account' ) {
        // Meta Cloud API format
        lcrm_wa_process_cloud_api_payload( $data, $account );
    } elseif ( isset( $data['event'] ) && isset( $data['from'] ) ) {
        // Generic/Baileys bridge format
        lcrm_wa_process_generic_payload( $data, $account );
    }

    // Meta Cloud API always expects 200 OK quickly, process sync
    return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
}

// ─── PAYLOAD PARSERS ─────────────────────────────────────────────────────────

function lcrm_wa_process_cloud_api_payload( $data, $account ) {
    if ( empty( $data['entry'] ) ) return;
    foreach ( $data['entry'] as $entry ) {
        if ( empty( $entry['changes'] ) ) continue;
        foreach ( $entry['changes'] as $change ) {
            $value = $change['value'] ?? [];
            // Messages
            if ( ! empty( $value['messages'] ) ) {
                foreach ( $value['messages'] as $msg ) {
                    lcrm_wa_handle_inbound_message( $msg, $account );
                }
            }
            // Status updates (sent/delivered/read) — we only care about read receipts
            if ( ! empty( $value['statuses'] ) ) {
                foreach ( $value['statuses'] as $status ) {
                    if ( ( $status['status'] ?? '' ) === 'read' ) {
                        lcrm_wa_handle_read_receipt( $status, $account );
                    }
                }
            }
        }
    }
}

function lcrm_wa_handle_inbound_message( $msg, $account ) {
    $from       = lcrm_normalize_phone( $msg['from'] ?? '' );
    $message_id = sanitize_text_field( $msg['id'] ?? '' );
    $timestamp  = isset( $msg['timestamp'] ) ? date( 'Y-m-d H:i:s', (int) $msg['timestamp'] ) : current_time( 'mysql' );

    if ( ! $from ) return;

    // Extract message text
    $body      = '';
    $media_t   = '';
    $msg_type  = $msg['type'] ?? 'unknown';
    if ( $msg_type === 'text' )   { $body = $msg['text']['body'] ?? ''; }
    elseif ( $msg_type === 'image' )  { $body = $msg['image']['caption'] ?? ''; $media_t = 'image'; }
    elseif ( $msg_type === 'document' ) { $body = $msg['document']['filename'] ?? 'Document'; $media_t = 'document'; }
    elseif ( $msg_type === 'audio' )  { $body = 'Voice message'; $media_t = 'audio'; }
    elseif ( $msg_type === 'video' )  { $body = 'Video'; $media_t = 'video'; }
    elseif ( $msg_type === 'button' ) { $body = $msg['button']['text'] ?? ''; }
    elseif ( $msg_type === 'interactive' ) { $body = $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? ''; }

    lcrm_wa_capture_engine( [
        'from'       => $from,
        'body'       => $body,
        'message_id' => $message_id,
        'media_type' => $media_t,
        'event_type' => 'message',
        'event_time' => $timestamp,
        'raw'        => $msg,
    ], $account );
}

function lcrm_wa_handle_read_receipt( $status, $account ) {
    global $wpdb;
    $phone = lcrm_normalize_phone( $status['recipient_id'] ?? '' );
    if ( ! $phone ) return;
    // Find the lead with this phone and reset unread count
    $lead_id = lcrm_wa_find_lead_by_phone( $phone );
    if ( $lead_id ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'wa_unread_count' => 0 ], [ 'id' => $lead_id ] );
    }
}

/**
 * Generic webhook format (for Baileys or any custom bridge):
 * {
 *   "event": "message" | "call_missed" | "call_incoming" | "call_outgoing",
 *   "from": "+919876543210",
 *   "body": "message text",
 *   "message_id": "xxx",
 *   "duration": 0,
 *   "media_type": "",
 *   "timestamp": 1234567890
 * }
 */
function lcrm_wa_process_generic_payload( $data, $account ) {
    $from       = lcrm_normalize_phone( $data['from'] ?? '' );
    $event_type = sanitize_text_field( $data['event'] ?? 'message' );
    $body       = sanitize_text_field( $data['body'] ?? '' );
    $message_id = sanitize_text_field( $data['message_id'] ?? uniqid( 'gen_', true ) );
    $duration   = intval( $data['duration'] ?? 0 );
    $media_t    = sanitize_text_field( $data['media_type'] ?? '' );
    $timestamp  = isset( $data['timestamp'] ) ? date( 'Y-m-d H:i:s', (int) $data['timestamp'] ) : current_time( 'mysql' );

    if ( ! $from ) return;

    lcrm_wa_capture_engine( [
        'from'       => $from,
        'body'       => $body,
        'message_id' => $message_id,
        'media_type' => $media_t,
        'event_type' => $event_type,
        'duration'   => $duration,
        'event_time' => $timestamp,
        'raw'        => $data,
    ], $account );
}

// ─── CORE CAPTURE ENGINE ─────────────────────────────────────────────────────

/**
 * The main engine: decides what to do with every incoming event.
 * Decision flow:
 *   1. Normalize phone
 *   2. Deduplicate against existing events (skip exact duplicates)
 *   3. Check if lead already exists → if yes, update and log
 *   4. Run smart filter → capture / review / block
 *   5. Act on filter decision
 */
function lcrm_wa_capture_engine( array $event, $account ) {
    global $wpdb;

    $phone      = $event['from'];
    $body       = $event['body'];
    $message_id = $event['message_id'];
    $event_type = $event['event_type'];
    $media_t    = $event['media_type'] ?? '';
    $duration   = $event['duration'] ?? 0;
    $event_time = $event['event_time'];
    $raw        = wp_json_encode( $event['raw'] ?? [] );

    // 1. Deduplicate: skip if this exact message_id was already stored
    if ( $message_id && $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_wa_events WHERE wa_message_id = %s LIMIT 1",
        $message_id
    ) ) ) return;

    // 2. Check if a lead already exists for this phone number
    $existing_lead_id = lcrm_wa_find_lead_by_phone( $phone );

    if ( $existing_lead_id ) {
        // Lead exists → update last contact times and log the event
        $updates = [
            'last_client_contact' => $event_time,
            'last_wa_time'        => $event_time,
        ];
        if ( $event_type === 'message' && $body ) {
            $updates['last_wa_message']  = mb_substr( $body, 0, 300 );
            $updates['wa_unread_count']  = max( 0,
                (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT wa_unread_count FROM {$wpdb->prefix}lcrm_leads WHERE id=%d", $existing_lead_id
                ) ) + 1
            );
        }
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", $updates, [ 'id' => $existing_lead_id ] );

        // Log to activity timeline
        lcrm_wa_log_to_activity( $existing_lead_id, $event_type, $body, $phone );

        // Store event record
        lcrm_wa_insert_event( [
            'lead_id'       => $existing_lead_id,
            'wa_account_id' => $account->id,
            'wa_message_id' => $message_id,
            'from_number'   => $phone,
            'to_number'     => $account->wa_number,
            'direction'     => 'inbound',
            'event_type'    => $event_type,
            'message_body'  => mb_substr( $body, 0, 2000 ),
            'media_type'    => $media_t,
            'call_duration' => $duration,
            'capture_status'=> 'updated',
            'capture_score' => 0,
            'raw_payload'   => $raw,
            'event_time'    => $event_time,
        ] );

        // Auto-create callback reminder on missed call
        if ( $event_type === 'call_missed' ) {
            lcrm_wa_create_missed_call_reminder( $existing_lead_id, $phone, $account );
        }
        return;
    }

    // 3. New number — run smart filter
    $capture_mode = get_option( 'lcrm_wa_capture_mode', 'smart' ); // smart | all | manual
    $filter       = lcrm_wa_smart_filter( $phone, $body, $event_type, $account );
    $score        = $filter['score'];
    $decision     = $filter['decision']; // 'capture' | 'review' | 'block'

    // Override: if mode=all, capture everything not hard-blocked
    if ( $capture_mode === 'all' && $decision !== 'block' ) {
        $decision = 'capture';
    }
    // Override: if mode=manual, send everything non-blocked to review
    if ( $capture_mode === 'manual' && $decision !== 'block' ) {
        $decision = 'review';
    }

    if ( $decision === 'block' ) {
        lcrm_wa_insert_event( [
            'lead_id'       => null,
            'wa_account_id' => $account->id,
            'wa_message_id' => $message_id,
            'from_number'   => $phone,
            'to_number'     => $account->wa_number,
            'direction'     => 'inbound',
            'event_type'    => $event_type,
            'message_body'  => mb_substr( $body, 0, 2000 ),
            'media_type'    => $media_t,
            'call_duration' => $duration,
            'capture_status'=> $filter['block_reason'] === 'blocklist' ? 'blocklisted' : 'rejected',
            'capture_score' => $score,
            'raw_payload'   => $raw,
            'event_time'    => $event_time,
        ] );
        return;
    }

    if ( $decision === 'review' ) {
        lcrm_wa_insert_event( [
            'lead_id'       => null,
            'wa_account_id' => $account->id,
            'wa_message_id' => $message_id,
            'from_number'   => $phone,
            'to_number'     => $account->wa_number,
            'direction'     => 'inbound',
            'event_type'    => $event_type,
            'message_body'  => mb_substr( $body, 0, 2000 ),
            'media_type'    => $media_t,
            'call_duration' => $duration,
            'capture_status'=> 'pending_review',
            'capture_score' => $score,
            'raw_payload'   => $raw,
            'event_time'    => $event_time,
        ] );
        return;
    }

    // decision === 'capture'
    $new_event_id = lcrm_wa_insert_event( [
        'lead_id'       => null,     // will be filled after lead creation
        'wa_account_id' => $account->id,
        'wa_message_id' => $message_id,
        'from_number'   => $phone,
        'to_number'     => $account->wa_number,
        'direction'     => 'inbound',
        'event_type'    => $event_type,
        'message_body'  => mb_substr( $body, 0, 2000 ),
        'media_type'    => $media_t,
        'call_duration' => $duration,
        'capture_status'=> 'captured',
        'capture_score' => $score,
        'raw_payload'   => $raw,
        'event_time'    => $event_time,
    ] );

    // Create the lead
    $brand_id    = (int) $account->brand_id;
    $client_name = lcrm_wa_build_client_name( $phone );
    $lead_id     = lcrm_create_lead( [
        'lead_date'          => date( 'Y-m-d' ),
        'client_name'        => $client_name,
        'client_phone'       => $phone,
        'lead_source'        => 'WhatsApp',
        'stage_id'           => 1,         // Inquiry
        'brand_id'           => $brand_id ?: null,
        'priority'           => 'medium',
        'lead_temperature'   => 'warm',
        'last_client_contact'=> $event_time,
        'last_wa_time'       => $event_time,
        'last_wa_message'    => mb_substr( $body, 0, 300 ),
        'wa_unread_count'    => 1,
        'remarks'            => $body ? 'First WA message: ' . mb_substr( $body, 0, 200 ) : 'New WhatsApp inquiry',
        'created_by'         => 0,
    ] );

    if ( ! $lead_id ) return;

    // Link event back to the new lead
    $wpdb->update( "{$wpdb->prefix}lcrm_wa_events", [ 'lead_id' => $lead_id ], [ 'id' => $new_event_id ] );

    // Log to activity timeline
    lcrm_wa_log_to_activity( $lead_id, $event_type, $body, $phone );

    // Auto-reminder for missed calls on new leads too
    if ( $event_type === 'call_missed' ) {
        lcrm_wa_create_missed_call_reminder( $lead_id, $phone, $account );
    }
}

// ─── SMART FILTER ────────────────────────────────────────────────────────────

/**
 * Returns ['decision' => 'capture'|'review'|'block', 'score' => int, 'block_reason' => string]
 *
 * HARD BLOCK (decision = 'block') if:
 *   - Phone is on personal blocklist
 *   - Phone belongs to a WordPress staff user
 *   - Phone belongs to a registered vendor
 *   - Message matches automated noise patterns (OTP, bank alerts, delivery, etc.)
 *
 * SCORING (0–10):
 *   - Short message (<5 chars, emoji-only, "ok/hi/hey" alone): -3
 *   - Message length 10–30 chars: 0  |  30–80: +1  |  80+: +2
 *   - Contains question mark: +2
 *   - Contains a service keyword: +3 (capped at one match)
 *   - Contains an intent keyword: +2 (capped at one match)
 *   - Media attachment (document/image): +1 (likely sending info)
 *   - Unknown number on this account (first contact ever): +1
 *
 * DECISION:
 *   score >= threshold (default 2): 'capture'
 *   score 0–1:                      'review'
 *   score < 0 (but not hard blocked): 'block'
 */
function lcrm_wa_smart_filter( $phone, $body, $event_type, $account ) {
    global $wpdb;

    $threshold = (int) get_option( 'lcrm_wa_capture_threshold', 2 );

    // ── HARD BLOCKS ──────────────────────────────────────────────────────────

    // 1. Personal blocklist
    $blocklist = (array) get_option( 'lcrm_wa_blocklist', [] );
    $norm      = lcrm_normalize_phone( $phone );
    foreach ( $blocklist as $bl ) {
        if ( lcrm_normalize_phone( $bl ) === $norm ) {
            return [ 'decision' => 'block', 'score' => -10, 'block_reason' => 'blocklist' ];
        }
    }

    // 2. WordPress staff/admin user phone
    $staff_match = $wpdb->get_var( $wpdb->prepare(
        "SELECT u.ID FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
         WHERE um.meta_key IN ('billing_phone','phone') AND um.meta_value != ''
         AND REPLACE(REPLACE(REPLACE(REPLACE(um.meta_value,' ',''),'-',''),'+',''),'(','') LIKE %s
         LIMIT 1",
        '%' . substr( $norm, -9 ) . '%'
    ) );
    if ( $staff_match ) {
        return [ 'decision' => 'block', 'score' => -10, 'block_reason' => 'staff' ];
    }

    // 3. Registered vendor
    $vendor_match = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_vendors
         WHERE REPLACE(REPLACE(phone,' ',''),'+','') LIKE %s
            OR REPLACE(REPLACE(phone2,' ',''),'+','') LIKE %s
         LIMIT 1",
        '%' . substr( $norm, -9 ) . '%',
        '%' . substr( $norm, -9 ) . '%'
    ) );
    if ( $vendor_match ) {
        return [ 'decision' => 'block', 'score' => -10, 'block_reason' => 'vendor' ];
    }

    // 4. Automated noise patterns in message body
    $noise_patterns = [
        '/\botp\b.*\bis\b/i',
        '/your\s+(otp|code|pin|verification)\s+is/i',
        '/\btransaction\s+(id|ref|no)\b/i',
        '/\bbank\s+(alert|statement|notification)\b/i',
        '/\bdelivery\s+(update|out\s+for|scheduled|rescheduled)\b/i',
        '/\bshipment\s+(update|has\s+been)\b/i',
        '/\bpayment\s+(of\s+rs|received\s+of)\b/i',
        '/\border\s+(confirmed|has\s+been\s+(placed|shipped|delivered))\b/i',
        '/dear\s+(customer|user|member)/i',
        '/\bdo\s+not\s+share\s+(your|this)\b/i',
        '/\byour\s+account\s+(has\s+been|will\s+be)\b/i',
    ];
    foreach ( $noise_patterns as $pattern ) {
        if ( preg_match( $pattern, $body ) ) {
            return [ 'decision' => 'block', 'score' => -5, 'block_reason' => 'noise' ];
        }
    }

    // ── SCORING ──────────────────────────────────────────────────────────────
    $score = 0;
    $msg   = mb_strtolower( trim( $body ) );
    $len   = mb_strlen( $msg );

    // Calls always get reviewed at minimum — they are worth a look
    if ( in_array( $event_type, [ 'call_incoming', 'call_missed' ], true ) ) {
        return [ 'decision' => 'review', 'score' => 1, 'block_reason' => '' ];
    }

    // Very short / generic openers
    $generic_only = preg_match( '/^(hi+|hey+|hello+|helo+|hlo+|ok+|okay+|yes+|no+|k+|👍|🙏|✅|ola|hiya|sup|yo+)?$/u', $msg );
    if ( $len <= 3 || $generic_only ) {
        $score -= 3;
    } elseif ( $len < 10 ) {
        $score -= 1;
    } elseif ( $len >= 30 ) {
        $score += 1;
    }
    if ( $len >= 80 ) {
        $score += 1; // longer message = more likely a real inquiry
    }

    // Question mark
    if ( strpos( $body, '?' ) !== false ) {
        $score += 2;
    }

    // Service keywords (NRI/documentation domain + general)
    $service_kw = [
        'visa', 'nri', 'oci', 'pio', 'passport', 'document', 'certificate',
        'notary', 'notarize', 'affidavit', 'apostille', 'attestation',
        'marriage', 'birth', 'police clearance', 'pcc',
        'property', 'legal', 'lawyer', 'advocate', 'court',
        'tax', 'itr', 'gst', 'pan', 'income tax', 'finance',
        'education', 'degree', 'marksheet', 'transcript',
        'translation', 'courier', 'consulate', 'embassy', 'immigration',
        'power of attorney', 'poa', 'will', 'probate',
        'service', 'services', 'help me', 'can you do', 'can you help',
        'charges', 'fees', 'cost', 'price', 'rate', 'quotation', 'quote',
        'procedure', 'process', 'how long', 'how much',
    ];
    foreach ( $service_kw as $kw ) {
        if ( strpos( $msg, $kw ) !== false ) {
            $score += 3;
            break; // only count once
        }
    }

    // Intent keywords
    $intent_kw = [
        'how much', 'how long', 'how to', 'what is',
        'can you', 'i need', 'i want', 'please', 'kindly',
        'i am looking', 'looking for', 'require', 'required',
        'enquiry', 'inquiry', 'query', 'information', 'details',
        'interested', 'want to know', 'could you', 'would you',
    ];
    foreach ( $intent_kw as $kw ) {
        if ( strpos( $msg, $kw ) !== false ) {
            $score += 2;
            break; // only count once
        }
    }

    // Media attachment (someone sending a document = likely a client)
    if ( $event['media_type'] ?? '' ) {
        $score += 1;
    }

    // ── DECISION ─────────────────────────────────────────────────────────────
    if ( $score >= $threshold ) {
        $decision = 'capture';
    } elseif ( $score >= 0 ) {
        $decision = 'review';
    } else {
        $decision = 'block';
    }

    return [ 'decision' => $decision, 'score' => $score, 'block_reason' => '' ];
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * Normalize a phone number to last 10 digits for comparison.
 * Handles +91, 0, spaces, dashes, brackets.
 */
function lcrm_normalize_phone( $phone ) {
    $clean = preg_replace( '/[^\d]/', '', $phone ); // strip everything except digits
    if ( strlen( $clean ) > 10 ) {
        $clean = substr( $clean, -10 ); // take last 10 digits
    }
    return $clean;
}

function lcrm_wa_find_lead_by_phone( $phone ) {
    global $wpdb;
    $norm = lcrm_normalize_phone( $phone );
    if ( ! $norm ) return null;
    // Match last 10 digits using LIKE on stored phone number
    $lead_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_leads
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(client_phone,' ',''),'-',''),'+',''),'(',''),')','') LIKE %s
         ORDER BY id DESC LIMIT 1",
        '%' . $norm
    ) );
    return $lead_id ? (int) $lead_id : null;
}

function lcrm_wa_build_client_name( $phone ) {
    // Format: "WA +XX XXXXXX" — admin updates name when they open the lead
    return 'WA +' . $phone;
}

function lcrm_wa_get_account( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_wa_accounts WHERE id = %d", intval( $id )
    ) );
}

function lcrm_wa_get_account_by_number( $number ) {
    global $wpdb;
    $norm = lcrm_normalize_phone( $number );
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_wa_accounts
         WHERE REPLACE(REPLACE(wa_number,' ',''),'+','') LIKE %s AND is_active=1 LIMIT 1",
        '%' . $norm
    ) );
}

function lcrm_wa_get_all_accounts() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT a.*, b.name AS brand_name FROM {$wpdb->prefix}lcrm_wa_accounts a
         LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id = a.brand_id
         ORDER BY a.display_name ASC"
    );
}

function lcrm_wa_insert_event( array $data ) {
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_wa_events", [
        'lead_id'       => $data['lead_id']       ?: null,
        'wa_account_id' => (int) $data['wa_account_id'],
        'wa_message_id' => $data['wa_message_id'] ?? '',
        'from_number'   => $data['from_number']   ?? '',
        'to_number'     => $data['to_number']     ?? '',
        'direction'     => $data['direction']     ?? 'inbound',
        'event_type'    => $data['event_type']    ?? 'message',
        'message_body'  => $data['message_body']  ?? '',
        'media_type'    => $data['media_type']    ?? '',
        'call_duration' => (int) ($data['call_duration'] ?? 0),
        'is_read'       => 0,
        'capture_status'=> $data['capture_status'] ?? 'captured',
        'capture_score' => (int) ($data['capture_score'] ?? 0),
        'raw_payload'   => $data['raw_payload']   ?? '{}',
        'event_time'    => $data['event_time']    ?? current_time( 'mysql' ),
    ] );
    return (int) $wpdb->insert_id;
}

function lcrm_wa_log_to_activity( $lead_id, $event_type, $body, $phone ) {
    if ( $event_type === 'message' ) {
        $msg = $body ? 'WhatsApp: ' . mb_substr( $body, 0, 100 ) : 'WhatsApp message received';
        lcrm_log_activity( $lead_id, 'whatsapp', $msg );
    } elseif ( $event_type === 'call_missed' ) {
        lcrm_log_activity( $lead_id, 'call_missed', 'Missed WhatsApp call from ' . $phone );
    } elseif ( $event_type === 'call_incoming' ) {
        lcrm_log_activity( $lead_id, 'call', 'Incoming WhatsApp call from ' . $phone );
    }
}

function lcrm_wa_create_missed_call_reminder( $lead_id, $phone, $account ) {
    global $wpdb;
    $delay_mins = (int) get_option( 'lcrm_wa_missed_call_reminder_mins', 60 );
    $remind_at  = date( 'Y-m-d H:i:s', time() + ( $delay_mins * 60 ) );
    $wpdb->insert( "{$wpdb->prefix}lcrm_reminders", [
        'lead_id'    => $lead_id,
        'remind_at'  => $remind_at,
        'note'       => 'Missed WhatsApp call from ' . $phone . ' — callback needed',
        'sent'       => 0,
        'created_by' => 0,
        'created_at' => current_time( 'mysql' ),
    ] );
}

/**
 * Helper to approve a pending_review event and create a lead from it.
 * Called from wa-ajax.php when admin clicks Approve.
 */
function lcrm_wa_approve_event( $event_id ) {
    global $wpdb;
    $event = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_wa_events WHERE id = %d AND capture_status = 'pending_review' LIMIT 1",
        $event_id
    ) );
    if ( ! $event ) return false;

    $account = lcrm_wa_get_account( $event->wa_account_id );

    // Re-run through the actual lead creation (same as capture path)
    lcrm_wa_capture_engine( [
        'from'       => $event->from_number,
        'body'       => $event->message_body,
        'message_id' => 'approved_' . $event_id . '_' . time(), // unique to avoid dup check
        'media_type' => $event->media_type,
        'event_type' => $event->event_type,
        'duration'   => $event->call_duration,
        'event_time' => $event->event_time,
        'raw'        => [],
    ], $account );

    // Mark the original review event as captured (so it leaves the queue)
    $wpdb->update(
        "{$wpdb->prefix}lcrm_wa_events",
        [ 'capture_status' => 'captured' ],
        [ 'id' => $event_id ]
    );
    return true;
}
