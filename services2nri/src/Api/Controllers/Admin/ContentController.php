<?php
namespace S2NRI\Api\Controllers\Admin;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\Controllers\BaseController;
use S2NRI\Api\Request;
use S2NRI\Api\Response;

/**
 * ContentController — FAQs, Testimonials, Blog Posts, Pricing Plans, Contact Form.
 * TRACE: All public GET endpoints are unauthenticated.
 *        All admin POST/PUT/DELETE require requireManager().
 *        All tables prefixed with $wpdb->prefix . 's2nri_'.
 */
class ContentController extends BaseController {

    private static bool $tables_ensured = false;

    public function __construct( ?array $user = null ) {
        parent::__construct( $user );
        if ( ! self::$tables_ensured ) {
                self::$tables_ensured = true;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 's2nri_' . $name;
    }

    private function ensure_tables(): void {
        global $wpdb;
        $c = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->table('faqs')} (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question    TEXT         NOT NULL,
            answer      TEXT         NOT NULL,
            category    VARCHAR(100) NOT NULL DEFAULT 'General',
            sort_order  SMALLINT     NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $c;" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->table('testimonials')} (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(120) NOT NULL,
            location    VARCHAR(100) NOT NULL DEFAULT '',
            rating      TINYINT      NOT NULL DEFAULT 5,
            text        TEXT         NOT NULL,
            image_url   VARCHAR(500) NOT NULL DEFAULT '',
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order  SMALLINT     NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $c;" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->table('blog_posts')} (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title        VARCHAR(300) NOT NULL,
            slug         VARCHAR(300) NOT NULL UNIQUE,
            excerpt      TEXT,
            content      LONGTEXT,
            category     VARCHAR(100) NOT NULL DEFAULT '',
            image_url    VARCHAR(500) NOT NULL DEFAULT '',
            is_published TINYINT(1)   NOT NULL DEFAULT 0,
            created_by   BIGINT UNSIGNED,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $c;" );

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->table('pricing_plans')} (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            subtitle    VARCHAR(200) NOT NULL DEFAULT '',
            price       VARCHAR(100) NOT NULL,
            price_note  VARCHAR(200) NOT NULL DEFAULT '',
            color       VARCHAR(20)  NOT NULL DEFAULT '#4A6FA5',
            popular     TINYINT(1)   NOT NULL DEFAULT 0,
            features    JSON,
            sort_order  SMALLINT     NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $c;" );
    }

    // ── PUBLIC: FAQs ─────────────────────────────────────────────────────────

    // TRACE: GET /api/v1/faqs → returns active FAQs ordered by sort_order.
    //        Postconditions: array of {id, question, answer, category}.
    public function listFaqs( Request $req ): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, question, answer, category, sort_order
             FROM {$this->table('faqs')}
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );
        Response::json( [ 'faqs' => $rows ?: [] ] );
    }

    // TRACE: GET /api/v1/testimonials → returns active testimonials for homepage.
    //        No auth required — public endpoint.
    public function listTestimonials( Request $req ): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, location, rating, text, image_url
             FROM {$this->table('testimonials')}
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC
             LIMIT 10",
            ARRAY_A
        );
        foreach ( $rows as &$r ) { $r['rating'] = (int) $r['rating']; }
        Response::json( [ 'testimonials' => $rows ?: [] ] );
    }

    // ── PUBLIC: Pricing Plans ────────────────────────────────────────────────

    // TRACE: GET /api/v1/pricing-plans → returns active plans ordered by sort_order.
    public function listPricingPlans( Request $req ): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, subtitle, price, price_note, color, popular, features, sort_order
             FROM {$this->table('pricing_plans')}
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );
        foreach ( $rows as &$row ) {
            $row['popular']  = (bool) $row['popular'];
            $row['features'] = json_decode( $row['features'] ?: '[]', true );
        }
        Response::json( [ 'plans' => $rows ?: [] ] );
    }

    // ── PUBLIC: Blog ─────────────────────────────────────────────────────────

    // TRACE: GET /api/v1/blog → paginated published posts.
    public function listPosts( Request $req ): void {
        global $wpdb;
        $per  = min( 20, max( 1, (int) $req->query( 'per_page', 9 ) ) );
        $page = max( 1, (int) $req->query( 'page', 1 ) );
        $off  = ( $page - 1 ) * $per;
        $cat  = sanitize_text_field( $req->query( 'category', '' ) );
        $where = "WHERE is_published = 1";
        if ( $cat ) $where .= $wpdb->prepare( " AND category = %s", $cat );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table('blog_posts')} {$where}" );
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, title, slug, excerpt, category, image_url, created_at
                FROM {$this->table('blog_posts')} {$where}
                ORDER BY created_at DESC LIMIT %d OFFSET %d", $per, $off ),
            ARRAY_A
        );
        Response::json( compact( 'rows', 'total', 'page', 'per' ) + [ 'posts' => $rows ?: [] ] );
    }

    // TRACE: GET /api/v1/blog/{slug} → single published post by slug.
    public function getPost( Request $req ): void {
        global $wpdb;
        $slug = sanitize_key( $req->param( 'slug' ) );
        $row  = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table('blog_posts')} WHERE slug = %s AND is_published = 1 LIMIT 1", $slug ),
            ARRAY_A
        );
        if ( ! $row ) { Response::json( [ 'error' => 'Post not found.' ], 404 ); return; }
        Response::json( [ 'post' => $row ] );
    }

    // ── PUBLIC: Contact Form ──────────────────────────────────────────────────

    // TRACE: POST /api/v1/contact → saves message, emails admin.
    //        Rate limited by IP (10 per hour).
    public function contactForm( Request $req ): void {
        global $wpdb;
        $name    = sanitize_text_field( $req->input('name', '' ) );
        $email   = sanitize_email( $req->input('email', '' ) );
        $phone   = sanitize_text_field( $req->input('phone', '' ) );
        $subject = sanitize_text_field( $req->input('subject', '' ) );
        $message = sanitize_textarea_field( $req->input('message', '' ) );

        if ( ! $name || ! $email || ! $message ) {
            Response::json( [ 'error' => 'Name, email and message are required.' ], 422 ); return;
        }
        if ( ! is_email( $email ) ) {
            Response::json( [ 'error' => 'Invalid email address.' ], 422 ); return;
        }

        // FIXED: previously this only called wp_mail() with no database
        // record at all, and never checked its return value — a mail
        // server hiccup meant total, silent, unrecoverable loss of the
        // customer's message. Now saves to a real table FIRST, so even a
        // failed email leaves a recoverable record, then attempts the
        // email as a best-effort notification with its result recorded.
        $msg_insert = $wpdb->insert( $wpdb->prefix . 's2nri_contact_messages', [
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'subject'    => $subject,
            'message'    => $message,
            'created_at' => current_time( 'mysql' ),
        ] );
        if ( $msg_insert === false ) {
            error_log( '[S2NRI] Contact form message insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to send your message. Please try again or contact us on WhatsApp.' ], 500 ); return;
        }
        $msg_id = $wpdb->insert_id;

        // Email admin
        $admin_email = get_option( 'admin_email' );
        $settings    = \S2NRI\Models\Setting::getPublic();
        $to          = $settings['platform_email'] ?? $admin_email;
        $body        = "New contact form submission:\n\n"
                     . "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\n"
                     . "Subject: {$subject}\n\nMessage:\n{$message}";
        $sent = wp_mail( $to, "Contact Form: {$subject}", $body );
        if ( ! $sent ) {
            error_log( '[S2NRI] Contact form email send failed for message ' . $msg_id . ' (message itself is safely saved, only the email notification failed).' );
        }
        if ( $wpdb->update( $wpdb->prefix . 's2nri_contact_messages', [ 'email_sent' => $sent ? 1 : 0 ], [ 'id' => $msg_id ] ) === false ) {
            error_log( '[S2NRI] Failed to set email_sent flag for contact message ' . $msg_id . ': ' . $wpdb->last_error );
            // Non-fatal — the message itself is already safely saved and checked above.
        }

        $this->logAudit( null, 'contact_form', $email, $name );
        Response::json( [ 'success' => true, 'message' => 'Your message has been sent. We will get back to you within 24 hours.' ] );
    }

    // ── ADMIN: FAQs ──────────────────────────────────────────────────────────

    public function adminFaqs( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table('faqs')} ORDER BY sort_order ASC, id ASC", ARRAY_A
        );
        Response::json( [ 'faqs' => $rows ?: [] ] );
    }

    public function createFaq( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $q = sanitize_textarea_field( $req->input('question', '' ) );
        $a = sanitize_textarea_field( $req->input('answer', '' ) );
        if ( ! $q || ! $a ) { Response::json( [ 'error' => 'Question and answer required.' ], 422 ); return; }
        $faq_insert = $wpdb->insert( $this->table( 'faqs' ), [
            'question'   => $q,
            'answer'     => $a,
            'category'   => sanitize_text_field( $req->input('category', 'General' ) ),
            'sort_order' => (int) $req->input('sort_order', 0 ),
            'is_active'  => 1,
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase.
        if ( $faq_insert === false ) {
            error_log( '[S2NRI] FAQ insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save FAQ.' ], 500 ); return;
        }
        $this->logAudit( null, 'faq_create', (string) $wpdb->insert_id, $q );
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    public function updateFaq( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $this->table( 'faqs' ), [
            'question'   => sanitize_textarea_field( $req->input('question', '' ) ),
            'answer'     => sanitize_textarea_field( $req->input('answer', '' ) ),
            'category'   => sanitize_text_field( $req->input('category', 'General' ) ),
            'sort_order' => (int) $req->input('sort_order', 0 ),
            'is_active'  => (int) $req->input('is_active', 1 ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update FAQ.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function deleteFaq( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( $wpdb->delete( $this->table( 'faqs' ), [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete FAQ.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    // ── ADMIN: Testimonials ──────────────────────────────────────────────────

    public function adminTestimonials( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table('testimonials')} ORDER BY sort_order ASC, id ASC", ARRAY_A
        );
        foreach ( $rows as &$r ) { $r['is_active'] = (bool) $r['is_active']; }
        Response::json( [ 'testimonials' => $rows ?: [] ] );
    }

    public function createTestimonial( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $name = sanitize_text_field( $req->input('name', '' ) );
        $text = sanitize_textarea_field( $req->input('text', '' ) );
        if ( ! $name || ! $text ) { Response::json( [ 'error' => 'Name and review text required.' ], 422 ); return; }
        $t_insert = $wpdb->insert( $this->table( 'testimonials' ), [
            'name'       => $name,
            'location'   => sanitize_text_field( $req->input('location', '' ) ),
            'rating'     => min( 5, max( 1, (int) $req->input('rating', 5 ) ) ),
            'text'       => $text,
            'image_url'  => esc_url_raw( $req->input('image_url', '' ) ),
            'is_active'  => $req->input('is_active', true ) ? 1 : 0,
            'sort_order' => (int) $req->input('sort_order', 0 ),
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase.
        if ( $t_insert === false ) {
            error_log( '[S2NRI] Testimonial insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save testimonial.' ], 500 ); return;
        }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    public function updateTestimonial( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $this->table( 'testimonials' ), [
            'name'       => sanitize_text_field( $req->input('name', '' ) ),
            'location'   => sanitize_text_field( $req->input('location', '' ) ),
            'rating'     => min( 5, max( 1, (int) $req->input('rating', 5 ) ) ),
            'text'       => sanitize_textarea_field( $req->input('text', '' ) ),
            'image_url'  => esc_url_raw( $req->input('image_url', '' ) ),
            'is_active'  => $req->input('is_active', true ) ? 1 : 0,
            'sort_order' => (int) $req->input('sort_order', 0 ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update testimonial.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function deleteTestimonial( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        if ( $wpdb->delete( $this->table( 'testimonials' ), [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete testimonial.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    // ── ADMIN: Blog Posts ────────────────────────────────────────────────────

    public function adminPosts( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $rows = $wpdb->get_results(
            "SELECT id, title, slug, excerpt, category, image_url, is_published, created_at
             FROM {$this->table('blog_posts')} ORDER BY created_at DESC", ARRAY_A
        );
        foreach ( $rows as &$r ) { $r['is_published'] = (bool) $r['is_published']; }
        Response::json( [ 'posts' => $rows ?: [] ] );
    }

    public function createPost( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $title = sanitize_text_field( $req->input('title', '' ) );
        if ( ! $title ) { Response::json( [ 'error' => 'Title required.' ], 422 ); return; }
        $slug = sanitize_title( $req->input('slug', '' ) ?: $title );
        // Ensure unique slug
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table('blog_posts')} WHERE slug = %s", $slug ) );
        if ( $existing ) $slug = $slug . '-' . time();
        $bp_insert = $wpdb->insert( $this->table( 'blog_posts' ), [
            'title'        => $title,
            'slug'         => $slug,
            'excerpt'      => sanitize_textarea_field( $req->input('excerpt', '' ) ),
            'content'      => wp_kses_post( $req->input('content', '' ) ),
            'category'     => sanitize_text_field( $req->input('category', '' ) ),
            'image_url'    => esc_url_raw( $req->input('image_url', '' ) ),
            'is_published' => $req->input('is_published', false ) ? 1 : 0,
            'created_by'   => get_current_user_id() ?: null,
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase — insert_id does not
        // reset to 0 on failure.
        if ( $bp_insert === false ) {
            error_log( '[S2NRI] Blog post insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to create post.' ], 500 ); return;
        }
        $this->logAudit( null, 'blog_create', (string) $wpdb->insert_id, $title );
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id, 'slug' => $slug ] );
    }

    public function updatePost( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $id = (int) $req->param( 'id' );
        if ( $wpdb->update( $this->table( 'blog_posts' ), [
            'title'        => sanitize_text_field( $req->input('title', '' ) ),
            'slug'         => sanitize_title( $req->input('slug', '' ) ),
            'excerpt'      => sanitize_textarea_field( $req->input('excerpt', '' ) ),
            'content'      => wp_kses_post( $req->input('content', '' ) ),
            'category'     => sanitize_text_field( $req->input('category', '' ) ),
            'image_url'    => esc_url_raw( $req->input('image_url', '' ) ),
            'is_published' => $req->input('is_published', false ) ? 1 : 0,
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update post.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function deletePost( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        if ( $wpdb->delete( $this->table( 'blog_posts' ), [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete post.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    // ── ADMIN: Pricing Plans ─────────────────────────────────────────────────

    public function adminPricingPlans( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table('pricing_plans')} ORDER BY sort_order ASC, id ASC", ARRAY_A
        );
        foreach ( $rows as &$r ) {
            $r['popular']  = (bool) $r['popular'];
            $r['is_active'] = (bool) $r['is_active'];
            $r['features'] = json_decode( $r['features'] ?: '[]', true );
        }
        Response::json( [ 'plans' => $rows ?: [] ] );
    }

    public function createPlan( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $name = sanitize_text_field( $req->input('name', '' ) );
        if ( ! $name ) { Response::json( [ 'error' => 'Plan name required.' ], 422 ); return; }
        $features = $req->input('features', [] );
        if ( is_string( $features ) ) $features = array_filter( array_map( 'trim', explode( "\n", $features ) ) );
        $plan_insert = $wpdb->insert( $this->table( 'pricing_plans' ), [
            'name'       => $name,
            'subtitle'   => sanitize_text_field( $req->input('subtitle', '' ) ),
            'price'      => sanitize_text_field( $req->input('price', '' ) ),
            'price_note' => sanitize_text_field( $req->input('price_note', '' ) ),
            'color'      => sanitize_hex_color( $req->input('color', '#4A6FA5' ) ) ?: '#4A6FA5',
            'popular'    => $req->input('popular', false ) ? 1 : 0,
            'features'   => wp_json_encode( array_values( $features ) ),
            'sort_order' => (int) $req->input('sort_order', 0 ),
            'is_active'  => 1,
        ] );
        // CHECKED (was previously unchecked): same insert_id staleness
        // pattern fixed throughout this codebase - completing the sweep
        // across all 5 previously-pending admin screens.
        if ( $plan_insert === false ) {
            error_log( '[S2NRI] Pricing plan insert failed: ' . $wpdb->last_error );
            Response::json( [ 'error' => 'Failed to save pricing plan.' ], 500 ); return;
        }
        Response::json( [ 'success' => true, 'id' => $wpdb->insert_id ] );
    }

    public function updatePlan( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        $id       = (int) $req->param( 'id' );
        $features = $req->input('features', [] );
        if ( is_string( $features ) ) $features = array_filter( array_map( 'trim', explode( "\n", $features ) ) );
        if ( $wpdb->update( $this->table( 'pricing_plans' ), [
            'name'       => sanitize_text_field( $req->input('name', '' ) ),
            'subtitle'   => sanitize_text_field( $req->input('subtitle', '' ) ),
            'price'      => sanitize_text_field( $req->input('price', '' ) ),
            'price_note' => sanitize_text_field( $req->input('price_note', '' ) ),
            'color'      => sanitize_hex_color( $req->input('color', '#4A6FA5' ) ) ?: '#4A6FA5',
            'popular'    => $req->input('popular', false ) ? 1 : 0,
            'features'   => wp_json_encode( array_values( (array) $features ) ),
            'sort_order' => (int) $req->input('sort_order', 0 ),
            'is_active'  => (int) $req->input('is_active', 1 ),
        ], [ 'id' => $id ] ) === false ) {
            Response::json( [ 'error' => 'Failed to update pricing plan.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }

    public function deletePlan( Request $req ): void {
        global $wpdb;
        $this->requireManager();
        if ( $wpdb->delete( $this->table( 'pricing_plans' ), [ 'id' => (int) $req->param( 'id' ) ] ) === false ) {
            Response::json( [ 'error' => 'Failed to delete pricing plan.' ], 500 ); return;
        }
        Response::json( [ 'success' => true ] );
    }
}
