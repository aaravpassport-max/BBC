<?php
/**
 * LeadCRM Form Capture Module
 * Hooks into every major WordPress form plugin and WooCommerce automatically.
 * Also provides a generic REST API endpoint for custom forms and chatbots.
 *
 * Hooks covered:
 *   - Contact Form 7        (wpcf7_mail_sent)
 *   - WPForms               (wpforms_process_complete)
 *   - Gravity Forms         (gform_after_submission)
 *   - Ninja Forms           (ninja_forms_after_submission)
 *   - Fluent Forms          (fluentform_submission_inserted)
 *   - WooCommerce new order (woocommerce_checkout_order_processed)
 *   - Generic REST endpoint /lcrm/v1/lead-capture  (for any custom form/chatbot)
 *
 * Every captured form submission calls lcrm_form_capture_engine() which:
 *   1. Normalises the data to a standard schema
 *   2. Checks for duplicate (phone or email)
 *   3. Optionally runs AI extraction for richer data
 *   4. Creates or updates the lead
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── REST ENDPOINT: GENERIC LEAD CAPTURE ────────────────────────────────────

add_action( 'rest_api_init', 'lcrm_form_register_routes' );

// TRACE: Hook rest_api_init → registers POST /lcrm/v1/lead-capture (public, API-key secured).
//        POSTCONDITIONS: Endpoint available via wp-json after WP bootstrap.
function lcrm_form_register_routes() {
    // Public endpoint — secured by API key in Authorization header or query param
    register_rest_route( 'lcrm/v1', '/lead-capture', [
        'methods'             => 'POST',
        'callback'            => 'lcrm_rest_lead_capture',
        'permission_callback' => '__return_true', // Key checked inside handler
    ] );
}

/**
 * lcrm_rest_lead_capture( WP_REST_Request $request )
 * Generic REST endpoint for any external form, chatbot, or landing page.
 * Secured by API key — set in LeadCRM → Settings → Integrations.
 *
 * Expected JSON body:
 * {
 *   "api_key": "xxx",
 *   "client_name": "Rajesh Kumar",
 *   "client_phone": "+919876543210",
 *   "client_email": "rajesh@example.com",
 *   "work_type": "NRI Documentation",
 *   "lead_source": "Website Chat",
 *   "message": "I need help with...",
 *   "brand_id": 1  (optional)
 * }
 *
 * TRACE: Checks API key → validates required fields →
 *        Calls lcrm_form_capture_engine →
 *        Returns lead_id on success.
 * EDGE CASES: Wrong API key → 401; missing name → 422; duplicate → returns existing lead_id with updated=true.
 */
function lcrm_rest_lead_capture( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    if ( ! $params ) $params = $request->get_body_params();

    // Auth check
    $submitted_key = sanitize_text_field( $params['api_key'] ?? $request->get_header( 'X-LCRM-Key' ) ?? '' );
    $stored_key    = get_option( 'lcrm_rest_api_key', '' );

    if ( ! $stored_key || ! hash_equals( $stored_key, $submitted_key ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid API key.' ], 401 );
    }

    $name    = sanitize_text_field( $params['client_name'] ?? '' );
    $phone   = sanitize_text_field( $params['client_phone'] ?? '' );
    $email   = sanitize_email(      $params['client_email'] ?? '' );
    $message = sanitize_textarea_field( $params['message'] ?? '' );

    if ( ! $name ) return new WP_REST_Response( [ 'success' => false, 'message' => 'client_name is required.' ], 422 );

    $result = lcrm_form_capture_engine( [
        'client_name'  => $name,
        'client_phone' => $phone,
        'client_email' => $email,
        'work_type'    => sanitize_text_field( $params['work_type']   ?? '' ),
        'lead_source'  => sanitize_text_field( $params['lead_source'] ?? 'API' ),
        'brand_id'     => intval( $params['brand_id'] ?? 0 ),
        'message'      => $message,
        'raw_text'     => $name . ' ' . $phone . ' ' . $message,
    ] );

    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 422 );
    }

    return new WP_REST_Response( [
        'success' => true,
        'lead_id' => $result['lead_id'],
        'updated' => $result['updated'],
    ], 200 );
}

// ─── CONTACT FORM 7 ──────────────────────────────────────────────────────────

