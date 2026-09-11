<?php
namespace NAS\Database;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SchemaV2 — Adds new tables for v2.1 features.
 * Called during activation alongside Schema::create_tables()
 */
class SchemaV2 {

    // TRACE: create_tables() — Called internally or via AJAX action.
    //        Steps: creates new record.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function create_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix . 'nas_';

        // ── Payments ──────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}payments (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id      BIGINT UNSIGNED NOT NULL,
            gateway         ENUM('razorpay','payu','stripe','manual','bank_transfer') DEFAULT 'razorpay',
            gateway_order_id   VARCHAR(100) DEFAULT '',
            gateway_payment_id VARCHAR(100) DEFAULT '',
            gateway_signature  VARCHAR(200) DEFAULT '',
            amount          DECIMAL(12,2) DEFAULT 0.00,
            currency        VARCHAR(5) DEFAULT 'INR',
            status          ENUM('created','captured','failed','refunded','partial_refund') DEFAULT 'created',
            refund_amount   DECIMAL(12,2) DEFAULT 0.00,
            refund_id       VARCHAR(100) DEFAULT '',
            notes           TEXT,
            raw_response    LONGTEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_gateway_order (gateway_order_id),
            KEY idx_status (status)
        ) $c;");

        // ── Invoices ──────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}invoices (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number  VARCHAR(30) NOT NULL UNIQUE,
            booking_id      BIGINT UNSIGNED NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            subtotal        DECIMAL(12,2) DEFAULT 0.00,
            gst_amount      DECIMAL(12,2) DEFAULT 0.00,
            gst_rate        DECIMAL(5,2) DEFAULT 18.00,
            total           DECIMAL(12,2) DEFAULT 0.00,
            paid_amount     DECIMAL(12,2) DEFAULT 0.00,
            status          ENUM('draft','sent','paid','cancelled') DEFAULT 'draft',
            line_items      LONGTEXT,
            due_date        DATE,
            paid_at         DATETIME,
            pdf_path        VARCHAR(350) DEFAULT '',
            notes           TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_client (client_id),
            KEY idx_number (invoice_number)
        ) $c;");

        // ── Ad Materials ──────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}ad_materials (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id      BIGINT UNSIGNED NOT NULL,
            uploader_id     BIGINT UNSIGNED DEFAULT 0,
            file_url        VARCHAR(500) DEFAULT '',
            file_name       VARCHAR(250) DEFAULT '',
            file_type       VARCHAR(50) DEFAULT '',
            file_size       INT DEFAULT 0,
            ad_text         LONGTEXT,
            material_type   ENUM('file','text','both') DEFAULT 'file',
            status          ENUM('pending','under_review','approved','rejected') DEFAULT 'pending',
            rejection_reason TEXT,
            reviewed_by     BIGINT UNSIGNED DEFAULT 0,
            reviewed_at     DATETIME,
            version         TINYINT DEFAULT 1,
            notes           TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_status (status)
        ) $c;");

        // ── Support Tickets ───────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}support_tickets (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_uid      VARCHAR(20) NOT NULL DEFAULT '',
            booking_id      BIGINT UNSIGNED DEFAULT 0,
            client_id       BIGINT UNSIGNED NOT NULL,
            category        ENUM('publication_query','material_issue','billing','cancellation','general') DEFAULT 'general',
            subject         VARCHAR(250) NOT NULL DEFAULT '',
            description     TEXT,
            status          ENUM('open','in_progress','waiting_client','resolved','closed') DEFAULT 'open',
            priority        ENUM('low','normal','high','urgent') DEFAULT 'normal',
            assigned_to     BIGINT UNSIGNED DEFAULT 0,
            resolved_at     DATETIME,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_uid (ticket_uid),
            KEY idx_client (client_id),
            KEY idx_booking (booking_id),
            KEY idx_status (status)
        ) $c;");

        // ── Ticket Replies ────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}ticket_replies (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id   BIGINT UNSIGNED NOT NULL,
            sender_id   BIGINT UNSIGNED NOT NULL,
            sender_type ENUM('client','staff','admin','system') DEFAULT 'client',
            message     TEXT NOT NULL,
            attachments LONGTEXT,
            is_internal TINYINT(1) DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id)
        ) $c;");

        // ── Email Templates ───────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}email_templates (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(80) NOT NULL UNIQUE,
            name        VARCHAR(150) NOT NULL DEFAULT '',
            subject     VARCHAR(250) NOT NULL DEFAULT '',
            html_body   LONGTEXT,
            plain_text  TEXT,
            placeholders TEXT,
            is_enabled  TINYINT(1) DEFAULT 1,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key (template_key)
        ) $c;");

        // ── FAQ ───────────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}faqs (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            question    TEXT NOT NULL,
            answer      LONGTEXT NOT NULL,
            category    VARCHAR(80) DEFAULT 'general',
            sort_order  INT DEFAULT 0,
            is_active   TINYINT(1) DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_active (is_active)
        ) $c;");

        // ── Blog Posts ────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}blog_posts (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(300) NOT NULL,
            slug        VARCHAR(320) NOT NULL UNIQUE,
            excerpt     TEXT,
            content     LONGTEXT,
            featured_image VARCHAR(500) DEFAULT '',
            category    VARCHAR(80) DEFAULT 'news',
            tags        VARCHAR(500) DEFAULT '',
            author_id   BIGINT UNSIGNED DEFAULT 0,
            status      ENUM('draft','published','archived') DEFAULT 'draft',
            seo_title   VARCHAR(250) DEFAULT '',
            seo_desc    TEXT,
            views       INT DEFAULT 0,
            published_at DATETIME,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_status (status),
            KEY idx_category (category)
        ) $c;");

        // ── Wallet ────────────────────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}wallet (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   BIGINT UNSIGNED NOT NULL,
            balance     DECIMAL(12,2) DEFAULT 0.00,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_client (client_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$p}wallet_transactions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id   BIGINT UNSIGNED NOT NULL,
            type        ENUM('credit','debit','refund') DEFAULT 'credit',
            amount      DECIMAL(12,2) DEFAULT 0.00,
            balance_after DECIMAL(12,2) DEFAULT 0.00,
            description VARCHAR(300) DEFAULT '',
            booking_id  BIGINT UNSIGNED DEFAULT 0,
            reference   VARCHAR(100) DEFAULT '',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_booking (booking_id)
        ) $c;");

        // ── Contact Submissions ───────────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}contact_submissions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(150) NOT NULL,
            email       VARCHAR(150) NOT NULL,
            phone       VARCHAR(25) DEFAULT '',
            city        VARCHAR(100) DEFAULT '',
            subject     VARCHAR(200) DEFAULT '',
            message     TEXT NOT NULL,
            status      ENUM('new','read','replied','spam') DEFAULT 'new',
            ip_address  VARCHAR(45) DEFAULT '',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) $c;");

        // ── Careers / Job Applications ────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}job_postings (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(200) NOT NULL,
            department  VARCHAR(100) DEFAULT '',
            location    VARCHAR(150) DEFAULT 'Remote',
            type        ENUM('full_time','part_time','contract','internship') DEFAULT 'full_time',
            description LONGTEXT,
            requirements TEXT,
            salary_range VARCHAR(100) DEFAULT '',
            is_active   TINYINT(1) DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$p}job_applications (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id      INT UNSIGNED NOT NULL,
            name        VARCHAR(150) NOT NULL,
            email       VARCHAR(150) NOT NULL,
            phone       VARCHAR(25) DEFAULT '',
            cover_letter TEXT,
            resume_url  VARCHAR(500) DEFAULT '',
            status      ENUM('new','shortlisted','rejected','hired') DEFAULT 'new',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_job (job_id),
            KEY idx_status (status)
        ) $c;");

        // ── Newspaper Availability Calendar ───────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}newspaper_blackout_dates (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            newspaper_id INT UNSIGNED NOT NULL,
            blackout_date DATE NOT NULL,
            reason       VARCHAR(200) DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY uq_np_date (newspaper_id, blackout_date),
            KEY idx_newspaper (newspaper_id)
        ) $c;");

        // Seed default email templates
        self::seed_email_templates();
        self::seed_faqs();
    }

    // TRACE: seed_email_templates() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_email_templates(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'nas_email_templates';

        $templates = [
            [
                'template_key' => 'booking_received',
                'name'         => 'Booking Received',
                'subject'      => 'Booking #{order_id} Received — {brand_name}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#1A3A5C;padding:24px 32px"><img src="{logo_url}" style="height:40px" alt="{brand_name}"><h1 style="color:#fff;margin:12px 0 0;font-size:20px">Booking Received!</h1></div><div style="padding:32px"><p style="color:#374151;font-size:16px">Hi {client_name},</p><p style="color:#374151">Thank you for your booking. We have received your request and our team will review it shortly.</p><table style="width:100%;border-collapse:collapse;margin:20px 0"><tr><td style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold">Order ID</td><td style="padding:8px;border:1px solid #e2e8f0">#{order_id}</td></tr><tr><td style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold">Newspaper</td><td style="padding:8px;border:1px solid #e2e8f0">{newspaper_name}</td></tr><tr><td style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold">Category</td><td style="padding:8px;border:1px solid #e2e8f0">{category}</td></tr><tr><td style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold">Total Price</td><td style="padding:8px;border:1px solid #e2e8f0">{total_price}</td></tr></table><a href="{dashboard_link}" style="display:inline-block;background:#2563EB;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">View Booking</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name} | <a href="{booking_link}">Book Another Ad</a></div></div>',
                'placeholders' => '{client_name},{order_id},{newspaper_name},{category},{total_price},{dashboard_link},{booking_link},{brand_name},{logo_url}',
            ],
            [
                'template_key' => 'payment_confirmed',
                'name'         => 'Payment Confirmed',
                'subject'      => 'Payment Confirmed for Booking #{order_id} — {brand_name}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#16A34A;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">✅ Payment Confirmed!</h1></div><div style="padding:32px"><p style="color:#374151">Hi {client_name}, your payment has been received successfully.</p><p><strong>Order:</strong> #{order_id} &nbsp;|&nbsp; <strong>Amount:</strong> {total_price}</p><p style="color:#374151">Please upload your ad material from your dashboard to proceed.</p><a href="{dashboard_link}" style="display:inline-block;background:#16A34A;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Upload Ad Material</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{total_price},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'material_approved',
                'name'         => 'Ad Material Approved',
                'subject'      => 'Your Ad Material is Approved — Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#0D9488;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">✅ Material Approved!</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Great news! Your ad material for Booking <strong>#{order_id}</strong> has been approved. We will now submit it to <strong>{newspaper_name}</strong>.</p><p>You will receive another update once the publication date is confirmed.</p><a href="{dashboard_link}" style="display:inline-block;background:#0D9488;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Track Status</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{newspaper_name},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'material_rejected',
                'name'         => 'Ad Material Rejected',
                'subject'      => 'Action Required: Re-upload Material for Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#DC2626;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">⚠️ Material Rejected</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Unfortunately, your ad material for Booking <strong>#{order_id}</strong> was not accepted.</p><div style="background:#fef2f2;border-left:4px solid #DC2626;padding:12px 16px;margin:16px 0"><strong>Reason:</strong> {rejection_reason}</div><p>Please re-upload corrected material from your dashboard.</p><a href="{dashboard_link}" style="display:inline-block;background:#DC2626;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Re-upload Material</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{rejection_reason},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'ad_published',
                'name'         => 'Ad Published Today',
                'subject'      => '🎉 Your Ad is Published Today — Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#7C3AED;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">🎉 Your Ad is Live!</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Your advertisement is published today in <strong>{newspaper_name}</strong>!</p><p>Booking Reference: <strong>#{order_id}</strong></p><p>We will send you the publication proof shortly. Thank you for choosing {brand_name}.</p><a href="{dashboard_link}" style="display:inline-block;background:#7C3AED;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Download Proof</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{newspaper_name},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'proof_delivered',
                'name'         => 'Publication Proof Delivered',
                'subject'      => 'Publication Proof Ready — Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#1A3A5C;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">📄 Proof / Tear Sheet Ready</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>The publication proof for your ad in <strong>{newspaper_name}</strong> is now available.</p><p>Booking Reference: <strong>#{order_id}</strong></p><a href="{dashboard_link}" style="display:inline-block;background:#1A3A5C;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Download Proof</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{newspaper_name},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'order_completed',
                'name'         => 'Order Completed',
                'subject'      => 'Order #{order_id} Completed — Thank You!',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#16A34A;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">✅ Order Completed!</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Your order <strong>#{order_id}</strong> has been successfully completed. Thank you for trusting {brand_name}!</p><p>We would love your feedback.</p><a href="{dashboard_link}" style="display:inline-block;background:#16A34A;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Book Another Ad</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'welcome_client',
                'name'         => 'Welcome New Client',
                'subject'      => 'Welcome to {brand_name} — Your Account is Ready',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#1A3A5C;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">Welcome to {brand_name}!</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Your account has been created. You can now book newspaper ads across 300+ Indian newspapers in minutes.</p><a href="{dashboard_link}" style="display:inline-block;background:#2563EB;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Go to Dashboard</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'ticket_raised',
                'name'         => 'Support Ticket Raised',
                'subject'      => 'Support Ticket #{ticket_id} Received — {brand_name}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#1A3A5C;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">🎫 Ticket Received</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>We have received your support request. Our team will respond within 24 hours.</p><p><strong>Ticket ID:</strong> #{ticket_id}</p><p><strong>Subject:</strong> {subject}</p><a href="{dashboard_link}" style="display:inline-block;background:#1A3A5C;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">View Ticket</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{ticket_id},{subject},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'material_reminder',
                'name'         => 'Material Upload Reminder (7 days)',
                'subject'      => 'Reminder: Please Upload Your Ad Material — Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#D97706;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">⏰ Upload Reminder</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>This is a friendly reminder to upload your ad material for Booking <strong>#{order_id}</strong>. Your payment has been confirmed but we are still waiting for the material.</p><a href="{dashboard_link}" style="display:inline-block;background:#D97706;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Upload Now</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'pub_date_confirmed',
                'name'         => 'Publication Date Confirmed',
                'subject'      => 'Publication Date Confirmed for Booking #{order_id}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#0D9488;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">📅 Publication Confirmed</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>Your ad has been submitted to <strong>{newspaper_name}</strong>.</p><p><strong>Publication Date:</strong> {publication_date}</p><p>We will notify you again on the day your ad goes live.</p><a href="{dashboard_link}" style="display:inline-block;background:#0D9488;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">View Booking</a></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{order_id},{newspaper_name},{publication_date},{dashboard_link},{brand_name}',
            ],
            [
                'template_key' => 'contact_auto_reply',
                'name'         => 'Contact Form Auto-Reply',
                'subject'      => 'We received your message — {brand_name}',
                'html_body'    => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden"><div style="background:#1A3A5C;padding:24px 32px"><h1 style="color:#fff;margin:0;font-size:20px">Thanks for reaching out!</h1></div><div style="padding:32px"><p>Hi {client_name},</p><p>We have received your message and will get back to you within 1 business day.</p><p><strong>Your message:</strong></p><blockquote style="border-left:3px solid #e2e8f0;padding:8px 16px;color:#6b7280">{message}</blockquote></div><div style="background:#f8fafc;padding:16px 32px;color:#6b7280;font-size:13px">&copy; {brand_name}</div></div>',
                'placeholders' => '{client_name},{message},{brand_name}',
            ],
        ];

        foreach ( $templates as $tpl ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `$t` (template_key, name, subject, html_body, placeholders) VALUES (%s,%s,%s,%s,%s)",
                $tpl['template_key'], $tpl['name'], $tpl['subject'], $tpl['html_body'], $tpl['placeholders']
            ));
        }
    }

    // TRACE: seed_faqs() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private static function seed_faqs(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'nas_faqs';

        $faqs = [
            ['Booking', 'How do I book a newspaper ad?', 'Simply click "Book an Ad", select your city, newspaper, and category. Our step-by-step wizard guides you through the entire process in minutes.'],
            ['Booking', 'What types of ads can I book?', 'We support Classified Text, Display, Matrimonial, Property, Recruitment, Obituary, Public Notice, Education, and more across 300+ newspapers.'],
            ['Booking', 'How many days in advance must I book?', 'Most newspapers require 2-3 days advance booking. The calendar in our booking wizard automatically highlights available dates.'],
            ['Payment', 'What payment methods are accepted?', 'We accept UPI, Net Banking, Credit/Debit Cards, and Wallets via Razorpay. Bank Transfer is also available.'],
            ['Payment', 'Is my payment secure?', 'Yes. All payments are processed by Razorpay, a PCI-DSS compliant payment gateway. We do not store your card details.'],
            ['Payment', 'Will I receive an invoice?', 'Yes. A GST invoice is automatically generated and available for download from your dashboard immediately after payment.'],
            ['Material', 'What file formats are accepted for Display ads?', 'We accept JPG, PNG, PDF, and TIFF files. Minimum resolution is 300 DPI. Maximum file size is 10MB.'],
            ['Material', 'What happens if my material is rejected?', 'You will receive an email with the specific reason for rejection and a link to re-upload. There is no extra charge for re-uploads.'],
            ['Publication', 'How will I know when my ad is published?', 'You will receive an email notification on the day of publication. The publication proof/tear sheet will also be emailed to you.'],
            ['Refund', 'What is the refund policy?', 'Cancellations requested 72+ hours before the publication date are eligible for a full refund. Cancellations within 72 hours may incur a cancellation fee.'],
        ];

        $i = 1;
        foreach ( $faqs as $faq ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `$t` (category, question, answer, sort_order) VALUES (%s,%s,%s,%d)",
                $faq[0], $faq[1], $faq[2], $i++
            ));
        }
    }
}
