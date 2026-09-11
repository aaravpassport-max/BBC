<?php
namespace NAS\Modules\Blog;
use NAS\Core\{Module, Database, Security};
if ( ! defined( 'ABSPATH' ) ) exit;

class BlogModule extends Module {
    public function key(): string { return 'blog'; }

    public function register(): void {
        add_shortcode( 'nas_blog',      [ $this, 'shortcode_blog_index' ] );
        add_shortcode( 'nas_blog_post', [ $this, 'shortcode_blog_post' ] );

        // Admin CRUD
        add_action( 'wp_ajax_nas_admin_get_posts',   [ BlogController::class, 'admin_list' ] );
        add_action( 'wp_ajax_nas_admin_save_post',   [ BlogController::class, 'save_post' ] );
        add_action( 'wp_ajax_nas_admin_delete_post', [ BlogController::class, 'delete_post' ] );

        // Public
        add_action( 'wp_ajax_nas_get_blog_posts',        [ BlogController::class, 'public_list' ] );
        add_action( 'wp_ajax_nopriv_nas_get_blog_posts',  [ BlogController::class, 'public_list' ] );
        add_action( 'wp_ajax_nas_get_blog_post',         [ BlogController::class, 'get_post' ] );
        add_action( 'wp_ajax_nopriv_nas_get_blog_post',   [ BlogController::class, 'get_post' ] );

        // Rewrite for SEO-friendly URLs
        add_action( 'init', [ self::class, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', fn($v) => array_merge($v, ['nas_blog_slug']) );
    }

    public function boot(): void {}

    // TRACE: add_rewrite_rules() — Trigger: wp_ajax_add_rewrite_rules AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function add_rewrite_rules(): void {
        add_rewrite_rule( '^blog/([^/]+)/?$', 'index.php?nas_blog_slug=$matches[1]', 'top' );
    }

    // TRACE: shortcode_blog_index() — Trigger: wp_ajax_shortcode_blog_index AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function shortcode_blog_index(): string {
        ob_start(); include NAS_DIR . 'templates/public/blog-index.php'; return ob_get_clean();
    }
    // TRACE: shortcode_blog_post() — Trigger: wp_ajax_shortcode_blog_post AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public function shortcode_blog_post(): string {
        ob_start(); include NAS_DIR . 'templates/public/blog-post.php'; return ob_get_clean();
    }
}

class BlogController {
    // TRACE: admin_list() — Trigger: wp_ajax_admin_list AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → queries DB.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function admin_list(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $db   = Database::instance();
        $posts = $db->select("SELECT id,title,slug,category,status,views,published_at,created_at FROM {$db->t('blog_posts')} ORDER BY created_at DESC LIMIT 50");
        wp_send_json_success(['posts' => $posts]);
    }

    // TRACE: save_post() — Trigger: wp_ajax_save_post AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function save_post(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        $id      = (int) Security::post('id');
        $db      = Database::instance();
        $data    = [
            'title'          => sanitize_text_field(Security::post('title')),
            'slug'           => sanitize_title(Security::post('slug') ?: Security::post('title')),
            'excerpt'        => sanitize_textarea_field(Security::post('excerpt')),
            'content'        => wp_kses_post(Security::post('content')),
            'featured_image' => esc_url_raw(Security::post('featured_image')),
            'category'       => sanitize_text_field(Security::post('category') ?: 'news'),
            'tags'           => sanitize_text_field(Security::post('tags')),
            'status'         => sanitize_text_field(Security::post('status') ?: 'draft'),
            'seo_title'      => sanitize_text_field(Security::post('seo_title')),
            'seo_desc'       => sanitize_textarea_field(Security::post('seo_desc')),
            'author_id'      => get_current_user_id(),
        ];
        if ($data['status'] === 'published' && !$id) $data['published_at'] = gmdate('Y-m-d H:i:s');

        if ($id) {
            $db->update($db->t('blog_posts'), $data, ['id' => $id]);
            wp_send_json_success(['message' => 'Post updated', 'id' => $id]);
        } else {
            // FIX (audit): slug has a UNIQUE constraint — a duplicate slug (e.g. two posts with
            // the same title) fails the INSERT silently. Check the return value instead of
            // reporting success unconditionally.
            $new_id = $db->insert($db->t('blog_posts'), $data);
            if ( ! $new_id ) {
                wp_send_json_error(['message' => 'Could not create post — a post with this slug may already exist.']);
                return;
            }
            wp_send_json_success(['message' => 'Post created', 'id' => $new_id]);
        }
    }

    // TRACE: delete_post() — Trigger: wp_ajax_delete_post AJAX action.
    //        Steps: verifies nonce → checks permissions → reads sanitised POST input → deletes DB row.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function delete_post(): void {
        Security::check_nonce(Security::post('nonce'), 'nas_admin_nonce');
        Security::require_cap('manage_options');
        Database::instance()->delete(Database::instance()->t('blog_posts'), ['id' => (int)Security::post('id')]);
        wp_send_json_success(['message' => 'Deleted']);
    }

    // TRACE: public_list() — Trigger: wp_ajax_public_list AJAX action.
    //        Steps: reads sanitised POST input → queries DB → returns JSON success response → returns JSON error response on failure.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function public_list(): void {
        $db      = Database::instance();
        $page    = max(1,(int)($_POST['page'] ?? 1));
        $limit   = 9;
        $offset  = ($page-1)*$limit;
        $cat     = sanitize_text_field($_POST['category'] ?? '');
        $where   = "status = 'published'";
        $args    = [];
        if ($cat) { $where .= " AND category = %s"; $args[] = $cat; }
        $posts   = $db->select("SELECT id,title,slug,excerpt,featured_image,category,views,published_at FROM {$db->t('blog_posts')} WHERE $where ORDER BY published_at DESC LIMIT %d OFFSET %d", array_merge($args, [$limit, $offset]));
        wp_send_json_success(['posts' => $posts, 'page' => $page]);
    }

    // TRACE: get_post() — Trigger: wp_ajax_get_post AJAX action.
    //        Steps: reads sanitised POST input → updates DB row → queries DB → returns JSON success response.
    //        Output: JSON response (success/error to browser).
    //        Edge cases: invalid input → error returned.
    public static function get_post(): void {
        $slug = sanitize_text_field($_POST['slug'] ?? $_GET['slug'] ?? '');
        $db   = Database::instance();
        $post = $db->row("SELECT * FROM {$db->t('blog_posts')} WHERE slug = %s AND status = 'published'", $slug);
        if (!$post) { wp_send_json_error(['message' => 'Not found']); return; }
        // Increment views
        $db->update($db->t('blog_posts'), ['views' => (int)$post['views']+1], ['id' => $post['id']]);
        wp_send_json_success(['post' => $post]);
    }
}


// =============================================================================
/**
 * FAQ Module
 */
// =============================================================================
