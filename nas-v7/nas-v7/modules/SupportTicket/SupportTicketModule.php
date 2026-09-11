<?php
namespace NAS\Modules\SupportTicket;

use NAS\Core\{Module, Database, Security, Config, EventBus, Helpers};
if ( ! defined( 'ABSPATH' ) ) exit;

class SupportTicketModule extends Module {
    public function key(): string { return 'support_ticket'; }

    public function register(): void {
        // Client
        add_action( 'wp_ajax_nas_raise_ticket',         [ TicketController::class, 'raise' ] );
        add_action( 'wp_ajax_nas_get_my_tickets',       [ TicketController::class, 'get_my_tickets' ] );
        add_action( 'wp_ajax_nas_get_ticket_detail',    [ TicketController::class, 'get_detail' ] );
        add_action( 'wp_ajax_nas_reply_ticket',         [ TicketController::class, 'reply' ] );

        // Admin/Staff
        add_action( 'wp_ajax_nas_admin_get_tickets',    [ TicketController::class, 'admin_list' ] );
        add_action( 'wp_ajax_nas_admin_reply_ticket',   [ TicketController::class, 'admin_reply' ] );
        add_action( 'wp_ajax_nas_admin_update_ticket',  [ TicketController::class, 'admin_update' ] );
        add_action( 'wp_ajax_nas_assign_ticket',        [ TicketController::class, 'assign' ] );
    }

    public function boot(): void {}
}

// ── Ticket Service ─────────────────────────────────────────────────────────────
class TicketService {
    private Database $db;
    private Config $cfg;

    public function __construct() {
        $this->db  = Database::instance();
        $this->cfg = Config::instance();
    }

    // TRACE: create() — Trigger: wp_ajax_create AJAX action.
    //        Steps: inserts DB row → queries DB → emits EventBus event.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function create( int $client_id, int $booking_id, string $category, string $subject, string $description ): int {
        $uid = 'TKT-' . strtoupper( substr( md5( uniqid() ), 0, 8 ) );

        $ticket_id = $this->db->insert( $this->db->t('support_tickets'), [
            'ticket_uid'  => $uid,
            'booking_id'  => $booking_id,
            'client_id'   => $client_id,
            'category'    => $category,
            'subject'     => $subject,
            'description' => $description,
            'status'      => 'open',
            'priority'    => 'normal',
        ]);

        // Auto-acknowledgement via email
        $client = $this->db->row("SELECT name, email FROM {$this->db->t('clients')} WHERE id = %d", $client_id);
        if ($client && $client['email']) {
            EventBus::emit('support_ticket_created', [
                'ticket_id'   => $ticket_id,
                'ticket_uid'  => $uid,
                'client_id'   => $client_id,
                'client_name' => $client['name'],
                'client_email'=> $client['email'],
                'subject'     => $subject,
            ]);
        }

        return (int) $ticket_id;
    }

    // TRACE: add_reply() — Trigger: wp_ajax_add_reply AJAX action.
    //        Steps: inserts DB row → updates DB row → returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public function add_reply( int $ticket_id, int $sender_id, string $sender_type, string $message, bool $internal = false ): int {
        $reply_id = $this->db->insert( $this->db->t('ticket_replies'), [
            'ticket_id'   => $ticket_id,
            'sender_id'   => $sender_id,
            'sender_type' => $sender_type,
            'message'     => $message,
            'is_internal' => $internal ? 1 : 0,
        ]);

        // Update ticket updated_at and status
        $new_status = $sender_type === 'client' ? 'open' : 'waiting_client';
        $this->db->update( $this->db->t('support_tickets'), ['status' => $new_status], ['id' => $ticket_id] );

        return (int) $reply_id;
    }
}

// ── Ticket Controller ──────────────────────────────────────────────────────────
class TicketController {

