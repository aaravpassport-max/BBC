<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MODULE 3: AUTOMATION + REMINDER ENGINE
 * Runs every hour via cron. Evaluates all automation rules and generates alerts.
 */

function lcrm_run_automation() {
    global $wpdb;
    // Suppress DB errors — automation runs during cron which may fire during AJAX requests.
    // Any error output would corrupt JSON responses. Errors are handled gracefully via (array) casts.
    $wpdb->suppress_errors( true );
    $today    = date('Y-m-d');
    $now      = current_time('mysql');
    $settings = lcrm_get_automation_settings();
    $no_vendor_days  = intval((isset($settings['no_vendor_response_days']) ? $settings['no_vendor_response_days'] : 5));
    $no_action_days  = intval((isset($settings['no_action_days']) ? $settings['no_action_days'] : 7));
    $alert_cooldown  = 24; // hours between same alert for same lead

    //  RULE 1: Payment received but work not started 
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE pmt_received > 0 AND work_started = 0
         AND stage_id NOT IN (SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Work Completed','Delivered'))"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'payment_no_work', 'high',
            "Payment received but work NOT started for {$l->client_name} ({$l->work_type}). Take action immediately.",
            $alert_cooldown
        );
    }

    //  RULE 2: Quotation requested but not sent 
    $leads = $wpdb->get_results(
        "SELECT l.id, l.client_name, l.work_type FROM {$wpdb->prefix}lcrm_leads l
         INNER JOIN {$wpdb->prefix}lcrm_stages s ON s.id = l.stage_id
         WHERE s.name LIKE '%Quotation%' AND l.total_quote = 0"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'quote_pending', 'high',
            "Quotation required for {$l->client_name} ({$l->work_type}) but not sent yet.",
            $alert_cooldown
        );
    }

    //  RULE 3: Client follow-up due 
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE client_followup_date <= %s AND client_followup_date IS NOT NULL", $today
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'client_followup', 'medium',
            "Client follow-up due for {$l->client_name}. Call {$l->client_phone}.",
            $alert_cooldown
        );
    }

    //  RULE 4: Vendor follow-up due 
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT l.id, l.client_name, v.name AS vendor_name, v.phone AS vendor_phone
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->prefix}lcrm_vendors v ON v.id = l.vendor_id
         WHERE l.vendor_followup_date <= %s AND l.vendor_followup_date IS NOT NULL AND l.vendor_id IS NOT NULL", $today
    ));
    foreach ( (array)$leads as $l ) {
        $created = lcrm_create_alert_deduped(
            $l->id, 'vendor_followup', 'medium',
            "Vendor follow-up due - contact {$l->vendor_name} ({$l->vendor_phone}) about {$l->client_name}'s case.",
            $alert_cooldown
        );
        // CallMe Bot: notify team + vendor on new alert only
        if ( $created ) lcrm_callme_notify_vendor_followup( $l->id );
    }

    //  RULE 5: Vendor not responding 
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$no_vendor_days} days"));
    $leads  = $wpdb->get_results($wpdb->prepare(
        "SELECT l.id, l.client_name, v.name AS vendor_name, v.phone AS vendor_phone
         FROM {$wpdb->prefix}lcrm_leads l
         LEFT JOIN {$wpdb->prefix}lcrm_vendors v ON v.id = l.vendor_id
         WHERE l.vendor_id IS NOT NULL
         AND l.last_vendor_contact IS NOT NULL
         AND l.last_vendor_contact <= %s
         AND l.stage_id NOT IN (SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Assigned and In Progress','Work Completed'))", $cutoff
    ));
    foreach ( (array)$leads as $l ) {
        $created = lcrm_create_alert_deduped(
            $l->id, 'vendor_no_response', 'high',
            "Vendor {$l->vendor_name} has NOT responded for {$no_vendor_days}+ days on {$l->client_name}'s case. Follow up urgently.",
            $alert_cooldown * 12 // only once per 12h for this
        );
        // CallMe Bot: escalate to admin, mark vendor as Delayed
        if ( $created ) lcrm_callme_notify_vendor_escalation( $l->id, $no_vendor_days );
    }

    //  RULE 6: No action taken on lead 
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$no_action_days} days"));
    $leads  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type FROM {$wpdb->prefix}lcrm_leads
         WHERE updated_at <= %s
         AND stage_id NOT IN (SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Work Completed','Delivered','Late Response - Lost','High Charges - Lost','Not Able to Serve'))", $cutoff
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'no_action', 'medium',
            "No activity on {$l->client_name}'s lead ({$l->work_type}) for {$no_action_days}+ days. Review required.",
            $alert_cooldown * 24 // only once per day
        );
    }

    // =========================================================
    //  SMART REMARK-BASED RULES (7-19) — AI conversion engine
    // =========================================================

    $lost_stage_subquery  = "SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Work Completed','Delivered','Late Response - Lost','High Charges - Lost','Not Able to Serve','No Response','Invalid case')";
    $closed_stage_subquery = "SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Work Completed','Delivered')";

    //  RULE 7: Hot lead — no follow-up date scheduled
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_temperature = 'hot'
         AND next_action_date IS NULL
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'hot_no_followup', 'high',
            "HOT lead {$l->client_name} ({$l->work_type}) has NO follow-up date! Set one immediately or this lead will go cold.",
            12
        );
    }

    //  RULE 8: Client is interested/hesitant — no action in 48 hours
    $cutoff_48h = date('Y-m-d H:i:s', strtotime('-48 hours'));
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%%hesitant%%'
             OR remarks LIKE '%%partially interested%%'
             OR remarks LIKE '%%reviewing details%%'
             OR remarks LIKE '%%interested but%%'
             OR remarks LIKE '%%Client interested%%'
             OR remarks LIKE '%%Client partially%%'
         )
         AND updated_at <= %s
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})",
        $cutoff_48h
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'interested_no_action', 'high',
            "Client {$l->client_name} ({$l->work_type}) is INTERESTED but hasn't been followed up in 48h. Push for conversion before they go cold!",
            24
        );
    }

    //  RULE 9: Competitor comparison detected — act fast
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%comparing%'
             OR remarks LIKE '%competitor%'
             OR remarks LIKE '%compare%'
             OR remarks LIKE '%comparing with others%'
             OR remarks LIKE '%Client comparing%'
         )
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'competitor_risk', 'high',
            "URGENT: {$l->client_name} ({$l->work_type}) is comparing your services with competitors! Call now or risk losing this lead.",
            12
        );
    }

    //  RULE 10: Quotation sent — no response in 2 days
    $cutoff_2d = date('Y-m-d H:i:s', strtotime('-2 days'));
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%%Quotation sent%%'
             OR remarks LIKE '%%quotation sent to client%%'
             OR remarks LIKE '%%Quotation explained%%'
             OR remarks LIKE '%%Follow-up on quotation done%%'
         )
         AND updated_at <= %s
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})",
        $cutoff_2d
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'quotation_no_response', 'high',
            "Quotation was sent to {$l->client_name} ({$l->work_type}) but NO response in 2+ days. Follow up now to close the deal.",
            24
        );
    }

    //  RULE 11: Price/budget objection raised — convert before losing
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone, total_quote FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%price too high%'
             OR remarks LIKE '%budget issue%'
             OR remarks LIKE '%high charges concern%'
             OR remarks LIKE '%Negotiation in progress%'
             OR remarks LIKE '%Price discussion ongoing%'
             OR remarks LIKE '%Client requested discount%'
             OR remarks LIKE '%Client negotiating%'
             OR remarks LIKE '%Budget not discussed%'
         )
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        $quote_str = $l->total_quote > 0 ? ' (Quote: Rs.' . number_format($l->total_quote, 0) . ')' : '';
        lcrm_create_alert_deduped(
            $l->id, 'price_objection', 'high',
            "Price/budget concern flagged for {$l->client_name}{$quote_str}. Offer alternatives or discuss value before this lead is lost.",
            24
        );
    }

    //  RULE 12: Payment link/details shared — payment not received in 2 days
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type, client_phone, total_quote FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%%Payment link sent%%'
             OR remarks LIKE '%%Payment details shared%%'
             OR remarks LIKE '%%Awaiting payment%%'
             OR remarks LIKE '%%Payment confirmation pending%%'
         )
         AND pmt_received = 0
         AND updated_at <= %s
         AND stage_id NOT IN ({$lost_stage_subquery})",
        $cutoff_2d
    ));
    foreach ( (array)$leads as $l ) {
        $q = $l->total_quote > 0 ? ' Rs.' . number_format($l->total_quote, 0) . ' due.' : '';
        lcrm_create_alert_deduped(
            $l->id, 'payment_link_no_payment', 'high',
            "Payment details were shared with {$l->client_name} but payment NOT received.{$q} Follow up for payment immediately.",
            12
        );
    }

    //  RULE 13: Client requested callback — no follow-up date set
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%callback%'
             OR remarks LIKE '%call later%'
             OR remarks LIKE '%call back%'
             OR remarks LIKE '%follow-up scheduled%'
             OR remarks LIKE '%Client busy%'
             OR remarks LIKE '%Client requested callback%'
         )
         AND next_action_date IS NULL
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'callback_no_date', 'medium',
            "{$l->client_name} ({$l->work_type}) requested a callback but NO follow-up date is set. Schedule it now before they forget you.",
            24
        );
    }

    //  RULE 14: Documents pending too long (5+ days)
    $cutoff_5d = date('Y-m-d H:i:s', strtotime('-5 days'));
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%%Documents/info pending from client%%'
             OR remarks LIKE '%%Waiting For The Documents%%'
             OR remarks LIKE '%%Waiting for Signed docs%%'
             OR remarks LIKE '%%docs pending%%'
             OR remarks LIKE '%%documents pending%%'
             OR remarks LIKE '%%Delay due to missing docs%%'
         )
         AND updated_at <= %s
         AND stage_id NOT IN ({$lost_stage_subquery})",
        $cutoff_5d
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'docs_pending_long', 'medium',
            "Documents pending from {$l->client_name} ({$l->work_type}) for 5+ days. Send a firm reminder — delays kill conversions.",
            48
        );
    }

    //  RULE 15: Work delivered — balance payment still not collected
    $leads = $wpdb->get_results(
        "SELECT l.id, l.client_name, l.work_type, l.client_phone, l.balance_payment
         FROM {$wpdb->prefix}lcrm_leads l
         WHERE l.balance_payment > 0
         AND (
             l.remarks LIKE '%work completed%'
             OR l.remarks LIKE '%Delivered to client%'
             OR l.remarks LIKE '%Client confirmed receipt%'
             OR l.stage_id IN (SELECT id FROM {$wpdb->prefix}lcrm_stages WHERE name IN ('Work Completed','Delivered'))
         )"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'delivered_balance_due', 'high',
            "Work DELIVERED for {$l->client_name} ({$l->work_type}) but Rs." . number_format($l->balance_payment, 0) . " balance still unpaid. Collect now.",
            24
        );
    }

    //  RULE 16: High-value lead gone cold — re-engage
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone, total_quote FROM {$wpdb->prefix}lcrm_leads
         WHERE lead_temperature = 'cold'
         AND total_quote > 0
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'highvalue_gone_cold', 'medium',
            "HIGH-VALUE lead {$l->client_name} (Quote: Rs." . number_format($l->total_quote, 0) . ") has gone COLD. Re-engage now — this is recoverable revenue.",
            48
        );
    }

    //  RULE 17: Multiple follow-ups done — still no conversion, try new approach
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%Multiple follow-ups done%'
             OR remarks LIKE '%Multiple reminders sent%'
         )
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'multi_followup_no_convert', 'medium',
            "Multiple follow-ups done with {$l->client_name} ({$l->work_type}) but NO conversion yet. Try a different approach, change the offer, or escalate.",
            48
        );
    }

    //  RULE 18: Partial payment received — work not started
    $leads = $wpdb->get_results(
        "SELECT id, client_name, work_type, client_phone, pmt_received, total_quote FROM {$wpdb->prefix}lcrm_leads
         WHERE pmt_received > 0
         AND total_quote > 0
         AND pmt_received < total_quote
         AND work_started = 0"
    );
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'partial_payment_not_started', 'high',
            "{$l->client_name} paid Rs." . number_format($l->pmt_received, 0) . " (partial). Work has NOT started! Start immediately and collect the balance Rs." . number_format($l->total_quote - $l->pmt_received, 0) . ".",
            24
        );
    }

    //  RULE 19: Awaiting decision — client silent for 3+ days
    $cutoff_3d = date('Y-m-d H:i:s', strtotime('-3 days'));
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, work_type, client_phone FROM {$wpdb->prefix}lcrm_leads
         WHERE (
             remarks LIKE '%%Awaiting decision%%'
             OR remarks LIKE '%%Client reviewing details%%'
             OR remarks LIKE '%%Client reviewing quotation%%'
             OR remarks LIKE '%%Client comparing%%'
         )
         AND updated_at <= %s
         AND pmt_received = 0
         AND stage_id NOT IN ({$lost_stage_subquery})",
        $cutoff_3d
    ));
    foreach ( (array)$leads as $l ) {
        lcrm_create_alert_deduped(
            $l->id, 'awaiting_decision_stalled', 'high',
            "{$l->client_name} ({$l->work_type}) is 'awaiting decision' but went silent for 3+ days. Nudge NOW — leads that stall 3+ days convert 60% less.",
            24
        );
    }

    //  AUTO-UPDATE lead temperature (smart, remark-aware)
    lcrm_auto_update_temperature();
}

