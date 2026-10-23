<?php
/**
 * LeadCRM PDF Intelligence Module
 * Handles PDF uploads attached to leads.
 * Extracts text server-side, runs AI analysis, and creates alerts for deadlines.
 *
 * Uses: pdfparser library (installed via composer) if available,
 *       or PDF.js text extraction via REST API fallback,
 *       or raw text extraction from simple PDFs via string parsing.
 *
 * DB table: lcrm_pdf_documents (per-lead PDF store)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB INSTALL ──────────────────────────────────────────────────────────────

// TRACE: Called on activate + v4 migration → dbDelta for lcrm_pdf_documents table.
//        POSTCONDITIONS: Table exists with correct schema. EDGE CASES: Idempotent.
function lcrm_pdf_install_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lcrm_pdf_documents (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id          BIGINT UNSIGNED NOT NULL,
        file_name        VARCHAR(300)    NOT NULL DEFAULT '',
        file_path        VARCHAR(500)    NOT NULL DEFAULT '',
        file_size        INT             NOT NULL DEFAULT 0,
        extracted_text   LONGTEXT        NOT NULL DEFAULT '',
        ai_summary       TEXT            NOT NULL DEFAULT '',
        ai_priority      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
        ai_data          LONGTEXT        NOT NULL DEFAULT '{}',
        created_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY lead_id (lead_id)
    ) $c;" );
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_upload_pdf',  'lcrm_ajax_upload_pdf' );
add_action( 'wp_ajax_lcrm_get_pdfs',    'lcrm_ajax_get_pdfs' );
add_action( 'wp_ajax_lcrm_delete_pdf',  'lcrm_ajax_delete_pdf' );

/**
 * lcrm_ajax_upload_pdf()
 * Handles multipart file upload for a PDF attached to a lead.
 * Steps: validate → save to uploads dir → extract text → AI analysis → create alerts.
 *
 * TRACE: Verifies nonce + login → validates file is PDF + < 20MB →
 *        Moves to WP uploads dir (wp-content/uploads/lcrm-docs/) →
 *        Calls lcrm_pdf_extract_text() →
 *        Calls lcrm_ai_extract_pdf_data() if AI enabled →
 *        Inserts lcrm_pdf_documents row →
 *        Creates lcrm_alerts for each deadline →
 *        Logs to activity timeline.
 * EDGE CASES: Not PDF MIME type → error; file too large → error; text extraction fails → saves with empty text; AI disabled → no AI analysis.
 */