add_action( 'wpcf7_mail_sent', 'lcrm_cf7_capture', 10, 1 );

/**
 * lcrm_cf7_capture( $contact_form )
 * Captures Contact Form 7 submissions.
 * Tries common field name patterns used by most CF7 forms.
 *
 * TRACE: Gets submission from WPCF7_Submission::get_instance() →
 *        Extracts posted_data →
 *        Maps known field keys to standard schema →
 *        Calls lcrm_form_capture_engine.
 * EDGE CASES: CF7 not active → hook never fires; missing phone field → captures with phone=''; custom field names → mapped via pattern matching.
 */
function lcrm_cf7_capture( $contact_form ) {
    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission ) return;

    $data = $submission->get_posted_data();

    // Try multiple common CF7 field name conventions
    $name  = $data['your-name'] ?? $data['name'] ?? $data['full-name'] ?? $data['client_name'] ?? '';
    $phone = $data['your-phone'] ?? $data['phone'] ?? $data['mobile'] ?? $data['contact'] ?? $data['your-tel'] ?? '';
    $email = $data['your-email'] ?? $data['email'] ?? $data['your_email'] ?? '';
    $msg   = $data['your-message'] ?? $data['message'] ?? $data['your_message'] ?? $data['description'] ?? '';
    $svc   = $data['service'] ?? $data['work-type'] ?? $data['your-subject'] ?? $data['subject'] ?? '';

    // Build combined text for AI extraction
    $all_values = implode( ' ', array_filter( array_map( 'sanitize_text_field', array_values( $data ) ) ) );

    if ( ! $name && ! $phone && ! $email ) return; // Nothing useful captured

    lcrm_form_capture_engine( [
        'client_name'  => sanitize_text_field( $name ),
        'client_phone' => sanitize_text_field( $phone ),
        'client_email' => sanitize_email( $email ),
        'work_type'    => sanitize_text_field( $svc ),
        'lead_source'  => 'Website Form',
        'message'      => sanitize_textarea_field( $msg ),
        'raw_text'     => $all_values,
        'form_name'    => $contact_form->name(),
    ] );
}

// ─── WPFORMS ─────────────────────────────────────────────────────────────────

add_action( 'wpforms_process_complete', 'lcrm_wpforms_capture', 10, 4 );

/**
 * lcrm_wpforms_capture( $fields, $entry, $form_data, $entry_id )
 * Captures WPForms submissions using field labels as lookup keys.
 *
 * TRACE: Iterates $fields array →
 *        Matches label patterns to standard schema keys →
 *        Calls lcrm_form_capture_engine.
 * EDGE CASES: Fields keyed by ID not label → uses value extraction fallback.
 */
function lcrm_wpforms_capture( $fields, $entry, $form_data, $entry_id ) {
    $mapped = lcrm_form_extract_by_label( $fields );
    if ( ! $mapped['client_name'] && ! $mapped['client_phone'] ) return;

    $mapped['lead_source'] = 'Website Form';
    $mapped['form_name']   = $form_data['settings']['form_title'] ?? '';
    lcrm_form_capture_engine( $mapped );
}

// ─── GRAVITY FORMS ───────────────────────────────────────────────────────────

add_action( 'gform_after_submission', 'lcrm_gforms_capture', 10, 2 );

/**
 * lcrm_gforms_capture( $entry, $form )
 * Captures Gravity Forms submissions.
 *
 * TRACE: Iterates form fields → matches admin labels → maps to schema →
 *        Calls lcrm_form_capture_engine.
 */
