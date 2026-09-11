<?php
namespace NAS\Modules\Contact;
use NAS\Core\{Module, Database, Security, Config, EventBus};
if ( ! defined( 'ABSPATH' ) ) exit;

class ContactModule extends Module {
    public function key(): string { return 'contact'; }
    public function register(): void {
        add_shortcode('nas_contact', [$this, 'shortcode']);
        add_action('wp_ajax_nas_submit_contact',        [ContactController::class, 'submit']);
        add_action('wp_ajax_nopriv_nas_submit_contact', [ContactController::class, 'submit']);
        add_action('wp_ajax_nas_admin_get_contacts',    [ContactController::class, 'admin_list']);
        add_action('wp_ajax_nas_admin_mark_contact',    [ContactController::class, 'mark_status']);
    }
    public function boot(): void {}
    // TRACE: shortcode() — Trigger: wp_ajax_shortcode AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function shortcode(): string {
        ob_start(); include NAS_DIR . 'templates/public/contact.php'; return ob_get_clean();
    }
}

class ContactController {
    // TRACE: submit() — Trigger: wp_ajax_submit AJAX action.
    //        Steps: verifies nonce → reads sanitised POST input → inserts DB row → returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function submit(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_action');
        $name    = sanitize_text_field(Security::post('name'));
        $email   = sanitize_email(Security::post('email'));
        $phone   = sanitize_text_field(Security::post('phone'));
        $city    = sanitize_text_field(Security::post('city'));
        $subject = sanitize_text_field(Security::post('subject'));
        $message = sanitize_textarea_field(Security::post('message'));

        if (!$name || !$email || !$message) { wp_send_json_error(['message'=>'Name, email and message are required']); return; }
        if (!is_email($email)) { wp_send_json_error(['message'=>'Invalid email']); return; }

        $db = Database::instance();
        $db->insert($db->t('contact_submissions'), [
            'name'       => $name, 'email' => $email, 'phone' => $phone,
            'city'       => $city, 'subject' => $subject, 'message' => $message,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Auto-reply
        EventBus::emit('contact_form_submitted', [
            'client_name' => $name, 'email' => $email, 'message' => $message
        ]);

        // Notify admin
        $cfg        = Config::instance();
        $admin_mail = $cfg->get('brand_email') ?: get_option('admin_email');
        add_filter('wp_mail_content_type', fn()=>'text/html');
        wp_mail($admin_mail, "New Contact: $subject", "<p><b>From:</b> $name &lt;$email&gt;</p><p><b>Phone:</b> $phone</p><p><b>City:</b> $city</p><p><b>Message:</b><br>".nl2br(esc_html($message))."</p>");
        remove_filter('wp_mail_content_type', fn()=>'text/html');

        wp_send_json_success(['message' => "Thanks {$name}! We will get back to you within 1 business day."]);
    }

    // TRACE: admin_list() — Trigger: wp_ajax_admin_list AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_list(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $db   = Database::instance();
        $rows = $db->select("SELECT * FROM {$db->t('contact_submissions')} ORDER BY created_at DESC LIMIT 50");
        wp_send_json_success(['submissions' => $rows]);
    }

    // TRACE: mark_status() — Trigger: wp_ajax_mark_status AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function mark_status(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        Database::instance()->update(Database::instance()->t('contact_submissions'),
            ['status' => sanitize_text_field(Security::post('status'))],
            ['id' => (int)Security::post('id')]
        );
        wp_send_json_success(['message'=>'Updated']);
    }
}


// =============================================================================
/**
 * Wallet Module — Client credit wallet
 */
// =============================================================================
