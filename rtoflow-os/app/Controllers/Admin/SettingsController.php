<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Config\MatchingConfig;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class SettingsController
{
    // FIX (Known Limitations audit): the documented permission gap was real —
    // this controller previously performed no per-tab check at all beyond the
    // global rto_is_staff() gate every admin page already gets in Router. An
    // rto_staff account could view AND change live Razorpay/SMS/WhatsApp/AI
    // provider credentials and the company's GSTIN/TAN, identically to
    // rto_admin. These are the tabs that hold live secrets or legal/tax
    // identity fields; Notifications and Colors remain open to rto_staff as
    // the doc's own recommended_fix suggested.
    // ENTERPRISE GAP FIX (Phase 9, item — "data retention is reactive, not
    // policy-driven"): retention periods gate an automatic purge of real
    // personal data (see DataRetentionService), same class of setting as
    // the other ADMIN_ONLY_TABS entries.
    private const ADMIN_ONLY_TABS = ['company', 'payment', 'sms', 'whatsapp', 'ai_summary', 'privacy'];

    // ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval
    // workflow): the tabs that hold live payment credentials or the
    // provider tokens used to reach every client/vendor by SMS/WhatsApp —
    // exactly the ones this class's own ADMIN_ONLY_TABS comment already
    // flags as "the tabs that hold live secrets" — now require a SECOND,
    // different admin to approve before the change takes effect, instead
    // of applying the instant the form is submitted. See
    // ConfigApprovalService and the new Config Approvals admin screen.
    private const REQUIRES_APPROVAL_TABS = ['payment', 'sms', 'whatsapp'];

    public function index(): void
    {
        $saved = false;
        $submittedForApproval = false;
        $tab   = Sanitiser::text($_GET['tab'] ?? 'company');

        if (in_array($tab, self::ADMIN_ONLY_TABS, true) && !rto_is_admin()) {
            wp_die('Access denied. This settings tab is restricted to RTO Admin accounts.', 403);
        }

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('rtoflow_settings_save', 'rtoflow_nonce')) {
            if (in_array($tab, self::ADMIN_ONLY_TABS, true) && !rto_is_admin()) {
                wp_die('Access denied.', 403);
            }
            if (in_array($tab, self::REQUIRES_APPROVAL_TABS, true)) {
                \RTOFLOW\Services\ConfigApprovalService::request($tab, $_POST, get_current_user_id());
                $submittedForApproval = true;
            } else {
                $error = $this->save($tab);
                $saved = $error === null;
            }
        }

        $pendingForTab = in_array($tab, self::REQUIRES_APPROVAL_TABS, true)
            ? array_filter(\RTOFLOW\Services\ConfigApprovalService::pending(), fn($r) => $r['tab'] === $tab)
            : [];

        $settings = $this->getAll();
        // Matching tab's "Preview" control needs a real city/service picker
        // (see previewMatching()) — only queried when this tab is open, so
        // every other tab's page load stays as cheap as it was before.
        $previewCities = $previewServices = [];
        if ($tab === 'matching') {
            global $wpdb;
            $p = $wpdb->prefix;
            $previewCities   = $wpdb->get_results("SELECT id, name FROM {$p}rto_cities WHERE is_active = 1 ORDER BY name LIMIT 200", ARRAY_A) ?: [];
            $previewServices = $wpdb->get_results("SELECT id, name FROM {$p}rto_services WHERE is_active = 1 ORDER BY name LIMIT 200", ARRAY_A) ?: [];
        }
        rto_view('admin.settings.index', compact('tab','saved','submittedForApproval','pendingForTab','settings','error','previewCities','previewServices'));
    }

    /**
     * Public wrapper so ConfigApprovalService::approve() can replay an
     * approved payload through the exact same save logic every other tab
     * uses, without duplicating it. Not reachable directly from any route —
     * ConfigApprovalService is the only caller, and it independently
     * enforces the maker-checker rule before calling this.
     */
    public function applyApprovedSave(string $tab): ?string
    {
        return $this->save($tab);
    }

    // Returns null on success, or a human-readable error message when the
    // save was rejected and nothing was written (currently only possible
    // for the 'matching' tab — see MatchingConfig::save()).
    private function save(string $tab): ?string
    {
        // FIX P1 (Matching/Lead-Allocation configurability): the matching
        // tab persists through MatchingConfig::save() (its own validated,
        // clamped wp_options JSON blob — same storage pattern FeatureFlags
        // uses) rather than the flat update_option()-per-key loop below,
        // since its values need range-clamping as a set, not individually.
        //
        // FIX (Vendor Matching weight-sum validation): MatchingConfig::save()
        // now returns success/failure instead of always writing — a save
        // whose four weights don't sum to ~100 is rejected here, with the
        // actual sum surfaced back to the admin, instead of being silently
        // persisted into a config that would produce an unpredictable
        // vendor ranking (see VendorService::scoreVendor()).
        if ($tab === 'matching') {
            $result = MatchingConfig::save($_POST);
            if (!$result['success']) {
                return $result['message'];
            }
            AuditService::log('settings.updated', null, ['tab' => $tab]);
            delete_transient('rtofl_active_services');
            return null;
        }

        $opts = match($tab) {
            'company' => [
                'rtoflow_company_name'    => Sanitiser::text($_POST['company_name']    ?? ''),
                'rtoflow_company_gstin'   => strtoupper(Sanitiser::text($_POST['company_gstin'] ?? '')),
                'rtoflow_company_tan'     => strtoupper(Sanitiser::text($_POST['company_tan']   ?? '')),
                'rtoflow_company_address' => Sanitiser::text($_POST['company_address'] ?? '', 500),
                'rtoflow_company_state'   => Sanitiser::text($_POST['company_state']   ?? ''),
                'rtoflow_company_phone'   => Sanitiser::text($_POST['company_phone']   ?? ''),
                'rtoflow_company_email'   => Sanitiser::email($_POST['company_email']  ?? ''),
                'rtoflow_support_email'   => Sanitiser::email($_POST['company_email']  ?? ''), // alias
                'rtoflow_admin_user_id'   => Sanitiser::int($_POST['admin_user_id']    ?? 0),
                'rtoflow_sla_days'        => max(1, Sanitiser::int($_POST['sla_days']  ?? 7)),
                'rtoflow_company_logo_url'=> esc_url_raw($_POST['company_logo_url']    ?? ''),
                // BUGFIX (found via a systematic get_option()-vs-update_option()
                // read/write consistency sweep): Bootstrap::runInactivityCheck()
                // has read get_option('rtoflow_inactivity_days', 3) since it was
                // built, and its own comment explicitly says "configurable via
                // rtoflow_inactivity_days option" -- but no admin field ever
                // existed anywhere to actually set it, so it could only ever
                // silently use the hardcoded default of 3. Phantom
                // configurability, not a real setting. Now genuinely wired.
                'rtoflow_inactivity_days' => max(1, Sanitiser::int($_POST['inactivity_days'] ?? 3)),
                // Same failure mode: Router.php's front-page guard reads
                // get_option('rtoflow_override_front_page', '1') to let an
                // admin defer to WordPress's own Settings -> Reading static
                // front page instead of RTOFLOW's home page, but no checkbox
                // ever existed to turn it off.
                'rtoflow_override_front_page' => isset($_POST['override_front_page']) ? '1' : '0',
            ],
            // FIX P0-8/P0-9: secrets (Razorpay key secret, SMS API key, WhatsApp
            // token) are now written ONLY to their *_enc option, encrypted via
            // RTOFLOW\Security\Encryption — never to the plain option name. The
            // plain option keys are explicitly cleared below so a value saved
            // before this fix cannot linger in the database once re-saved.
            // Also fixes P0-9 (see integrations/Razorpay.php): this was already
            // the intended write path, but nothing on the read side consumed it
            // until Razorpay.php was updated to match.
            'payment' => [
                'rtoflow_razorpay_key_id'         => Sanitiser::text($_POST['razorpay_key_id'] ?? ''),
                'rtoflow_razorpay_key_secret_enc' => ($secret = Sanitiser::text($_POST['razorpay_key_secret'] ?? '')) !== ''
                    ? \RTOFLOW\Security\Encryption::encrypt($secret)
                    : get_option('rtoflow_razorpay_key_secret_enc', ''), // keep existing secret if field left blank on edit
                'rtoflow_razorpay_key_secret'     => '', // FIX P0-8: never store plaintext
                // FIX (10-workstream integration pass): the webhook secret
                // (distinct from the API key secret above — Razorpay signs
                // webhook payloads with its own separate secret) previously
                // had no admin-configurable field at all; PaymentService::
                // verifyRazorpayWebhook() could only ever read it from .env.
                'rtoflow_razorpay_webhook_secret_enc' => ($whSecret = Sanitiser::text($_POST['razorpay_webhook_secret'] ?? '')) !== ''
                    ? \RTOFLOW\Security\Encryption::encrypt($whSecret)
                    : get_option('rtoflow_razorpay_webhook_secret_enc', ''),
                'rtoflow_razorpay_mode'           => ($_POST['razorpay_mode'] ?? '') === 'live' ? 'live' : 'test',
                'rtoflow_currency'                => 'INR',
            ],
            'sms' => [
                'rtoflow_sms_provider'    => Sanitiser::text($_POST['sms_provider']   ?? ''),
                'rtoflow_sms_api_key_enc' => ($smsKey = Sanitiser::text($_POST['sms_api_key'] ?? '')) !== ''
                    ? \RTOFLOW\Security\Encryption::encrypt($smsKey)
                    : get_option('rtoflow_sms_api_key_enc', ''),
                'rtoflow_sms_api_key'     => '', // FIX P0-8: never store plaintext
                'rtoflow_sms_sender_id'   => Sanitiser::text($_POST['sms_sender_id']  ?? ''),
                'rtoflow_sms_dlt_entity'  => Sanitiser::text($_POST['sms_dlt_entity'] ?? ''),
                'rtoflow_sms_enabled'     => isset($_POST['sms_enabled']) ? '1' : '0',
            ],
            'whatsapp' => [
                'rtoflow_wa_provider'  => Sanitiser::text($_POST['wa_provider']    ?? ''),
                'rtoflow_wa_token_enc' => ($waToken = Sanitiser::text($_POST['wa_token'] ?? '')) !== ''
                    ? \RTOFLOW\Security\Encryption::encrypt($waToken)
                    : get_option('rtoflow_wa_token_enc', ''),
                'rtoflow_wa_token'     => '', // FIX P0-8: never store plaintext
                'rtoflow_wa_phone_id'  => Sanitiser::text($_POST['wa_phone_id']    ?? ''),
                'rtoflow_wa_waba_id'   => Sanitiser::text($_POST['wa_waba_id']     ?? ''),
                'rtoflow_wa_enabled'   => isset($_POST['wa_enabled']) ? '1' : '0',
                // ENTERPRISE GAP FIX (Phase 4, item 7 — no fallback provider for
                // SMS/WhatsApp): lets an operator turn off the new automatic
                // WhatsApp→SMS failover (RTOFLOW_WhatsApp::fallbackToSms()) if
                // they don't want a WhatsApp outage silently consuming SMS credits.
                'rtoflow_wa_sms_fallback' => isset($_POST['wa_sms_fallback']) ? '1' : '0',
            ],
            'notifications' => [
                'rtoflow_notif_lead_created'  => isset($_POST['notif_lead_created'])  ? '1' : '0',
                'rtoflow_notif_vendor_assign' => isset($_POST['notif_vendor_assign']) ? '1' : '0',
                'rtoflow_notif_status_change' => isset($_POST['notif_status_change']) ? '1' : '0',
                'rtoflow_notif_payment_done'  => isset($_POST['notif_payment_done'])  ? '1' : '0',
                'rtoflow_notif_sla_warning'   => isset($_POST['notif_sla_warning'])   ? '1' : '0',
                'rtoflow_admin_email'         => Sanitiser::email($_POST['admin_email'] ?? ''),
            ],
            // Part 5.9 (Lead Summary "AI-powered Briefing" — real integration
            // point, no key ever shipped in this codebase). Same encrypted-
            // secret pattern as sms/whatsapp/razorpay above: the plaintext
            // key is never round-tripped or stored in a plain option, only
            // its *_enc value; leaving the field blank on a re-save keeps
            // whatever key (if any) is already configured.
            'ai_summary' => [
                'rtoflow_ai_summary_enabled'      => isset($_POST['ai_summary_enabled']) ? '1' : '0',
                'rtoflow_ai_summary_provider'     => in_array($_POST['ai_summary_provider'] ?? '', ['anthropic','openai'], true)
                    ? $_POST['ai_summary_provider'] : 'anthropic',
                'rtoflow_ai_summary_api_key_enc'  => ($aiKey = Sanitiser::text($_POST['ai_summary_api_key'] ?? '')) !== ''
                    ? \RTOFLOW\Security\Encryption::encrypt($aiKey)
                    : get_option('rtoflow_ai_summary_api_key_enc', ''),
                'rtoflow_ai_summary_api_key'      => '', // never store plaintext, same as sms/wa/razorpay
            ],
            // FIX (Known Limitations audit): sanitizeHexColor() used to fall
            // back to a single hardcoded constant (Primary's own default) for
            // ANY invalid field, so mistyping e.g. Accent silently turned it
            // into the navy Primary color. Each field now falls back to its
            // OWN default.
            'colors' => [
                'rtoflow_color_primary'   => $this->sanitizeHexColor($_POST['color_primary']   ?? '#1B2A6B', '#1B2A6B'),
                'rtoflow_color_secondary' => $this->sanitizeHexColor($_POST['color_secondary'] ?? '#E97B28', '#E97B28'),
                'rtoflow_color_accent'    => $this->sanitizeHexColor($_POST['color_accent']    ?? '#16A34A', '#16A34A'),
                'rtoflow_color_bg_dark'   => $this->sanitizeHexColor($_POST['color_bg_dark']   ?? '#0A1628', '#0A1628'),
                'rtoflow_color_text'      => $this->sanitizeHexColor($_POST['color_text']      ?? '#0f172a', '#0f172a'),
            ],
            // ENTERPRISE GAP FIX (Phase 9, item — "data retention is
            // reactive, not policy-driven"): GdprService::eraseSubject()
            // already erases one subject on request; nothing previously
            // purged records automatically once they aged past a defined
            // retention period — someone had to manually invoke erasure
            // per subject, every time. These three periods (in days) now
            // drive DataRetentionService::runDaily(), a cron job that
            // anonymises/purges records past their own window the same
            // way GdprService already does for an on-demand request. 0
            // disables purging for that category (kept, not deleted, by
            // default — an admin must opt in to each one).
            'privacy' => [
                'rtoflow_retention_days_leads'  => max(0, Sanitiser::int($_POST['retention_days_leads']  ?? 0)),
                // Checkbox, not a day count — each draft already carries its
                // own per-row expiry (set at save time); this only decides
                // whether the already-built FormDraftService::cleanupExpired()
                // actually runs.
                'rtoflow_retention_days_drafts' => isset($_POST['retention_days_drafts']) ? '1' : '0',
                'rtoflow_retention_days_logs'   => max(0, Sanitiser::int($_POST['retention_days_logs']   ?? 0)),
            ],
            default => [],
        };

        foreach ($opts as $key => $value) {
            update_option($key, $value, false);
        }

        AuditService::log('settings.updated', null, ['tab' => $tab, 'keys' => array_keys($opts)]);

        // Clear service cache on any save
        delete_transient('rtofl_active_services');
        delete_transient('rtofl_active_states');
        delete_transient('rtoflow_dashboard_v2');

        return null;
    }

    private function sanitizeHexColor(string $color, string $fieldDefault = '#1B2A6B'): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) return $color;
        if (preg_match('/^#[0-9A-Fa-f]{3}$/', $color)) return $color;
        return $fieldDefault; // fall back to THIS field's own default, not a shared constant
    }

        private function getAll(): array
    {
        $keys = [
            // company
            'rtoflow_company_name', 'rtoflow_company_gstin', 'rtoflow_company_tan',
            'rtoflow_company_address', 'rtoflow_company_state', 'rtoflow_company_phone',
            'rtoflow_company_email', 'rtoflow_admin_user_id', 'rtoflow_sla_days',
            'rtoflow_company_logo_url', 'rtoflow_inactivity_days', 'rtoflow_override_front_page',
            // payment
            'rtoflow_razorpay_key_id', 'rtoflow_razorpay_key_secret', 'rtoflow_razorpay_mode',
            // sms
            'rtoflow_sms_provider', 'rtoflow_sms_api_key', 'rtoflow_sms_sender_id',
            'rtoflow_sms_dlt_entity', 'rtoflow_sms_enabled',
            // whatsapp
            'rtoflow_wa_provider', 'rtoflow_wa_token', 'rtoflow_wa_phone_id',
            'rtoflow_wa_waba_id', 'rtoflow_wa_enabled', 'rtoflow_wa_sms_fallback',
            // notifications
            'rtoflow_notif_lead_created', 'rtoflow_notif_vendor_assign',
            'rtoflow_notif_status_change', 'rtoflow_notif_payment_done',
            'rtoflow_notif_sla_warning', 'rtoflow_admin_email',
            // ai_summary
            'rtoflow_ai_summary_enabled', 'rtoflow_ai_summary_provider',
            // privacy — ENTERPRISE GAP FIX (Phase 9, item — "data retention
            // is reactive, not policy-driven")
            'rtoflow_retention_days_leads', 'rtoflow_retention_days_drafts', 'rtoflow_retention_days_logs',
        ];
        $result = [];
        foreach ($keys as $k) {
            $short = str_replace('rtoflow_', '', $k);
            // wa_sms_fallback defaults to enabled (matches RTOFLOW_WhatsApp::
            // fallbackToSms()'s own get_option(..., '1') default) so the
            // settings form doesn't show it as off before it's ever been saved.
            $default = $k === 'rtoflow_wa_sms_fallback' ? '1' : '';
            $result[$short] = get_option($k, $default);
        }
        // FIX P0-8: secret fields are never round-tripped back to the form in
        // plain text. The form shows whether a secret is already configured
        // (so an admin editing other fields doesn't need to re-enter it — the
        // save handler above keeps the existing *_enc value when the field is
        // left blank) without ever exposing the value itself in page HTML.
        $result['razorpay_key_secret_configured'] = get_option('rtoflow_razorpay_key_secret_enc', '') !== '';
        $result['sms_api_key_configured']         = get_option('rtoflow_sms_api_key_enc', '') !== '';
        $result['wa_token_configured']            = get_option('rtoflow_wa_token_enc', '') !== '';
        $result['razorpay_webhook_secret_configured'] = get_option('rtoflow_razorpay_webhook_secret_enc', '') !== '';
        $result['ai_summary_api_key_configured']  = get_option('rtoflow_ai_summary_api_key_enc', '') !== '';
        // FIX P1 (Matching/Lead-Allocation configurability): expose current
        // matching weights/thresholds to the settings view's new tab.
        $result['matching'] = MatchingConfig::all();
        return $result;
    }

    /**
     * AJAX: admin.settings.preview_matching — Settings → Matching's "Preview"
     * button. Re-ranks the real, currently-eligible vendor pool for a
     * city/service using CANDIDATE weights the admin typed but has not
     * saved, via VendorService::previewRanking() (which itself calls the
     * exact same scoreVendor() formula auto_assign() uses — one formula,
     * never duplicated). Writes nothing: no option saved, no lead touched.
     */
    public function previewMatching(): void
    {
        if (!rto_is_staff()) { rto_json_err('Access denied.', 403); return; }
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) { rto_json_err('Security check failed.', 403); return; }

        $cityId    = Sanitiser::int($_POST['city_id'] ?? 0, 0);
        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0, 0);
        if ($cityId <= 0 || $serviceId <= 0) { rto_json_err('Pick a city and service to preview against.', 422); return; }

        $weights = [
            'rating_weight'     => max(0, min(100, (float)($_POST['rating_weight'] ?? 0))),
            'completion_weight' => max(0, min(100, (float)($_POST['completion_weight'] ?? 0))),
            'acceptance_weight' => max(0, min(100, (float)($_POST['acceptance_weight'] ?? 0))),
            'load_weight'       => max(0, min(100, (float)($_POST['load_weight'] ?? 0))),
        ];

        $vendorService = new \RTOFLOW\Services\VendorService();
        $ranking = $vendorService->previewRanking($cityId, $serviceId, $weights);
        $impact  = $vendorService->previewImpactOnRecentAssignments($cityId, $serviceId, $weights, 50);

        rto_json_ok(['ranking' => $ranking, 'weights' => $weights, 'impact' => $impact]);
    }
}