// TRACE: Hook: gform_after_submission → iterates form.fields array → matches admin labels to schema →
//        builds mapped array → calls lcrm_form_capture_engine.
//        EDGE CASES: Form with no name/phone fields → returns early; label case insensitive.
function lcrm_gforms_capture( $entry, $form ) {
    $mapped = [ 'client_name' => '', 'client_phone' => '', 'client_email' => '', 'work_type' => '', 'message' => '', 'raw_text' => '' ];
    $all    = [];

    foreach ( $form['fields'] as $field ) {
        $fid   = $field->id;
        $label = strtolower( $field->label );
        $val   = sanitize_text_field( $entry[ $fid ] ?? '' );
        $all[] = $val;

        if ( preg_match( '/name|full.?name/i', $label ) )     $mapped['client_name']  = $val;
        elseif ( preg_match( '/phone|mobile|tel/i', $label ) )$mapped['client_phone'] = $val;
        elseif ( preg_match( '/email/i', $label ) )           $mapped['client_email'] = sanitize_email( $val );
        elseif ( preg_match( '/service|work|type/i', $label ) )$mapped['work_type']   = $val;
        elseif ( preg_match( '/message|inquiry|query/i', $label ) )$mapped['message'] = $val;
    }

    $mapped['raw_text']   = implode( ' ', array_filter( $all ) );
    $mapped['lead_source']= 'Website Form';
    $mapped['form_name']  = $form['title'] ?? '';

    if ( ! $mapped['client_name'] && ! $mapped['client_phone'] ) return;
    lcrm_form_capture_engine( $mapped );
}

// ─── NINJA FORMS ─────────────────────────────────────────────────────────────

add_action( 'ninja_forms_after_submission', 'lcrm_ninjaforms_capture', 10, 1 );

// TRACE: Hook: ninja_forms_after_submission → passes fields to lcrm_form_extract_by_label('ninja') →
//        returns early if no name/phone → calls capture engine.
//        EDGE CASES: Ninja Forms field format differs from WPForms; handled by format param.
function lcrm_ninjaforms_capture( $form_data ) {
    $fields = $form_data['fields'] ?? [];
    $mapped = lcrm_form_extract_by_label( $fields, 'ninja' );
    if ( ! $mapped['client_name'] && ! $mapped['client_phone'] ) return;
    $mapped['lead_source'] = 'Website Form';
    lcrm_form_capture_engine( $mapped );
}

// ─── FLUENT FORMS ────────────────────────────────────────────────────────────

add_action( 'fluentform_submission_inserted', 'lcrm_fluentforms_capture', 10, 3 );

// TRACE: Hook fluentform_submission_inserted → reads $formData array by key names →
//        maps name/phone/email/service/message → calls lcrm_form_capture_engine.
//        EDGE CASES: Missing fields default to empty string; returns early if no name+phone.
function lcrm_fluentforms_capture( $insertId, $formData, $form ) {
    $mapped = [
        'client_name'  => sanitize_text_field( $formData['full_name'] ?? $formData['name'] ?? $formData['your_name'] ?? '' ),
        'client_phone' => sanitize_text_field( $formData['phone'] ?? $formData['mobile'] ?? $formData['your_phone'] ?? '' ),
        'client_email' => sanitize_email( $formData['email'] ?? $formData['your_email'] ?? '' ),
        'work_type'    => sanitize_text_field( $formData['service'] ?? $formData['work_type'] ?? '' ),
        'message'      => sanitize_textarea_field( $formData['message'] ?? $formData['your_message'] ?? '' ),
        'raw_text'     => implode( ' ', array_filter( array_map( 'sanitize_text_field', array_values( $formData ) ) ) ),
        'lead_source'  => 'Website Form',
        'form_name'    => $form->title ?? '',
    ];
    if ( ! $mapped['client_name'] && ! $mapped['client_phone'] ) return;
    lcrm_form_capture_engine( $mapped );
}

// ─── WOOCOMMERCE CHECKOUT ────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_order_processed', 'lcrm_woocommerce_capture', 10, 3 );

/**
 * lcrm_woocommerce_capture( $order_id, $posted_data, $order )
 * Creates a lead from every new WooCommerce order.
 * Useful for service-based WooCommerce shops (e.g. consultation booking).
 *
 * TRACE: Reads billing details from order →
 *        Assembles standard schema →
 *        Calls lcrm_form_capture_engine with source='WooCommerce'.
 * EDGE CASES: Guest checkout → uses billing email/name; order object null → returns.
 */
function lcrm_woocommerce_capture( $order_id, $posted_data, $order ) {
    if ( ! $order ) return;

    $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    $phone = $order->get_billing_phone();
    $email = $order->get_billing_email();
    $items = implode( ', ', array_map( function( $item ) { return $item->get_name(); }, $order->get_items() ) );

    lcrm_form_capture_engine( [
        'client_name'  => sanitize_text_field( $name ),
        'client_phone' => sanitize_text_field( $phone ),
        'client_email' => sanitize_email( $email ),
        'work_type'    => mb_substr( $items, 0, 150 ),
        'lead_source'  => 'WooCommerce',
        'message'      => "Order #{$order_id}: {$items}",
        'raw_text'     => $name . ' ' . $phone . ' ' . $items,
        'total_quote'  => (float) $order->get_total(),
        'brand_id'     => 0,
    ] );
}

