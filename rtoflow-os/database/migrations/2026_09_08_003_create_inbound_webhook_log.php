<?php
/**
 * Migration: Inbound webhook delivery log
 *
 * ENTERPRISE GAP FIX (Phase 12, item — "No inbound webhook framework beyond
 * per-integration handlers"): Razorpay and WhatsApp each had their own
 * bespoke inbound-webhook signature verification and handling directly in
 * Router.php with no shared, reusable framework for a FUTURE integration to
 * plug into. This table backs the new generic InboundWebhookService (see
 * app/Services/InboundWebhookService.php): every verified (and every
 * rejected-signature) inbound call across all providers is logged here —
 * provider name, whether the signature check passed, the raw payload, and
 * the outcome — giving a single place to audit/debug inbound integration
 * traffic instead of it being invisible unless someone thought to add
 * error_log() calls to one specific handler.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateInboundWebhookLog extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_inbound_webhook_log (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider          VARCHAR(60) NOT NULL,
                event_type        VARCHAR(120) NULL,
                signature_valid   TINYINT(1) NOT NULL DEFAULT 0,
                http_status       SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                remote_ip         VARCHAR(45) NULL,
                payload           LONGTEXT NULL,
                error_message     VARCHAR(500) NULL,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_provider(provider),
                INDEX idx_created(created_at),
                INDEX idx_sig_valid(signature_valid)
            ) {$c}"
        );

        // Per-provider inbound secrets/config for the generic 'custom'
        // provider slug — Razorpay/WhatsApp keep using their own existing
        // options (rtoflow_razorpay_*, rtoflow_wa_*); this table is only for
        // NEW integrations registered through the generic framework so they
        // don't need a bespoke settings screen just to store one secret.
        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_inbound_webhook_providers (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug              VARCHAR(60) NOT NULL,
                label             VARCHAR(150) NOT NULL,
                secret            VARCHAR(255) NOT NULL,
                signature_header  VARCHAR(80) NOT NULL DEFAULT 'X-Signature',
                signature_scheme  VARCHAR(20) NOT NULL DEFAULT 'hmac_sha256_hex',
                is_active         TINYINT(1) NOT NULL DEFAULT 1,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by        BIGINT UNSIGNED NULL,
                UNIQUE KEY uniq_slug(slug)
            ) {$c}"
        );
    }

    public function down(): void { /* Deliberate no-op */ }
}
