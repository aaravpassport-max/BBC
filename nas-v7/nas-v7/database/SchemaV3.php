<?php
namespace NAS\Database;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schema V3 — adds / upgrades tables for the Super Combo rebuild.
 * Called from the main plugin activation hook AFTER Schema::create_tables().
 */
class SchemaV3 {

    // TRACE: upgrade() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function upgrade(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'nas_';

        /* ── 1. is_internal + sent_via columns on messages ── */
        $cols = $wpdb->get_results("SHOW COLUMNS FROM {$p}messages");
        $col_names = array_column($cols, 'Field');
        if (!in_array('is_internal', $col_names))
            $wpdb->query("ALTER TABLE {$p}messages ADD COLUMN is_internal TINYINT(1) DEFAULT 0 AFTER is_read");
        if (!in_array('sent_via_email', $col_names))
            $wpdb->query("ALTER TABLE {$p}messages ADD COLUMN sent_via_email TINYINT(1) DEFAULT 0 AFTER is_internal");
        if (!in_array('sent_via_whatsapp', $col_names))
            $wpdb->query("ALTER TABLE {$p}messages ADD COLUMN sent_via_whatsapp TINYINT(1) DEFAULT 0 AFTER sent_via_email");
        if (!in_array('reply_to_id', $col_names))
            $wpdb->query("ALTER TABLE {$p}messages ADD COLUMN reply_to_id BIGINT UNSIGNED DEFAULT NULL AFTER sent_via_whatsapp");
        if (!in_array('attachments', $col_names))
            $wpdb->query("ALTER TABLE {$p}messages ADD COLUMN attachments LONGTEXT DEFAULT NULL AFTER reply_to_id");

