<?php
/**
 * BOS_Installer — Creates all database tables and seeds defaults.
 * Direct port of BOS_Database::install() without WordPress dbDelta.
 * Uses IF NOT EXISTS so re-running is always safe.
 */
class BOS_Installer {

    const SCHEMA_VERSION = '1.3.0';

    public static function run(): void {
        try {
            BOS_DB::connect(); // Ensure connection before version check
        } catch (Throwable $e) {
            error_log('[BOS_Installer] DB connection failed: ' . $e->getMessage());
            return;
        }

        $current = BOS_DB::get_setting('schema_version', '0');
        if ($current === self::SCHEMA_VERSION) {
            BOS_Auth::cleanup_expired(); // Lightweight maintenance every request
            return;
        }

        try {
            self::create_tables();
            self::seed_defaults();
            BOS_DB::set_setting('schema_version', self::SCHEMA_VERSION);
        } catch (Throwable $e) {
            error_log('[BOS_Installer] Schema install failed: ' . $e->getMessage());
        }
    }

    private static function create_tables(): void {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $p = BOS_DB::$prefix;

        $standard = "id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                     uuid        VARCHAR(36) NOT NULL,
                     created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                     updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                     deleted_at  DATETIME NULL DEFAULT NULL,
                     created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                     updated_by  BIGINT UNSIGNED NULL DEFAULT NULL";

        $tables = [

        "CREATE TABLE IF NOT EXISTS `{$p}settings` (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            setting_key   VARCHAR(100) NOT NULL,
            setting_value LONGTEXT NULL,
            autoload      TINYINT(1) NOT NULL DEFAULT 1,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_key (setting_key)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}users` (
            $standard,
            name            VARCHAR(100) NOT NULL,
            email           VARCHAR(255) NOT NULL,
            password_hash   VARCHAR(255) NOT NULL,
            role            VARCHAR(20) NOT NULL DEFAULT 'Staff',
            status          VARCHAR(20) NOT NULL DEFAULT 'Active',
            phone           VARCHAR(20) NULL,
            two_fa_enabled  TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at   DATETIME NULL,
            last_login_ip   VARCHAR(45) NULL,
            failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until    DATETIME NULL,
            notes           TEXT NULL,
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_email (email),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}user_sessions` (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id      BIGINT UNSIGNED NOT NULL,
            token_hash   VARCHAR(255) NOT NULL,
            refresh_hash VARCHAR(255) NOT NULL,
            ip_address   VARCHAR(45) NULL,
            user_agent   TEXT NULL,
            expires_at   DATETIME NOT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_token (token_hash),
            KEY idx_expires (expires_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}token_blacklist` (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash     VARCHAR(255) NOT NULL,
            blacklisted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at     DATETIME NOT NULL,
            UNIQUE KEY idx_token (token_hash),
            KEY idx_expires (expires_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}brands` (
            $standard,
            name        VARCHAR(255) NOT NULL,
            slug        VARCHAR(100) NOT NULL,
            legal_name  VARCHAR(255) NULL,
            logo_url    VARCHAR(500) NULL,
            status      VARCHAR(20) NOT NULL DEFAULT 'Active',
            gstin       VARCHAR(20) NULL,
            pan         VARCHAR(10) NULL,
            address     TEXT NULL,
            city        VARCHAR(100) NULL,
            state       VARCHAR(100) NULL,
            pin_code    VARCHAR(10) NULL,
            phone       VARCHAR(20) NULL,
            email       VARCHAR(255) NULL,
            website     VARCHAR(255) NULL,
            bank_details JSON NULL,
            is_primary  TINYINT(1) NOT NULL DEFAULT 0,
            sort_order  INT NOT NULL DEFAULT 0,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}categories` (
            $standard,
            category_type VARCHAR(50) NOT NULL,
            name          VARCHAR(100) NOT NULL,
            code          VARCHAR(10) NULL,
            description   TEXT NULL,
            parent_id     BIGINT UNSIGNED NULL,
            color         VARCHAR(7) NULL,
            icon          VARCHAR(50) NULL,
            sort_order    INT NOT NULL DEFAULT 0,
            is_default    TINYINT(1) NOT NULL DEFAULT 0,
            is_active     TINYINT(1) NOT NULL DEFAULT 1,
            tax_rate      DECIMAL(5,2) NULL,
            hsn_sac_code  VARCHAR(20) NULL,
            notes         TEXT NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_type (category_type),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}clients` (
            $standard,
            client_type       VARCHAR(50) NOT NULL DEFAULT 'Business',
            display_name      VARCHAR(100) NOT NULL,
            first_name        VARCHAR(50) NULL,
            last_name         VARCHAR(50) NULL,
            business_name     VARCHAR(200) NULL,
            primary_email     VARCHAR(255) NULL,
            secondary_email   VARCHAR(255) NULL,
            primary_phone     VARCHAR(20) NULL,
            secondary_phone   VARCHAR(20) NULL,
            whatsapp          VARCHAR(20) NULL,
            website           VARCHAR(255) NULL,
            address1          VARCHAR(255) NULL,
            address2          VARCHAR(255) NULL,
            city              VARCHAR(100) NULL,
            state             VARCHAR(100) NULL,
            pin_code          VARCHAR(10) NULL,
            country           VARCHAR(100) NOT NULL DEFAULT 'India',
            pan               VARCHAR(10) NULL,
            gstin             VARCHAR(20) NULL,
            gst_type          VARCHAR(30) NULL,
            tds_applicable    TINYINT(1) NOT NULL DEFAULT 0,
            tds_rate          DECIMAL(5,2) NULL,
            tds_section       VARCHAR(20) NULL,
            credit_limit      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            credit_alert_pct  TINYINT UNSIGNED NOT NULL DEFAULT 80,
            credit_block      TINYINT(1) NOT NULL DEFAULT 0,
            lead_source       VARCHAR(100) NULL,
            assigned_to       BIGINT UNSIGNED NULL,
            client_since      DATE NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'Active',
            status_reason     VARCHAR(200) NULL,
            internal_notes    TEXT NULL,
            portal_enabled    TINYINT(1) NOT NULL DEFAULT 0,
            portal_password   VARCHAR(255) NULL,
            tags              JSON NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at),
            KEY idx_status (status),
            KEY idx_email (primary_email),
            KEY idx_name (display_name)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}vendors` (
            $standard,
            vendor_type           VARCHAR(50) NOT NULL DEFAULT 'Supplier',
            display_name          VARCHAR(100) NOT NULL,
            business_name         VARCHAR(200) NULL,
            contact_person        VARCHAR(100) NULL,
            primary_email         VARCHAR(255) NULL,
            primary_phone         VARCHAR(20) NULL,
            address1              VARCHAR(255) NULL,
            address2              VARCHAR(255) NULL,
            city                  VARCHAR(100) NULL,
            state                 VARCHAR(100) NULL,
            pin_code              VARCHAR(10) NULL,
            country               VARCHAR(100) NOT NULL DEFAULT 'India',
            pan                   VARCHAR(10) NULL,
            gstin                 VARCHAR(20) NULL,
            gst_type              VARCHAR(30) NULL,
            tds_applicable        TINYINT(1) NOT NULL DEFAULT 0,
            tds_rate              DECIMAL(5,2) NULL,
            tds_section           VARCHAR(20) NULL,
            bank_account_name     VARCHAR(100) NULL,
            bank_account_number   VARCHAR(30) NULL,
            bank_ifsc             VARCHAR(11) NULL,
            bank_name             VARCHAR(100) NULL,
            upi_id                VARCHAR(100) NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'Active',
            internal_notes        TEXT NULL,
            tags                  JSON NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at),
            KEY idx_status (status),
            KEY idx_name (display_name)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}services` (
            $standard,
            name              VARCHAR(100) NOT NULL,
            code              VARCHAR(20) NULL,
            description       TEXT NULL,
            unit_type         VARCHAR(30) NOT NULL DEFAULT 'Fixed',
            default_price     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            minimum_price     DECIMAL(15,2) NULL,
            hsn_sac_code      VARCHAR(20) NULL,
            gst_rate          DECIMAL(5,2) NULL,
            gst_inclusive     TINYINT(1) NOT NULL DEFAULT 0,
            tds_applicable    TINYINT(1) NOT NULL DEFAULT 0,
            tds_section       VARCHAR(20) NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'Active',
            is_active         TINYINT(1) NOT NULL DEFAULT 1,
            sort_order        INT NOT NULL DEFAULT 0,
            notes             TEXT NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at),
            KEY idx_status (status)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}service_rates` (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_id     BIGINT UNSIGNED NOT NULL,
            client_id      BIGINT UNSIGNED NULL,
            price          DECIMAL(15,2) NOT NULL,
            effective_from DATE NOT NULL,
            effective_to   DATE NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_service (service_id),
            KEY idx_client (client_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}bank_accounts` (
            $standard,
            account_name    VARCHAR(100) NOT NULL,
            account_number  VARCHAR(30) NOT NULL,
            bank_name       VARCHAR(100) NOT NULL,
            ifsc_code       VARCHAR(11) NULL,
            branch          VARCHAR(100) NULL,
            account_type    VARCHAR(30) NULL,
            upi_id          VARCHAR(100) NULL,
            is_default      TINYINT(1) NOT NULL DEFAULT 0,
            show_on_invoice TINYINT(1) NOT NULL DEFAULT 1,
            status          VARCHAR(20) NOT NULL DEFAULT 'Active',
            opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}number_sequences` (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sequence_type  VARCHAR(30) NOT NULL,
            brand_id       BIGINT UNSIGNED NULL,
            financial_year VARCHAR(10) NOT NULL,
            prefix         VARCHAR(20) NULL,
            current_value  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pad_length     TINYINT NOT NULL DEFAULT 4,
            reset_yearly   TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY idx_type_year (sequence_type, financial_year)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}invoices` (
            $standard,
            invoice_number  VARCHAR(30) NOT NULL,
            invoice_date    DATE NOT NULL,
            due_date        DATE NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            brand_id        BIGINT UNSIGNED NULL,
            quotation_id    BIGINT UNSIGNED NULL,
            po_number       VARCHAR(50) NULL,
            place_of_supply VARCHAR(100) NULL,
            is_igst         TINYINT(1) NOT NULL DEFAULT 0,
            currency        VARCHAR(3) NOT NULL DEFAULT 'INR',
            status          VARCHAR(30) NOT NULL DEFAULT 'Draft',
            sent_at         DATETIME NULL,
            paid_at         DATETIME NULL,
            cancelled_at    DATETIME NULL,
            cancel_reason   TEXT NULL,
            subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_discount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            taxable_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_cgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_sgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_igst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            round_off       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            grand_total     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tds_deducted    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tds_section     VARCHAR(20) NULL,
            net_receivable  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            amount_paid     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_status  VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
            amount_outstanding DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            terms           TEXT NULL,
            notes_to_client TEXT NULL,
            internal_notes  TEXT NULL,
            internal_ref    VARCHAR(100) NULL,
            invoice_title   VARCHAR(100) NULL,
            declaration     TEXT NULL,
            template_id     VARCHAR(30) NOT NULL DEFAULT 'classic',
            color_theme     VARCHAR(20) NOT NULL DEFAULT 'navy',
            bank_account_id BIGINT UNSIGNED NULL,
            share_token     VARCHAR(64) NULL,
            share_token_exp DATETIME NULL,
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_number (invoice_number),
            KEY idx_client (client_id),
            KEY idx_status (status),
            KEY idx_deleted (deleted_at),
            KEY idx_due_date (due_date)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}invoice_items` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id      BIGINT UNSIGNED NOT NULL,
            service_id      BIGINT UNSIGNED NULL,
            line_number     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            description     TEXT NOT NULL,
            hsn_sac_code    VARCHAR(20) NULL,
            quantity        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            unit            VARCHAR(30) NOT NULL DEFAULT 'Fixed',
            unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discount_type   VARCHAR(10) NULL,
            discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            taxable_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gst_rate        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            cgst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            sgst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            igst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_invoice (invoice_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}invoice_payments` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid            VARCHAR(36) NOT NULL,
            invoice_id      BIGINT UNSIGNED NOT NULL,
            payment_date    DATE NOT NULL,
            amount          DECIMAL(15,2) NOT NULL,
            payment_method  VARCHAR(50) NOT NULL DEFAULT 'UPI',
            reference       VARCHAR(100) NULL,
            notes           TEXT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_invoice (invoice_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}quotations` (
            $standard,
            quote_number    VARCHAR(30) NOT NULL,
            quote_date      DATE NOT NULL,
            valid_until     DATE NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            brand_id        BIGINT UNSIGNED NULL,
            prepared_by     BIGINT UNSIGNED NOT NULL,
            title           VARCHAR(150) NULL,
            currency        VARCHAR(3) NOT NULL DEFAULT 'INR',
            status          VARCHAR(30) NOT NULL DEFAULT 'Draft',
            sent_at         DATETIME NULL,
            accepted_at     DATETIME NULL,
            rejected_at     DATETIME NULL,
            rejection_reason TEXT NULL,
            converted_invoice_id BIGINT UNSIGNED NULL,
            place_of_supply VARCHAR(100) NULL,
            subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_discount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            taxable_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_cgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_sgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_igst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            round_off       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            grand_total     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            terms           TEXT NULL,
            notes_to_client TEXT NULL,
            internal_notes  TEXT NULL,
            internal_ref    VARCHAR(50) NULL,
            invoice_title   VARCHAR(100) NULL,
            declaration     TEXT NULL,
            template_id     VARCHAR(30) NOT NULL DEFAULT 'classic',
            color_theme     VARCHAR(20) NOT NULL DEFAULT 'navy',
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_client (client_id),
            KEY idx_status (status),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}quotation_items` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quotation_id    BIGINT UNSIGNED NOT NULL,
            service_id      BIGINT UNSIGNED NULL,
            line_number     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            description     TEXT NOT NULL,
            hsn_sac_code    VARCHAR(20) NULL,
            quantity        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            unit            VARCHAR(30) NOT NULL DEFAULT 'Fixed',
            unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            discount_type   VARCHAR(10) NULL,
            discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            taxable_amount  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gst_rate        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            cgst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            sgst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            igst_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_quotation (quotation_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}credit_notes` (
            $standard,
            cn_number       VARCHAR(30) NOT NULL,
            cn_date         DATE NOT NULL,
            invoice_id      BIGINT UNSIGNED NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            brand_id        BIGINT UNSIGNED NULL,
            reason          TEXT NOT NULL,
            cn_type         VARCHAR(30) NOT NULL,
            subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_cgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_sgst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_igst      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            grand_total     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            refund_method   VARCHAR(30) NULL,
            refund_reference VARCHAR(100) NULL,
            refund_date     DATE NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'Draft',
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_invoice (invoice_id),
            KEY idx_client (client_id),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}payments` (
            $standard,
            payment_number  VARCHAR(30) NOT NULL,
            receipt_number  VARCHAR(30) NULL,
            payment_date    DATE NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            brand_id        BIGINT UNSIGNED NULL,
            amount_received DECIMAL(15,2) NOT NULL,
            payment_method  VARCHAR(50) NOT NULL,
            bank_account_id BIGINT UNSIGNED NULL,
            transaction_ref VARCHAR(100) NULL,
            transaction_date DATE NULL,
            tds_deducted    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tds_section     VARCHAR(20) NULL,
            notes           TEXT NULL,
            is_advance      TINYINT(1) NOT NULL DEFAULT 0,
            is_reversed     TINYINT(1) NOT NULL DEFAULT 0,
            reversal_reason TEXT NULL,
            reversed_at     DATETIME NULL,
            currency        VARCHAR(3) NOT NULL DEFAULT 'INR',
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_number (payment_number),
            KEY idx_client (client_id),
            KEY idx_date (payment_date),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}payment_invoice_links` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_id  BIGINT UNSIGNED NOT NULL,
            invoice_id  BIGINT UNSIGNED NOT NULL,
            amount      DECIMAL(15,2) NOT NULL,
            KEY idx_payment (payment_id),
            KEY idx_invoice (invoice_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}expenses` (
            $standard,
            expense_number   VARCHAR(30) NOT NULL,
            expense_date     DATE NOT NULL,
            title            VARCHAR(200) NOT NULL,
            category_id      BIGINT UNSIGNED NOT NULL,
            vendor_id        BIGINT UNSIGNED NULL,
            brand_id         BIGINT UNSIGNED NULL,
            amount           DECIMAL(15,2) NOT NULL,
            currency         VARCHAR(3) NOT NULL DEFAULT 'INR',
            payment_method   VARCHAR(50) NULL,
            bank_account_id  BIGINT UNSIGNED NULL,
            reference        VARCHAR(100) NULL,
            bill_date        DATE NULL,
            due_date         DATE NULL,
            payment_status   VARCHAR(20) NOT NULL DEFAULT 'Paid',
            amount_paid      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gst_paid         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gst_rate         DECIMAL(5,2) NULL,
            hsn_sac_code     VARCHAR(20) NULL,
            itc_eligible     TINYINT(1) NOT NULL DEFAULT 0,
            tds_deducted     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tds_section      VARCHAR(20) NULL,
            description      TEXT NULL,
            is_recurring     TINYINT(1) NOT NULL DEFAULT 0,
            recurring_frequency VARCHAR(20) NULL,
            recurring_until  DATE NULL,
            is_reimbursable  TINYINT(1) NOT NULL DEFAULT 0,
            tags             JSON NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_date (expense_date),
            KEY idx_vendor (vendor_id),
            KEY idx_category (category_id),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}subscriptions` (
            $standard,
            sub_number       VARCHAR(30) NOT NULL,
            client_id        BIGINT UNSIGNED NOT NULL,
            brand_id         BIGINT UNSIGNED NULL,
            plan_id          BIGINT UNSIGNED NULL,
            title            VARCHAR(150) NOT NULL,
            billing_amount   DECIMAL(15,2) NOT NULL,
            billing_cycle    VARCHAR(20) NOT NULL,
            start_date       DATE NOT NULL,
            end_date         DATE NULL,
            next_invoice_date DATE NULL,
            grace_period_days TINYINT NOT NULL DEFAULT 7,
            auto_generate    TINYINT(1) NOT NULL DEFAULT 1,
            gst_rate         DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status           VARCHAR(20) NOT NULL DEFAULT 'Active',
            cancelled_at     DATETIME NULL,
            cancel_reason    TEXT NULL,
            notes            TEXT NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_client (client_id),
            KEY idx_status (status),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}subscription_plans` (
            $standard,
            name             VARCHAR(100) NOT NULL,
            code             VARCHAR(20) NULL,
            description      TEXT NULL,
            billing_cycle    VARCHAR(20) NOT NULL,
            price            DECIMAL(15,2) NOT NULL,
            setup_fee        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            trial_days       INT NOT NULL DEFAULT 0,
            gst_rate         DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status           VARCHAR(20) NOT NULL DEFAULT 'Active',
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}time_entries` (
            $standard,
            entry_date   DATE NOT NULL,
            client_id    BIGINT UNSIGNED NOT NULL,
            service_id   BIGINT UNSIGNED NULL,
            task         TEXT NULL,
            start_time   TIME NULL,
            end_time     TIME NULL,
            duration     DECIMAL(6,2) NOT NULL,
            hourly_rate  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            is_billable  TINYINT(1) NOT NULL DEFAULT 1,
            status       VARCHAR(20) NOT NULL DEFAULT 'Unbilled',
            invoice_id   BIGINT UNSIGNED NULL,
            notes        TEXT NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_client (client_id),
            KEY idx_date (entry_date),
            KEY idx_status (status),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}documents` (
            $standard,
            title        VARCHAR(200) NOT NULL,
            doc_type_id  BIGINT UNSIGNED NULL,
            doc_number   VARCHAR(100) NULL,
            doc_date     DATE NULL,
            expiry_date  DATE NULL,
            alert_days   INT NOT NULL DEFAULT 30,
            client_id    BIGINT UNSIGNED NULL,
            vendor_id    BIGINT UNSIGNED NULL,
            description  TEXT NULL,
            file_path    VARCHAR(500) NULL,
            file_name    VARCHAR(255) NULL,
            tags         JSON NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_client (client_id),
            KEY idx_expiry (expiry_date),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}compliance_items` (
            $standard,
            title            VARCHAR(200) NOT NULL,
            category_id      BIGINT UNSIGNED NULL,
            description      TEXT NULL,
            due_date         DATE NOT NULL,
            responsible_id   BIGINT UNSIGNED NULL,
            status           VARCHAR(20) NOT NULL DEFAULT 'Pending',
            filing_date      DATE NULL,
            filing_reference VARCHAR(100) NULL,
            period           VARCHAR(50) NULL,
            amount_due       DECIMAL(15,2) NULL,
            amount_paid      DECIMAL(15,2) NULL,
            notes            TEXT NULL,
            is_recurring     TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_due_date (due_date),
            KEY idx_status (status),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}notifications` (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id      BIGINT UNSIGNED NOT NULL,
            type         VARCHAR(50) NOT NULL,
            title        VARCHAR(200) NOT NULL,
            body         TEXT NULL,
            entity_type  VARCHAR(50) NULL,
            entity_uuid  VARCHAR(36) NULL,
            is_read      TINYINT(1) NOT NULL DEFAULT 0,
            read_at      DATETIME NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_read (is_read)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}audit_logs` (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            timestamp      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id        BIGINT UNSIGNED NULL,
            user_name      VARCHAR(100) NULL,
            user_role      VARCHAR(20) NULL,
            ip_address     VARCHAR(45) NULL,
            user_agent     TEXT NULL,
            module         VARCHAR(50) NOT NULL,
            action         VARCHAR(30) NOT NULL,
            record_id      VARCHAR(36) NULL,
            record_label   VARCHAR(200) NULL,
            old_values     JSON NULL,
            new_values     JSON NULL,
            changed_fields JSON NULL,
            result         VARCHAR(10) NOT NULL DEFAULT 'SUCCESS',
            error_message  TEXT NULL,
            KEY idx_user (user_id),
            KEY idx_module (module),
            KEY idx_timestamp (timestamp)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}communication_logs` (
            $standard,
            entity_type  VARCHAR(20) NOT NULL,
            entity_id    BIGINT UNSIGNED NOT NULL,
            comm_type    VARCHAR(30) NOT NULL,
            subject      VARCHAR(255) NULL,
            details      TEXT NULL,
            follow_up    TINYINT(1) NOT NULL DEFAULT 0,
            follow_up_date DATE NULL,
            KEY idx_entity (entity_type, entity_id),
            KEY idx_deleted (deleted_at)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}bank_statements` (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid             VARCHAR(36) NOT NULL,
            bank_account_id  BIGINT UNSIGNED NOT NULL,
            transaction_date DATE NOT NULL,
            description      TEXT NULL,
            debit_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            credit_amount    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            balance          DECIMAL(15,2) NULL,
            status           VARCHAR(30) NOT NULL DEFAULT 'Unmatched',
            notes            TEXT NULL,
            imported_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_account (bank_account_id),
            KEY idx_status (status)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}reconciliation_matches` (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            statement_id     BIGINT UNSIGNED NOT NULL,
            match_type       VARCHAR(20) NOT NULL,
            matched_id       BIGINT UNSIGNED NOT NULL,
            match_confidence VARCHAR(20) NOT NULL DEFAULT 'Manual',
            matched_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            matched_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_statement (statement_id)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}service_presets` (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid            VARCHAR(36) NOT NULL,
            name            VARCHAR(100) NOT NULL,
            icon            VARCHAR(10) NOT NULL DEFAULT '📄',
            invoice_title   VARCHAR(100) NULL,
            description     TEXT NULL,
            amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            gst_rate        DECIMAL(5,2) NOT NULL DEFAULT 18.00,
            notes           TEXT NULL,
            terms           TEXT NULL,
            declaration     TEXT NULL,
            template_id     VARCHAR(30) NULL,
            color_theme     VARCHAR(20) NULL,
            sort_order      INT NOT NULL DEFAULT 0,
            is_active       TINYINT(1) NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_by      BIGINT UNSIGNED NULL DEFAULT NULL,
            deleted_at      DATETIME NULL,
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_active (is_active)
        ) $charset",

        "CREATE TABLE IF NOT EXISTS `{$p}payment_register` (
            id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid             CHAR(36) NOT NULL UNIQUE,
            client_name      VARCHAR(255) NOT NULL,
            mobile_number    VARCHAR(20) NULL,
            email_address    VARCHAR(255) NULL,
            service_description TEXT NULL,
            invoice_amount   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            amount_received  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_date     DATE NOT NULL,
            payment_method   VARCHAR(50) NOT NULL DEFAULT 'Cash',
            utr_txn_id       VARCHAR(100) NULL,
            pan_number       VARCHAR(10) NULL,
            gstin            VARCHAR(15) NULL,
            address          TEXT NULL,
            city             VARCHAR(100) NULL,
            state            VARCHAR(100) NULL,
            pin_code         VARCHAR(10) NULL,
            tds_deducted     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tds_section      VARCHAR(20) NULL,
            notes            TEXT NULL,
            invoice_generated TINYINT(1) NOT NULL DEFAULT 0,
            invoice_uuid     CHAR(36) NULL,
            status           VARCHAR(20) NOT NULL DEFAULT 'Received',
            created_by       BIGINT UNSIGNED NULL,
            updated_by       BIGINT UNSIGNED NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at       DATETIME NULL
        ) $charset",

        ];

        foreach ($tables as $sql) {
            BOS_DB::query($sql);
        }

        // Alter-if-missing: ensure share token columns exist for fresh vs upgrade
        BOS_DB::query("ALTER TABLE `{$p}invoices` ADD COLUMN IF NOT EXISTS share_token VARCHAR(64) NULL");
        BOS_DB::query("ALTER TABLE `{$p}invoices` ADD COLUMN IF NOT EXISTS share_token_exp DATETIME NULL");
        BOS_DB::query("ALTER TABLE `{$p}invoice_payments` ADD COLUMN IF NOT EXISTS uuid VARCHAR(36) NULL AFTER id");
    }

    private static function seed_defaults(): void {
        $p = BOS_DB::$prefix;

        // ── Default admin user ────────────────────────────────────────────────
        $existing = BOS_DB::get_row(
            "SELECT id FROM `{$p}users` WHERE role='Admin' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
        );
        $default_hash = password_hash('changeme123', PASSWORD_BCRYPT, ['cost' => 12]);
        if (!$existing) {
            BOS_DB::insert("{$p}users", [
                'uuid'          => BOS_Helpers::uuid(),
                'name'          => 'Admin',
                'email'         => 'admin@businessos.local',
                'password_hash' => $default_hash,
                'role'          => 'Admin',
                'status'        => 'Active',
                'created_by'    => 0,
            ]);
        } else {
            BOS_DB::update("{$p}users",
                ['password_hash' => $default_hash, 'failed_attempts' => 0, 'locked_until' => null, 'status' => 'Active'],
                ['id' => $existing->id]
            );
        }

        // ── Default brand ─────────────────────────────────────────────────────
        $existing_brand = BOS_DB::get_var("SELECT id FROM `{$p}brands` WHERE is_primary=1 LIMIT 1");
        if (!$existing_brand) {
            BOS_DB::insert("{$p}brands", [
                'uuid'       => BOS_Helpers::uuid(),
                'name'       => 'My Business',
                'slug'       => 'main',
                'is_primary' => 1,
                'status'     => 'Active',
                'created_by' => 0,
            ]);
        }

        // ── Default settings ──────────────────────────────────────────────────
        $defaults = [
            'financial_year_start'   => 'April',
            'default_currency'       => 'INR',
            'currency_symbol'        => '₹',
            'number_format'          => 'Indian',
            'decimal_places'         => '2',
            'amounts_in_words'       => '1',
            'tax_features_enabled'   => '0',
            'gst_enabled'            => '0',
            'tds_enabled'            => '0',
            'quotation_enabled'      => '1',
            'subscription_enabled'   => '0',
            'time_tracking_enabled'  => '0',
            'compliance_enabled'     => '0',
            'document_mgmt_enabled'  => '0',
            'bank_recon_enabled'     => '0',
            'recurring_expenses'     => '0',
            'default_invoice_due_days' => '30',
            'invoice_prefix'         => 'INV',
            'quotation_prefix'       => 'QT',
            'payment_prefix'         => 'RCP',
            'expense_prefix'         => 'EXP',
            'credit_note_prefix'     => 'CN',
            'invoice_pad_length'     => '4',
            'setup_complete'         => '0',
            'default_gst_rate'       => '18',
            'smtp_enabled'           => '0',
            'smtp_host'              => '',
            'smtp_port'              => '587',
            'smtp_username'          => '',
            'smtp_password'          => '',
            'smtp_encryption'        => 'tls',
            'smtp_from_name'         => 'Business OS',
            'smtp_from_email'        => '',
        ];

        foreach ($defaults as $key => $value) {
            $exists = BOS_DB::get_var(
                "SELECT id FROM `{$p}settings` WHERE setting_key=?", [$key]
            );
            if (!$exists) {
                BOS_DB::insert("{$p}settings", ['setting_key' => $key, 'setting_value' => $value]);
            }
        }

        // ── Default categories ────────────────────────────────────────────────
        self::seed_categories();
    }

    private static function seed_categories(): void {
        $p = BOS_DB::$prefix;
        $cats = [
            ['income',          'Consulting',              'CONS', '#4f46e5'],
            ['income',          'Documentation Service',   'DOCS', '#0891b2'],
            ['income',          'Registration Service',    'REG',  '#059669'],
            ['income',          'Training',                'TRN',  '#d97706'],
            ['income',          'Other Income',            'OTH',  '#6b7280'],
            ['expense',         'Hosting & Infrastructure','HOST', '#7c3aed'],
            ['expense',         'Software & Subscriptions','SOFT', '#0284c7'],
            ['expense',         'Travel & Conveyance',     'TRVL', '#047857'],
            ['expense',         'Office Supplies',         'OFFC', '#b45309'],
            ['expense',         'Marketing & Advertising', 'MKTG', '#be185d'],
            ['expense',         'Professional Fees',       'PROF', '#0f766e'],
            ['expense',         'Salaries & Wages',        'SAL',  '#dc2626'],
            ['expense',         'Bank Charges',            'BANK', '#6b7280'],
            ['payment_method',  'UPI',                     'UPI',  '#4f46e5'],
            ['payment_method',  'Bank Transfer (NEFT)',    'NEFT', '#0891b2'],
            ['payment_method',  'RTGS',                    'RTGS', '#0284c7'],
            ['payment_method',  'IMPS',                    'IMPS', '#7c3aed'],
            ['payment_method',  'Cash',                    'CASH', '#059669'],
            ['payment_method',  'Cheque',                  'CHQ',  '#d97706'],
            ['payment_method',  'Card',                    'CARD', '#be185d'],
            ['client_type',     'Individual',              'IND',  '#4f46e5'],
            ['client_type',     'Business',                'BUS',  '#0891b2'],
            ['client_type',     'NGO',                     'NGO',  '#059669'],
            ['client_type',     'Government',              'GOVT', '#d97706'],
            ['vendor_type',     'Freelancer',              'FREE', '#4f46e5'],
            ['vendor_type',     'Agency',                  'AGCY', '#0891b2'],
            ['vendor_type',     'Supplier',                'SUPP', '#059669'],
            ['document_type',   'Agreement',               'AGR',  '#4f46e5'],
            ['document_type',   'Certificate',             'CERT', '#0891b2'],
            ['document_type',   'License',                 'LIC',  '#7c3aed'],
        ];

        $sort = [];
        foreach ($cats as [$type, $name, $code, $color]) {
            $sort[$type] = ($sort[$type] ?? 0) + 1;
            $exists = BOS_DB::get_var(
                "SELECT id FROM `{$p}categories` WHERE category_type=? AND name=?", [$type, $name]
            );
            if (!$exists) {
                BOS_DB::insert("{$p}categories", [
                    'uuid'          => BOS_Helpers::uuid(),
                    'category_type' => $type,
                    'name'          => $name,
                    'code'          => $code,
                    'color'         => $color,
                    'sort_order'    => $sort[$type],
                    'is_active'     => 1,
                    'created_by'    => 0,
                ]);
            }
        }
    }
}