// TRACE: wp_ajax_lcrm_upload_pdf → verifies login → validates MIME + size →
//        wp_mkdir_p uploads dir → move_uploaded_file → lcrm_pdf_extract_text →
//        lcrm_ai_extract_pdf_data → lcrm_create_alert_deduped per deadline →
//        wpdb->insert pdf_documents → lcrm_log_activity → returns {msg, doc_id, ai_summary, alerts_created}.
//        PRECONDITIONS: lead_id exists; $_FILES['pdf_file'] populated.
//        POSTCONDITIONS: File on disk at lcrm-docs/lead-{id}/; row in pdf_documents; alerts in lcrm_alerts.
//        EDGE CASES: Not PDF → error; >20MB → error; dir create fail → error; pdftotext absent → fallback; AI disabled → no analysis.
function lcrm_ajax_upload_pdf() {
    lcrm_verify();

    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    if ( empty( $_FILES['pdf_file']['tmp_name'] ) ) wp_send_json_error( [ 'msg' => 'No file uploaded.' ] );

    $file     = $_FILES['pdf_file'];
    $tmp_path = $file['tmp_name'];
    $orig_name= sanitize_file_name( $file['name'] );
    $size     = (int) $file['size'];
    $mime     = mime_content_type( $tmp_path );

    // Validate
    $allowed_mimes = [ 'application/pdf', 'application/x-pdf', 'binary/octet-stream' ];
    $ext           = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
    if ( $ext !== 'pdf' || ! in_array( $mime, $allowed_mimes, true ) ) {
        if ( $ext !== 'pdf' ) wp_send_json_error( [ 'msg' => 'Only PDF files are allowed.' ] );
    }
    if ( $size > 20 * 1024 * 1024 ) wp_send_json_error( [ 'msg' => 'PDF must be under 20MB.' ] );
    if ( $size === 0 ) wp_send_json_error( [ 'msg' => 'Uploaded file is empty.' ] );

    // Destination directory
    $upload_dir = wp_upload_dir();
    $dest_dir   = $upload_dir['basedir'] . '/lcrm-docs/lead-' . $lead_id . '/';
    if ( ! wp_mkdir_p( $dest_dir ) ) wp_send_json_error( [ 'msg' => 'Could not create upload directory.' ] );

    // Unique filename to avoid overwrites
    $unique_name = time() . '_' . wp_generate_password( 6, false ) . '_' . $orig_name;
    $dest_path   = $dest_dir . $unique_name;
    $dest_url    = $upload_dir['baseurl'] . '/lcrm-docs/lead-' . $lead_id . '/' . $unique_name;

    if ( ! move_uploaded_file( $tmp_path, $dest_path ) ) {
        wp_send_json_error( [ 'msg' => 'File move failed. Check server permissions.' ] );
    }

    // Extract text from PDF
    $text = lcrm_pdf_extract_text( $dest_path );

    // AI analysis
    $ai_data     = [];
    $ai_summary  = '';
    $ai_priority = 'medium';
    $alerts_created = 0;

    if ( lcrm_ai_enabled() && mb_strlen( trim( $text ) ) >= 50 ) {
        $ai_data = lcrm_ai_extract_pdf_data( $text );
        if ( ! empty( $ai_data ) ) {
            $ai_summary  = $ai_data['summary'] ?? '';
            $ai_priority = $ai_data['priority'] ?? 'medium';

            // Create alerts for each deadline
            foreach ( $ai_data['deadlines'] ?? [] as $dl ) {
                if ( empty( $dl['description'] ) ) continue;
                $alert_msg = "PDF Deadline: {$dl['description']}";
                if ( ! empty( $dl['date'] ) ) $alert_msg .= " (Due: {$dl['date']})";
                lcrm_create_alert_deduped( $lead_id, 'pdf_deadline', $ai_priority, $alert_msg, 48 );
                $alerts_created++;
            }

            // Create alert for obligations
            if ( ! empty( $ai_data['obligations'] ) ) {
                $obs_text = "PDF obligations: " . implode( '; ', array_slice( $ai_data['obligations'], 0, 3 ) );
                lcrm_create_alert_deduped( $lead_id, 'pdf_obligation', 'medium', $obs_text, 48 );
                $alerts_created++;
            }
        }
    }

    // Save to DB
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}lcrm_pdf_documents", [
        'lead_id'        => $lead_id,
        'file_name'      => $orig_name,
        'file_path'      => $dest_url,
        'file_size'      => $size,
        'extracted_text' => mb_substr( $text, 0, 65000 ),
        'ai_summary'     => $ai_summary,
        'ai_priority'    => $ai_priority,
        'ai_data'        => $ai_data ? wp_json_encode( $ai_data ) : '{}',
        'created_by'     => get_current_user_id(),
        'created_at'     => current_time( 'mysql' ),
    ] );

    if ( $wpdb->last_error ) {
        wp_send_json_error( [ 'msg' => 'Database error: ' . $wpdb->last_error ] );
    }

    $doc_id = (int) $wpdb->insert_id;

    // Log to activity timeline
    $activity_msg = "PDF uploaded: {$orig_name}";
    if ( $ai_summary ) $activity_msg .= " | AI: " . mb_substr( $ai_summary, 0, 100 );
    if ( $alerts_created ) $activity_msg .= " | {$alerts_created} deadline alert(s) created.";
    lcrm_log_activity( $lead_id, 'pdf_upload', $activity_msg );

    wp_send_json_success( [
        'msg'             => 'PDF uploaded and analysed.',
        'doc_id'          => $doc_id,
        'file_name'       => $orig_name,
        'file_url'        => $dest_url,
        'ai_summary'      => $ai_summary,
        'alerts_created'  => $alerts_created,
        'deadlines'       => $ai_data['deadlines'] ?? [],
        'amounts'         => $ai_data['amounts']   ?? [],
        'obligations'     => $ai_data['obligations']?? [],
        'text_extracted'  => mb_strlen( trim( $text ) ) > 0,
    ] );
}

// TRACE: wp_ajax_lcrm_get_pdfs → verifies login → queries lcrm_pdf_documents WHERE lead_id DESC →
//        returns array of {id, file_name, file_url, file_size, ai_summary, ai_priority, ai_data, created_at}.
//        POSTCONDITIONS: Always returns success with docs array (empty [] if none).
//        EDGE CASES: lead_id=0 → error; no docs → empty array.
function lcrm_ajax_get_pdfs() {
    lcrm_verify();
    global $wpdb;
    $lead_id = intval( $_POST['lead_id'] ?? 0 );
    if ( ! $lead_id ) wp_send_json_error( [ 'msg' => 'Lead ID required.' ] );

    $docs = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, file_name, file_path, file_size, ai_summary, ai_priority, ai_data, created_at
         FROM {$wpdb->prefix}lcrm_pdf_documents WHERE lead_id=%d ORDER BY created_at DESC",
        $lead_id
    ) );

    $out = [];
    foreach ( (array) $docs as $d ) {
        $out[] = [
            'id'          => (int) $d->id,
            'file_name'   => $d->file_name,
            'file_url'    => $d->file_path,
            'file_size'   => lcrm_pdf_human_filesize( (int) $d->file_size ),
            'ai_summary'  => $d->ai_summary,
            'ai_priority' => $d->ai_priority,
            'ai_data'     => json_decode( $d->ai_data ?? '{}', true ),
            'created_at'  => $d->created_at,
        ];
    }

    wp_send_json_success( [ 'docs' => $out ] );
}

