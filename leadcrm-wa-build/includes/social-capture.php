<?php
/**
 * LeadCRM Social Capture Module
 * Handles Instagram Direct Messages and Facebook Messenger via Meta Graph API webhooks.
 * Architecture identical to wa-capture.php — same REST endpoint pattern, same capture engine.
 *
 * REST endpoints registered:
 *   GET/POST /lcrm/v1/social-webhook/{account_id}  (Instagram + FB Messenger)
 *
 * DB tables used:
 *   lcrm_social_accounts  — one per connected page/IG account
 *   lcrm_social_events    — every inbound DM event
 *   lcrm_leads            — existing table
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

// TRACE: Called on activate + v4 migration → dbDelta for lcrm_social_accounts + lcrm_social_events.
//        POSTCONDITIONS: Both tables exist. EDGE CASES: Idempotent — safe to re-run.
function lcrm_social_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_social_accounts (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        brand_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
        platform             ENUM('instagram','facebook') NOT NULL DEFAULT 'instagram',
        display_name         VARCHAR(150)    NOT NULL DEFAULT '',
        page_id              VARCHAR(100)    NOT NULL DEFAULT '',
        page_access_token    TEXT            NOT NULL DEFAULT '',
        webhook_verify_token VARCHAR(100)    NOT NULL DEFAULT '',
        ig_account_id        VARCHAR(100)    NOT NULL DEFAULT '',
        is_active            TINYINT(1)      NOT NULL DEFAULT 1,
        last_synced_at       DATETIME        DEFAULT NULL,
        created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY brand_id (brand_id),
        KEY platform (platform)
    ) $c;" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_social_events (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id          BIGINT UNSIGNED DEFAULT NULL,
        account_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        platform         ENUM('instagram','facebook') NOT NULL DEFAULT 'instagram',
        sender_id        VARCHAR(200)    NOT NULL DEFAULT '',
        sender_name      VARCHAR(200)    NOT NULL DEFAULT '',
        message_id       VARCHAR(300)    NOT NULL DEFAULT '',
        message_body     TEXT            NOT NULL DEFAULT '',
        media_type       VARCHAR(30)     NOT NULL DEFAULT '',
        capture_status   ENUM('captured','updated','rejected','blocklisted') NOT NULL DEFAULT 'captured',
        capture_score    TINYINT         NOT NULL DEFAULT 0,
        raw_payload      LONGTEXT        NOT NULL DEFAULT '{}',
        event_time       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY message_id (message_id(191)),
        KEY lead_id (lead_id),
        KEY account_id (account_id),
        KEY sender_id (sender_id)
    ) $c;" );
}

// ─── REST WEBHOOK ────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'lcrm_social_register_routes' );

// TRACE: Hook rest_api_init → registers GET+POST /lcrm/v1/social-webhook/{account_id}.
//        POSTCONDITIONS: Both routes available after wp-json bootstrap.
//        EDGE CASES: account_id sanitized via absint.
function lcrm_social_register_routes() {
    register_rest_route( 'lcrm/v1', '/social-webhook/(?P<account_id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'lcrm_social_webhook_verify',
            'permission_callback' => '__return_true',
            'args'                => [ 'account_id' => [ 'sanitize_callback' => 'absint' ] ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'lcrm_social_webhook_receive',
            'permission_callback' => '__return_true',
            'args'                => [ 'account_id' => [ 'sanitize_callback' => 'absint' ] ],
        ],
    ] );
}

/**
 * GET handler — Meta sends GET for webhook verification (same pattern as WhatsApp).
 *
 * TRACE: Loads account → checks hub_mode='subscribe' + token match →
 *        Returns hub_challenge as integer on success, 403 on failure.
 */
function lcrm_social_webhook_verify( WP_REST_Request $request ) {
    $account = lcrm_social_get_account( $request->get_param( 'account_id' ) );
    if ( ! $account ) return new WP_REST_Response( 'Not found', 403 );

    $mode      = $request->get_param( 'hub_mode' );
    $token     = $request->get_param( 'hub_verify_token' );
    $challenge = $request->get_param( 'hub_challenge' );

    if ( $mode === 'subscribe' && hash_equals( $account->webhook_verify_token, (string) $token ) ) {
        return new WP_REST_Response( (int) $challenge, 200 );
    }
    return new WP_REST_Response( 'Forbidden', 403 );
}