function lcrm_create_alert_deduped( $lead_id, $type, $priority, $message, $cooldown_hours ) {
    global $wpdb;
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$cooldown_hours} hours"));
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}lcrm_alerts WHERE lead_id=%d AND type=%s AND created_at >= %s",
        $lead_id, $type, $cutoff
    ));
    if ( $exists ) return false;
    $wpdb->insert("{$wpdb->prefix}lcrm_alerts", [
        'lead_id'    => intval($lead_id),
        'type'       => sanitize_text_field($type),
        'priority'   => $priority,
        'message'    => sanitize_textarea_field($message),
        'is_sent'    => 0,
        'is_read'    => 0,
        'created_at' => current_time('mysql'),
    ]);
    do_action( 'lcrm_alert_created', intval($lead_id), $priority, sanitize_textarea_field($message) );
    return true; // newly created
}

function lcrm_auto_update_temperature() {
    global $wpdb;
    $today = date('Y-m-d');

    // HOT: payment received, or action due today, or partial payment, or competitor risk
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_leads SET lead_temperature='hot'
         WHERE (
             pmt_received > 0
             OR next_action_date = %s
             OR (pmt_received > 0 AND pmt_received < total_quote)
             OR remarks LIKE '%%Payment link sent%%'
             OR remarks LIKE '%%Awaiting payment%%'
             OR remarks LIKE '%%Payment confirmation pending%%'
             OR remarks LIKE '%%comparing%%'
             OR remarks LIKE '%%Awaiting decision%%'
             OR remarks LIKE '%%Client interested but hesitant%%'
         )
         AND work_started = 0
         AND stage_id NOT IN (
             SELECT id FROM {$wpdb->prefix}lcrm_stages
             WHERE name IN ('Work Completed','Delivered','Late Response - Lost','High Charges - Lost','Not Able to Serve','No Response')
         )",
        $today
    ));

    // COLD: no update in 14+ days and no payment AND remarks suggest disengagement
    $cold_cutoff = date('Y-m-d H:i:s', strtotime('-14 days'));
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}lcrm_leads SET lead_temperature='cold'
         WHERE (
             updated_at <= %s
             OR remarks LIKE '%%No response after follow-ups%%'
             OR remarks LIKE '%%No response from client%%'
             OR remarks LIKE '%%Client not interested%%'
             OR remarks LIKE '%%Client dropped after quotation%%'
             OR remarks LIKE '%%No response after quotation%%'
             OR remarks LIKE '%%Wrong / invalid inquiry%%'
             OR remarks LIKE '%%Invalid case%%'
         )
         AND pmt_received = 0
         AND lead_temperature != 'hot'",
        $cold_cutoff
    ));

    // WARM: everything in between — interested but not hot/cold
    $wpdb->query(
        "UPDATE {$wpdb->prefix}lcrm_leads SET lead_temperature='warm'
         WHERE lead_temperature NOT IN ('hot','cold')"
    );

    // WARM override: remark says actively engaged
    $wpdb->query(
        "UPDATE {$wpdb->prefix}lcrm_leads SET lead_temperature='warm'
         WHERE lead_temperature = 'cold'
         AND pmt_received = 0
         AND (
             remarks LIKE '%Client exploring options%'
             OR remarks LIKE '%Requirements being reviewed%'
             OR remarks LIKE '%Quotation being prepared%'
             OR remarks LIKE '%Follow-up call done%'
             OR remarks LIKE '%Follow-up message sent%'
             OR remarks LIKE '%Client reviewing quotation%'
             OR remarks LIKE '%Negotiation in progress%'
         )"
    );
}

