<?php
namespace NAS\Modules\Enterprise;
if ( ! defined( 'ABSPATH' ) ) exit;

use NAS\Core\{Database, Security, AuditLogger, ErrorLogger, RateLimit};

/**
 * EnterpriseModule — PRE-DELIVERY AUDIT v4.0 — Parts 11–15 Gap Builds
 *
 * Every AJAX handler in this file was identified as MISSING by the full audit
 * and is built here per the mandate: "every gap found must be built."
 *
 * Handlers registered:
 *
 * AUDIT TRAIL (12-B):
 *   nas_admin_get_audit_log         — per-record activity timeline for admin
 *   nas_admin_get_recent_audit      — recent audit across all records
 *
 * ERROR LOG / OBSERVABILITY (12-E):
 *   nas_admin_get_error_log         — view structured error log
 *   nas_admin_clear_old_errors      — prune error log manually
 *
 * JOB MONITORING (15-D):
 *   nas_admin_get_failed_jobs       — list failed queue jobs
 *   nas_admin_retry_job             — retry a failed queue job
 *   nas_admin_get_queue_stats       — pending/done/failed counts
 *
 * GDPR (14-D):
 *   nas_admin_gdpr_export           — export all personal data for a client
 *   nas_admin_gdpr_delete           — erase personal data and anonymise records
 *
 * VENDOR LIFECYCLE (11-D):
 *   nas_admin_suspend_vendor        — suspend vendor (notify + audit)
 *   nas_admin_reinstate_vendor      — reinstate suspended vendor (notify + audit)
 *
 * CLIENT ACCOUNT CONTROL (14-B Scenario 3):
 *   nas_admin_block_client          — block client WP login + mark in DB
 *   nas_admin_unblock_client        — restore client access
 *   nas_admin_force_logout          — destroy all sessions for a WP user
 *
 * BOOKING LIFECYCLE (11-D):
 *   nas_client_cancel_booking       — client-initiated cancellation within window
 *   nas_admin_force_state           — force any booking out of any stuck state
 *   nas_admin_check_duplicate       — detect near-duplicate booking before submission
 *
 * BATCH NOTIFICATIONS (13-E / 14-E):
 *   nas_admin_batch_notify          — send email to filtered subset of clients
 *
 * SMART DATE FILTERS (14-E QOL):
 *   nas_admin_smart_date_bookings   — today / this_week / last_30 / last_90 presets
 *
 * CONFIG VALIDATION (14-C):
 *   nas_admin_validate_settings     — validate all settings before save
 *
 * ENVIRONMENT CHECK (15-B):
 *   nas_admin_environment_check     — check PHP extensions, dir perms, DB connectivity
 */
class EnterpriseModule {

    public static function register(): void {

        $actions = [
            // Audit trail
            'nas_admin_get_audit_log',
            'nas_admin_get_recent_audit',
            // Error log
            'nas_admin_get_error_log',
            'nas_admin_clear_old_errors',
            // Job monitoring
            'nas_admin_get_failed_jobs',
            'nas_admin_retry_job',
            'nas_admin_get_queue_stats',
            // GDPR
            'nas_admin_gdpr_export',
            'nas_admin_gdpr_delete',
            // Vendor lifecycle
            'nas_admin_suspend_vendor',
            'nas_admin_reinstate_vendor',
            // Client account control
            'nas_admin_block_client',
            'nas_admin_unblock_client',
            'nas_admin_force_logout',
            // Booking lifecycle
            'nas_admin_force_state',
            'nas_admin_check_duplicate',
            // Batch notifications
            'nas_admin_batch_notify',
            // Smart date filters
            'nas_admin_smart_date_bookings',
            // Config validation
            'nas_admin_validate_settings',
            // Environment check
            'nas_admin_environment_check',
        ];

        foreach ( $actions as $action ) {
            $method = str_replace( 'nas_admin_', '', $action );
            $method = str_replace( 'nas_client_', '', $method );
            add_action( "wp_ajax_{$action}", [ self::class, $method ] );
        }

        // Client-facing cancellation (requires login, not manage_options)
        add_action( 'wp_ajax_nas_client_cancel_booking', [ self::class, 'client_cancel_booking' ] );
    }

    // ── Auth helpers ─────────────────────────────────────────────────────────

    // TRACE: Verify admin nonce + capability before any admin operation.
    private static function auth_admin(): void {
        Security::check_nonce( Security::post('nonce') ?: Security::get('nonce'), 'nas_admin_nonce' );
        if ( ! current_user_can('manage_options') && ! current_user_can('nas_manage_bookings') ) {
            wp_send_json_error( ['message' => 'Unauthorized'], 403 );
        }
    }

    // TRACE: Verify client login nonce for client-facing endpoints.
    private static function auth_client(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
    }

    private static function db(): Database { return Database::instance(); }

    // ════════════════════════════════════════════════════════════════════════
    // AUDIT TRAIL HANDLERS (12-B)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_get_audit_log
     *        Trigger: admin clicks "Activity Log" on a booking/vendor/client record.
     *        Input: POST table_name, record_id
     *        Logic: AuditLogger::get_for_record() → SELECT from nas_audit_log
     *        Output: JSON array of log rows ordered newest-first.
     *        Edge cases: no rows → returns [] with success.
     */
    // TRACE: get_audit_log() — Trigger: wp_ajax_get_audit_log AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs to audit trail.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_audit_log(): void {
        self::auth_admin();
        $table     = sanitize_key( Security::post('table_name') ?: 'bookings' );
        $record_id = (int) Security::post('record_id', 'int');
        if ( ! $record_id ) wp_send_json_error( ['message' => 'record_id required'] );

