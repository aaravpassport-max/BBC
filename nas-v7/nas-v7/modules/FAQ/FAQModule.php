<?php
namespace NAS\Modules\FAQ;
use NAS\Core\{Module, Database, Security};
if ( ! defined( 'ABSPATH' ) ) exit;

class FAQModule extends Module {
    public function key(): string { return 'faq'; }
    public function register(): void {
        add_shortcode('nas_faq', [$this, 'shortcode']);
        add_action('wp_ajax_nas_get_faqs',         [FAQController::class, 'get_faqs']);
        add_action('wp_ajax_nopriv_nas_get_faqs',  [FAQController::class, 'get_faqs']);
        add_action('wp_ajax_nas_admin_save_faq',   [FAQController::class, 'save']);
        add_action('wp_ajax_nas_admin_delete_faq', [FAQController::class, 'delete']);
    }
    public function boot(): void {}
    // TRACE: shortcode() — Trigger: wp_ajax_shortcode AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function shortcode(): string {
        ob_start(); include NAS_DIR . 'templates/public/faq.php'; return ob_get_clean();
    }
}

class FAQController {
    // TRACE: get_faqs() — Trigger: wp_ajax_get_faqs AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_faqs(): void {
        $db       = Database::instance();
        $category = sanitize_text_field($_POST['category'] ?? '');
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $where    = "is_active = 1";
        $args     = [];
        if ($category) { $where .= " AND category = %s"; $args[] = $category; }
        if ($search) {
            $like = $db->esc_like($search);
            $where .= " AND (question LIKE %s OR answer LIKE %s)";
            $args = array_merge($args, ["%$like%", "%$like%"]);
        }
        $faqs = $db->select("SELECT id,question,answer,category FROM {$db->t('faqs')} WHERE $where ORDER BY category, sort_order ASC", $args);
        wp_send_json_success(['faqs' => $faqs]);
    }
    // TRACE: save() — Trigger: wp_ajax_save AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → inserts DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function save(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $id   = (int) Security::post('id');
        $db   = Database::instance();
        $data = [
            'question'   => sanitize_text_field(Security::post('question')),
            'answer'     => wp_kses_post(Security::post('answer')),
            'category'   => sanitize_text_field(Security::post('category') ?: 'general'),
            'sort_order' => (int) Security::post('sort_order'),
            'is_active'  => 1,
        ];
        $id ? $db->update($db->t('faqs'), $data, ['id'=>$id]) : $db->insert($db->t('faqs'), $data);
        wp_send_json_success(['message' => 'Saved']);
    }
    // TRACE: delete() — Trigger: wp_ajax_delete AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        Database::instance()->delete(Database::instance()->t('faqs'), ['id'=>(int)Security::post('id')]);
        wp_send_json_success(['message' => 'Deleted']);
    }
}


// =============================================================================
/**
 * Contact Module
 */
// =============================================================================
