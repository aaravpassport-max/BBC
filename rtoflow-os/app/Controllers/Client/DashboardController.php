<?php

namespace RTOFLOW\Controllers\Client;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Security\FileUploadGuard;
use RTOFLOW\Services\LeadService;
use RTOFLOW\Services\PaymentService;

if (!defined('ABSPATH')) exit;

class DashboardController
{
    private LeadService    $leads;
    private PaymentService $payments;

    public function __construct(LeadService $leads, PaymentService $payments)
    {
        $this->leads    = $leads;
        $this->payments = $payments;
    }

    public function index(): void
    {
        if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }

        global $wpdb;
        $userId = get_current_user_id();
        $p      = $wpdb->prefix;

        // P6-UX-008 FIX: add pagination support
        $page    = max(1, (int)($_GET['paged'] ?? 1));
        $myLeads = $this->leads->getLeads(['client_id' => $userId], $page, 10);

        $recentPayments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, l.lead_number FROM {$p}rto_payments p
             JOIN {$p}rto_leads l ON l.id=p.lead_id
             WHERE l.client_id=%d ORDER BY p.created_at DESC LIMIT 5",
            $userId
        ), ARRAY_A) ?: [];

        $activeCount    = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE client_id=%d AND status NOT IN ('completed','cancelled') AND deleted_at IS NULL",
            $userId
        ));
        $completedCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE client_id=%d AND status='completed' AND deleted_at IS NULL",
            $userId
        ));
        $totalSpent     = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(p.amount),0) FROM {$p}rto_payments p
             JOIN {$p}rto_leads l ON l.id=p.lead_id WHERE l.client_id=%d AND p.status='completed'",
            $userId
        ));

        rto_view('client.dashboard.index', compact('myLeads','recentPayments','activeCount','completedCount','totalSpent','page'));
    }

    public function showOrder(int $leadId): void
    {
        if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }

        $lead = $this->leads->getLead($leadId);
        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) wp_die('Order not found or access denied.', 'Not Found', ['response' => 404, 'back_link' => true]);

        global $wpdb;
        $p = $wpdb->prefix;
        $payments  = $this->payments->getPaymentsForLead($leadId);
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, dt.name as doc_type_name FROM {$p}rto_documents d
             LEFT JOIN {$p}rto_doc_types dt ON dt.id=d.doc_type_id
             WHERE d.lead_id=%d ORDER BY d.created_at DESC", $leadId
        ), ARRAY_A) ?: [];
        $messages  = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.display_name FROM {$p}rto_messages m
             LEFT JOIN {$p}users u ON u.ID=m.user_id
             WHERE m.lead_id=%d ORDER BY m.created_at ASC", $leadId
        ), ARRAY_A) ?: [];
        $invoice   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}rto_invoices WHERE lead_id=%d ORDER BY id DESC LIMIT 1", $leadId
        ), ARRAY_A);

        // ENTERPRISE GAP FIX (critical missing functionality — clients had
        // no way to submit a rating anywhere: RatingsController::store() was
        // fully implemented server-side but unreachable from any view. Load
        // whether this client already rated this order so the widget below
        // can hide itself post-submission rather than allow a duplicate.
        $myRating = $wpdb->get_row($wpdb->prepare(
            "SELECT id, score, comment FROM {$p}rto_ratings WHERE lead_id=%d AND rated_by=%d AND deleted_at IS NULL",
            $leadId, get_current_user_id()
        ), ARRAY_A);

        // Mark messages as read
        $wpdb->query($wpdb->prepare(
            "UPDATE {$p}rto_messages SET read_at=NOW() WHERE lead_id=%d AND user_id!=%d AND read_at IS NULL",
            $leadId, get_current_user_id()
        ));

        rto_view('client.orders.show', compact('lead','payments','documents','messages','invoice','myRating'));
    }

    // ── Initiate Razorpay payment (AJAX) ──────────────────────────────────
    // Known Limitations audit fix (platform-wide sweep, found while
    // investigating the same class of bug in NotificationService — see its
    // sendSms()/sendWhatsApp() comment for the full explanation): all three
    // RTOFLOW_Razorpay:: calls in this method and verifyPayment() below were
    // unqualified references to a GLOBAL-namespace class from this
    // namespaced file (RTOFLOW\Controllers\Client) — PHP does not fall back
    // to the global namespace for an unqualified class reference, so every
    // one of createOrder()/verifyPayment()/fetchPayment() threw an uncaught
    // "Class not found" Error the instant it ran. This is the entire
    // client-facing online-payment flow: initiating a Razorpay order,
    // verifying the callback, and reconciling the captured amount — meaning
    // no client could ever complete an online payment through this
    // controller. Fixed by fully qualifying all three references with a
    // leading backslash (Bootstrap.php's own \RTOFLOW_Razorpay::isEnabled()
    // call already did this correctly, which is what made the inconsistency
    // visible once compared side by side).
    public function initiatePayment(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!check_ajax_referer('rto_client_pay', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('payment')) \RTOFLOW\Http\Middleware\RateLimiter::abort();

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $lead   = $this->leads->getLead($leadId);

        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) rto_json_err('Order not found.', 404);
        if ($lead['payment_status'] === 'paid') rto_json_err('This order is already paid.');

        $pendingAmount = (float)$lead['total_amount'] - (float)$lead['paid_amount'];
        $result        = \RTOFLOW_Razorpay::createOrder($pendingAmount, $leadId, ['lead_number' => $lead['lead_number']]);

        if (!$result['success']) {
            // ENTERPRISE GAP FIX (Phase 12, item — "single-provider
            // dependency for ... payments ... no fallback provider"): a bare
            // gateway error left the client stuck with no path forward.
            // Detects a real outage (repeated failures, not one blip) and
            // both alerts an admin AND tells the client staff can collect
            // payment manually (PaymentService::record()'s existing
            // 'manual' method) instead of leaving them at a dead end.
            \RTOFLOW\Services\PaymentGatewayHealthService::recordFailure('client_initiate_payment lead_id=' . $leadId);
            $message = \RTOFLOW\Services\PaymentGatewayHealthService::isDegraded()
                ? 'Online payment is temporarily unavailable. Our team has been notified — please contact support to arrange payment by another method for your order.'
                : $result['message'];
            rto_json_err($message);
        }
        \RTOFLOW\Services\PaymentGatewayHealthService::recordSuccess();
        rto_json_ok($result);
    }

    // ── Verify payment callback (AJAX) ────────────────────────────────────
    public function verifyPayment(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!check_ajax_referer('rto_client_pay', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $razorpayOrderId  = Sanitiser::text($_POST['razorpay_order_id']   ?? '');
        $razorpayPayment  = Sanitiser::text($_POST['razorpay_payment_id'] ?? '');
        $razorpaySignature= Sanitiser::text($_POST['razorpay_signature']  ?? '');
        $leadId           = Sanitiser::int($_POST['lead_id'] ?? 0, 1);

        if (!\RTOFLOW_Razorpay::verifyPayment($razorpayOrderId, $razorpayPayment, $razorpaySignature)) {
            rto_json_err('Payment verification failed. Please contact support.');
        }

        $lead   = $this->leads->getLead($leadId);
        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) rto_json_err('Order not found.', 404);

        $dueAmount = (float)$lead['total_amount'] - (float)$lead['paid_amount'];

        // FIX P2: previously the signature was verified (proving Razorpay
        // authorized *some* payment tied to this order/payment id pair) but
        // the amount actually captured at Razorpay's end was never checked —
        // the locally-computed "amount due" was recorded as paid on faith.
        // fetchPayment() asks Razorpay directly what it captured and confirms
        // it matches, within one paisa for floating-point rounding, before
        // any payment row is written.
        $gatewayPayment = \RTOFLOW_Razorpay::fetchPayment($razorpayPayment);
        if (!$gatewayPayment['success']) {
            \RTOFLOW\Services\AuditService::log('payment.reconciliation_failed', $leadId, [
                'reason' => 'gateway_fetch_failed', 'razorpay_payment_id' => $razorpayPayment,
            ]);
            rto_json_err('Could not confirm this payment with the gateway. Please contact support before retrying.');
        }
        $capturedAmount = ((float)($gatewayPayment['data']['amount'] ?? 0)) / 100; // paise → rupees
        $capturedStatus = $gatewayPayment['data']['status'] ?? '';
        if ($capturedStatus !== 'captured' || abs($capturedAmount - $dueAmount) > 0.01) {
            \RTOFLOW\Services\AuditService::log('payment.reconciliation_mismatch', $leadId, [
                'expected_amount' => $dueAmount,
                'captured_amount' => $capturedAmount,
                'captured_status' => $capturedStatus,
                'razorpay_payment_id' => $razorpayPayment,
            ]);
            rto_json_err('The amount confirmed by the payment gateway does not match this order. Please contact support — no charge has been recorded against your order.');
        }

        $result = $this->payments->record([
            'lead_id'     => $leadId,
            'amount'      => $dueAmount,
            'method'      => 'razorpay',
            'txn_id'      => $razorpayPayment,
            'gateway_ref' => $razorpayOrderId,
        ]);

        $result['success'] ? rto_json_ok($result, 'Payment successful!') : rto_json_err($result['message']);
    }

    // ── Upload document (AJAX) ────────────────────────────────────────────
    public function uploadDocument(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!check_ajax_referer('rto_client_upload', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('upload')) \RTOFLOW\Http\Middleware\RateLimiter::abort();

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $lead   = $this->leads->getLead($leadId);
        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) rto_json_err('Order not found.', 404);

        if (empty($_FILES['file'])) rto_json_err('No file uploaded.');
        $stored = FileUploadGuard::store($_FILES['file'], 'documents', $leadId);
        if (!$stored['success']) rto_json_err($stored['error']);

        global $wpdb;
        // BUGFIX (ghost-success sweep, same class of bug fixed in the
        // equivalent vendor-side upload handler this pass): the file was
        // already physically written to disk above; a silently failed insert
        // here previously left an orphaned file with no database record while
        // the client was told the upload succeeded.
        $docInserted = $wpdb->insert($wpdb->prefix . 'rto_documents', [
            'lead_id'      => $leadId,
            'doc_type_id'  => Sanitiser::int($_POST['doc_type_id'] ?? 0),
            'file_path'    => $stored['path'],
            'file_name'    => $stored['name'],
            'file_size_kb' => $stored['size_kb'],
            'mime_type'    => $_FILES['file']['type'],
            'status'       => 'pending',
            'uploaded_by'  => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ]);

        if (!$docInserted) {
            error_log('RTOFLOW Client DashboardController: document row insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            \RTOFLOW\Security\FileUploadGuard::delete($stored['path']);
            rto_json_err('Unable to save the uploaded document. Please try again.');
        }

        rto_json_ok(null, 'Document uploaded successfully. Our team will verify it shortly.');
    }

    // ── Submit message (AJAX) ─────────────────────────────────────────────
    public function sendMessage(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!check_ajax_referer('rto_client_msg', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('submit')) \RTOFLOW\Http\Middleware\RateLimiter::abort();

        $leadId  = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $message = Sanitiser::text($_POST['message'] ?? '', 2000);

        if (!$message) rto_json_err('Message cannot be empty.');

        $lead = $this->leads->getLead($leadId);
        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) rto_json_err('Order not found.', 404);

        global $wpdb;
        // BUGFIX (ghost-success sweep): a silently failed insert here
        // previously still returned "Message sent." to the client, with the
        // message text echoed back in the response as if it had been stored
        // — the client would see their own message appear to send
        // successfully while nothing was actually saved for staff to read.
        $msgInserted = $wpdb->insert($wpdb->prefix . 'rto_messages', [
            'lead_id'     => $leadId,
            'user_id'     => get_current_user_id(),
            'message'     => $message,
            'sender_type' => 'client',
            'created_at'  => current_time('mysql'),
        ]);

        if (!$msgInserted) {
            error_log('RTOFLOW Client DashboardController: message insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            rto_json_err('Unable to send your message. Please try again.');
        }

        rto_json_ok(['message' => $message, 'time' => current_time('mysql')], 'Message sent.');
    }

    // ── ENTERPRISE GAP FIX (Phase 1, item 2 — client self-service
    // cancellation/refund request) ──────────────────────────────────────────
    // TRACE: client clicks "Request Cancellation" or "Request Refund" on
    //        their order detail page → enters a reason → verifies nonce,
    //        confirms the lead genuinely belongs to this client, blocks a
    //        second identical request while one is already pending (a
    //        client mashing the button does not flood the queue with
    //        duplicates) → inserts one rto_client_requests row →
    //        precondition: lead belongs to this client, lead is not already
    //        cancelled (for a cancellation request), reason is non-empty →
    //        postcondition: one new 'pending' rto_client_requests row,
    //        visible to staff on the new Client Requests admin screen →
    //        edge cases: order not found/not owned → 404; already a pending
    //        request of the same type → rejected with a clear message
    //        instead of a silent duplicate; insert failure → surfaced, not
    //        swallowed (same ghost-success discipline as sendMessage() above).
    public function requestCancellationOrRefund(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!check_ajax_referer('rto_client_request', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('submit')) \RTOFLOW\Http\Middleware\RateLimiter::abort();

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $type   = Sanitiser::text($_POST['request_type'] ?? '', 20);
        $reason = Sanitiser::text($_POST['reason'] ?? '', 1000);

        if (!in_array($type, ['cancellation', 'refund'], true)) rto_json_err('Invalid request type.');
        if ($reason === '') rto_json_err('Please describe why you are requesting this.');

        $lead = $this->leads->getLead($leadId);
        if (!$lead || (int)$lead['client_id'] !== get_current_user_id()) rto_json_err('Order not found.', 404);

        if ($type === 'cancellation' && in_array($lead['status'], ['cancelled', 'completed'], true)) {
            rto_json_err('This order is already ' . $lead['status'] . ' and cannot be cancelled.');
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $existingPending = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}rto_client_requests WHERE lead_id=%d AND request_type=%s AND status='pending' LIMIT 1",
            $leadId, $type
        ));
        if ($existingPending) {
            rto_json_err('You already have a pending ' . $type . ' request for this order — our team will respond soon.');
        }

        $inserted = $wpdb->insert($p . 'rto_client_requests', [
            'lead_id'      => $leadId,
            'client_id'    => get_current_user_id(),
            'request_type' => $type,
            'reason'       => $reason,
            'status'       => 'pending',
            'created_at'   => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW Client DashboardController: client_requests insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            rto_json_err('Unable to submit your request. Please try again.');
        }

        \RTOFLOW\Services\AuditService::log('client_request.submitted', $leadId, [
            'request_type' => $type, 'client_id' => get_current_user_id(),
        ]);

        rto_json_ok(null, ucfirst($type) . ' request submitted — our team will review it shortly.');
    }
}