    // TRACE: get_client_id() — Trigger: wp_ajax_get_client_id AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    private static function get_client_id(): int {
        $db     = Database::instance();
        $client = $db->row("SELECT id FROM {$db->t('clients')} WHERE wp_user_id = %d", get_current_user_id());
        return $client ? (int) $client['id'] : 0;
    }

    // TRACE: raise() — Trigger: wp_ajax_raise AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function raise(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();

        $client_id   = self::get_client_id();
        if ( ! $client_id ) { wp_send_json_error(['message' => 'Client not found']); return; }

        $booking_id  = (int) Security::post('booking_id');
        $category    = sanitize_text_field( Security::post('category') ?: 'general' );
        $subject     = sanitize_text_field( Security::post('subject') );
        $description = sanitize_textarea_field( Security::post('description') );

        if ( ! $subject || ! $description ) {
            wp_send_json_error(['message' => 'Subject and description are required']);
            return;
        }

        $svc       = new TicketService();
        $ticket_id = $svc->create($client_id, $booking_id, $category, $subject, $description);
        wp_send_json_success(['ticket_id' => $ticket_id, 'message' => 'Ticket raised. You will receive an email confirmation.']);
    }

    // TRACE: get_my_tickets() — Trigger: wp_ajax_get_my_tickets AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_my_tickets(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $client_id = self::get_client_id();
        if ( ! $client_id ) { wp_send_json_success(['tickets' => []]); return; }

        $db      = Database::instance();
        $page    = max(1, (int)Security::post('page'));
        $limit   = 10;
        $offset  = ($page-1)*$limit;
        $status  = sanitize_text_field( Security::post('status') ?? '' );

        $where = "client_id = %d";
        $args  = [$client_id];
        if ($status) { $where .= " AND status = %s"; $args[] = $status; }

        $total   = (int) $db->row("SELECT COUNT(*) as c FROM {$db->t('support_tickets')} WHERE $where", $args)['c'];
        $args[]  = $limit; $args[] = $offset;
        $tickets = $db->select("SELECT * FROM {$db->t('support_tickets')} WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $args);

        wp_send_json_success(['tickets' => $tickets, 'total' => $total, 'page' => $page]);
    }

    // TRACE: get_detail() — Trigger: wp_ajax_get_detail AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_detail(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $ticket_id = (int) Security::post('ticket_id');
        $db        = Database::instance();
        $client_id = self::get_client_id();

        $ticket = $db->row("SELECT * FROM {$db->t('support_tickets')} WHERE id = %d AND client_id = %d", $ticket_id, $client_id);
        if ( ! $ticket ) { wp_send_json_error(['message' => 'Ticket not found']); return; }

        $replies = $db->select(
            "SELECT r.*, u.display_name as sender_name FROM {$db->t('ticket_replies')} r
             LEFT JOIN {$db->prefix}users u ON u.ID = r.sender_id
             WHERE r.ticket_id = %d AND r.is_internal = 0 ORDER BY r.created_at ASC",
            $ticket_id
        );

        wp_send_json_success(['ticket' => $ticket, 'replies' => $replies]);
    }

    // TRACE: reply() — Trigger: wp_ajax_reply AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function reply(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $ticket_id = (int) Security::post('ticket_id');
        $message   = sanitize_textarea_field( Security::post('message') );
        if ( ! $message ) { wp_send_json_error(['message' => 'Message is required']); return; }

        $db        = Database::instance();
        $client_id = self::get_client_id();
        $ticket    = $db->row("SELECT * FROM {$db->t('support_tickets')} WHERE id = %d AND client_id = %d", $ticket_id, $client_id);
        if ( ! $ticket ) { wp_send_json_error(['message' => 'Ticket not found']); return; }

        $svc = new TicketService();
        $svc->add_reply($ticket_id, get_current_user_id(), 'client', $message);
        wp_send_json_success(['message' => 'Reply sent']);
    }

    // TRACE: admin_list() — Trigger: wp_ajax_admin_list AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function admin_list(): void {
        // FIX (audit): called from templates/admin/pages/clients.php, which sources its nonce
        // from the shared nas-admin-config (templates/admin/layout.php:47, action
        // 'nas_admin_nonce') — not 'nas_action'. Same bug pattern found ~27 other times this
        // session across dashboards/AdminDashboard.php and modules/Admin/AdminModule.php.
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $db     = Database::instance();
        $page   = max(1, (int)($_POST['page'] ?? 1));
        $limit  = 20;
        $offset = ($page-1)*$limit;
        $status = sanitize_text_field($_POST['status'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $where = '1=1';
        $args  = [];
        if ($status) { $where .= ' AND t.status = %s'; $args[] = $status; }
        if ($search) {
            $like = $db->esc_like($search);
            $where .= ' AND (t.subject LIKE %s OR t.ticket_uid LIKE %s OR cl.name LIKE %s)';
            $args = array_merge($args, ["%$like%","%$like%","%$like%"]);
        }
        $args2 = array_merge($args, [$limit, $offset]);

        $tickets = $db->select(
            "SELECT t.*, cl.name as client_name, cl.email as client_email
             FROM {$db->t('support_tickets')} t
             LEFT JOIN {$db->t('clients')} cl ON cl.id = t.client_id
             WHERE $where ORDER BY t.created_at DESC LIMIT %d OFFSET %d",
            $args2
        );

        wp_send_json_success(['tickets' => $tickets, 'page' => $page]);
    }

    // TRACE: admin_reply() — Trigger: wp_ajax_admin_reply AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_reply(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $ticket_id  = (int) Security::post('ticket_id');
        $message    = sanitize_textarea_field( Security::post('message') );
        $is_internal= (bool) Security::post('internal');
        $svc        = new TicketService();
        $svc->add_reply($ticket_id, get_current_user_id(), 'staff', $message, $is_internal);
        wp_send_json_success(['message' => 'Reply sent']);
    }

    // TRACE: admin_update() — Trigger: wp_ajax_admin_update AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_update(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $ticket_id = (int) Security::post('ticket_id');
        $status    = sanitize_text_field( Security::post('status') );
        $priority  = sanitize_text_field( Security::post('priority') );
        $db        = Database::instance();
        $data      = [];
        if ($status) $data['status'] = $status;
        if ($priority) $data['priority'] = $priority;
        if ($status === 'resolved') $data['resolved_at'] = gmdate('Y-m-d H:i:s');
        $db->update($db->t('support_tickets'), $data, ['id' => $ticket_id]);
        wp_send_json_success(['message' => 'Ticket updated']);
    }

    // TRACE: assign() — Trigger: wp_ajax_assign AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function assign(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $ticket_id   = (int) Security::post('ticket_id');
        $assigned_to = (int) Security::post('user_id');
        $db          = Database::instance();
        $db->update($db->t('support_tickets'), ['assigned_to' => $assigned_to, 'status' => 'in_progress'], ['id' => $ticket_id]);
        wp_send_json_success(['message' => 'Assigned']);
    }

    /**
     * Public support ticket submission — from support page form and client dashboard
     * Works for both logged-in and guest users (guests need to provide email)
     */
    // TRACE: public_submit() — Trigger: wp_ajax_public_submit AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function public_submit(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );

        $name        = sanitize_text_field( Security::post('name') ?: (is_user_logged_in() ? wp_get_current_user()->display_name : '') );
        $email       = sanitize_email( Security::post('email') ?: (is_user_logged_in() ? wp_get_current_user()->user_email : '') );
        $subject     = sanitize_text_field( Security::post('subject') );
        $description = sanitize_textarea_field( Security::post('message') ?: Security::post('description') );
        $category    = sanitize_text_field( Security::post('category') ?: 'general' );
        $booking_uid = sanitize_text_field( Security::post('booking_uid') );

        if ( ! $name || ! $email )        wp_send_json_error(['message' => 'Name and email are required.']);
        if ( ! $subject )                  wp_send_json_error(['message' => 'Please enter a subject for your inquiry.']);
        if ( ! $description || strlen($description) < 10 ) wp_send_json_error(['message' => 'Please describe your issue in a bit more detail (at least 10 characters).']);

        $db        = Database::instance();
        $client_id = 0;

        // Get or create client record
        if ( is_user_logged_in() ) {
            $client = $db->row("SELECT id FROM {$db->t('clients')} WHERE wp_user_id = %d", get_current_user_id());
            if ($client) $client_id = (int)$client['id'];
        }

        if ( ! $client_id ) {
            $existing = $db->row("SELECT id FROM {$db->t('clients')} WHERE email = %s", $email);
            $client_id = $existing ? (int)$existing['id'] : 0;
        }

        // Find booking if uid provided
        $booking_id = 0;
        if ($booking_uid) {
            $bk = $db->row("SELECT id FROM {$db->t('bookings')} WHERE uid = %s", $booking_uid);
            if ($bk) $booking_id = (int)$bk['id'];
        }

        $uid = 'TKT-' . strtoupper( substr( md5( uniqid($email, true) ), 0, 8 ) );

        $ticket_id = $db->insert( $db->t('support_tickets'), [
            'ticket_uid'  => $uid,
            'booking_id'  => $booking_id,
            'client_id'   => $client_id,
            'category'    => $category,
            'subject'     => $subject,
            'description' => $description,
            'status'      => 'open',
            'priority'    => 'normal',
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        if ( ! $ticket_id ) {
            wp_send_json_error(['message' => 'Failed to create support ticket. Please try again.']);
        }

        // Send acknowledgement email to client
        $cfg   = \NAS\Core\Config::instance();
        $brand = $cfg->get('brand_name', get_bloginfo('name'));
        $dash  = home_url('/client-dashboard/');

        wp_mail(
            $email,
            "[{$brand}] Support Request Received — {$uid}",
            "Hi {$name},

We've received your support request (ID: {$uid}) and our team will respond within 4 hours.

Subject: {$subject}

You can track your ticket from your dashboard:
{$dash}

Thank you!
— {$brand} Support Team"
        );

        // Notify admin
        wp_mail(
            get_option('admin_email'),
            "[{$brand}] New Support Ticket #{$uid}: {$subject}",
            "New ticket from {$name} ({$email}):

Category: {$category}
Subject: {$subject}

{$description}"
        );

        wp_send_json_success([
            'message'    => 'Your support request has been submitted! Ticket ID: ' . $uid . '. You will receive a confirmation email shortly.',
            'ticket_uid' => $uid,
        ]);
    }

}

// ── Missing public endpoint fix — nas_submit_support_ticket ─────────────────
add_action('wp_ajax_nas_submit_support_ticket',        ['NAS\Modules\SupportTicket\TicketController', 'public_submit']);
add_action('wp_ajax_nopriv_nas_submit_support_ticket', ['NAS\Modules\SupportTicket\TicketController', 'public_submit']);