/**
 * POST handler — receives incoming social DM events.
 *
 * TRACE: Loads + validates account → parses JSON payload →
 *        Detects platform (Instagram vs Facebook) →
 *        Routes to correct parser →
 *        Returns 200 immediately (Meta requires fast response).
 * EDGE CASES: Invalid JSON → 200 with ignored status; inactive account → 200 ignored; unknown platform → 200 ignored.
 */
function lcrm_social_webhook_receive( WP_REST_Request $request ) {
    $account = lcrm_social_get_account( $request->get_param( 'account_id' ) );
    if ( ! $account || ! $account->is_active ) {
        return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
    }

    $data = json_decode( $request->get_body(), true );
    if ( ! is_array( $data ) ) return new WP_REST_Response( [ 'status' => 'invalid_json' ], 200 );

    $object = $data['object'] ?? '';

    if ( $object === 'instagram' ) {
        lcrm_social_process_instagram_payload( $data, $account );
    } elseif ( $object === 'page' ) {
        lcrm_social_process_facebook_payload( $data, $account );
    }

    return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
}

// ─── INSTAGRAM PAYLOAD PARSER ────────────────────────────────────────────────

/**
 * lcrm_social_process_instagram_payload( $data, $account )
 * Parses Meta's Instagram Messaging webhook payload.
 *
 * TRACE: Iterates entry → messaging array →
 *        Extracts sender PSID, message text/attachments →
 *        Calls lcrm_social_capture_engine().
 * EDGE CASES: Echo messages (from bot itself) → skipped; no message key → skipped.
 */
// TRACE: Called by webhook_receive when object='instagram' → iterates entry.messaging[] →
//        Skips echo messages → extracts sender_id, message, attachment type →
//        Calls lcrm_social_capture_engine for each valid message.
//        POSTCONDITIONS: Every non-echo message processed through capture engine.
//        EDGE CASES: is_echo=true → skipped; no sender_id → skipped; attachment-only → body='Attachment received'.
function lcrm_social_process_instagram_payload( $data, $account ) {
    foreach ( $data['entry'] ?? [] as $entry ) {
        foreach ( $entry['messaging'] ?? [] as $event ) {
            // Skip echo/outbound messages
            if ( ! empty( $event['message']['is_echo'] ) ) continue;

            $sender_id  = $event['sender']['id'] ?? '';
            $message    = $event['message'] ?? [];
            $message_id = $message['mid'] ?? '';
            $body       = $message['text'] ?? '';
            $media_type = '';

            if ( ! empty( $message['attachments'] ) ) {
                $att        = $message['attachments'][0];
                $media_type = $att['type'] ?? 'attachment';
                if ( ! $body ) $body = ucfirst( $media_type ) . ' received';
            }

            if ( ! $sender_id ) continue;

            $timestamp = isset( $event['timestamp'] )
                ? date( 'Y-m-d H:i:s', intval( $event['timestamp'] ) / 1000 )
                : current_time( 'mysql' );

            lcrm_social_capture_engine( [
                'platform'   => 'instagram',
                'sender_id'  => $sender_id,
                'sender_name'=> lcrm_social_resolve_sender_name( $sender_id, $account ),
                'message_id' => $message_id,
                'body'       => $body,
                'media_type' => $media_type,
                'event_time' => $timestamp,
                'raw'        => $event,
            ], $account );
        }
    }
}

/**
 * lcrm_social_process_facebook_payload( $data, $account )
 * Parses Meta's Facebook Page Messaging webhook payload.
 * Identical structure to Instagram with different object type.
 */
