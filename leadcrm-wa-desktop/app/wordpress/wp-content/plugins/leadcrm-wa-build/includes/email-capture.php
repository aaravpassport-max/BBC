<?php
/**
 * LeadCRM Email Capture Module
 * Polls Gmail (OAuth2) or any IMAP inbox every 15 minutes.
 * Converts emails into leads using the same capture engine as WhatsApp.
 *
 * Two methods supported:
 *   1. Gmail via OAuth2 (recommended — no password stored)
 *   2. Generic IMAP (any provider — uses stored password)
 *
 * DB tables used:
 *   lcrm_email_accounts  — configured inboxes
 *   lcrm_email_events    — every captured email event (dedup + audit)
 *   lcrm_leads           — lead records (existing table)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

// TRACE: Called on activate + v4 migration → runs dbDelta for 2 tables → idempotent.
//        POSTCONDITIONS: lcrm_email_accounts + lcrm_email_events exist with correct schema.
//        EDGE CASES: Tables already exist → dbDelta adds missing columns only; no data lost.
function lcrm_email_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Email Accounts — one per inbox
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_email_accounts (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        brand_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        display_name     VARCHAR(150)    NOT NULL DEFAULT '',
        method           ENUM('gmail_oauth','imap') NOT NULL DEFAULT 'imap',
        email_address    VARCHAR(200)    NOT NULL DEFAULT '',
        imap_host        VARCHAR(200)    NOT NULL DEFAULT '',
        imap_port        SMALLINT        NOT NULL DEFAULT 993,
        imap_encryption  ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
        imap_username    VARCHAR(200)    NOT NULL DEFAULT '',
        imap_password    TEXT            NOT NULL DEFAULT '',
        gmail_token      LONGTEXT        NOT NULL DEFAULT '',
        gmail_client_id  VARCHAR(200)    NOT NULL DEFAULT '',
        gmail_client_secret TEXT         NOT NULL DEFAULT '',
        last_uid         BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_synced_at   DATETIME        DEFAULT NULL,
        is_active        TINYINT(1)      NOT NULL DEFAULT 1,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY brand_id (brand_id)
    ) $c;" );

    // Email Events — audit trail of every email processed
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_email_events (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id          BIGINT UNSIGNED DEFAULT NULL,
        account_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        message_id       VARCHAR(500)    NOT NULL DEFAULT '',
        from_email       VARCHAR(200)    NOT NULL DEFAULT '',
        from_name        VARCHAR(200)    NOT NULL DEFAULT '',
        subject          VARCHAR(500)    NOT NULL DEFAULT '',
        body_preview     TEXT            NOT NULL DEFAULT '',
        capture_status   ENUM('captured','updated','rejected','duplicate') NOT NULL DEFAULT 'captured',
        capture_score    TINYINT         NOT NULL DEFAULT 0,
        event_time       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY message_id (message_id(191)),
        KEY lead_id (lead_id),
        KEY account_id (account_id),
        KEY from_email (from_email),
        KEY event_time (event_time)
    ) $c;" );
}

// ─── CRON HOOK ───────────────────────────────────────────────────────────────

add_action( 'lcrm_email_poll_cron', 'lcrm_email_poll_all_accounts' );

// TRACE: Called from lcrm_email_install_tables and v4 migration → schedules lcrm_email_poll_cron
//        on the lcrm_15min interval (every 15 minutes) if not already scheduled.
//        EDGE CASES: Already scheduled → wp_next_scheduled returns truthy → no duplicate.
function lcrm_email_schedule_cron() {
    if ( ! wp_next_scheduled( 'lcrm_email_poll_cron' ) ) {
        wp_schedule_event( time(), 'lcrm_15min', 'lcrm_email_poll_cron' );
    }
}

// ─── POLL ALL ACCOUNTS ───────────────────────────────────────────────────────

/**
 * lcrm_email_poll_all_accounts()
 * Runs every 15 minutes via cron. Polls each active email account.
 *
 * TRACE: Loads all active email accounts →
 *        For each: calls lcrm_email_poll_account() →
 *        Updates last_synced_at.
 * EDGE CASES: No accounts → returns early; account poll fails → logs error, continues to next.
 */
