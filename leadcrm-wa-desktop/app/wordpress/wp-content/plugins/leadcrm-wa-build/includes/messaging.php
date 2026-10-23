<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MODULE 4: MESSAGING SYSTEM
 * Generates human-language messages and WhatsApp links.
 */

function lcrm_generate_message( $lead, $type ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead ) return '';
    $name    = $lead->client_name;
    $work    = $lead->work_type;
    $phone   = $lead->client_phone;
    $vendor  = (isset($lead->vendor_name) ? $lead->vendor_name : 'the vendor');

    switch ( $type ) {
        case 'admin_payment_no_work':
            return "Payment received but work not started for {$name}. "
                 . "Service: {$work}. Please assign a vendor and start processing immediately.";

        case 'admin_quote_pending':
            return "Quotation requested but not sent for {$name} ({$work}). "
                 . "Client is waiting - prepare and send the quotation today.";

        case 'admin_followup':
            return "Follow-up required for {$name} ({$work}). Phone: {$phone}. "
                 . "Please call or message the client.";

        case 'admin_vendor_no_response':
            return "Vendor {$vendor} has not responded on {$name}'s case ({$work}). "
                 . "Chase the vendor for an update and escalate if needed.";

        case 'admin_no_action':
            return "No activity recorded for {$name}'s lead ({$work}) in several days. "
                 . "Please review and take next steps.";

        // ---- Smart remark-based alert message templates (Rules 7-19) ----

        case 'admin_hot_no_followup':
            return "HOT LEAD - {$name} ({$work}) has no follow-up date scheduled! "
                 . "This lead is marked hot and needs immediate attention. Call now: {$phone}";

        case 'admin_interested_no_action':
            return "CONVERSION OPPORTUNITY - {$name} ({$work}) is showing interest but has not been followed up. "
                 . "The window is closing. Call them now on {$phone} and push for a decision.";

        case 'admin_competitor_risk':
            return "COMPETITOR ALERT - {$name} ({$work}) is actively comparing your services with competitors! "
                 . "Call immediately on {$phone}. Highlight your strengths, match their concern, close now.";

        case 'admin_quotation_no_response':
            return "QUOTATION FOLLOW-UP - {$name} ({$work}) received a quotation but has not responded in 2+ days. "
                 . "Follow up on {$phone}. Ask if they have questions — they are likely comparing or hesitating.";

        case 'admin_price_objection':
            $quote_str = isset($lead->total_quote) && $lead->total_quote > 0
                ? ' (Quote: Rs.' . number_format((float)$lead->total_quote, 0) . ')' : '';
            return "PRICE OBJECTION - {$name}{$quote_str} has raised a budget/price concern. "
                 . "Call {$phone}, understand their budget, offer a revised plan or payment option.";

        case 'admin_payment_link_no_payment':
            $due_str = isset($lead->total_quote) && $lead->total_quote > 0
                ? ' Rs.' . number_format((float)$lead->total_quote, 0) . ' due.' : '';
            return "PAYMENT FOLLOW-UP - Payment details were shared with {$name} ({$work}) but payment is NOT received.{$due_str} "
                 . "Call {$phone} now. A gentle nudge at this stage converts most of the time.";

        case 'admin_callback_no_date':
            return "MISSED CALLBACK - {$name} ({$work}) requested a callback but no follow-up date was set in the system. "
                 . "They are waiting for your call on {$phone}. Set a follow-up date and call now.";

        case 'admin_docs_pending_long':
            return "DOCUMENTS OVERDUE - {$name} ({$work}) has had documents pending for 5+ days. "
                 . "Send a firm WhatsApp reminder on {$phone}. Delays frustrate clients and damage trust.";

        case 'admin_delivered_balance_due':
            $bal_str = isset($lead->balance_payment) && $lead->balance_payment > 0
                ? 'Rs.' . number_format((float)$lead->balance_payment, 0) : 'outstanding balance';
            return "COLLECT PAYMENT - Work is complete for {$name} ({$work}) but {$bal_str} is still unpaid. "
                 . "Call {$phone} and request payment — work is done, you have earned it.";

        case 'admin_highvalue_gone_cold':
            $hv_q = isset($lead->total_quote) && $lead->total_quote > 0
                ? 'Rs.' . number_format((float)$lead->total_quote, 0) : 'high value';
            return "RE-ENGAGE NOW - {$name} ({$work}, {$hv_q}) was a strong prospect but has gone COLD. "
                 . "Try a fresh message on {$phone}. Offer a limited-time benefit or discount to restart the conversation.";

        case 'admin_multi_followup_no_convert':
            return "STRATEGY CHANGE NEEDED - Multiple follow-ups done with {$name} ({$work}) but still no conversion. "
                 . "Try a different approach: change the channel, offer a revised package, or escalate.";

        case 'admin_partial_payment_not_started':
            $paid_str = isset($lead->pmt_received) && $lead->pmt_received > 0
                ? 'Rs.' . number_format((float)$lead->pmt_received, 0) : 'partial payment';
            $bal_str2 = (isset($lead->total_quote) && isset($lead->pmt_received) && $lead->total_quote > 0)
                ? ' Balance due: Rs.' . number_format((float)($lead->total_quote - $lead->pmt_received), 0) . '.' : '';
            return "PARTIAL PAYMENT — ACT NOW - {$name} ({$work}) has paid {$paid_str} but work has not started!{$bal_str2} "
                 . "Start work immediately and collect the balance.";

        case 'admin_awaiting_decision_stalled':
            return "DECISION STALLED - {$name} ({$work}) was awaiting decision but has gone quiet for 3+ days. "
                 . "Call {$phone} now. Try: 'Did you get a chance to review? Any questions I can answer?' "
                 . "Leads silent for 3+ days have much lower conversion rate — act today.";

        case 'vendor_followup':
            return "Hi, just following up on the case assigned to you. "
                 . "Service: {$work}. Could you please share the latest status? "
                 . "Client is waiting for an update. Thank you.";

        case 'vendor_assignment':
            $parts   = [];
            $parts[] = "Dear {$vendor},";
            $parts[] = "We have a new case for you. Please find the details below:";
            if ( $name )        $parts[] = "Client Name: {$name}";
            if ( $work )        $parts[] = "Service Required: {$work}";
            if ( ! empty($lead->client_phone) )  $parts[] = "Contact: {$lead->client_phone}";
            if ( ! empty($lead->remarks) )        $parts[] = "Notes: " . strip_tags($lead->remarks);
            $parts[] = "Please confirm receipt and share your timeline. Thank you.";
            return implode("\n", $parts);

        case 'client_reminder':
            return "Dear {$name}, this is a friendly reminder regarding your {$work} request. "
                 . "Please share the pending documents at the earliest to proceed. Thank you.";

        case 'client_update':
            $stage   = (isset($lead->stage_name) ? $lead->stage_name : 'In Progress');
            return "Dear {$name}, quick update on your {$work} request - "
                 . "current status: {$stage}. "
                 . "We will keep you posted. Feel free to reach out for any queries. Thank you.";

        case 'payment_reminder':
            $bal    = 'Rs.' . number_format((float)((isset($lead->balance_payment) ? $lead->balance_payment : 0)), 2);
            return "Dear {$name}, as per our records, a balance payment of {$bal} is pending "
                 . "for your {$work} service. Request you to kindly clear the dues. Thank you.";

        default:
            return "Update regarding {$name}'s case ({$work}). Please check and take action.";
    }
}

