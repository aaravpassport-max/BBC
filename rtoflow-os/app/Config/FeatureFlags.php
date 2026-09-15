<?php

namespace RTOFLOW\Config;

if (!defined('ABSPATH')) exit;

/**
 * Feature Flag System
 *
 * Every feature module can be enabled/disabled at runtime without code changes.
 * Flags are stored in wp_options as a single JSON blob for performance.
 *
 * Usage:
 *   FeatureFlags::is_enabled('whatsapp_notifications')
 *   FeatureFlags::enable('vendor_ratings')
 *   FeatureFlags::disable('ai_scoring')
 */
class FeatureFlags
{
    const OPTION_KEY = 'rtoflow_feature_flags';

    const DEFAULTS = [
        // Core modules (always on)
        'lead_management'         => true,
        'vendor_management'       => true,
        'payment_collection'      => true,
        'document_management'     => true,

        // Optional modules (admin-controlled)
        'whatsapp_notifications'  => false,
        'sms_notifications'       => false,
        'email_notifications'     => true,
        'ai_scoring'              => false,
        'vendor_ratings'          => true,
        'grievance_portal'        => true,
        'automation_engine'       => true,
        'form_builder'            => true,
        'public_tracking'         => true,
        'vendor_self_signup'      => false,
        'razorpay_payments'       => false,
        'payment_links'           => false,
        'vendor_payouts'          => true,
        'sla_enforcement'         => true,
        'report_export'           => true,
        'api_access'              => true,
        'webhook_razorpay'        => false,
        'webhook_whatsapp'        => false,
        'gst_invoicing'           => true,
        'tds_deduction'           => true,
    ];

    const META = [
        'lead_management'         => ['Lead Management',        'Core lead creation, tracking, and workflow'],
        'vendor_management'       => ['Vendor Management',      'Vendor onboarding, job assignment, performance'],
        'payment_collection'      => ['Payment Collection',     'Manual payment recording and tracking'],
        'document_management'     => ['Document Management',    'Document upload, verification, rejection'],
        'whatsapp_notifications'  => ['WhatsApp Notifications', 'Send updates via WhatsApp Business API'],
        'sms_notifications'       => ['SMS Notifications',      'Send SMS via MSG91 / Fast2SMS'],
        'email_notifications'     => ['Email Notifications',    'Send email notifications on key events'],
        'ai_scoring'              => ['AI Risk Scoring',        'Automatic lead risk scoring (0–100)'],
        'vendor_ratings'          => ['Vendor Ratings',         'Client ratings for completed jobs'],
        'grievance_portal'        => ['Grievance Portal',       'Complaint and grievance management'],
        'automation_engine'       => ['Automation Engine',      'Rule-based auto-actions on events'],
        'form_builder'            => ['Dynamic Form Builder',   'Build custom service request forms'],
        'public_tracking'         => ['Public Lead Tracking',   'Let clients track without login'],
        'vendor_self_signup'      => ['Vendor Self-Signup',     'Vendors can register from public page'],
        'razorpay_payments'       => ['Razorpay Gateway',       'Online payment via Razorpay checkout'],
        'payment_links'           => ['Payment Links',          'Send Razorpay payment links via WhatsApp'],
        'vendor_payouts'          => ['Vendor Payouts',         'Monthly payout calculation for vendors'],
        'sla_enforcement'         => ['SLA Enforcement',        'Auto-breach marking and escalation'],
        'report_export'           => ['Report Export',          'CSV export of leads and revenue'],
        'api_access'              => ['REST API',               'External access via REST API endpoints'],
        'webhook_razorpay'        => ['Razorpay Webhook',       'Receive payment events from Razorpay'],
        'webhook_whatsapp'        => ['WhatsApp Webhook',       'Receive incoming WhatsApp messages'],
        'gst_invoicing'           => ['GST Invoicing',          'Auto-generate GST-compliant invoices'],
        'tds_deduction'           => ['TDS Deduction',          'Auto-calculate and track TDS on vendor payouts'],
    ];

    private static ?array $flags = null;