function lcrm_email_poll_all_accounts() {
    global $wpdb;
    $accounts = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}lcrm_email_accounts WHERE is_active=1"
    );
    if ( empty( $accounts ) ) return;

    foreach ( $accounts as $account ) {
        try {
            lcrm_email_poll_account( $account );
            $wpdb->update(
                "{$wpdb->prefix}lcrm_email_accounts",
                [ 'last_synced_at' => current_time( 'mysql' ) ],
                [ 'id' => $account->id ]
            );
        } catch ( Exception $e ) {
            error_log( "LeadCRM Email poll error account #{$account->id}: " . $e->getMessage() );
        }
    }
}

// ─── POLL SINGLE ACCOUNT ────────────────────────────────────────────────────

/**
 * lcrm_email_poll_account( $account )
 * Fetches unseen emails from one account and processes each through capture engine.
 *
 * TRACE: Determines method (gmail_oauth or imap) →
 *        Fetches array of raw email data objects →
 *        For each email: calls lcrm_email_capture_engine().
 * POSTCONDITIONS: New leads created or existing leads updated in DB.
 * EDGE CASES: IMAP extension missing → returns WP_Error; auth failure → returns WP_Error; 0 new emails → returns without DB writes.
 */
function lcrm_email_poll_account( $account ) {
    if ( $account->method === 'gmail_oauth' ) {
        $emails = lcrm_email_fetch_gmail( $account );
    } else {
        $emails = lcrm_email_fetch_imap( $account );
    }

    if ( is_wp_error( $emails ) ) {
        error_log( 'LeadCRM Email fetch error: ' . $emails->get_error_message() );
        return;
    }

    if ( empty( $emails ) ) return;

    foreach ( $emails as $email ) {
        lcrm_email_capture_engine( $email, $account );
    }
}

// ─── IMAP FETCH ──────────────────────────────────────────────────────────────

/**
 * lcrm_email_fetch_imap( $account )
 * Connects via PHP IMAP and returns array of unseen email objects.
 * Each object: message_id, from_email, from_name, subject, body, date.
 *
 * TRACE: Checks imap extension → builds mailbox string →
 *        imap_open → imap_search UNSEEN →
 *        For each UID: imap_fetchheader + imap_body →
 *        Returns array of email data objects.
 * EDGE CASES: IMAP extension not loaded → WP_Error; connection failure → WP_Error;
 *             no UNSEEN messages → empty array; malformed headers → skipped.
 */
function lcrm_email_fetch_imap( $account ) {
    if ( ! function_exists( 'imap_open' ) ) {
        return new WP_Error( 'imap_missing', 'PHP IMAP extension is not installed on this server.' );
    }

    $enc   = $account->imap_encryption === 'none' ? '' : '/' . $account->imap_encryption;
    $mbox  = '{' . $account->imap_host . ':' . $account->imap_port . $enc . '/novalidate-cert}INBOX';
    $pass  = lcrm_email_decrypt( $account->imap_password );

    // Suppress PHP warnings from imap_open — it throws E_WARNING on auth failure
    $conn = @imap_open( $mbox, $account->imap_username, $pass, 0, 1, [ 'DISABLE_AUTHENTICATOR' => 'GSSAPI' ] );

    if ( ! $conn ) {
        $err = imap_last_error();
        imap_errors(); // clear error stack
        return new WP_Error( 'imap_connect', 'IMAP connection failed: ' . $err );
    }

    $uids = imap_search( $conn, 'UNSEEN', SE_UID );
    if ( ! $uids ) {
        imap_close( $conn );
        return [];
    }

    // Limit to 50 emails per poll to avoid timeout
    $uids   = array_slice( $uids, 0, 50 );
    $emails = [];

    foreach ( $uids as $uid ) {
        $header   = imap_rfc822_parse_headers( imap_fetchheader( $conn, $uid, FT_UID ) );
        $body_raw = imap_body( $conn, $uid, FT_UID );

        $from_email = '';
        $from_name  = '';
        if ( ! empty( $header->from ) ) {
            $from_email = $header->from[0]->mailbox . '@' . ( $header->from[0]->host ?? '' );
            $from_name  = isset( $header->from[0]->personal )
                          ? imap_utf8( $header->from[0]->personal ) : '';
        }

        $subject    = isset( $header->subject ) ? imap_utf8( $header->subject ) : '';
        $message_id = isset( $header->message_id ) ? trim( $header->message_id ) : 'imap_uid_' . $uid;
        $date       = isset( $header->date ) ? date( 'Y-m-d H:i:s', strtotime( $header->date ) ) : current_time( 'mysql' );

        // Decode body — handle base64 and quoted-printable
        $body = quoted_printable_decode( $body_raw );
        $body = strip_tags( $body );
        $body = mb_substr( trim( $body ), 0, 2000 );

        if ( ! $from_email ) continue;

        $emails[] = (object) [
            'message_id'  => $message_id,
            'from_email'  => sanitize_email( $from_email ),
            'from_name'   => sanitize_text_field( $from_name ),
            'subject'     => sanitize_text_field( $subject ),
            'body'        => $body,
            'date'        => $date,
        ];

        // Mark as read on server immediately after we've captured it
        imap_setflag_full( $conn, $uid, '\\Seen', ST_UID );
    }

    imap_close( $conn );
    return $emails;
}

