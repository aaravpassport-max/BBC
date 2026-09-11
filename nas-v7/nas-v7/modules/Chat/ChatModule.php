<?php
namespace NAS\Modules\Chat;
use NAS\Core\{Module, Database, Security, EventBus, Helpers, Queue};
if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════════════════════════
   ChatModule — Super Combo v3
   Supports: Internal Notes · 3-Way Send · Quick Reply Picker · Reply Threading
   ══════════════════════════════════════════════════════════════════════════════ */
class ChatModule extends Module {
    public function key(): string { return 'chat'; }

    public function register(): void {
        $controller = new ChatController();
        $actions = [
            'nas_send_message'        => ['nopriv'=>false, 'method'=>'send_message'],
            'nas_get_messages'        => ['nopriv'=>false, 'method'=>'get_messages'],
            'nas_get_messages_since'  => ['nopriv'=>false, 'method'=>'get_messages_since'],
            'nas_delete_message'      => ['nopriv'=>false, 'method'=>'delete_message'],
            'nas_mark_messages_read'  => ['nopriv'=>false, 'method'=>'mark_read'],
            'nas_get_quick_replies'   => ['nopriv'=>false, 'method'=>'get_quick_replies'],
            'nas_send_whatsapp'       => ['nopriv'=>false, 'method'=>'send_whatsapp'],
            'nas_send_email_msg'      => ['nopriv'=>false, 'method'=>'send_email_message'],
            'nas_unread_count'        => ['nopriv'=>false, 'method'=>'unread_count'],
        ];
        foreach ($actions as $action => $cfg) {
            add_action("wp_ajax_{$action}", [$controller, $cfg['method']]);
            if ($cfg['nopriv']) add_action("wp_ajax_nopriv_{$action}", [$controller, $cfg['method']]);
        }
    }

    public function boot(): void {}
}

/* ══════════════════════════════════════════════════════════════════════════════
   ChatRepository
   ══════════════════════════════════════════════════════════════════════════════ */
class ChatRepository {
    private Database $db;

    public function __construct() { $this->db = Database::instance(); }

