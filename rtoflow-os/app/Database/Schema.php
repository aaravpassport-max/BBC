<?php

namespace RTOFLOW\Database;

if (!defined('ABSPATH')) exit;

/**
 * Database Schema
 *
 * Creates / upgrades all RTOFLOW OS database tables.
 * Called on plugin activation and version upgrades.
 */
class Schema
{
    public function run(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $tables = [
"CREATE TABLE IF NOT EXISTS {$p}rto_states (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, code VARCHAR(10) NOT NULL, zone VARCHAR(50) NULL, UNIQUE KEY code(code)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_cities (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, state_id INT NOT NULL, slug VARCHAR(200) NULL, rto_code VARCHAR(30) NULL, rto_detail VARCHAR(200) NULL, is_active TINYINT NOT NULL DEFAULT 1, INDEX idx_state(state_id), INDEX idx_slug(slug)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_rtos (id INT AUTO_INCREMENT PRIMARY KEY, city_id INT NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL, working_hrs VARCHAR(100) NULL, is_active TINYINT NOT NULL DEFAULT 1, UNIQUE KEY code(code), INDEX idx_city(city_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_services (id INT AUTO_INCREMENT PRIMARY KEY, category VARCHAR(100) NOT NULL, name VARCHAR(200) NOT NULL, slug VARCHAR(200) NOT NULL, description TEXT NULL, base_price DECIMAL(10,2) NOT NULL DEFAULT 0, govt_fee DECIMAL(10,2) NOT NULL DEFAULT 0, gst_applicable TINYINT NOT NULL DEFAULT 1, gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18, vendor_share DECIMAL(5,2) NOT NULL DEFAULT 45, sla_days INT NOT NULL DEFAULT 15, sla_urgent_days INT NOT NULL DEFAULT 7, form_schema_id INT NULL, is_active TINYINT NOT NULL DEFAULT 1, show_price TINYINT NOT NULL DEFAULT 1, display_order INT NOT NULL DEFAULT 0, UNIQUE KEY slug(slug)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_doc_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, category VARCHAR(100) NULL, is_mandatory TINYINT NOT NULL DEFAULT 0, formats VARCHAR(200) NULL, max_size_mb INT NOT NULL DEFAULT 5, is_active TINYINT NOT NULL DEFAULT 1) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_vendors (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, vendor_number VARCHAR(30) NOT NULL, full_name VARCHAR(200) NOT NULL, mobile VARCHAR(20) NOT NULL, email VARCHAR(100) NOT NULL, cities JSON NULL, services JSON NULL, bank_details JSON NULL, kyc_status VARCHAR(20) NOT NULL DEFAULT 'pending', rating DECIMAL(3,2) NOT NULL DEFAULT 0, total_jobs INT NOT NULL DEFAULT 0, completion_rate DECIMAL(5,2) NOT NULL DEFAULT 0, acceptance_rate DECIMAL(5,2) NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY vnum(vendor_number), INDEX idx_user(user_id), INDEX idx_status(status), INDEX idx_rating(rating)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_assignments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, vendor_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, accepted_at DATETIME NULL, rejected_at DATETIME NULL, reject_reason TEXT NULL, INDEX idx_lead(lead_id), INDEX idx_vendor(vendor_id), INDEX idx_status(status)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_leads (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_number VARCHAR(30) NOT NULL, service_id INT NOT NULL DEFAULT 0, city_id INT NOT NULL DEFAULT 0, rto_id INT NULL, client_id BIGINT UNSIGNED NOT NULL DEFAULT 0, vendor_id BIGINT UNSIGNED NULL, status VARCHAR(50) NOT NULL DEFAULT 'created', priority TINYINT NOT NULL DEFAULT 2, source VARCHAR(30) NOT NULL DEFAULT 'web', sla_deadline DATETIME NULL, sla_breached TINYINT NOT NULL DEFAULT 0, total_amount DECIMAL(10,2) NOT NULL DEFAULT 0, paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0, gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0, payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid', risk_score TINYINT NOT NULL DEFAULT 0, internal_notes LONGTEXT NULL, completed_at DATETIME NULL, cancelled_at DATETIME NULL, cancel_reason TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP, deleted_at DATETIME NULL, UNIQUE KEY lnum(lead_number), INDEX idx_status(status), INDEX idx_client(client_id), INDEX idx_vendor(vendor_id), INDEX idx_service(service_id), INDEX idx_city(city_id), INDEX idx_created(created_at), INDEX idx_sla(sla_deadline), INDEX idx_pay_status(payment_status)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_lead_meta (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(200) NOT NULL, meta_value LONGTEXT NULL, INDEX idx_lead_key(lead_id, meta_key(100))) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_documents (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, doc_type_id INT NOT NULL DEFAULT 0, file_path VARCHAR(500) NOT NULL, file_name VARCHAR(255) NOT NULL, file_size_kb INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending', verified_by BIGINT UNSIGNED NULL, verified_at DATETIME NULL, reject_reason TEXT NULL, uploaded_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_lead_status(lead_id, status)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_payments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, txn_id VARCHAR(100) NULL, amount DECIMAL(10,2) NOT NULL, gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0, tds_amount DECIMAL(10,2) NOT NULL DEFAULT 0, type VARCHAR(30) NOT NULL DEFAULT 'full', method VARCHAR(50) NOT NULL, gateway_ref VARCHAR(200) NULL, razorpay_order_id VARCHAR(100) NULL, status VARCHAR(20) NOT NULL DEFAULT 'completed', created_by BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_lead(lead_id), INDEX idx_status(status), INDEX idx_created(created_at)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_form_schemas (id INT AUTO_INCREMENT PRIMARY KEY, service_id INT NULL, name VARCHAR(200) NOT NULL, steps_json LONGTEXT NOT NULL, is_active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_service(service_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_automation_rules (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, trigger_event VARCHAR(100) NOT NULL, conditions_json LONGTEXT NULL, actions_json LONGTEXT NOT NULL, priority INT NOT NULL DEFAULT 10, is_active TINYINT NOT NULL DEFAULT 1, run_count INT NOT NULL DEFAULT 0, last_run DATETIME NULL, INDEX idx_trigger(trigger_event), INDEX idx_active(is_active)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_notifications (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, channel VARCHAR(20) NOT NULL, template_id VARCHAR(100) NOT NULL, recipient VARCHAR(200) NOT NULL, message TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_user(user_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_notification_templates (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(100) NOT NULL, channel VARCHAR(20) NOT NULL, subject VARCHAR(255) NULL, body LONGTEXT NOT NULL, is_active TINYINT NOT NULL DEFAULT 1, UNIQUE KEY slug_channel(slug, channel)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_complaints (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, complaint_number VARCHAR(30) NOT NULL, complaint_type VARCHAR(50) NOT NULL, lead_id BIGINT UNSIGNED NULL, complainant_id BIGINT UNSIGNED NOT NULL, subject VARCHAR(500) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL DEFAULT 'open', priority TINYINT NOT NULL DEFAULT 2, resolution_note LONGTEXT NULL, resolved_at DATETIME NULL, sla_deadline DATETIME NULL, sla_breached TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY cnum(complaint_number), INDEX idx_complainant(complainant_id), INDEX idx_status(status)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_ratings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, vendor_id BIGINT UNSIGNED NOT NULL, score TINYINT NOT NULL, review TEXT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_vendor(vendor_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_refunds (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payment_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(10,2) NOT NULL, reason TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', processed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_payment(payment_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_vendor_payouts (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id BIGINT UNSIGNED NOT NULL, period VARCHAR(20) NOT NULL, gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0, tds_amount DECIMAL(10,2) NOT NULL DEFAULT 0, net_amount DECIMAL(10,2) NOT NULL DEFAULT 0, leads_json JSON NULL, notes TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', paid_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_vendor(vendor_id), INDEX idx_status(status)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_messages (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, message LONGTEXT NOT NULL, is_email TINYINT NOT NULL DEFAULT 0, sender_type VARCHAR(20) NOT NULL DEFAULT 'client', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_lead(lead_id)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, lead_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL, old_value LONGTEXT NULL, new_value LONGTEXT NULL, ip_address VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_lead(lead_id), INDEX idx_action(action), INDEX idx_created(created_at)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_holidays (id INT AUTO_INCREMENT PRIMARY KEY, date DATE NOT NULL, name VARCHAR(200) NOT NULL, INDEX idx_date(date)) $c",
"CREATE TABLE IF NOT EXISTS {$p}rto_invoices (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(50) NOT NULL, lead_id BIGINT UNSIGNED NOT NULL, payment_id BIGINT UNSIGNED NULL, client_id BIGINT UNSIGNED NOT NULL, subtotal DECIMAL(10,2) NOT NULL DEFAULT 0, gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0, total DECIMAL(10,2) NOT NULL DEFAULT 0, gst_type VARCHAR(10) NOT NULL DEFAULT 'igst', pdf_path VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY inv_num(invoice_number), INDEX idx_lead(lead_id), INDEX idx_client(client_id)) $c",
        ];

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        // WordPress roles
        remove_role('rto_admin');
        remove_role('rto_staff');
        remove_role('rto_vendor');
        remove_role('rto_client');

        add_role('rto_admin',  'RTO Admin',  ['read' => true, 'rto_admin' => true, 'rto_staff' => true]);
        add_role('rto_staff',  'RTO Staff',  ['read' => true, 'rto_staff' => true]);
        add_role('rto_vendor', 'RTO Vendor', ['read' => true, 'rto_vendor' => true]);
        add_role('rto_client', 'RTO Client', ['read' => true]);

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('rto_admin');
            $admin->add_cap('rto_staff');
        }
    }
}