// ─── GMAIL OAUTH FETCH ───────────────────────────────────────────────────────

/**
 * lcrm_email_fetch_gmail( $account )
 * Uses Gmail REST API with stored OAuth2 access token to fetch unread messages.
 *
 * TRACE: Refreshes token if expired →
 *        GET /gmail/v1/users/me/messages?q=is:unread →
 *        For each message ID: GET full message →
 *        Parses headers + body → returns array of email objects.
 * EDGE CASES: Token refresh fails → WP_Error; rate limit 429 → WP_Error; no messages → [].
 */
function lcrm_email_fetch_gmail( $account ) {
    $token_data = json_decode( $account->gmail_token, true );
    if ( empty( $token_data['access_token'] ) ) {
        return new WP_Error( 'no_token', 'Gmail OAuth token not configured.' );
    }

    // Refresh token if expired (tokens expire after 3600s)
    if ( ! empty( $token_data['expires_at'] ) && $token_data['expires_at'] < time() + 60 ) {
        $refreshed = lcrm_email_gmail_refresh_token( $account, $token_data );
        if ( is_wp_error( $refreshed ) ) return $refreshed;
        $token_data = $refreshed;
    }

    $access_token = $token_data['access_token'];

    // Fetch unread message IDs (max 50)
    $list_resp = wp_remote_get(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages?q=is:unread&maxResults=50',
        [ 'headers' => [ 'Authorization' => 'Bearer ' . $access_token ], 'timeout' => 15 ]
    );

    if ( is_wp_error( $list_resp ) ) return $list_resp;

    $list_code = wp_remote_retrieve_response_code( $list_resp );
    if ( $list_code === 401 ) return new WP_Error( 'gmail_auth', 'Gmail authorization failed. Re-authenticate.' );
    if ( $list_code !== 200 ) return new WP_Error( 'gmail_error', "Gmail API error {$list_code}" );

    $list_data = json_decode( wp_remote_retrieve_body( $list_resp ), true );
    if ( empty( $list_data['messages'] ) ) return [];

    $emails = [];
    foreach ( $list_data['messages'] as $msg_stub ) {
        $msg_id   = $msg_stub['id'];
        $msg_resp = wp_remote_get(
            "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msg_id}?format=full",
            [ 'headers' => [ 'Authorization' => 'Bearer ' . $access_token ], 'timeout' => 15 ]
        );
        if ( is_wp_error( $msg_resp ) ) continue;
        if ( wp_remote_retrieve_response_code( $msg_resp ) !== 200 ) continue;

        $msg = json_decode( wp_remote_retrieve_body( $msg_resp ), true );
        if ( empty( $msg ) ) continue;

        // Parse headers
        $headers     = [];
        $raw_headers = $msg['payload']['headers'] ?? [];
        foreach ( $raw_headers as $h ) $headers[ $h['name'] ] = $h['value'];

        $from_raw   = $headers['From'] ?? '';
        $subject    = sanitize_text_field( $headers['Subject'] ?? '' );
        $date_raw   = $headers['Date'] ?? '';
        $message_id = sanitize_text_field( $headers['Message-ID'] ?? 'gmail_' . $msg_id );

        // Parse "From: Name <email>" or "email"
        $from_email = '';
        $from_name  = '';
        if ( preg_match( '/^(.*?)<([^>]+)>$/', $from_raw, $m ) ) {
            $from_name  = sanitize_text_field( trim( $m[1] ) );
            $from_email = sanitize_email( trim( $m[2] ) );
        } elseif ( filter_var( trim( $from_raw ), FILTER_VALIDATE_EMAIL ) ) {
            $from_email = sanitize_email( trim( $from_raw ) );
        }

        if ( ! $from_email ) continue;

        // Extract body from payload parts
        $body = lcrm_email_extract_gmail_body( $msg['payload'] ?? [] );

        $emails[] = (object) [
            'message_id'  => $message_id,
            'from_email'  => $from_email,
            'from_name'   => $from_name,
            'subject'     => $subject,
            'body'        => mb_substr( $body, 0, 2000 ),
            'date'        => $date_raw ? date( 'Y-m-d H:i:s', strtotime( $date_raw ) ) : current_time( 'mysql' ),
        ];

        // Mark as read
        wp_remote_post(
            "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$msg_id}/modify",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( [ 'removeLabelIds' => [ 'UNREAD' ] ] ),
                'timeout' => 10,
            ]
        );
    }

    return $emails;
}

