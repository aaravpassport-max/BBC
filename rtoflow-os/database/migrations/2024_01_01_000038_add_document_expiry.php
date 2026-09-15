<?php
/**
 * Migration 38: Document Expiry Tracking (rto_documents.expiry_date)
 *
 * ENTERPRISE GAP FIX (Phase 4, item 3 — "no document expiry tracking"):
 * client documents (license, insurance, RC) have no expiry_date column or
 * renewal-reminder logic despite being exactly the kind of time-bound
 * compliance document an RTO platform revolves around. Optional field —
 * NULL means "no expiry tracked for this document" (most uploaded proofs,
 * e.g. PAN, don't expire), so nothing is forced for document types that
 * genuinely have no expiry. reminder_sent_at prevents the daily cron
 * (Bootstrap::runDocumentExpiryCheck()) from re-notifying every single day
 * once a reminder has already gone out for a given expiry date.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddDocumentExpiry extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}rto_documents";

        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'expiry_date'");
        if (!$col) {
            $this->run("ALTER TABLE {$table} ADD COLUMN expiry_date DATE NULL AFTER status");
        }
        $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'expiry_reminder_sent_at'");
        if (!$col2) {
            $this->run("ALTER TABLE {$table} ADD COLUMN expiry_reminder_sent_at DATETIME NULL AFTER expiry_date");
        }

        // Insert the 'document_expiring' notification templates directly here
        // (not only in NotificationTemplateSeeder) because the seeder only
        // auto-runs on an empty rto_notification_templates table — it will
        // never fire on an already-deployed/live site. This migration DOES
        // run there via the rtoflow_db_version bump mechanism, so this is
        // the only reliable way for the new reminder to have a template to
        // send server-side (see Bootstrap::runDocumentExpiryCheck()).
        $tplTable = "{$p}rto_notification_templates";
        $company  = get_option('rtoflow_company_name', 'RTOFLOW');
        $templates = [
            [
                'slug'    => 'document_expiring',
                'channel' => 'email',
                'subject' => 'Your {doc_type_name} expires on {expiry_date}',
                'body'    => "<h2>Document Expiring Soon</h2><p>The <strong>{doc_type_name}</strong> on file for order <strong>{lead_number}</strong> expires on <strong>{expiry_date}</strong>.</p><p>Please upload a renewed copy before it expires to avoid delays.</p><p><a href='{documents_url}'>Upload Renewed Document</a></p>",
                'vars'    => ['doc_type_name', 'expiry_date', 'lead_number', 'documents_url'],
            ],
            [
                'slug'    => 'document_expiring',
                'channel' => 'sms',
                'subject' => null,
                'body'    => "{$company}: Your {doc_type_name} (order {lead_number}) expires {expiry_date}. Please upload a renewed copy soon.",
                'vars'    => ['doc_type_name', 'lead_number', 'expiry_date'],
            ],
        ];
        foreach ($templates as $tpl) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tplTable} WHERE slug = %s AND channel = %s",
                $tpl['slug'], $tpl['channel']
            ));
            if (!$existing) {
                $wpdb->insert($tplTable, [
                    'slug'      => $tpl['slug'],
                    'channel'   => $tpl['channel'],
                    'subject'   => $tpl['subject'],
                    'body'      => $tpl['body'],
                    'variables' => wp_json_encode($tpl['vars']),
                    'is_active' => 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