function lcrm_get_automation_settings() {
    return [
        'no_vendor_response_days'   => get_option('lcrm_no_vendor_days', 5),
        'no_action_days'            => get_option('lcrm_no_action_days', 7),
        'email_alerts'              => get_option('lcrm_email_alerts', 1),
        'admin_email'               => get_option('lcrm_alert_email', get_option('admin_email')),
        // CallMe Bot (v2.1.2)
        'callme_enabled'            => get_option('lcrm_callme_enabled', 0),
        'callme_api_key'            => get_option('lcrm_callme_api_key', ''),
        'callme_admin_number'       => get_option('lcrm_callme_admin_number', ''),
        'callme_staff_numbers'      => get_option('lcrm_callme_staff_numbers', ''),
        'vendor_escalation_hours'   => get_option('lcrm_vendor_escalation_hours', 48),
    ];
}

function lcrm_get_alerts( $unread_only = false, $priority = '', $limit = 50 ) {
    global $wpdb;
    $where = ['1=1'];
    $vals  = [];
    if ( $unread_only ) { $where[] = 'a.is_read = 0'; }
    if ( $priority )    { $where[] = 'a.priority = %s'; $vals[] = $priority; }
    $sql = "SELECT a.*, l.client_name, l.client_phone, l.work_type
            FROM {$wpdb->prefix}lcrm_alerts a
            LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.id = a.lead_id
            WHERE " . implode(' AND ',$where) . "
            ORDER BY FIELD(a.priority,'high','medium','low'), a.created_at DESC
            LIMIT %d";
    $vals[] = $limit;
    return empty($vals) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql,...$vals));
}