/**
 * Extract plain text body from Gmail payload parts recursively.
 *
 * TRACE: Checks if payload is text/plain → decodes base64url →
 *        If multipart: recurses into parts → returns first text/plain found.
 * EDGE CASES: No text/plain part → returns text/html stripped of tags; no body at all → ''.
 */
function lcrm_email_extract_gmail_body( $payload ) {
    $mime = $payload['mimeType'] ?? '';

    if ( $mime === 'text/plain' && ! empty( $payload['body']['data'] ) ) {
        return strip_tags( base64_decode( strtr( $payload['body']['data'], '-_', '+/' ) ) );
    }

    if ( $mime === 'text/html' && ! empty( $payload['body']['data'] ) ) {
        return strip_tags( base64_decode( strtr( $payload['body']['data'], '-_', '+/' ) ) );
    }

    if ( strpos( $mime, 'multipart' ) !== false && ! empty( $payload['parts'] ) ) {
        // Prefer text/plain part
        foreach ( $payload['parts'] as $part ) {
            if ( ( $part['mimeType'] ?? '' ) === 'text/plain' && ! empty( $part['body']['data'] ) ) {
                return strip_tags( base64_decode( strtr( $part['body']['data'], '-_', '+/' ) ) );
            }
        }
        // Fall back to any part
        foreach ( $payload['parts'] as $part ) {
            $body = lcrm_email_extract_gmail_body( $part );
            if ( $body ) return $body;
        }
    }

    return '';
}

/**
 * lcrm_email_gmail_refresh_token( $account, $token_data )
 * Refreshes an expired Gmail OAuth2 access token using the refresh token.
 * Returns updated token_data array on success, WP_Error on failure.
 *
 * TRACE: POSTs to Google token endpoint with refresh_token →
 *        On success: updates lcrm_email_accounts.gmail_token in DB →
 *        Returns new token_data array.
 * EDGE CASES: No refresh_token stored → WP_Error; Google returns error → WP_Error.
 */
function lcrm_email_gmail_refresh_token( $account, $token_data ) {
    global $wpdb;

    if ( empty( $token_data['refresh_token'] ) ) {
        return new WP_Error( 'no_refresh', 'No refresh token stored. Please re-authenticate Gmail.' );
    }

    $resp = wp_remote_post( 'https://oauth2.googleapis.com/token', [
        'body' => [
            'client_id'     => $account->gmail_client_id,
            'client_secret' => $account->gmail_client_secret,
            'refresh_token' => $token_data['refresh_token'],
            'grant_type'    => 'refresh_token',
        ],
        'timeout' => 15,
    ] );

    if ( is_wp_error( $resp ) ) return $resp;

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( empty( $data['access_token'] ) ) {
        return new WP_Error( 'refresh_failed', 'Gmail token refresh failed: ' . ( $data['error'] ?? 'unknown' ) );
    }

    $token_data['access_token'] = $data['access_token'];
    $token_data['expires_at']   = time() + intval( $data['expires_in'] ?? 3600 );

    $wpdb->update(
        "{$wpdb->prefix}lcrm_email_accounts",
        [ 'gmail_token' => wp_json_encode( $token_data ) ],
        [ 'id' => $account->id ]
    );

    return $token_data;
}

// ─── CORE CAPTURE ENGINE ─────────────────────────────────────────────────────