// TRACE: Called by webhook_receive when object="page" → iterates entry.messaging[] →
//        Skips echo messages → extracts sender_id, message text, attachment type →
//        Calls lcrm_social_capture_engine for each valid event.
//        EDGE CASES: Identical to Instagram parser; different object type only.
function lcrm_social_process_facebook_payload( $data, $account ) {
    foreach ( $data['entry'] ?? [] as $entry ) {
        foreach ( $entry['messaging'] ?? [] as $event ) {
            if ( ! empty( $event['message']['is_echo'] ) ) continue;

            $sender_id  = $event['sender']['id'] ?? '';
            $message    = $event['message'] ?? [];
            $message_id = $message['mid'] ?? '';
            $body       = $message['text'] ?? '';
            $media_type = '';

            if ( ! empty( $message['attachments'] ) ) {
                $att        = $message['attachments'][0];
                $media_type = $att['type'] ?? 'attachment';
                if ( ! $body ) $body = ucfirst( $media_type ) . ' received';
            }

            if ( ! $sender_id ) continue;

            $timestamp = isset( $event['timestamp'] )
                ? date( 'Y-m-d H:i:s', intval( $event['timestamp'] ) / 1000 )
                : current_time( 'mysql' );

            lcrm_social_capture_engine( [
                'platform'   => 'facebook',
                'sender_id'  => $sender_id,
                'sender_name'=> lcrm_social_resolve_sender_name( $sender_id, $account ),
                'message_id' => $message_id,
                'body'       => $body,
                'media_type' => $media_type,
                'event_time' => $timestamp,
                'raw'        => $event,
            ], $account );
        }
    }
}

// ─── CORE CAPTURE ENGINE ────────────────────────────────────────────────────

/**
 * lcrm_social_capture_engine( $event, $account )
 * Main processing function for all social DMs. Mirrors lcrm_wa_capture_engine.
 *
 * TRACE: Deduplicates by message_id →
 *        Finds existing lead by social_sender_id (stored in custom_fields JSON) →
 *        Branch: update OR create new lead →
 *        Inserts social_events row.
 * POSTCONDITIONS: social_events row inserted; lead created or updated.
 * EDGE CASES: Duplicate message_id → return; sender is page admin → block; AI disabled → basic lead creation.
 */
function lcrm_social_capture_engine( array $event, $account ) {
    global $wpdb;

    $platform   = $event['platform'];
    $sender_id  = $event['sender_id'];
    $sender_name= $event['sender_name'];
    $message_id = $event['message_id'];
    $body       = $event['body'];
    $media_type = $event['media_type'];
    $event_time = $event['event_time'];
    $raw        = wp_json_encode( $event['raw'] ?? [] );

    // 1. Deduplicate
    if ( $message_id ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lcrm_social_events WHERE message_id=%s LIMIT 1",
            $message_id
        ) );
        if ( $exists ) return;
    }

    // 2. Find existing lead by social platform sender ID
    $existing_lead_id = lcrm_social_find_lead_by_sender( $platform, $sender_id );

    if ( $existing_lead_id ) {
        // Update existing lead
        $msg = "[{$platform} DM] " . mb_substr( $body, 0, 500 );
        lcrm_log_activity( $existing_lead_id, 'social_dm', $msg );
        lcrm_add_conversation( $existing_lead_id, $msg );
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'last_client_contact' => $event_time ], [ 'id' => $existing_lead_id ] );

        // AI intent scoring
        if ( lcrm_ai_enabled() && get_option( 'lcrm_ai_auto_tasks', 1 ) && $body ) {
            $intent = lcrm_ai_score_intent( $body );
            if ( ! is_wp_error( $intent ) && ! empty( $intent['tasks'] ) ) {
                lcrm_ai_insert_tasks_as_alerts( $existing_lead_id, $intent['tasks'] );
            }
            if ( ! empty( $intent['temperature'] ) && $intent['temperature'] === 'hot' ) {
                $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'lead_temperature' => 'hot' ], [ 'id' => $existing_lead_id ] );
            }
        }

        lcrm_social_insert_event( $event, $account->id, $existing_lead_id, 'updated', 5, $raw );
        return;
    }

    // 3. New sender — create lead
    $client_name = $sender_name ?: ucfirst( $platform ) . ' User';
    $source_label= ucfirst( $platform );
    $remarks     = "{$source_label} DM: " . mb_substr( $body, 0, 300 );
    $temperature = 'warm';
    $work_type   = '';
    $tasks       = [];

    if ( lcrm_ai_enabled() && $body ) {
        $extracted = lcrm_ai_extract_lead_data( $body, $source_label . ' DM' );
        if ( ! empty( $extracted ) ) {
            if ( ! empty( $extracted['client_name'] ) && $extracted['client_name'] !== $client_name )
                $client_name = $extracted['client_name'];
            if ( ! empty( $extracted['work_type'] ) )      $work_type   = $extracted['work_type'];
            if ( ! empty( $extracted['lead_temperature'] )) $temperature = $extracted['lead_temperature'];
            if ( ! empty( $extracted['tasks'] ) )          $tasks       = $extracted['tasks'];
        }
    }

    // Store social sender ID in custom_fields so we can re-match future messages
    $custom_fields = wp_json_encode( [
        'social_platform'  => $platform,
        'social_sender_id' => $sender_id,
    ] );

    $lead_id = lcrm_create_lead( [
        'lead_date'          => date( 'Y-m-d' ),
        'client_name'        => $client_name,
        'client_phone'       => '',
        'client_email'       => '',
        'lead_source'        => $source_label,
        'work_type'          => $work_type,
        'stage_id'           => 1,
        'brand_id'           => $account->brand_id ?: null,
        'priority'           => 'medium',
        'lead_temperature'   => $temperature,
        'last_client_contact'=> $event_time,
        'remarks'            => $remarks,
        'custom_fields'      => $custom_fields,
        'created_by'         => 0,
    ] );

    if ( ! $lead_id || is_wp_error( $lead_id ) ) {
        error_log( "LeadCRM Social: lead creation failed for {$platform} sender {$sender_id}" );
        return;
    }

    lcrm_add_conversation( $lead_id, "[{$source_label} DM] " . mb_substr( $body, 0, 500 ) );
    if ( ! empty( $tasks ) ) lcrm_ai_insert_tasks_as_alerts( $lead_id, $tasks );

    lcrm_social_insert_event( $event, $account->id, $lead_id, 'captured', 7, $raw );
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * lcrm_social_find_lead_by_sender( $platform, $sender_id )
 * Searches custom_fields JSON for a matching platform + sender_id.
 * Returns lead ID or null.
 *
 * TRACE: Queries lcrm_leads WHERE custom_fields contains sender_id JSON substring →
 *        Returns most recent match.
 * EDGE CASES: No match → null; multiple matches → most recent returned.
 */
