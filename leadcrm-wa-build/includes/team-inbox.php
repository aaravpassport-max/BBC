<?php
/**
 * LeadCRM Team Inbox Module
 * Multi-agent lead management: assignment queues, SLA breach alerts,
 * internal notes with @mentions, and handoff tracking.
 *
 * DB tables: lcrm_internal_notes, lcrm_sla_rules, lcrm_mentions
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

// TRACE: Called on activate + v5 migration → creates internal_notes, sla_rules, mentions tables.
//        POSTCONDITIONS: All 3 tables exist.  EDGE CASES: Idempotent.
function lcrm_team_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Internal staff notes — not visible to client
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_internal_notes (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id      BIGINT UNSIGNED NOT NULL,
        author_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        author_name  VARCHAR(150)    NOT NULL DEFAULT '',
        note         TEXT            NOT NULL,
        is_pinned    TINYINT(1)      NOT NULL DEFAULT 0,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id),
        KEY author_id (author_id),
        KEY is_pinned (is_pinned)
    ) $c;" );

    // SLA rules — time-based breach detection
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_sla_rules (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rule_name       VARCHAR(150)    NOT NULL DEFAULT '',
        trigger_on      ENUM('new_lead','stage_entry','no_activity','no_reply') NOT NULL DEFAULT 'new_lead',
        stage_id        BIGINT UNSIGNED DEFAULT NULL,
        hours_threshold INT             NOT NULL DEFAULT 24,
        priority        ENUM('low','medium','high') NOT NULL DEFAULT 'high',
        alert_message   TEXT            NOT NULL DEFAULT '',
        is_active       TINYINT(1)      NOT NULL DEFAULT 1,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;" );

    // @mentions — tag an agent in a note
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_mentions (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        note_id     BIGINT UNSIGNED NOT NULL,
        lead_id     BIGINT UNSIGNED NOT NULL,
        from_user   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        to_user     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        is_read     TINYINT(1)      NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY to_user (to_user),
        KEY is_read (is_read),
        KEY lead_id (lead_id)
    ) $c;" );
}

// ─── DEFAULT SLA RULES ───────────────────────────────────────────────────────

// TRACE: Inserts default SLA rules if none exist.
//        POSTCONDITIONS: 4 default rules seeded.
//        EDGE CASES: Already seeded → no-op.
function lcrm_team_seed_sla_rules() {
    global $wpdb;
    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_sla_rules" ) > 0 ) return;

    $rules = [
        [ 'First response SLA',        'new_lead',    null, 4,   'high',   'New lead has not been contacted in 4 hours.' ],
        [ 'Follow-up overdue',         'no_activity', null, 48,  'high',   'No activity on this lead for 48 hours.' ],
        [ 'No client reply follow-up', 'no_reply',    null, 72,  'medium', 'Client has not replied in 72 hours.' ],
        [ 'Quote not sent SLA',        'stage_entry', 5,    24,  'medium', 'Quotation not sent within 24h of entering Quotation stage.' ],
    ];

    foreach ( (array)$rules as $r ) {
        $wpdb->insert( "{$wpdb->prefix}lcrm_sla_rules", [
            'rule_name'       => $r[0],
            'trigger_on'      => $r[1],
            'stage_id'        => $r[2],
            'hours_threshold' => $r[3],
            'priority'        => $r[4],
            'alert_message'   => $r[5],
            'is_active'       => 1,
        ] );
    }
}

// ─── SLA BREACH CHECK (CRON) ─────────────────────────────────────────────────

// TRACE: Called by hourly cron → loads active SLA rules → checks each lead against rules →
//        calls lcrm_create_alert_deduped for any breach.
//        POSTCONDITIONS: SLA breach alerts created in lcrm_alerts.
//        EDGE CASES: No rules → returns early; lead already has alert → deduped (24h cooldown).
function lcrm_team_check_sla_breaches() {
    global $wpdb;

    $rules = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}lcrm_sla_rules WHERE is_active=1"
    );
    if ( empty( $rules ) ) return;

    foreach ( (array)$rules as $rule ) {
        $threshold_dt = date( 'Y-m-d H:i:s', strtotime( "-{$rule->hours_threshold} hours" ) );

        switch ( $rule->trigger_on ) {

            case 'new_lead':
                // Leads created before threshold that have never been touched
                $leads = $wpdb->get_results( $wpdb->prepare(
                    "SELECT l.id, l.client_name FROM {$wpdb->prefix}lcrm_leads l
                     LEFT JOIN {$wpdb->prefix}lcrm_activity a ON a.lead_id = l.id
                     WHERE l.created_at <= %s
                       AND ( l.assigned_to IS NULL OR l.assigned_to = 0 )
                       AND a.id IS NULL
                     LIMIT 50",
                    $threshold_dt
                ) );
                foreach ( (array) $leads as $lead ) {
                    lcrm_create_alert_deduped(
                        $lead->id, 'sla_new_lead', $rule->priority,
                        "[SLA] {$lead->client_name}: {$rule->alert_message}", 24
                    );
                }
                break;

            case 'no_activity':
                // Leads with last activity older than threshold
                $leads = $wpdb->get_results( $wpdb->prepare(
                    "SELECT l.id, l.client_name,
                            MAX(a.created_at) AS last_act
                     FROM {$wpdb->prefix}lcrm_leads l
                     LEFT JOIN {$wpdb->prefix}lcrm_activity a ON a.lead_id = l.id
                     GROUP BY l.id
                     HAVING last_act <= %s OR last_act IS NULL
                     LIMIT 50",
                    $threshold_dt
                ) );
                foreach ( (array) $leads as $lead ) {
                    lcrm_create_alert_deduped(
                        $lead->id, 'sla_no_activity', $rule->priority,
                        "[SLA] {$lead->client_name}: {$rule->alert_message}", 24
                    );
                }
                break;

            case 'no_reply':
                // Leads with no client contact since threshold
                $leads = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, client_name FROM {$wpdb->prefix}lcrm_leads
                     WHERE ( last_client_contact <= %s OR last_client_contact IS NULL )
                       AND lead_temperature != 'cold'
                     LIMIT 50",
                    $threshold_dt
                ) );
                foreach ( (array) $leads as $lead ) {
                    lcrm_create_alert_deduped(
                        $lead->id, 'sla_no_reply', $rule->priority,
                        "[SLA] {$lead->client_name}: {$rule->alert_message}", 48
                    );
                }
                break;

            case 'stage_entry':
                if ( ! $rule->stage_id ) break;
                // Leads in the target stage for longer than threshold
                $leads = $wpdb->get_results( $wpdb->prepare(
                    "SELECT l.id, l.client_name
                     FROM {$wpdb->prefix}lcrm_leads l
                     JOIN {$wpdb->prefix}lcrm_analytics_events ae
                         ON ae.lead_id = l.id AND ae.event_type = 'stage_change'
                     JOIN {$wpdb->prefix}lcrm_stages s ON s.id = %d
                     WHERE l.stage_id = %d
                       AND ae.to_value = s.name
                       AND ae.created_at <= %s
                     LIMIT 50",
                    $rule->stage_id, $rule->stage_id, $threshold_dt
                ) );
                foreach ( (array) $leads as $lead ) {
                    lcrm_create_alert_deduped(
                        $lead->id, 'sla_stage_entry', $rule->priority,
                        "[SLA] {$lead->client_name}: {$rule->alert_message}", 24
                    );
                }
                break;
        }
    }
}

// ─── INTERNAL NOTES ──────────────────────────────────────────────────────────

// TRACE: Called by AJAX → INSERTs internal note → parses @mentions → notifies mentioned users.
//        PRECONDITIONS: lead_id valid; note non-empty.
//        POSTCONDITIONS: Note row in DB; mentions extracted and inserted; notification emails sent.
//        EDGE CASES: @mention of non-CRM user → ignored; same user mentioned twice → one notification.
function lcrm_team_add_internal_note( $lead_id, $note_text, $author_id = 0 ) {
    global $wpdb;

    $author = $author_id ? get_userdata( $author_id ) : wp_get_current_user();
    $author_name = $author ? sanitize_text_field( $author->display_name ) : 'System';

    $result = $wpdb->insert( "{$wpdb->prefix}lcrm_internal_notes", [
        'lead_id'     => (int) $lead_id,
        'author_id'   => (int) ( $author ? $author->ID : 0 ),
        'author_name' => $author_name,
        'note'        => sanitize_textarea_field( $note_text ),
        'created_at'  => current_time( 'mysql' ),
    ] );

    if ( ! $result ) return false;

    $note_id = (int) $wpdb->insert_id;

    // Parse @mentions — match @username or @Display Name
    preg_match_all( '/@(\w[\w\s]*?)(?=\s|$|[^\w\s])/u', $note_text, $mention_matches );
    $mentioned_names = array_unique( $mention_matches[1] ?? [] );

    $crm_users = lcrm_get_crm_users();
    foreach ( $mentioned_names as $mention_str ) {
        $mention_str = trim( $mention_str );
        // Find user by display_name or user_login match
        foreach ( $crm_users as $user ) {
            if ( stripos( $user->display_name, $mention_str ) !== false ||
                 stripos( $user->user_login, $mention_str ) !== false ) {

                $wpdb->insert( "{$wpdb->prefix}lcrm_mentions", [
                    'note_id'   => $note_id,
                    'lead_id'   => (int) $lead_id,
                    'from_user' => (int) ( $author ? $author->ID : 0 ),
                    'to_user'   => (int) $user->ID,
                    'is_read'   => 0,
                    'created_at'=> current_time( 'mysql' ),
                ] );

                // Email notification
                $lead = lcrm_get_lead( $lead_id );
                $lead_name = $lead ? $lead->client_name : "Lead #{$lead_id}";
                $from_name = $author_name;
                $subject = "[LeadCRM] {$from_name} mentioned you in a note";
                $body = "{$from_name} mentioned you in a note on lead: {$lead_name}\n\n"
                      . '"' . wp_strip_all_tags( $note_text ) . '"'
                      . "\n\nView lead: " . admin_url( "admin.php?page=leadcrm-leads&open_lead={$lead_id}" );
                wp_mail( $user->user_email, $subject, $body );
                break;
            }
        }
    }

    return $note_id;
}

// TRACE: Returns all internal notes for a lead, pinned first then newest.
//        EDGE CASES: No notes → empty array.
function lcrm_team_get_internal_notes( $lead_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT n.*, u.user_email
         FROM {$wpdb->prefix}lcrm_internal_notes n
         LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
         WHERE n.lead_id = %d
         ORDER BY n.is_pinned DESC, n.created_at DESC",
        (int) $lead_id
    ) );
}

// TRACE: Returns unread @mentions for the current user across all leads.
//        POSTCONDITIONS: Returns array for notification badge.
//        EDGE CASES: No mentions → empty array; user not logged in → empty array.
function lcrm_team_get_my_mentions( $user_id = 0 ) {
    global $wpdb;
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return [];

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT m.*, n.note, l.client_name, n.author_name
         FROM {$wpdb->prefix}lcrm_mentions m
         JOIN {$wpdb->prefix}lcrm_internal_notes n ON n.id = m.note_id
         JOIN {$wpdb->prefix}lcrm_leads l ON l.id = m.lead_id
         WHERE m.to_user = %d AND m.is_read = 0
         ORDER BY m.created_at DESC
         LIMIT 20",
        (int) $user_id
    ) );
}

// ─── AGENT QUEUE ─────────────────────────────────────────────────────────────

// TRACE: Returns leads assigned to a specific agent, sorted by priority and follow-up date.
//        Used to render "My Queue" for agents in the team inbox view.
//        PRECONDITIONS: agent_id is a valid WP user ID.
//        POSTCONDITIONS: Returns paginated leads array.
//        EDGE CASES: agent_id=0 → returns unassigned leads; no leads → empty array.
function lcrm_team_get_agent_queue( $agent_id, $per_page = 25, $page = 1 ) {
    global $wpdb;
    $offset = ( max(1, $page) - 1 ) * $per_page;

    if ( $agent_id > 0 ) {
        $where = $wpdb->prepare( "l.assigned_to = %d", $agent_id );
    } else {
        $where = "( l.assigned_to IS NULL OR l.assigned_to = 0 )";
    }

    $leads = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.client_name, l.client_phone, l.work_type, l.lead_temperature,
                l.priority, l.stage_id, s.name AS stage_name, s.color AS stage_color,
                l.client_followup_date, l.last_client_contact,
                l.wa_unread_count, l.lead_date,
                u.display_name AS assigned_name
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         LEFT JOIN {$wpdb->users} u ON u.ID = l.assigned_to
         WHERE {$where}
           AND l.stage_id NOT IN (
               SELECT id FROM {$wpdb->prefix}lcrm_stages
               WHERE name IN ('Work Completed','Delivered','No Response','High Charges - Lost',
                               'Not Able to Serve','Late Response - Lost')
           )
         ORDER BY
           FIELD(l.lead_temperature,'hot','warm','cold'),
           FIELD(l.priority,'high','medium','low'),
           l.client_followup_date ASC,
           l.last_client_contact ASC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    ) );

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads l WHERE {$where}"
    );

    return [ 'leads' => (array) $leads, 'total' => $total ];
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_team_add_note',        'lcrm_ajax_team_add_note' );
add_action( 'wp_ajax_lcrm_team_get_notes',       'lcrm_ajax_team_get_notes' );
add_action( 'wp_ajax_lcrm_team_delete_note',     'lcrm_ajax_team_delete_note' );
add_action( 'wp_ajax_lcrm_team_pin_note',        'lcrm_ajax_team_pin_note' );
add_action( 'wp_ajax_lcrm_team_get_queue',       'lcrm_ajax_team_get_queue' );
add_action( 'wp_ajax_lcrm_team_get_mentions',    'lcrm_ajax_team_get_mentions' );
add_action( 'wp_ajax_lcrm_team_read_mention',    'lcrm_ajax_team_read_mention' );
add_action( 'wp_ajax_lcrm_team_assign_lead',     'lcrm_ajax_team_assign_lead' );
add_action( 'wp_ajax_lcrm_team_save_sla_rule',   'lcrm_ajax_team_save_sla_rule' );
add_action( 'wp_ajax_lcrm_team_delete_sla_rule', 'lcrm_ajax_team_delete_sla_rule' );
add_action( 'wp_ajax_lcrm_team_get_sla_rules',   'lcrm_ajax_team_get_sla_rules' );

// TRACE: Adds an internal note to a lead. Parses @mentions and emails mentioned users.
//        PRECONDITIONS: Logged in user; lead_id + note non-empty.
//        POSTCONDITIONS: Note in DB; mentions created; emails sent.
function lcrm_ajax_team_add_note() {
    lcrm_verify();
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    $note    = sanitize_textarea_field( $_POST['note'] ?? '' );
    if ( ! $lead_id || ! $note ) wp_send_json_error( [ 'msg' => 'Lead ID and note are required.' ] );

    $note_id = lcrm_team_add_internal_note( $lead_id, $note, get_current_user_id() );
    if ( ! $note_id ) wp_send_json_error( [ 'msg' => 'Failed to save note.' ] );

    wp_send_json_success( [ 'note_id' => $note_id, 'msg' => 'Note added.' ] );
}

// TRACE: Returns formatted internal notes for a lead's "Internal" tab.
//        Returns array of {id, note, author_name, created_at, is_pinned, avatar_url}.
function lcrm_ajax_team_get_notes() {
    lcrm_verify();
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $raw   = lcrm_team_get_internal_notes( $lead_id );
    $notes = array_map( function( $n ) {
        return [
            'id'          => (int) $n->id,
            'note'        => $n->note,
            'author_name' => $n->author_name,
            'created_at'  => $n->created_at,
            'is_pinned'   => (bool) $n->is_pinned,
            'avatar_url'  => get_avatar_url( $n->author_id, [ 'size' => 32 ] ),
        ];
    }, (array) $raw );

    wp_send_json_success( [ 'notes' => $notes ] );
}

// TRACE: Deletes an internal note. Only author or admin can delete.
//        PRECONDITIONS: note_id valid; current user is author OR admin.
//        POSTCONDITIONS: Note row deleted.
//        EDGE CASES: Non-author non-admin → 403 error.
function lcrm_ajax_team_delete_note() {
    lcrm_verify();
    global $wpdb;
    $note_id = intval( $_POST['note_id'] ?? 0 );
    if ( ! $note_id ) wp_send_json_error( [ 'msg' => 'Note ID required.' ] );

    $note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lcrm_internal_notes WHERE id=%d", $note_id ) );
    if ( ! $note ) wp_send_json_error( [ 'msg' => 'Note not found.' ] );

    if ( (int) $note->author_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'You can only delete your own notes.' ], 403 );
    }

    $wpdb->delete( "{$wpdb->prefix}lcrm_internal_notes", [ 'id' => $note_id ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_mentions", [ 'note_id' => $note_id ] );
    wp_send_json_success( [ 'msg' => 'Note deleted.' ] );
}

// TRACE: Toggles is_pinned on an internal note. Any team member can pin.
function lcrm_ajax_team_pin_note() {
    lcrm_verify();
    global $wpdb;
    $note_id = intval( $_POST['note_id'] ?? 0 );
    $pinned  = intval( $_POST['pinned']  ?? 0 );
    if ( ! $note_id ) wp_send_json_error( [ 'msg' => 'Note ID required.' ] );
    $wpdb->update( "{$wpdb->prefix}lcrm_internal_notes", [ 'is_pinned' => $pinned ], [ 'id' => $note_id ] );
    wp_send_json_success( [ 'msg' => $pinned ? 'Note pinned.' : 'Note unpinned.' ] );
}

// TRACE: Returns the agent queue for the current user (or specified agent for managers).
function lcrm_ajax_team_get_queue() {
    lcrm_verify();
    $agent_id = intval( $_POST['agent_id'] ?? get_current_user_id() );
    $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
    // Non-admins can only see their own queue
    if ( ! current_user_can( 'manage_options' ) && ! lcrm_can_assign_leads() ) {
        $agent_id = get_current_user_id();
    }
    $result = lcrm_team_get_agent_queue( $agent_id, 25, $page );
    wp_send_json_success( $result );
}

// TRACE: Returns unread @mentions for the current user → used for notification badge.
function lcrm_ajax_team_get_mentions() {
    lcrm_verify();
    $mentions = lcrm_team_get_my_mentions( get_current_user_id() );
    wp_send_json_success( [
        'mentions' => array_map( fn($m) => [
            'id'          => (int) $m->id,
            'lead_id'     => (int) $m->lead_id,
            'client_name' => $m->client_name,
            'from_name'   => $m->author_name,
            'note'        => mb_substr( $m->note, 0, 120 ),
            'created_at'  => $m->created_at,
        ], $mentions ),
        'count' => count( $mentions ),
    ] );
}

// TRACE: Marks a @mention as read.
function lcrm_ajax_team_read_mention() {
    lcrm_verify();
    global $wpdb;
    $mention_id = intval( $_POST['mention_id'] ?? 0 );
    if ( ! $mention_id ) wp_send_json_error( [ 'msg' => 'Mention ID required.' ] );
    $wpdb->update(
        "{$wpdb->prefix}lcrm_mentions",
        [ 'is_read' => 1 ],
        [ 'id' => $mention_id, 'to_user' => get_current_user_id() ]
    );
    wp_send_json_success( [ 'msg' => 'Marked as read.' ] );
}

// TRACE: Assigns a lead to an agent. Records in assignment history.
//        PRECONDITIONS: Manager/admin role; lead_id and agent_id valid.
//        POSTCONDITIONS: lead.assigned_to updated; assignment history row inserted.
function lcrm_ajax_team_assign_lead() {
    lcrm_verify();
    if ( ! lcrm_can_assign_leads() && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Permission denied.' ], 403 );
    }
    global $wpdb;
    $lead_id  = intval( $_POST['lead_id']  ?? 0 );
    $agent_id = intval( $_POST['agent_id'] ?? 0 );
    $note     = sanitize_text_field( $_POST['note'] ?? '' );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $lead = lcrm_get_lead( $lead_id );
    if ( ! $lead ) wp_send_json_error( [ 'msg' => 'Lead not found.' ] );

    $from_user = (int) $lead->assigned_to;
    $action    = $from_user ? 'reassigned' : 'assigned';

    $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'assigned_to' => $agent_id ?: null ], [ 'id' => $lead_id ] );
    lcrm_log_assignment( $lead_id, $action, $from_user, $agent_id, $note );
    lcrm_log_activity( $lead_id, 'assignment', "Lead {$action} to user #{$agent_id}" );

    $agent_name = $agent_id ? ( get_userdata( $agent_id )->display_name ?? "User #{$agent_id}" ) : 'Unassigned';
    wp_send_json_success( [ 'msg' => "Lead {$action} to {$agent_name}." ] );
}

// TRACE: Saves or updates an SLA rule from the settings UI.
function lcrm_ajax_team_save_sla_rule() {
    lcrm_verify_admin();
    global $wpdb;
    $id        = intval( $_POST['id']               ?? 0 );
    $name      = sanitize_text_field( $_POST['rule_name']       ?? '' );
    $trigger   = sanitize_text_field( $_POST['trigger_on']      ?? 'new_lead' );
    $stage_id  = intval( $_POST['stage_id']         ?? 0 ) ?: null;
    $hours     = max( 1, intval( $_POST['hours_threshold'] ?? 24 ) );
    $priority  = in_array( $_POST['priority'] ?? '', ['low','medium','high'], true ) ? $_POST['priority'] : 'high';
    $message   = sanitize_textarea_field( $_POST['alert_message'] ?? '' );
    $is_active = intval( $_POST['is_active'] ?? 1 );
    $valid_triggers = ['new_lead','stage_entry','no_activity','no_reply'];

    if ( ! $name ) wp_send_json_error( [ 'msg' => 'Rule name required.' ] );
    if ( ! in_array( $trigger, $valid_triggers, true ) ) wp_send_json_error( [ 'msg' => 'Invalid trigger.' ] );

    $data = [
        'rule_name'       => $name,
        'trigger_on'      => $trigger,
        'stage_id'        => $stage_id,
        'hours_threshold' => $hours,
        'priority'        => $priority,
        'alert_message'   => $message ?: "SLA breach: {$name}",
        'is_active'       => $is_active,
    ];

    if ( $id ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_sla_rules", $data, [ 'id' => $id ] );
    } else {
        $wpdb->insert( "{$wpdb->prefix}lcrm_sla_rules", $data );
        $id = (int) $wpdb->insert_id;
    }
    wp_send_json_success( [ 'msg' => 'SLA rule saved.', 'id' => $id ] );
}

// TRACE: Deletes an SLA rule.
function lcrm_ajax_team_delete_sla_rule() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Rule ID required.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_sla_rules", [ 'id' => $id ] );
    wp_send_json_success( [ 'msg' => 'SLA rule deleted.' ] );
}

// TRACE: Returns all SLA rules for the settings UI.
function lcrm_ajax_team_get_sla_rules() {
    lcrm_verify_admin();
    global $wpdb;
    $rules = $wpdb->get_results(
        "SELECT r.*, s.name AS stage_name
         FROM {$wpdb->prefix}lcrm_sla_rules r
         LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id = r.stage_id
         ORDER BY r.priority DESC, r.rule_name ASC"
    );
    wp_send_json_success( [ 'rules' => (array) $rules ] );
}