// ─── CORE CAPTURE ENGINE ─────────────────────────────────────────────────────

/**
 * lcrm_form_capture_engine( $data )
 * Central processing function for all form submissions.
 * Returns array ['lead_id' => int, 'updated' => bool] or WP_Error.
 *
 * TRACE: Checks required fields → duplicate check by phone/email →
 *        If duplicate: updates existing lead, appends conversation →
 *        If new: optionally runs AI extraction → creates lead →
 *        Returns result array.
 * PRECONDITIONS: $data array with at least client_name or client_phone.
 * POSTCONDITIONS: Lead exists in DB; conversation entry added.
 * EDGE CASES: Both phone and email missing → WP_Error; AI disabled → basic lead; DB insert fails → WP_Error.
 */
function lcrm_form_capture_engine( $data ) {
    $name    = sanitize_text_field( $data['client_name']  ?? '' );
    $phone   = sanitize_text_field( $data['client_phone'] ?? '' );
    $email   = sanitize_email(      $data['client_email'] ?? '' );
    $message = sanitize_textarea_field( $data['message']  ?? '' );
    $source  = sanitize_text_field( $data['lead_source']  ?? 'Website Form' );
    $work    = sanitize_text_field( $data['work_type']    ?? '' );
    $brand   = intval( $data['brand_id'] ?? 0 );
    $raw     = sanitize_textarea_field( $data['raw_text'] ?? ( $name . ' ' . $phone . ' ' . $message ) );
    $form_nm = sanitize_text_field( $data['form_name']    ?? '' );
    $quote   = floatval( $data['total_quote'] ?? 0 );

    if ( ! $name && ! $phone && ! $email ) {
        return new WP_Error( 'missing_data', 'Name, phone, or email is required.' );
    }

    // Check duplicate — phone takes priority, then email
    $dups = lcrm_check_duplicate( $phone, $email );
    if ( ! empty( $dups ) ) {
        $lead_id = $dups[0]->id;
        // Update existing lead with new contact info
        global $wpdb;
        $update = [ 'last_client_contact' => current_time( 'mysql' ) ];
        if ( $email && ! $dups[0]->client_email ) $update['client_email'] = $email;
        if ( $phone && ! $dups[0]->client_phone ) $update['client_phone'] = $phone;
        $wpdb->update( "{$wpdb->prefix}lcrm_leads", $update, [ 'id' => $lead_id ] );

        $note = "[Form: {$source}" . ( $form_nm ? " / {$form_nm}" : '' ) . "] " . mb_substr( $message ?: $raw, 0, 300 );
        lcrm_add_conversation( $lead_id, $note );
        lcrm_log_activity( $lead_id, 'form_resubmit', "Form resubmission from {$source}" );

        return [ 'lead_id' => $lead_id, 'updated' => true ];
    }

    // AI enrichment for new leads
    $temperature = 'warm';
    $tasks       = [];

    if ( lcrm_ai_enabled() && $raw ) {
        $extracted = lcrm_ai_extract_lead_data( $raw, $source );
        if ( ! empty( $extracted ) ) {
            if ( ! $name && ! empty( $extracted['client_name'] ) )   $name        = $extracted['client_name'];
            if ( ! $work && ! empty( $extracted['work_type'] ) )     $work        = $extracted['work_type'];
            if ( ! empty( $extracted['lead_temperature'] ) )         $temperature = $extracted['lead_temperature'];
            if ( ! empty( $extracted['tasks'] ) )                    $tasks       = $extracted['tasks'];
            if ( ! empty( $extracted['client_phone'] ) && ! $phone ) $phone       = $extracted['client_phone'];
            if ( ! empty( $extracted['client_email'] ) && ! $email ) $email       = $extracted['client_email'];
        }
    }

    $remarks = ( $form_nm ? "Form: {$form_nm}\n" : '' )
             . ( $message ? "Message: " . mb_substr( $message, 0, 500 ) : mb_substr( $raw, 0, 300 ) );

    $lead_id = lcrm_create_lead( [
        'lead_date'        => date( 'Y-m-d' ),
        'client_name'      => $name ?: 'Website Enquiry',
        'client_phone'     => $phone,
        'client_email'     => $email,
        'work_type'        => $work,
        'lead_source'      => $source,
        'stage_id'         => 1,
        'brand_id'         => $brand ?: null,
        'priority'         => 'medium',
        'lead_temperature' => $temperature,
        'total_quote'      => $quote,
        'remarks'          => $remarks,
        'created_by'       => 0,
    ] );

    if ( ! $lead_id || is_wp_error( $lead_id ) ) {
        return is_wp_error( $lead_id ) ? $lead_id : new WP_Error( 'create_fail', 'Lead creation failed.' );
    }

    if ( $message ) lcrm_add_conversation( $lead_id, "[{$source}] " . mb_substr( $message, 0, 500 ) );
    if ( ! empty( $tasks ) ) lcrm_ai_insert_tasks_as_alerts( $lead_id, $tasks );

    return [ 'lead_id' => $lead_id, 'updated' => false ];
}