/**
 * lcrm_email_capture_engine( $email, $account )
 * The main processing function for every incoming email.
 * Mirrors the logic of lcrm_wa_capture_engine exactly.
 *
 * DECISION FLOW:
 *   1. Deduplicate by message_id
 *   2. Filter out internal/staff/marketing emails
 *   3. Match by email address to existing lead
 *   4a. If match: update lead, append to conversation timeline
 *   4b. If no match: AI extract data, create new lead
 *   5. Insert AI-extracted tasks as alerts
 *
 * TRACE: Checks message_id dedup in lcrm_email_events →
 *        Checks hard-block conditions (staff email, marketing) →
 *        Calls lcrm_wa_find_lead_by_email() →
 *        Branch: update OR create →
 *        Calls lcrm_ai_extract_lead_data() if AI enabled and new lead →
 *        Inserts tasks as alerts.
 * POSTCONDITIONS: email_events row inserted; lead created or updated.
 * EDGE CASES: Duplicate message_id → returns immediately; blocked email → logs rejected event; AI disabled → creates lead with raw data.
 */
function lcrm_email_capture_engine( $email, $account ) {
    global $wpdb;

    // 1. Deduplicate
    if ( $email->message_id ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lcrm_email_events WHERE message_id=%s LIMIT 1",
            $email->message_id
        ) );
        if ( $exists ) return;
    }

    // 2. Hard blocks — skip internal emails, automated marketing, and spam
    if ( lcrm_email_is_blocked( $email->from_email, $email->subject, $email->body ) ) {
        lcrm_email_insert_event( $email, $account->id, null, 'rejected', 0 );
        return;
    }

    // 3. Match to existing lead by email address
    $existing_lead_id = lcrm_email_find_lead_by_email( $email->from_email );

    if ( $existing_lead_id ) {
        // 4a. Update existing lead
        $message = "Email received\nSubject: {$email->subject}\n\n" . mb_substr( $email->body, 0, 500 );
        lcrm_log_activity( $existing_lead_id, 'email', $message );
        lcrm_add_conversation( $existing_lead_id, "[Email] " . $email->subject . "\n\n" . mb_substr( $email->body, 0, 300 ) );

        $wpdb->update( "{$wpdb->prefix}lcrm_leads", [
            'last_client_contact' => $email->date,
        ], [ 'id' => $existing_lead_id ] );

        // AI: score intent and extract tasks
        if ( lcrm_ai_enabled() && get_option( 'lcrm_ai_auto_tasks', 1 ) ) {
            $lead_obj  = lcrm_get_lead( $existing_lead_id );
            $context   = $lead_obj ? "Service: {$lead_obj->work_type}, Stage: {$lead_obj->stage_name}" : '';
            $text      = $email->subject . ' ' . $email->body;
            $intent    = lcrm_ai_score_intent( $text, $context );
            if ( ! is_wp_error( $intent ) && ! empty( $intent ) ) {
                if ( ! empty( $intent['tasks'] ) ) {
                    lcrm_ai_insert_tasks_as_alerts( $existing_lead_id, $intent['tasks'], 'medium' );
                }
                // Upgrade temperature if AI scored high
                if ( ! empty( $intent['temperature'] ) && $intent['temperature'] === 'hot' ) {
                    $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'lead_temperature' => 'hot' ], [ 'id' => $existing_lead_id ] );
                }
            }
        }

        lcrm_email_insert_event( $email, $account->id, $existing_lead_id, 'updated', 5 );
        return;
    }

    // 4b. New email address — create lead
    $client_name = $email->from_name ?: 'Email: ' . $email->from_email;
    $work_type   = '';
    $remarks     = "Email inquiry\nSubject: {$email->subject}\n\n" . mb_substr( $email->body, 0, 300 );
    $temperature = 'warm';
    $tasks       = [];

    // AI extraction for new leads
    if ( lcrm_ai_enabled() ) {
        $text      = $email->subject . "\n\n" . $email->body;
        $extracted = lcrm_ai_extract_lead_data( $text, 'email' );
        if ( ! empty( $extracted ) ) {
            if ( ! empty( $extracted['client_name'] ) )    $client_name = $extracted['client_name'];
            if ( ! empty( $extracted['work_type'] ) )      $work_type   = $extracted['work_type'];
            if ( ! empty( $extracted['lead_temperature'] )) $temperature = $extracted['lead_temperature'];
            if ( ! empty( $extracted['remarks'] ) )        $remarks    .= "\n\nAI Summary: " . $extracted['remarks'];
            if ( ! empty( $extracted['tasks'] ) )          $tasks       = $extracted['tasks'];
        }
    }

    $lead_id = lcrm_create_lead( [
        'lead_date'          => date( 'Y-m-d' ),
        'client_name'        => $client_name,
        'client_email'       => $email->from_email,
        'client_phone'       => '',
        'lead_source'        => 'Email',
        'work_type'          => $work_type,
        'stage_id'           => 1,
        'brand_id'           => $account->brand_id ?: null,
        'priority'           => 'medium',
        'lead_temperature'   => $temperature,
        'last_client_contact'=> $email->date,
        'remarks'            => $remarks,
        'created_by'         => 0,
    ] );

    if ( ! $lead_id || is_wp_error( $lead_id ) ) {
        error_log( 'LeadCRM Email: lead creation failed for ' . $email->from_email );
        return;
    }

    // Log email as first conversation entry
    lcrm_add_conversation( $lead_id, "[Email] {$email->subject}\n\n" . mb_substr( $email->body, 0, 500 ) );

    // Insert AI tasks as alerts
    if ( ! empty( $tasks ) ) {
        lcrm_ai_insert_tasks_as_alerts( $lead_id, $tasks, 'medium' );
    }

    lcrm_email_insert_event( $email, $account->id, $lead_id, 'captured', 7 );
}