function lcrm_social_find_lead_by_sender( $platform, $sender_id ) {
    global $wpdb;
    if ( ! $sender_id ) return null;

    // Search the JSON string for the sender_id — works for both MySQL and MariaDB
    $like = '%"social_sender_id":"' . $wpdb->esc_like( $sender_id ) . '"%';
    $id   = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_leads WHERE custom_fields LIKE %s ORDER BY id DESC LIMIT 1",
        $like
    ) );
    return $id ? (int) $id : null;
}

/**
 * lcrm_social_resolve_sender_name( $sender_id, $account )
 * Attempts to fetch sender's name from Graph API using page access token.
 * Returns display name string or empty string on failure.
 *
 * TRACE: GETs /{sender_id}?fields=name with page_access_token →
 *        Returns name on success, '' on failure.
 * EDGE CASES: No token → ''; API error → ''; rate limit → ''.
 */
// TRACE: Called by capture_engine → GETs /{sender_id}?fields=name with page token →
//        Returns sanitized display name or '' on any failure.
//        POSTCONDITIONS: Always returns string (never null/false).
//        EDGE CASES: No token → ''; 429 rate limit → ''; PSID not found → ''.
function lcrm_social_resolve_sender_name( $sender_id, $account ) {
    if ( ! $account->page_access_token || ! $sender_id ) return '';

    $resp = wp_remote_get(
        "https://graph.facebook.com/v19.0/{$sender_id}?fields=name&access_token=" . $account->page_access_token,
        [ 'timeout' => 8 ]
    );

    if ( is_wp_error( $resp ) ) return '';
    if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) return '';

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    return sanitize_text_field( $data['name'] ?? '' );
}

// TRACE: Utility getter → queries lcrm_social_accounts by primary key → returns stdClass or null.
//        EDGE CASES: Invalid ID or deleted account → null; caller must check before use.
function lcrm_social_get_account( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_social_accounts WHERE id=%d", intval( $id )
    ) );
}

// TRACE: Utility getter → returns all social accounts joined with brand names, ordered by display_name.
//        POSTCONDITIONS: Always returns array (empty if none configured).
function lcrm_social_get_all_accounts() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT a.*, b.name AS brand_name
         FROM {$wpdb->prefix}lcrm_social_accounts a
         LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id=a.brand_id
         ORDER BY a.display_name ASC"
    );
}

