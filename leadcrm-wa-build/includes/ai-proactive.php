<?php
/**
 * LeadCRM AI Proactive Intelligence Module
 * Pushes AI insight instead of waiting to be asked.
 *
 * Features:
 *   1. Daily AI triage — ranks leads by urgency, drafts next actions
 *   2. Going-cold detection — leads with high intent but no activity
 *   3. Pattern learning — which source/work_type/time combos convert best
 *   4. Proactive reply drafting — queues suggested replies for tomorrow's hot leads
 *   5. Win-loss pattern analysis — what makes deals close vs die
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DAILY TRIAGE (CRON) ─────────────────────────────────────────────────────

// TRACE: Called by daily cron → pulls top-priority leads → uses AI to score urgency and draft
//        next action for each → creates alerts with AI-generated action text.
//        PRECONDITIONS: AI enabled; leads exist.
//        POSTCONDITIONS: lcrm_alerts rows created for AI-recommended actions.
//        EDGE CASES: AI disabled → runs rule-based triage fallback; no leads → no-op.
function lcrm_ai_proactive_daily_triage() {
    if ( ! lcrm_ai_enabled() ) {
        lcrm_ai_proactive_rule_triage(); // fallback
        return;
    }

    global $wpdb;
    $today = date( 'Y-m-d' );

    // Get hot leads with recent activity that need a next action
    $leads = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.client_name, l.client_phone, l.work_type, l.lead_temperature,
                l.last_client_contact, l.client_followup_date, l.remarks,
                s.name AS stage_name,
                ( SELECT message FROM {$wpdb->prefix}lcrm_conversations
                  WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1 ) AS last_message
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         WHERE l.lead_temperature IN ('hot','warm')
           AND l.stage_id NOT IN (
               SELECT id FROM {$wpdb->prefix}lcrm_stages
               WHERE name IN ('Work Completed','Delivered','Late Response - Lost',
                               'High Charges - Lost','Not Able to Serve')
           )
         ORDER BY FIELD(l.lead_temperature,'hot','warm'), l.last_client_contact ASC
         LIMIT 20",
        $today
    ) );

    if ( empty( $leads ) ) return;

    // Build batch context for AI
    $context_lines = [];
    foreach ( (array) $leads as $l ) {
        $days_silent = $l->last_client_contact
            ? floor( ( time() - strtotime( $l->last_client_contact ) ) / 86400 )
            : 999;
        $context_lines[] = "Lead #{$l->id}: {$l->client_name}, {$l->work_type}, "
                         . "Stage: {$l->stage_name}, Temp: {$l->lead_temperature}, "
                         . "Silent: {$days_silent} days, "
                         . "Last msg: " . mb_substr( $l->last_message ?? 'none', 0, 80 );
    }

    $batch_context = implode( "\n", $context_lines );
    $today_label   = date( 'l, d F Y' );

    $prompt = "Today is {$today_label}. You are a CRM advisor for an Indian service business.\n\n"
            . "Here are leads needing attention today:\n{$batch_context}\n\n"
            . "For each lead, return a JSON array of objects with:\n"
            . '{"lead_id":N,"urgency":"high|medium|low","action":"1-sentence specific next action","reason":"why"}' . "\n"
            . "Return ONLY the JSON array, no markdown, no explanation.";

    $result = lcrm_ai_call_json(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        'You are a CRM advisor. Return only valid JSON arrays.',
        600
    );

    if ( is_wp_error( $result ) || empty( $result ) ) {
        lcrm_ai_proactive_rule_triage();
        return;
    }

    // Process AI triage results
    foreach ( (array) $result as $item ) {
        $lead_id = intval( $item['lead_id'] ?? 0 );
        $urgency = in_array( $item['urgency'] ?? '', ['high','medium','low'], true ) ? $item['urgency'] : 'medium';
        $action  = sanitize_text_field( $item['action'] ?? '' );
        if ( ! $lead_id || ! $action ) continue;

        lcrm_create_alert_deduped(
            $lead_id, 'ai_triage', $urgency,
            "🤖 AI: {$action}", 20
        );
    }
}

// TRACE: Rule-based triage fallback when AI is unavailable.
//        Creates standard follow-up alerts for leads silent >24h.
//        POSTCONDITIONS: Alerts created for silent hot leads.
function lcrm_ai_proactive_rule_triage() {
    global $wpdb;
    $cutoff = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

    $leads = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.client_name, l.lead_temperature FROM {$wpdb->prefix}lcrm_leads l
         WHERE l.lead_temperature = 'hot'
           AND ( l.last_client_contact <= %s OR l.last_client_contact IS NULL )
         LIMIT 20",
        $cutoff
    ) );

    foreach ( (array) $leads as $lead ) {
        lcrm_create_alert_deduped(
            $lead->id, 'triage_followup', 'high',
            "Hot lead {$lead->client_name} has been silent for 24+ hours. Follow up now.", 24
        );
    }
}

// ─── GOING-COLD DETECTION ────────────────────────────────────────────────────

// TRACE: Called hourly by cron → finds leads that were warm/hot but haven't had client contact
//        in configurable hours → downgrades temperature → creates alert.
//        PRECONDITIONS: None — safe to call on empty DB.
//        POSTCONDITIONS: Lead temperature updated; alert created.
//        EDGE CASES: Already cold → not updated again (WHERE clause guards); threshold from settings.
function lcrm_ai_proactive_going_cold_check() {
    global $wpdb;
    $warm_cold_hours = (int) get_option( 'lcrm_ai_warm_cold_hours', 72 );
    $hot_warm_hours  = (int) get_option( 'lcrm_ai_hot_warm_hours',  48 );

    $now = time();

    // Hot leads going to warm
    $hot_cutoff = date( 'Y-m-d H:i:s', $now - $hot_warm_hours * 3600 );
    $hot_going_warm = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, client_name FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_temperature = 'hot'
           AND ( last_client_contact <= %s OR last_client_contact IS NULL )
         LIMIT 30",
        $hot_cutoff
    ) );
    foreach ( (array) $hot_going_warm as $lead ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'lead_temperature' => 'warm' ], [ 'id' => $lead->id ] );
        lcrm_create_alert_deduped(
            $lead->id, 'going_cold', 'high',
            "🌡️ {$lead->client_name} was HOT but no reply in {$hot_warm_hours}h — going warm.", 48
        );
    }

    // Warm leads going to cold
    $warm_cutoff = date( 'Y-m-d H:i:s', $now - $warm_cold_hours * 3600 );
    $warm_going_cold = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, client_name FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_temperature = 'warm'
           AND ( last_client_contact <= %s OR last_client_contact IS NULL )
         LIMIT 30",
        $warm_cutoff
    ) );
    foreach ( (array) $warm_going_cold as $lead ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", [ 'lead_temperature' => 'cold' ], [ 'id' => $lead->id ] );
        lcrm_create_alert_deduped(
            $lead->id, 'going_cold', 'medium',
            "❄️ {$lead->client_name} has gone COLD — {$warm_cold_hours}h without contact.", 72
        );
    }
}

// ─── WIN-LOSS PATTERN ANALYSIS ────────────────────────────────────────────────

// TRACE: Called on demand or weekly → uses AI to analyse conversion patterns →
//        Returns string narrative insight about what's working and what's not.
//        PRECONDITIONS: AI enabled; some leads in DB.
//        POSTCONDITIONS: Returns narrative string; no DB writes.
//        EDGE CASES: AI disabled → returns static analysis; < 10 leads → insufficient data message.
function lcrm_ai_proactive_win_loss_analysis() {
    global $wpdb;

    // Get aggregated win/loss data
    $by_source = lcrm_analytics_source_roi( 90 );
    $by_agent  = lcrm_analytics_agent_performance( 90 );

    $total_leads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads" );
    if ( $total_leads < 5 ) {
        return "Not enough data yet. Add at least 10 leads to see pattern analysis.";
    }

    // Won leads — work type distribution
    $won_by_type = $wpdb->get_results(
        "SELECT work_type, COUNT(*) AS count, SUM(pmt_received) AS revenue
         FROM {$wpdb->prefix}lcrm_leads
         WHERE pmt_received > 0
         GROUP BY work_type ORDER BY revenue DESC LIMIT 5"
    );

    // Lost leads — last stage before lost
    $lost_leads = $wpdb->get_results(
        "SELECT l.work_type, l.lead_source, s.name AS lost_stage
         FROM {$wpdb->prefix}lcrm_leads l
         JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         WHERE s.name LIKE '%Lost%' OR s.name LIKE '%No Response%'
         LIMIT 50"
    );

    $won_summary  = array_map( fn($w) => "{$w->work_type}: {$w->count} wins, ₹" . number_format($w->revenue,0), (array) $won_by_type );
    $lost_summary = array_reduce( (array) $lost_leads, function($carry, $l) {
        $key = "{$l->lead_source} / {$l->work_type}";
        $carry[$key] = ($carry[$key] ?? 0) + 1;
        return $carry;
    }, [] );
    arsort( $lost_summary );
    $lost_lines = array_slice( array_map( fn($k,$v) => "{$k}: {$v} losses", array_keys($lost_summary), $lost_summary ), 0, 5 );

    if ( ! lcrm_ai_enabled() ) {
        $top_source = ! empty( $by_source ) ? $by_source[0]['source'] : 'unknown';
        return "Top source: {$top_source}. Won services: " . implode( ', ', $won_summary ) . ". Frequent loss patterns: " . implode( ', ', $lost_lines );
    }

    $prompt = "Analyse these CRM patterns for an Indian service business and give 3-4 specific, actionable insights in 150 words or less:\n\n"
            . "Top winning service types: " . implode( ', ', $won_summary ) . "\n"
            . "Most common loss patterns: " . implode( ', ', $lost_lines ) . "\n"
            . "Best sources: " . implode( ', ', array_map( fn($s) => "{$s['source']} ({$s['conversion_rate']}% conv)", array_slice($by_source,0,3) ) );

    $result = lcrm_ai_call(
        [ [ 'role' => 'user', 'content' => $prompt ] ],
        'You are a business analytics advisor. Be specific and actionable. No generic advice.',
        300
    );

    return is_wp_error( $result ) ? "Pattern analysis unavailable right now." : $result;
}

// ─── PROACTIVE REPLY QUEUE ────────────────────────────────────────────────────

// TRACE: Called daily at 7am cron → for each hot lead with unread WA messages →
//        generates AI reply suggestion → stores as internal note with [AI Draft] prefix →
//        agent sees it pre-written in their morning queue.
//        PRECONDITIONS: AI enabled; lcrm_wa_events table exists.
//        POSTCONDITIONS: Internal notes created for hot leads with unread WA.
//        EDGE CASES: AI disabled → no-op; lead with no WA → skipped.
function lcrm_ai_proactive_predraft_replies() {
    if ( ! lcrm_ai_enabled() ) return;
    if ( ! get_option( 'lcrm_ai_predraft_replies', 0 ) ) return;

    global $wpdb;

    $hot_wa_leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, wa_unread_count
         FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_temperature = 'hot'
           AND wa_unread_count > 0
         ORDER BY wa_unread_count DESC
         LIMIT 10"
    );

    foreach ( (array) $hot_wa_leads as $lead ) {
        // Get last inbound WA message
        $last_msg = $wpdb->get_var( $wpdb->prepare(
            "SELECT message_body FROM {$wpdb->prefix}lcrm_wa_events
             WHERE lead_id = %d AND direction = 'inbound'
             ORDER BY event_time DESC LIMIT 1",
            $lead->id
        ) );

        if ( ! $last_msg ) continue;

        $suggestion = lcrm_ai_suggest_reply( $lead->id, $last_msg );
        if ( is_wp_error( $suggestion ) || ! $suggestion ) continue;

        lcrm_team_add_internal_note(
            $lead->id,
            "[AI Draft Reply] {$suggestion}\n\n(Auto-drafted based on last message. Edit before sending.)",
            0 // system author
        );
    }
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_ai_run_triage',       'lcrm_ajax_ai_run_triage' );
add_action( 'wp_ajax_lcrm_ai_win_loss',         'lcrm_ajax_ai_win_loss' );
add_action( 'wp_ajax_lcrm_ai_save_pro_settings','lcrm_ajax_ai_save_pro_settings' );

// TRACE: Triggers manual AI triage run from dashboard.
//        POSTCONDITIONS: Triage alerts created; returns count.
function lcrm_ajax_ai_run_triage() {
    lcrm_verify();
    lcrm_ai_proactive_daily_triage();
    $new_alerts = lcrm_count_unread_alerts();
    wp_send_json_success( [ 'msg' => 'AI triage complete.', 'alerts' => $new_alerts ] );
}

// TRACE: Returns win/loss AI analysis on demand.
//        POSTCONDITIONS: Returns narrative string.
function lcrm_ajax_ai_win_loss() {
    lcrm_verify();
    $analysis = lcrm_ai_proactive_win_loss_analysis();
    wp_send_json_success( [ 'analysis' => $analysis ] );
}

// TRACE: Saves AI proactive intelligence settings.
//        POSTCONDITIONS: wp_options updated for triage and cold detection thresholds.
function lcrm_ajax_ai_save_pro_settings() {
    lcrm_verify_admin();
    update_option( 'lcrm_ai_warm_cold_hours',  max( 1, intval( $_POST['warm_cold_hours']  ?? 72 ) ) );
    update_option( 'lcrm_ai_hot_warm_hours',   max( 1, intval( $_POST['hot_warm_hours']   ?? 48 ) ) );
    update_option( 'lcrm_ai_predraft_replies', intval( $_POST['predraft_replies'] ?? 0 ) );
    update_option( 'lcrm_ai_daily_triage',     intval( $_POST['daily_triage']     ?? 1 ) );
    wp_send_json_success( [ 'msg' => 'AI intelligence settings saved.' ] );
}