        /* ── 2. Enhanced bookings columns ── */
        $bcols = array_column($wpdb->get_results("SHOW COLUMNS FROM {$p}bookings"), 'Field');
        $add = [
            'base_amount'      => "DECIMAL(12,2) DEFAULT 0.00",
            // ad_type: already defined as ENUM in Schema.php — SchemaV3 skips if present
            // 'ad_type' omitted to avoid overriding ENUM with VARCHAR
            'edition'          => "VARCHAR(120) DEFAULT ''",
            'samples_viewed'   => "TINYINT(1) DEFAULT 0",
            'combo_offer_id'   => "INT UNSIGNED DEFAULT NULL",
            'width_cm'         => "DECIMAL(6,2) DEFAULT NULL",
            'height_cm'        => "DECIMAL(6,2) DEFAULT NULL",
            'word_count'       => "SMALLINT UNSIGNED DEFAULT 0",
            'publish_date'     => "DATE DEFAULT NULL",
            'proof_url'        => "VARCHAR(400) DEFAULT ''",
            'tear_sheet_url'   => "VARCHAR(400) DEFAULT ''",
            'discount_amount'  => "DECIMAL(10,2) DEFAULT 0.00",
            'tax_amount'       => "DECIMAL(10,2) DEFAULT 0.00",
            'coupon_code'      => "VARCHAR(50) DEFAULT ''",
            // Wizard denormalised columns — stored for fast display without JOINs
            'newspaper_name'   => "VARCHAR(200) DEFAULT ''",
            'category_name'    => "VARCHAR(100) DEFAULT ''",
            'city_name'        => "VARCHAR(120) DEFAULT ''",
            'ad_title'         => "VARCHAR(300) DEFAULT ''",
            'client_company'   => "VARCHAR(200) DEFAULT ''",
            'client_gst'       => "VARCHAR(30) DEFAULT ''",
            'whatsapp_optin'   => "TINYINT(1) DEFAULT 1",
            'multi_newspapers' => "LONGTEXT DEFAULT NULL COMMENT 'JSON array of all newspapers in this booking'",
            'quoted_amount'    => "DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Admin-set quotation amount'",
            'quote_note'       => "TEXT DEFAULT NULL COMMENT 'Admin note attached to quotation'",
            'vendor_assigned_at' => "DATETIME DEFAULT NULL COMMENT 'When vendor was assigned'",
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $bcols))
                $wpdb->query("ALTER TABLE {$p}bookings ADD COLUMN {$col} {$def}");
        }

        /* ── 3. Sample Ads ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}sample_ads (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NOT NULL DEFAULT 0,
            title       VARCHAR(250) NOT NULL DEFAULT '',
            content     LONGTEXT,
            format_type VARCHAR(30) DEFAULT 'classified',
            word_count  SMALLINT UNSIGNED DEFAULT 0,
            image_url   VARCHAR(400) DEFAULT '',
            is_active   TINYINT(1) DEFAULT 1,
            sort_order  INT DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_cat (category_id),
            KEY idx_active (is_active)
        ) $c;");

        /* ── 4. Combo Offers ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}combo_offers (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name           VARCHAR(200) NOT NULL DEFAULT '',
            cities         LONGTEXT,
            newspapers     LONGTEXT,
            discount_type  ENUM('percentage','flat') DEFAULT 'percentage',
            discount_value DECIMAL(8,2) DEFAULT 0.00,
            conditions     TEXT,
            valid_from     DATE DEFAULT NULL,
            valid_to       DATE DEFAULT NULL,
            is_active      TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $c;");

        /* ── 5. Quick Replies (enhanced) ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}quick_replies (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(200) NOT NULL DEFAULT '',
            category   VARCHAR(60) DEFAULT 'general',
            content    LONGTEXT,
            sort_order INT DEFAULT 0,
            is_active  TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_cat (category),
            KEY idx_active (is_active)
        ) $c;");

        /* ── 6. SEO City Pages cache ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}seo_pages (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            page_type    VARCHAR(40) NOT NULL DEFAULT 'city',
            ref_id       INT UNSIGNED DEFAULT 0,
            slug         VARCHAR(300) NOT NULL DEFAULT '',
            title        VARCHAR(300) DEFAULT '',
            meta_desc    VARCHAR(500) DEFAULT '',
            h1           VARCHAR(300) DEFAULT '',
            content_json LONGTEXT,
            schema_json  LONGTEXT,
            is_published TINYINT(1) DEFAULT 1,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_type (page_type),
            KEY idx_ref (ref_id)
        ) $c;");

        /* ── 7. Ad size presets ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}ad_sizes (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(100) NOT NULL,
            ad_type     VARCHAR(30) DEFAULT 'display',
            width_cm    DECIMAL(6,2) DEFAULT 0,
            height_cm   DECIMAL(6,2) DEFAULT 0,
            description VARCHAR(200) DEFAULT '',
            sort_order  INT DEFAULT 0,
            is_active   TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id)
        ) $c;");

        /* ── 8. Coupons ── */
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}coupons (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code           VARCHAR(60) NOT NULL,
            discount_type  ENUM('percentage','flat') DEFAULT 'percentage',
            discount_value DECIMAL(8,2) DEFAULT 0.00,
            min_order      DECIMAL(10,2) DEFAULT 0.00,
            max_uses       INT DEFAULT 0,
            used_count     INT DEFAULT 0,
            valid_from     DATE DEFAULT NULL,
            valid_to       DATE DEFAULT NULL,
            is_active      TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_code (code)
        ) $c;");

        /* ── 9. Seed ad sizes ── */
        if (!$wpdb->get_var("SELECT COUNT(*) FROM {$p}ad_sizes")) {
            $sizes = [
                ['Single Column 5 cm',  'display', 3.5,  5.0,  '1 col × 5 cm', 1],
                ['Single Column 10 cm', 'display', 3.5,  10.0, '1 col × 10 cm', 2],
                ['Double Column 5 cm',  'display', 7.5,  5.0,  '2 col × 5 cm', 3],
                ['Double Column 10 cm', 'display', 7.5,  10.0, '2 col × 10 cm', 4],
                ['Quarter Page',        'display', 13.5, 19.0, 'Quarter page', 5],
                ['Half Page',           'display', 28.0, 19.0, 'Half page', 6],
                ['Full Page',           'display', 28.0, 40.0, 'Full page', 7],
                ['Jacket Ad',           'display', 28.0, 5.0,  'Jacket / strip', 8],
            ];
            foreach ($sizes as $s) {
                $wpdb->insert("{$p}ad_sizes", [
                    'name' => $s[0], 'ad_type' => $s[1],
                    'width_cm' => $s[2], 'height_cm' => $s[3],
                    'description' => $s[4], 'sort_order' => $s[5], 'is_active' => 1,
                ]);
            }
        }
        /* ── price_history table (self-learning AI suggestions) ── */
        // Fixed: was using {$wpdb->prefix}nas_price_history which creates a double-prefixed
        // table name ({prefix}nas_nas_price_history). Now uses $p which is already {prefix}nas_.
        dbDelta("CREATE TABLE IF NOT EXISTS {$p}price_history (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            newspaper_id INT UNSIGNED NOT NULL DEFAULT 0,
            category_id  INT UNSIGNED NOT NULL DEFAULT 0,
            ad_type      VARCHAR(30) DEFAULT 'classified',
            client_price DECIMAL(10,2) DEFAULT 0,
            vendor_cost  DECIMAL(10,2) DEFAULT 0,
            profit       DECIMAL(10,2) DEFAULT 0,
            margin_pct   DECIMAL(5,2) DEFAULT 0,
            recorded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_np_cat (newspaper_id, category_id)
        ) $c;");

        /* ── 10. Critical missing indexes on bookings table ── */
        // These are the two most-queried columns — full table scans without these.
        $existing_keys = $wpdb->get_col("SHOW INDEX FROM {$p}bookings", 2);
        if ( ! in_array('idx_status', $existing_keys, true) ) {
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD KEY `idx_status` (`status`)");
        }
        if ( ! in_array('idx_submitted', $existing_keys, true) ) {
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD KEY `idx_submitted` (`submitted_at`)");
        }
        if ( ! in_array('idx_status_submitted', $existing_keys, true) ) {
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD KEY `idx_status_submitted` (`status`, `submitted_at`)");
        }
        if ( ! in_array('idx_client', $existing_keys, true) ) {
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD KEY `idx_client` (`client_id`)");
        }

        /* ── 11. Migrate rate_cards.ad_type ENUM — remove classified_text ── */
        // classified_text was a wizard-only concept. The DB stores 'classified'.
        // Update any existing rows then alter the ENUM to match bookings table.
        if ( $wpdb->get_var("SHOW TABLES LIKE '{$p}rate_cards'") ) {
            $wpdb->query("UPDATE `{$p}rate_cards` SET ad_type='classified' WHERE ad_type='classified_text'");
            // Only alter ENUM if classified_text is still in column definition
            $col_def = $wpdb->get_var("SHOW COLUMNS FROM `{$p}rate_cards` LIKE 'ad_type'", 1);
            if ( $col_def && strpos($col_def, 'classified_text') !== false ) {
                $wpdb->query("ALTER TABLE `{$p}rate_cards` MODIFY `ad_type` ENUM('classified','display','display_classified') NOT NULL DEFAULT 'classified'");
            }
        }

        /* ── 12. Missing bookings columns used at runtime ── */
        // Columns used in queries/updates that were never defined in Schema.php or SchemaV3.
        // Using SHOW COLUMNS guard so safe to re-run on any existing install.
        $bcols = array_column( $wpdb->get_results("SHOW COLUMNS FROM `{$p}bookings`"), 'Field' );

        // payment_id: capture_payment() saves the gateway payment ID here
        // bookings has payment_ref but PaymentModule uses payment_id — add as alias column
        if ( ! in_array('payment_id', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `payment_id` VARCHAR(100) DEFAULT '' AFTER `payment_ref`");

        // paid_amount: capture_payment() records how much was actually paid
        if ( ! in_array('paid_amount', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `paid_amount` DECIMAL(12,2) DEFAULT 0.00 AFTER `payment_id`");

        // vendor_payment_amount: amount paid to vendor (set by admin when vendor is paid)
        if ( ! in_array('vendor_payment_amount', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `vendor_payment_amount` DECIMAL(12,2) DEFAULT 0.00 AFTER `vendor_cost`");

        // vendor_payment_status: payment state to vendor
        if ( ! in_array('vendor_payment_status', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `vendor_payment_status` ENUM('pending','paid') DEFAULT 'pending' AFTER `vendor_payment_amount`");

        // client_name: denormalized from clients table for fast search without JOIN
        // BookingRepository::get_all() searches WHERE client_name LIKE %s — needs this column
        if ( ! in_array('client_name', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `client_name` VARCHAR(150) DEFAULT '' AFTER `client_id`");

        // FIX (audit): client_phone: modules/MissingHandlers.php's nas_get_pending_bookings_handler()
        // and nas_get_all_bookings_handler() both SELECT/WHERE b.client_phone directly on bookings,
        // but this column never existed anywhere in the schema — the entire query fails (unknown
        // column), silently returning zero rows. This broke Moderation's Pending Review tab and the
        // global booking search completely. Denormalized here the same way client_name already is.
        if ( ! in_array('client_phone', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `client_phone` VARCHAR(20) DEFAULT '' AFTER `client_name`");

        // One-time backfill for rows created before this column existed. Idempotent — only
        // touches rows where client_phone is still empty, safe to run on every upgrade() call.
        $wpdb->query(
            "UPDATE `{$p}bookings` b
             INNER JOIN `{$p}clients` c ON c.id = b.client_id
             SET b.client_phone = c.phone
             WHERE (b.client_phone = '' OR b.client_phone IS NULL) AND c.phone <> ''"
        );

        // FIX (audit, per user decision): is_flagged — real boolean column for the moderation
        // Flag feature. Previously, flagging a booking just set status='under_review' (see
        // removed workaround in modules/MissingHandlers.php nas_flag_booking_handler), while the
        // "Flagged Bookings" tab queried a column that never existed (b.is_flagged), so it always
        // showed empty regardless of how many bookings had been flagged. This column makes
        // flagging independent of status, as the user asked for the "cleaner" option.
        if ( ! in_array('is_flagged', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `is_flagged` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");

        // FIX (audit, per user decision): vendor_credit_balance — tracks leftover amounts from
        // nas_admin_record_vendor_payment_handler() (modules/Admin/AdminModule.php) when a lump
        // sum payment doesn't exactly divide across pending booking costs. Previously that
        // remainder was silently dropped with no record anywhere.
        $vcols = array_column($wpdb->get_results("SHOW COLUMNS FROM {$p}vendors"), 'Field');
        if ( ! in_array('vendor_credit_balance', $vcols, true) )
            $wpdb->query("ALTER TABLE `{$p}vendors` ADD COLUMN `vendor_credit_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_orders`");

        // publication_dates: InvoiceModule queries b.publication_dates (plural)
        // bookings has publish_date (singular) — add alias column for backward compat
        if ( ! in_array('publication_dates', $bcols, true) )
            $wpdb->query("ALTER TABLE `{$p}bookings` ADD COLUMN `publication_dates` TEXT DEFAULT NULL AFTER `publish_date`");

        /* ── 13. Fix messages.sender_role ENUM — add 'vendor' ── */
        // Security::current_role() now correctly returns 'vendor' for nas_vendor users.
        // messages.sender_role ENUM was ('admin','staff','client','system') — missing 'vendor'.
        // MySQL strict mode silently stores '' for unknown ENUM values, breaking vendor chat.
        $mcols = $wpdb->get_row("SHOW COLUMNS FROM `{$p}messages` WHERE Field='sender_role'");
        if ( $mcols && strpos($mcols->Type, 'vendor') === false ) {
            $wpdb->query("ALTER TABLE `{$p}messages` MODIFY `sender_role` ENUM('admin','staff','client','vendor','system') DEFAULT 'system'");
        }


        /* ── 14. BUG FIX: Missing columns — PRE-DELIVERY AUDIT v4.0 findings ──────────
         *
         * BUG-1  payment_method missing from nas_bookings.
         *        AdminModule::save_payment() writes payment_status, payment_method, payment_ref
         *        as a single UPDATE. Without this column MySQL rejects the entire statement
         *        (Unknown column error). Database::update() returns 0 and the caller sends a
         *        ghost success — admin sees "Payment updated" but nothing is stored in DB.
         *
         * BUG-2  sender_name missing from nas_messages.
         * BUG-3  channel missing from nas_messages.
         *        ClientModule::send_message() and AdminModule::send_client_message_handler()
         *        both INSERT directly into nas_messages with sender_name and channel columns.
         *        MySQL rejects the INSERT entirely — messages are silently lost, the caller
         *        gets a ghost success ("Message sent."). Note: ChatRepository::send() does NOT
         *        use these columns and is unaffected by this fix.
         *
         * BUG-4  pan_number missing from nas_clients AND nas_vendors.
         *        AdminModule::update_client() and VendorModule::update_profile() both write
         *        pan_number. Without the column MySQL rejects the UPDATE — the entire client
         *        or vendor record update fails silently (ghost success).
         *
         * All statements use IF NOT EXISTS or in_array() guards — safe to re-run on every
         * admin_init (fully idempotent).
         */

        // BUG-1 — payment_method on nas_bookings
        $bcols_v6 = array_column( $wpdb->get_results( "SHOW COLUMNS FROM `{$p}bookings`" ), 'Field' );
        if ( ! in_array( 'payment_method', $bcols_v6, true ) ) {
            $wpdb->query( "ALTER TABLE `{$p}bookings` ADD COLUMN `payment_method` VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER `payment_status`" );
        }

        // BUG-2/3 — sender_name + channel on nas_messages
        $mcols_v6 = array_column( $wpdb->get_results( "SHOW COLUMNS FROM `{$p}messages`" ), 'Field' );
        if ( ! in_array( 'sender_name', $mcols_v6, true ) ) {
            $wpdb->query( "ALTER TABLE `{$p}messages` ADD COLUMN `sender_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `sender_role`" );
        }
        if ( ! in_array( 'channel', $mcols_v6, true ) ) {
            $wpdb->query( "ALTER TABLE `{$p}messages` ADD COLUMN `channel` VARCHAR(30) NOT NULL DEFAULT 'platform' AFTER `sender_name`" );
        }

        // BUG-4 — pan_number on nas_clients (AdminModule::update_client writes it)
        $wpdb->query( "ALTER TABLE `{$p}clients` ADD COLUMN IF NOT EXISTS `pan_number` VARCHAR(30) NOT NULL DEFAULT ''" );

        // BUG-4 — pan_number on nas_vendors (VendorModule::update_profile writes it)
        $wpdb->query( "ALTER TABLE `{$p}vendors` ADD COLUMN IF NOT EXISTS `pan_number` VARCHAR(30) NOT NULL DEFAULT ''" );

        // BUG-4b — payment gateway settings on nas_settings missing payu columns
        //          AdminDashboard::save_settings writes 'payu_salt' and 'payu_merchant_key'
        //          but neither column existed in nas_settings. All payu saves silently failed.
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `payu_merchant_key` VARCHAR(255) DEFAULT ''" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `payu_merchant_salt` VARCHAR(255) DEFAULT ''" );
        // BUG-4c — brand contact info used throughout email templates but columns missing
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_email` VARCHAR(200) DEFAULT ''" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_phone` VARCHAR(50) DEFAULT ''" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `gst_number` VARCHAR(30) DEFAULT ''" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `wa_admin_number` VARCHAR(25) DEFAULT ''" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_address` TEXT DEFAULT NULL" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `admin_alert_email` VARCHAR(200) DEFAULT ''" );

        // BUG-5 — bank_details on nas_vendors
        //        VendorModule::VendorRegisterController::handle() and MissingHandlers::nas_vendor_update_profile_handler()
        //        both INSERT / UPDATE bank_details. Column was never created → MySQL rejects the row silently.
        $wpdb->query( "ALTER TABLE `{$p}vendors` ADD COLUMN IF NOT EXISTS `bank_details` TEXT DEFAULT NULL" );

        // BUG-6 — contact_person on nas_vendors
        //        VendorDashboard::update_profile() whitelists 'contact_person' but column was not in Schema.php.
        $wpdb->query( "ALTER TABLE `{$p}vendors` ADD COLUMN IF NOT EXISTS `contact_person` VARCHAR(200) DEFAULT ''" );

        // BUG-7 — ticket_replies table (ClientModule::reply_ticket inserts into it)
        //         Table was not created in SchemaV2 — all client ticket replies silently failed.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}ticket_replies (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id    BIGINT UNSIGNED NOT NULL,
            sender_type  ENUM('client','staff','admin') DEFAULT 'client',
            sender_name  VARCHAR(150) DEFAULT '',
            message      TEXT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id)
        ) $c;" );

        /* ── END BUG FIX BLOCK ── */

        // v5 Enterprise additions — client notes + settings columns
        $wpdb->query("ALTER TABLE `{$p}clients` ADD COLUMN IF NOT EXISTS `admin_notes` TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `stripe_publishable_key` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `stripe_secret_key` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `stripe_webhook_secret` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `razorpay_key_id` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `razorpay_key_secret` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `razorpay_webhook_secret` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `bank_transfer_details` TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `founded_year` VARCHAR(10) DEFAULT '2020'");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `tagline` VARCHAR(500) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `mission` TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `vision` TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_address` TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_phone` VARCHAR(50) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `brand_email` VARCHAR(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `vendor_auto_approve` TINYINT(1) DEFAULT 0");
        $wpdb->query("ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) DEFAULT 'INR'");

        // PWA Theme columns on nas_settings — NASTheme three-layer system
        foreach ( ['pwa_theme','pwa_portal_bg','pwa_bg_preset','pwa_accent_color','pwa_button_color','pwa_icon_192','pwa_icon_512'] as $col ) {
            $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `{$col}` VARCHAR(255) DEFAULT ''" );
        }

        // FIX (audit): ai_enabled and auto_assign_vendor — AdminDashboard::save_settings() has
        // always tried to write these two fields from the admin Settings page, but neither
        // column ever existed anywhere in the schema, so both were silently dropped on every
        // save regardless of any other naming fix.
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `ai_enabled` TINYINT(1) DEFAULT 1" );
        $wpdb->query( "ALTER TABLE `{$p}settings` ADD COLUMN IF NOT EXISTS `auto_assign_vendor` TINYINT(1) DEFAULT 0" );
    }
}
