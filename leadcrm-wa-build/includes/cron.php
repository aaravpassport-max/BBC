<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*  Register custom cron interval: every hour  */
add_filter('cron_schedules', 'lcrm_add_cron_intervals');
function lcrm_add_cron_intervals($schedules) {
    $schedules['every_hour']  = ['interval' => HOUR_IN_SECONDS,       'display' => 'Every Hour'];
    $schedules['lcrm_15min']  = ['interval' => 15 * MINUTE_IN_SECONDS,'display' => 'Every 15 Minutes'];
    return $schedules;
}

function lcrm_schedule_cron() {
    if ( ! wp_next_scheduled('lcrm_hourly_cron') ) {
        wp_schedule_event(time(), 'every_hour', 'lcrm_hourly_cron');
    }
    if ( ! wp_next_scheduled('lcrm_daily_cron') ) {
        wp_schedule_event(time(), 'daily', 'lcrm_daily_cron');
    }
    // Email poll — every 15 minutes
    if ( ! wp_next_scheduled('lcrm_email_poll_cron') ) {
        wp_schedule_event(time(), 'lcrm_15min', 'lcrm_email_poll_cron');
    }
}
add_action('wp', 'lcrm_schedule_cron');

/*  HOURLY: Run automation engine  */
add_action('lcrm_hourly_cron', 'lcrm_run_hourly');
function lcrm_run_hourly() {
    lcrm_run_automation();
    lcrm_send_reminder_emails();
    lcrm_email_unread_alerts();
    // v5.0 additions
    if ( function_exists('lcrm_team_check_sla_breaches') )     lcrm_team_check_sla_breaches();
    if ( function_exists('lcrm_ai_proactive_going_cold_check')) lcrm_ai_proactive_going_cold_check();
}

/*  DAILY: Email summaries + auto follow-ups  */
add_action('lcrm_daily_cron', 'lcrm_run_daily');
function lcrm_run_daily() {
    lcrm_send_daily_summary();
    lcrm_send_followup_alerts();
    lcrm_send_payment_alerts();
    if ( function_exists('lcrm_wa_send_scheduled_followups') )    lcrm_wa_send_scheduled_followups();
    if ( function_exists('lcrm_ai_proactive_daily_triage') )      lcrm_ai_proactive_daily_triage();
    if ( function_exists('lcrm_ai_proactive_predraft_replies') )  lcrm_ai_proactive_predraft_replies();
}

function lcrm_send_reminder_emails() {
    global $wpdb;
    $now = current_time('mysql');
    $reminders = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, l.client_name, l.work_type, l.assigned_to FROM {$wpdb->prefix}lcrm_reminders r
         LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.id = r.lead_id
         WHERE r.sent = 0 AND r.remind_at <= %s", $now
    ));
    foreach ( $reminders as $rem ) {
        $subject = "[LeadCRM] Reminder: {$rem->client_name}";
        $body    = "Reminder for Lead #{$rem->lead_id} - {$rem->client_name} ({$rem->work_type})\n\n{$rem->note}\n\nDue: {$rem->remind_at}\n\nView leads: ".admin_url('admin.php?page=leadcrm-leads');
        // Email admin
        wp_mail(get_option('admin_email'), $subject, $body);
        // Also email the assigned user if different from admin
        if (!empty($rem->assigned_to)) {
            $u = get_userdata($rem->assigned_to);
            if ($u && $u->user_email && $u->user_email !== get_option('admin_email')) {
                wp_mail($u->user_email, $subject, $body);
            }
        }
        $wpdb->update("{$wpdb->prefix}lcrm_reminders", ['sent'=>1], ['id'=>$rem->id]);
    }
}

function lcrm_email_unread_alerts() {
    $settings = lcrm_get_automation_settings();
    if ( ! $settings['email_alerts'] ) return;
    global $wpdb;
    // Only email HIGH priority alerts not yet emailed
    $alerts = $wpdb->get_results(
        "SELECT a.*, l.client_name FROM {$wpdb->prefix}lcrm_alerts a
         LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.id = a.lead_id
         WHERE a.priority='high' AND a.is_sent=0
         ORDER BY a.created_at DESC LIMIT 20"
    );
    if ( empty($alerts) ) return;
    $email = $settings['admin_email'];
    $lines = [];
    foreach ($alerts as $a) {
        $lines[] = " {$a->message}";
        $wpdb->update("{$wpdb->prefix}lcrm_alerts", ['is_sent'=>1], ['id'=>$a->id]);
    }
    $body = "URGENT ACTION REQUIRED - LeadCRM Alerts:\n\n" . implode("\n\n", $lines)
          . "\n\nLogin to take action: " . admin_url('admin.php?page=leadcrm');
    wp_mail($email, '[LeadCRM]  Urgent Actions Required', $body);
}

function lcrm_send_followup_alerts() {
    global $wpdb;
    $today = date('Y-m-d');
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_name, client_phone, work_type FROM {$wpdb->prefix}lcrm_leads WHERE next_action_date = %s", $today
    ));
    if ( empty($leads) ) return;
    $lines = [];
    foreach ($leads as $l) $lines[] = " #{$l->id} {$l->client_name} | {$l->work_type} | {$l->client_phone}";
    wp_mail(get_option('admin_email'),
        "[LeadCRM] Follow-up Required Today ({$today})",
        "Leads requiring follow-up today:\n\n" . implode("\n", $lines)
    );
}

function lcrm_send_payment_alerts() {
    global $wpdb;
    $leads = $wpdb->get_results(
        "SELECT id, client_name, balance_payment FROM {$wpdb->prefix}lcrm_leads WHERE balance_payment > 0 ORDER BY balance_payment DESC LIMIT 20"
    );
    if ( empty($leads) ) return;
    $total = 0; $lines = [];
    foreach ($leads as $l) { $total += $l->balance_payment; $lines[] = " #{$l->id} {$l->client_name} - Rs." . number_format($l->balance_payment, 2); }
    wp_mail(get_option('admin_email'),
        '[LeadCRM] Pending Payments Summary',
        "Outstanding balances:\n\n" . implode("\n", $lines) . "\n\nTotal pending: Rs." . number_format($total, 2)
    );
}

function lcrm_send_daily_summary() {
    global $wpdb;
    $today = date('Y-m-d');

    // v4.0: Use AI morning brief if available, fall back to static
    if ( function_exists('lcrm_ai_morning_brief') ) {
        $body = lcrm_ai_morning_brief();
    } else {
        $stats = [
            'total'   => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads"),
            'today'   => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_date=%s",$today)),
            'revenue' => $wpdb->get_var("SELECT SUM(pmt_received) FROM {$wpdb->prefix}lcrm_leads"),
            'alerts'  => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0"),
            'hot'     => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE lead_temperature='hot'"),
        ];
        $body = "LeadCRM Daily Summary - {$today}\n\n"
              . "Total Leads: {$stats['total']}\n"
              . "Added Today: {$stats['today']}\n"
              . "Hot Leads: {$stats['hot']}\n"
              . "Total Revenue: Rs." . number_format($stats['revenue'],2) . "\n"
              . "Unread Alerts: {$stats['alerts']}\n\n"
              . "View Dashboard: " . admin_url('admin.php?page=leadcrm');
    }

    wp_mail(get_option('admin_email'), '[LeadCRM] Daily Summary - ' . $today, $body);
}