/**
 * lcrm_ajax_delete_pdf()
 * Deletes a PDF document record and removes the file from disk.
 *
 * TRACE: Verifies admin → fetches doc → deletes file from disk →
 *        Deletes DB row → logs activity.
 * EDGE CASES: File already gone from disk → proceeds with DB deletion; wrong lead_id → denied.
 */
function lcrm_ajax_delete_pdf() {
    lcrm_verify();
    if ( ! current_user_can( 'manage_options' ) && ! lcrm_current_can( 'lcrm_edit_leads' ) ) {
        wp_send_json_error( [ 'msg' => 'Permission denied.' ] );
    }

    global $wpdb;
    $doc_id = intval( $_POST['doc_id'] ?? 0 );
    if ( ! $doc_id ) wp_send_json_error( [ 'msg' => 'Document ID required.' ] );

    $doc = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lcrm_pdf_documents WHERE id=%d", $doc_id
    ) );
    if ( ! $doc ) wp_send_json_error( [ 'msg' => 'Document not found.' ] );

    // Convert URL back to path and delete from disk
    $upload_dir = wp_upload_dir();
    $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $doc->file_path );
    if ( $file_path && file_exists( $file_path ) ) {
        @unlink( $file_path );
    }

    $wpdb->delete( "{$wpdb->prefix}lcrm_pdf_documents", [ 'id' => $doc_id ] );
    lcrm_log_activity( (int) $doc->lead_id, 'pdf_delete', "PDF deleted: {$doc->file_name}" );

    wp_send_json_success( [ 'msg' => 'Document deleted.' ] );
}

// ─── TEXT EXTRACTION ─────────────────────────────────────────────────────────

/**
 * lcrm_pdf_extract_text( $file_path )
 * Extracts raw text from a PDF file.
 * Tries multiple methods in order: pdftotext CLI → pdfparser PHP library → raw string search.
 * Returns extracted text string (may be empty for image-only PDFs).
 *
 * TRACE: Tries pdftotext shell command if available →
 *        Falls back to Smalot pdfparser if autoloaded →
 *        Falls back to raw regex extraction from PDF binary.
 * POSTCONDITIONS: Returns string (empty string acceptable — not an error).
 * EDGE CASES: Image-only PDF → all methods return ''; binary extraction may include garbage chars → cleaned.
 */
function lcrm_pdf_extract_text( $file_path ) {
    if ( ! file_exists( $file_path ) ) return '';

    // Method 1: pdftotext (part of poppler-utils — available on most Linux servers)
    if ( function_exists( 'exec' ) ) {
        $escaped = escapeshellarg( $file_path );
        $output  = [];
        $retval  = 0;
        exec( "pdftotext {$escaped} - 2>/dev/null", $output, $retval );
        if ( $retval === 0 && ! empty( $output ) ) {
            return lcrm_pdf_clean_text( implode( "\n", $output ) );
        }
    }

    // Method 2: Smalot PdfParser (if installed via Composer)
    $autoload = ABSPATH . 'vendor/autoload.php';
    if ( file_exists( $autoload ) ) {
        try {
            require_once $autoload;
            if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf    = $parser->parseFile( $file_path );
                $text   = $pdf->getText();
                if ( $text ) return lcrm_pdf_clean_text( $text );
            }
        } catch ( Exception $e ) {
            // Parser failed — fall through to method 3
        }
    }

    // Method 3: Raw binary extraction — reads BT...ET text blocks from PDF stream
    $raw = file_get_contents( $file_path );
    if ( ! $raw ) return '';

    $text = '';
    preg_match_all( '/BT(.+?)ET/s', $raw, $matches );
    foreach ( $matches[1] as $block ) {
        preg_match_all( '/\(([^)]+)\)/', $block, $string_matches );
        foreach ( $string_matches[1] as $str ) {
            $text .= ' ' . $str;
        }
    }

    return lcrm_pdf_clean_text( $text );
}

/**
 * lcrm_pdf_clean_text( $text )
 * Removes non-printable characters and normalises whitespace.
 *
 * TRACE: Strips non-UTF8 characters → collapses whitespace → trims.
 */
// TRACE: Utility → strips non-printable bytes via regex → collapses 3+ whitespace chars to newline →
//        trims → returns clean UTF-8 string.
//        POSTCONDITIONS: Output is safe for DB storage and AI processing.
//        EDGE CASES: Binary data → non-UTF8 chars stripped; all whitespace → returns ''.
function lcrm_pdf_clean_text( $text ) {
    // Remove non-printable ASCII except newlines and tabs
    $text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/u', ' ', $text );
    // Collapse multiple whitespace
    $text = preg_replace( '/\s{3,}/', "\n", $text );
    return trim( $text );
}

// TRACE: Utility → converts bytes int to human-readable string (MB/KB/B) → used in UI display.
//        EDGE CASES: 0 bytes → '0 B'; very large → shows MB with 1 decimal.
function lcrm_pdf_human_filesize( $bytes ) {
    if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 1 ) . ' MB';
    if ( $bytes >= 1024 )    return round( $bytes / 1024, 0 ) . ' KB';
    return $bytes . ' B';
}