function lcrm_whatsapp_link( $phone, $message ) {
    $phone   = preg_replace('/[^0-9]/', '', $phone);
    if ( strlen($phone) === 10 ) $phone = '91' . $phone;
    $encoded = rawurlencode($message);
    return "https://wa.me/{$phone}?text={$encoded}";
}

function lcrm_render_whatsapp_button( $phone, $message, $label = 'Send WhatsApp' ) {
    if ( empty($phone) ) return '';
    $link = lcrm_whatsapp_link($phone, $message);
    return '<a href="' . esc_url($link) . '" target="_blank" class="lcrm-wa-btn" rel="noopener">'
         . '<span class="lcrm-wa-icon">&#128172;</span> ' . esc_html($label) . '</a>';
}

// Generate vendor share message (structured fields)
function lcrm_generate_vendor_share( $lead, $fields = [] ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead ) return '';

    $field_map = [
        'client_name'    => 'Client Name',
        'work_type'      => 'Service',
        'client_phone'   => 'Phone',
        'client_email'   => 'Email',
        'lead_source'    => 'Lead Source',
        'remarks'        => 'Notes',
        'next_action_date'=> 'Timeline',
        'priority'       => 'Priority',
        'stage_name'     => 'Current Status',
        'total_quote'    => 'Quote Amount',
    ];

    if ( empty($fields) ) $fields = array_keys($field_map);

    $lines = ["*CASE DETAILS*", str_repeat('-',30)];
    foreach ( $fields as $field ) {
        $label = (isset($field_map[$field]) ? $field_map[$field] : ucwords(str_replace('_',' ',$field)));
        $key = is_string($field) ? $field : '';
        $val = ( $key !== '' && isset($lead->$key) ) ? $lead->$key : '';
        if ( $field === 'total_quote' && $val ) $val = 'Rs.' . number_format((float)$val, 2);
        if ( $val ) $lines[] = "*{$label}:* {$val}";
    }
    $lines[] = str_repeat('-', 30);
    $lines[] = "_Please confirm receipt and share expected timeline._";
    return implode("\n", $lines);
}

