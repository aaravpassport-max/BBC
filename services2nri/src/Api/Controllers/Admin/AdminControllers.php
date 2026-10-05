<?php
namespace S2NRI\Api\Controllers\Admin;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\{Request, Response};
use S2NRI\Services\{NotificationService, EmailService};

// ══════════════════════════════════════════════════════════════════════════════
// BookingAdminController
// ══════════════════════════════════════════════════════════════════════════════

class BookingAdminController extends \S2NRI\Api\Controllers\BaseController {

    // ── List all bookings (filterable) ────────────────────────────────────────

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;

        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset      = $this->offset( $page, $per_page );
        $status      = sanitize_key( $req->query( 'status', '' ) );
        $search      = sanitize_text_field( $req->query( 'search', '' ) );
        $cat_id      = (int) $req->query( 'category_id', 0 );
        $assigned    = (int) $req->query( 'assigned_to', 0 );
        $date_from   = sanitize_text_field( $req->query( 'date_from', '' ) );
        $date_to     = sanitize_text_field( $req->query( 'date_to', '' ) );
        $priority    = sanitize_key( $req->query( 'priority', '' ) );
        $qual_status = sanitize_key( $req->query( 'qual_status', '' ) );

        $where = 'WHERE 1=1';
        if ( $status )    $where .= $wpdb->prepare( ' AND b.status = %s', $status );
        if ( $priority )  $where .= $wpdb->prepare( ' AND b.priority = %s', $priority );
        if ( $cat_id )    $where .= $wpdb->prepare( ' AND b.category_id = %d', $cat_id );
        if ( $assigned )  $where .= $wpdb->prepare( ' AND b.assigned_to = %d', $assigned );
        if ( $date_from ) $where .= $wpdb->prepare( ' AND DATE(b.created_at) >= %s', $date_from );
        if ( $date_to )   $where .= $wpdb->prepare( ' AND DATE(b.created_at) <= %s', $date_to );
        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                " AND (b.booking_ref LIKE %s OR u.user_email LIKE %s OR COALESCE(u.display_name, u.user_login, '') LIKE %s)",
                $like, $like, $like
            );
        }

        // COUNT uses minimal JOINs — only what WHERE clause references
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$p}s2nri_bookings` b
             LEFT JOIN `{$p}s2nri_customers` cu ON cu.id = b.customer_id
             LEFT JOIN `{$p}users` u ON u.ID = cu.wp_user_id
             {$where}"
        );

        // SAFE QUERY: Only guaranteed-existing columns.
        // secondary_status and c.icon were added via ALTER TABLE — use NULL AS placeholders.
        // No duplicate JOINs — each table aliased once only.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.priority, b.payment_status,
                    b.quoted_amount, b.paid_amount, b.created_at, b.updated_at,
                    COALESCE(s.name, '') AS service_name,
                    COALESCE(c.name, '') AS category_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    COALESCE(u.user_email, '') AS customer_email,
                    COALESCE(cu.phone, '') AS customer_phone,
                    COALESCE(cu.country, '') AS customer_country,
                    COALESCE(au.display_name, au.user_login, '') AS assigned_name,
                    b.secondary_status AS secondary_status,
                    b.due_date AS due_date,
                    c.icon AS cat_icon
             FROM `{$p}s2nri_bookings` b
             LEFT JOIN `{$p}s2nri_services` s   ON s.id  = b.service_id
             LEFT JOIN `{$p}s2nri_categories` c  ON c.id  = b.category_id
             LEFT JOIN `{$p}s2nri_customers` cu  ON cu.id = b.customer_id
             LEFT JOIN `{$p}users` u             ON u.ID  = cu.wp_user_id
             LEFT JOIN `{$p}users` au            ON au.ID = b.assigned_to
             {$where}
             ORDER BY b.updated_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        if ( $wpdb->last_error ) {
            error_log( '[S2NRI] admin/bookings index() error: ' . $wpdb->last_error );
        }

        $rows = $rows ?: [];
        Response::json( [
            'rows'     => $rows,
            'bookings' => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    public function show( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    s.name AS service_name, s.slug AS service_slug, s.required_docs,
                    s.turnaround_days, s.pricing_model,
                    c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    u.user_email AS customer_email, u.ID AS customer_wp_id,
                    cu.phone AS customer_phone, cu.whatsapp AS customer_whatsapp,
                    cu.country, cu.city_abroad, cu.city_india, cu.address_india,
                    COALESCE(au.display_name, au.user_login, '') AS assigned_name,
                    au.user_email AS assigned_email,
                    NULL AS assigned_phone
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id = b.category_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             LEFT JOIN {$p}users au ON au.ID = b.assigned_to
             LEFT JOIN {$p}s2nri_staff st ON st.wp_user_id = b.assigned_to
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { error_log( '[S2NRI admin show()] id=' . $id . ' NULL error=' . $wpdb->last_error . ' user=' . json_encode($this->user ?? null) ); Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        $booking['field_data']    = json_decode( $booking['field_data']    ?? '{}', true );
        $booking['required_docs'] = json_decode( $booking['required_docs'] ?? '[]', true );

        // Structured vendor + manager contact for staff panels (M-03, M-13, M-21)
        $booking['manager_contact'] = $booking['assigned_to'] ? [
            'name'  => $booking['assigned_name'],
            'email' => $booking['assigned_email'],
            'phone' => $booking['assigned_phone'] ?? '',
        ] : null;
        $booking['vendor'] = null; // vendor assignment not implemented in this version

        // Delivery info string (M-04, M-09)
        if ( $booking['due_date'] && $booking['delivery_working_days'] ) {
            $booking['delivery_info'] = date( 'd M Y', strtotime( $booking['due_date'] ) )
                . ' (' . $booking['delivery_working_days'] . ' working days — excl. Sat, Sun & Govt Holidays)';
        } elseif ( $booking['due_date'] ) {
            $booking['delivery_info'] = date( 'd M Y', strtotime( $booking['due_date'] ) );
        } else {
            $booking['delivery_info'] = null;
        }

        // Quotes — return both breakdown and line_items (M-05)
        $booking['quotes'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*, COALESCE(u.display_name, u.user_login, '') AS created_by_name
             FROM {$p}s2nri_quotes q JOIN {$p}users u ON u.ID = q.created_by
             WHERE q.booking_id = %d ORDER BY q.created_at DESC",
            $id
        ), ARRAY_A );
        foreach ( $booking['quotes'] as &$q ) {
            $line_items_raw = $q['line_items'] ?? null;
            $breakdown_raw  = $q['breakdown']  ?? null;
            $q['line_items'] = $line_items_raw ? json_decode( $line_items_raw, true ) : ( $breakdown_raw ? json_decode( $breakdown_raw, true ) : [] );
            $q['breakdown']  = $q['line_items']; // keep legacy field populated too
        }
        unset( $q );

        // Compute service + shipping charges from latest quote (M-05)
        $latest_q = $booking['quotes'][0] ?? null;
        $booking['service_charges']  = 0.0;
        $booking['shipping_charges'] = 0.0;
        if ( $latest_q ) {
            foreach ( (array) $latest_q['line_items'] as $item ) {
                if ( isset($item['type']) && $item['type'] === 'shipping' ) {
                    $booking['shipping_charges'] = (float) ($item['amount'] ?? 0);
                } elseif ( isset($item['type']) && $item['type'] === 'service' ) {
                    $booking['service_charges'] = (float) ($item['amount'] ?? 0);
                }
            }
            if ( $booking['service_charges'] === 0.0 && $booking['quoted_amount'] ) {
                $booking['service_charges'] = (float) $booking['quoted_amount'] - $booking['shipping_charges'];
            }
        }

        // Documents split into user + provider sections (M-16)
        $all_docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, COALESCE(u.display_name, u.user_login, '') AS uploaded_by_name
             FROM {$p}s2nri_documents d JOIN {$p}users u ON u.ID = d.uploaded_by
             WHERE d.booking_id = %d ORDER BY d.is_from_staff ASC, d.created_at ASC",
            $id
        ), ARRAY_A );
        $booking['documents']          = $all_docs;
        $booking['user_documents']     = array_values( array_filter( $all_docs, fn($d) => ! $d['is_from_staff'] ) );
        $booking['provider_documents'] = array_values( array_filter( $all_docs, fn($d) =>  $d['is_from_staff'] ) );

        // Messages with is_email_sent badge (4.13)
        $booking['messages'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login, '') AS sender_name, u.user_email AS sender_email
             FROM {$p}s2nri_messages m LEFT JOIN {$p}users u ON u.ID = m.sender_id
             WHERE m.booking_id = %d ORDER BY m.created_at ASC",
            $id
        ), ARRAY_A );

        // Payments
        $booking['payments'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, COALESCE(u.display_name, u.user_login, '') AS verified_by_name
             FROM {$p}s2nri_payments p LEFT JOIN {$p}users u ON u.ID = p.verified_by
             WHERE p.booking_id = %d ORDER BY p.created_at DESC",
            $id
        ), ARRAY_A );

        // Communication log (M-06)
        $booking['communication_log'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT cl.*, COALESCE(u.display_name, u.user_login, 'System') AS sender_name
             FROM {$p}s2nri_communication_log cl LEFT JOIN {$p}users u ON u.ID=cl.sent_by
             WHERE cl.booking_id=%d ORDER BY cl.created_at DESC LIMIT 30",
            $id
        ), ARRAY_A );

        // Audit log
        $booking['audit_log'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT al.*, COALESCE(u.display_name, u.user_login, '') AS user_name
             FROM {$p}s2nri_audit_log al LEFT JOIN {$p}users u ON u.ID = al.user_id
             WHERE al.booking_id = %d ORDER BY al.created_at DESC LIMIT 50",
            $id
        ), ARRAY_A );

        // Quick replies for communication panel (M-07)
        $booking['quick_replies'] = $wpdb->get_results(
            "SELECT id, title, category, content FROM {$p}s2nri_quick_replies ORDER BY category ASC, sort_order ASC",
            ARRAY_A
        );

        // Staff list for assignment — includes all active staff records AND any WP users
        // with s2nri_manager / s2nri_agent / administrator roles who may have been added
        // via the portal Settings panel rather than the /admin/staff page.
        $booking['staff_list'] = $wpdb->get_results(
            "SELECT st.wp_user_id AS id,
                    COALESCE(u.display_name, u.user_login) AS name,
                    u.user_email AS email,
                    st.s2nri_role AS role
             FROM {$p}s2nri_staff st
             JOIN {$p}users u ON u.ID = st.wp_user_id
             WHERE st.is_active = 1
             UNION
             SELECT u.ID AS id,
                    COALESCE(u.display_name, u.user_login) AS name,
                    u.user_email AS email,
                    CASE
                        WHEN um.meta_value LIKE '%administrator%' THEN 'super_admin'
                        WHEN um.meta_value LIKE '%s2nri_manager%' THEN 'manager'
                        WHEN um.meta_value LIKE '%s2nri_agent%'   THEN 'agent'
                        ELSE 'manager'
                    END AS role
             FROM {$p}users u
             JOIN {$p}usermeta um ON um.user_id = u.ID
                 AND um.meta_key = '{$p}capabilities'
                 AND (um.meta_value LIKE '%s2nri_manager%'
                      OR um.meta_value LIKE '%s2nri_agent%'
                      OR um.meta_value LIKE '%administrator%')
             WHERE u.ID NOT IN (SELECT wp_user_id FROM {$p}s2nri_staff WHERE is_active = 1)
             ORDER BY name ASC",
            ARRAY_A
        ) ?: [];

        // Vendor list for assignment (M-13)
        $booking['vendor_list'] = $wpdb->get_results(
            "SELECT id, name, phone, whatsapp, company FROM {$p}s2nri_vendors WHERE status='active' ORDER BY name ASC",
            ARRAY_A
        );

        // Tickets (M-20 — searchable from admin detail)
        $booking['tickets'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM {$p}s2nri_ticket_messages WHERE ticket_id=t.id) AS message_count
             FROM {$p}s2nri_tickets t WHERE t.booking_id=%d ORDER BY t.updated_at DESC",
            $id
        ), ARRAY_A );

        Response::json( [ 'booking' => $booking ] );
    }

    // ── Update status ─────────────────────────────────────────────────────────

    public function updateStatus( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p      = $wpdb->prefix;
        $id     = (int) $req->param( 'id' );
        $status = sanitize_key( $req->input( 'status', '' ) );
        $note   = sanitize_textarea_field( $req->input( 'note', '' ) );

        $valid_statuses = [
            // Core workflow statuses (match progress strip keys in portal JS)
            'submitted',        // Progress strip label: "Received"
            'under_review',     // Progress strip label: "Under Review"
            'quote_sent',       // Progress strip label: "Quote Sent"
            'quote_approved',   // Progress strip label: "Approved"
            'in_progress',      // Progress strip label: "In Progress"
            'processing',       // Progress strip label: "Processing"
            'completed',        // Progress strip label: "Completed"
            // Additional statuses
            'service_not_available', // = "Not Able to Serve" (shown as special case in strip)
            'docs_requested',
            'docs_received',
            'cancelled',
            'on_hold',
            // Aliases accepted from backend/API consumers
            'received',         // alias for 'submitted' — normalized below
            'delivered',        // maps to 'completed' in the portal strip
        ];
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            Response::json( [ 'error' => 'Invalid status.' ], 422 ); return;
        }

        // Normalize aliases to canonical values stored in DB
        $alias_map = [ 'received' => 'submitted', 'delivered' => 'completed' ];
        if ( isset( $alias_map[ $status ] ) ) {
            $status = $alias_map[ $status ];
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        $old_status = $booking['status'];

        $update = [
            'status'     => $status,
            'updated_at' => current_time( 'mysql' ),
        ];
        if ( $status === 'completed' ) $update['completed_at'] = current_time( 'mysql' );
        if ( $status === 'cancelled' ) $update['cancelled_at'] = current_time( 'mysql' );

        if ( $wpdb->update( $p . 's2nri_bookings', $update, [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update booking status.' ], 500 ); return;
        }

        // System message
        $msg = "Status updated: {$old_status} → {$status}";
        if ( $note ) $msg .= " — {$note}";
        if ( $wpdb->insert( $p . 's2nri_messages', [
            'booking_id'  => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'system',
            'message'     => $msg,
            'created_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] System message insert failed for booking ' . $id . ' after status change: ' . $wpdb->last_error );
            // Non-fatal — the status change itself already succeeded and was
            // checked above; a missing system message is a lesser, logged
            // gap, not a reason to tell the admin the whole action failed.
        }

        $this->logAudit( $id, 'status_changed', $old_status, $status );

        // Notify customer
        NotificationService::notifyCustomer( $booking['wp_user_id'], "booking_{$status}", [
            'booking_id' => $id,
            'title'      => "Booking Update: {$booking['booking_ref']}",
            'body'       => $this->statusMessage( $status, $booking['booking_ref'] ),
        ] );

        // Send email
        EmailService::sendStatusUpdate( $booking['wp_user_id'], $id, $status, $booking['booking_ref'] );

        // M-14: WhatsApp notification hook
        do_action( 's2nri_booking_status_changed', $id, $status, [
            'booking_ref'   => $booking['booking_ref'],
            'customer_name' => $booking['customer_name'] ?? '',
            'service_name'  => $booking['service_name']  ?? '',
            'status'        => $status,
        ] );

        Response::json( [ 'success' => true, 'message' => "Status updated to {$status}." ] );
    }

    // ── Assign staff ──────────────────────────────────────────────────────────

    public function assign( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        // Frontend sends different field names depending on which admin UI is used:
        // - /s2nri-admin/ portal SPA sends: {user_id: N}
        // - /admin/ public website admin sends: {wp_user_id: N}
        // - Legacy field name: {assigned_to: N}
        // Support all three for compatibility.
        $assigned_to = (int) (
            $req->input( 'user_id', null )
            ?? $req->input( 'wp_user_id', null )
            ?? $req->input( 'assigned_to', 0 )
        );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, booking_ref, status, assigned_to FROM {$wpdb->prefix}s2nri_bookings WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        if ( $wpdb->update(
            $wpdb->prefix . 's2nri_bookings',
            [ 'assigned_to' => $assigned_to ?: null, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        ) === false ) {
            Response::json( [ 'error' => 'Failed to assign booking.' ], 500 ); return;
        }

        $this->logAudit( $id, 'assigned', (string) $booking['assigned_to'], (string) $assigned_to );
        Response::json( [ 'success' => true ] );
    }

    // ── Add internal note ─────────────────────────────────────────────────────

    public function addNote( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id   = (int) $req->param( 'id' );
        $note = sanitize_textarea_field( $req->input( 'note', '' ) );

        if ( ! $note ) { Response::json( [ 'error' => 'Note required.' ], 422 ); return; }

        // Store as internal message
        if ( $wpdb->insert( $wpdb->prefix . 's2nri_messages', [
            'booking_id'  => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'staff',
            'message'     => $note,
            'is_internal' => 1,
            'created_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] Internal note insert failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save note. Please try again.' ], 500 ); return;
        }

        // Log to audit trail as note_added so OPS-07 notesHistory can retrieve it
        $this->logAudit( $id, 'note_added', '', $note );

        Response::json( [ 'success' => true ] );
    }

    // ── Send message to customer ──────────────────────────────────────────────

    public function sendMessage( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p       = $wpdb->prefix;
        $id      = (int) $req->param( 'id' );
        $message = sanitize_textarea_field( $req->input( 'message', '' ) );

        if ( ! $message ) { Response::json( [ 'error' => 'Message required.' ], 422 ); return; }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.booking_ref, cu.wp_user_id FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        if ( $wpdb->insert( $p . 's2nri_messages', [
            'booking_id'  => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'staff',
            'message'     => $message,
            'is_internal' => 0,
            'created_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] Staff-to-customer message insert failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to send message. Please try again.' ], 500 ); return;
        }

        NotificationService::notifyCustomer( $booking['wp_user_id'], 'staff_message', [
            'booking_id' => $id,
            'title'      => "New message on {$booking['booking_ref']}",
            'body'       => substr( $message, 0, 100 ),
        ] );

        Response::json( [ 'success' => true ] );
    }

    private function statusMessage( string $status, string $ref ): string {
        $unavail_msg = \S2NRI\Models\Setting::get( 'service_unavailable_message',
            'We regret to inform you that this service is currently not available for your requirement. Our team will contact you to discuss alternatives.' );
        $messages = [
            'under_review'          => "Your booking {$ref} is under review. We'll send you a quote soon.",
            'quote_sent'            => "A quote has been sent for your booking {$ref}. Please review and approve.",
            'in_progress'           => "Great! Your booking {$ref} is now in progress.",
            'docs_requested'        => "We need some documents for your booking {$ref}. Please check your booking portal.",
            'processing'            => "Your booking {$ref} is being processed. We'll update you shortly.",
            'completed'             => "Your booking {$ref} has been completed successfully! Please share your feedback.",
            'on_hold'               => "Your booking {$ref} is on hold. Our team will contact you.",
            'cancelled'             => "Your booking {$ref} has been cancelled.",
            'service_not_available' => "Re: Booking {$ref} — {$unavail_msg}",
        ];
        return $messages[ $status ] ?? "Your booking {$ref} has been updated to: " . ucwords( str_replace('_', ' ', $status) );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// QuoteAdminController
// ══════════════════════════════════════════════════════════════════════════════

class QuoteAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function send( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = (int) $req->param( 'id' );
        $amount     = (float) $req->input( 'amount', 0 );
        // Support both 'line_items' (new frontend) and 'breakdown' (legacy)
        $breakdown  = $req->input( 'line_items', $req->input( 'breakdown', [] ) );
        $notes      = sanitize_textarea_field( $req->input( 'notes', '' ) );
        $valid_days = max( 1, (int) $req->input( 'valid_days', 7 ) );

        if ( $amount <= 0 ) {
            Response::json( [ 'error' => 'Quote amount must be greater than zero.' ], 422 ); return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );
        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }

        // Supersede any existing pending quotes
        if ( $wpdb->update( $p . 's2nri_quotes', [ 'status' => 'superseded' ], [ 'booking_id' => $id, 'status' => 'pending' ] ) === false ) {
            error_log( '[S2NRI] Failed to supersede old quotes for booking ' . $id . ': ' . $wpdb->last_error );
            // Non-fatal here — logged for visibility; the new quote insert
            // right after this is still checked and is the operation that
            // actually matters for correctness.
        }

        $valid_until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$valid_days} days" ) );

        $quote_insert = $wpdb->insert( $p . 's2nri_quotes', [
            'booking_id'  => $id,
            'created_by'  => $this->user['wp_id'],
            'amount'      => $amount,
            'breakdown'   => wp_json_encode( is_array( $breakdown ) ? $breakdown : [] ),
            'line_items'  => wp_json_encode( is_array( $breakdown ) ? $breakdown : [] ),
            'notes'       => $notes,
            'valid_until' => $valid_until,
            'status'      => 'pending',
            'created_at'  => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): the booking update below was
        // previously unconditional — a failed quote insert here meant the
        // booking got marked status=quote_sent with a quoted_amount, but no
        // actual quote row existed for the customer to view or accept.
        if ( $quote_insert === false ) {
            error_log( '[S2NRI] Quote insert failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save quote. Please try again.' ], 500 ); return;
        }

        if ( $wpdb->update( $p . 's2nri_bookings', [
            'status'          => 'quote_sent',
            'quoted_amount'   => $amount,
            'quoted_at'       => current_time( 'mysql' ),
            'quote_expires_at'=> $valid_until,
            'updated_at'      => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            error_log( '[S2NRI] Booking status update to quote_sent failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Quote saved but failed to update booking status. Please refresh and verify.' ], 500 ); return;
        }

        $this->logAudit( $id, 'quote_sent', '', "₹{$amount}" );

        NotificationService::notifyCustomer( $booking['wp_user_id'], 'quote_sent', [
            'booking_id' => $id,
            'title'      => "Quote Ready: {$booking['booking_ref']}",
            'body'       => "We've sent you a quote of ₹{$amount} for your booking {$booking['booking_ref']}. Valid for {$valid_days} days. Please log in to review.",
        ] );

        EmailService::sendQuoteEmail( $booking['wp_user_id'], $id, $amount, $valid_until );

        Response::json( [ 'success' => true, 'message' => 'Quote sent to customer.' ] );
    }

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*, b.booking_ref, s.name AS service_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    COALESCE(cu.display_name, cu.user_login, '') AS created_by_name
             FROM {$wpdb->prefix}s2nri_quotes q
             JOIN {$wpdb->prefix}s2nri_bookings b ON b.id = q.booking_id
             JOIN {$wpdb->prefix}s2nri_services s ON s.id = b.service_id
             JOIN {$wpdb->prefix}s2nri_customers cust ON cust.id = b.customer_id
             JOIN {$wpdb->prefix}users u ON u.ID = cust.wp_user_id
             JOIN {$wpdb->prefix}users cu ON cu.ID = q.created_by
             ORDER BY q.created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        Response::json( [ 'quotes' => $rows ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PaymentAdminController
// ══════════════════════════════════════════════════════════════════════════════

class PaymentAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );
        $status = sanitize_key( $req->query( 'status', '' ) );

        $search = sanitize_text_field( $req->query( 'search', '' ) );

        $where = 'WHERE 1=1';
        if ( $status ) $where .= $wpdb->prepare( " AND p.status = %s", $status );
        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                " AND (p.payment_ref LIKE %s OR b.booking_ref LIKE %s OR u.user_email LIKE %s)",
                $like, $like, $like
            );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_payments p JOIN {$p}s2nri_bookings b ON b.id = p.booking_id LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id {$where}" );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, b.booking_ref, s.name AS service_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email AS customer_email
             FROM {$p}s2nri_payments p
             JOIN {$p}s2nri_bookings b ON b.id = p.booking_id
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             {$where}
             ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }

    public function verify( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p   = $wpdb->prefix;
        $id  = (int) $req->param( 'id' );

        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, b.booking_ref, cu.wp_user_id
             FROM {$p}s2nri_payments p
             JOIN {$p}s2nri_bookings b ON b.id = p.booking_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE p.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $payment ) { Response::json( [ 'error' => 'Payment not found.' ], 404 ); return; }

        // Guard: already verified — prevent double-process (race condition or double-click)
        if ( $payment['status'] === 'verified' ) {
            Response::json( [ 'error' => 'Payment already verified.' ], 409 ); return;
        }

        // CHECKED (was previously unchecked): same money-critical class
        // already fixed for the Razorpay verification flow earlier in
        // this audit — this is the manual/staff bank-transfer
        // verification path. A silent failure here previously still fell
        // through to sending the customer a "payment confirmed" email and
        // WhatsApp notification, and returning success:true to the staff
        // member, while the payment/booking records may never have
        // actually updated in the database.
        $pay_update = $wpdb->update( $p . 's2nri_payments', [
            'status'      => 'verified',
            'verified_by' => $this->user['wp_id'],
            'verified_at' => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        $book_update = $wpdb->update( $p . 's2nri_bookings', [
            'payment_status' => 'paid',
            'paid_amount'    => $payment['amount'],
            'updated_at'     => current_time( 'mysql' ),
        ], [ 'id' => $payment['booking_id'] ] );

        if ( $pay_update === false || $book_update === false ) {
            error_log( "[S2NRI] CRITICAL: manual payment verification DB update failed for payment {$id}, booking {$payment['booking_id']}: " . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save verification. Please try again — no notification was sent.' ], 500 ); return;
        }

        $this->logAudit( $payment['booking_id'], 'payment_verified', 'pending', "₹{$payment['amount']}" );

        NotificationService::notifyCustomer( $payment['wp_user_id'], 'payment_verified', [
            'booking_id' => $payment['booking_id'],
            'title'      => "Payment Confirmed: {$payment['booking_ref']}",
            'body'       => "Your payment of ₹{$payment['amount']} has been confirmed. We'll begin processing your request.",
        ] );

        // Send payment confirmation email
        try {
            $email_svc = new \S2NRI\Services\EmailService();
            $booking_info = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.booking_ref, s.name AS service_name, cu.wp_user_id
                 FROM {$p}s2nri_bookings b
                 LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
                 LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
                 WHERE b.id = %d LIMIT 1",
                $payment['booking_id']
            ), ARRAY_A );
            if ( $booking_info ) {
                $email_svc->sendPaymentConfirmation(
                    (int) $booking_info['wp_user_id'],
                    $payment,
                    $booking_info['booking_ref'],
                    $booking_info['service_name']
                );
            }
        } catch ( \Throwable $e ) {
            error_log( 'S2NRI payment email error: ' . $e->getMessage() );
        }

        // M-14: WhatsApp notification hook
        do_action( 's2nri_payment_verified', $payment['booking_id'], [
            'booking_ref'   => $payment['booking_ref'],
            'customer_name' => '',
            'service_name'  => '',
            'amount'        => $payment['amount'],
        ] );

        Response::json( [ 'success' => true, 'message' => 'Payment verified.' ] );
    }

    // ADDED: this is what actually activates the 'refunded' status —
    // confirmed earlier this session that no code path anywhere ever set
    // a payment/booking to 'refunded', despite it being a real ENUM
    // value on both tables. Follows the exact same pattern as verify()/
    // reject() above: staff attest that a refund was processed
    // externally (bank transfer reversal, Razorpay dashboard, etc.) and
    // record it here — this codebase's verify()/reject() already work
    // the same way (staff attestation, not an automated bank/gateway
    // API call), so this is consistent with the established design
    // rather than introducing a new architecture or a live payment-
    // gateway refund API integration (a much larger, separate scope
    // decision this fix does not make unprompted).
    public function refund( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, b.booking_ref, cu.wp_user_id
             FROM {$p}s2nri_payments p
             LEFT JOIN {$p}s2nri_bookings b ON b.id = p.booking_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE p.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $payment ) { Response::json( [ 'error' => 'Payment not found.' ], 404 ); return; }

        // Only a genuinely verified (paid) payment can be refunded — this
        // is the real business constraint: you cannot refund money that
        // was never confirmed as received.
        if ( $payment['status'] !== 'verified' ) {
            Response::json( [ 'error' => 'Only a verified payment can be refunded.' ], 409 ); return;
        }

        $reason = sanitize_textarea_field( $req->input( 'reason', '' ) );
        $notes  = "Refunded ₹{$payment['amount']}" . ( $reason ? " — {$reason}" : '' );

        $pay_update = $wpdb->update( $p . 's2nri_payments', [
            'status'     => 'refunded',
            'notes'      => $notes,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        $book_update = $wpdb->update( $p . 's2nri_bookings', [
            'payment_status' => 'refunded',
            'updated_at'     => current_time( 'mysql' ),
        ], [ 'id' => $payment['booking_id'] ] );

        // CHECKED from the start (not retrofitted) — same money-critical
        // pattern already established for verify()/reject() in this file.
        if ( $pay_update === false || $book_update === false ) {
            error_log( "[S2NRI] CRITICAL: refund DB update failed for payment {$id}, booking {$payment['booking_id']}: " . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save refund. Please try again — no notification was sent.' ], 500 ); return;
        }

        $this->logAudit( $payment['booking_id'], 'payment_refunded', 'verified', "₹{$payment['amount']}" . ( $reason ? " — {$reason}" : '' ) );

        if ( $payment['wp_user_id'] ) {
            NotificationService::notifyCustomer( (int) $payment['wp_user_id'], 'payment_refunded', [
                'booking_id' => $payment['booking_id'],
                'title'      => "Refund Processed: {$payment['booking_ref']}",
                'body'       => "Your payment of ₹{$payment['amount']} has been refunded." . ( $reason ? " Reason: {$reason}" : '' ),
            ] );
        }

        Response::json( [ 'success' => true, 'message' => 'Payment refunded.' ] );
    }

    public function reject( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id     = (int) $req->param( 'id' );
        $reason = sanitize_textarea_field( $req->input( 'reason', '' ) );

        if ( $wpdb->update( $wpdb->prefix . 's2nri_payments', [
            'status'     => 'failed',
            'notes'      => $reason,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to reject payment.' ], 500 ); return;
        }

        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CustomerAdminController
// ══════════════════════════════════════════════════════════════════════════════

class CustomerAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );
        $search = sanitize_text_field( $req->query( 'search', '' ) );

        $where = 'WHERE 1=1';
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND (u.user_email LIKE %s OR COALESCE(u.display_name, u.user_login, '') LIKE %s OR cu.phone LIKE %s)", $like, $like, $like );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}s2nri_customers cu LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id {$where}"
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT cu.*, COALESCE(u.display_name, u.user_login, '') AS name, u.user_email AS email,
                    COUNT(b.id) AS booking_count,
                    SUM(CASE WHEN b.payment_status = 'paid' THEN b.paid_amount ELSE 0 END) AS total_paid
             FROM {$p}s2nri_customers cu
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             LEFT JOIN {$p}s2nri_bookings b ON b.customer_id = cu.id
             {$where}
             GROUP BY cu.id
             ORDER BY cu.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }

    public function show( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT cu.*, COALESCE(u.display_name, u.user_login, '') AS name, u.user_email AS email
             FROM {$p}s2nri_customers cu LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             WHERE cu.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $customer ) { Response::json( [ 'error' => 'Customer not found.' ], 404 ); return; }

        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.payment_status, b.quoted_amount,
                    b.paid_amount, b.created_at, s.name AS service_name
             FROM {$p}s2nri_bookings b LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             WHERE b.customer_id = %d ORDER BY b.created_at DESC LIMIT 50",
            $id
        ), ARRAY_A );

        // Stats — matches what React AdminCustomerDetail expects
        $stats = [
            'total_bookings' => count( $bookings ),
            'completed'      => count( array_filter( $bookings, fn( $b ) => $b['status'] === 'completed' ) ),
            'total_paid'     => array_sum( array_column(
                array_filter( $bookings, fn( $b ) => $b['payment_status'] === 'paid' ),
                'paid_amount'
            ) ),
        ];

        // Add is_disabled flag from user meta
        $customer['is_disabled'] = get_user_meta( $customer['wp_user_id'], 's2nri_disabled', true ) === '1';

        Response::json( compact( 'customer', 'bookings', 'stats' ) );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id    = (int) $req->param( 'id' );
        $notes = sanitize_textarea_field( $req->input( 'notes', '' ) );

        if ( $wpdb->update( $wpdb->prefix . 's2nri_customers', [
            'notes'      => $notes,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to save notes.' ], 500 ); return;
        }

        Response::json( [ 'success' => true ] );
    }

    public function disable( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}s2nri_customers WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );

        if ( ! $customer ) { Response::json( [ 'error' => 'Customer not found.' ], 404 ); return; }

        update_user_meta( $customer['wp_user_id'], 's2nri_disabled', '1' );
        // Force immediate session termination — delete portal token so existing sessions are invalidated now
        delete_user_meta( $customer['wp_user_id'], 's2nri_portal_token' );
        delete_user_meta( $customer['wp_user_id'], 's2nri_portal_token_exp' );
        $this->logAudit( null, 'customer_disabled', '', (string) $customer['wp_user_id'] );
        Response::json( [ 'success' => true ] );
    }

    // TRACE: enable() → POST admin/customers/{id}/enable → removes s2nri_disabled meta → customer can log in again.
    public function enable( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}s2nri_customers WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );

        if ( ! $customer ) { Response::json( [ 'error' => 'Customer not found.' ], 404 ); return; }

        delete_user_meta( $customer['wp_user_id'], 's2nri_disabled' );
        $this->logAudit( null, 'customer_enabled', '', (string) $customer['wp_user_id'] );
        Response::json( [ 'success' => true, 'message' => 'Account enabled.' ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// StaffAdminController
// ══════════════════════════════════════════════════════════════════════════════

class StaffAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT sf.*, COALESCE(u.display_name, u.user_login, '') AS name, u.user_email AS email
             FROM {$p}s2nri_staff sf JOIN {$p}users u ON u.ID = sf.wp_user_id
             WHERE sf.is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );
        Response::json( [ 'staff' => $rows ] );
    }

    public function create( Request $req ): void {
        $this->requireManager();
        $email = sanitize_email( $req->input( 'email', '' ) );
        $name  = sanitize_text_field( $req->input( 'name', '' ) );
        $role  = sanitize_key( $req->input( 'role', 'agent' ) );

        if ( ! is_email( $email ) || ! $name ) {
            Response::json( [ 'error' => 'Name and valid email required.' ], 422 ); return;
        }
        $allowed_roles = [ 'manager', 'agent', 'finance' ];
        // Only super_admin can create another super_admin — privilege escalation guard
        if ( $this->user['s2nri_role'] === 'super_admin' || in_array( 'administrator', $this->user['wp_roles'] ?? [], true ) ) {
            $allowed_roles[] = 'super_admin';
        }
        if ( ! in_array( $role, $allowed_roles, true ) ) $role = 'agent';

        $exists = get_user_by( 'email', $email );
        if ( $exists ) {
            $user_id = $exists->ID;
        } else {
            $pass    = wp_generate_password( 12 );
            $user_id = wp_create_user( $email, $pass, $email );
            if ( is_wp_error( $user_id ) ) {
                Response::json( [ 'error' => 'Failed to create user.' ], 500 ); return;
            }
            wp_update_user( [ 'ID' => $user_id, 'display_name' => $name ] );
            $wp_user = get_user_by( 'id', $user_id );
            $wp_user->set_role( "s2nri_{$role}" );
        }

        global $wpdb;
        $exists_staff = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}s2nri_staff WHERE wp_user_id = %d LIMIT 1", $user_id
        ) );

        if ( ! $exists_staff ) {
            $staff_insert = $wpdb->insert( $wpdb->prefix . 's2nri_staff', [
                'wp_user_id' => $user_id,
                's2nri_role' => $role,
                'is_active'  => 1,
                'created_at' => current_time( 'mysql' ),
            ] );
            if ( $staff_insert === false ) {
                error_log( '[S2NRI] Staff insert failed for wp_user_id ' . $user_id . ': ' . $wpdb->last_error );
                Response::json( [ 'error' => 'User account created but failed to grant staff access. Please try again or contact support.' ], 500 ); return;
            }
        } else {
            if ( $wpdb->update( $wpdb->prefix . 's2nri_staff', [ 's2nri_role' => $role ], [ 'wp_user_id' => $user_id ] ) === false ) {
                Response::json( [ 'error' => 'Failed to update staff role.' ], 500 ); return;
            }
        }

        Response::json( [ 'success' => true, 'message' => 'Staff member created.' ] );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        // FIXED: this function previously only ever handled `role`,
        // defaulting to 'agent' whenever it was absent from the request
        // body — which is EVERY TIME the frontend's Activate/Deactivate
        // button calls this endpoint (it only ever sends {is_active},
        // confirmed against src/pages/admin/index.tsx's AdminStaff
        // component: `api.put('admin/staff/${id}', { is_active:
        // isActive ? 0 : 1 })`). Net effect: clicking Deactivate on ANY
        // staff member never actually deactivated them — is_active was
        // silently ignored — and instead silently RESET their role to
        // 'agent' every single time, corrupting manager/finance role
        // assignments with no error surfaced anywhere. Now treats role
        // and is_active as genuinely independent, optional fields.
        $update = [];

        if ( $req->has( 'role' ) ) {
            $role = sanitize_key( $req->input( 'role', 'agent' ) );
            $allowed_roles_u = [ 'manager', 'agent', 'finance' ];
            if ( $this->user['s2nri_role'] === 'super_admin' || in_array( 'administrator', $this->user['wp_roles'] ?? [], true ) ) {
                $allowed_roles_u[] = 'super_admin';
            }
            if ( ! in_array( $role, $allowed_roles_u, true ) ) $role = 'agent';
            $update['s2nri_role'] = $role;
        }

        if ( $req->has( 'is_active' ) ) {
            $update['is_active'] = (int) $req->input( 'is_active', 1 ) ? 1 : 0;
        }

        if ( empty( $update ) ) {
            Response::json( [ 'error' => 'No changes provided.' ], 422 ); return;
        }

        if ( $wpdb->update( $wpdb->prefix . 's2nri_staff', $update, [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update staff member.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ServiceAdminController
// ══════════════════════════════════════════════════════════════════════════════

class ServiceAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        // TRACE: SELECT explicit columns only — excludes LONGTEXT columns (qualification_schema,
        // description, form_schema, required_docs) that total 50-200KB per row across 57 services.
        // SELECT s.* caused PHP memory/packet limit failures on the live server.
        // The admin services list only renders: id, name, icon, description (short_desc).
        // Full row data is fetched separately via GET /admin/services/{id} when editing.
        $rows = $wpdb->get_results(
            "SELECT s.id, s.category_id, s.slug, s.name, s.name_hi, s.icon,
                    s.short_desc, s.short_desc AS description,
                    s.pricing_model, s.base_price, s.price_min, s.price_max,
                    s.turnaround_days, s.image_url, s.is_active, s.public_status, s.availability, s.sort_order,
                    s.seo_title, s.seo_desc, s.created_at, s.updated_at,
                    COALESCE(c.name, '(No Category)') AS category_name,
                    COALESCE(c.color, '#4A6FA5') AS category_color,
                    COALESCE(c.icon, '📁') AS category_icon,
                    (SELECT COUNT(*) FROM `{$wpdb->prefix}s2nri_form_fields` ff
                     WHERE ff.service_id = s.id AND ff.is_active = 1) AS form_field_count
             FROM {$wpdb->prefix}s2nri_services s
             LEFT JOIN {$wpdb->prefix}s2nri_categories c ON c.id = s.category_id
             ORDER BY COALESCE(c.sort_order, 99), s.sort_order",
            ARRAY_A
        );
        if ( $wpdb->last_error ) {
            error_log( '[S2NRI] ServiceAdminController::index() SQL error: ' . $wpdb->last_error );
        }
        $total_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}s2nri_services`" );
        $active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}s2nri_services` WHERE is_active = 1" );
        Response::json( [ 'services' => $rows ?: [], 'total' => $total_count, 'active_count' => $active_count ] );
    }

    /**
     * GET /admin/services/{id}
     * Returns the FULL service row including hero_settings, marquee_settings,
     * qualification_schema, form_schema, description, required_docs.
     * Used by the builder SPA for Hero Settings and Marquee editor.
     */
    public function show( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $service = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*,
                    COALESCE(c.name, '(No Category)') AS category_name,
                    COALESCE(c.color, '#4A6FA5') AS category_color,
                    COALESCE(c.icon, '📁') AS category_icon
             FROM `{$wpdb->prefix}s2nri_services` s
             LEFT JOIN `{$wpdb->prefix}s2nri_categories` c ON c.id = s.category_id
             WHERE s.id = %d
             LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $service ) {
            Response::json( [ 'error' => 'Service not found.' ], 404 );
            return;
        }

        // Decode JSON fields
        foreach ( [ 'hero_settings', 'marquee_settings', 'qualification_schema', 'form_schema', 'required_docs' ] as $col ) {
            if ( isset( $service[ $col ] ) && is_string( $service[ $col ] ) && strlen( $service[ $col ] ) > 0 ) {
                $decoded = json_decode( $service[ $col ], true );
                if ( json_last_error() === JSON_ERROR_NONE ) {
                    $service[ $col ] = $decoded;
                }
            }
        }

        // CRITICAL: If this service has rows in s2nri_form_fields, those are the AUTHORITATIVE
        // source for the booking form (the public API ignores form_schema JSON when db_fields exist).
        // Return the db_fields as form_schema so the admin textarea shows what actually appears
        // on the service page — not a stale JSON blob that has no effect.
        $db_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_key AS `key`, label, field_type AS `type`, step, required,
                    placeholder, help_text AS description, options, conditions, is_active, sort_order
             FROM `{$wpdb->prefix}s2nri_form_fields`
             WHERE service_id = %d
             ORDER BY sort_order ASC, id ASC",
            $id
        ), ARRAY_A );

        if ( ! empty( $db_fields ) ) {
            // Build the same structure the public API returns, so admin sees exactly
            // what the booking form renders.
            $schema = [];
            foreach ( $db_fields as $f ) {
                $opts       = $f['options']    ? ( json_decode( $f['options'],    true ) ?: [] ) : [];
                $conditions = $f['conditions'] ? json_decode( $f['conditions'],   true ) : null;
                $schema[]   = [
                    'key'         => $f['key'],
                    'label'       => $f['label'],
                    'type'        => $f['type'],
                    'required'    => (bool) $f['required'],
                    'is_active'   => (bool) $f['is_active'],
                    'placeholder' => $f['placeholder'] ?: '',
                    'description' => $f['description'] ?: '',
                    'options'     => $opts,
                    'conditions'  => $conditions,
                    'step'        => (int) $f['step'],
                    'sort_order'  => (int) $f['sort_order'],
                ];
            }
            $service['form_schema']        = $schema;
            $service['using_form_builder'] = true; // flag so React can show notice
        } else {
            $service['using_form_builder'] = false;
        }

        Response::json( [ 'service' => $service ] );
    }

    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        $name        = sanitize_text_field( $req->input( 'name', '' ) );
        $category_id = (int) $req->input( 'category_id', 0 );
        if ( ! $name || ! $category_id ) {
            Response::json( [ 'error' => 'Name and category are required.' ], 422 ); return;
        }

        // required_docs: frontend sends array — empty array must be '[]' not NULL
        $raw_docs     = $req->input( 'required_docs', [] );
        $required_docs = is_array( $raw_docs ) ? wp_json_encode( $raw_docs )
                       : ( is_string( $raw_docs ) && strlen( trim( $raw_docs ) ) ? $raw_docs : '[]' );

        // form_schema: array from JSON body or string
        $raw_schema  = $req->input( 'form_schema', null );
        $form_schema = null;
        if ( is_array( $raw_schema ) && ! empty( $raw_schema ) ) {
            $form_schema = wp_json_encode( $raw_schema );
        } elseif ( is_string( $raw_schema ) && strlen( trim( $raw_schema ) ) > 0 ) {
            $decoded = json_decode( $raw_schema, true );
            if ( json_last_error() === JSON_ERROR_NONE ) $form_schema = wp_json_encode( $decoded );
        }

        $insert = [
            'category_id'    => $category_id,
            'name'           => $name,
            'name_hi'        => sanitize_text_field( $req->input( 'name_hi', '' ) ),
            'slug'           => sanitize_title( $req->input( 'slug', $name ) ),
            'icon'           => sanitize_text_field( $req->input( 'icon', '📋' ) ),
            'short_desc'     => sanitize_text_field( $req->input( 'short_desc', '' ) ),
            'description'    => wp_kses_post( $req->input( 'description', '' ) ),
            'seo_desc'       => sanitize_text_field( $req->input( 'seo_desc', '' ) ),
            'image_url'      => esc_url_raw( $req->input( 'image_url', '' ) ),
            'pricing_model'  => sanitize_key( $req->input( 'pricing_model', 'quote' ) ),
            'base_price'     => $req->input( 'base_price' ) ? (float) $req->input( 'base_price' ) : null,
            'price_min'      => $req->input( 'price_min' )  ? (float) $req->input( 'price_min' )  : null,
            'price_max'      => $req->input( 'price_max' )  ? (float) $req->input( 'price_max' )  : null,
            'turnaround_days'=> max( 1, (int) $req->input( 'turnaround_days', 7 ) ),
            'required_docs'  => $required_docs,
            'form_schema'    => $form_schema,
            'is_active'      => 1,
            'sort_order'     => (int) $req->input( 'sort_order', 0 ),
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ];

        $svc_insert = $wpdb->insert( $p . 's2nri_services', $insert );
        // CHECKED (was previously unchecked): this one is worse than the
        // usual unchecked-insert pattern. $wpdb->insert_id does NOT reset
        // to 0 on failure — it holds MySQL's LAST_INSERT_ID() for the
        // connection, i.e. the last AUTO_INCREMENT value from ANY
        // successful insert earlier in this same request, on ANY table.
        // A silently failed insert here would previously fall through to
        // $new_service_id = a STALE id possibly belonging to a completely
        // unrelated row — and the code below then attaches 4-9 form
        // fields to that id, silently corrupting an unrelated service.
        if ( $svc_insert === false ) {
            error_log( '[S2NRI] Service insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to create service. Please try again.' ], 500 ); return;
        }
        $new_service_id = (int) $wpdb->insert_id;

        // ── Seed default form fields for the new service ──────────────────────
        // Every new service gets 4 essential contact fields automatically.
        // These appear on the booking form immediately — no manual setup needed.
        // Admin can add service-specific fields via the Form Builder.
        $default_fields = [
            [ 'key' => 'applicant_name',  'label' => 'Full Name',           'type' => 'text',     'step' => 98, 'required' => 1, 'placeholder' => 'Enter your full name as per passport', 'sort' => 10 ],
            [ 'key' => 'email',           'label' => 'Email Address',        'type' => 'email',    'step' => 98, 'required' => 1, 'placeholder' => 'Your email for booking updates',        'sort' => 20 ],
            [ 'key' => 'phone',           'label' => 'WhatsApp / Phone',     'type' => 'phone',    'step' => 98, 'required' => 1, 'placeholder' => 'Include country code e.g. +1 555 0123',  'sort' => 30 ],
            [ 'key' => 'country',         'label' => 'Country of Residence', 'type' => 'text',     'step' => 98, 'required' => 1, 'placeholder' => 'e.g. United States',                    'sort' => 40 ],
            [ 'key' => 'remarks',         'label' => 'Additional Remarks',   'type' => 'textarea', 'step' => 98, 'required' => 0, 'placeholder' => 'Any additional details or special requests', 'sort' => 50 ],
        ];
        foreach ( $default_fields as $df ) {
            if ( $wpdb->insert( $p . 's2nri_form_fields', [
                'service_id'  => $new_service_id,
                'field_key'   => $df['key'],
                'label'       => $df['label'],
                'field_type'  => $df['type'],
                'step'        => $df['step'],
                'required'    => $df['required'],
                'placeholder' => $df['placeholder'],
                'is_active'   => 1,
                'sort_order'  => $df['sort'],
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ] ) === false ) {
                error_log( '[S2NRI] Default field "' . $df['key'] . '" insert failed for new service ' . $new_service_id . ': ' . $wpdb->last_error );
                // Non-fatal — the service itself is already safely created
                // and checked above; a missing default field can be added
                // manually via the Form Builder.
            }
        }

        // Also sync form_schema into form_fields if admin submitted fields via JSON
        if ( $form_schema ) {
            $fs_arr = json_decode( $form_schema, true );
            if ( is_array( $fs_arr ) ) {
                $allowed_types = [ 'text','number','email','phone','textarea','select','dropdown','searchable','radio','checkbox','date','file' ];
                foreach ( $fs_arr as $i => $field ) {
                    $fkey = isset( $field['key'] ) ? sanitize_key( str_replace( '-', '_', (string) $field['key'] ) ) : '';
                    if ( ! $fkey ) continue;
                    // Skip if already seeded as default field
                    $existing_key = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM `{$p}s2nri_form_fields` WHERE service_id=%d AND field_key=%s LIMIT 1",
                        $new_service_id, $fkey
                    ) );
                    if ( $existing_key ) continue;
                    $ftype = sanitize_key( (string) ( $field['field_type'] ?? $field['type'] ?? 'text' ) );
                    if ( ! in_array( $ftype, $allowed_types, true ) ) $ftype = 'text';
                    if ( $wpdb->insert( $p . 's2nri_form_fields', [
                        'service_id'  => $new_service_id,
                        'field_key'   => $fkey,
                        'label'       => sanitize_text_field( (string) ( $field['label'] ?? $fkey ) ),
                        'field_type'  => $ftype,
                        'step'        => (int) ( $field['step'] ?? 1 ),
                        'required'    => (int) (bool) ( $field['required'] ?? false ),
                        'placeholder' => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
                        'is_active'   => 1,
                        'sort_order'  => $i * 10 + 100,
                    ] ) === false ) {
                        error_log( '[S2NRI] Custom field "' . $fkey . '" insert failed for new service ' . $new_service_id . ': ' . $wpdb->last_error );
                    }
                }
            }
        }

        \S2NRI\Services\CacheService::bustPattern( 'cats_' );

        // Return the builder URL so the admin portal can offer a direct link to add more fields
        $builder_url = rtrim( home_url( '/s2nri-builder' ), '/' ) . '?page=form-builder&service=' . $new_service_id;
        Response::json( [ 'success' => true, 'id' => $new_service_id, 'form_builder_url' => $builder_url ], 201 );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        $p  = $wpdb->prefix;

        // Load existing row so we can fall back to DB values for any field
        // the frontend did not include in the payload.
        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$p}s2nri_services` WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );
        if ( ! $existing ) {
            Response::json( [ 'error' => 'Service not found.' ], 404 );
            return;
        }

        // Read the raw request body once.  We use json_decode directly here
        // rather than $req->input() because PHP's ?? operator swallows explicit
        // null values (json null → PHP null → null ?? $default → $default).
        // array_key_exists() correctly distinguishes "sent as null" from "not sent".
        $raw  = file_get_contents( 'php://input' );
        $body = $raw ? ( json_decode( $raw, true ) ?? [] ) : [];

        // Helper: use sent value when key is present, existing DB value otherwise.
        $f = static function ( string $key ) use ( $body, $existing ) {
            return array_key_exists( $key, $body ) ? $body[ $key ] : $existing[ $key ];
        };

        // ── Scalar fields — always safe, never null after casting ────────────────
        $name      = sanitize_text_field( (string) ( $f('name')      ?: $existing['name'] ) );
        $name_hi   = sanitize_text_field( (string) ( $f('name_hi')   ?? $existing['name_hi'] ) );
        $icon      = sanitize_text_field( (string) ( $f('icon')      ?? $existing['icon'] ?? '📋' ) );
        $seo_title = sanitize_text_field( (string) ( $f('seo_title') ?? $existing['seo_title'] ?? '' ) );
        $slug_in   = $f('slug');
        $slug      = $slug_in ? sanitize_title( (string) $slug_in ) : (string) $existing['slug'];
        // short_desc: portal sends 'description' field (it only edits name/icon/description).
        // We sync: if 'description' was sent but 'short_desc' was not, use description for short_desc
        // so the list endpoint (which returns short_desc) reflects the portal save immediately.
        $raw_short_desc  = $f('short_desc');
        $raw_description = $f('description');
        if ( ! array_key_exists( 'short_desc', $body ) && array_key_exists( 'description', $body ) ) {
            // Portal save: description sent → use it as short_desc too
            $short_desc = sanitize_text_field( (string) mb_substr( $raw_description ?? $existing['short_desc'], 0, 255 ) );
        } else {
            $short_desc = sanitize_text_field( (string) ( $raw_short_desc ?? $existing['short_desc'] ) );
        }
        $description = wp_kses_post( (string) ( $raw_description ?? $existing['description'] ) );
        $seo_desc  = sanitize_text_field( (string) ( $f('seo_desc')  ?? $existing['seo_desc'] ) );
        $image_url = esc_url_raw( (string) ( $f('image_url')        ?? $existing['image_url'] ) );
        $pm        = sanitize_key( (string) ( $f('pricing_model')   ?? $existing['pricing_model'] ) );
        if ( ! in_array( $pm, [ 'quote', 'fixed', 'range' ], true ) ) $pm = 'quote';
        $cat_id    = (int) ( $f('category_id')     ?? $existing['category_id'] );
        $days      = max( 1, (int) ( $f('turnaround_days') ?? $existing['turnaround_days'] ) );
        $is_active = (int) ( $f('is_active')       ?? $existing['is_active'] );
        $sort      = (int) ( $f('sort_order')      ?? $existing['sort_order'] );
        $now       = current_time( 'mysql' );

        // ── required_docs (JSON column) — must never be '' or raw null ────────────
        $rd_raw = $f( 'required_docs' );
        if ( is_array( $rd_raw ) ) {
            $required_docs = wp_json_encode( $rd_raw ) ?: '[]';
        } elseif ( is_string( $rd_raw ) && strlen( trim( $rd_raw ) ) > 0 ) {
            $dec = json_decode( $rd_raw, true );
            $required_docs = ( json_last_error() === JSON_ERROR_NONE )
                ? ( wp_json_encode( $dec ) ?: '[]' ) : ( $existing['required_docs'] ?: '[]' );
        } else {
            $required_docs = $existing['required_docs'] ?: '[]';
        }

        // ── form_schema (JSON column, nullable) ────────────────────────────────────
        // Strategy: NULL literal in SQL when we want no value.
        //           Quoted JSON string when we have content.
        //           Nothing changed if input is empty and existing is already null.
        $fs_raw = $f( 'form_schema' );
        if ( is_array( $fs_raw ) && ! empty( $fs_raw ) ) {
            $form_schema_sql = $wpdb->prepare( '%s', wp_json_encode( $fs_raw ) );
        } elseif ( is_string( $fs_raw ) && strlen( trim( $fs_raw ) ) > 0 ) {
            $dec = json_decode( $fs_raw, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $form_schema_sql = $wpdb->prepare( '%s', wp_json_encode( $dec ) );
            } else {
                $form_schema_sql = 'NULL'; // invalid JSON → clear it
            }
        } else {
            // Empty input: keep existing value or NULL
            $form_schema_sql = ( $existing['form_schema'] !== null )
                ? $wpdb->prepare( '%s', $existing['form_schema'] )
                : 'NULL';
        }

        // ── Nullable DECIMAL columns ────────────────────────────────────────────────
        $make_decimal = static function ( $raw ) use ( $wpdb ) {
            if ( $raw === null || $raw === '' || $raw === 'null' ) return 'NULL';
            return $wpdb->prepare( '%s', number_format( (float) $raw, 2, '.', '' ) );
        };
        $bp  = array_key_exists( 'base_price', $body ) ? $make_decimal( $body['base_price'] ) : $make_decimal( $existing['base_price'] );
        $pmin= array_key_exists( 'price_min',  $body ) ? $make_decimal( $body['price_min'] )  : $make_decimal( $existing['price_min'] );
        $pmax= array_key_exists( 'price_max',  $body ) ? $make_decimal( $body['price_max'] )  : $make_decimal( $existing['price_max'] );

        // ── hero_settings (JSON column, nullable) ──────────────────────────────────
        $hero_raw = $f( 'hero_settings' );
        if ( is_array( $hero_raw ) ) {
            $hero_settings_sql = $wpdb->prepare( '%s', wp_json_encode( $hero_raw ) );
        } else {
            $hero_settings_sql = ( $existing['hero_settings'] ?? null ) !== null
                ? $wpdb->prepare( '%s', $existing['hero_settings'] )
                : 'NULL';
        }

        // ── marquee_settings (JSON column, nullable) ───────────────────────────────
        $marquee_raw = $f( 'marquee_settings' );
        if ( is_array( $marquee_raw ) ) {
            $marquee_settings_sql = $wpdb->prepare( '%s', wp_json_encode( $marquee_raw ) );
        } else {
            $marquee_settings_sql = ( $existing['marquee_settings'] ?? null ) !== null
                ? $wpdb->prepare( '%s', $existing['marquee_settings'] )
                : 'NULL';
        }

        // ── Single UPDATE — every field in one query, no wpdb->update() ─────────────
        // All scalar placeholders use wpdb->prepare() for safety.
        // Nullable columns (decimals, form_schema) are pre-rendered as SQL literals
        // so they never pass through the %s→'' null-conversion bug in wpdb->update().
        $sql = $wpdb->prepare(
            "UPDATE `{$p}s2nri_services` SET
                `name`            = %s,
                `name_hi`         = %s,
                `icon`            = %s,
                `slug`            = %s,
                `short_desc`      = %s,
                `description`     = %s,
                `seo_title`       = %s,
                `seo_desc`        = %s,
                `image_url`       = %s,
                `pricing_model`   = %s,
                `category_id`     = %d,
                `turnaround_days` = %d,
                `is_active`       = %d,
                `sort_order`      = %d,
                `required_docs`   = %s,
                `base_price`      = {$bp},
                `price_min`       = {$pmin},
                `price_max`       = {$pmax},
                `form_schema`     = {$form_schema_sql},
                `hero_settings`   = {$hero_settings_sql},
                `marquee_settings`= {$marquee_settings_sql},
                `updated_at`      = %s
            WHERE `id` = %d",
            $name, $name_hi, $icon, $slug, $short_desc,
            $description, $seo_title, $seo_desc, $image_url, $pm,
            $cat_id, $days, $is_active, $sort,
            $required_docs,
            $now,
            $id
        );

        $result = $wpdb->query( $sql );

        if ( $result === false ) {
            error_log( '[S2NRI] service update FAILED id=' . $id . ' sql=' . $wpdb->last_query . ' err=' . $wpdb->last_error );
            Response::json( [ 'error' => 'Database update failed: ' . $wpdb->last_error ], 500 );
            return;
        }

        // ── Sync form_schema JSON → s2nri_form_fields ─────────────────────────────
        // The public booking form reads from s2nri_form_fields (authoritative) when rows exist.
        // Saving form_schema to the services table alone has no effect on what customers see.
        // We must upsert each field from the submitted JSON into s2nri_form_fields.
        //
        // Strategy: upsert by field_key within this service.
        //   - Key exists in db   → UPDATE that row
        //   - Key absent from db → INSERT new row
        //   - Key absent from JSON but exists in db → LEAVE IT (don't delete; FormBuilder may own it)
        //
        // This makes the textarea a "sync from JSON" tool that works regardless of whether
        // the service was previously managed by FormBuilder.
        if ( array_key_exists( 'form_schema', $body ) ) {
            $fs_decoded = is_array( $fs_raw ) ? $fs_raw : ( $fs_raw ? json_decode( $fs_raw, true ) : null );
            if ( is_array( $fs_decoded ) && ! empty( $fs_decoded ) ) {
                $allowed_types = [ 'text','number','email','phone','textarea','select','dropdown','searchable','radio','checkbox','date','file' ];
                foreach ( $fs_decoded as $i => $field ) {
                    $fkey = isset( $field['key'] ) ? sanitize_key( str_replace( '-', '_', (string) $field['key'] ) ) : '';
                    if ( ! $fkey ) continue;
                    $ftype  = sanitize_key( (string) ( $field['field_type'] ?? $field['type'] ?? 'text' ) );
                    if ( ! in_array( $ftype, $allowed_types, true ) ) $ftype = 'text';
                    $flabel = sanitize_text_field( (string) ( $field['label'] ?? $fkey ) );
                    $fstep  = (int) ( $field['step'] ?? 1 );
                    $freq   = (int) (bool) ( $field['required'] ?? false );
                    $fph    = sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) );
                    $fhelp  = sanitize_textarea_field( (string) ( $field['description'] ?? $field['help_text'] ?? '' ) );
                    $fopts  = ( isset( $field['options'] ) && is_array( $field['options'] ) )
                              ? wp_json_encode( array_map( 'sanitize_text_field', $field['options'] ) )
                              : null;
                    $fcond  = isset( $field['conditions'] ) ? wp_json_encode( $field['conditions'] ) : null;
                    $factive= isset( $field['is_active'] ) ? (int)(bool)$field['is_active'] : 1;
                    $fsort  = isset( $field['sort_order'] ) ? (int)$field['sort_order'] : ( $i * 10 );

                    // Check if this field_key already exists for this service
                    $existing_fid = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM `{$p}s2nri_form_fields` WHERE service_id=%d AND field_key=%s LIMIT 1",
                        $id, $fkey
                    ) );

                    if ( $existing_fid ) {
                        if ( $wpdb->update( $p . 's2nri_form_fields', [
                            'label'       => $flabel,
                            'field_type'  => $ftype,
                            'step'        => $fstep,
                            'required'    => $freq,
                            'placeholder' => $fph,
                            'help_text'   => $fhelp,
                            'options'     => $fopts,
                            'conditions'  => $fcond,
                            'is_active'   => $factive,
                            'sort_order'  => $fsort,
                        ], [ 'id' => (int) $existing_fid ] ) === false ) {
                            error_log( '[S2NRI] Form field update failed for field_key "' . $fkey . '" on service ' . $id . ': ' . $wpdb->last_error );
                        }
                    } else {
                        if ( $wpdb->insert( $p . 's2nri_form_fields', [
                            'service_id'  => $id,
                            'field_key'   => $fkey,
                            'label'       => $flabel,
                            'field_type'  => $ftype,
                            'step'        => $fstep,
                            'required'    => $freq,
                            'placeholder' => $fph,
                            'help_text'   => $fhelp,
                            'options'     => $fopts,
                            'conditions'  => $fcond,
                            'is_active'   => $factive,
                            'sort_order'  => $fsort,
                        ] ) === false ) {
                            error_log( '[S2NRI] Form field insert failed for field_key "' . $fkey . '" on service ' . $id . ': ' . $wpdb->last_error );
                        }
                    }
                }
            }
        }

        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true ] );
    }

    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        // CHECKED (was previously unchecked): same ghost-success pattern
        // fixed throughout this file.
        if ( $wpdb->update( $wpdb->prefix . 's2nri_services', [ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to deactivate service.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true ] );
    }

    // TRACE: PATCH admin/services/{id}/toggle → flips is_active without touching any other field.
    //        Preconditions: requireManager(). Service exists.
    //        Postconditions: is_active flipped, cache busted, 200 returned with new value.
    //        CRITICAL: does NOT call sanitizeServiceData — only touches is_active column.
    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_active FROM {$wpdb->prefix}s2nri_services WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Service not found.' ], 404 ); return; }
        $new_val = (int) $row['is_active'] ? 0 : 1;  // cast to int — PHP returns TINYINT as string
        $public_status = $new_val ? 'published' : 'disabled';
        if ( $wpdb->update( $wpdb->prefix . 's2nri_services',
            [
                'is_active'      => $new_val,
                'public_status'  => $public_status,
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        ) === false ) {
            Response::json( [ 'error' => 'Failed to toggle service status.' ], 500 ); return;
        }
        \S2NRI\Services\ServiceRegistry::bustCache();
        Response::json( [ 'success' => true, 'is_active' => $new_val, 'public_status' => $public_status ] );
    }

    private function sanitizeServiceData( Request $req ): array {
        // Build the base update data — only include fields that were actually submitted
        $data = [
            'category_id'    => (int) $req->input( 'category_id', 0 ),
            'name'           => sanitize_text_field( $req->input( 'name', '' ) ),
            'name_hi'        => sanitize_text_field( $req->input( 'name_hi', '' ) ),
            'slug'           => sanitize_title( $req->input( 'slug', $req->input( 'name', '' ) ) ),
            'icon'           => sanitize_text_field( $req->input( 'icon', '📋' ) ),
            'short_desc'     => sanitize_text_field( $req->input( 'short_desc', '' ) ),
            'description'    => wp_kses_post( $req->input( 'description', '' ) ),
            'seo_desc'       => sanitize_text_field( $req->input( 'seo_desc', '' ) ),
            'image_url'      => esc_url_raw( $req->input( 'image_url', '' ) ),
            'pricing_model'  => sanitize_key( $req->input( 'pricing_model', 'quote' ) ),
            'base_price'     => $req->input( 'base_price' ) ? (float) $req->input( 'base_price' ) : null,
            'price_min'      => $req->input( 'price_min' ) ? (float) $req->input( 'price_min' ) : null,
            'price_max'      => $req->input( 'price_max' ) ? (float) $req->input( 'price_max' ) : null,
            'turnaround_days'=> max( 1, (int) $req->input( 'turnaround_days', 7 ) ),
            'required_docs'  => $req->input( 'required_docs' ) ? wp_json_encode( $req->input( 'required_docs' ) ) : null,
            'is_active'      => (int) $req->input( 'is_active', 1 ),
            'sort_order'     => (int) $req->input( 'sort_order', 0 ),
        ];

        // form_schema: only save if explicitly submitted — never wipe existing schema with null
        $form_schema_raw = $req->input( 'form_schema', null );
        if ( $form_schema_raw !== null ) {
            if ( is_string( $form_schema_raw ) && strlen( trim( $form_schema_raw ) ) > 0 ) {
                $decoded = json_decode( $form_schema_raw, true );
                $data['form_schema'] = ( json_last_error() === JSON_ERROR_NONE ) ? wp_json_encode( $decoded ) : null;
            } elseif ( is_array( $form_schema_raw ) ) {
                $data['form_schema'] = wp_json_encode( $form_schema_raw );
            }
            // If form_schema_raw is empty string — do not include form_schema in update (preserve DB value)
        }

        return $data;
    }

    // TRACE: uploadImage() → POST admin/services/{id}/image → media_handle_upload → store URL.
    public function uploadImage( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( empty( $_FILES['file'] ) ) { Response::json( [ 'error' => 'No file uploaded.' ], 422 ); return; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $att_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $att_id ) ) { Response::json( [ 'error' => $att_id->get_error_message() ], 500 ); return; }
        $url = wp_get_attachment_url( $att_id );
        global $wpdb;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_services', [ 'image_url' => $url, 'updated_at' => current_time('mysql') ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Image uploaded but failed to save. Please try again.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true, 'url' => $url ] );
    }

    // TRACE: deleteImage() → DELETE admin/services/{id}/image → clears image_url.
    public function deleteImage( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        global $wpdb;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_services', [ 'image_url' => '', 'updated_at' => current_time('mysql') ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to remove image.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true ] );
    }

    // POST admin/services/reseed — seeds missing services AND fixes orphaned category_id=0
    public function reseed( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p = $wpdb->prefix;

        // Step 1: Seed categories first (per-slug guard — safe on existing installs).
        // This ensures our 8 canonical slugs exist so seedServices can map category_ids.
        \S2NRI\Installer::seedCategories();

        // Step 2: Seed any missing services (per-slug guard — safe to always run)
        \S2NRI\Installer::seedServices();

        // Step 3: Seed missing blog posts
        \S2NRI\Installer::seedBlogPosts();

        // Step 4: Push qualification schemas for all 44 services to live DB
        \S2NRI\Installer::updateAllQualSchemas();

        // Step 2: Fix services with category_id = 0 (seeded when live category slugs differed)
        // Build a name-based map: keyword in category name → category id
        $live_cats = $wpdb->get_results( "SELECT id, slug, name FROM `{$p}s2nri_categories`", ARRAY_A );

        // Map our canonical slugs to keyword patterns to match against live category names
        $keyword_map = [
            'documentation' => [ 'document', 'doc', 'certificate', 'attestation' ],
            'education'     => [ 'education', 'academic', 'degree', 'university' ],
            'immigration'   => [ 'immigration', 'oci', 'passport', 'visa' ],
            'uscis'         => [ 'uscis', 'green card', 'citizenship', 'immigration' ],
            'property'      => [ 'property', 'real estate', 'tenant', 'rent' ],
            'financial'     => [ 'financial', 'finance', 'banking', 'tax', 'account' ],
            'legal'         => [ 'legal', 'law', 'attorney', 'court' ],
            'tax'           => [ 'tax', 'itr', 'gst', 'tds', 'income' ],
        ];

        // Build live slug → id and live name-keyword → id
        $live_slug_to_id = [];
        $live_keyword_to_id = [];
        foreach ( $live_cats as $cat ) {
            $live_slug_to_id[ $cat['slug'] ] = (int) $cat['id'];
            foreach ( $keyword_map as $canonical => $keywords ) {
                foreach ( $keywords as $kw ) {
                    if ( stripos( $cat['name'], $kw ) !== false || stripos( $cat['slug'], $kw ) !== false ) {
                        if ( ! isset( $live_keyword_to_id[ $canonical ] ) ) {
                            $live_keyword_to_id[ $canonical ] = (int) $cat['id'];
                        }
                    }
                }
            }
        }

        // Service slug → which canonical category it belongs to
        $service_cat_map = [
            'birth-certificate'          => 'documentation', 'nabc'                => 'documentation',
            'apostille'                  => 'documentation', 'affidavit'           => 'documentation',
            'hrd-attestation'            => 'documentation', 'police-clearance'    => 'documentation',
            'single-status-certificate'  => 'documentation', 'translation-services'=> 'documentation',
            'university-transcript'      => 'education',     'degree-certificate'  => 'education',
            'duplicate-marksheet'        => 'education',     'moi'                 => 'education',
            'migration-certificate'      => 'education',     'character-certificate'=> 'education',
            'oci-card-new'               => 'immigration',   'oci-card-renewal'    => 'immigration',
            'oci-card-update'            => 'immigration',   'indian-passport-renewal' => 'immigration',
            'india-visa'                 => 'immigration',   'overseas-visa-assistance'=> 'immigration',
            'ead-assistance'             => 'uscis',         'green-card-assistance'=> 'uscis',
            'green-card-renewal'         => 'uscis',         'us-citizenship'      => 'uscis',
            'complete-property-management'=> 'property',     'property-inspection' => 'property',
            'tenancy-management'         => 'property',      'encumbrance-certificate'=> 'property',
            'property-title-search'      => 'property',      'housekeeping-services'=> 'property',
            'rent-agreement'             => 'property',      'rent-collection'     => 'property',
            'financial-planning'         => 'financial',     'nre-nro-account'     => 'financial',
            'epf-pf-withdrawal'          => 'financial',     'pan-card'            => 'financial',
            'bill-payment'               => 'financial',
            'power-of-attorney'          => 'legal',         'will-drafting'       => 'legal',
            'legal-notice'               => 'legal',         'legal-advisory'      => 'legal',
            'itr-filing'                 => 'tax',           'tds-refund'          => 'tax',
            'form-15ca-15cb'             => 'tax',           'gst-registration'    => 'tax',
        ];

        // Fix orphaned services (category_id = 0 or missing category)
        $fixed = 0;
        $orphans = $wpdb->get_results(
            "SELECT id, slug FROM `{$p}s2nri_services` WHERE category_id = 0 OR category_id NOT IN (SELECT id FROM `{$p}s2nri_categories`)",
            ARRAY_A
        );
        foreach ( $orphans as $svc ) {
            $canonical = $service_cat_map[ $svc['slug'] ] ?? null;
            if ( ! $canonical ) continue;

            // Try live slug first, then keyword match
            $cat_id = $live_slug_to_id[ $canonical ] ?? $live_keyword_to_id[ $canonical ] ?? 0;
            if ( $cat_id ) {
                if ( $wpdb->update( "{$p}s2nri_services", [ 'category_id' => $cat_id ], [ 'id' => $svc['id'] ] ) === false ) {
                    error_log( '[S2NRI] Category reassignment failed for service ' . $svc['id'] . ': ' . $wpdb->last_error );
                } else {
                    $fixed++;
                }
            }
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$p}s2nri_services`" );
        $active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$p}s2nri_services` WHERE is_active = 1" );

        Response::json( [
            'success'        => true,
            'total_services' => $total,
            'active_services'=> $active,
            'orphans_fixed'  => $fixed,
        ] );
    }

}

// ══════════════════════════════════════════════════════════════════════════════
// CategoryAdminController
// ══════════════════════════════════════════════════════════════════════════════

class CategoryAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT c.*, COUNT(s.id) AS service_count
             FROM {$wpdb->prefix}s2nri_categories c
             LEFT JOIN {$wpdb->prefix}s2nri_services s ON s.category_id = c.id AND s.is_active = 1
             GROUP BY c.id ORDER BY c.sort_order",
            ARRAY_A
        );
        Response::json( [ 'categories' => $rows ] );
    }

    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $name = sanitize_text_field( $req->input( 'name', '' ) );
        $slug = sanitize_title( $req->input( 'slug', $name ) );
        if ( ! $name ) { Response::json( [ 'error' => 'Name required.' ], 422 ); return; }
        $cat_insert = $wpdb->insert( $wpdb->prefix . 's2nri_categories', [
            'slug'       => $slug, 'name' => $name,
            'name_hi'    => sanitize_text_field( $req->input( 'name_hi', '' ) ),
            'icon'       => sanitize_text_field( $req->input( 'icon', '📋' ) ),
            'color'      => sanitize_hex_color( $req->input( 'color', '#4A6FA5' ) ) ?: '#4A6FA5',
            'sort_order' => (int) $req->input( 'sort_order', 0 ),
            'is_active'  => 1, 'created_at' => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed elsewhere in this codebase — insert_id does not
        // reset to 0 on failure.
        if ( $cat_insert === false ) { Response::json( [ 'error' => 'Failed to create category.' ], 500 ); return; }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ], 201 );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        // Build update array — do NOT use array_filter (it strips sort_order=0, is_active=0)
        $update = [];
        if ( $req->input('name')       !== null ) $update['name']       = sanitize_text_field( $req->input( 'name' ) );
        if ( $req->input('name_hi')    !== null ) $update['name_hi']    = sanitize_text_field( $req->input( 'name_hi' ) );
        if ( $req->input('icon')       !== null ) $update['icon']       = sanitize_text_field( $req->input( 'icon' ) );
        if ( $req->input('color')      !== null ) $update['color']      = sanitize_hex_color( $req->input( 'color' ) ) ?: '#4A6FA5';
        if ( $req->input('seo_desc')   !== null ) $update['seo_desc']   = sanitize_text_field( $req->input( 'seo_desc' ) );
        if ( $req->input('image_url')  !== null ) $update['image_url']  = esc_url_raw( $req->input( 'image_url' ) );
        // Accept both display_order (frontend) and sort_order (legacy)
        $order_val = $req->input('sort_order') ?? $req->input('display_order');
        if ( $order_val !== null ) $update['sort_order'] = (int) $order_val;

        if ( ! empty( $update ) ) {
            // CHECKED (was previously unchecked): a failed update here
            // previously still returned success:true with no visible error,
            // and the admin would see stale values persist after refresh
            // with no indication why.
            if ( $wpdb->update( $wpdb->prefix . 's2nri_categories', $update, [ 'id' => $id ] ) === false ) {
                Response::json( [ 'error' => 'Failed to update category.' ], 500 ); return;
            }
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true ] );
    }

    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        // Soft delete — set is_active=0
        if ( $wpdb->update( $wpdb->prefix . 's2nri_categories', [ 'is_active' => 0 ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete category.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true ] );
    }

    public function uploadImage( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( empty( $_FILES['file'] ) ) { Response::json( [ 'error' => 'No file uploaded.' ], 422 ); return; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $att_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $att_id ) ) { Response::json( [ 'error' => $att_id->get_error_message() ], 500 ); return; }
        $url = wp_get_attachment_url( $att_id );
        global $wpdb;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_categories', [ 'image_url' => $url ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Image uploaded but failed to save.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cats_' );
        Response::json( [ 'success' => true, 'url' => $url ] );
    }

    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id  = (int) $req->param( 'id' );
        $cat = $wpdb->get_row( $wpdb->prepare( "SELECT is_active FROM {$wpdb->prefix}s2nri_categories WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        if ( ! $cat ) { Response::json( [ 'error' => 'Not found.' ], 404 ); return; }
        $new_active = (int) $cat['is_active'] ? 0 : 1;  // cast to int — PHP returns TINYINT as string
        $pub = $new_active ? 'published' : 'disabled';
        $upd = [ 'is_active' => $new_active ];
        if ( \S2NRI\Services\ServiceRegistry::schemaReady() ) {
            $upd['public_status'] = $pub;
        }
        if ( $wpdb->update( $wpdb->prefix . 's2nri_categories', $upd, [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to toggle category status.' ], 500 ); return;
        }
        \S2NRI\Services\ServiceRegistry::bustCache();
        Response::json( [ 'success' => true, 'public_status' => $pub ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DocumentAdminController
// ══════════════════════════════════════════════════════════════════════════════

class DocumentAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function upload( Request $req ): void {
        $this->requireStaff();
        $id       = (int) $req->param( 'id' );
        $doc_type = sanitize_text_field( $req->input( 'doc_type', 'Processed Document' ) );
        $visible  = (int) $req->input( 'visible_to_customer', 1 );
        $notes    = sanitize_textarea_field( $req->input( 'notes', '' ) );

        if ( empty( $_FILES['file'] ) ) { Response::json( [ 'error' => 'No file.' ], 422 ); return; }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $att_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $att_id ) ) { Response::json( [ 'error' => $att_id->get_error_message() ], 500 ); return; }

        $url = wp_get_attachment_url( $att_id );
        global $wpdb;
        $doc_insert = $wpdb->insert( $wpdb->prefix . 's2nri_documents', [
            'booking_id'            => $id,
            'uploaded_by'           => $this->user['wp_id'],
            'doc_type'              => $doc_type,
            'file_name'             => $_FILES['file']['name'],
            'file_url'              => $url,
            'file_size'             => $_FILES['file']['size'],
            'is_from_staff'         => 1,
            'is_visible_to_customer'=> $visible,
            'notes'                 => $notes,
            'created_at'            => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same severity class as the
        // customer-facing document upload fixed earlier this session —
        // by this point media_handle_upload() already succeeded, so the
        // file physically exists in the WP media library. A failed
        // insert here would leave it with no record linking it to the
        // booking, invisible to the customer and staff alike.
        if ( $doc_insert === false ) {
            error_log( '[S2NRI] Staff document insert failed for booking ' . $id . ' (file already uploaded to media library, attachment ' . $att_id . '): ' . $wpdb->last_error );
            Response::json( [ 'error' => 'File uploaded but failed to save. Please try again.' ], 500 ); return;
        }

        Response::json( [ 'success' => true, 'url' => $url ] );
    }

    public function delete( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $doc_id = (int) $req->param( 'doc_id' );
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_documents', [ 'id' => $doc_id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete document.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AnalyticsController
// ══════════════════════════════════════════════════════════════════════════════

class AnalyticsController extends \S2NRI\Api\Controllers\BaseController {

    public function dashboard( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;

        $today    = current_time( 'Y-m-d' );
        $month_s  = date( 'Y-m-01', strtotime( $today ) );
        $prev_m_s = date( 'Y-m-01', strtotime( '-1 month', strtotime( $month_s ) ) );
        $prev_m_e = date( 'Y-m-t', strtotime( '-1 month', strtotime( $month_s ) ) );

        $stats = [
            'total_bookings'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings" ),
            'bookings_today'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE DATE(created_at) = %s", $today ) ),
            'bookings_this_month'=> (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE created_at >= %s", $month_s ) ),
            'open_bookings'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status NOT IN ('completed','cancelled')" ),
            'completed_bookings' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status = 'completed'" ),
            'pending_quotes'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE status = 'under_review'" ),
            'pending_payments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_payments WHERE status = 'pending'" ),
            'total_customers'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_customers" ),
            'revenue_this_month' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$p}s2nri_payments WHERE status = 'verified' AND created_at >= %s", $month_s ) ),
            'revenue_prev_month' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$p}s2nri_payments WHERE status = 'verified' AND created_at BETWEEN %s AND %s", $prev_m_s, $prev_m_e . ' 23:59:59' ) ),
            'open_tickets'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_tickets WHERE status IN ('open','in_progress')" ),
        ];

        // Bookings by status
        $by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) AS count FROM {$p}s2nri_bookings GROUP BY status", ARRAY_A
        );

        // Top categories
        $top_cats = $wpdb->get_results(
            "SELECT c.name, COUNT(b.id) AS count
             FROM {$p}s2nri_bookings b LEFT JOIN {$p}s2nri_categories c ON c.id = b.category_id
             GROUP BY c.id ORDER BY count DESC LIMIT 5",
            ARRAY_A
        );

        // Recent bookings
        $recent = $wpdb->get_results(
            "SELECT b.id, b.booking_ref, b.status, b.created_at,
                    COALESCE(u.display_name, u.user_login, 'Unknown') AS customer_name,
                    s.name AS service_name
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             ORDER BY b.created_at DESC LIMIT 10",
            ARRAY_A
        );

        // 30-day daily trends — 2 aggregate queries instead of 60 individual queries (N+1 fix)
        $daily_b_raw = $wpdb->get_results(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {$p}s2nri_bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY d",
            ARRAY_A
        ) ?: [];
        $daily_r_raw = $wpdb->get_results(
            "SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS n FROM {$p}s2nri_payments WHERE status = 'verified' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY d",
            ARRAY_A
        ) ?: [];
        // Index by date for O(1) lookup
        $b_map = []; foreach ( $daily_b_raw as $r ) $b_map[ $r['d'] ] = (int) $r['n'];
        $r_map = []; foreach ( $daily_r_raw as $r ) $r_map[ $r['d'] ] = (float) $r['n'];
        $daily_b = []; $daily_r = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $d = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $daily_b[] = $b_map[ $d ] ?? 0;
            $daily_r[] = $r_map[ $d ] ?? 0.0;
        }
        $cat_chart = $wpdb->get_results( "SELECT c.name AS label, COUNT(b.id) AS value FROM {$p}s2nri_bookings b LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY c.id ORDER BY value DESC LIMIT 6", ARRAY_A ) ?: [];
        $stats['total_bookings_month'] = array_sum( $daily_b );
        $trends = [ 'bookings_daily' => $daily_b, 'revenue_daily' => $daily_r, 'by_category' => $cat_chart ];
        Response::json( compact( 'stats', 'by_status', 'top_cats', 'recent', 'trends' ) );
    }

    public function revenue( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p     = $wpdb->prefix;
        $months= (int) $req->query( 'months', 6 );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month, COALESCE(SUM(amount),0) AS revenue, COUNT(*) AS payments
             FROM {$p}s2nri_payments WHERE status = 'verified' AND created_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)
             GROUP BY month ORDER BY month ASC",
            $months
        ), ARRAY_A );
        Response::json( [ 'revenue' => $rows ] );
    }

    public function bookings( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = (int) $req->query( 'days', 30 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS date, COUNT(*) AS count
             FROM {$p}s2nri_bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY date ORDER BY date ASC",
            $days
        ), ARRAY_A );
        Response::json( [ 'bookings' => $rows ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SettingsAdminController
// ══════════════════════════════════════════════════════════════════════════════

class SettingsAdminController extends \S2NRI\Api\Controllers\BaseController {

    /** Preserve JSON and multiline content; single-line fields stay sanitized. */
    private static function sanitizeSettingValue( string $key, string $value ): string {
        if ( str_ends_with( $key, '_json' ) || str_contains( $key, 'custom_css' ) ) {
            return sanitize_textarea_field( $value );
        }
        $multiline_keys = [
            'hero_description', 'about_text', 'about_text_secondary', 'home_notice_text',
            'newsletter_subtitle', 'app_subtitle', 'contact_address', 'faq_cta_body',
            'pricing_grid_subtitle', 'pricing_compare_subtitle',
        ];
        if ( in_array( $key, $multiline_keys, true ) ) {
            return sanitize_textarea_field( $value );
        }
        return sanitize_text_field( $value );
    }

    public function get( Request $req ): void {
        $this->requireManager();
        $all  = \S2NRI\Models\Setting::getAll();
        $flat = [];
        foreach ( $all as $key => $entry ) {
            if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
                $flat[ $key ] = (string) $entry['value'];
            } else {
                $flat[ $key ] = is_scalar( $entry ) ? (string) $entry : '';
            }
        }
        Response::json( [
            'settings'      => $all,
            'settings_flat' => $flat,
        ] );
    }

    // TRACE: PUT admin/settings → validates keys → batch REPLACE INTO → bust cache.
    //        Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic batch update in 1 query per key.
    //        N settings → N queries maximum (vs 2N for the old SELECT+UPDATE pattern).
    //        Preconditions: requireManager() already verified.
    //        Postconditions: all valid keys updated, cache busted, 200 returned.
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $t    = $wpdb->prefix . 's2nri_settings';
        $data = $req->body();

        if ( ! is_array( $data ) || empty( $data ) ) {
            Response::json( [ 'error' => 'No settings data provided.' ], 422 ); return;
        }

        // Keys that should be publicly visible to the frontend (is_public=1)
        // These are read by app.js / SEO.php / Bootstrap::getJsConfig()
        $public_keys = [
            'platform_name', 'platform_tagline', 'platform_email', 'platform_phone',
            'platform_whatsapp', 'platform_logo_url', 'platform_address', 'platform_city',
            'platform_us_phone', 'primary_color', 'accent_color',
            'hero_banners', 'hero_heading_1', 'hero_heading_2', 'hero_subheading',
            'hero_description', 'hero_show', 'hero_title', 'hero_subtitle',
            'hero_cta_text', 'hero_cta_url', 'hero_cta2_text', 'hero_cta2_url',
            'hero_image_url', 'hero_overlay_color', 'hero_overlay_opacity',
            'hero_show_stats', 'hero_stat1_value', 'hero_stat1_label',
            'hero_stat2_value', 'hero_stat2_label', 'hero_stat3_value', 'hero_stat3_label',
            'marquee_show', 'marquee_text', 'marquee_speed', 'marquee_bg',
            'marquee_color', 'marquee_pause_hover', 'home_tagline',
            'stat_1_number', 'stat_1_label', 'stat_2_number', 'stat_2_label',
            'stat_3_number', 'stat_3_label', 'stat_4_number', 'stat_4_label',
            'about_heading', 'about_text', 'about_video_url', 'about_image_url',
            'about_show', 'about_show_video',
            'services_show', 'services_title', 'services_subtitle',
            'services_show_search', 'services_show_category_filter', 'services_per_page',
            'hiw_show', 'hiw_title', 'hiw_step1_title', 'hiw_step1_desc',
            'hiw_step2_title', 'hiw_step2_desc', 'hiw_step3_title', 'hiw_step3_desc',
            'hiw_step4_title', 'hiw_step4_desc',
            'hiw_step5_title', 'hiw_step5_desc', 'hiw_step6_title', 'hiw_step6_desc',
            'hiw_eyebrow', 'hiw_footer_link_text',
            'testimonials_show', 'testimonials_title',
            'contact_show', 'contact_title', 'contact_email', 'contact_phone',
            'contact_address', 'footer_copyright',
            'social_facebook', 'social_twitter', 'social_instagram',
            'social_youtube', 'social_linkedin',
            'show_blog', 'show_pricing', 'show_cities', 'show_faq',
            'show_login', 'show_dashboard', 'show_whatsapp_cta',
            'google_rating', 'google_review_count',
            'seo_title', 'seo_description', 'og_image_url',
            'ga_id', 'gtm_id', 'google_site_verification',
            'google_analytics_id', 'facebook_pixel_id',
            'bank_upi', 'razorpay_enabled', 'quote_validity_days',
            'app_playstore_url', 'app_appstore_url',
            'home_hero_title', 'home_hero_subtitle',
            'home_search_title', 'home_search_subtitle', 'home_search_placeholder', 'home_search_button',
            'features_eyebrow', 'features_title',
            'services_eyebrow',
            'testimonials_eyebrow',
            'faq_section_eyebrow', 'faq_section_title',
            'newsletter_title', 'newsletter_subtitle', 'newsletter_placeholder', 'newsletter_button',
            'app_eyebrow', 'app_title', 'app_subtitle',
            'home_notice_text', 'home_notice_whatsapp_label',
            'home_why_choose_json', 'home_faq_json', 'home_press_json', 'home_press_label',
            'home_partners_json', 'home_partners_label', 'home_awards_json', 'home_awards_label',
            'home_locations_label', 'home_section_order_json', 'public_page_section_orders_json',
            'services_view_all_text', 'services_page_title', 'services_page_subtitle',
            'cities_section_title', 'cities_section_subtitle', 'cities_card_eyebrow',
            'about_eyebrow', 'about_text_secondary', 'about_cta_text', 'about_whatsapp_cta',
            'faq_footer_link_text',
            'about_page_title', 'about_page_subtitle', 'about_values_title', 'about_values_json',
            'about_team_title', 'about_team_json', 'about_cta_title', 'about_cta_subtitle',
            'contact_page_title', 'contact_page_subtitle', 'contact_form_title',
            'hiw_page_title', 'hiw_page_subtitle', 'hiw_page_steps_json', 'hiw_page_cta_title', 'hiw_page_cta_subtitle',
            'faq_page_title', 'faq_page_subtitle', 'faq_cta_title', 'faq_cta_body', 'faq_cta_button',
            'pricing_page_title', 'pricing_page_subtitle',
            'pricing_grid_eyebrow', 'pricing_grid_title', 'pricing_grid_subtitle',
            'pricing_compare_title', 'pricing_compare_subtitle',
            'about_highlights_json',
            // CSS injection keys — read by SEO.php to inject <style> into every page
            'custom_css_homepage', 'custom_css_global',
            // Individual CSS override keys written by the Homepage Page Builder
            'css_hero_minheight', 'css_hero_textcolor', 'css_hero_bg', 'css_hero_padding',
            'css_hero_padding_desktop', 'css_hero_padding_tablet', 'css_hero_padding_mobile',
            'css_notice_bg', 'css_notice_color', 'css_notice_padding',
            'css_notice_padding_desktop', 'css_notice_padding_tablet', 'css_notice_padding_mobile',
            'css_search_bg', 'css_search_padding',
            'css_search_padding_desktop', 'css_search_padding_tablet', 'css_search_padding_mobile',
            'css_features_bg', 'css_features_padding',
            'css_features_padding_desktop', 'css_features_padding_tablet', 'css_features_padding_mobile',
            'css_cities_bg', 'css_cities_padding',
            'css_cities_padding_desktop', 'css_cities_padding_tablet', 'css_cities_padding_mobile',
            'css_stats_bg', 'css_stats_color', 'css_stats_padding',
            'css_stats_padding_desktop', 'css_stats_padding_tablet', 'css_stats_padding_mobile',
            'css_tagline_bg', 'css_tagline_color', 'css_tagline_size',
            'css_testimonials_bg', 'css_testimonials_padding',
            'css_testimonials_padding_desktop', 'css_testimonials_padding_tablet', 'css_testimonials_padding_mobile',
            'css_svc_cols', 'css_svc_bg', 'css_svc_card_bg', 'css_svc_gap',
            'css_how_bg', 'css_how_cols', 'css_how_gap',
            'css_press_bg', 'css_partners_bg', 'css_about_bg', 'css_about_padding',
            'css_about_padding_desktop', 'css_about_padding_tablet', 'css_about_padding_mobile',
            'css_awards_bg', 'css_faq_bg', 'css_newsletter_bg', 'css_newsletter_padding', 'css_app_bg',
            'css_newsletter_padding_desktop', 'css_newsletter_padding_tablet', 'css_newsletter_padding_mobile',
            'css_global_font', 'css_global_radius', 'css_global_maxw',
            // Section visibility flags
            'hide_section_hero', 'hide_section_notice', 'hide_section_search', 'hide_section_features',
            'hide_section_stats', 'hide_section_tagline', 'hide_section_cities', 'hide_section_testimonials',
            'hide_section_services', 'hide_section_how', 'hide_section_press', 'hide_section_partners',
            'hide_section_about', 'hide_section_awards', 'hide_section_faq', 'hide_section_newsletter',
            'hide_section_app', 'hide_section_locations', 'hide_section_global',
        ];

        $updated = 0;
        foreach ( $data as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( ! $key ) continue;

            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }
            $value = wp_unslash( (string) $value );
            if ( $value === '[object Object]' ) {
                continue;
            }

            $is_public = in_array( $key, $public_keys, true ) ? 1 : 0;

            $stored = self::sanitizeSettingValue( $key, $value );

            // Use INSERT ... ON DUPLICATE KEY UPDATE — atomic, no SELECT needed
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$t} (setting_key, setting_value, is_public)
                 VALUES (%s, %s, %d)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_public = VALUES(is_public)",
                $key,
                $stored,
                $is_public
            ) );
            $updated++;
        }

        // If any section was just turned ON (hide_section_X = '0'),
        // strip stale hide-CSS from custom_css_homepage so it can't override JS show/hide.
        $hide_keys = [
            'hide_section_hero', 'hide_section_notice', 'hide_section_search', 'hide_section_features',
            'hide_section_stats', 'hide_section_tagline', 'hide_section_cities', 'hide_section_testimonials',
            'hide_section_services', 'hide_section_how', 'hide_section_press', 'hide_section_partners',
            'hide_section_about', 'hide_section_awards', 'hide_section_faq', 'hide_section_newsletter',
            'hide_section_app', 'hide_section_locations',
        ];
        $any_shown = false;
        foreach ( $hide_keys as $hk ) {
            if ( isset( $data[$hk] ) && $data[$hk] === '0' ) { $any_shown = true; break; }
        }
        if ( $any_shown && isset( $data['custom_css_homepage'] ) ) {
            $hide_patterns = [
                '/#s2nri-root>div>section:nth-child\(1\)\{display:none!important\}[
]*/i',
                '/\.s2-stats-grid\{display:none!important\}[
]*/i',
                '/\.s2-hero-quote-wrap\{display:none!important\}[
]*/i',
                '/\.s2-svc-grid,\.s2-tabs\{display:none!important\}[
]*/i',
                '/\.s2-how-grid\{display:none!important\}[
]*/i',
            ];
            $clean_css = preg_replace( $hide_patterns, '', $data['custom_css_homepage'] );
            if ( $clean_css !== $data['custom_css_homepage'] ) {
                // Save the cleaned CSS back
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$t} (setting_key, setting_value, is_public)
                     VALUES ('custom_css_homepage', %s, 1)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    sanitize_text_field( $clean_css )
                ) );
            }
        }

        \S2NRI\Services\CacheService::bustPattern( 'settings_' );
        \S2NRI\Models\Setting::bustCache(); // also bust static in-process cache
        Response::json( [ 'success' => true, 'message' => "{$updated} settings updated." ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// TicketAdminController
// ══════════════════════════════════════════════════════════════════════════════

class TicketAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );
        $status = sanitize_key( $req->query( 'status', '' ) );

        $where = $status ? $wpdb->prepare( "WHERE t.status = %s", $status ) : '';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email AS customer_email
             FROM {$p}s2nri_tickets t JOIN {$p}s2nri_customers cu ON cu.id = t.customer_id LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             {$where} ORDER BY t.updated_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        $total = (int) $wpdb->get_var(
            $status
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$p}s2nri_tickets WHERE status=%s", $status )
                : "SELECT COUNT(*) FROM {$p}s2nri_tickets"
        );
        // Return both 'rows' (public admin reads t.rows) and 'tickets' (portal reads t.tickets) for compatibility
        Response::json( [ 'rows' => $rows ?: [], 'tickets' => $rows ?: [], 'total' => $total ] );
    }

    public function show( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*, COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email AS customer_email
             FROM {$p}s2nri_tickets t JOIN {$p}s2nri_customers cu ON cu.id = t.customer_id LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             WHERE t.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $ticket ) { Response::json( [ 'error' => 'Ticket not found.' ], 404 ); return; }

        $ticket['messages'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login, '') AS sender_name
             FROM {$p}s2nri_ticket_messages m LEFT JOIN {$p}users u ON u.ID = m.sender_id
             WHERE m.ticket_id = %d ORDER BY m.created_at ASC",
            $id
        ), ARRAY_A );

        Response::json( [ 'ticket' => $ticket ] );
    }

    public function updateStatus( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $id     = (int) $req->param( 'id' );
        $status = sanitize_key( $req->input( 'status', '' ) );
        if ( ! in_array( $status, [ 'open', 'in_progress', 'resolved', 'closed' ], true ) ) {
            Response::json( [ 'error' => 'Invalid status.' ], 422 ); return;
        }
        $resolved_at = in_array( $status, [ 'resolved', 'closed' ], true ) ? current_time( 'mysql' ) : null;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_tickets', [
            'status'      => $status,
            'resolved_at' => $resolved_at,
            'updated_at'  => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update ticket status.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function sendMessage( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p       = $wpdb->prefix;
        $id      = (int) $req->param( 'id' );
        $message = sanitize_textarea_field( $req->input( 'message', '' ) );
        if ( ! $message ) { Response::json( [ 'error' => 'Message required.' ], 422 ); return; }

        $msg_insert = $wpdb->insert( $p . 's2nri_ticket_messages', [
            'ticket_id'   => $id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'staff',
            'message'     => $message,
            'created_at'  => current_time( 'mysql' ),
        ] );
        if ( $msg_insert === false ) {
            error_log( '[S2NRI] Ticket message insert failed for ticket ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to send message. Please try again.' ], 500 ); return;
        }
        if ( $wpdb->update( $p . 's2nri_tickets', [ 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] ) === false ) {
            error_log( '[S2NRI] Failed to touch ticket updated_at for id ' . $id . ': ' . $wpdb->last_error );
            // Non-fatal — cosmetic timestamp touch only.
        }
        Response::json( [ 'success' => true ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AuditLogController
// ══════════════════════════════════════════════════════════════════════════════

class AuditLogController extends \S2NRI\Api\Controllers\BaseController {

    // TRACE: GET admin/audit-log?search=&action=&page= → returns paginated rows with user_name, booking_ref, details.
    //        Preconditions: user is manager or above.
    //        Postconditions: {rows, total, page, per_page} returned.
    public function index( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p        = $wpdb->prefix;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset   = $this->offset( $page, $per_page );
        $search   = sanitize_text_field( $req->query( 'search', '' ) );
        $action_f = sanitize_key( $req->query( 'action', '' ) );

        $where = 'WHERE 1=1';
        $args  = [];
        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (COALESCE(u.display_name, u.user_login, \'\') LIKE %s OR b.booking_ref LIKE %s OR al.action LIKE %s)';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        if ( $action_f ) {
            $where .= ' AND al.action = %s';
            $args[] = $action_f;
        }

        $count_sql = "SELECT COUNT(*) FROM {$p}s2nri_audit_log al
                      LEFT JOIN {$p}users u ON u.ID = al.user_id
                      LEFT JOIN {$p}s2nri_bookings b ON b.id = al.booking_id
                      {$where}";
        $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

        $rows_sql = "SELECT al.*, COALESCE(u.display_name, u.user_login, '') AS user_name,
                            b.booking_ref
                     FROM {$p}s2nri_audit_log al
                     LEFT JOIN {$p}users u ON u.ID = al.user_id
                     LEFT JOIN {$p}s2nri_bookings b ON b.id = al.booking_id
                     {$where}
                     ORDER BY al.created_at DESC LIMIT %d OFFSET %d";
        $all_args = array_merge( $args, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$all_args ), ARRAY_A );

        // Decode JSON details field
        foreach ( $rows as &$row ) {
            if ( ! empty( $row['details'] ) ) {
                $row['details'] = json_decode( $row['details'], true );
            }
        }
        unset( $row );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// NotificationAdminController
// ══════════════════════════════════════════════════════════════════════════════

class NotificationAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function broadcast( Request $req ): void {
        $this->requireManager();
        $title   = sanitize_text_field( $req->input( 'title', '' ) );
        $body    = sanitize_textarea_field( $req->input( 'body', '' ) );
        $target  = sanitize_key( $req->input( 'target', 'all' ) ); // all, customers, staff

        if ( ! $title || ! $body ) {
            Response::json( [ 'error' => 'Title and body required.' ], 422 ); return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        if ( $target === 'customers' ) {
            $users = $wpdb->get_col( "SELECT wp_user_id FROM {$p}s2nri_customers WHERE is_active = 1" );
        } elseif ( $target === 'staff' ) {
            $users = $wpdb->get_col( "SELECT wp_user_id FROM {$p}s2nri_staff WHERE is_active = 1" );
        } else {
            $users = array_merge(
                $wpdb->get_col( "SELECT wp_user_id FROM {$p}s2nri_customers WHERE is_active = 1" ),
                $wpdb->get_col( "SELECT wp_user_id FROM {$p}s2nri_staff WHERE is_active = 1" )
            );
        }

        $count = 0;
        foreach ( array_unique( $users ) as $uid ) {
            NotificationService::notifyCustomer( (int) $uid, 'broadcast', [
                'title' => $title,
                'body'  => $body,
            ] );
            $count++;
        }

        Response::json( [ 'success' => true, 'sent' => $count ] );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ReviewAdminController
// ══════════════════════════════════════════════════════════════════════════════

class ReviewAdminController extends \S2NRI\Api\Controllers\BaseController {

    // TRACE: GET admin/reviews?status=pending|published|rejected → paginated rows.
    //        React reads r.review_text (aliased from 'review' column), r.status, r.customer_name, r.service_name.
    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p       = $wpdb->prefix;
        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset  = $this->offset( $page, $per_page );
        $status  = sanitize_key( $req->query( 'status', 'pending' ) );
        if ( ! in_array( $status, [ 'pending', 'published', 'rejected' ], true ) ) $status = 'pending';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}s2nri_reviews WHERE status = %s", $status
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.booking_id, r.rating, r.review AS review_text,
                    r.status, r.is_published, r.created_at,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name,
                    s.name AS service_name, b.booking_ref
             FROM {$p}s2nri_reviews r
             JOIN {$p}s2nri_customers cu ON cu.id = r.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             JOIN {$p}s2nri_bookings b ON b.id = r.booking_id
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             WHERE r.status = %s
             ORDER BY r.created_at DESC LIMIT %d OFFSET %d",
            $status, $per_page, $offset
        ), ARRAY_A );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }

    // TRACE: PATCH admin/reviews/{id}/publish → sets status='published', is_published=1.
    public function publish( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_reviews', [
            'is_published' => 1,
            'status'       => 'published',
            'published_at' => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to publish review.' ], 500 ); return;
        }
        $this->logAudit( null, 'review_published', '', (string) $id );
        Response::json( [ 'success' => true, 'message' => 'Review published.' ] );
    }

    // TRACE: PATCH admin/reviews/{id}/reject → sets status='rejected', is_published=0.
    public function reject( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $wpdb->prefix . 's2nri_reviews', [
            'is_published' => 0,
            'status'       => 'rejected',
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to reject review.' ], 500 ); return;
        }
        $this->logAudit( null, 'review_rejected', '', (string) $id );
        Response::json( [ 'success' => true, 'message' => 'Review rejected.' ] );
    }
}

class ExportController extends \S2NRI\Api\Controllers\BaseController {

    public function bookings( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p       = $wpdb->prefix;
        $status  = sanitize_key( $req->query( 'status', '' ) );
        $date_from = sanitize_text_field( $req->query( 'date_from', '' ) );
        $date_to   = sanitize_text_field( $req->query( 'date_to', '' ) );

        $where = 'WHERE 1=1';
        if ( $status )   $where .= $wpdb->prepare( " AND b.status = %s", $status );
        if ( $date_from ) $where .= $wpdb->prepare( " AND DATE(b.created_at) >= %s", $date_from );
        if ( $date_to )   $where .= $wpdb->prepare( " AND DATE(b.created_at) <= %s", $date_to );

        $rows = $wpdb->get_results(
            "SELECT b.booking_ref, b.status, b.payment_status, b.quoted_amount, b.paid_amount,
                    b.created_at, b.completed_at,
                    s.name AS service_name, c.name AS category_name,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email AS email,
                    cu.phone, cu.country
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id = b.category_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             {$where} ORDER BY b.created_at DESC LIMIT 10000",
            ARRAY_A
        );

        $this->outputCsv( $rows, 'bookings-export-' . date( 'Y-m-d' ) . '.csv' );
    }

    public function customers( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT COALESCE(u.display_name, u.user_login, '') AS name, u.user_email AS email,
                    cu.phone, cu.country, cu.city_abroad, cu.city_india, cu.created_at,
                    COUNT(b.id) AS total_bookings,
                    SUM(CASE WHEN b.payment_status='paid' THEN b.paid_amount ELSE 0 END) AS total_paid
             FROM {$p}s2nri_customers cu
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             LEFT JOIN {$p}s2nri_bookings b ON b.customer_id = cu.id
             GROUP BY cu.id ORDER BY cu.created_at DESC LIMIT 10000",
            ARRAY_A
        );
        $this->outputCsv( $rows, 'customers-export-' . date( 'Y-m-d' ) . '.csv' );
    }

    public function payments( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT p.id, b.booking_ref, p.method, p.amount, p.currency, p.status,
                    p.payment_ref, p.created_at, p.verified_at,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email
             FROM {$p}s2nri_payments p
             JOIN {$p}s2nri_bookings b ON b.id = p.booking_id
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             LEFT JOIN {$p}users u ON u.ID = cu.wp_user_id
             ORDER BY p.created_at DESC LIMIT 10000",
            ARRAY_A
        );
        $this->outputCsv( $rows, 'payments-export-' . date( 'Y-m-d' ) . '.csv' );
    }

    private function outputCsv( array $rows, string $filename ): void {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-store' );

        if ( empty( $rows ) ) { echo 'No data'; return; }

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array_keys( $rows[0] ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// MediaController — handles image uploads for banners, service images, logos
// ══════════════════════════════════════════════════════════════════════════════

class MediaController extends \S2NRI\Api\Controllers\BaseController {

    // TRACE: POST admin/media/upload → validates file → uploads via WP media library
    //        → returns {url, id, file_name, width, height}.
    //        Preconditions: requireManager() verified. File in $_FILES['file'].
    //        Postconditions: File stored in WP uploads dir, URL returned.
    //        Edge cases: invalid type → 422. Too large → 422. WP media error → 500.
    public function upload( Request $req ): void {
        $this->requireManager();

        if ( empty( $_FILES['file'] ) ) {
            Response::json( [ 'error' => 'No file uploaded.' ], 422 ); return;
        }

        // SECURITY FIX: 'image/svg+xml' removed from allowed types. SVG
        // files can embed <script> tags and JS event handlers — a real,
        // well-documented stored-XSS vector, which is exactly why
        // WordPress core itself deliberately excludes SVG from its own
        // default allowed upload types. This endpoint is gated to
        // requireManager(), so exploiting it needs a compromised manager
        // account rather than being fully public — but the fix costs
        // nothing functionally (PNG/WebP cover banner/logo needs) and
        // removes the vulnerability class entirely rather than attempting
        // SVG sanitization, which needs a dedicated library to do safely.
        $allowed_types = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        $file          = $_FILES['file'];
        $mime_type     = mime_content_type( $file['tmp_name'] );

        if ( ! in_array( $mime_type, $allowed_types, true ) ) {
            Response::json( [ 'error' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF.' ], 422 ); return;
        }

        $max_size = 8 * 1024 * 1024; // 8MB
        if ( $file['size'] > $max_size ) {
            Response::json( [ 'error' => 'File too large. Maximum size is 8MB.' ], 422 ); return;
        }

        // Load WordPress media functions
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Override the file name to be clean
        $original_name = sanitize_file_name( $file['name'] );
        $_FILES['file']['name'] = $original_name;

        $attachment_id = media_handle_upload( 'file', 0, [], [
            'test_form' => false,
        ] );

        if ( is_wp_error( $attachment_id ) ) {
            Response::json( [ 'error' => $attachment_id->get_error_message() ], 500 ); return;
        }

        $url  = wp_get_attachment_url( $attachment_id );
        $meta = wp_get_attachment_metadata( $attachment_id );

        // Log audit
        $this->logAudit( null, 'media_upload', '', $url );

        Response::json( [
            'success'   => true,
            'id'        => $attachment_id,
            'url'       => $url,
            'file_name' => basename( $url ),
            'width'     => $meta['width']  ?? null,
            'height'    => $meta['height'] ?? null,
            'mime_type' => $mime_type,
        ] );
    }

    // TRACE: GET admin/media → returns paginated list of uploaded images.
    public function index( Request $req ): void {
        $this->requireManager();

        $page     = max( 1, (int) $req->query( 'page', 1 ) );
        $per_page = 20;
        $offset   = ( $page - 1 ) * $per_page;

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'post_status'    => 'inherit',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $total = wp_count_attachments( 'image' );
        $items = [];

        foreach ( $attachments as $att ) {
            $items[] = [
                'id'        => $att->ID,
                'url'       => wp_get_attachment_url( $att->ID ),
                'file_name' => basename( wp_get_attachment_url( $att->ID ) ),
                'title'     => $att->post_title,
                'date'      => $att->post_date,
            ];
        }

        Response::json( [
            'items'    => $items,
            'total'    => (int) ( $total->image ?? 0 ),
            'page'     => $page,
            'per_page' => $per_page,
        ] );
    }

    // TRACE: DELETE admin/media/{id} → removes attachment from WP media library.
    public function destroy( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( wp_delete_attachment( $id, true ) ) {
            $this->logAudit( null, 'media_delete', (string) $id, '' );
            Response::json( [ 'success' => true ] );
        } else {
            Response::json( [ 'error' => 'Could not delete media item.' ], 500 );
        }
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// CityAdminController — full CRUD for s2nri_cities
// TRACE: All admin city routes → requireManager() → CRUD on s2nri_cities.
//        index() returns all cities ordered by sort_order.
//        store() validates name + slug uniqueness → INSERT.
//        update() accepts image_url, name, tagline, sort_order, state, is_active.
//        destroy() hard deletes (cities have no FK children).
//        toggle() flips is_active.
//        uploadImage() uploads via WP media → updates image_url.
// ══════════════════════════════════════════════════════════════════════════════

class CityAdminController extends \S2NRI\Api\Controllers\BaseController {

    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}s2nri_cities ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
        Response::json( [ 'cities' => $rows ] );
    }

    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $name  = sanitize_text_field( $req->input( 'name', '' ) );
        $slug  = sanitize_title( $req->input( 'slug', $name ) );
        if ( ! $name || ! $slug ) { Response::json( [ 'error' => 'City name is required.' ], 422 ); return; }

        // Unique slug check
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}s2nri_cities WHERE slug = %s LIMIT 1", $slug ) );
        if ( $exists ) { Response::json( [ 'error' => "A city with slug '{$slug}' already exists." ], 422 ); return; }

        $city_insert = $wpdb->insert( $wpdb->prefix . 's2nri_cities', [
            'name'       => $name,
            'slug'       => $slug,
            'state'      => sanitize_text_field( $req->input( 'state', '' ) ),
            'tagline'    => sanitize_text_field( $req->input( 'tagline', '' ) ),
            'image_url'  => esc_url_raw( $req->input( 'image_url', '' ) ),
            'sort_order' => (int) $req->input( 'sort_order', 0 ),
            'is_active'  => 1,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed elsewhere in this codebase.
        if ( $city_insert === false ) { Response::json( [ 'error' => 'Failed to create city.' ], 500 ); return; }
        $this->logAudit( null, 'city_created', '', $name );
        \S2NRI\Services\CacheService::bustPattern( 'cities_' );
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id, 'slug' => $slug ], 201 );
    }

    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $city = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}s2nri_cities WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        if ( ! $city ) { Response::json( [ 'error' => 'City not found.' ], 404 ); return; }

        $update = [ 'updated_at' => current_time( 'mysql' ) ];
        if ( $req->input('name')       !== null ) $update['name']       = sanitize_text_field( $req->input( 'name' ) );
        if ( $req->input('slug')       !== null ) $update['slug']       = sanitize_title( $req->input( 'slug' ) );
        if ( $req->input('state')      !== null ) $update['state']      = sanitize_text_field( $req->input( 'state' ) );
        if ( $req->input('tagline')    !== null ) $update['tagline']    = sanitize_text_field( $req->input( 'tagline' ) );
        if ( $req->input('image_url')  !== null ) $update['image_url']  = esc_url_raw( $req->input( 'image_url' ) );
        if ( $req->input('sort_order') !== null ) $update['sort_order'] = (int) $req->input( 'sort_order' );
        if ( $req->input('is_active')  !== null ) $update['is_active']  = (int) $req->input( 'is_active' );

        // CHECKED (was previously unchecked): same ghost-success pattern
        // fixed throughout this file — a failed update previously still
        // returned success:true.
        if ( $wpdb->update( $wpdb->prefix . 's2nri_cities', $update, [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update city.' ], 500 ); return;
        }
        $this->logAudit( null, 'city_updated', '', (string) $id );
        \S2NRI\Services\CacheService::bustPattern( 'cities_' );
        Response::json( [ 'success' => true ] );
    }

    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id = (int) $req->param( 'id' );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}s2nri_cities WHERE id = %d LIMIT 1", $id ) ) ) {
            Response::json( [ 'error' => 'City not found.' ], 404 ); return;
        }
        // Confirmed via codebase-wide search: no other table references
        // city_id, so this hard delete cannot orphan any child rows.
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_cities', [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete city.' ], 500 ); return;
        }
        $this->logAudit( null, 'city_deleted', '', (string) $id );
        \S2NRI\Services\CacheService::bustPattern( 'cities_' );
        Response::json( [ 'success' => true ] );
    }

    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $id   = (int) $req->param( 'id' );
        $city = $wpdb->get_row( $wpdb->prepare( "SELECT is_active FROM {$wpdb->prefix}s2nri_cities WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        if ( ! $city ) { Response::json( [ 'error' => 'City not found.' ], 404 ); return; }
        $new_city_active = (int) $city['is_active'] ? 0 : 1;  // cast to int — PHP returns TINYINT as string
        if ( $wpdb->update( $wpdb->prefix . 's2nri_cities', [ 'is_active' => $new_city_active ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to toggle city status.' ], 500 ); return;
        }
        \S2NRI\Services\CacheService::bustPattern( 'cities_' );
        Response::json( [ 'success' => true, 'is_active' => $new_city_active ] );
    }

    public function uploadImage( Request $req ): void {
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( empty( $_FILES['file'] ) ) { Response::json( [ 'error' => 'No file uploaded.' ], 422 ); return; }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $att_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $att_id ) ) { Response::json( [ 'error' => $att_id->get_error_message() ], 500 ); return; }

        $url = wp_get_attachment_url( $att_id );
        global $wpdb;
        if ( $wpdb->update( $wpdb->prefix . 's2nri_cities', [ 'image_url' => $url, 'updated_at' => current_time('mysql') ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Image uploaded but failed to save.' ], 500 ); return;
        }
                \S2NRI\Services\CacheService::bustPattern( 'cities_' );
        Response::json( [ 'success' => true, 'url' => $url ] );
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// ServiceSectionAdminController — page-builder CRUD for s2nri_service_sections
// ══════════════════════════════════════════════════════════════════════════════

class ServiceSectionAdminController extends \S2NRI\Api\Controllers\BaseController {

    // GET admin/services/{id}/sections — return all sections for a service
    //
    // ISSUE 6 FIX: Builder JS ($m component) reads e.is_active for toggle UI,
    // but DB column is is_visible. Toggle PATCH flips is_visible only.
    // Fix: return is_active as alias of is_visible so builder reflects correctly.
    public function index( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}s2nri_service_sections`
             WHERE service_id = %d ORDER BY sort_order ASC, id ASC",
            $service_id
        ), ARRAY_A );
        foreach ( $rows as &$row ) {
            if ( $row['content'] ) {
                $decoded = json_decode( $row['content'], true );
                $row['content'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $row['content'];
            }
            // Sync is_active ↔ is_visible so builder toggle and nav filter both work
            if ( array_key_exists( 'is_visible', $row ) ) {
                $row['is_active'] = (int) $row['is_visible'];
            } elseif ( array_key_exists( 'is_active', $row ) ) {
                $row['is_visible'] = (int) $row['is_active'];
            }
        }
        unset( $row );
        Response::json( [ 'sections' => $rows ] );
    }

    // POST admin/services/{id}/sections — create a new section
    public function store( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );

        // Verify service exists
        if ( ! $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}s2nri_services` WHERE id = %d LIMIT 1", $service_id
        ) ) ) { Response::json( [ 'error' => 'Service not found.' ], 404 ); return; }

        $body    = json_decode( file_get_contents( 'php://input' ), true ) ?? [];
        $type    = sanitize_key( $body['type']  ?? 'text' );
        // FIXED (low severity, fixed per explicit request to address
        // everything found): no allowlist existed here at all —
        // sanitize_key() only validates FORMAT (lowercase,
        // alphanumeric/underscore), not that the value is one of the 16
        // real, supported section types. Traced the actual public
        // renderer (ServiceDetailPage.tsx SectionRenderer) and confirmed
        // an unrecognized type safely renders nothing (default: return
        // null) — so this was never a crash risk, just a silent content
        // gap reachable only by a trusted manager account bypassing their
        // own UI dropdown. Fixed for correctness now that the frontend's
        // own SECTION_TYPES list (src-react/.../ServiceBuilder/index.jsx)
        // was corrected in the same pass — this 17-value list must match
        // it exactly.
        $allowed_section_types = [
            'description', 'process', 'documents', 'why_choose', 'trust_badges',
            'faq', 'eligibility', 'charges', 'security', 'benefits', 'features',
            'cta', 'testimonials', 'text', 'notes', 'highlights', 'related',
        ];
        if ( ! in_array( $type, $allowed_section_types, true ) ) {
            Response::json( [ 'error' => 'Invalid section type.' ], 422 ); return;
        }
        $title   = sanitize_text_field( $body['title'] ?? '' );
        $content = $body['content'] ?? null;

        // Get next sort_order
        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order),0) FROM `{$wpdb->prefix}s2nri_service_sections` WHERE service_id = %d",
            $service_id
        ) );

        $sec_insert = $wpdb->insert( $wpdb->prefix . 's2nri_service_sections', [
            'service_id'  => $service_id,
            'type'        => $type,
            'title'       => $title,
            'content'     => $content !== null ? wp_json_encode( $content ) : null,
            'sort_order'  => $max + 1,
            'is_visible'  => 1,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase — a failed insert here
        // would previously return an unrelated section's row as if it
        // were the "newly created" one.
        if ( $sec_insert === false ) {
            error_log( '[S2NRI] service_sections insert failed for service ' . $service_id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to create section.' ], 500 ); return;
        }
        $new_id = $wpdb->insert_id;
        $row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}s2nri_service_sections` WHERE id = %d", $new_id
        ), ARRAY_A );
        if ( $row['content'] ) {
            $decoded = json_decode( $row['content'], true );
            $row['content'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $row['content'];
        }
        Response::json( [ 'success' => true, 'section' => $row ], 201 );
    }

    // PUT admin/services/{id}/sections/{section_id} — update a section
    public function update( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );
        $section_id = (int) $req->param( 'section_id' );

        $section = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$wpdb->prefix}s2nri_service_sections` WHERE id = %d AND service_id = %d LIMIT 1",
            $section_id, $service_id
        ), ARRAY_A );
        if ( ! $section ) { Response::json( [ 'error' => 'Section not found.' ], 404 ); return; }

        $body    = json_decode( file_get_contents( 'php://input' ), true ) ?? [];
        $update  = [ 'updated_at' => current_time( 'mysql' ) ];
        if ( array_key_exists( 'title',      $body ) ) $update['title']      = sanitize_text_field( $body['title'] );
        if ( array_key_exists( 'type', $body ) ) {
            $new_type = sanitize_key( $body['type'] );
            $allowed_section_types_u = [
                'description', 'process', 'documents', 'why_choose', 'trust_badges',
                'faq', 'eligibility', 'charges', 'security', 'benefits', 'features',
                'cta', 'testimonials', 'text', 'notes', 'highlights', 'related',
            ];
            // FIXED: same missing allowlist gap as store(), fixed here too
            // for consistency — same 17-value list, must match the
            // frontend's SECTION_TYPES exactly.
            if ( ! in_array( $new_type, $allowed_section_types_u, true ) ) {
                Response::json( [ 'error' => 'Invalid section type.' ], 422 ); return;
            }
            $update['type'] = $new_type;
        }
        if ( array_key_exists( 'is_visible', $body ) ) $update['is_visible'] = (int) $body['is_visible'];
        if ( array_key_exists( 'sort_order', $body ) ) $update['sort_order'] = (int) $body['sort_order'];
        if ( array_key_exists( 'content',    $body ) ) {
            $update['content'] = ( $body['content'] !== null ) ? wp_json_encode( $body['content'] ) : null;
        }

        if ( $wpdb->update( $wpdb->prefix . 's2nri_service_sections', $update, [ 'id' => $section_id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update section.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    // DELETE admin/services/{id}/sections/{section_id}
    public function destroy( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );
        $section_id = (int) $req->param( 'section_id' );
        if ( $wpdb->delete( $wpdb->prefix . 's2nri_service_sections', [ 'id' => $section_id, 'service_id' => $service_id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete section.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    // PATCH admin/services/{id}/sections/{section_id}/toggle — show/hide
    //
    // ISSUE 6 FIX: Flip both is_visible AND is_active (they are synonymous).
    // Return both in response so builder JS (.is_active) and renderSectionNav()
    // (.is_visible) both see the updated value without needing a separate reload.
    public function toggle( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $p          = $wpdb->prefix;
        $section_id = (int) $req->param( 'section_id' );

        // Read current visibility — prefer is_visible, fall back to is_active
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_visible FROM `{$p}s2nri_service_sections` WHERE id = %d LIMIT 1",
            $section_id
        ), ARRAY_A );
        if ( ! $row ) { Response::json( [ 'error' => 'Not found.' ], 404 ); return; }

        $new = (int) $row['is_visible'] ? 0 : 1;

        // Update both columns so both the PHP nav filter (is_visible) and
        // the builder JS toggle UI (is_active) see the correct value
        $cols = [ 'is_visible' => $new ];
        // Also flip is_active if the column exists in the table
        $has_is_active = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$p}s2nri_service_sections'
               AND COLUMN_NAME = 'is_active'"
        );
        if ( (int) $has_is_active ) {
            $cols['is_active'] = $new;
        }
        if ( $wpdb->update( $p . 's2nri_service_sections', $cols, [ 'id' => $section_id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to toggle section.' ], 500 ); return;
        }

        // Bust any cached section nav so PHP re-reads is_visible on next page load
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 's2nri_sections_' . $section_id, 's2nri' );
        }

        Response::json( [ 'success' => true, 'is_visible' => $new, 'is_active' => $new ] );
    }

    // POST admin/services/{id}/sections/{section_id}/duplicate
    public function duplicate( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $section_id = (int) $req->param( 'section_id' );
        $src = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}s2nri_service_sections` WHERE id = %d LIMIT 1", $section_id
        ), ARRAY_A );
        if ( ! $src ) { Response::json( [ 'error' => 'Not found.' ], 404 ); return; }

        $max = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order),0) FROM `{$wpdb->prefix}s2nri_service_sections` WHERE service_id = %d",
            $src['service_id']
        ) );

        $dup_insert = $wpdb->insert( $wpdb->prefix . 's2nri_service_sections', [
            'service_id'  => $src['service_id'],
            'type'        => $src['type'],
            'title'       => $src['title'] . ' (Copy)',
            'content'     => $src['content'],
            'sort_order'  => $max + 1,
            'is_visible'  => $src['is_visible'],
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness fix
        // applied throughout — see store() above for the full explanation.
        if ( $dup_insert === false ) {
            error_log( '[S2NRI] service_sections duplicate insert failed for section ' . $section_id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to duplicate section.' ], 500 ); return;
        }
        $new_id = $wpdb->insert_id;
        $new    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}s2nri_service_sections` WHERE id = %d", $new_id
        ), ARRAY_A );
        if ( $new['content'] ) {
            $decoded = json_decode( $new['content'], true );
            $new['content'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $new['content'];
        }
        Response::json( [ 'success' => true, 'section' => $new ], 201 );
    }

    // PUT admin/services/{id}/sections/reorder — save new sort order
    public function reorder( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );
        $body   = json_decode( file_get_contents( 'php://input' ), true ) ?? [];
        $order  = $body['order'] ?? [];  // [ { id: N, sort_order: N }, ... ]
        foreach ( $order as $item ) {
            if ( $wpdb->update( $wpdb->prefix . 's2nri_service_sections',
                [ 'sort_order' => (int) $item['sort_order'] ],
                [ 'id' => (int) $item['id'], 'service_id' => $service_id ]
            ) === false ) {
                error_log( '[S2NRI] section reorder failed for id=' . (int) $item['id'] . ': ' . $wpdb->last_error );
            }
        }
        Response::json( [ 'success' => true ] );
    }

    // POST admin/services/{id}/sections/dedup
    // Removes duplicate sections keeping the most recently created one per type.
    public function dedup( Request $req ): void {
        $this->requireManager();
        global $wpdb;
        $service_id = (int) $req->param( 'id' );

        // Find all sections for this service
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type FROM `{$wpdb->prefix}s2nri_service_sections`
             WHERE service_id = %d ORDER BY id ASC",
            $service_id
        ), ARRAY_A );

        // Group by type — keep highest id (most recent), delete the rest
        $keep   = [];  // type => max id to keep
        $delete = [];  // ids to delete

        foreach ( $rows as $row ) {
            $type = $row['type'];
            if ( ! isset( $keep[ $type ] ) ) {
                $keep[ $type ] = (int) $row['id'];
            } else {
                // Already have one — delete the older one, keep the newer (higher id)
                if ( (int) $row['id'] > $keep[ $type ] ) {
                    $delete[] = $keep[ $type ];
                    $keep[ $type ] = (int) $row['id'];
                } else {
                    $delete[] = (int) $row['id'];
                }
            }
        }

        $removed = 0;
        foreach ( $delete as $del_id ) {
            // CHECKED (was previously unchecked, and $removed was
            // incremented unconditionally — over-counting on a failed
            // delete, so the response could claim more rows were removed
            // than actually were).
            $del_result = $wpdb->delete( $wpdb->prefix . 's2nri_service_sections', [ 'id' => $del_id ] );
            if ( $del_result === false ) {
                error_log( '[S2NRI] dedup delete failed for section ' . $del_id . ': ' . $wpdb->last_error );
                continue;
            }
            $removed++;
        }

        // Re-fetch clean list after dedup
        $clean = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}s2nri_service_sections`
             WHERE service_id = %d ORDER BY sort_order ASC, id ASC",
            $service_id
        ), ARRAY_A );

        foreach ( $clean as &$row ) {
            if ( $row['content'] ) {
                $decoded = json_decode( $row['content'], true );
                $row['content'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $row['content'];
            }
        }

        Response::json( [ 'success' => true, 'removed' => $removed, 'sections' => $clean ] );
    }

}


