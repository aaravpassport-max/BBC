<?php
/**
 * Migration: Cookie / tracking consent records
 *
 * ENTERPRISE GAP FIX (Phase 9, item — "no cookie-consent / tracking-consent
 * banner"): consent was previously captured only as a per-form "I agree to
 * terms" checkbox at lead submission — there was no site-wide, revocable,
 * auditable consent record for analytics/tracking scripts. This table is
 * that record: one row per consent decision (a fresh row on every change,
 * not an overwrite), keyed by an anonymous per-browser token so it works
 * for logged-out visitors as well as logged-in users, with an optional
 * user_id once a token can be tied to an account. See ConsentService.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateCookieConsents extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_cookie_consents (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token        VARCHAR(64) NOT NULL,
                user_id      BIGINT UNSIGNED NULL,
                analytics    TINYINT(1) NOT NULL DEFAULT 0,
                marketing    TINYINT(1) NOT NULL DEFAULT 0,
                ip_hash      VARCHAR(64) NULL,
                user_agent   VARCHAR(255) NULL,
                revoked_at   DATETIME NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token(token),
                INDEX idx_user(user_id),
                INDEX idx_created(created_at)
            ) {$c}"
        );
    }

    public function down(): void { /* Deliberate no-op */ }
}