function lcrm_social_insert_event( $event, $account_id, $lead_id, $status, $score, $raw ) {
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_social_events", [
        'lead_id'       => $lead_id ?: null,
        'account_id'    => (int) $account_id,
        'platform'      => $event['platform'],
        'sender_id'     => mb_substr( $event['sender_id'],   0, 200 ),
        'sender_name'   => mb_substr( $event['sender_name'], 0, 200 ),
        'message_id'    => mb_substr( $event['message_id'],  0, 300 ),
        'message_body'  => mb_substr( $event['body'],        0, 2000 ),
        'media_type'    => $event['media_type'] ?? '',
        'capture_status'=> $status,
        'capture_score' => (int) $score,
        'raw_payload'   => $raw,
        'event_time'    => $event['event_time'],
    ] );
}

// ─── AJAX: SOCIAL ACCOUNT MANAGEMENT ────────────────────────────────────────

add_action( 'wp_ajax_lcrm_social_save_account',   'lcrm_ajax_social_save_account' );
add_action( 'wp_ajax_lcrm_social_delete_account', 'lcrm_ajax_social_delete_account' );

/**
 * lcrm_ajax_social_save_account()
 * Saves/updates a social account (Instagram or Facebook page).
 *
 * TRACE: Validates nonce + admin → sanitizes fields →
 *        Auto-generates verify token if missing →
 *        INSERTs or UPDATEs lcrm_social_accounts.
 * EDGE CASES: Missing page_id → error; token left blank on update → kept from DB.
 */
// TRACE: wp_ajax_lcrm_social_save_account → verifies admin → sanitizes fields →
//        auto-generates verify_token if blank → INSERT or UPDATE lcrm_social_accounts →
//        returns {msg, id, webhook_url}.
//        POSTCONDITIONS: Account row in DB; webhook_url built from rest_url().
//        EDGE CASES: Missing page_id → error; token blank on update → kept from DB; brand_id optional.
function lcrm_ajax_social_save_account() {
    lcrm_verify_admin();
    global $wpdb;

    $id          = intval( $_POST['id'] ?? 0 );
    $brand_id    = intval( $_POST['brand_id'] ?? 0 );
    $platform    = in_array( $_POST['platform'] ?? '', ['instagram','facebook'], true ) ? $_POST['platform'] : 'instagram';
    $display     = sanitize_text_field( $_POST['display_name'] ?? '' );
    $page_id     = sanitize_text_field( $_POST['page_id'] ?? '' );
    $token       = sanitize_text_field( $_POST['page_access_token'] ?? '' );
    $verify_tok  = sanitize_text_field( $_POST['webhook_verify_token'] ?? '' ) ?: wp_generate_password( 24, false );
    $ig_id       = sanitize_text_field( $_POST['ig_account_id'] ?? '' );
    $is_active   = intval( $_POST['is_active'] ?? 1 );

    if ( ! $page_id ) wp_send_json_error( [ 'msg' => 'Page ID is required.' ] );

    $data = [
        'brand_id'            => $brand_id ?: null,
        'platform'            => $platform,
        'display_name'        => $display ?: $page_id,
        'page_id'             => $page_id,
        'webhook_verify_token'=> $verify_tok,
        'ig_account_id'       => $ig_id,
        'is_active'           => $is_active,
    ];

    // Keep existing token if blank was sent on update
    if ( $token ) $data['page_access_token'] = $token;

    if ( $id ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_social_accounts", $data, [ 'id' => $id ] );
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}lcrm_social_accounts", $data );
        $id = (int) $wpdb->insert_id;
    }

    $webhook_url = rest_url( "lcrm/v1/social-webhook/{$id}" );
    wp_send_json_success( [ 'msg' => 'Social account saved.', 'id' => $id, 'webhook_url' => $webhook_url ] );
}

// TRACE: wp_ajax_lcrm_social_delete_account → verifies admin → DELETEs from lcrm_social_accounts →
//        returns {msg:'Social account deleted.'}.
//        POSTCONDITIONS: Account row gone; no webhook delivered to that endpoint from Meta after deletion.
//        EDGE CASES: ID=0 → error; already deleted → success (DELETE of missing row is safe).
function lcrm_ajax_social_delete_account() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Invalid ID.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_social_accounts", [ 'id' => $id ] );
    wp_send_json_success( [ 'msg' => 'Social account deleted.' ] );
}