function lcrm_count_unread_alerts() {
    global $wpdb;
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0");
}

function lcrm_get_dashboard_alerts() {
    global $wpdb;
    return [
        'urgent'              => lcrm_get_alerts(true,'high', 20),
        'followup'            => lcrm_get_alerts(true,'medium',20),
        'total_unread'        => lcrm_count_unread_alerts(),
        'payment_no_work'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='payment_no_work' AND is_read=0"),
        'quote_pending'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='quote_pending' AND is_read=0"),
        'vendor_issues'       => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type IN ('vendor_followup','vendor_no_response') AND is_read=0"),
        // New smart-rule counters
        'hot_no_followup'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='hot_no_followup' AND is_read=0"),
        'competitor_risk'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='competitor_risk' AND is_read=0"),
        'quotation_silent'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='quotation_no_response' AND is_read=0"),
        'price_objections'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='price_objection' AND is_read=0"),
        'payment_pending'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type IN ('payment_link_no_payment','partial_payment_not_started','delivered_balance_due') AND is_read=0"),
        'awaiting_decision'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='awaiting_decision_stalled' AND is_read=0"),
        'interested_stalled'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='interested_no_action' AND is_read=0"),
        'docs_pending'        => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='docs_pending_long' AND is_read=0"),
        'highvalue_cold'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE type='highvalue_gone_cold' AND is_read=0"),
    ];
}

