<?php
namespace NAS\Database;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schema — creates all custom NAS tables.
 * No wp_posts or wp_postmeta used for business data.
 * Proper indexing, relational structure, optimized queries.
 */
class Schema {

    // TRACE: create_tables() — Called internally or via AJAX action.
    //        Steps: creates new record.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function create_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix . 'nas_';

        // ── Clients ──────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}clients (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id   BIGINT UNSIGNED DEFAULT 0,
            uid          VARCHAR(20) NOT NULL DEFAULT '',
            name         VARCHAR(150) NOT NULL DEFAULT '',
            phone        VARCHAR(25) NOT NULL DEFAULT '',
            email        VARCHAR(150) DEFAULT '',
            company_name VARCHAR(200) DEFAULT '',
            gst_number   VARCHAR(20) DEFAULT '',
            address      TEXT,
            city         VARCHAR(100) DEFAULT '',
            state        VARCHAR(100) DEFAULT '',
            pincode      VARCHAR(10) DEFAULT '',
            total_orders INT DEFAULT 0,
            total_spent  DECIMAL(14,2) DEFAULT 0.00,
            status       ENUM('active','inactive','blocked') DEFAULT 'active',
            notes        TEXT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login   DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY uq_uid (uid),
            KEY idx_wp_user (wp_user_id),
            KEY idx_phone (phone),
            KEY idx_email (email)
        ) $c;");

        // ── Vendors ───────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}vendors (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id           BIGINT UNSIGNED DEFAULT 0,
            uid                  VARCHAR(20) NOT NULL DEFAULT '',
            name                 VARCHAR(150) NOT NULL DEFAULT '',
            phone                VARCHAR(25) NOT NULL DEFAULT '',
            email                VARCHAR(150) DEFAULT '',
            company_name         VARCHAR(200) DEFAULT '',
            whatsapp_number      VARCHAR(25) DEFAULT '',
            cities_supported     LONGTEXT,
            newspapers_supported LONGTEXT,
            categories_supported LONGTEXT,
            gst_number           VARCHAR(20) DEFAULT '',
            address              TEXT,
            rating               DECIMAL(3,1) DEFAULT 0.0,
            total_orders         INT DEFAULT 0,
            avg_response_hours   INT DEFAULT 24,
            escalation_hours     INT DEFAULT 24,
            escalation_days      INT DEFAULT 3,
            notes                TEXT,
            is_active            TINYINT(1) DEFAULT 1,
            created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_uid (uid),
            KEY idx_wp_user (wp_user_id),
            KEY idx_active (is_active)
        ) $c;");

        // ── Cities ────────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}cities (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(100) NOT NULL,
            slug        VARCHAR(120) NOT NULL DEFAULT '',
            state       VARCHAR(100) NOT NULL DEFAULT '',
            state_code  VARCHAR(5) DEFAULT '',
            tier        TINYINT DEFAULT 3,
            population  BIGINT DEFAULT 0,
            is_active   TINYINT(1) DEFAULT 1,
            seo_title   VARCHAR(250) DEFAULT '',
            seo_desc    TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_state (state),
            KEY idx_tier (tier),
            KEY idx_active (is_active)
        ) $c;");

        // ── Newspapers ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}newspapers (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name                 VARCHAR(200) NOT NULL,
            slug                 VARCHAR(220) DEFAULT '',
            language             VARCHAR(50) DEFAULT 'English',
            logo_url             VARCHAR(350) DEFAULT '',
            cities_supported     LONGTEXT,
            editions             LONGTEXT,
            categories_supported LONGTEXT,
            vendor_id            BIGINT UNSIGNED DEFAULT 0,
            base_rate_classified DECIMAL(10,2) DEFAULT 50.00,
            base_rate_display    DECIMAL(10,2) DEFAULT 400.00,
            base_rate_dc         DECIMAL(10,2) DEFAULT 200.00,
            min_charge           DECIMAL(10,2) DEFAULT 300.00,
            circulation          INT DEFAULT 0,
            description          TEXT,
            is_active            TINYINT(1) DEFAULT 1,
            sort_order           INT DEFAULT 0,
            created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (is_active),
            KEY idx_vendor (vendor_id)
        ) $c;");

        // ── Categories ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}categories (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(100) NOT NULL,
            slug         VARCHAR(120) DEFAULT '',
            description  TEXT,
            icon         VARCHAR(10) DEFAULT '',
            keywords     TEXT,
            sort_order   INT DEFAULT 0,
            is_active    TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $c;");

        // ── Rate Cards ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}rate_cards (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            newspaper_id INT UNSIGNED NOT NULL,
            category_id  INT UNSIGNED DEFAULT 0,
            city_id      INT UNSIGNED DEFAULT 0,
            ad_type      ENUM('classified','display','display_classified') NOT NULL DEFAULT 'classified',
            unit_type    ENUM('word','cm','package') DEFAULT 'word',
            base_rate    DECIMAL(10,2) DEFAULT 0.00,
            min_charge   DECIMAL(10,2) DEFAULT 0.00,
            markup_pct   DECIMAL(5,2) DEFAULT 20.00,
            notes        TEXT,
            is_active    TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_newspaper_type (newspaper_id, ad_type),
            KEY idx_category (category_id),
            KEY idx_city (city_id)
        ) $c;");

        // ── Bookings (Core Business Table) ────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}bookings (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid                 VARCHAR(25) NOT NULL UNIQUE,
            client_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_id         INT UNSIGNED DEFAULT 0,
            city_id             INT UNSIGNED DEFAULT 0,
            newspaper_id        INT UNSIGNED DEFAULT 0,
            edition             VARCHAR(120) DEFAULT '',
            ad_type             ENUM('classified','display','display_classified') DEFAULT 'classified',
            ad_size_width       DECIMAL(6,2) DEFAULT 0,
            ad_size_height      DECIMAL(6,2) DEFAULT 0,
            word_count          INT DEFAULT 0,
            ad_content          LONGTEXT,
            ad_preview_html     LONGTEXT,
            keywords_used       TEXT,
            publish_date        DATE,
            additional_dates    TEXT,
            status              VARCHAR(50) DEFAULT 'booking_received',
            workflow_history    LONGTEXT,
            assigned_vendor_id  BIGINT UNSIGNED DEFAULT 0,
  base_amount         DECIMAL(12,2) DEFAULT 0.00,
            client_price        DECIMAL(12,2) DEFAULT 0.00,
            vendor_cost         DECIMAL(12,2) DEFAULT 0.00,
            profit              DECIMAL(12,2) DEFAULT 0.00,
            gst_percentage      DECIMAL(5,2) DEFAULT 18.00,
            gst_amount          DECIMAL(12,2) DEFAULT 0.00,
            total_amount        DECIMAL(12,2) DEFAULT 0.00,
            payment_status      ENUM('pending','partial','paid','refunded') DEFAULT 'pending',
            payment_ref         VARCHAR(100) DEFAULT '',
            invoice_number      VARCHAR(30) DEFAULT '',
            multi_city          TINYINT(1) DEFAULT 0,
            multi_city_data     LONGTEXT,
            notes_admin         TEXT,
            notes_vendor        TEXT,
            notes_client        TEXT,
            rejection_reason    TEXT,
            source              VARCHAR(50) DEFAULT 'website',
            submitted_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_uid (uid),
            KEY idx_client (client_id),
            KEY idx_status (status),
            KEY idx_city (city_id),
            KEY idx_newspaper (newspaper_id),
            KEY idx_publish_date (publish_date),
            KEY idx_vendor (assigned_vendor_id),
            KEY idx_payment_status (payment_status)
        ) $c;");

        // ── Chat Messages ─────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}messages (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id   BIGINT UNSIGNED NOT NULL,
            sender_id    BIGINT UNSIGNED DEFAULT 0,
            sender_role  ENUM('admin','staff','client','system') DEFAULT 'system',
            message      LONGTEXT NOT NULL,
            is_read      TINYINT(1) DEFAULT 0,
            read_at      DATETIME,
            sent_via_email   TINYINT(1) DEFAULT 0,
            sent_via_whatsapp TINYINT(1) DEFAULT 0,
            attachment_url   VARCHAR(500) DEFAULT '',
            is_deleted   TINYINT(1) DEFAULT 0,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_sender (sender_id),
            KEY idx_created (created_at)
        ) $c;");

        // ── Quick Replies ─────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}quick_replies (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(200) NOT NULL,
            content    TEXT NOT NULL,
            category   VARCHAR(50) DEFAULT 'general',
            is_active  TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category)
        ) $c;");

        // ── Quotations ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}quotations (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id   BIGINT UNSIGNED NOT NULL,
            uid          VARCHAR(20) NOT NULL DEFAULT '',
            line_items   LONGTEXT,
            subtotal     DECIMAL(12,2) DEFAULT 0.00,
            discount     DECIMAL(12,2) DEFAULT 0.00,
            gst_amount   DECIMAL(12,2) DEFAULT 0.00,
            total        DECIMAL(12,2) DEFAULT 0.00,
            status       ENUM('draft','sent','accepted','rejected','expired') DEFAULT 'draft',
            valid_until  DATE,
            notes        TEXT,
            sent_at      DATETIME,
            accepted_at  DATETIME,
            created_by   BIGINT UNSIGNED DEFAULT 0,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id)
        ) $c;");

        // ── Payments ──────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}payments (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id   BIGINT UNSIGNED NOT NULL,
            amount       DECIMAL(12,2) DEFAULT 0.00,
            method       VARCHAR(50) DEFAULT 'online',
            reference    VARCHAR(150) DEFAULT '',
            status       ENUM('pending','captured','failed','refunded') DEFAULT 'pending',
            gateway      VARCHAR(50) DEFAULT '',
            gateway_data LONGTEXT,
            notes        TEXT,
            recorded_by  BIGINT UNSIGNED DEFAULT 0,
            paid_at      DATETIME,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_status (status)
        ) $c;");

        // ── Sample Ads ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}sample_ads (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id  INT UNSIGNED NOT NULL,
            newspaper_id INT UNSIGNED DEFAULT 0,
            title        VARCHAR(200) NOT NULL,
            content      LONGTEXT,
            format_type  ENUM('classified','display','display_classified') DEFAULT 'classified',
            word_limit   INT DEFAULT 60,
            tags         VARCHAR(300) DEFAULT '',
            is_active    TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_category (category_id),
            KEY idx_newspaper (newspaper_id)
        ) $c;");

        // ── Ad Templates ──────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}templates (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id      INT UNSIGNED NOT NULL,
            name             VARCHAR(200) NOT NULL,
            content          LONGTEXT,
            tone             VARCHAR(30) DEFAULT 'formal',
            word_limit       INT DEFAULT 100,
            ad_type          VARCHAR(30) DEFAULT 'classified',
            is_active        TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_category (category_id)
        ) $c;");

        // ── Combo Offers ──────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}combo_offers (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name           VARCHAR(200) NOT NULL,
            cities         LONGTEXT,
            newspapers     LONGTEXT,
            categories     LONGTEXT,
            discount_type  ENUM('percentage','fixed') DEFAULT 'percentage',
            discount_value DECIMAL(8,2) DEFAULT 0.00,
            conditions     TEXT,
            is_active      TINYINT(1) DEFAULT 1,
            valid_from     DATE,
            valid_to       DATE,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $c;");

        // ── Follow-ups ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}followups (
            id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id         BIGINT UNSIGNED NOT NULL,
            type               ENUM('client','vendor','internal') DEFAULT 'client',
            last_contact_date  DATETIME,
            next_followup_date DATETIME,
            status             ENUM('pending','done','skipped') DEFAULT 'pending',
            assigned_to        BIGINT UNSIGNED DEFAULT 0,
            notes              TEXT,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_next_date (next_followup_date),
            KEY idx_status (status)
        ) $c;");

        // ── Analytics ─────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}analytics (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type    VARCHAR(50) NOT NULL,
            entity_type   VARCHAR(50) DEFAULT '',
            entity_id     BIGINT UNSIGNED DEFAULT 0,
            value         DECIMAL(14,2) DEFAULT 0.00,
            meta          LONGTEXT,
            recorded_date DATE,
            recorded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event (event_type),
            KEY idx_date (recorded_date),
            KEY idx_entity (entity_type, entity_id)
        ) $c;");

        // ── AI Logs ───────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}ai_logs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id  BIGINT UNSIGNED DEFAULT 0,
            session_id  VARCHAR(100) DEFAULT '',
            type        VARCHAR(30) DEFAULT 'generate',
            input_text  TEXT,
            output_text LONGTEXT,
            model_used  VARCHAR(100) DEFAULT '',
            tokens_used INT DEFAULT 0,
            cost_usd    DECIMAL(10,6) DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_type (type)
        ) $c;");

        // ── Price History (self-learning) ──────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}price_history (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            newspaper_id INT UNSIGNED NOT NULL,
            category_id  INT UNSIGNED NOT NULL,
            city_id      INT UNSIGNED DEFAULT 0,
            ad_type      VARCHAR(30) DEFAULT '',
            client_price DECIMAL(12,2) DEFAULT 0.00,
            vendor_cost  DECIMAL(12,2) DEFAULT 0.00,
            profit       DECIMAL(12,2) DEFAULT 0.00,
            margin_pct   DECIMAL(5,2) DEFAULT 0.00,
            recorded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_np_cat (newspaper_id, category_id)
        ) $c;");

        // ── Notifications ────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}notifications (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(50) DEFAULT 'info',
            title       VARCHAR(200) NOT NULL DEFAULT '',
            message     TEXT,
            booking_id  BIGINT UNSIGNED DEFAULT 0,
            is_read     TINYINT(1) DEFAULT 0,
            read_at     DATETIME,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_read (is_read),
            KEY idx_booking (booking_id)
        ) $c;");

        // ── Queue ────────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}queue (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            handler     VARCHAR(200) NOT NULL,
            payload     LONGTEXT,
            priority    ENUM('high','normal','low') DEFAULT 'normal',
            status      ENUM('pending','processing','done','failed') DEFAULT 'pending',
            attempts    TINYINT DEFAULT 0,
            error       TEXT,
            run_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at  DATETIME,
            finished_at DATETIME,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_run (status, run_at),
            KEY idx_priority (priority)
        ) $c;");

        // ── Settings ─────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}settings (
            id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
            brand_name         VARCHAR(200) DEFAULT 'NewspaperAds Pro',
            logo_url           VARCHAR(350) DEFAULT '',
            email_sender_name  VARCHAR(150) DEFAULT 'NewspaperAds Pro',
            email_sender_addr  VARCHAR(150) DEFAULT '',
            whatsapp_number    VARCHAR(25) DEFAULT '',
            callmebot_api_key  TEXT,
            callmebot_api_url  VARCHAR(300) DEFAULT 'https://api.callmebot.com/whatsapp.php',
            wa_staff_numbers   TEXT,
            wa_enabled         TINYINT(1) DEFAULT 1,
            email_enabled      TINYINT(1) DEFAULT 1,
            gst_percentage     DECIMAL(5,2) DEFAULT 18.00,
            currency           VARCHAR(5) DEFAULT 'INR',
            currency_symbol    VARCHAR(5) DEFAULT '₹',
            footer_text        TEXT,
            invoice_prefix     VARCHAR(10) DEFAULT 'INV',
            invoice_counter    INT DEFAULT 1000,
            cutoff_days        INT DEFAULT 2,
            ai_provider        VARCHAR(30) DEFAULT 'anthropic',
            ai_model           VARCHAR(100) DEFAULT 'claude-opus-4-6',
            ai_api_key         TEXT,
            smtp_host          VARCHAR(200) DEFAULT '',
            smtp_port          INT DEFAULT 587,
            smtp_user          VARCHAR(200) DEFAULT '',
            smtp_pass          TEXT,
            smtp_encryption    VARCHAR(10) DEFAULT 'tls',
            PRIMARY KEY (id)
        ) $c;");

        // ── Feature Flags ─────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}feature_flags (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            feature_key VARCHAR(100) NOT NULL,
            is_enabled  TINYINT(1) DEFAULT 1,
            description TEXT,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key (feature_key)
        ) $c;");

        // ── City Landing Pages meta ───────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}city_page_meta (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            city_id      INT UNSIGNED NOT NULL,
            seo_title    VARCHAR(250) DEFAULT '',
            seo_desc     TEXT,
            og_image     VARCHAR(350) DEFAULT '',
            content_body LONGTEXT,
            schema_data  LONGTEXT,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_city (city_id)
        ) $c;");

        // Insert defaults
        global $wpdb;
        if ( ! $wpdb->get_var("SELECT id FROM {$p}settings LIMIT 1") ) {
            $wpdb->insert("{$p}settings", [
                'brand_name'       => get_bloginfo('name') ?: 'NewspaperAds Pro',
                'email_sender_name'=> get_bloginfo('name') ?: 'NewspaperAds Pro',
                'gst_percentage'   => 18.00,
                'currency'         => 'INR',
                'currency_symbol'  => '₹',
                'invoice_prefix'   => 'INV',
                'invoice_counter'  => 1000,
                'cutoff_days'      => 2,
            ]);
        }

        // Default feature flags
        $flags = ['booking','client','vendor','pricing','chat','notifications','analytics','ai','city_pages'];
        foreach ( $flags as $flag ) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}feature_flags (feature_key, is_enabled) VALUES (%s, 1)", $flag));
        }
    }
}
// This file is extended by SchemaV2
