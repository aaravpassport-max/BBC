<?php
namespace NAS\Modules\EmailTemplate;

use NAS\Core\{Module, Database, Security, Config};
if ( ! defined( 'ABSPATH' ) ) exit;

class EmailTemplateModule extends Module {
    public function key(): string { return 'email_template'; }

    public function register(): void {
        add_action( 'wp_ajax_nas_get_email_templates',    [ EmailTemplateController::class, 'list_templates' ] );
        add_action( 'wp_ajax_nas_get_email_template',     [ EmailTemplateController::class, 'get_template' ] );
        add_action( 'wp_ajax_nas_save_email_template',    [ EmailTemplateController::class, 'save_template' ] );
        add_action( 'wp_ajax_nas_preview_email_template', [ EmailTemplateController::class, 'preview' ] );
        add_action( 'wp_ajax_nas_test_send_email',        [ EmailTemplateController::class, 'test_send' ] );
        add_action( 'wp_ajax_nas_toggle_email_template',  [ EmailTemplateController::class, 'toggle' ] );
        add_action( 'wp_ajax_nas_reset_email_template',   [ EmailTemplateController::class, 'reset_to_default' ] );
    }

    public function boot(): void {}

    /**
     * Fetch a template and replace placeholders with real or sample data.
     */
    // TRACE: render() — Trigger: wp_ajax_render AJAX action.
    //        Steps: queries DB.
    //        Output: single DB row as associative array or null.
    //        Edge cases: invalid input → error returned.
    public static function render( string $template_key, array $vars = [] ): array {
        $db  = Database::instance();
        $tpl = $db->row(
            "SELECT * FROM {$db->t('email_templates')} WHERE template_key = %s AND is_enabled = 1",
            $template_key
        );
        if ( ! $tpl ) return [ 'subject' => '', 'body' => '' ];

        $cfg          = Config::instance();
        $brand_name   = $cfg->get('brand_name','NewspaperAds Pro');
        $logo_url     = $cfg->get('logo_url','');
        $dashboard_url= get_permalink(get_option('nas_page_client_dashboard')) ?: home_url('/client-dashboard/');
        $booking_url  = get_permalink(get_option('nas_page_booking')) ?: home_url('/book-newspaper-ad/');

        $defaults = [
            '{brand_name}'       => $brand_name,
            '{logo_url}'         => $logo_url,
            '{dashboard_link}'   => $dashboard_url,
            '{booking_link}'     => $booking_url,
            '{client_name}'      => $vars['client_name'] ?? 'Valued Customer',
            '{order_id}'         => $vars['order_id'] ?? 'N/A',
            '{newspaper_name}'   => $vars['newspaper_name'] ?? 'Your Newspaper',
            '{category}'         => $vars['category'] ?? 'Classified',
            '{total_price}'      => $vars['total_price'] ?? '₹0.00',
            '{publication_date}' => $vars['publication_date'] ?? 'TBD',
            '{rejection_reason}' => $vars['rejection_reason'] ?? 'Does not meet specifications',
            '{ticket_id}'        => $vars['ticket_id'] ?? 'TKT-00001',
            '{subject}'          => $vars['subject'] ?? 'Support Query',
            '{message}'          => $vars['message'] ?? '',
            '{material_status}'  => $vars['material_status'] ?? 'Pending',
        ];

        $merged  = array_merge($defaults, $vars);
        $subject = str_replace(array_keys($merged), array_values($merged), $tpl['subject']);
        $body    = str_replace(array_keys($merged), array_values($merged), $tpl['html_body']);

        return ['subject' => $subject, 'body' => $body];
    }
}

// ── Email Template Controller ──────────────────────────────────────────────────
class EmailTemplateController {

    // TRACE: list_templates() — Trigger: wp_ajax_list_templates AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function list_templates(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('manage_options');
        $db   = Database::instance();
        $tpls = $db->select("SELECT id, template_key, name, subject, is_enabled, updated_at FROM {$db->t('email_templates')} ORDER BY name ASC");
        wp_send_json_success(['templates' => $tpls]);
    }

    // TRACE: get_template() — Trigger: wp_ajax_get_template AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_template(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('manage_options');
        $key  = sanitize_text_field( Security::post('template_key') );
        $db   = Database::instance();
        $tpl  = $db->row("SELECT * FROM {$db->t('email_templates')} WHERE template_key = %s", $key);
        wp_send_json_success(['template' => $tpl]);
    }

    // TRACE: save_template() — Trigger: wp_ajax_save_template AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save_template(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('manage_options');
        $id      = (int) Security::post('id');
        $subject = sanitize_text_field( Security::post('subject') );
        $body    = wp_kses_post( Security::post('html_body') );
        $db      = Database::instance();
        $db->update( $db->t('email_templates'), [
            'subject'   => $subject,
            'html_body' => $body,
        ], ['id' => $id] );
        wp_send_json_success(['message' => 'Template saved']);
    }

    // TRACE: preview() — Trigger: wp_ajax_preview AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function preview(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('manage_options');
        $key    = sanitize_text_field( Security::post('template_key') );
        $result = EmailTemplateModule::render($key, [
            'client_name'    => 'Rahul Sharma',
            'order_id'       => 'BK-10042',
            'newspaper_name' => 'Times of India, Delhi',
            'category'       => 'Matrimonial',
            'total_price'    => '₹2,360',
        ]);
        wp_send_json_success(['subject' => $result['subject'], 'body' => $result['body']]);
    }

    // TRACE: test_send() — Trigger: wp_ajax_test_send AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function test_send(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_admin_nonce' );
        Security::require_cap('manage_options');
        $key     = sanitize_text_field( Security::post('template_key') );
        $to_addr = sanitize_email( Security::post('email') ?: get_option('admin_email') );
        $result  = EmailTemplateModule::render($key, [
            'client_name'    => 'Test User',
            'order_id'       => 'TEST-001',
            'newspaper_name' => 'Sample Newspaper',
            'category'       => 'Test Category',
            'total_price'    => '₹1,180',
        ]);

        add_filter('wp_mail_content_type', fn() => 'text/html');
        $sent = wp_mail( $to_addr, '[TEST] ' . $result['subject'], $result['body'] );
        remove_filter('wp_mail_content_type', fn() => 'text/html');

        $sent ? wp_send_json_success(['message' => "Test email sent to {$to_addr}"])
              : wp_send_json_error(['message' => 'Failed to send. Check SMTP settings.']);
    }

    // TRACE: toggle() — Trigger: wp_ajax_toggle AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → updates DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function toggle(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('manage_options');
        $id      = (int) Security::post('id');
        $enabled = (int) Security::post('enabled');
        $db      = Database::instance();
        $db->update( $db->t('email_templates'), ['is_enabled' => $enabled], ['id' => $id] );
        wp_send_json_success(['message' => $enabled ? 'Template enabled' : 'Template disabled']);
    }

    // TRACE: reset_to_default() — Trigger: wp_ajax_reset_to_default AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function reset_to_default(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_cap('manage_options');
        // Re-run the seeder for this template
        \NAS\Database\SchemaV2::seed_email_templates_public();
        wp_send_json_success(['message' => 'Templates reset to defaults']);
    }
}