//  Duplicate detection 
function lcrm_check_duplicate( $phone, $email = '' ) {
    global $wpdb;
    $where = ['1=1']; $vals = [];
    if ( $phone ) {
        $where[] = 'client_phone = %s';
        $vals[]  = sanitize_text_field($phone);
    }
    if ( $email ) {
        $where[] = 'client_email = %s';
        $vals[]  = sanitize_email($email);
    }
    if ( count($vals) === 0 ) return [];
    $sql = "SELECT id, client_name, lead_date, work_type FROM {$wpdb->prefix}lcrm_leads WHERE " . implode(' OR ', $where) . " LIMIT 5";
    return $wpdb->get_results($wpdb->prepare($sql,...$vals));
}

//  Lead scoring 
function lcrm_calculate_lead_score( $lead ) {
    $score = 0;
    if ( $lead->pmt_received > 0 )                       $score += 40;
    if ( $lead->total_quote > 0 )                        $score += 15;
    if ( ! empty($lead->client_email) )                  $score += 10;
    if ( $lead->vendor_id )                              $score += 10;
    if ( $lead->work_started )                           $score += 15;
    if ( $lead->lead_temperature === 'hot' )             $score += 20;
    elseif ( $lead->lead_temperature === 'warm' )        $score += 10;
    $stage_scores = ['Payment Received'=>20,'Assigned and In Progress'=>15,'Work Completed'=>30,'Delivered'=>40,'Documents Received'=>10];
    if ( isset($stage_scores[$lead->stage_name]) )       $score += $stage_scores[$lead->stage_name];
    return min(100, $score);
}
