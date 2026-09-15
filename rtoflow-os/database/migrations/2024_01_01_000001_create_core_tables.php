<?php

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateCoreTables extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_states (
            id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name   VARCHAR(100) NOT NULL,
            code   VARCHAR(10)  NOT NULL,
            zone   VARCHAR(50)  NULL,
            UNIQUE KEY uq_code (code)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_cities (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            state_id   INT UNSIGNED NOT NULL,
            slug       VARCHAR(200) NULL,
            rto_code   VARCHAR(30)  NULL,
            rto_detail VARCHAR(200) NULL,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_state   (state_id),
            INDEX idx_slug    (slug(100)),
            INDEX idx_active  (is_active)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_rtos (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            city_id     INT UNSIGNED NOT NULL,
            code        VARCHAR(20)  NOT NULL,
            name        VARCHAR(200) NOT NULL,
            working_hrs VARCHAR(100) NULL,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_code (code),
            INDEX idx_city   (city_id),
            INDEX idx_active (is_active)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_services (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category       VARCHAR(100) NOT NULL,
            name           VARCHAR(200) NOT NULL,
            slug           VARCHAR(200) NOT NULL,
            description    TEXT NULL,
            base_price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            govt_fee       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            gst_applicable TINYINT(1)   NOT NULL DEFAULT 1,
            vendor_share   DECIMAL(5,2) NOT NULL DEFAULT 45.00,
            sla_days       INT UNSIGNED NOT NULL DEFAULT 15,
            sla_urgent_days INT UNSIGNED NOT NULL DEFAULT 7,
            form_schema_id INT UNSIGNED NULL,
            is_active      TINYINT(1)   NOT NULL DEFAULT 1,
            show_price     TINYINT(1)   NOT NULL DEFAULT 1,
            display_order  INT UNSIGNED NOT NULL DEFAULT 0,
            meta_title     VARCHAR(255) NULL,
            meta_desc      VARCHAR(500) NULL,
            schema_json    LONGTEXT NULL,
            UNIQUE KEY uq_slug (slug),
            INDEX idx_category (category),
            INDEX idx_active   (is_active)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_doc_types (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name         VARCHAR(200) NOT NULL,
            category     VARCHAR(100) NULL,
            is_mandatory TINYINT(1)   NOT NULL DEFAULT 0,
            formats      VARCHAR(200) NULL DEFAULT 'pdf,jpg,png',
            max_size_mb  INT UNSIGNED NOT NULL DEFAULT 5,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_vendors (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id          BIGINT UNSIGNED NOT NULL,
            vendor_number    VARCHAR(30)  NOT NULL,
            full_name        VARCHAR(200) NOT NULL,
            mobile           VARCHAR(20)  NOT NULL,
            email            VARCHAR(100) NOT NULL,
            cities           JSON NULL,
            services         JSON NULL,
            bank_details_enc TEXT NULL COMMENT 'AES-256-GCM encrypted JSON',
            aadhaar_hash     CHAR(64) NULL COMMENT 'SHA-256 HMAC for dedup',
            pan_enc          TEXT NULL COMMENT 'AES-256-GCM encrypted',
            kyc_status       VARCHAR(20) NOT NULL DEFAULT 'pending',
            kyc_verified_at  DATETIME NULL,
            kyc_verified_by  BIGINT UNSIGNED NULL,
            rating           DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            total_jobs       INT UNSIGNED NOT NULL DEFAULT 0,
            completion_rate  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            acceptance_rate  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status           VARCHAR(20)  NOT NULL DEFAULT 'active',
            suspend_reason   TEXT NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vnum  (vendor_number),
            UNIQUE KEY uq_email (email),
            INDEX idx_user    (user_id),
            INDEX idx_status  (status),
            INDEX idx_rating  (rating),
            INDEX idx_kyc     (kyc_status)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_assignments (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id     BIGINT UNSIGNED NOT NULL,
            vendor_id   BIGINT UNSIGNED NOT NULL,
            status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME NULL,
            rejected_at DATETIME NULL,
            reject_reason TEXT NULL,
            INDEX idx_lead    (lead_id),
            INDEX idx_vendor  (vendor_id),
            INDEX idx_status  (status)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_leads (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_number    VARCHAR(30)  NOT NULL,
            service_id     INT UNSIGNED NOT NULL DEFAULT 0,
            city_id        INT UNSIGNED NOT NULL DEFAULT 0,
            rto_id         INT UNSIGNED NULL,
            client_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            vendor_id      BIGINT UNSIGNED NULL,
            assigned_staff BIGINT UNSIGNED NULL,
            status         VARCHAR(50)  NOT NULL DEFAULT 'created',
            priority       TINYINT UNSIGNED NOT NULL DEFAULT 2,
            source         VARCHAR(30)  NOT NULL DEFAULT 'web',
            sla_deadline   DATETIME NULL,
            sla_breached   TINYINT(1) NOT NULL DEFAULT 0,
            sla_warned     TINYINT(1) NOT NULL DEFAULT 0,
            total_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            gst_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_status VARCHAR(20)  NOT NULL DEFAULT 'unpaid',
            risk_score     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            internal_notes LONGTEXT NULL,
            cancel_reason  TEXT NULL,
            completed_at   DATETIME NULL,
            cancelled_at   DATETIME NULL,
            deleted_at     DATETIME NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lnum      (lead_number),
            INDEX idx_status        (status),
            INDEX idx_client        (client_id),
            INDEX idx_vendor        (vendor_id),
            INDEX idx_service       (service_id),
            INDEX idx_city          (city_id),
            INDEX idx_created       (created_at),
            INDEX idx_sla           (sla_deadline),
            INDEX idx_pay_status    (payment_status),
            INDEX idx_deleted       (deleted_at),
            INDEX idx_priority      (priority),
            INDEX idx_risk          (risk_score)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_lead_meta (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id    BIGINT UNSIGNED NOT NULL,
            meta_key   VARCHAR(200) NOT NULL,
            meta_value LONGTEXT NULL,
            INDEX idx_lead_key (lead_id, meta_key(100))
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_documents (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id       BIGINT UNSIGNED NOT NULL,
            doc_type_id   INT UNSIGNED NOT NULL DEFAULT 0,
            file_path     VARCHAR(500) NOT NULL,
            file_name     VARCHAR(255) NOT NULL,
            file_size_kb  INT UNSIGNED NOT NULL DEFAULT 0,
            mime_type     VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            verified_by   BIGINT UNSIGNED NULL,
            verified_at   DATETIME NULL,
            reject_reason TEXT NULL,
            uploaded_by   BIGINT UNSIGNED NOT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead_status (lead_id, status),
            INDEX idx_uploader    (uploaded_by)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_payments (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id      BIGINT UNSIGNED NOT NULL,
            invoice_id   BIGINT UNSIGNED NULL,
            txn_id       VARCHAR(100) NULL,
            amount       DECIMAL(10,2) NOT NULL,
            gst_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            type         VARCHAR(30)  NOT NULL DEFAULT 'full',
            method       VARCHAR(50)  NOT NULL,
            gateway_ref  VARCHAR(200) NULL,
            idempotency_key VARCHAR(100) NULL COMMENT 'Prevents duplicate payment recording',
            status       VARCHAR(20)  NOT NULL DEFAULT 'completed',
            notes        TEXT NULL,
            created_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_idempotency (idempotency_key),
            INDEX idx_lead    (lead_id),
            INDEX idx_status  (status),
            INDEX idx_created (created_at),
            INDEX idx_method  (method)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_invoices (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50)  NOT NULL,
            lead_id        BIGINT UNSIGNED NOT NULL,
            client_id      BIGINT UNSIGNED NOT NULL,
            subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            gst_rate       DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
            cgst           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sgst           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            igst           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status         VARCHAR(20)  NOT NULL DEFAULT 'draft',
            issued_at      DATETIME NULL,
            due_at         DATETIME NULL,
            pdf_path       VARCHAR(500) NULL,
            client_gstin   VARCHAR(20)  NULL,
            place_of_supply VARCHAR(5)  NULL COMMENT 'State code for GST',
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_invoice (invoice_number),
            INDEX idx_lead   (lead_id),
            INDEX idx_client (client_id),
            INDEX idx_status (status)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_refunds (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_id   BIGINT UNSIGNED NOT NULL,
            lead_id      BIGINT UNSIGNED NOT NULL,
            amount       DECIMAL(10,2) NOT NULL,
            reason       TEXT NULL,
            gateway_ref  VARCHAR(200) NULL,
            status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
            approved_by  BIGINT UNSIGNED NULL,
            approved_at  DATETIME NULL,
            processed_at DATETIME NULL,
            created_by   BIGINT UNSIGNED NOT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment (payment_id),
            INDEX idx_lead    (lead_id),
            INDEX idx_status  (status)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_vendor_payouts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id   BIGINT UNSIGNED NOT NULL,
            period      VARCHAR(20)  NOT NULL,
            gross_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tds_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            net_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            leads_json    JSON NULL,
            notes         TEXT NULL,
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            utr_number    VARCHAR(50)  NULL COMMENT 'Bank transfer reference',
            paid_at       DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vendor (vendor_id),
            INDEX idx_status (status),
            INDEX idx_period (period)
        ) {$c}");

        // P11-DEAD-005: rto_form_schemas — reserved for dynamic form builder (not yet active)
        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_form_schemas (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_id INT UNSIGNED NULL,
            name       VARCHAR(200) NOT NULL,
            steps_json LONGTEXT NOT NULL,
            version    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service (service_id),
            INDEX idx_active  (is_active)
        ) {$c}");

        // P11-DEAD-005: rto_automation_rules — reserved for automation engine (not yet active)
        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_automation_rules (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name            VARCHAR(200) NOT NULL,
            trigger_event   VARCHAR(100) NOT NULL,
            conditions_json LONGTEXT NULL,
            actions_json    LONGTEXT NOT NULL,
            priority        INT UNSIGNED NOT NULL DEFAULT 10,
            is_active       TINYINT(1) NOT NULL DEFAULT 1,
            run_count       INT UNSIGNED NOT NULL DEFAULT 0,
            fail_count      INT UNSIGNED NOT NULL DEFAULT 0,
            last_run        DATETIME NULL,
            last_error      TEXT NULL,
            INDEX idx_trigger (trigger_event),
            INDEX idx_active  (is_active, priority)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_notification_templates (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug       VARCHAR(100) NOT NULL,
            channel    VARCHAR(20)  NOT NULL,
            subject    VARCHAR(255) NULL,
            body       LONGTEXT NOT NULL,
            variables  JSON NULL COMMENT 'Available template variables',
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_slug_channel (slug, channel)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_notifications (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id     BIGINT UNSIGNED NULL,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel     VARCHAR(20)  NOT NULL,
            template_id VARCHAR(100) NOT NULL,
            recipient   VARCHAR(200) NOT NULL,
            message     TEXT NOT NULL,
            status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
            error_msg   TEXT NULL,
            attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sent_at     DATETIME NULL,
            next_retry  DATETIME NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user      (user_id),
            INDEX idx_status    (status),
            INDEX idx_lead      (lead_id),
            INDEX idx_channel   (channel),
            INDEX idx_retry     (next_retry, status)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_messages (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id     BIGINT UNSIGNED NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message     LONGTEXT NOT NULL,
            is_email    TINYINT(1) NOT NULL DEFAULT 0,
            sender_type VARCHAR(20)  NOT NULL DEFAULT 'client',
            read_at     DATETIME NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead    (lead_id),
            INDEX idx_user    (user_id),
            INDEX idx_created (created_at)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_complaints (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            complaint_number VARCHAR(30)  NOT NULL,
            complaint_type   VARCHAR(50)  NOT NULL,
            lead_id          BIGINT UNSIGNED NULL,
            complainant_id   BIGINT UNSIGNED NOT NULL,
            assigned_to      BIGINT UNSIGNED NULL,
            subject          VARCHAR(500) NOT NULL,
            description      LONGTEXT NOT NULL,
            status           VARCHAR(50)  NOT NULL DEFAULT 'open',
            priority         TINYINT UNSIGNED NOT NULL DEFAULT 2,
            resolution_note  LONGTEXT NULL,
            resolved_at      DATETIME NULL,
            sla_deadline     DATETIME NULL,
            sla_breached     TINYINT(1) NOT NULL DEFAULT 0,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cnum      (complaint_number),
            INDEX idx_complainant   (complainant_id),
            INDEX idx_status        (status),
            INDEX idx_lead          (lead_id),
            INDEX idx_assigned      (assigned_to)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_ratings (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id    BIGINT UNSIGNED NOT NULL,
            vendor_id  BIGINT UNSIGNED NOT NULL,
            score      TINYINT UNSIGNED NOT NULL,
            review     TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lead_vendor (lead_id, vendor_id),
            INDEX idx_vendor (vendor_id)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_logs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id    BIGINT UNSIGNED NULL,
            user_id    BIGINT UNSIGNED NULL,
            action     VARCHAR(100) NOT NULL,
            old_value  LONGTEXT NULL,
            new_value  LONGTEXT NULL,
            ip_address VARCHAR(50)  NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead    (lead_id),
            INDEX idx_user    (user_id),
            INDEX idx_action  (action),
            INDEX idx_created (created_at)
        ) {$c}");

        // P11-DEAD-005: rto_job_queue — reserved for background job system (not yet active)
        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_job_queue (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            job_class   VARCHAR(255) NOT NULL,
            payload     LONGTEXT NOT NULL,
            queue       VARCHAR(50)  NOT NULL DEFAULT 'default',
            priority    TINYINT UNSIGNED NOT NULL DEFAULT 10,
            status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
            attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            error       TEXT NULL,
            run_at      DATETIME NOT NULL,
            started_at  DATETIME NULL,
            finished_at DATETIME NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_queue_status (queue, status, run_at),
            INDEX idx_status       (status),
            INDEX idx_priority     (priority)
        ) {$c}");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_holidays (
            id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(50)  NOT NULL DEFAULT 'national',
            INDEX idx_date (date)
        ) {$c}");

        // P11-DEAD-005: rto_sessions — reserved for custom session store (not yet active)
        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_sessions (
            id         VARCHAR(128) NOT NULL PRIMARY KEY,
            user_id    BIGINT UNSIGNED NOT NULL,
            ip         VARCHAR(50)  NULL,
            user_agent VARCHAR(500) NULL,
            payload    LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_active DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user       (user_id),
            INDEX idx_last_active (last_active)
        ) {$c}");

        // API keys table
        \RTOFLOW\Auth\ApiKeyAuth::createTable();
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $tables = [
            'rto_sessions', 'rto_holidays', 'rto_job_queue', 'rto_logs',
            'rto_ratings', 'rto_complaints', 'rto_messages', 'rto_notifications',
            'rto_notification_templates', 'rto_automation_rules', 'rto_form_schemas',
            'rto_vendor_payouts', 'rto_refunds', 'rto_invoices', 'rto_payments',
            'rto_documents', 'rto_lead_meta', 'rto_leads', 'rto_assignments',
            'rto_vendors', 'rto_doc_types', 'rto_services', 'rto_rtos',
            'rto_cities', 'rto_states', 'rto_api_keys', 'rto_migrations',
        ];
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
        }
        // NOTE: rto_audit_log intentionally NOT dropped — it is a separate audit table
        // that may be needed for compliance and is not part of the plugin's own schema.
    }
}