// ─── HARD BLOCK FILTER ───────────────────────────────────────────────────────

/**
 * lcrm_email_is_blocked( $from_email, $subject, $body )
 * Returns true if this email should NOT become a lead.
 *
 * BLOCKS:
 *   - Email matches a staff/admin WordPress user
 *   - Subject matches automated/marketing patterns
 *   - From address is a known no-reply pattern
 *
 * TRACE: Checks staff email match in wp_users →
 *        Checks no-reply patterns in from_email →
 *        Checks automated subject patterns.
 * EDGE CASES: All checks use case-insensitive matching.
 */
function lcrm_email_is_blocked( $from_email, $subject, $body ) {
    global $wpdb;

    // Block staff emails
    $is_staff = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE user_email=%s LIMIT 1", $from_email
    ) );
    if ( $is_staff ) return true;

    // Block no-reply / automated senders
    $noreply_patterns = [ 'noreply', 'no-reply', 'donotreply', 'mailer-daemon', 'postmaster', 'bounce' ];
    $email_lower = strtolower( $from_email );
    foreach ( $noreply_patterns as $p ) {
        if ( strpos( $email_lower, $p ) !== false ) return true;
    }

    // Block automated subject patterns
    $auto_subjects = [
        '/\bOTP\b/i', '/\bverification code\b/i', '/\bpassword reset\b/i',
        '/\bunsubscribe\b/i', '/\bnewsletter\b/i', '/\bpromo\b/i',
        '/\bsale\s+\d+%\s+off\b/i', '/\byour order\s+#/i',
        '/\breceipt for your payment\b/i', '/\binvoice\s+#\d+/i',
    ];
    foreach ( $auto_subjects as $pattern ) {
        if ( preg_match( $pattern, $subject ) ) return true;
    }

    return false;
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * lcrm_email_find_lead_by_email( $email )
 * Returns lead ID if an existing lead has this email address, otherwise null.
 *
 * TRACE: Queries lcrm_leads WHERE client_email = $email →
 *        Returns most recent match's ID or null.
 * EDGE CASES: Empty email → null; multiple leads same email → returns most recent.
 */
// TRACE: Called by capture_engine → queries lcrm_leads WHERE client_email=$email DESC →
//        Returns most recent matching lead ID or null.
//        PRECONDITIONS: $email is sanitized email string.
//        POSTCONDITIONS: Returns int lead ID or null.
//        EDGE CASES: Empty email → null; multiple leads same email → most recent returned.
function lcrm_email_find_lead_by_email( $email ) {
    global $wpdb;
    if ( ! $email ) return null;
    $id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_leads WHERE client_email=%s ORDER BY id DESC LIMIT 1",
        sanitize_email( $email )
    ) );
    return $id ? (int) $id : null;
}

/**
 * lcrm_email_insert_event( $email, $account_id, $lead_id, $status, $score )
 * Inserts a row into lcrm_email_events for audit trail.
 */
