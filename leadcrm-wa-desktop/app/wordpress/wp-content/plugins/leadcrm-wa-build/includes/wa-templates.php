<?php
/**
 * LeadCRM WhatsApp Template Library
 * Agents select from pre-approved templates instead of typing every message.
 * Templates support {{client_name}}, {{work_type}}, {{stage}}, {{amount}}, {{site_name}} placeholders.
 *
 * DB table: lcrm_wa_templates
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB ──────────────────────────────────────────────────────────────────────

function lcrm_wa_templates_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_wa_templates (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category     VARCHAR(80)     NOT NULL DEFAULT 'General',
        title        VARCHAR(150)    NOT NULL DEFAULT '',
        body         TEXT            NOT NULL,
        sort_order   INT             NOT NULL DEFAULT 0,
        is_active    TINYINT(1)      NOT NULL DEFAULT 1,
        created_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY category (category),
        KEY is_active (is_active)
    ) $c;" );
}

// ─── SEED ────────────────────────────────────────────────────────────────────

// TRACE: Seeds default templates if none exist. Called on activate.
//        POSTCONDITIONS: Default templates present for all common use cases.
//        EDGE CASES: Already seeded → no-op.
function lcrm_wa_templates_seed_defaults() {
    global $wpdb;
    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_wa_templates" ) > 0 ) return;

    $templates = [
        // First Contact
        ['First Contact', 'Initial Welcome',
         "Hello {{client_name}} ji! 🙏\n\nThank you for contacting {{site_name}}.\n\nWe have received your inquiry for {{work_type}} and our team will review it shortly.\n\nPlease feel free to share any relevant documents or details to help us assist you better.\n\nWarm regards,\n{{site_name}}"],
        ['First Contact', 'Callback Confirmation',
         "Hello {{client_name}} ji! 🙏\n\nThank you for reaching out. We have noted your request for {{work_type}}.\n\nWe will call you back within the next few hours during business hours (10am–7pm).\n\nRegards,\n{{site_name}}"],
        // Follow-up
        ['Follow-up', 'Gentle Follow-up',
         "Hello {{client_name}} ji! 😊\n\nJust following up on your inquiry for {{work_type}}.\n\nPlease let us know if you have any questions or if we can share more information to help you.\n\nWe are here to help!\n{{site_name}}"],
        ['Follow-up', 'No Response Follow-up',
         "Hello {{client_name}} ji,\n\nWe tried reaching you regarding your {{work_type}} inquiry but could not connect.\n\nPlease reply to this message at your convenience and we will be happy to assist you.\n\n{{site_name}}"],
        ['Follow-up', 'Quotation Follow-up',
         "Hello {{client_name}} ji! 🙏\n\nWe sent you a quotation for {{work_type}} a few days ago.\n\nPlease let us know if you have had a chance to review it or if you have any questions. We are happy to discuss.\n\n{{site_name}}"],
        // Documents
        ['Documents', 'Documents Required',
         "Hello {{client_name}} ji! 🙏\n\nTo proceed with your {{work_type}} application, we require the following documents:\n\n📌 Please reply to this message with scanned copies or clear photos.\n\nFor any questions, we are here to help!\n{{site_name}}"],
        ['Documents', 'Documents Received',
         "Hello {{client_name}} ji! ✅\n\nThank you! We have received your documents for {{work_type}}.\n\nOur team is reviewing them and we will update you shortly.\n\n{{site_name}}"],
        // Payment
        ['Payment', 'Payment Reminder',
         "Hello {{client_name}} ji! 🙏\n\nThis is a gentle reminder regarding the pending payment of {{amount}} for your {{work_type}} service.\n\nKindly arrange the payment at your earliest convenience so we can proceed without delay.\n\nThank you!\n{{site_name}}"],
        ['Payment', 'Payment Received Thanks',
         "Hello {{client_name}} ji! 🎉\n\nThank you for your payment of {{amount}} for {{work_type}}.\n\nWe have received it successfully and your work will be prioritised accordingly.\n\nThank you for trusting us!\n{{site_name}}"],
        // Status Updates
        ['Status Update', 'Work Started',
         "Hello {{client_name}} ji! 🚀\n\nGreat news! We have started working on your {{work_type}} application.\n\nWe will keep you updated on the progress. Feel free to ask any questions.\n\n{{site_name}}"],
        ['Status Update', 'Work Completed',
         "Hello {{client_name}} ji! ✅\n\nExcellent news! Your {{work_type}} has been completed.\n\nPlease review and confirm receipt. We hope you are satisfied with our service.\n\nThank you for choosing {{site_name}}! 🙏"],
        ['Status Update', 'Delivery Confirmation',
         "Hello {{client_name}} ji! 📦\n\nYour {{work_type}} has been dispatched/delivered.\n\nPlease confirm receipt and do not hesitate to contact us for any follow-up requirements.\n\n{{site_name}}"],
        // Quotation
        ['Quotation', 'Quotation Ready',
         "Hello {{client_name}} ji! 🙏\n\nThank you for your patience. We have prepared the quotation for your {{work_type}} service.\n\nOur charges are competitive and include end-to-end assistance. Please find the details below:\n\n[Add quotation details here]\n\nLet us know if you would like to proceed or have any questions.\n\n{{site_name}}"],
        // Lost/Cold
        ['Re-engagement', 'Win Back Cold Lead',
         "Hello {{client_name}} ji! 🙏\n\nWe hope you are doing well!\n\nWe noticed we had spoken earlier about {{work_type}}. If you are still looking for assistance, we are here and happy to help with the best service and competitive pricing.\n\nPlease feel free to reach out anytime.\n\n{{site_name}}"],
    ];

    foreach ( $templates as $i => $t ) {
        $wpdb->insert( "{$wpdb->prefix}lcrm_wa_templates", [
            'category'   => $t[0],
            'title'      => $t[1],
            'body'       => $t[2],
            'sort_order' => $i,
            'is_active'  => 1,
            'created_by' => 0,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

// TRACE: Returns all active templates grouped by category.
//        POSTCONDITIONS: Returns assoc array [category => [templates...]].
//        EDGE CASES: No templates → returns empty array.
function lcrm_wa_templates_get_grouped() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}lcrm_wa_templates WHERE is_active=1 ORDER BY category,sort_order ASC"
    );
    $grouped = [];
    foreach ( (array) $rows as $row ) {
        $grouped[ $row->category ][] = $row;
    }
    return $grouped;
}

// TRACE: Interpolates template placeholders against a lead object.
//        Returns final string ready to send.
//        EDGE CASES: Null lead → only site_name replaced; missing lead fields → replaced with empty string.
function lcrm_wa_templates_render( $template_body, $lead = null ) {
    $replacements = [
        '{{site_name}}'  => get_bloginfo( 'name' ),
        '{{client_name}}'=> $lead ? $lead->client_name  : '',
        '{{work_type}}'  => $lead ? $lead->work_type    : '',
        '{{amount}}'     => $lead ? '₹' . number_format( $lead->balance_payment, 0 ) : '',
        '{{stage}}'      => '',
    ];

    if ( $lead && $lead->stage_id ) {
        $stage = lcrm_get_stage( (int) $lead->stage_id );
        $replacements['{{stage}}'] = $stage ? $stage->name : '';
    }

    return str_replace( array_keys( $replacements ), array_values( $replacements ), $template_body );
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_wat_get_grouped',  'lcrm_ajax_wat_get_grouped' );
add_action( 'wp_ajax_lcrm_wat_save',         'lcrm_ajax_wat_save' );
add_action( 'wp_ajax_lcrm_wat_delete',       'lcrm_ajax_wat_delete' );
add_action( 'wp_ajax_lcrm_wat_reorder',      'lcrm_ajax_wat_reorder' );
add_action( 'wp_ajax_lcrm_wat_render',       'lcrm_ajax_wat_render' );
add_action( 'wp_ajax_lcrm_wat_send',         'lcrm_ajax_wat_send' );

// TRACE: Returns grouped templates for the template picker UI.
function lcrm_ajax_wat_get_grouped() {
    lcrm_verify();
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    $lead    = $lead_id ? lcrm_get_lead( $lead_id ) : null;

    $grouped = lcrm_wa_templates_get_grouped();
    $result  = [];
    foreach ( $grouped as $category => $templates ) {
        $result[ $category ] = array_map( function( $t ) use ( $lead ) {
            return [
                'id'       => (int) $t->id,
                'title'    => $t->title,
                'body'     => $t->body,
                'preview'  => mb_substr( lcrm_wa_templates_render( $t->body, $lead ), 0, 80 ) . '…',
            ];
        }, $templates );
    }
    wp_send_json_success( [ 'templates' => $result ] );
}

// TRACE: Saves or updates a WA template.
//        PRECONDITIONS: Admin; title and body required.
//        POSTCONDITIONS: Template in DB.
function lcrm_ajax_wat_save() {
    lcrm_verify_admin();
    global $wpdb;
    $id       = intval( $_POST['id']       ?? 0 );
    $category = sanitize_text_field( $_POST['category'] ?? 'General' );
    $title    = sanitize_text_field( $_POST['title']    ?? '' );
    $body     = sanitize_textarea_field( $_POST['body'] ?? '' );
    $active   = intval( $_POST['is_active'] ?? 1 );

    if ( ! $title ) wp_send_json_error( [ 'msg' => 'Title required.' ] );
    if ( ! $body  ) wp_send_json_error( [ 'msg' => 'Message body required.' ] );

    $data = [
        'category'  => $category,
        'title'     => $title,
        'body'      => $body,
        'is_active' => $active,
    ];

    if ( $id ) {
        $wpdb->update( "{$wpdb->prefix}lcrm_wa_templates", $data, [ 'id' => $id ] );
    } else {
        $max = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$wpdb->prefix}lcrm_wa_templates" );
        $data['sort_order'] = $max + 1;
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}lcrm_wa_templates", $data );
        $id = (int) $wpdb->insert_id;
    }
    wp_send_json_success( [ 'msg' => 'Template saved.', 'id' => $id ] );
}

// TRACE: Deletes a WA template. Admin only.
function lcrm_ajax_wat_delete() {
    lcrm_verify_admin();
    global $wpdb;
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'msg' => 'Template ID required.' ] );
    $wpdb->delete( "{$wpdb->prefix}lcrm_wa_templates", [ 'id' => $id ] );
    wp_send_json_success( [ 'msg' => 'Template deleted.' ] );
}

// TRACE: Reorders templates by accepting a JSON array of IDs in new order.
//        POSTCONDITIONS: sort_order updated for each template.
function lcrm_ajax_wat_reorder() {
    lcrm_verify_admin();
    global $wpdb;
    $ids = array_map( 'intval', json_decode( sanitize_text_field( $_POST['ids'] ?? '[]' ), true ) ?: [] );
    foreach ( $ids as $order => $id ) {
        if ( $id ) $wpdb->update( "{$wpdb->prefix}lcrm_wa_templates", [ 'sort_order' => $order ], [ 'id' => $id ] );
    }
    wp_send_json_success( [ 'msg' => 'Order saved.' ] );
}

// TRACE: Renders a template with lead placeholders replaced.
//        Returns rendered text for preview in the modal before sending.
function lcrm_ajax_wat_render() {
    lcrm_verify();
    $template_id = intval( $_POST['template_id'] ?? 0 );
    $lead_id     = intval( $_POST['lead_id']     ?? 0 );

    global $wpdb;
    $template = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_wa_templates WHERE id=%d", $template_id
    ) );
    if ( ! $template ) wp_send_json_error( [ 'msg' => 'Template not found.' ] );

    $lead     = $lead_id ? lcrm_get_lead( $lead_id ) : null;
    $rendered = lcrm_wa_templates_render( $template->body, $lead );

    wp_send_json_success( [ 'text' => $rendered ] );
}

// TRACE: Sends a rendered WA template immediately to a lead.
//        PRECONDITIONS: lead_id valid; lead has phone; WA account available.
//        POSTCONDITIONS: WA message sent; conversation logged; wa_sent flag set.
//        EDGE CASES: No phone → error; no WA account → error; API fail → error returned.
function lcrm_ajax_wat_send() {
    lcrm_verify();
    $lead_id     = intval( $_POST['lead_id']     ?? 0 );
    $template_id = intval( $_POST['template_id'] ?? 0 );
    $custom_text = sanitize_textarea_field( $_POST['custom_text'] ?? '' ); // may be edited by agent

    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $lead = lcrm_get_lead( $lead_id );
    if ( ! $lead ) wp_send_json_error( [ 'msg' => 'Lead not found.' ] );
    if ( ! $lead->client_phone ) wp_send_json_error( [ 'msg' => 'Lead has no phone number.' ] );

    // Use custom_text if agent edited, otherwise render template
    if ( $custom_text ) {
        $message = $custom_text;
    } elseif ( $template_id ) {
        global $wpdb;
        $template = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lcrm_wa_templates WHERE id=%d", $template_id
        ) );
        if ( ! $template ) wp_send_json_error( [ 'msg' => 'Template not found.' ] );
        $message = lcrm_wa_templates_render( $template->body, $lead );
    } else {
        wp_send_json_error( [ 'msg' => 'No message or template provided.' ] );
    }

    $account_id = lcrm_wa_find_account_for_lead( $lead );
    if ( ! $account_id ) wp_send_json_error( [ 'msg' => 'No WhatsApp account configured. Please set up a WA account in Settings → WhatsApp.' ] );

    $result = lcrm_wa_send_message( $account_id, $lead->client_phone, $message );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'msg' => $result->get_error_message() ] );

    lcrm_add_conversation( $lead_id, $message );

    wp_send_json_success( [ 'msg' => 'Message sent via WhatsApp.' ] );
}