// Log vendor contact
function lcrm_log_vendor_contact( $lead_id, $vendor_id, $message, $status = 'sent' ) {
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}lcrm_vendor_logs", [
        'lead_id'      => intval($lead_id),
        'vendor_id'    => intval($vendor_id),
        'message_sent' => sanitize_textarea_field($message),
        'status'       => $status,
        'created_at'   => current_time('mysql'),
    ]);
    // Update last_vendor_contact on lead
    $wpdb->update("{$wpdb->prefix}lcrm_leads",
        ['last_vendor_contact' => current_time('mysql')],
        ['id' => intval($lead_id)]
    );
}

// ================================================================
// CALLME BOT INTEGRATION (v2.1.2)
// ================================================================

/**
 * Get CallMe Bot settings
 */
function lcrm_get_callme_settings() {
    return [
        'enabled'        => get_option('lcrm_callme_enabled', 0),
        'api_key'        => get_option('lcrm_callme_api_key', ''),
        'admin_number'   => get_option('lcrm_callme_admin_number', ''),
        'staff_numbers'  => get_option('lcrm_callme_staff_numbers', ''), // comma-separated
    ];
}

/**
 * Send a WhatsApp message via CallMe Bot API
 * Returns true on success, WP_Error on failure.
 */
function lcrm_callme_send( $phone, $message ) {
    $settings = lcrm_get_callme_settings();
    if ( ! $settings['enabled'] || empty($settings['api_key']) || empty($phone) ) {
        return new WP_Error('callme_disabled', 'CallMe Bot not configured or disabled.');
    }

    $phone = preg_replace('/[^0-9]/', '', $phone);
    if ( strlen($phone) === 10 ) $phone = '91' . $phone;

    $response = wp_remote_post( 'https://api.callmebot.com/whatsapp.php', [
        'timeout' => 15,
        'body'    => [
            'phone'  => $phone,
            'text'   => $message,
            'apikey' => $settings['api_key'],
        ],
    ]);

    if ( is_wp_error($response) ) return $response;

    $code = wp_remote_retrieve_response_code($response);
    if ( $code !== 200 ) {
        return new WP_Error('callme_http', 'CallMe Bot HTTP error: ' . $code);
    }
    return true;
}