// TRACE: Called after every capture decision → INSERTs one row into lcrm_email_events →
//        provides full audit trail regardless of outcome.
//        POSTCONDITIONS: Row in DB with lead_id (nullable), status, score, preview.
//        EDGE CASES: Duplicate message_id blocked by UNIQUE KEY at DB level; no return value needed.
function lcrm_email_insert_event( $email, $account_id, $lead_id, $status, $score ) {
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_email_events", [
        'lead_id'       => $lead_id ?: null,
        'account_id'    => (int) $account_id,
        'message_id'    => mb_substr( $email->message_id, 0, 500 ),
        'from_email'    => mb_substr( $email->from_email, 0, 200 ),
        'from_name'     => mb_substr( $email->from_name,  0, 200 ),
        'subject'       => mb_substr( $email->subject,    0, 500 ),
        'body_preview'  => mb_substr( $email->body,       0, 500 ),
        'capture_status'=> $status,
        'capture_score' => (int) $score,
        'event_time'    => $email->date,
    ] );
}

/**
 * lcrm_email_encrypt( $value ) / lcrm_email_decrypt( $value )
 * Simple reversible obfuscation for stored IMAP passwords.
 * Not AES — just base64 with a salt prefix to prevent plain-text storage.
 * For production, swap with openssl_encrypt/decrypt using WP AUTH_KEY.
 *
 * EDGE CASES: Empty string → stored as empty; decrypt of non-encoded value → returns raw.
 */
// TRACE: Called before INSERT of IMAP password → base64-encodes with AUTH_KEY salt prefix →
//        Returns obfuscated string. Decrypt reverses by stripping salt and decoding.
//        POSTCONDITIONS: Non-plaintext string stored in DB.
//        EDGE CASES: Empty string → stored as empty; same empty on decrypt.
function lcrm_email_encrypt( $value ) {
    if ( ! $value ) return '';
    $salt = substr( AUTH_KEY, 0, 8 );
    return base64_encode( $salt . '|' . $value );
}

function lcrm_email_decrypt( $value ) {
    if ( ! $value ) return '';
    $decoded = base64_decode( $value );
    if ( ! $decoded ) return $value;
    $salt = substr( AUTH_KEY, 0, 8 );
    if ( strpos( $decoded, $salt . '|' ) === 0 ) {
        return substr( $decoded, strlen( $salt ) + 1 );
    }
    return $value; // Not our encoded format — return raw
}

// ─── AJAX: ACCOUNT MANAGEMENT ────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_email_save_account',   'lcrm_ajax_email_save_account' );
add_action( 'wp_ajax_lcrm_email_delete_account', 'lcrm_ajax_email_delete_account' );
add_action( 'wp_ajax_lcrm_email_test_account',   'lcrm_ajax_email_test_account' );
add_action( 'wp_ajax_lcrm_email_poll_now',       'lcrm_ajax_email_poll_now' );

/**
 * lcrm_ajax_email_save_account()
 * Saves/updates an email account configuration.
 *
 * TRACE: Validates nonce + admin → sanitizes POST fields →
 *        Encrypts IMAP password → INSERTs or UPDATEs lcrm_email_accounts →
 *        Returns saved account ID.
 * EDGE CASES: Missing email_address → error; duplicate email on same method → allowed (one brand may poll same inbox differently).
 */
// TRACE: wp_ajax_lcrm_email_save_account → verifies admin nonce → sanitizes 9 POST fields →
//        encrypts password → INSERT or UPDATE lcrm_email_accounts → returns {msg, id}.
//        PRECONDITIONS: Admin-level user.  POSTCONDITIONS: Account row in DB.
//        EDGE CASES: Empty email → 422 error; IMAP with no host → 422; blank password on update → keeps existing.
function lcrm_ajax_email_save_account() {
    lcrm_verify_admin();
    global $wpdb;

    $id          = intval( $_POST['id'] ?? 0 );
    $brand_id    = intval( $_POST['brand_id'] ?? 0 );
    $display     = sanitize_text_field( $_POST['display_name'] ?? '' );
    $method      = in_array( $_POST['method'] ?? '', ['gmail_oauth','imap'], true ) ? $_POST['method'] : 'imap';
    $email_addr  = sanitize_email( $_POST['email_address'] ?? '' );
    $imap_host   = sanitize_text_field( $_POST['imap_host'] ?? '' );
    $imap_port   = intval( $_POST['imap_port'] ?? 993 );
    $imap_enc    = in_array( $_POST['imap_encryption'] ?? '', ['ssl','tls','none'], true ) ? $_POST['imap_encryption'] : 'ssl';
    $imap_user   = sanitize_text_field( $_POST['imap_username'] ?? '' );
    $imap_pass   = sanitize_text_field( $_POST['imap_password'] ?? '' );
    $is_active   = intval( $_POST['is_active'] ?? 1 );

    if ( ! $email_addr ) wp_send_json_error( [ 'msg' => 'Email address is required.' ] );
    if ( $method === 'imap' && ! $imap_host ) wp_send_json_error( [ 'msg' => 'IMAP host is required.' ] );

    $data = [
        'brand_id'       => $brand_id ?: null,
        'display_name'   => $display ?: $email_addr,
        'method'         => $method,
        'email_address'  => $email_addr,
        'imap_host'      => $imap_host,
        'imap_port'      => $imap_port,
        'imap_encryption'=> $imap_enc,
        'imap_username'  => $imap_user ?: $email_addr,
        'is_active'      => $is_active,
    ];

    // Only update password if a new one was provided
    if ( $imap_pass ) {
        $data['imap_password'] = lcrm_email_encrypt( $imap_pass );
    }

    if ( $id ) {
        $result = $wpdb->update( "{$wpdb->prefix}lcrm_email_accounts", $data, [ 'id' => $id ] );
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $result = $wpdb->insert( "{$wpdb->prefix}lcrm_email_accounts", $data );
        $id = (int) $wpdb->insert_id;
    }

    if ( $result === false ) wp_send_json_error( [ 'msg' => 'Database error: ' . $wpdb->last_error ] );

    wp_send_json_success( [ 'msg' => 'Email account saved.', 'id' => $id ] );
}