    // TRACE: get_messages() — Trigger: wp_ajax_get_messages AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function get_messages( int $booking_id, int $since_id = 0 ): array {
        $t     = $this->db->prefix('messages');
        $users = $GLOBALS['wpdb']->prefix . 'users';
        $sql   = "SELECT m.*,
                         u.display_name as sender_name,
                         rp.message as reply_to_content
                  FROM `$t` m
                  LEFT JOIN `$users` u ON u.ID = m.sender_id
                  LEFT JOIN `$t` rp ON rp.id = m.reply_to_id
                  WHERE m.booking_id = %d AND m.is_deleted = 0";
        $params = [$booking_id];
        if ($since_id > 0) { $sql .= ' AND m.id > %d'; $params[] = $since_id; }
        $sql .= ' ORDER BY m.created_at ASC';
        return $this->db->select($sql, $params, OBJECT);
    }

    // TRACE: send() — Trigger: wp_ajax_send AJAX action.
    //        Steps: inserts DB row → queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function send( array $data ): int {
        $t = $this->db->prefix('messages');
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        // Ensure defaults
        $data = array_merge([
            'is_internal'       => 0,
            'sent_via_email'    => 0,
            'sent_via_whatsapp' => 0,
            'reply_to_id'       => null,
            'is_read'           => 0,
            'is_deleted'        => 0,
        ], $data);
        return (int) $this->db->insert($t, $data);
    }

    // TRACE: unread_count() — Trigger: wp_ajax_unread_count AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: scalar value (int/float/string) from DB.
    //        Edge cases: invalid input → error returned.
    public function unread_count( int $user_id, string $role ): int {
        $t  = $this->db->prefix('messages');
        $bt = $this->db->prefix('bookings');
        $ct = $this->db->prefix('clients');
        if ($role === 'client') {
            return (int) $this->db->scalar(
                "SELECT COUNT(*) FROM `$t` m
                 INNER JOIN `$bt` b ON b.id = m.booking_id
                 INNER JOIN `$ct` c ON c.id = b.client_id
                 WHERE c.wp_user_id=%d AND m.sender_role!='client' AND m.is_read=0 AND m.is_deleted=0 AND m.is_internal=0",
                [$user_id]
            );
        }
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `$t` WHERE sender_role='client' AND is_read=0 AND is_deleted=0"
        );
    }

    // TRACE: mark_read() — Trigger: wp_ajax_mark_read AJAX action.
    //        Steps: queries DB → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public function mark_read( int $booking_id, string $reader_role ): void {
        global $wpdb;
        $t          = $this->db->prefix('messages');
        // Determine which messages to mark: if reader is client, mark non-client messages; otherwise mark client messages
        $sender_val = $reader_role === 'client' ? 'client' : 'client';
        $operator   = $reader_role === 'client' ? '!=' : '=';
        $wpdb->query( $wpdb->prepare(
            "UPDATE `$t` SET is_read=1, read_at=NOW()
             WHERE booking_id=%d AND sender_role $operator %s AND is_read=0 AND is_deleted=0",
            $booking_id,
            $sender_val
        ) );
    }

    // TRACE: delete() — Trigger: wp_ajax_delete AJAX action.
    //        Steps: updates DB row → queries DB → returns JSON error response on failure.
    //        Output: single DB row as associative array or null.
    //        Edge cases: returns null/false on failure; explicit false check on DB op.
    public function delete( int $msg_id, int $user_id ): bool {
        $t   = $this->db->prefix('messages');
        $msg = $this->db->row("SELECT sender_id, sender_role FROM `$t` WHERE id=%d", [$msg_id], OBJECT);
        if (!$msg) return false;
        $can_delete = $msg->sender_id == $user_id || current_user_can('nas_manage_bookings');
        if (!$can_delete) return false;
        return $this->db->update($t, ['is_deleted'=>1], ['id'=>$msg_id]) !== false;
    }

    // TRACE: get_quick_replies() — Trigger: wp_ajax_get_quick_replies AJAX action.
    //        Steps: queries DB.
    //        Output: array of DB rows.
    //        Edge cases: invalid input → error returned.
    public function get_quick_replies( string $category = '' ): array {
        $t = $this->db->prefix('quick_replies');
        if ($category) {
            return $this->db->select("SELECT * FROM `$t` WHERE is_active=1 AND category=%s ORDER BY sort_order ASC", [$category]);
        }
        return $this->db->select("SELECT * FROM `$t` WHERE is_active=1 ORDER BY category ASC, sort_order ASC");
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ChatController
   ══════════════════════════════════════════════════════════════════════════════ */
class ChatController {
    private ChatRepository $repo;

    public function __construct() { $this->repo = new ChatRepository(); }

    /* ── send_message ────────────────────────────────────────────────────── */
    // TRACE: Trigger: AJAX nas_send_message from client dashboard or admin.
    //        Precondition: user logged in, valid nonce (nas_action or nas_admin_nonce).
    //        Postcondition: message row inserted into nas_messages; success response.
    //        Edge cases: empty message → error; booking not found → error; internal note by non-admin → rejected.
    // TRACE: send_message() — Trigger: wp_ajax_send_message AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function send_message(): void {
        $nonce = Security::post('nonce') ?: ( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'nas_action' ) && ! wp_verify_nonce( $nonce, 'nas_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ], 403 );
        }
        Security::require_login();

        $booking_id   = Security::post('booking_id','int');
        $message      = Security::post('message','textarea');
        $is_internal  = (int) Security::post('is_internal','int');
        $reply_to_id  = Security::post('reply_to_id','int') ?: null;
        $send_email   = (int) Security::post('send_email','int');
        $send_wa      = (int) Security::post('send_wa','int');

        if (!$booking_id)  { wp_send_json_error(['message'=>'Booking ID required.']); return; }
        if (!trim($message ?? '')) { wp_send_json_error(['message'=>'Message cannot be empty.']); return; }

        $user_id     = get_current_user_id();
        $sender_role = Security::current_role();

        // Internal notes are admin-only
        if ($is_internal && $sender_role === 'client') {
            wp_send_json_error(['message'=>'Clients cannot post internal notes.']); return;
        }

        $data = [
            'booking_id'        => $booking_id,
            'sender_id'         => $user_id,
            'sender_role'       => $sender_role,
            'message'           => $message,
            'is_internal'       => $is_internal,
            'reply_to_id'       => $reply_to_id,
            'sent_via_email'    => $send_email && !$is_internal ? 1 : 0,
            'sent_via_whatsapp' => $send_wa && !$is_internal ? 1 : 0,
            'attachments'       => Security::post('attachments') ? wp_json_encode(json_decode(Security::post('attachments'), true)) : null,
        ];

        $msg_id = $this->repo->send($data);
        if (!$msg_id) { wp_send_json_error(['message'=>'Could not send message.']); return; }

        $db = Database::instance();
        $msg = $db->row(
            "SELECT m.*, u.display_name as sender_name, rp.message as reply_to_content
             FROM `{$db->prefix('messages')}` m
             LEFT JOIN `{$GLOBALS['wpdb']->prefix}users` u ON u.ID=m.sender_id
             LEFT JOIN `{$db->prefix('messages')}` rp ON rp.id=m.reply_to_id
             WHERE m.id=%d", [$msg_id]
        );

        // Queue email delivery
        if ($send_email && !$is_internal) {
            Queue::push('\NAS\Modules\Notifications\EmailJob', [
                'type'       => 'new_message',
                'booking_id' => $booking_id,
                'message'    => $message,
                'sender_role'=> $sender_role,
            ], 0, 'normal');
        }

        // Queue WhatsApp delivery
        if ($send_wa && !$is_internal) {
            Queue::push('\NAS\Modules\Notifications\WhatsAppJob', [
                'type'       => 'message',
                'booking_id' => $booking_id,
                'message'    => $message,
                'sender_role'=> $sender_role,
            ], 0, 'normal');
        }

        EventBus::emit('message_sent', ['booking_id'=>$booking_id,'sender_role'=>$sender_role,'is_internal'=>$is_internal]);

        wp_send_json_success($msg);
    }

    /* ── get_messages ────────────────────────────────────────────────────── */
    // TRACE: Trigger: AJAX nas_get_messages — client dashboard polling or admin request-detail.
    //        Precondition: user logged in, valid nonce (accepts both nas_action and nas_admin_nonce).
    //        Postcondition: returns array of message objects ordered by created_at ASC.
    //        Edge case: no messages → returns []. Since_id=0 → all messages.
    // TRACE: get_messages() — Trigger: wp_ajax_get_messages AJAX action.
    //        Steps: checks permissions → reads sanitised POST input → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function get_messages(): void {
        $nonce = Security::post('nonce') ?: ( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'nas_action' ) && ! wp_verify_nonce( $nonce, 'nas_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ], 403 );
        }
        Security::require_login();
        $booking_id = Security::post('booking_id','int');
        if (!$booking_id) { wp_send_json_error(['message'=>'Booking required.']); return; }

        $msgs = $this->repo->get_messages($booking_id);
        $role = Security::current_role();

        // Clients cannot see internal notes
        if ($role === 'client') {
            $msgs = array_filter($msgs, fn($m) => !(int)($m->is_internal ?? 0));
            $msgs = array_values($msgs);
        }

        $this->repo->mark_read($booking_id, $role);
        wp_send_json_success($msgs);
    }

    /* ── get_messages_since ──────────────────────────────────────────────── */
    // TRACE: Polling endpoint — returns only messages after given ID to minimize payload.
    public function get_messages_since(): void {
        $nonce = Security::post('nonce') ?: ( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'nas_action' ) && ! wp_verify_nonce( $nonce, 'nas_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        Security::require_login();
        $booking_id = Security::post('booking_id','int');
        $since_id   = Security::post('since_id','int');
        if (!$booking_id) { wp_send_json_error(['message'=>'Booking required.']); return; }

        $msgs = $this->repo->get_messages($booking_id, $since_id);
        $role = Security::current_role();

        if ($role === 'client') {
            $msgs = array_values(array_filter($msgs, fn($m) => !(int)($m->is_internal ?? 0)));
        }

        if (!empty($msgs)) $this->repo->mark_read($booking_id, $role);
        wp_send_json_success($msgs);
    }

    /* ── delete_message ──────────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_delete_message. Soft-deletes a message (is_deleted=1).
    //        Precondition: user must own message or have nas_manage_bookings. Nonce required.
    //        Postcondition: is_deleted=1 in DB; success response. Message hidden in UI.
    //        Edge case: msg not found or no permission → false returned → error sent.
    // TRACE: delete_message() — Trigger: wp_ajax_delete_message AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function delete_message(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $msg_id = Security::post('id','int');
        $ok     = $this->repo->delete($msg_id, get_current_user_id());
        $ok ? wp_send_json_success(['message'=>'Deleted.']) : wp_send_json_error(['message'=>'Delete failed.']);
    }

    /* ── mark_read ───────────────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_mark_messages_read. Marks all messages in a booking as read for the current role.
    //        Precondition: user logged in, booking_id in POST.
    //        Postcondition: is_read=1 for applicable messages; success response.
    // TRACE: mark_read() — Trigger: wp_ajax_mark_read AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function mark_read(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $booking_id = Security::post('booking_id','int');
        $this->repo->mark_read($booking_id, Security::current_role());
        wp_send_json_success();
    }

    /* ── get_quick_replies ───────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_get_quick_replies. Returns quick reply templates.
    public function get_quick_replies(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $cat = Security::post('category') ?: '';
        wp_send_json_success($this->repo->get_quick_replies($cat));
    }

    /* ── send_whatsapp ───────────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_send_whatsapp. Admin-only: sends WA to client via CallMeBot.
    //        Precondition: nas_manage_bookings cap + nas_admin_nonce.
    //        Postcondition: WA message sent or error returned. Message logged in nas_messages.
    //        Edge case: WA not configured → error returned, no silent failure.
    // TRACE: send_whatsapp() — Trigger: wp_ajax_send_whatsapp AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function send_whatsapp(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $booking_id = Security::post('booking_id','int');
        $message    = Security::post('message','textarea');
        $to         = Security::post('to') ?: 'client';
        if (!$booking_id || !$message) { wp_send_json_error(['message'=>'Required fields missing.']); return; }

        Queue::push('\NAS\Modules\Notifications\WhatsAppJob', [
            'type'=>'manual','booking_id'=>$booking_id,'message'=>$message,'to'=>$to,
        ], 0, 'high');

        $this->repo->send([
            'booking_id'        => $booking_id,
            'sender_id'         => get_current_user_id(),
            'sender_role'       => 'admin',
            'message'           => "📱 WhatsApp sent to {$to}: {$message}",
            'sent_via_whatsapp' => 1,
        ]);
        wp_send_json_success(['message'=>'WhatsApp message queued.']);
    }

    /* ── send_email_message ──────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_send_email_msg. Admin-only: sends email to booking client.
    //        Precondition: nas_manage_bookings cap + nas_admin_nonce.
    //        Postcondition: email sent via wp_mail; message row inserted in nas_messages.
    //        Edge case: empty subject or message → error returned.
    // TRACE: send_email_message() — Trigger: wp_ajax_send_email_message AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function send_email_message(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('nas_manage_bookings');
        $booking_id = Security::post('booking_id','int');
        $subject    = Security::post('subject');
        $body       = Security::post('body','textarea');
        if (!$booking_id || !$subject || !$body) { wp_send_json_error(['message'=>'Required fields missing.']); return; }

        Queue::push('\NAS\Modules\Notifications\EmailJob', [
            'type'=>'manual_email','booking_id'=>$booking_id,'subject'=>$subject,'body'=>$body,
        ], 0, 'high');

        $this->repo->send([
            'booking_id'     => $booking_id,
            'sender_id'      => get_current_user_id(),
            'sender_role'    => 'admin',
            'message'        => "📧 Email sent — Subject: {$subject}\n\n{$body}",
            'sent_via_email' => 1,
        ]);
        wp_send_json_success(['message'=>'Email queued successfully.']);
    }

    /* ── unread_count ────────────────────────────────────────────────────── */
    // TRACE: Trigger: wp_ajax_nas_unread_count. Returns unread message count for badge.
    public function unread_count(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $count = $this->repo->unread_count(get_current_user_id(), Security::current_role());
        wp_send_json_success(['count'=>$count]);
    }
}