/**
 * Send alert to admin and/or staff via CallMe Bot
 */
function lcrm_callme_notify_team( $message, $include_admin = true, $include_staff = true ) {
    $settings = lcrm_get_callme_settings();
    if ( ! $settings['enabled'] ) return;

    if ( $include_admin && ! empty($settings['admin_number']) ) {
        lcrm_callme_send( $settings['admin_number'], $message );
    }

    if ( $include_staff && ! empty($settings['staff_numbers']) ) {
        $numbers = array_filter( array_map('trim', explode(',', $settings['staff_numbers'])) );
        foreach ( $numbers as $num ) {
            lcrm_callme_send( $num, $message );
        }
    }
}

/**
 * Send vendor assignment WhatsApp via CallMe Bot
 */
function lcrm_callme_notify_vendor_assigned( $lead ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead || empty($lead->vendor_phone) ) return;

    $msg = lcrm_generate_message( $lead, 'vendor_assignment' );
    lcrm_callme_send( $lead->vendor_phone, $msg );

    // Also notify internal team
    $team_msg = "Vendor Follow-Up Due:\nVendor: {$lead->vendor_name}\nLead: {$lead->client_name}\nPhone: {$lead->vendor_phone}";
    lcrm_callme_notify_team( $team_msg );
}

/**
 * Send vendor follow-up reminder via CallMe Bot
 */
function lcrm_callme_notify_vendor_followup( $lead ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead ) return;

    // Alert internal team
    $team_msg = "Vendor Follow-Up Due:\nVendor: {$lead->vendor_name}\nLead: {$lead->client_name}\nPhone: {$lead->vendor_phone}";
    lcrm_callme_notify_team( $team_msg );

    // Remind vendor directly
    if ( ! empty($lead->vendor_phone) ) {
        $vendor_msg = lcrm_generate_message( $lead, 'vendor_followup' );
        lcrm_callme_send( $lead->vendor_phone, $vendor_msg );
    }
}

/**
 * Send vendor escalation (no response) via CallMe Bot
 */
function lcrm_callme_notify_vendor_escalation( $lead, $days ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead ) return;

    // Mark vendor as Delayed (stored in internal_status)
    global $wpdb;
    $wpdb->update(
        "{$wpdb->prefix}lcrm_leads",
        ['internal_status' => 'Vendor Delayed'],
        ['id' => intval($lead->id)]
    );

    $escalation_msg = "VENDOR DELAY ALERT:\n{$lead->vendor_name} has not responded for {$days} days.\nLead: {$lead->client_name}\n⚠ Marked as Delayed. Please escalate.";
    lcrm_callme_notify_team( $escalation_msg, true, false ); // Admin only for escalations

    // Send reminder to vendor too
    if ( ! empty($lead->vendor_phone) ) {
        $vendor_msg = "URGENT REMINDER:\nPlease update status for {$lead->client_name}'s case.\nThis case has been pending for {$days} days. Immediate response required.";
        lcrm_callme_send( $lead->vendor_phone, $vendor_msg );
    }
}

/**
 * Notify team when vendor marks work complete
 */
function lcrm_callme_notify_vendor_work_complete( $lead ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead ) return;

    $msg = "✅ Work Complete:\nVendor: {$lead->vendor_name}\nClient: {$lead->client_name}\nService: {$lead->work_type}\nPlease review and collect balance payment.";
    lcrm_callme_notify_team( $msg, true, false ); // Admin only
}

/**
 * Notify vendor when payment is released
 */
function lcrm_callme_notify_vendor_payment( $lead, $amount ) {
    if ( is_numeric($lead) ) $lead = lcrm_get_lead($lead);
    if ( ! $lead || empty($lead->vendor_phone) ) return;

    $msg = "Payment Released:\nClient: {$lead->client_name}\nService: {$lead->work_type}\nAmount: Rs." . number_format((float)$amount, 2) . "\nThank you!";
    lcrm_callme_send( $lead->vendor_phone, $msg );
}
