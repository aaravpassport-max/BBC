<?php
namespace S2NRI\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\{Request, Response};
use S2NRI\Services\NotificationService;
use S2NRI\Exceptions\{ValidationException, NotFoundException, ForbiddenException};

/**
 * BookingController — customer-facing booking CRUD.
 *
 * TRACE: POST bookings → validates service + fields → inserts s2nri_bookings → sends notifications → returns booking.
 *        GET  bookings → lists customer's bookings paginated.
 *        GET  bookings/{id} → returns full booking detail with quotes, docs, messages.
 *        POST bookings/{id}/approve-quote → sets quote approved, booking status = in_progress.
 *        POST bookings/{id}/cancel → cancels if in cancellable status.
 *        POST bookings/{id}/review → submits 1–5 star review on completed booking.
 *        Preconditions: user authenticated.
 *        Postconditions: booking row created/updated in DB, notifications queued.
 *        Edge cases: duplicate ref generation guarded by UNIQUE constraint, invalid service rejected.
 */
class BookingController extends BaseController {

    // ── Create booking ────────────────────────────────────────────────────────

    public function store( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $service_id           = (int) $req->input( 'service_id', 0 );
        $field_data           = $req->input( 'field_data', [] );
        $priority             = sanitize_key( $req->input( 'priority', 'normal' ) );
        // Qualification data from client (re-verified server-side below)
        $client_score         = (int) $req->input( 'qualification_score', 0 );
        $client_status        = sanitize_key( $req->input( 'qualification_status', 'incomplete' ) );

        // Validate service
        $service = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, c.id AS cat_id FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id
             WHERE s.id = %d AND s.is_active = 1 LIMIT 1",
            $service_id
        ), ARRAY_A );

        if ( ! $service ) {
            Response::json( [ 'error' => 'Service not found or not available.' ], 404 );
            return;
        }

        // ── Server-side required-field validation ─────────────────────────────
        // Validate field_data against s2nri_form_fields required=1 rows.
        // This prevents empty bookings from slipping through if JS validation is bypassed.
        // Only validates fields that are is_active=1 AND required=1 AND step < 98
        // (step 98 = contact info which is always required — validated separately).
        $required_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_key, label FROM `{$p}s2nri_form_fields`
             WHERE service_id=%d AND is_active=1 AND required=1
             ORDER BY sort_order ASC",
            $service_id
        ), ARRAY_A );

        $missing = [];
        if ( is_array( $required_fields ) && is_array( $field_data ) ) {
            foreach ( $required_fields as $rf ) {
                $key = $rf['field_key'];
                $val = $field_data[ $key ] ?? null;
                if ( $val === null || $val === '' || $val === [] ) {
                    $missing[] = $rf['label'];
                }
            }
        }
        if ( ! empty( $missing ) ) {
            Response::json( [
                'error'          => 'Please fill in all required fields before submitting.',
                'missing_fields' => $missing,
            ], 422 );
            return;
        }

        if ( ! in_array( $priority, [ 'normal', 'high', 'urgent' ], true ) ) $priority = 'normal';

        // ── Server-side qualification scoring ─────────────────────────────────
        // Re-derive score from service's qualification_schema independently of client.
        $qual_schema = null;
        if ( ! empty( $service['qualification_schema'] ) ) {
            $qual_schema = json_decode( $service['qualification_schema'], true );
        } elseif ( ! empty( $service['form_schema'] ) ) {
            $qual_schema = json_decode( $service['form_schema'], true );
        }

        $score  = 0;
        $status = 'incomplete';
        $disqualified = false;

        if ( is_array( $qual_schema ) && is_array( $field_data ) ) {
            $max_score = 0;

            foreach ( $qual_schema as $field ) {
                $key    = $field['key'] ?? '';
                $weight = (int) ( $field['score_weight'] ?? 0 );
                $rule   = $field['eligibility_rule'] ?? null;
                $value  = $field_data[ $key ] ?? null;

                $max_score += $weight;

                // Eligibility rules
                if ( $rule && $value !== null ) {
                    if ( isset( $rule['disqualify_if'] ) && (string) $value === (string) $rule['disqualify_if'] ) {
                        $disqualified = true;
                    }
                }

                // Score contribution: answered non-empty = full weight
                if ( $weight > 0 && $value !== null && $value !== '' ) {
                    $score += $weight;
                }
            }

            $pct = $max_score > 0 ? ( $score / $max_score ) * 100 : 0;

            if ( $disqualified ) {
                $status = 'not_yet_eligible';
            } elseif ( $pct >= 80 ) {
                $status = 'highly_qualified';
            } elseif ( $pct >= 60 ) {
                $status = 'qualified';
            } elseif ( $pct >= 40 ) {
                $status = 'requires_review';
            } else {
                $status = 'missing_documents';
            }
        }

        // Ensure customer profile exists before reading the ID
        \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );

        // Retry SELECT twice — ensureCustomerProfile may have just inserted the row
        $customer = null;
        for ( $attempt = 0; $attempt < 2 && ! $customer; $attempt++ ) {
            $customer = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
                $this->user['wp_id']
            ), ARRAY_A );
        }

        // If still missing, insert a minimal customer row now
        if ( ! $customer ) {
            $wp_user = get_userdata( $this->user['wp_id'] );
            $cust_insert = $wpdb->insert( $p . 's2nri_customers', [
                'wp_user_id' => $this->user['wp_id'],
                'email'      => $wp_user ? $wp_user->user_email : '',
                'first_name' => $wp_user ? $wp_user->first_name : '',
                'last_name'  => $wp_user ? $wp_user->last_name  : '',
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ] );
            // CHECKED (was previously unchecked): an unchecked failure here
            // fell through to $cust_id = 0 (an empty insert_id), which then
            // flowed straight into the booking insert below as
            // customer_id: 0 — a real, orphaned/invalid booking with no
            // valid customer link, created silently with a 200 response.
            if ( $cust_insert === false ) {
                Response::json( [ 'error' => 'Failed to prepare your account. Please try again.' ], 500 );
                return;
            }
            $cust_id = (int) $wpdb->insert_id;
            $customer = [ 'id' => $cust_id ];
        }

        $customer_id = (int) $customer['id'];

        // Generate ref (retry on collision)
        $ref = $this->generateRef();

        $result = $wpdb->insert( $p . 's2nri_bookings', [
            'booking_ref'  => $ref,
            'customer_id'  => $customer_id,
            'service_id'   => $service_id,
            'category_id'  => $service['cat_id'],
            'status'       => 'submitted',
            'priority'     => $priority,
            'field_data'            => wp_json_encode( is_array( $field_data ) ? $field_data : [] ),
            'qualification_score'   => min( 100, max( 0, $score ) ),
            'qualification_status'  => $status,
            'created_at'            => current_time( 'mysql' ),
            'updated_at'            => current_time( 'mysql' ),
        ] );

        if ( $result === false ) {
            Response::json( [ 'error' => 'Failed to create booking. Please try again.' ], 500 );
            return;
        }

        $booking_id = (int) $wpdb->insert_id;

        // System message
        if ( $wpdb->insert( $p . 's2nri_messages', [
            'booking_id'  => $booking_id,
            'sender_id'   => $this->user['wp_id'],
            'sender_type' => 'system',
            'message'     => 'Booking submitted successfully. Our team will review and send a quote within 24 hours.',
            'created_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] Welcome system message insert failed for booking ' . $booking_id . ': ' . $wpdb->last_error );
            // Non-fatal — the booking itself is already safely created and
            // checked above; a missing welcome message is a cosmetic gap.
        }

        // Audit log
        $this->logAudit( $booking_id, 'booking_created', '', $ref );

        // Notify customer
        NotificationService::notifyCustomer( $this->user['wp_id'], 'booking_submitted', [
            'booking_id' => $booking_id,
            'title'      => 'Booking Submitted Successfully',
            'body'       => "Your booking {$ref} for {$service['name']} has been submitted. We'll send you a quote within 24 hours.",
        ] );

        // Notify admin
        NotificationService::notifyAdmins( 'new_booking', [
            'booking_id' => $booking_id,
            'title'      => "New Booking: {$ref}",
            'body'       => "New booking received for {$service['name']} — Priority: {$priority}",
        ] );

        // Send HTML booking confirmation email
        try {
            $email_svc = new \S2NRI\Services\EmailService();
            $cust_row  = $wpdb->get_row( $wpdb->prepare(
                "SELECT u.user_email, u.display_name FROM {$p}s2nri_customers c JOIN {$p}users u ON u.ID = c.wp_user_id WHERE c.id = %d",
                $customer_id
            ), ARRAY_A );
            if ( $cust_row ) {
                $email_svc->sendBookingConfirmation(
                    [ 'booking_ref' => $ref, 'service_name' => $service['name'], 'dashboard_url' => home_url( "/dashboard/bookings/{$booking_id}" ) ],
                    $cust_row['user_email'],
                    $cust_row['display_name']
                );
            }
        } catch ( \Throwable $e ) {
            error_log( 'S2NRI email send error: ' . $e->getMessage() );
        }

        // M-14: Fire WhatsApp notification hook for new booking
        do_action( 's2nri_booking_submitted', $booking_id, [
            'booking_ref'   => $ref,
            'customer_name' => $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$p}users u LEFT JOIN {$p}s2nri_customers c ON c.wp_user_id=u.ID WHERE c.id=%d LIMIT 1", $customer_id ) ) ?: '',
            'service_name'  => $service['name'],
            'status'        => 'submitted',
        ] );

        $booking = $this->getBookingDetail( $booking_id );

        // If detail query fails for any reason, return a minimal object so the client
        // can still show the success screen and reference number.
        if ( ! $booking ) {
            $booking = [
                'id'          => $booking_id,
                'booking_ref' => $ref,
                'status'      => 'submitted',
                'service_id'  => $service_id,
                'created_at'  => current_time( 'mysql' ),
            ];
        }

        Response::json( [ 'success' => true, 'booking' => $booking ], 201 );
    }

    // ── List customer's bookings ──────────────────────────────────────────────

    public function myList( Request $req ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Ensure customer profile row exists — new users who log in before
        // submitting their first booking have no row yet. Without this they
        // always see 0 requests even after submitting, because the booking's
        // customer_id=0 and the orphan-repair below never runs.
        \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );

        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id']
        ), ARRAY_A );

        if ( ! $customer ) {
            Response::json( [ 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 20 ] );
            return;
        }

        // Repair: claim any orphan bookings (customer_id=0) that have this user's email
        // in field_data. These were created before the customer profile was linked.
        $user_email = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_email FROM {$p}users WHERE ID = %d LIMIT 1",
            $this->user['wp_id']
        ) );
        if ( $user_email ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}s2nri_bookings
                 SET customer_id = %d
                 WHERE customer_id = 0
                   AND (
                       JSON_UNQUOTE( JSON_EXTRACT( field_data, '$.email' ) ) = %s
                       OR JSON_UNQUOTE( JSON_EXTRACT( field_data, '$.__email' ) ) = %s
                   )",
                $customer['id'], $user_email, $user_email
            ) );
        }

        [ 'page' => $page, 'per_page' => $per_page ] = $this->paginate( $req );
        $offset = $this->offset( $page, $per_page );
        $status = sanitize_key( $req->query( 'status', '' ) );

        $where = $wpdb->prepare( "WHERE b.customer_id = %d", $customer['id'] );
        if ( $status ) {
            $where .= $wpdb->prepare( " AND b.status = %s", $status );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}s2nri_bookings b {$where}"
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.booking_ref, b.status, b.priority, b.payment_status,
                    b.quoted_amount, b.created_at, b.updated_at, b.due_date,
                    b.secondary_status, b.delivery_working_days, b.delivery_note,
                    b.shipping_type,
                    s.name AS service_name, s.slug AS service_slug,
                    c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                    (SELECT COUNT(*) FROM {$p}s2nri_messages m WHERE m.booking_id = b.id AND m.is_read = 0 AND m.sender_type != 'customer') AS unread_count
             FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             LEFT JOIN {$p}s2nri_categories c ON c.id = b.category_id
             {$where}
             ORDER BY b.updated_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        Response::json( compact( 'rows', 'total', 'page', 'per_page' ) );
    }

    // ── Show booking detail ───────────────────────────────────────────────────

    public function show( Request $req ): void {
        $id      = (int) $req->param( 'id' );
        $booking = $this->getBookingDetail( $id );

        global $wpdb;

        if ( ! $booking ) {
            Response::json( [ 'error' => 'Booking not found.' ], 404 );
            return;
        }

        // Customer can only see their own booking.
        // ensureCustomerProfile first so newly-registered customers get a row
        // before we check ownership — otherwise any booking shows as 403.
        if ( ! $this->userIsStaff() ) {
            \S2NRI\Models\User::ensureCustomerProfile( $this->user['wp_id'] );
            $customer = $this->getCustomerByWpId( $this->user['wp_id'] );
            if ( ! $customer || (int) $booking['customer_id'] !== (int) $customer['id'] ) {
                Response::json( [ 'error' => 'Access denied.' ], 403 );
                return;
            }
        }

        Response::json( [ 'booking' => $booking ] );
    }

    // ── Cancel booking ────────────────────────────────────────────────────────

    public function cancel( Request $req ): void {
        global $wpdb;
        $id = (int) $req->param( 'id' );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$wpdb->prefix}s2nri_bookings b
             JOIN {$wpdb->prefix}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) {
            Response::json( [ 'error' => 'Booking not found.' ], 404 ); return;
        }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }

        $cancellable = [ 'submitted', 'under_review', 'quote_sent' ];
        if ( ! in_array( $booking['status'], $cancellable, true ) ) {
            Response::json( [ 'error' => 'This booking cannot be cancelled at its current stage. Please contact support.' ], 422 );
            return;
        }

        if ( $wpdb->update(
            $wpdb->prefix . 's2nri_bookings',
            [ 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        ) === false ) {
            error_log( '[S2NRI] Booking cancellation update failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to cancel booking. Please try again.' ], 500 ); return;
        }

        $this->logAudit( $id, 'booking_cancelled', $booking['status'], 'cancelled' );
        NotificationService::notifyAdmins( 'booking_cancelled', [
            'booking_id' => $id,
            'title'      => "Booking Cancelled: {$booking['booking_ref']}",
            'body'       => 'Customer cancelled the booking.',
        ] );

        Response::json( [ 'success' => true, 'message' => 'Booking cancelled successfully.' ] );
    }

    // ── Approve quote ─────────────────────────────────────────────────────────

    public function approveQuote( Request $req ): void {
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = (int) $req->param( 'id' );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }
        if ( $booking['status'] !== 'quote_sent' ) {
            Response::json( [ 'error' => 'No pending quote to approve.' ], 422 ); return;
        }

        // Get active quote
        $quote = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}s2nri_quotes WHERE booking_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $quote ) { Response::json( [ 'error' => 'Quote not found.' ], 404 ); return; }

        // Check expiry
        if ( $quote['valid_until'] && strtotime( $quote['valid_until'] ) < time() ) {
            Response::json( [ 'error' => 'This quote has expired. Please request a new quote from support.' ], 422 ); return;
        }

        $quote_update = $wpdb->update( $p . 's2nri_quotes', [
            'status'      => 'approved',
            'approved_at' => current_time( 'mysql' ),
        ], [ 'id' => $quote['id'] ] );

        $booking_update = $wpdb->update( $p . 's2nri_bookings', [
            'status'            => 'quote_approved',
            'quote_approved_at' => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        // CHECKED (was previously unchecked): genuinely money-critical —
        // this booking status transition is the exact precondition
        // createRazorpayOrder() checks before allowing payment (confirmed
        // earlier this session: "Booking must be in quote_approved
        // status"). A silent failure here previously still told the
        // customer "Quote approved!... Please complete payment to
        // proceed" — a false success — but the booking would still be
        // stuck at quote_sent, so the customer's very next action
        // (trying to pay) would then be confusingly rejected by the
        // payment endpoint for a reason they were never told about.
        if ( $quote_update === false || $booking_update === false ) {
            error_log( "[S2NRI] CRITICAL: quote approval DB update failed for booking {$id}, quote {$quote['id']}: " . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to approve quote. Please try again.' ], 500 ); return;
        }

        $this->logAudit( $id, 'quote_approved', 'pending', 'approved' );

        NotificationService::notifyAdmins( 'quote_approved', [
            'booking_id' => $id,
            'title'      => "Quote Approved: {$booking['booking_ref']}",
            'body'       => "Customer approved the quote of ₹{$quote['amount']}. Proceed to service delivery.",
        ] );

        // M-14: Fire WhatsApp notification hook
        do_action( 's2nri_quote_approved', $id, [
            'booking_ref'   => $booking['booking_ref'],
            'customer_name' => $booking['customer_name'] ?? '',
            'service_name'  => $booking['service_name']  ?? '',
            'amount'        => $quote['amount'],
        ] );

        Response::json( [ 'success' => true, 'message' => 'Quote approved! Our team will begin processing your request. Please complete payment to proceed.' ] );
    }

    // ── Reject quote ──────────────────────────────────────────────────────────

    public function rejectQuote( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $id     = (int) $req->param( 'id' );
        $reason = sanitize_textarea_field( $req->input( 'reason', '' ) );

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }
        if ( $booking['status'] !== 'quote_sent' ) {
            Response::json( [ 'error' => 'No pending quote to reject.' ], 422 ); return;
        }

        $quote_update = $wpdb->update( $p . 's2nri_quotes', [
            'status'        => 'rejected',
            'rejected_at'   => current_time( 'mysql' ),
            'reject_reason' => $reason,
        ], [ 'booking_id' => $id, 'status' => 'pending' ] );

        $booking_update = $wpdb->update( $p . 's2nri_bookings', [
            'status'     => 'under_review',
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        if ( $quote_update === false || $booking_update === false ) {
            error_log( "[S2NRI] Quote rejection DB update failed for booking {$id}: " . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to reject quote. Please try again.' ], 500 ); return;
        }

        $this->logAudit( $id, 'quote_rejected', 'quote_sent', 'under_review' );

        NotificationService::notifyAdmins( 'quote_rejected', [
            'booking_id' => $id,
            'title'      => "Quote Rejected: {$booking['booking_ref']}",
            'body'       => "Customer rejected the quote. Reason: " . ( $reason ?: 'Not specified' ),
        ] );

        Response::json( [ 'success' => true, 'message' => 'Quote rejected. Our team will reach out to discuss further.' ] );
    }

    // ── Submit review ─────────────────────────────────────────────────────────

    public function submitReview( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $id     = (int) $req->param( 'id' );
        $rating = (int) $req->input( 'rating', 0 );
        $review = sanitize_textarea_field( $req->input( 'review', '' ) );

        if ( $rating < 1 || $rating > 5 ) {
            Response::json( [ 'error' => 'Rating must be between 1 and 5.' ], 422 ); return;
        }

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, cu.wp_user_id, cu.id AS cid FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_customers cu ON cu.id = b.customer_id
             WHERE b.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $booking ) { Response::json( [ 'error' => 'Booking not found.' ], 404 ); return; }
        if ( (int) $booking['wp_user_id'] !== $this->user['wp_id'] ) {
            Response::json( [ 'error' => 'Access denied.' ], 403 ); return;
        }
        if ( $booking['status'] !== 'completed' ) {
            Response::json( [ 'error' => 'You can only review completed bookings.' ], 422 ); return;
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_reviews WHERE booking_id = %d LIMIT 1", $id
        ) );
        if ( $existing ) {
            Response::json( [ 'error' => 'You have already submitted a review for this booking.' ], 422 ); return;
        }

        if ( $wpdb->insert( $p . 's2nri_reviews', [
            'booking_id'  => $id,
            'customer_id' => (int) $booking['cid'],
            'rating'      => $rating,
            'review'      => $review,
            'status'      => 'pending',
            'is_published'=> 0,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ] ) === false ) {
            error_log( '[S2NRI] Review insert failed for booking ' . $id . ': ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to submit review. Please try again.' ], 500 ); return;
        }

        Response::json( [ 'success' => true, 'message' => 'Thank you for your review!' ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getBookingDetail( int $id ): ?array {
        global $wpdb;
        $p = $wpdb->prefix;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*,
                    s.name AS service_name, s.slug AS service_slug,
                    s.description AS service_desc, s.turnaround_days, s.required_docs,
                    c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                    COALESCE(u.display_name, u.user_login, '') AS customer_name, u.user_email AS customer_email,
                    cu.phone AS customer_phone, cu.country AS customer_country,
                    cu.address_india AS customer_address,
                    COALESCE(au.display_name, au.user_login, '') AS assigned_name,
                    au.user_email AS assigned_email
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

        if ( ! $booking ) return null;

        // Decode JSON fields
        $booking['field_data'] = json_decode( $booking['field_data'] ?? '{}', true ) ?? [];

        // M-03: Manager contact card — surface assigned manager's contact to customers
        $booking['manager_contact'] = null;
        if ( $booking['assigned_to'] && $booking['assigned_name'] ) {
            $booking['manager_contact'] = [
                'name'   => $booking['assigned_name'],
                'email'  => $booking['assigned_email'] ?? '',
                'phone'  => $booking['assigned_phone'] ?? '',
            ];
        }

        // M-13: Vendor info — surface vendor WhatsApp to staff after assignment
        $booking['vendor'] = null;
        if ( ! empty( $booking['vendor_id'] ) ) {
            $vendor_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, phone, whatsapp, company FROM {$p}s2nri_vendors WHERE id=%d LIMIT 1",
                (int) $booking['vendor_id']
            ), ARRAY_A );
            $booking['vendor'] = $vendor_row ?: null;
        }

        // Quotes
        $booking['quotes'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*, COALESCE(u.display_name, u.user_login, '') AS created_by_name
             FROM {$p}s2nri_quotes q
             LEFT JOIN {$p}users u ON u.ID = q.created_by
             WHERE q.booking_id = %d ORDER BY q.created_at DESC",
            $id
        ), ARRAY_A );

        // Decode quote line_items JSON
        foreach ( $booking['quotes'] as &$q ) {
            if ( ! empty( $q['line_items'] ) ) {
                $q['line_items'] = json_decode( $q['line_items'], true ) ?? [];
            }
        }
        unset( $q );

        // M-05: Compute service_charges / shipping_charges from latest quote line_items
        $latest_quote = $booking['quotes'][0] ?? null;
        $booking['service_charges']  = 0;
        $booking['shipping_charges'] = 0;
        if ( $latest_quote ) {
            $items = is_array( $latest_quote['line_items'] ) ? $latest_quote['line_items'] : [];
            foreach ( $items as $item ) {
                if ( isset( $item['type'] ) && $item['type'] === 'shipping' ) {
                    $booking['shipping_charges'] = (float) ( $item['amount'] ?? 0 );
                } elseif ( isset( $item['type'] ) && $item['type'] === 'service' ) {
                    $booking['service_charges'] = (float) ( $item['amount'] ?? 0 );
                }
            }
            // Fallback: if no typed line items, use raw amounts from booking
            if ( $booking['service_charges'] === 0.0 && $booking['quoted_amount'] ) {
                $booking['service_charges'] = (float) $booking['quoted_amount'] - $booking['shipping_charges'];
            }
        }

        // M-16: Documents split into user-uploaded vs provider-uploaded
        $all_docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, COALESCE(u.display_name, u.user_login, '') AS uploaded_by_name
             FROM {$p}s2nri_documents d
             JOIN {$p}users u ON u.ID = d.uploaded_by
             WHERE d.booking_id = %d AND d.is_visible_to_customer = 1
             ORDER BY d.is_from_staff ASC, d.created_at ASC",
            $id
        ), ARRAY_A );

        $booking['documents']          = $all_docs; // flat list (backward compat)
        $booking['user_documents']     = array_values( array_filter( $all_docs, fn($d) => ! $d['is_from_staff'] ) );
        $booking['provider_documents'] = array_values( array_filter( $all_docs, fn($d) =>  $d['is_from_staff'] ) );

        // Messages (public only for customer view)
        $is_staff = $this->userIsStaff();
        $msg_where = $is_staff ? '' : "AND m.is_internal = 0";
        $booking['messages'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, COALESCE(u.display_name, u.user_login, '') AS sender_name, u.user_email AS sender_email
             FROM {$p}s2nri_messages m
             LEFT JOIN {$p}users u ON u.ID = m.sender_id
             WHERE m.booking_id = %d {$msg_where}
             ORDER BY m.created_at ASC",
            $id
        ), ARRAY_A );

        // Audit log (staff only)
        if ( $is_staff ) {
            $booking['audit_log'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT al.*, COALESCE(u.display_name, u.user_login, '') AS user_name
                 FROM {$p}s2nri_audit_log al
                 LEFT JOIN {$p}users u ON u.ID = al.user_id
                 WHERE al.booking_id = %d ORDER BY al.created_at DESC LIMIT 30",
                $id
            ), ARRAY_A );
        }

        // has_review — React uses this to decide whether to show the 'Leave Review' button
        $booking['has_review'] = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_reviews WHERE booking_id = %d LIMIT 1", $id
        ) );

        // Tickets for Service Requests tab in customer dashboard
        // me = M.tickets || [] in the Na component — must be present in booking response
        $customer_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $this->user['wp_id'] ?? 0
        ), ARRAY_A );
        $cust_id_for_tickets = $customer_row ? (int) $customer_row['id'] : 0;

        $booking['tickets'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.subject, t.status, t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM {$p}s2nri_ticket_messages WHERE ticket_id = t.id) AS message_count,
                    (SELECT COUNT(*) FROM {$p}s2nri_ticket_messages WHERE ticket_id = t.id AND is_from_staff = 1 AND is_read = 0) AS unread_count
             FROM {$p}s2nri_tickets t
             WHERE t.booking_id = %d
             ORDER BY t.updated_at DESC",
            $id
        ), ARRAY_A ) ?: [];

        // M-09: Delivery info string
        if ( $booking['due_date'] && $booking['delivery_working_days'] ) {
            $booking['delivery_info'] = date( 'd M Y', strtotime( $booking['due_date'] ) )
                . ' (' . $booking['delivery_working_days'] . ' working days — excl. Sat, Sun & Govt Holidays)';
        } elseif ( $booking['due_date'] ) {
            $booking['delivery_info'] = date( 'd M Y', strtotime( $booking['due_date'] ) );
        } else {
            $booking['delivery_info'] = null;
        }

        return $booking;
    }

    private function userIsStaff(): bool {
        if ( ! $this->user ) return false;
        return in_array( $this->user['s2nri_role'], [ 'super_admin', 'manager', 'agent', 'finance' ], true )
            || in_array( 'administrator', $this->user['wp_roles'], true );
    }

    private function getCustomerByWpId( int $wp_id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}s2nri_customers WHERE wp_user_id = %d LIMIT 1",
            $wp_id
        ), ARRAY_A ) ?: null;
    }
}