// ─── LABEL-BASED FIELD EXTRACTOR ────────────────────────────────────────────

/**
 * lcrm_form_extract_by_label( $fields, $format )
 * Generic extractor that maps form fields to standard schema by matching label text.
 * Works for WPForms field array format and Ninja Forms field array format.
 *
 * TRACE: Iterates fields → extracts label and value → matches patterns →
 *        Returns mapped array.
 */
// TRACE: Utility → iterates $fields array → matches field label text against regex patterns →
//        Populates mapped array with client_name, phone, email, work_type, message →
//        Returns standardised array for lcrm_form_capture_engine.
//        PRECONDITIONS: $format is 'wpforms' or 'ninja' (affects field key access).
//        EDGE CASES: No matching labels → all values ''; ambiguous labels → last match wins.
function lcrm_form_extract_by_label( $fields, $format = 'wpforms' ) {
    $mapped = [ 'client_name' => '', 'client_phone' => '', 'client_email' => '', 'work_type' => '', 'message' => '', 'raw_text' => '' ];
    $all    = [];

    foreach ( $fields as $field ) {
        if ( $format === 'ninja' ) {
            $label = strtolower( $field['label'] ?? '' );
            $val   = sanitize_text_field( $field['value'] ?? '' );
        } else {
            // WPForms format
            $label = strtolower( $field['name'] ?? $field['label'] ?? '' );
            $val   = sanitize_text_field( $field['value'] ?? '' );
        }

        $all[] = $val;

        if ( preg_match( '/\b(name|full.?name|your.?name)\b/i', $label ) )   $mapped['client_name']  = $val;
        elseif ( preg_match( '/\b(phone|mobile|tel|contact)\b/i', $label ) ) $mapped['client_phone'] = $val;
        elseif ( preg_match( '/\bemail\b/i', $label ) )                      $mapped['client_email'] = sanitize_email( $val );
        elseif ( preg_match( '/\b(service|work.?type|service.?type)\b/i', $label ) ) $mapped['work_type'] = $val;
        elseif ( preg_match( '/\b(message|inquiry|query|description)\b/i', $label ) )$mapped['message']   = $val;
    }

    $mapped['raw_text'] = implode( ' ', array_filter( $all ) );
    return $mapped;
}

// ─── AJAX: API KEY MANAGEMENT ────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_regenerate_rest_key', 'lcrm_ajax_regenerate_rest_key' );

// TRACE: wp_ajax_lcrm_regenerate_rest_key → verifies admin → generates 'lcrm_' + 32-char password →
//        saves to wp_option lcrm_rest_api_key → returns {key: new_key}.
//        POSTCONDITIONS: Old key immediately invalid; new key returned to UI.
//        EDGE CASES: Called multiple times → each generates new key; no revocation list needed.
function lcrm_ajax_regenerate_rest_key() {
    lcrm_verify_admin();
    $new_key = 'lcrm_' . wp_generate_password( 32, false );
    update_option( 'lcrm_rest_api_key', $new_key );
    wp_send_json_success( [ 'key' => $new_key ] );
}