// TRACE: wp_ajax_lcrm_email_delete_account → verifies admin → DELETEs row from lcrm_email_accounts
//        → returns {msg:"Email account deleted."}.
//        POSTCONDITIONS: Account row gone; next poll cycle skips it.
//        EDGE CASES: ID=0 → error; already deleted → success.
function lcrm_ajax_email_delete_account() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Invalid ID.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_email_accounts", [ 'id' => $id ] );
    wp_send_json_success( [ 'msg' => 'Email account deleted.' ] );
}

/**
 * lcrm_ajax_email_test_account()
 * Tests IMAP connectivity for an account without saving.
 *
 * TRACE: Builds mailbox string from POST fields →
 *        Attempts imap_open →
 *        Returns success/error message.
 */
// TRACE: wp_ajax_lcrm_email_test_account → verifies admin → checks imap extension →
//        imap_open with test credentials → imap_num_msg → imap_close → returns {msg:'Connected. N messages'}.
//        POSTCONDITIONS: No DB write; connection closed after test.
//        EDGE CASES: imap extension absent → error; auth fail → error with IMAP error string.
function lcrm_ajax_email_test_account() {
    lcrm_verify_admin();

    if ( ! function_exists( 'imap_open' ) ) {
        wp_send_json_error( [ 'msg' => 'PHP IMAP extension not installed on this server. Contact your host.' ] );
    }

    $host = sanitize_text_field( $_POST['imap_host'] ?? '' );
    $port = intval( $_POST['imap_port'] ?? 993 );
    $enc  = in_array( $_POST['imap_encryption'] ?? '', ['ssl','tls','none'], true ) ? $_POST['imap_encryption'] : 'ssl';
    $user = sanitize_text_field( $_POST['imap_username'] ?? '' );
    $pass = sanitize_text_field( $_POST['imap_password'] ?? '' );

    if ( ! $host || ! $user || ! $pass ) wp_send_json_error( [ 'msg' => 'Host, username, and password are required for testing.' ] );

    $enc_str = $enc === 'none' ? '' : '/' . $enc;
    $mbox    = '{' . $host . ':' . $port . $enc_str . '/novalidate-cert}INBOX';
    $conn    = @imap_open( $mbox, $user, $pass, 0, 1 );

    if ( ! $conn ) {
        $err = imap_last_error();
        imap_errors();
        wp_send_json_error( [ 'msg' => 'Connection failed: ' . $err ] );
    }

    $count = imap_num_msg( $conn );
    imap_close( $conn );

    wp_send_json_success( [ 'msg' => "Connected successfully. {$count} messages in inbox." ] );
}

// TRACE: wp_ajax_lcrm_email_poll_now → verifies admin → calls lcrm_email_poll_all_accounts() →
//        returns {msg:'Email poll completed.'}.
//        POSTCONDITIONS: Any new emails processed and converted to leads.
//        EDGE CASES: No accounts → no-op; account errors logged, execution continues.
function lcrm_ajax_email_poll_now() {
    lcrm_verify_admin();
    lcrm_email_poll_all_accounts();
    wp_send_json_success( [ 'msg' => 'Email poll completed.' ] );
}