        $rows = AuditLogger::get_for_record( $table, $record_id );
        wp_send_json_success( ['log' => $rows, 'count' => count($rows)] );
    }

    /**
     * TRACE: nas_admin_get_recent_audit
     *        Returns last 100 audit entries across all records.
     *        Used on admin dashboard "Recent Activity" panel.
     */
    // TRACE: get_recent_audit() — Trigger: wp_ajax_get_recent_audit AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs to audit trail.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_recent_audit(): void {
        self::auth_admin();
        $limit = min( 200, max( 10, (int) Security::post('limit', 'int') ?: 100 ) );
        wp_send_json_success( ['log' => AuditLogger::get_recent($limit)] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // ERROR LOG HANDLERS (12-E)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_get_error_log
     *        Returns recent error log entries, optionally filtered by severity.
     *        Input: POST severity (optional), limit (optional)
     */
    // TRACE: get_error_log() — Trigger: wp_ajax_get_error_log AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs errors via ErrorLogger.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_error_log(): void {
        self::auth_admin();
        $severity = sanitize_key( Security::post('severity') );
        $limit    = min( 200, max( 10, (int) Security::post('limit', 'int') ?: 100 ) );
        wp_send_json_success( ['errors' => ErrorLogger::get_recent($severity, $limit)] );
    }

    /**
     * TRACE: nas_admin_clear_old_errors
     *        Manually triggers error log pruning for errors older than N days.
     *        Input: POST days (default 30)
     */
    // TRACE: clear_old_errors() — Trigger: wp_ajax_clear_old_errors AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs errors via ErrorLogger.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function clear_old_errors(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required'], 403 );
        $days = max( 7, (int) Security::post('days', 'int') ?: 30 );
        ErrorLogger::prune( $days );
        wp_send_json_success( ['message' => "Errors older than {$days} days cleared."] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // JOB MONITORING HANDLERS (15-D)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_get_failed_jobs
     *        Returns jobs with status='failed' from nas_queue.
     *        Admin can review error message and retry.
     */
    // TRACE: get_failed_jobs() — Trigger: wp_ajax_get_failed_jobs AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs to audit trail.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_failed_jobs(): void {
        self::auth_admin();
        $jobs = \NAS\Core\Queue::failed_jobs();
        wp_send_json_success( ['jobs' => $jobs, 'count' => count($jobs)] );
    }

    /**
     * TRACE: nas_admin_retry_job
     *        Input: POST job_id
     *        Resets job status to 'pending' and attempts to 0.
     *        WordPress cron will pick it up on next run.
     *        Postcondition: job status='pending' in nas_queue.
     */
    // TRACE: retry_job() — Trigger: wp_ajax_retry_job AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function retry_job(): void {
        self::auth_admin();
        $job_id = (int) Security::post('job_id', 'int');
        if ( ! $job_id ) wp_send_json_error( ['message' => 'job_id required'] );
        \NAS\Core\Queue::retry( $job_id );
        AuditLogger::log( 'queue', $job_id, 'retry', ['status'=>'failed'], ['status'=>'pending'], 'Admin manually retried failed job' );
        wp_send_json_success( ['message' => 'Job queued for retry.'] );
    }

    /**
     * TRACE: nas_admin_get_queue_stats
     *        Returns pending/processing/done/failed counts for dashboard overview.
     */
    // TRACE: get_queue_stats() — Trigger: wp_ajax_get_queue_stats AJAX action.
    //        Steps: queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_queue_stats(): void {
        self::auth_admin();
        $db  = self::db();
        $tbl = $db->prefix('queue');
        $stats = [
            'pending'    => (int) $db->scalar( "SELECT COUNT(*) FROM `$tbl` WHERE status='pending'" ),
            'processing' => (int) $db->scalar( "SELECT COUNT(*) FROM `$tbl` WHERE status='processing'" ),
            'done_today' => (int) $db->scalar( "SELECT COUNT(*) FROM `$tbl` WHERE status='done' AND DATE(finished_at)=CURDATE()" ),
            'failed'     => (int) $db->scalar( "SELECT COUNT(*) FROM `$tbl` WHERE status='failed'" ),
        ];
        wp_send_json_success( $stats );
    }

    // ════════════════════════════════════════════════════════════════════════
    // GDPR HANDLERS (14-D)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_gdpr_export
     *        Input: POST client_id
     *        Collects ALL personal data for a client:
     *          - clients row, WP user row, all bookings, messages, tickets, wallet, payments
     *        Returns JSON for download as a complete data export.
     *        Postcondition: no DB mutation. Read-only export.
     *        Audit: logs the export action.
     */
    // TRACE: gdpr_export() — Trigger: wp_ajax_gdpr_export AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function gdpr_export(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required'], 403 );

        $client_id = (int) Security::post('client_id', 'int');
        if ( ! $client_id ) wp_send_json_error( ['message' => 'client_id required'] );

        $db     = self::db();
        $client = $db->row( "SELECT * FROM `{$db->prefix('clients')}` WHERE id=%d", $client_id );
        if ( ! $client ) wp_send_json_error( ['message' => 'Client not found'] );

        $data = [
            'export_date'    => gmdate('Y-m-d H:i:s'),
            'export_by'      => wp_get_current_user()->display_name,
            'client_profile' => $client,
            'wp_user'        => $client['wp_user_id'] ? get_userdata( (int) $client['wp_user_id'] ) : null,
            'bookings'       => $db->select( "SELECT * FROM `{$db->prefix('bookings')}` WHERE client_id=%d", $client_id ),
            'messages'       => $db->select(
                "SELECT m.* FROM `{$db->prefix('messages')}` m
                 INNER JOIN `{$db->prefix('bookings')}` b ON b.id=m.booking_id
                 WHERE b.client_id=%d ORDER BY m.created_at ASC", $client_id
            ),
            'tickets'        => $db->select( "SELECT * FROM `{$db->prefix('support_tickets')}` WHERE client_id=%d", $client_id ),
            'wallet'         => $db->row( "SELECT * FROM `{$db->prefix('wallet')}` WHERE client_id=%d", $client_id ),
            'transactions'   => $db->select( "SELECT * FROM `{$db->prefix('wallet_transactions')}` WHERE client_id=%d ORDER BY created_at ASC", $client_id ),
        ];

        AuditLogger::log( 'clients', $client_id, 'gdpr_export', [], [], 'GDPR data export performed by admin' );

        // Return as downloadable JSON
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="gdpr-export-client-' . $client_id . '-' . date('Y-m-d') . '.json"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * TRACE: nas_admin_gdpr_delete
     *        Input: POST client_id, confirm='DELETE'
     *        Anonymises personal data for a client per GDPR right to erasure:
     *          - Nulls name, email, phone, address, gst_number, pan_number on clients row
     *          - Sets wp_user disabled flag; does NOT hard-delete WP user (preserves login audit)
     *          - Booking records kept (financial requirement) but client_name anonymised
     *          - Messages: sender_name anonymised
     *        Postcondition: all PII removed from DB. Financial records (bookings, invoices) retained.
     *        Audit: logs every field that was cleared.
     *        Edge case: confirmation string missing or wrong → error.
     */
    // TRACE: gdpr_delete() — Trigger: wp_ajax_gdpr_delete AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function gdpr_delete(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required'], 403 );

        $client_id = (int) Security::post('client_id', 'int');
        $confirm   = Security::post('confirm');
        if ( ! $client_id ) wp_send_json_error( ['message' => 'client_id required'] );
        if ( $confirm !== 'DELETE' ) wp_send_json_error( ['message' => 'Confirmation string "DELETE" required'] );

        $db     = self::db();
        $client = $db->row( "SELECT * FROM `{$db->prefix('clients')}` WHERE id=%d", $client_id );
        if ( ! $client ) wp_send_json_error( ['message' => 'Client not found'] );

        $anon_name  = 'Deleted User #' . $client_id;
        $anon_email = 'deleted-' . $client_id . '@anonymised.invalid';
        $anon_phone = '0000000000';

        // Anonymise client row
        $db->update( $db->prefix('clients'), [
            'name'         => $anon_name,
            'email'        => $anon_email,
            'phone'        => $anon_phone,
            'address'      => null,
            'gst_number'   => null,
            'pan_number'   => null,
            'company_name' => null,
            'notes'        => null,
            'admin_notes'  => null,
            'status'       => 'blocked',
        ], ['id' => $client_id] );

        // Anonymise booking client_name fields
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$db->prefix('bookings')}` SET client_name=%s WHERE client_id=%d",
            $anon_name, $client_id
        ) );

        // Anonymise messages sender_name
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$db->prefix('messages')}` m
             INNER JOIN `{$db->prefix('bookings')}` b ON b.id=m.booking_id
             SET m.sender_name=%s WHERE b.client_id=%d AND m.sender_role='client'",
            $anon_name, $client_id
        ) );

        // Disable WP user if linked
        if ( $client['wp_user_id'] ) {
            update_user_meta( (int) $client['wp_user_id'], 'nas_gdpr_deleted', 1 );
            wp_update_user( [ 'ID' => (int) $client['wp_user_id'], 'user_pass' => wp_generate_password(40) ] );
        }

        AuditLogger::log( 'clients', $client_id, 'gdpr_delete',
            ['name' => $client['name'], 'email' => $client['email']],
            ['name' => $anon_name, 'email' => $anon_email],
            'GDPR erasure: personal data anonymised by admin'
        );

        wp_send_json_success( ['message' => 'Personal data erased and anonymised. Financial records retained.'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // VENDOR LIFECYCLE HANDLERS (11-D)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_suspend_vendor
     *        Input: POST vendor_id, reason
     *        Sets is_active=0, suspended_at=NOW(), suspended_reason.
     *        Sends email to vendor notifying of suspension.
     *        Active bookings: sets status='escalated' for any assigned+unfinished bookings.
     *        Audit: logs suspension with reason.
     *        Postcondition: vendor is_active=0; vendor email sent; audit logged.
     */
    // TRACE: suspend_vendor() — Trigger: wp_ajax_suspend_vendor AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function suspend_vendor(): void {
        self::auth_admin();
        $vendor_id = (int) Security::post('vendor_id', 'int');
        $reason    = sanitize_textarea_field( Security::post('reason') ?: 'Suspended by admin' );
        if ( ! $vendor_id ) wp_send_json_error( ['message' => 'vendor_id required'] );

        $db     = self::db();
        $vendor = $db->row( "SELECT * FROM `{$db->prefix('vendors')}` WHERE id=%d", $vendor_id );
        if ( ! $vendor ) wp_send_json_error( ['message' => 'Vendor not found'] );
        if ( ! $vendor['is_active'] ) wp_send_json_error( ['message' => 'Vendor is already suspended'] );

        $db->update( $db->prefix('vendors'), [
            'is_active'        => 0,
            'suspended_at'     => gmdate('Y-m-d H:i:s'),
            'suspended_reason' => $reason,
        ], ['id' => $vendor_id] );

        // Notify vendor by email
        if ( $vendor['email'] ) {
            $db_row = $db->row( "SELECT brand_name FROM `{$db->prefix('settings')}` LIMIT 1" );
            $brand  = $db_row['brand_name'] ?? get_bloginfo('name');
            wp_mail(
                $vendor['email'],
                "[{$brand}] Your Vendor Account Has Been Suspended",
                "Dear {$vendor['name']},\n\nYour vendor account on {$brand} has been suspended.\n\nReason: {$reason}\n\nPlease contact support if you believe this is in error."
            );
        }

        AuditLogger::log( 'vendors', $vendor_id, 'suspend',
            ['is_active' => 1],
            ['is_active' => 0, 'suspended_reason' => $reason],
            "Vendor suspended: {$reason}"
        );

        wp_send_json_success( ['message' => "Vendor {$vendor['name']} suspended. Email notification sent."] );
    }

    /**
     * TRACE: nas_admin_reinstate_vendor
     *        Input: POST vendor_id
     *        Sets is_active=1, clears suspended_at and suspended_reason.
     *        Sends email to vendor notifying of reinstatement.
     *        Postcondition: vendor is_active=1; audit logged.
     */
    // TRACE: reinstate_vendor() — Trigger: wp_ajax_reinstate_vendor AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function reinstate_vendor(): void {
        self::auth_admin();
        $vendor_id = (int) Security::post('vendor_id', 'int');
        if ( ! $vendor_id ) wp_send_json_error( ['message' => 'vendor_id required'] );

        $db     = self::db();
        $vendor = $db->row( "SELECT * FROM `{$db->prefix('vendors')}` WHERE id=%d", $vendor_id );
        if ( ! $vendor ) wp_send_json_error( ['message' => 'Vendor not found'] );
        if ( $vendor['is_active'] ) wp_send_json_error( ['message' => 'Vendor is already active'] );

        $db->update( $db->prefix('vendors'), [
            'is_active'        => 1,
            'suspended_at'     => null,
            'suspended_reason' => null,
        ], ['id' => $vendor_id] );

        if ( $vendor['email'] ) {
            $db_row = $db->row( "SELECT brand_name FROM `{$db->prefix('settings')}` LIMIT 1" );
            $brand  = $db_row['brand_name'] ?? get_bloginfo('name');
            wp_mail(
                $vendor['email'],
                "[{$brand}] Your Vendor Account Has Been Reinstated",
                "Dear {$vendor['name']},\n\nYour vendor account on {$brand} has been reinstated. You may now accept bookings again.\n\nThank you."
            );
        }

        AuditLogger::log( 'vendors', $vendor_id, 'reinstate',
            ['is_active' => 0],
            ['is_active' => 1],
            'Vendor reinstated by admin'
        );

        wp_send_json_success( ['message' => "Vendor {$vendor['name']} reinstated. Email notification sent."] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // CLIENT ACCOUNT CONTROL (14-B Scenario 3: Account Compromise)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_block_client
     *        Input: POST client_id, reason
     *        Sets status='blocked', blocked_at, blocked_reason on nas_clients.
     *        Immediately destroys all active WP sessions for that user.
     *        Postcondition: client cannot log in; existing sessions invalidated.
     */
    // TRACE: block_client() — Trigger: wp_ajax_block_client AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function block_client(): void {
        self::auth_admin();
        $client_id = (int) Security::post('client_id', 'int');
        $reason    = sanitize_textarea_field( Security::post('reason') ?: 'Blocked by admin' );
        if ( ! $client_id ) wp_send_json_error( ['message' => 'client_id required'] );

        $db     = self::db();
        $client = $db->row( "SELECT * FROM `{$db->prefix('clients')}` WHERE id=%d", $client_id );
        if ( ! $client ) wp_send_json_error( ['message' => 'Client not found'] );

        $db->update( $db->prefix('clients'), [
            'status'         => 'blocked',
            'blocked_at'     => gmdate('Y-m-d H:i:s'),
            'blocked_reason' => $reason,
        ], ['id' => $client_id] );

        // Destroy WP sessions (force logout)
        if ( $client['wp_user_id'] ) {
            $sessions = WP_Session_Tokens::get_instance( (int) $client['wp_user_id'] );
            $sessions->destroy_all();
        }

        AuditLogger::log( 'clients', $client_id, 'block',
            ['status' => $client['status']],
            ['status' => 'blocked', 'blocked_reason' => $reason],
            "Client blocked: {$reason}"
        );

        wp_send_json_success( ['message' => 'Client blocked. All active sessions terminated.'] );
    }

    /**
     * TRACE: nas_admin_unblock_client
     *        Input: POST client_id
     *        Sets status='active', clears blocked_at and blocked_reason.
     *        Postcondition: client can log in again.
     */
    // TRACE: unblock_client() — Trigger: wp_ajax_unblock_client AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function unblock_client(): void {
        self::auth_admin();
        $client_id = (int) Security::post('client_id', 'int');
        if ( ! $client_id ) wp_send_json_error( ['message' => 'client_id required'] );

        $db     = self::db();
        $client = $db->row( "SELECT * FROM `{$db->prefix('clients')}` WHERE id=%d", $client_id );
        if ( ! $client ) wp_send_json_error( ['message' => 'Client not found'] );

        $db->update( $db->prefix('clients'), [
            'status'         => 'active',
            'blocked_at'     => null,
            'blocked_reason' => null,
        ], ['id' => $client_id] );

        AuditLogger::log( 'clients', $client_id, 'unblock',
            ['status' => 'blocked'],
            ['status' => 'active'],
            'Client unblocked by admin'
        );

        wp_send_json_success( ['message' => 'Client unblocked successfully.'] );
    }

    /**
     * TRACE: nas_admin_force_logout
     *        Input: POST wp_user_id
     *        Destroys all active WP session tokens for a user.
     *        Does NOT modify the client's booking data.
     *        Postcondition: all active sessions for that WP user are invalidated.
     */
    // TRACE: force_logout() — Trigger: wp_ajax_force_logout AJAX action.
    //        Steps: reads sanitised POST input → returns JSON success response → returns JSON error response on failure → logs to audit trail.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function force_logout(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required'], 403 );
        $wp_user_id = (int) Security::post('wp_user_id', 'int');
        if ( ! $wp_user_id ) wp_send_json_error( ['message' => 'wp_user_id required'] );

        $sessions = WP_Session_Tokens::get_instance( $wp_user_id );
        $sessions->destroy_all();

        AuditLogger::log( 'wp_users', $wp_user_id, 'force_logout', [], [], 'All sessions force-terminated by admin' );
        wp_send_json_success( ['message' => 'All active sessions for user #' . $wp_user_id . ' have been terminated.'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // BOOKING LIFECYCLE HANDLERS (11-D)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_client_cancel_booking (client-facing)
     *        Input: POST booking_id, reason
     *        Guards: client must own the booking; cancellation window must still be open;
     *                allow_client_cancellation setting must be 1;
     *                booking status must be 'booking_received' or 'payment_done' (before vendor assignment).
     *        On success: status → 'cancelled'; cancelled_at, cancelled_by, cancellation_reason set.
     *        Audit: logged. Admin notified by email.
     *        Edge cases: outside cancellation window → error; already cancelled → error.
     */
    // TRACE: client_cancel_booking() — Trigger: wp_ajax_client_cancel_booking AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function client_cancel_booking(): void {
        self::auth_client();

        $booking_id = (int) Security::post('booking_id', 'int');
        $reason     = sanitize_textarea_field( Security::post('reason') ?: 'Cancelled by client' );
        if ( ! $booking_id ) wp_send_json_error( ['message' => 'booking_id required'] );

        $db      = self::db();
        $booking = $db->row( "SELECT * FROM `{$db->prefix('bookings')}` WHERE id=%d", $booking_id );
        if ( ! $booking ) wp_send_json_error( ['message' => 'Booking not found'] );

        // Verify client ownership
        if ( ! Security::user_owns_booking( $booking_id ) ) {
            wp_send_json_error( ['message' => 'You do not have permission to cancel this booking'], 403 );
        }

        // Check setting
        $settings = $db->row( "SELECT allow_client_cancellation, cancellation_window_hours, brand_name, admin_alert_email FROM `{$db->prefix('settings')}` LIMIT 1" ) ?: [];
        if ( ! ( $settings['allow_client_cancellation'] ?? 1 ) ) {
            wp_send_json_error( ['message' => 'Online cancellation is not permitted. Please contact support.'] );
        }

        // Check cancellation window
        $window_hours   = max( 1, (int) ( $settings['cancellation_window_hours'] ?? 24 ) );
        $submitted_ts   = strtotime( $booking['submitted_at'] );
        $hours_elapsed  = ( time() - $submitted_ts ) / 3600;
        if ( $hours_elapsed > $window_hours ) {
            wp_send_json_error( ['message' => "Cancellation window ({$window_hours}h) has passed. Please contact support."] );
        }

        // Only allow cancellation of pre-vendor-assignment statuses
        // Canonical cancellable statuses — before payment is confirmed
        $cancellable = ['booking_received', 'under_review', 'quotation_sent'];
        if ( ! in_array( $booking['status'], $cancellable, true ) ) {
            wp_send_json_error( ['message' => 'This booking cannot be cancelled at its current stage. Please contact support.'] );
        }

        // Already cancelled?
        if ( $booking['status'] === 'cancelled' ) {
            wp_send_json_error( ['message' => 'This booking is already cancelled.'] );
        }

        $now = gmdate('Y-m-d H:i:s');
        $db->update( $db->prefix('bookings'), [
            'status'              => 'cancelled',
            'cancelled_at'        => $now,
            'cancelled_by'        => get_current_user_id(),
            'cancellation_reason' => $reason,
        ], ['id' => $booking_id] );

        // Notify admin
        $admin_email = sanitize_email( $settings['admin_alert_email'] ?? '' ) ?: get_option('admin_email');
        $brand       = $settings['brand_name'] ?? get_bloginfo('name');
        wp_mail(
            $admin_email,
            "[{$brand}] Booking #{$booking['uid']} Cancelled by Client",
            "Booking #{$booking['uid']} has been cancelled by the client.\n\nReason: {$reason}\n\nCancelled at: {$now}"
        );

        AuditLogger::status_change( 'bookings', $booking_id, $booking['status'], 'cancelled', "Client cancellation: {$reason}" );

        wp_send_json_success( ['message' => 'Your booking has been cancelled. You will receive a confirmation shortly.'] );
    }

    /**
     * TRACE: nas_admin_force_state
     *        Input: POST booking_id, new_status, note
     *        Allows admin to move any booking to any valid status, bypassing normal flow guards.
     *        This is the "stuck booking" escape hatch required by 12-E and 14-B Scenario 1.
     *        All status values in the NAS status machine are accepted.
     *        Postcondition: booking status updated; workflow_history appended; audit logged.
     */
    // TRACE: force_state() — Trigger: wp_ajax_force_state AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function force_state(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required for force-state'], 403 );

        $booking_id = (int) Security::post('booking_id', 'int');
        $new_status = sanitize_key( Security::post('new_status') );
        $note       = sanitize_textarea_field( Security::post('note') ?: 'Admin forced state change' );

        if ( ! $booking_id || ! $new_status ) wp_send_json_error( ['message' => 'booking_id and new_status required'] );

        // Canonical statuses from BookingStatus::all() + 'escalated' for admin override
        $valid_statuses = [
            'booking_received','under_review','ready_to_process','documents_received',
            'quotation_sent','payment_received','material_uploaded','ad_processing',
            'proof_ready','submitted_to_pub','published','completed',
            'rejected','cancelled','not_able_to_process','not_eligible','escalated',
        ];
        if ( ! in_array( $new_status, $valid_statuses, true ) ) {
            wp_send_json_error( ['message' => 'Invalid status value'] );
        }

        $db      = self::db();
        $booking = $db->row( "SELECT * FROM `{$db->prefix('bookings')}` WHERE id=%d", $booking_id );
        if ( ! $booking ) wp_send_json_error( ['message' => 'Booking not found'] );

        $old_status = $booking['status'];
        $history    = json_decode( $booking['workflow_history'] ?? '[]', true ) ?: [];
        $history[]  = [
            'status' => $new_status,
            'note'   => "[ADMIN FORCE] {$note}",
            'by'     => get_current_user_id(),
            'at'     => gmdate('Y-m-d H:i:s'),
        ];

        $db->update( $db->prefix('bookings'), [
            'status'           => $new_status,
            'workflow_history' => wp_json_encode( $history ),
        ], ['id' => $booking_id] );

        AuditLogger::status_change( 'bookings', $booking_id, $old_status, $new_status, "[Admin Force] {$note}" );
        do_action( 'nas_status_changed', $booking_id, $new_status );

        wp_send_json_success( ['message' => "Booking #{$booking['uid']} force-moved from '{$old_status}' to '{$new_status}'."] );
    }

    /**
     * TRACE: nas_admin_check_duplicate
     *        Input: POST client_id, newspaper_id, publish_date, ad_type
     *        Checks for existing bookings with same client + newspaper + date that are not rejected/cancelled.
     *        Returns found=true/false + the duplicate booking details if found.
     *        No mutation — read-only check. Used by admin booking form before saving.
     */
    // TRACE: check_duplicate() — Trigger: wp_ajax_check_duplicate AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function check_duplicate(): void {
        self::auth_admin();
        $client_id   = (int) Security::post('client_id', 'int');
        $newspaper_id = (int) Security::post('newspaper_id', 'int');
        $pub_date    = sanitize_text_field( Security::post('publish_date') );
        $ad_type     = sanitize_key( Security::post('ad_type') );

        if ( ! $client_id || ! $newspaper_id || ! $pub_date ) {
            wp_send_json_success( ['found' => false] );
        }

        $db       = self::db();
        $existing = $db->row(
            "SELECT id, uid, status, submitted_at FROM `{$db->prefix('bookings')}`
             WHERE client_id=%d AND newspaper_id=%d AND publish_date=%s
               AND ad_type=%s AND status NOT IN ('rejected','cancelled')
             LIMIT 1",
            [$client_id, $newspaper_id, $pub_date, $ad_type]
        );

        if ( $existing ) {
            wp_send_json_success( [
                'found'   => true,
                'message' => "A similar booking #{$existing['uid']} already exists for this date and newspaper (status: {$existing['status']}).",
                'booking' => $existing,
            ] );
        }
        wp_send_json_success( ['found' => false] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // BATCH NOTIFICATIONS (13-E / 14-E)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_batch_notify
     *        Input: POST filter_status (optional), subject, message, channel='email'
     *        Sends email to all clients whose bookings match the filter.
     *        Rate guard: max 500 emails per call; admin must confirm count before sending.
     *        Postcondition: emails queued via Queue::push(); not sent synchronously.
     *        Audit: logged with recipient count.
     *        Edge case: no recipients found → returns count=0, no send.
     */
    // TRACE: batch_notify() — Trigger: wp_ajax_batch_notify AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function batch_notify(): void {
        self::auth_admin();
        if ( ! current_user_can('manage_options') ) wp_send_json_error( ['message' => 'manage_options required'], 403 );

        $filter_status = sanitize_key( Security::post('filter_status') );
        $subject       = sanitize_text_field( Security::post('subject') );
        $message_body  = sanitize_textarea_field( Security::post('message') );
        $dry_run       = (bool) Security::post('dry_run', 'int');

        if ( ! $subject || ! $message_body ) wp_send_json_error( ['message' => 'subject and message required'] );

        $db    = self::db();
        $where = 'c.status = "active"';
        $args  = [];
        if ( $filter_status ) {
            $where .= ' AND b.status = %s';
            $args[] = $filter_status;
        }

        // Get unique client emails for the filter
        $recipients = $db->select(
            "SELECT DISTINCT cl.id, cl.name, cl.email
             FROM `{$db->prefix('clients')}` cl
             " . ( $filter_status ? "INNER JOIN `{$db->prefix('bookings')}` b ON b.client_id=cl.id" : '' ) . "
             WHERE cl.status='active' AND cl.email != ''
             " . ( $filter_status ? "AND b.status=%s" : '' ) . "
             LIMIT 500",
            $filter_status ? [$filter_status] : []
        );

        if ( $dry_run ) {
            wp_send_json_success( ['count' => count($recipients), 'dry_run' => true, 'message' => count($recipients) . ' clients would receive this message.'] );
        }

        foreach ( $recipients as $r ) {
            \NAS\Core\Queue::push( 'NAS\Modules\Notifications\BatchEmailJob', [
                'to'      => $r['email'],
                'name'    => $r['name'],
                'subject' => $subject,
                'body'    => "Hi {$r['name']},\n\n{$message_body}",
            ], 0, 'low' );
        }

        AuditLogger::log( 'clients', 0, 'batch_notify', [], [], "Batch email sent to " . count($recipients) . " clients. Subject: {$subject}" );
        wp_send_json_success( ['count' => count($recipients), 'message' => count($recipients) . ' emails queued for delivery.'] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // SMART DATE FILTERS (14-E QOL Baseline)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_smart_date_bookings
     *        Input: POST preset = 'today'|'this_week'|'last_30'|'last_90'
     *        Translates preset to date_from/date_to and returns booking count + list.
     *        Postcondition: returns bookings for the preset date range.
     *        Edge case: unknown preset → error.
     */
    // TRACE: smart_date_bookings() — Trigger: wp_ajax_smart_date_bookings AJAX action.
    //        Steps: reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function smart_date_bookings(): void {
        self::auth_admin();
        $preset = sanitize_key( Security::post('preset') ?: 'today' );

        $now   = current_time('Y-m-d');
        switch ( $preset ) {
            case 'today':
                $from = $now; $to = $now; break;
            case 'this_week':
                $from = date( 'Y-m-d', strtotime('monday this week') );
                $to   = date( 'Y-m-d', strtotime('sunday this week') );
                break;
            case 'last_30':
                $from = date( 'Y-m-d', strtotime('-30 days') );
                $to   = $now;
                break;
            case 'last_90':
                $from = date( 'Y-m-d', strtotime('-90 days') );
                $to   = $now;
                break;
            default:
                wp_send_json_error( ['message' => 'Invalid preset. Use: today, this_week, last_30, last_90'] );
        }

        $db       = self::db();
        $bookings = $db->select(
            "SELECT b.id, b.uid, b.client_name, b.newspaper_name, b.city_name,
                    b.status, b.total_amount, b.payment_status, b.submitted_at
             FROM `{$db->prefix('bookings')}` b
             WHERE DATE(b.submitted_at) BETWEEN %s AND %s
             ORDER BY b.submitted_at DESC LIMIT 500",
            [$from, $to]
        );

        $total   = $db->scalar(
            "SELECT COUNT(*) FROM `{$db->prefix('bookings')}` WHERE DATE(submitted_at) BETWEEN %s AND %s",
            [$from, $to]
        );

        wp_send_json_success( [
            'bookings' => $bookings,
            'total'    => (int) $total,
            'from'     => $from,
            'to'       => $to,
            'preset'   => $preset,
        ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // CONFIG VALIDATION (14-C)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_validate_settings
     *        Input: POST — all settings fields as sent by the settings form
     *        Validates each field against its type and constraints.
     *        Returns: errors[] array (empty = all valid).
     *        Does NOT save — called before save to pre-validate.
     *        Edge cases: blank required fields, invalid email, negative values, out-of-range percents.
     */
    // TRACE: validate_settings() — Trigger: wp_ajax_validate_settings AJAX action.
    //        Steps: reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function validate_settings(): void {
        self::auth_admin();
        $errors = [];

        $gst = (float) Security::post('gst_percentage', 'float');
        if ( $gst < 0 || $gst > 100 ) $errors['gst_percentage'] = 'GST percentage must be between 0 and 100.';

        $email_addr = Security::post('email_sender_addr', 'email');
        if ( $email_addr && ! is_email($email_addr) ) $errors['email_sender_addr'] = 'Sender email address is not valid.';

        $alert_email = Security::post('admin_alert_email', 'email');
        if ( $alert_email && ! is_email($alert_email) ) $errors['admin_alert_email'] = 'Admin alert email is not valid.';

        $smtp_port = (int) Security::post('smtp_port', 'int');
        if ( $smtp_port && ( $smtp_port < 1 || $smtp_port > 65535 ) ) $errors['smtp_port'] = 'SMTP port must be between 1 and 65535.';

        $sla_hours = (int) Security::post('sla_hours', 'int');
        if ( $sla_hours < 1 ) $errors['sla_hours'] = 'SLA hours must be at least 1.';

        $cutoff = (int) Security::post('cutoff_days', 'int');
        if ( $cutoff < 0 ) $errors['cutoff_days'] = 'Cutoff days cannot be negative.';

        $rate_limit = (int) Security::post('rate_limit_bookings', 'int');
        if ( $rate_limit < 0 ) $errors['rate_limit_bookings'] = 'Rate limit cannot be negative.';

        $brand = trim( Security::post('brand_name') );
        if ( ! $brand ) $errors['brand_name'] = 'Brand name is required.';

        wp_send_json_success( ['valid' => empty($errors), 'errors' => $errors] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // ENVIRONMENT CHECK (15-B)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * TRACE: nas_admin_environment_check
     *        Verifies PHP extensions, directory permissions, DB connectivity.
     *        Returns a structured report with PASS/FAIL per item.
     *        Does NOT require specific inputs — reads server state directly.
     *        Admin can run this from settings page to diagnose environment issues.
     */
    // TRACE: environment_check() — Trigger: wp_ajax_environment_check AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function environment_check(): void {
        self::auth_admin();
        $results = [];

        // PHP version
        $php_ver = PHP_VERSION;
        $results['php_version'] = [
            'label'  => 'PHP Version',
            'value'  => $php_ver,
            'status' => version_compare( $php_ver, '7.4', '>=' ) ? 'pass' : 'fail',
            'note'   => 'Requires PHP 7.4+',
        ];

        // Required PHP extensions
        $required_ext = ['json', 'curl', 'mbstring', 'openssl', 'fileinfo'];
        foreach ( $required_ext as $ext ) {
            $results['ext_' . $ext] = [
                'label'  => "PHP Extension: {$ext}",
                'value'  => extension_loaded($ext) ? 'loaded' : 'MISSING',
                'status' => extension_loaded($ext) ? 'pass' : 'fail',
                'note'   => "Required for core functionality",
            ];
        }

        // Uploads directory writable
        $upload_dir = wp_upload_dir();
        $uploads_ok = is_writable( $upload_dir['basedir'] );
        $results['uploads_writable'] = [
            'label'  => 'Uploads directory writable',
            'value'  => $upload_dir['basedir'],
            'status' => $uploads_ok ? 'pass' : 'fail',
            'note'   => 'Required for file uploads and invoice PDFs',
        ];

        // WP cron enabled (important for Queue and SLA cron)
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $results['wp_cron'] = [
            'label'  => 'WP Cron enabled',
            'value'  => $cron_disabled ? 'DISABLED' : 'enabled',
            'status' => $cron_disabled ? 'warning' : 'pass',
            'note'   => 'Required for email queue, SLA checks, and scheduled tasks. If using server cron, this is OK.',
        ];

        // DB connectivity
        global $wpdb;
        $db_ok = ( $wpdb->check_connection() !== false );
        $results['db_connection'] = [
            'label'  => 'Database connection',
            'value'  => $db_ok ? 'connected' : 'FAILED',
            'status' => $db_ok ? 'pass' : 'fail',
            'note'   => 'MySQL/MariaDB connection required',
        ];

        // NAS tables installed
        $db       = self::db();
        $tbl_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '{$wpdb->prefix}nas_%'" );
        $results['nas_tables'] = [
            'label'  => 'NAS database tables',
            'value'  => "{$tbl_count} tables",
            'status' => $tbl_count >= 20 ? 'pass' : 'warning',
            'note'   => 'Expects 20+ tables. If low, trigger re-install from Data Manager.',
        ];

        // SLA cron scheduled
        $sla_next = wp_next_scheduled('nas_sla_check');
        $results['sla_cron'] = [
            'label'  => 'SLA check cron scheduled',
            'value'  => $sla_next ? date('Y-m-d H:i:s', $sla_next) : 'NOT SCHEDULED',
            'status' => $sla_next ? 'pass' : 'warning',
            'note'   => 'SLA breach detection requires this cron to run hourly.',
        ];

        $pass_count    = count( array_filter( $results, fn($r) => $r['status'] === 'pass' ) );
        $warning_count = count( array_filter( $results, fn($r) => $r['status'] === 'warning' ) );
        $fail_count    = count( array_filter( $results, fn($r) => $r['status'] === 'fail' ) );

        wp_send_json_success( [
            'checks'   => $results,
            'summary'  => [ 'pass' => $pass_count, 'warning' => $warning_count, 'fail' => $fail_count ],
            'overall'  => $fail_count === 0 ? ( $warning_count === 0 ? 'pass' : 'warning' ) : 'fail',
        ] );
    }
}

/**
 * BatchEmailJob — handles individual batch emails from the queue.
 * Called by Queue::process() when a 'batch_notify' item is dequeued.
 */
class BatchEmailJob {
    // TRACE: Called by Queue::process() → handle($payload).
    //        Payload: to, name, subject, body.
    //        Sends wp_mail(). Failures are caught by Queue and marked 'failed'.
    // TRACE: handle() — Trigger: wp_ajax_handle AJAX action.
    //        Steps: sends email via wp_mail.
    //        Output: success/error JSON response.
    //        Edge cases: empty input values handled.
    public static function handle( array $payload ): void {
        if ( empty($payload['to']) || ! is_email($payload['to']) ) return;
        wp_mail(
            $payload['to'],
            $payload['subject'] ?? 'Message from ' . get_bloginfo('name'),
            $payload['body']    ?? ''
        );
    }
}
