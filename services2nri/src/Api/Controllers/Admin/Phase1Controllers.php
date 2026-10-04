<?php
/**
 * Phase 1 Controllers — M-01 through M-10, M-30
 * Email Templates, Holidays, Delivery Calculator, Magic Link,
 * Vendors, Request Types, Quick Replies, Communication
 */

namespace S2NRI\Api\Controllers\Admin;

use S2NRI\Api\{Request, Response};
use S2NRI\Api\Controllers\BaseController;

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════════════════
// EmailTemplateAdminController
// M-08: 7 built-in + custom HTML email templates with variable system
// ══════════════════════════════════════════════════════════════════════════════

class EmailTemplateAdminController extends BaseController {

    // Built-in template slugs that cannot be deleted
    private const BUILTINS = [
        'application_received', 'status_update', 'quote_sent',
        'document_reminder', 'dispatch', 'completion', 'terms_conditions',
    ];

    private const DEFAULT_TEMPLATES = [
        'application_received' => [
            'name'    => 'Application Received',
            'subject' => 'Your Application Has Been Received — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#4A6FA5;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
  <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:14px">NRI Services Platform</p>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">Application Received ✅</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Thank you for submitting your service request. We have received your application and our team will review it shortly.</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:8px;padding:0;margin:0 0 24px;border:1px solid #e2e8f0">
    <tr><td style="padding:16px 20px">
      <table width="100%"><tr>
        <td style="font-size:13px;color:#666;padding:4px 0">Application Reference:</td>
        <td style="font-size:13px;font-weight:700;color:#1E2D40;text-align:right;padding:4px 0">{BOOKING_REF}</td>
      </tr><tr>
        <td style="font-size:13px;color:#666;padding:4px 0">Service Requested:</td>
        <td style="font-size:13px;color:#374151;text-align:right;padding:4px 0">{SERVICE_NAME}</td>
      </tr><tr>
        <td style="font-size:13px;color:#666;padding:4px 0">Submitted On:</td>
        <td style="font-size:13px;color:#374151;text-align:right;padding:4px 0">{SUBMITTED_DATE}</td>
      </tr></table>
    </td></tr>
  </table>
  <p style="color:#555;font-size:14px;line-height:1.6;margin:0 0 24px">We will send you a quotation within 1-2 business days. You can track your request status using the button below.</p>
  <p style="text-align:center;margin:0 0 24px">
    <a href="{DASHBOARD_URL}" style="background:#4A6FA5;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">Track My Application →</a>
  </p>
  <p style="color:#888;font-size:13px;text-align:center;margin:0">If you have any questions, reply to this email or contact your assigned manager.</p>
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME} · Trusted NRI Services · <a href="{DASHBOARD_URL}" style="color:#4A6FA5;text-decoration:none">Visit Dashboard</a></p>
</td></tr>
</table></td></tr></table></body></html>',
        ],
        'status_update' => [
            'name'    => 'Status Update',
            'subject' => 'Status Update on Your Application — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#4A6FA5;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">Application Status Update</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">The status of your application <strong>{BOOKING_REF}</strong> has been updated.</p>
  <table width="100%" style="background:#f0fdf4;border-radius:8px;border:1px solid #86efac;margin:0 0 24px"><tr><td style="padding:16px 20px;text-align:center">
    <p style="color:#15803d;font-size:13px;margin:0 0 4px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Current Status</p>
    <p style="color:#166534;font-size:20px;font-weight:800;margin:0">{STATUS}</p>
    {SECONDARY_STATUS}
  </td></tr></table>
  {TIMELINE_BLOCK}
  <p style="text-align:center;margin:0 0 24px"><a href="{DASHBOARD_URL}" style="background:#4A6FA5;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">View My Application →</a></p>
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME}</p>
</td></tr></table></td></tr></table></body></html>',
        ],
        'quote_sent' => [
            'name'    => 'Quotation Sent',
            'subject' => 'Your Quotation is Ready — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#4A6FA5;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">Your Quotation is Ready 📋</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">We have prepared a quotation for your service request <strong>{BOOKING_REF}</strong>. Please review the details below.</p>
  <table width="100%" style="background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin:0 0 8px"><tr><td style="padding:16px 20px">
    <table width="100%">
      <tr><td style="font-size:13px;color:#666;padding:5px 0;border-bottom:1px solid #f0f0f0">Service Charges:</td><td style="font-size:13px;font-weight:600;color:#1E2D40;text-align:right;padding:5px 0;border-bottom:1px solid #f0f0f0">{SERVICE_CHARGES}</td></tr>
      <tr><td style="font-size:13px;color:#666;padding:5px 0;border-bottom:1px solid #f0f0f0">Shipping Charges ({SHIPPING_TYPE}):</td><td style="font-size:13px;font-weight:600;color:#1E2D40;text-align:right;padding:5px 0;border-bottom:1px solid #f0f0f0">{SHIPPING_CHARGES}</td></tr>
      <tr><td style="font-size:15px;font-weight:700;color:#4A6FA5;padding:8px 0 0">Total Amount:</td><td style="font-size:15px;font-weight:800;color:#4A6FA5;text-align:right;padding:8px 0 0">{TOTAL_AMOUNT}</td></tr>
    </table>
  </td></tr></table>
  <p style="color:#888;font-size:12px;margin:0 0 20px">Quotation valid until: {QUOTE_VALID_UNTIL}</p>
  {TIMELINE_BLOCK}
  <p style="text-align:center;margin:0 0 16px"><a href="{DASHBOARD_URL}" style="background:#16a34a;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">Approve Quotation →</a></p>
  <p style="color:#888;font-size:13px;text-align:center;margin:0">You can approve or reject this quotation from your dashboard.</p>
  {TC_BLOCK}
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME}</p>
</td></tr></table></td></tr></table></body></html>',
        ],
        'document_reminder' => [
            'name'    => 'Document Reminder',
            'subject' => 'Documents Required for Your Application — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#d97706;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">📎 Documents Required</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">We are processing your application <strong>{BOOKING_REF}</strong> and require additional documents to proceed.</p>
  <table width="100%" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin:0 0 24px"><tr><td style="padding:16px 20px">
    <p style="color:#92400e;font-size:14px;margin:0;font-weight:600">Required Documents</p>
    <p style="color:#78350f;font-size:14px;margin:8px 0 0;line-height:1.6">{DOCUMENT_TYPE}</p>
  </td></tr></table>
  <p style="color:#555;font-size:14px;line-height:1.6;margin:0 0 24px">Please upload these documents from your dashboard as soon as possible to avoid delays in processing.</p>
  <p style="text-align:center;margin:0 0 24px"><a href="{DASHBOARD_URL}" style="background:#d97706;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">Upload Documents →</a></p>
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME}</p>
</td></tr></table></td></tr></table></body></html>',
        ],
        'dispatch' => [
            'name'    => 'Documents Dispatched',
            'subject' => 'Your Documents Have Been Dispatched — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#7c3aed;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">Documents Dispatched 📦</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">Your documents for application <strong>{BOOKING_REF}</strong> have been dispatched and are on their way to you.</p>
  {TIMELINE_BLOCK}
  <p style="text-align:center;margin:0 0 24px"><a href="{DASHBOARD_URL}" style="background:#7c3aed;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">Track Application →</a></p>
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME}</p>
</td></tr></table></td></tr></table></body></html>',
        ],
        'completion' => [
            'name'    => 'Service Completed',
            'subject' => 'Your Service Has Been Completed — {BOOKING_REF}',
            'body'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 20px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
<tr><td style="background:#15803d;padding:30px 40px;text-align:center">
  <h1 style="color:#fff;margin:0;font-size:22px">{PLATFORM_NAME}</h1>
</td></tr>
<tr><td style="padding:36px 40px">
  <h2 style="color:#1E2D40;margin:0 0 16px;font-size:20px">Service Completed 🎉</h2>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 20px">Dear {CUSTOMER_NAME},</p>
  <p style="color:#555;font-size:15px;line-height:1.7;margin:0 0 24px">We are pleased to inform you that your service request <strong>{BOOKING_REF}</strong> has been completed successfully.</p>
  <table width="100%" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin:0 0 24px"><tr><td style="padding:16px 20px;text-align:center">
    <p style="color:#15803d;font-size:24px;margin:0">✅</p>
    <p style="color:#166534;font-size:16px;font-weight:700;margin:8px 0 0">Application {BOOKING_REF} — Completed</p>
  </td></tr></table>
  <p style="color:#555;font-size:14px;line-height:1.6;margin:0 0 24px">Thank you for choosing {PLATFORM_NAME}. We hope you are satisfied with our service. Please take a moment to leave a review from your dashboard.</p>
  <p style="text-align:center;margin:0 0 24px"><a href="{DASHBOARD_URL}" style="background:#15803d;color:#fff;text-decoration:none;padding:13px 30px;border-radius:7px;font-size:15px;font-weight:700;display:inline-block">Leave a Review →</a></p>
</td></tr>
<tr><td style="background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0">
  <p style="color:#aaa;font-size:12px;margin:0">{PLATFORM_NAME}</p>
</td></tr></table></td></tr></table></body></html>',
        ],
        'terms_conditions' => [
            'name'    => 'Terms & Conditions',
            'subject' => 'Terms and Conditions — {PLATFORM_NAME}',
            'body'    => '<table width="100%" style="font-family:Arial,sans-serif;color:#374151;font-size:13px;line-height:1.6">
<tr><td style="padding:16px 0"><h3 style="color:#1E2D40;margin:0 0 12px;font-size:15px;border-bottom:2px solid #4A6FA5;padding-bottom:8px">Terms & Conditions — {PLATFORM_NAME}</h3>
<p><strong>1. Payment:</strong> Full payment is required within {QUOTE_VALID_UNTIL} days of quotation approval. Work begins only after payment confirmation.</p>
<p><strong>2. Refund Policy:</strong> Refunds are processed only if the service cannot be completed due to our limitations. Processing fees are non-refundable.</p>
<p><strong>3. Timeline:</strong> Delivery timelines are estimates based on government processing speeds and may vary. {PLATFORM_NAME} is not responsible for delays caused by government departments.</p>
<p><strong>4. Documents:</strong> You are responsible for providing correct and authentic documents. {PLATFORM_NAME} is not liable for rejection due to incorrect client information.</p>
<p><strong>5. Communication:</strong> All updates will be communicated via the dashboard and email. Please ensure your email is accessible.</p>
<p><strong>6. Privacy:</strong> Your personal information is handled in accordance with applicable data protection laws and is never shared with third parties without consent.</p>
</td></tr></table>',
        ],
    ];

    /** GET admin/email-templates */
    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( "SELECT * FROM {$p}s2nri_email_templates ORDER BY is_builtin DESC, name ASC", ARRAY_A );
        // Ensure builtins exist
        $existing_slugs = array_column( $rows ?: [], 'slug' );
        foreach ( self::BUILTINS as $slug ) {
            if ( ! in_array( $slug, $existing_slugs, true ) ) {
                $this->installBuiltin( $slug );
            }
        }
        if ( array_diff( self::BUILTINS, $existing_slugs ) ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$p}s2nri_email_templates ORDER BY is_builtin DESC, name ASC", ARRAY_A );
        }
        Response::json( [ 'templates' => $rows ?: [] ] );
    }

    private function installBuiltin( string $slug ): void {
        global $wpdb;
        $t   = $wpdb->prefix . 's2nri_email_templates';
        $def = self::DEFAULT_TEMPLATES[ $slug ] ?? null;
        if ( ! $def ) return;
        if ( $wpdb->insert( $t, [
            'slug'           => $slug,
            'name'           => $def['name'],
            'subject'        => $def['subject'],
            'body'           => $def['body'],
            'is_builtin'     => 1,
            'default_body'   => $def['body'],
            'default_subject'=> $def['subject'],
        ] ) === false ) {
            error_log( '[S2NRI] Failed to install built-in email template "' . $slug . '": ' . $wpdb->last_error );
        }
    }

    /** POST admin/email-templates — create custom template */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $name    = sanitize_text_field( $req->input( 'name', '' ) );
        $subject = sanitize_text_field( $req->input( 'subject', '' ) );
        $body    = wp_kses_post( $req->input( 'body', '' ) );
        if ( ! $name || ! $subject ) {
            Response::json( [ 'error' => 'Name and subject are required.' ], 422 ); return;
        }
        $slug = 'custom_' . sanitize_key( $name ) . '_' . time();
        $et_insert = $wpdb->insert( $wpdb->prefix . 's2nri_email_templates', compact( 'slug', 'name', 'subject', 'body' ) + [ 'is_builtin' => 0, 'default_body' => '', 'default_subject' => '' ] );
        // CHECKED (was previously unchecked): insert_id does not reset to 0
        // on failure — see the same fix pattern applied elsewhere in this
        // codebase for the full explanation.
        if ( $et_insert === false ) { Response::json( [ 'error' => 'Failed to save template.' ], 500 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** PUT admin/email-templates/{id} — update */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id      = (int) $req->param( 'id' );
        $subject = sanitize_text_field( $req->input( 'subject', '' ) );
        $body    = wp_kses_post( $req->input( 'body', '' ) );
        $name    = sanitize_text_field( $req->input( 'name', '' ) );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_email_templates', array_filter( compact( 'subject', 'body', 'name' ), fn($v) => $v !== '' ), [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to save template.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /** DELETE admin/email-templates/{id} — delete custom only */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT is_builtin FROM {$wpdb->prefix}s2nri_email_templates WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Template not found.' ], 404 ); return; }
        if ( (int) $row['is_builtin'] === 1 ) { Response::json( [ 'error' => 'Built-in templates cannot be deleted.' ], 403 ); return; }
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_email_templates', [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete template.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /** POST admin/email-templates/{id}/reset — reset to default */
    public function reset( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}s2nri_email_templates WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Template not found.' ], 404 ); return; }
        if ( ! $row['default_body'] ) { Response::json( [ 'error' => 'No default to reset to.' ], 422 ); return; }
        if ( $wpdb->update( $wpdb->prefix . 's2nri_email_templates', [ 'body' => $row['default_body'], 'subject' => $row['default_subject'] ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to reset template.' ], 500 ); return;
        }
        Response::json( [ 'success' => true, 'body' => $row['default_body'], 'subject' => $row['default_subject'] ] );
    }

    /** POST admin/email-templates/{id}/duplicate */
    public function duplicate( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}s2nri_email_templates WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Template not found.' ], 404 ); return; }
        $dup_insert = $wpdb->insert( $wpdb->prefix . 's2nri_email_templates', [
            'slug'            => 'custom_copy_' . $row['slug'] . '_' . time(),
            'name'            => 'Copy of ' . $row['name'],
            'subject'         => $row['subject'],
            'body'            => $row['body'],
            'is_builtin'      => 0,
            'default_body'    => '',
            'default_subject' => '',
        ] );
        if ( $dup_insert === false ) { Response::json( [ 'error' => 'Failed to duplicate template.' ], 500 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** POST admin/email-templates/{id}/test-email */
    public function testEmail( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id      = (int) $req->param( 'id' );
        $to      = sanitize_email( $req->input( 'email', '' ) );
        if ( ! is_email( $to ) ) { Response::json( [ 'error' => 'Valid email address required.' ], 422 ); return; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}s2nri_email_templates WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Template not found.' ], 404 ); return; }
        $vars = [
            '{CUSTOMER_NAME}'    => 'Test User',
            '{BOOKING_REF}'      => 'NRI-2026-0001',
            '{SERVICE_NAME}'     => 'OCI Card Renewal',
            '{STATUS}'           => 'Under Review',
            '{SECONDARY_STATUS}' => '',
            '{SERVICE_CHARGES}'  => '₹5,000',
            '{SHIPPING_CHARGES}' => '₹500',
            '{TOTAL_AMOUNT}'     => '₹5,500',
            '{SHIPPING_TYPE}'    => 'Domestic',
            '{TIMELINE}'         => '30 working days',
            '{DELIVERY_DATE}'    => date( 'd M Y', strtotime( '+30 days' ) ),
            '{QUOTE_VALID_UNTIL}'=> date( 'd M Y', strtotime( '+7 days' ) ),
            '{DOCUMENT_TYPE}'    => 'Passport Copy, Address Proof',
            '{SUBMITTED_DATE}'   => date( 'd M Y' ),
            '{PLATFORM_NAME}'    => \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' ),
            '{DASHBOARD_URL}'    => home_url( '/dashboard' ),
            '{TC_BLOCK}'         => '',
            '{TIMELINE_BLOCK}'   => '',
        ];
        $subject = str_replace( array_keys( $vars ), array_values( $vars ), $row['subject'] );
        $body    = str_replace( array_keys( $vars ), array_values( $vars ), $row['body'] );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $sent = wp_mail( $to, '[TEST] ' . $subject, $body, $headers );
        Response::json( [ 'success' => $sent, 'message' => $sent ? "Test email sent to {$to}" : 'Failed to send. Check SMTP settings.' ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// HolidayAdminController
// M-10: Holiday management with year navigation and 2026 Indian holidays pre-loaded
// ══════════════════════════════════════════════════════════════════════════════

class HolidayAdminController extends BaseController {

    /** GET admin/holidays?year=2026 */
    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $year = (int) $req->query( 'year', (string) (int) date('Y') );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_holidays WHERE YEAR(holiday_date)=%d ORDER BY holiday_date ASC", $year
        ), ARRAY_A );
        Response::json( [ 'holidays' => $rows ?: [], 'year' => $year ] );
    }

    /** POST admin/holidays */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $date = sanitize_text_field( $req->input( 'holiday_date', '' ) );
        $name = sanitize_text_field( $req->input( 'holiday_name', '' ) );
        $type = sanitize_key( $req->input( 'holiday_type', 'national' ) );
        if ( ! $date || ! $name ) { Response::json( [ 'error' => 'Date and name required.' ], 422 ); return; }
        if ( ! in_array( $type, [ 'national', 'state', 'university' ], true ) ) $type = 'national';
        $result = $wpdb->insert( $wpdb->prefix . 's2nri_holidays', [
            'holiday_date' => $date,
            'holiday_name' => $name,
            'holiday_type' => $type,
        ] );
        if ( $result === false ) { Response::json( [ 'error' => 'Could not add holiday. Date may already exist.' ], 422 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** DELETE admin/holidays/{id} */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_holidays', [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete holiday.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /**
     * POST admin/holidays/calculate
     * Body: { working_days: 30, from_date: '2026-01-15' }
     * Returns: { delivery_date, calendar_days, weekends_excluded, holidays_excluded }
     */
    public function calculate( Request $req ): void {
        $this->requireManager();
        $working_days = max( 1, (int) $req->input( 'working_days', '30' ) );
        $from_raw     = sanitize_text_field( $req->input( 'from_date', current_time( 'Y-m-d' ) ) );
        $from_date    = \DateTime::createFromFormat( 'Y-m-d', $from_raw );
        if ( ! $from_date ) {
            Response::json( [ 'error' => 'Invalid from_date format. Use YYYY-MM-DD.' ], 422 ); return;
        }
        $result = \S2NRI\Services\DeliveryCalculator::calculate( $from_date, $working_days );
        Response::json( $result );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// QuickReplyAdminController
// M-07: Quick replies for the communication panel
// ══════════════════════════════════════════════════════════════════════════════

class QuickReplyAdminController extends BaseController {

    /** GET admin/quick-replies */
    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}s2nri_quick_replies ORDER BY category ASC, sort_order ASC, id ASC",
            ARRAY_A
        );
        Response::json( [ 'replies' => $rows ?: [] ] );
    }

    /** POST admin/quick-replies */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $title    = sanitize_text_field( $req->input( 'title', '' ) );
        $category = sanitize_key( $req->input( 'category', 'general' ) );
        $content  = sanitize_textarea_field( $req->input( 'content', '' ) );
        if ( ! $title || ! $content ) { Response::json( [ 'error' => 'Title and content required.' ], 422 ); return; }
        if ( ! in_array( $category, [ 'documents', 'status', 'general', 'followup' ], true ) ) $category = 'general';
        $qr_insert = $wpdb->insert( $wpdb->prefix . 's2nri_quick_replies', compact( 'title', 'category', 'content' ) + [ 'sort_order' => 0 ] );
        if ( $qr_insert === false ) { Response::json( [ 'error' => 'Failed to save quick reply.' ], 500 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** PUT admin/quick-replies/{id} */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id       = (int) $req->param( 'id' );
        $title    = sanitize_text_field( $req->input( 'title', '' ) );
        $category = sanitize_key( $req->input( 'category', 'general' ) );
        $content  = sanitize_textarea_field( $req->input( 'content', '' ) );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_quick_replies', compact( 'title', 'category', 'content' ), [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update quick reply.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /** DELETE admin/quick-replies/{id} */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_quick_replies', [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete quick reply.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// VendorAdminController
// M-13: Vendor profiles separate from WP users
// ══════════════════════════════════════════════════════════════════════════════

class VendorAdminController extends BaseController {

    /** GET admin/vendors */
    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $search = sanitize_text_field( $req->query( 'search', '' ) );
        $where  = 'WHERE 1=1';
        $args   = [];
        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $args  = [ $like, $like, $like, $like ];
        }
        $sql  = "SELECT * FROM {$wpdb->prefix}s2nri_vendors {$where} ORDER BY name ASC LIMIT 100";
        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
        Response::json( [ 'vendors' => $rows ?: [] ] );
    }

    /** POST admin/vendors */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $name    = sanitize_text_field( $req->input( 'name', '' ) );
        $email   = sanitize_email( $req->input( 'email', '' ) );
        $phone   = sanitize_text_field( $req->input( 'phone', '' ) );
        $wa      = sanitize_text_field( $req->input( 'whatsapp', '' ) );
        $company = sanitize_text_field( $req->input( 'company', '' ) );
        $types   = sanitize_textarea_field( $req->input( 'work_types', '' ) );
        $notes   = sanitize_textarea_field( $req->input( 'notes', '' ) );
        if ( ! $name ) { Response::json( [ 'error' => 'Vendor name is required.' ], 422 ); return; }
        $v_insert = $wpdb->insert( $wpdb->prefix . 's2nri_vendors', compact( 'name', 'email', 'phone', 'company', 'notes' ) + [
            'whatsapp'   => $wa,
            'work_types' => $types,
            'status'     => 'active',
        ] );
        if ( $v_insert === false ) { Response::json( [ 'error' => 'Failed to save vendor.' ], 500 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** PUT admin/vendors/{id} */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_vendors', [
            'name'       => sanitize_text_field( $req->input( 'name', '' ) ),
            'email'      => sanitize_email( $req->input( 'email', '' ) ),
            'phone'      => sanitize_text_field( $req->input( 'phone', '' ) ),
            'whatsapp'   => sanitize_text_field( $req->input( 'whatsapp', '' ) ),
            'company'    => sanitize_text_field( $req->input( 'company', '' ) ),
            'work_types' => sanitize_textarea_field( $req->input( 'work_types', '' ) ),
            'notes'      => sanitize_textarea_field( $req->input( 'notes', '' ) ),
            'status'     => sanitize_key( $req->input( 'status', 'active' ) ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update vendor.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /** DELETE admin/vendors/{id} */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_vendors', [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete vendor.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RequestTypeAdminController
// M-15: Request types for support ticket category dropdown
// ══════════════════════════════════════════════════════════════════════════════

class RequestTypeAdminController extends BaseController {

    /** GET admin/request-types  (also public: GET request-types) */
    public function index( Request $req ): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}s2nri_request_types WHERE is_active=1 ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );
        Response::json( [ 'types' => $rows ?: [] ] );
    }

    /** GET admin/request-types/all — admin sees inactive too */
    public function adminIndex( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}s2nri_request_types ORDER BY sort_order ASC, id ASC", ARRAY_A );
        Response::json( [ 'types' => $rows ?: [] ] );
    }

    /** POST admin/request-types */
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $name = sanitize_text_field( $req->input( 'name', '' ) );
        $desc = sanitize_textarea_field( $req->input( 'description', '' ) );
        if ( ! $name ) { Response::json( [ 'error' => 'Name is required.' ], 422 ); return; }
        $rt_insert = $wpdb->insert( $wpdb->prefix . 's2nri_request_types', [ 'name' => $name, 'description' => $desc, 'is_active' => 1, 'sort_order' => 0 ] );
        if ( $rt_insert === false ) { Response::json( [ 'error' => 'Failed to save request type.' ], 500 ); return; }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    /** PUT admin/request-types/{id} */
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id   = (int) $req->param( 'id' );
        $name = sanitize_text_field( $req->input( 'name', '' ) );
        $desc = sanitize_textarea_field( $req->input( 'description', '' ) );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_request_types', [ 'name' => $name, 'description' => $desc ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update request type.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    /** PATCH admin/request-types/{id}/toggle */
    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$wpdb->prefix}s2nri_request_types WHERE id=%d", $id ) );
        if ( $row === null ) { Response::json( [ 'error' => 'Request type not found.' ], 404 ); return; }
        if ( $wpdb->update( $wpdb->prefix . 's2nri_request_types', [ 'is_active' => $row ? 0 : 1 ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to toggle request type.' ], 500 ); return;
        }
        Response::json( [ 'success' => true, 'is_active' => ! $row ] );
    }

    /** DELETE admin/request-types/{id} */
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_request_types', [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete request type.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CommunicationAdminController
// M-06: Email compose + history log on booking detail
// M-22: Send Quotation Email button  M-23: Quick email action buttons
// ══════════════════════════════════════════════════════════════════════════════

class CommunicationAdminController extends BaseController {

    /** GET admin/bookings/{id}/communications — email history log */
    public function history( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT cl.*, COALESCE(u.display_name, u.user_login, 'System') AS sender_name
             FROM {$p}s2nri_communication_log cl
             LEFT JOIN {$p}users u ON u.ID = cl.sent_by
             WHERE cl.booking_id = %d
             ORDER BY cl.created_at DESC LIMIT 50",
            $booking_id
        ), ARRAY_A );
        Response::json( [ 'history' => $rows ?: [] ] );
    }

    /**
     * POST admin/bookings/{id}/communications
     * Body: { to_email, subject, body, log_only? }
     * Sends email + logs to s2nri_communication_log
     */
    public function send( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );
        $to         = sanitize_email( $req->input( 'to_email', '' ) );
        $subject    = sanitize_text_field( $req->input( 'subject', '' ) );
        $body       = wp_kses_post( $req->input( 'body', '' ) );
        $log_only   = (bool) $req->input( 'log_only', false );

        if ( ! $to || ! $subject || ! $body ) {
            Response::json( [ 'error' => 'To, subject, and body are required.' ], 422 ); return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, s.name AS service_name FROM {$p}s2nri_bookings b LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id WHERE b.id=%d",
            $booking_id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        $sent = false;
        if ( ! $log_only ) {
            // Wrap plain body in branded HTML if not already HTML
            $html_body = ( stripos( $body, '<!DOCTYPE' ) === false && stripos( $body, '<html' ) === false )
                ? $this->wrapInEmail( $body, $subject, $booking['booking_ref'] )
                : $body;
            // FIXED: closure-identity remove_filter bug, same class fixed elsewhere this session.
            $html_filter_c1 = fn() => 'text/html';
            add_filter( 'wp_mail_content_type', $html_filter_c1 );
            $sent = wp_mail( $to, $subject, $html_body, [ 'Content-Type: text/html; charset=UTF-8' ] );
            remove_filter( 'wp_mail_content_type', $html_filter_c1 );
        }

        // Always log
        if ( $wpdb->insert( $p . 's2nri_communication_log', [
            'booking_id' => $booking_id,
            'sent_by'    => get_current_user_id(),
            'to_email'   => $to,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => $log_only ? 'logged' : ( $sent ? 'sent' : 'failed' ),
        ] ) === false ) {
            error_log( '[S2NRI] communication_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
        }

        $this->logAudit( $booking_id, 'email_sent', $subject );
        Response::json( [ 'success' => true, 'sent' => $sent || $log_only ] );
    }

    /**
     * POST admin/bookings/{id}/send-quotation-email
     * Sends the quote_sent template with line-item breakdown
     * Body: { include_tc: bool }
     */
    public function sendQuotationEmail( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );
        $include_tc = (bool) $req->input( 'include_tc', false );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, s.name AS service_name, c.wp_user_id
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_customers c ON c.id=b.customer_id
             WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        $quote = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_quotes WHERE booking_id=%d AND status='pending' ORDER BY id DESC LIMIT 1",
            $booking_id
        ), ARRAY_A );

        $wp_user    = get_user_by( 'id', $booking['wp_user_id'] );
        $to         = $wp_user ? $wp_user->user_email : '';
        if ( ! $to ) { Response::json( [ 'error' => 'Customer email not found.' ], 422 ); return; }

        $service_charges  = $booking['quoted_amount'] ? (float) $booking['quoted_amount'] : 0;
        $shipping_charges = 0;
        $shipping_type    = $booking['shipping_type'] ?? 'Domestic';
        if ( $quote && ! empty( $quote['line_items'] ) ) {
            $items = json_decode( $quote['line_items'], true ) ?: [];
            foreach ( $items as $item ) {
                if ( isset( $item['type'] ) && $item['type'] === 'shipping' ) {
                    $shipping_charges = (float) ( $item['amount'] ?? 0 );
                }
            }
        }
        $total = $service_charges + $shipping_charges;

        $tc_html = '';
        if ( $include_tc ) {
            $tc_row = $wpdb->get_row( "SELECT body FROM {$wpdb->prefix}s2nri_email_templates WHERE slug='terms_conditions' LIMIT 1", ARRAY_A );
            if ( $tc_row ) {
                $tc_html = '<hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0"><h3 style="color:#1E2D40;font-size:15px">Terms & Conditions</h3>' . $tc_row['body'];
            }
        }

        $delivery_block = '';
        if ( $booking['due_date'] ) {
            $days   = $booking['delivery_working_days'] ?? '';
            $note   = $days ? " ({$days} working days — excl. Sat, Sun &amp; Govt Holidays)" : '';
            $delivery_block = '<table width="100%" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 18px">
              <span style="font-size:13px;font-weight:600;color:#1d4ed8">⏱ Estimated Timeline: ' . esc_html( date('d M Y', strtotime($booking['due_date'])) . $note ) . '</span>
            </td></tr></table>';
        }

        $vars = [
            '{CUSTOMER_NAME}'    => get_user_by( 'id', $booking['wp_user_id'] )->display_name ?? 'Valued Customer',
            '{BOOKING_REF}'      => $booking['booking_ref'],
            '{SERVICE_NAME}'     => $booking['service_name'],
            '{SERVICE_CHARGES}'  => '₹' . number_format( $service_charges, 2 ),
            '{SHIPPING_CHARGES}' => '₹' . number_format( $shipping_charges, 2 ),
            '{SHIPPING_TYPE}'    => $shipping_type,
            '{TOTAL_AMOUNT}'     => '₹' . number_format( $total, 2 ),
            '{QUOTE_VALID_UNTIL}'=> $quote ? date( 'd M Y', strtotime( $quote['valid_until'] ) ) : 'N/A',
            '{PLATFORM_NAME}'    => \S2NRI\Models\Setting::get( 'platform_name', 'Services2NRI' ),
            '{DASHBOARD_URL}'    => home_url( '/dashboard/bookings/' . $booking_id ),
            '{TC_BLOCK}'         => $tc_html,
            '{TIMELINE_BLOCK}'   => $delivery_block,
        ];

        $tmpl    = $wpdb->get_row( "SELECT subject, body FROM {$wpdb->prefix}s2nri_email_templates WHERE slug='quote_sent' LIMIT 1", ARRAY_A );
        $subject = $tmpl ? str_replace( array_keys($vars), array_values($vars), $tmpl['subject'] ) : 'Quotation Ready — ' . $booking['booking_ref'];
        $body    = $tmpl ? str_replace( array_keys($vars), array_values($vars), $tmpl['body'] ) : '';

        if ( ! $body ) { $body = $this->wrapInEmail( 'Quotation amount: ₹' . number_format($total,2), $subject, $booking['booking_ref'] ); }

        // FIXED: closure-identity remove_filter bug.
        $html_filter_c2 = fn() => 'text/html';
        add_filter( 'wp_mail_content_type', $html_filter_c2 );
        $sent = wp_mail( $to, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
        remove_filter( 'wp_mail_content_type', $html_filter_c2 );

        if ( $wpdb->insert( $p . 's2nri_communication_log', [
            'booking_id' => $booking_id,
            'sent_by'    => get_current_user_id(),
            'to_email'   => $to,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => $sent ? 'sent' : 'failed',
        ] ) === false ) {
            error_log( '[S2NRI] communication_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
        }
        $this->logAudit( $booking_id, 'quotation_email_sent', $to );
        Response::json( [ 'success' => $sent, 'message' => $sent ? 'Quotation email sent.' : 'Failed to send. Check SMTP settings.' ] );
    }

    /**
     * POST admin/bookings/{id}/quick-email
     * Body: { type: 'document_reminder' | 'status_update' }
     */
    public function quickEmail( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $booking_id = (int) $req->param( 'id' );
        $type       = sanitize_key( $req->input( 'type', '' ) );
        if ( ! in_array( $type, ['document_reminder', 'status_update', 'dispatch', 'completion'], true ) ) {
            Response::json( [ 'error' => 'Invalid email type.' ], 422 ); return;
        }
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, s.name AS service_name, c.wp_user_id
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id=b.service_id
             LEFT JOIN {$p}s2nri_customers c ON c.id=b.customer_id
             WHERE b.id=%d LIMIT 1",
            $booking_id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        $wp_user = get_user_by( 'id', $booking['wp_user_id'] );
        $to      = $wp_user ? $wp_user->user_email : '';
        if ( ! $to ) { Response::json( [ 'error' => 'Customer email not found.' ], 422 ); return; }

        $delivery_block = '';
        if ( $booking['due_date'] ) {
            $days   = $booking['delivery_working_days'] ?? '';
            $note   = $days ? " ({$days} working days — excl. Sat, Sun &amp; Govt Holidays)" : '';
            $delivery_block = '<p style="font-size:13px;color:#1d4ed8;background:#eff6ff;border-radius:6px;padding:10px 14px;margin:0 0 16px">⏱ Estimated Delivery: ' . esc_html( date('d M Y', strtotime($booking['due_date'])) . $note ) . '</p>';
        }
        $sec_status_html = $booking['secondary_status']
            ? '<p style="font-size:13px;color:#6b7280;font-style:italic;margin:6px 0 0">' . esc_html($booking['secondary_status']) . '</p>'
            : '';

        $slug = match($type) {
            'document_reminder' => 'document_reminder',
            'status_update'     => 'status_update',
            'dispatch'          => 'dispatch',
            'completion'        => 'completion',
            default             => 'status_update',
        };
        $vars = [
            '{CUSTOMER_NAME}'    => $wp_user->display_name ?? 'Valued Customer',
            '{BOOKING_REF}'      => $booking['booking_ref'],
            '{SERVICE_NAME}'     => $booking['service_name'],
            '{STATUS}'           => ucwords( str_replace('_', ' ', $booking['status']) ),
            '{SECONDARY_STATUS}' => $sec_status_html,
            '{DOCUMENT_TYPE}'    => 'As previously communicated',
            '{TIMELINE}'         => $booking['delivery_working_days'] ? $booking['delivery_working_days'] . ' working days' : '',
            '{DELIVERY_DATE}'    => $booking['due_date'] ? date('d M Y', strtotime($booking['due_date'])) : '',
            '{PLATFORM_NAME}'    => \S2NRI\Models\Setting::get('platform_name', 'Services2NRI'),
            '{DASHBOARD_URL}'    => home_url('/dashboard/bookings/' . $booking_id),
            '{TIMELINE_BLOCK}'   => $delivery_block,
            '{TC_BLOCK}'         => '',
        ];

        $tmpl    = $wpdb->get_row( $wpdb->prepare( "SELECT subject, body FROM {$wpdb->prefix}s2nri_email_templates WHERE slug=%s LIMIT 1", $slug ), ARRAY_A );
        $subject = $tmpl ? str_replace( array_keys($vars), array_values($vars), $tmpl['subject'] ) : 'Update on ' . $booking['booking_ref'];
        $body    = $tmpl ? str_replace( array_keys($vars), array_values($vars), $tmpl['body'] ) : $this->wrapInEmail( "Status: {$vars['{STATUS}']}", $subject, $booking['booking_ref'] );

        // FIXED: closure-identity remove_filter bug.
        $html_filter_c3 = fn() => 'text/html';
        add_filter( 'wp_mail_content_type', $html_filter_c3 );
        $sent = wp_mail( $to, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
        remove_filter( 'wp_mail_content_type', $html_filter_c3 );

        if ( $wpdb->insert( $p . 's2nri_communication_log', [
            'booking_id' => $booking_id, 'sent_by' => get_current_user_id(),
            'to_email' => $to, 'subject' => $subject, 'body' => $body,
            'status' => $sent ? 'sent' : 'failed',
        ] ) === false ) {
            error_log( '[S2NRI] communication_log insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
        }
        $this->logAudit( $booking_id, 'quick_email_sent', $type );
        Response::json( [ 'success' => $sent, 'message' => $sent ? 'Email sent.' : 'Failed to send.' ] );
    }

    private function wrapInEmail( string $content, string $subject, string $ref ): string {
        $name  = esc_html( \S2NRI\Models\Setting::get('platform_name', 'Services2NRI') );
        $color = esc_attr( \S2NRI\Models\Setting::get('primary_color', '#4A6FA5') );
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:20px;background:#f4f6f9;font-family:Arial,sans-serif'>
<table width='600' style='background:#fff;border-radius:10px;overflow:hidden;margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
<tr><td style='background:{$color};padding:24px 32px;text-align:center'><h1 style='color:#fff;margin:0;font-size:20px'>{$name}</h1></td></tr>
<tr><td style='padding:28px 32px'><p style='font-size:13px;color:#888;margin:0 0 4px'>Re: {$ref}</p>" . wpautop( esc_html($content) ) . "</td></tr>
<tr><td style='background:#f8fafc;padding:14px 32px;text-align:center;border-top:1px solid #e2e8f0'><p style='color:#aaa;font-size:11px;margin:0'>{$name}</p></td></tr>
</table></body></html>";
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// BookingPhase1AdminController
// M-02 Secondary Status, M-03 Manager Contact, M-04 Delivery Date,
// M-09 Delivery Calculator set, M-21 Currently Assigned label
// ══════════════════════════════════════════════════════════════════════════════

class BookingPhase1AdminController extends BaseController {

    /** PATCH admin/bookings/{id}/secondary-status */
    public function setSecondaryStatus( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        // Portal JS sends {value: '...'}, legacy field name was 'secondary_status'
        $status = sanitize_text_field(
            $req->input( 'value', null ) ?? $req->input( 'secondary_status', '' )
        );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_bookings', [ 'secondary_status' => $status ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update status.' ], 500 ); return;
        }
        $this->logAudit( $id, 'secondary_status_set', $status );
        Response::json( [ 'success' => true ] );
    }

    /**
     * PATCH admin/bookings/{id}/delivery
     * Body: { working_days: 30 } or { fixed_date: '2026-03-15' }
     * If working_days provided: calculate calendar date via DeliveryCalculator
     */
    public function setDelivery( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id   = (int) $req->param( 'id' );

        // Frontend sends {days: N} or {working_days: N} — support both field names
        $days_raw = $req->input( 'days', null ) ?? $req->input( 'working_days', '0' );
        $days     = (int) $days_raw;

        // Frontend sends {date: 'YYYY-MM-DD'} or {fixed_date: 'YYYY-MM-DD'} — support both
        $fixed = sanitize_text_field( $req->input( 'date', '' ) ?: $req->input( 'fixed_date', '' ) );
        $note  = sanitize_text_field( $req->input( 'note', '' ) );

        $due_date = null;
        $wd_stored = null;

        if ( $days > 0 ) {
            $result   = \S2NRI\Services\DeliveryCalculator::calculate( new \DateTime(), $days );
            $due_date = $result['delivery_date'];
            $wd_stored = $days;
        } elseif ( $fixed ) {
            $due_date  = $fixed;
            $wd_stored = null;
        } else {
            Response::json( ['error' => 'Provide working_days or fixed_date.'], 422 ); return;
        }

        $update = [ 'due_date' => $due_date ];
        if ( $wd_stored ) $update['delivery_working_days'] = $wd_stored;
        if ( $note )      $update['delivery_note']         = $note;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_bookings', $update, [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to set delivery date.' ], 500 ); return;
        }
        $this->logAudit( $id, 'delivery_date_set', $due_date . ( $days ? " ({$days}wd)" : '' ) );
        Response::json( [ 'success' => true, 'due_date' => $due_date, 'working_days' => $wd_stored ] );
    }

    /** PATCH admin/bookings/{id}/vendor — assign vendor (M-13) */
    public function assignVendor( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id        = (int) $req->param( 'id' );
        $vendor_id = $req->input( 'vendor_id' ) !== null ? (int) $req->input( 'vendor_id' ) : null;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_bookings', [ 'vendor_id' => $vendor_id ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to assign vendor.' ], 500 ); return;
        }
        $this->logAudit( $id, 'vendor_assigned', (string) $vendor_id );
        // Return vendor details
        $vendor = $vendor_id ? $wpdb->get_row( $wpdb->prepare( "SELECT id,name,phone,whatsapp FROM {$wpdb->prefix}s2nri_vendors WHERE id=%d", $vendor_id ), ARRAY_A ) : null;
        Response::json( [ 'success' => true, 'vendor' => $vendor ] );
    }

    /** PATCH admin/bookings/{id}/shipping-type */
    public function setShippingType( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id   = (int) $req->param( 'id' );
        $type = sanitize_key( $req->input( 'shipping_type', 'domestic' ) );
        if ( ! in_array( $type, ['domestic','international','none'], true ) ) $type = 'domestic';
        if ( $wpdb->update( $wpdb->prefix . 's2nri_bookings', [ 'shipping_type' => $type ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update shipping type.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

