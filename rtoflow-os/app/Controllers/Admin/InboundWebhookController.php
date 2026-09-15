<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;
use RTOFLOW\Services\InboundWebhookService;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 12, item — "No inbound webhook framework beyond
 * per-integration handlers"): admin screen for the new generic inbound
 * framework (see InboundWebhookService). Lets an admin register a new
 * inbound integration (slug + secret + signature scheme) without a code
 * change, and shows the recent inbound log across every provider —
 * Razorpay/WhatsApp included, since those handlers now also log through
 * this same service.
 */
class InboundWebhookController
{
    private const SIGNATURE_SCHEMES = ['hmac_sha256_hex', 'hmac_sha256_prefix'];

    public function index(): void
    {
        $svc       = new InboundWebhookService();
        $providers = $svc->listProviders();
        $log       = $svc->recentLog(100);
        rto_view('admin.inbound-webhooks.index', compact('providers', 'log'));
    }

    public function register(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $slug   = Sanitiser::slug($_POST['slug'] ?? '');
        $label  = Sanitiser::text($_POST['label'] ?? '', 150);
        $header = Sanitiser::text($_POST['signature_header'] ?? 'X-Signature', 80);
        $scheme = in_array($_POST['signature_scheme'] ?? '', self::SIGNATURE_SCHEMES, true)
            ? $_POST['signature_scheme'] : 'hmac_sha256_hex';

        if ($slug === '' || $label === '') rto_json_err('Slug and label are required.');

        $secret = bin2hex(random_bytes(32));

        $result = (new InboundWebhookService())->registerProvider($slug, $label, $secret, $header, $scheme, get_current_user_id() ?: 0);
        if (!$result['success']) rto_json_err($result['message']);

        AuditService::log('inbound_webhook_provider.created', null, ['slug' => $slug, 'label' => $label]);

        // Secret returned once, same "generate once, show once" posture as
        // the outbound webhook subscriptions and API keys screens.
        rto_json_ok(['slug' => $result['slug'], 'url' => $result['url'], 'secret' => $secret], 'Provider registered. Copy the secret now — it will not be shown again.');
    }

    public function toggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['provider_id'] ?? 0, 1);
        $active = ($_POST['active'] ?? '') === '1';

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_inbound_webhook_providers WHERE id=%d", $id));
        if (!$exists) rto_json_err('Provider not found.', 404);

        (new InboundWebhookService())->toggleProvider($id, $active);
        AuditService::log('inbound_webhook_provider.toggled', null, ['provider_id' => $id, 'active' => $active]);
        rto_json_ok(['is_active' => $active ? 1 : 0], $active ? 'Provider enabled.' : 'Provider disabled.');
    }
}