    /**
     * FIX (Feature Flags audit — HelpContent 'features' known_limitations):
     * a codebase-wide grep (not limited to app/, since real call sites for
     * several of these flags live in integrations/ and rtoflow-os.php)
     * found that whatsapp_notifications, sms_notifications, razorpay_payments,
     * webhook_whatsapp, and api_access are all genuinely checked at real
     * call sites (integrations/WhatsApp.php:78,96,119, integrations/Sms.php:298,
     * integrations/Razorpay.php:142, rtoflow-os.php:241) — the previously
     * documented "at least 7 dead flags" list was stale. Only these two keys
     * have zero FeatureFlags::is_enabled() references anywhere in the
     * codebase: no vendor self-signup page and no Razorpay-payment-link-via-
     * WhatsApp feature exist at all, so there is nothing for either flag to
     * gate yet. Surfaced via all() as 'wired' => false so the admin UI can
     * badge them "Not yet wired" instead of implying a working control
     * exists.
     */
    const NOT_WIRED = ['vendor_self_signup', 'payment_links'];

    private static function load(): array
    {
        if (self::$flags !== null) return self::$flags;
        $stored = get_option(self::OPTION_KEY, null);
        if ($stored === null) {
            update_option(self::OPTION_KEY, wp_json_encode(self::DEFAULTS));
            return self::$flags = self::DEFAULTS;
        }
        $decoded = json_decode($stored, true) ?: [];
        return self::$flags = array_merge(self::DEFAULTS, $decoded);
    }

    public static function is_enabled(string $flag): bool
    {
        return (bool)(self::load()[$flag] ?? self::DEFAULTS[$flag] ?? false);
    }

    public static function enable(string $flag): void  { self::set($flag, true); }
    public static function disable(string $flag): void { self::set($flag, false); }

    public static function set(string $flag, bool $value): void
    {
        $flags         = self::load();
        $flags[$flag]  = $value;
        self::$flags   = $flags;
        update_option(self::OPTION_KEY, wp_json_encode($flags));
    }

    public static function bulk_save(array $submitted): void
    {
        $flags = self::load();
        $core  = ['lead_management', 'vendor_management', 'payment_collection', 'document_management'];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (in_array($key, $core, true)) continue;
            $flags[$key] = isset($submitted[$key]);
        }
        self::$flags = $flags;
        update_option(self::OPTION_KEY, wp_json_encode($flags));
    }

    /**
     * FIX (Config Versioning wiring — closes the gap noted when Matching
     * was wired: "FeatureFlags::bulk_save()'s real setter uses PHP
     * checkbox-presence semantics ... does not match a stored version
     * payload of real booleans"): this setter takes an actual flag=>bool
     * map (the shape a config-version payload decodes to) and applies it
     * directly — no isset()-as-checkbox-proxy involved, so a version whose
     * payload has `'ai_scoring' => false` correctly disables it rather than
     * being indistinguishable from "key present, so treat as checked".
     * Core flags are always forced true regardless of what the payload
     * says, matching bulk_save()'s existing rule that core modules can't be
     * turned off through this surface.
     *
     * @param array<string,bool> $flags
     */
    public static function applyBooleanMap(array $flags): void
    {
        $current = self::load();
        $core    = ['lead_management', 'vendor_management', 'payment_collection', 'document_management'];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (in_array($key, $core, true)) {
                $current[$key] = true;
                continue;
            }
            if (array_key_exists($key, $flags)) {
                $current[$key] = (bool)$flags[$key];
            }
        }
        self::$flags = $current;
        update_option(self::OPTION_KEY, wp_json_encode($current));
    }

    /**
     * Raw flag=>bool map (including core flags), suitable as a
     * ConfigVersionService payload — unlike all(), which wraps each value
     * with label/description/core metadata for the admin UI.
     *
     * @return array<string,bool>
     */
    public static function raw(): array
    {
        $flags  = self::load();
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $result[$key] = (bool)($flags[$key] ?? $default);
        }
        return $result;
    }

    public static function all(): array
    {
        $flags  = self::load();
        $result = [];
        $core   = ['lead_management', 'vendor_management', 'payment_collection', 'document_management'];
        foreach (self::DEFAULTS as $key => $default) {
            [$label, $desc] = self::META[$key] ?? [$key, ''];
            $result[$key] = [
                'enabled'     => $flags[$key] ?? $default,
                'label'       => $label,
                'description' => $desc,
                'core'        => in_array($key, $core, true),
                'wired'       => !in_array($key, self::NOT_WIRED, true),
            ];
        }
        return $result;
    }
}
