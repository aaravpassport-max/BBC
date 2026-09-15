<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\ConfigVersionService;

if (!defined('ABSPATH')) exit;

/**
 * Config Version Controller
 *
 * Small, reusable AJAX surface over ConfigVersionService. Any admin config
 * screen (Eligibility Rules, Matching config, Feature Flags, City/Service
 * pricing, and future ones) can call these three actions against its own
 * config_key to get version history, publish, and rollback for free,
 * without writing its own history endpoints.
 */
class ConfigVersionController
{
    // ── AJAX: list version history for a config surface ────────────────────
    public function history(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $configKey = Sanitiser::text($_POST['config_key'] ?? $_GET['config_key'] ?? '');
        if (!$configKey) rto_json_err('config_key is required.');

        $history = ConfigVersionService::getHistory($configKey);
        $current = ConfigVersionService::getCurrentPublished($configKey);

        rto_json_ok(['history' => $history, 'current' => $current]);
    }

    // ── AJAX: publish a draft/archived version ──────────────────────────────
    public function publish(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $versionId = Sanitiser::int($_POST['version_id'] ?? 0, 1);
        if (!$versionId) rto_json_err('Invalid version_id.');

        $ok = ConfigVersionService::publish($versionId);
        if (!$ok) rto_json_err('Could not publish that version. It may not exist.', 404);

        rto_json_ok(['version_id' => $versionId], 'Version published.');
    }

    // ── AJAX: roll back a config surface to an older version ───────────────
    public function rollback(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $configKey   = Sanitiser::text($_POST['config_key'] ?? '');
        $toVersionId = Sanitiser::int($_POST['to_version_id'] ?? 0, 1);

        if (!$configKey)   rto_json_err('config_key is required.');
        if (!$toVersionId) rto_json_err('Invalid to_version_id.');

        $ok = ConfigVersionService::rollback($configKey, $toVersionId);
        if (!$ok) rto_json_err('Rollback failed. The target version may not exist for this config_key.', 404);

        rto_json_ok(['config_key' => $configKey, 'to_version_id' => $toVersionId], 'Rolled back to the selected version.');
    }
}
