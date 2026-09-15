<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for outbound webhook subscriptions (see WebhookDispatchService
 * for the runtime dispatch half). Follows the exact same nonce/AJAX/
 * validation/audit-logging shape as AutomationController — the other
 * "configure, don't code" admin screen wired onto a table a runtime
 * service reads directly (WebhookDispatchService::dispatch() reads
 * rto_webhook_subscriptions the same way AutomationService::handle()
 * reads rto_automation_rules).
 *
 * The subscription secret is generated server-side and returned to the
 * caller ONLY on create — it is never re-read or re-displayed afterwards
 * (index() intentionally omits the secret column from what it hands the
 * view), matching this codebase's existing "generate once, show once"
 * posture for other credentials.
 */
class WebhookController
{
    private \wpdb $db;
    private string $p;

    // Same event taxonomy AutomationController::VALID_EVENTS already
    // enumerates — the real, exhaustive set of events actually fired via
    // do_action()/EventBus::fire() in this codebase. A webhook subscribed
    // to anything outside this set could never fire, so it is rejected at
    // write time rather than silently stored as dead configuration.
    private const VALID_EVENTS = [
        'lead.created', 'lead.status_changed', 'lead.completed',
        'lead.sla_breach', 'lead.sla_warning',
        'lead.vendor_assigned', 'lead.vendor_accepted', 'lead.vendor_rejected',
        'payment.received',
        'document.uploaded', 'document.verified', 'document.rejected',
        'complaint.created',
    ];

    private const SECRET_BYTES = 32;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        $subscriptions = $this->db->get_results(
            "SELECT id, event_type, target_url, is_active, created_at FROM {$this->p}rto_webhook_subscriptions ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];

        // ENTERPRISE GAP FIX (Section 7/8 — "no delivery-log admin UI"):
        // WebhookDispatchService has populated rto_webhook_delivery_log
        // since migration 20 (every attempt, success or failure) and now
        // also tracks retry/exhausted state (migration 25) — but nothing
        // ever rendered it. This is a minimal, real read of that existing
        // data: the 50 most recent attempts, so an admin can actually see
        // whether their webhooks are working without a database console.
        // dl.* already includes id, needed by the manual "Replay" action
        // (Phase 1, item 4) added to the view below.
        $recentDeliveries = $this->db->get_results(
            "SELECT dl.*, s.event_type as sub_event_type
             FROM {$this->p}rto_webhook_delivery_log dl
             LEFT JOIN {$this->p}rto_webhook_subscriptions s ON s.id = dl.subscription_id
             ORDER BY dl.attempted_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [];

        $validEvents = self::VALID_EVENTS;
        rto_view('admin.webhooks.index', compact('subscriptions', 'validEvents', 'recentDeliveries'));
    }

    // ── AJAX: create subscription ────────────────────────────────────────────
    public function create(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $eventType = Sanitiser::text($_POST['event_type'] ?? '');
        $targetUrl = Sanitiser::url($_POST['target_url'] ?? '');

        $error = $this->validate($eventType, $targetUrl);
        if ($error) rto_json_err($error);

        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $inserted = $this->db->insert($this->p . 'rto_webhook_subscriptions', [
            'event_type' => $eventType,
            'target_url' => $targetUrl,
            'secret'     => $secret,
            'is_active'  => 1,
            'created_at' => current_time('mysql'),
        ]);
        if (!$inserted) rto_json_err('Could not save the subscription. Please try again.');

        $id = (int)$this->db->insert_id;
        AuditService::log('webhook_subscription.created', null, [
            'subscription_id' => $id, 'event_type' => $eventType, 'target_url' => $targetUrl,
        ]);

        $this->snapshotVersion('Subscription created: ' . $eventType . ' → ' . $targetUrl);

        // The secret is returned ONLY here, on creation — never again.
        rto_json_ok(['subscription_id' => $id, 'secret' => $secret], 'Webhook subscription created.');
    }

    // ── AJAX: update subscription ────────────────────────────────────────────
    public function update(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['subscription_id'] ?? 0, 1);
        $before = $this->db->get_row($this->db->prepare(
            "SELECT id, event_type, target_url FROM {$this->p}rto_webhook_subscriptions WHERE id=%d", $id
        ), ARRAY_A);
        if (!$before) rto_json_err('Subscription not found.', 404);

        $eventType = Sanitiser::text($_POST['event_type'] ?? '');
        $targetUrl = Sanitiser::url($_POST['target_url'] ?? '');

        $error = $this->validate($eventType, $targetUrl);
        if ($error) rto_json_err($error);

        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): the update() result was discarded here
        // — an admin repointing a webhook to a new target_url would be told
        // "Webhook subscription updated." even if the write failed and
        // events kept firing at the OLD (possibly decommissioned) URL.
        $updated = $this->db->update($this->p . 'rto_webhook_subscriptions', [
            'event_type' => $eventType,
            'target_url' => $targetUrl,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        if ($updated === false) rto_json_err('Could not update the webhook subscription. Please try again.', 500);

        AuditService::log('webhook_subscription.updated', null, [
            'subscription_id' => $id, 'event_type' => $eventType, 'target_url' => $targetUrl,
        ], $before);
        $this->snapshotVersion('Subscription #' . $id . ' updated');
        rto_json_ok(['subscription_id' => $id], 'Webhook subscription updated.');
    }

    // ── AJAX: delete subscription ─────────────────────────────────────────────
    public function delete(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['subscription_id'] ?? 0, 1);
        $deleted = $this->db->delete($this->p . 'rto_webhook_subscriptions', ['id' => $id]);
        if (!$deleted) rto_json_err('Subscription not found or already removed.', 404);

        AuditService::log('webhook_subscription.deleted', null, ['subscription_id' => $id]);
        $this->snapshotVersion('Subscription #' . $id . ' deleted');
        rto_json_ok(null, 'Webhook subscription removed.');
    }

    // ── AJAX: rotate signing secret in place ─────────────────────────────────
    // Known Limitations audit fix: "A lost signing secret cannot be recovered
    // or rotated in place — the only way to get a new one is to delete the
    // subscription and create a fresh one." Regenerates the secret for the
    // SAME row (same id/event_type/target_url), avoiding the delete/recreate
    // churn and the brief delivery gap that caused. Follows the exact same
    // "generate once, show once" posture as create(): the new secret is
    // returned in this one response only and never re-readable afterwards.
    public function rotateSecret(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['subscription_id'] ?? 0, 1);
        $sub = $this->db->get_row($this->db->prepare(
            "SELECT id, event_type, target_url FROM {$this->p}rto_webhook_subscriptions WHERE id=%d", $id
        ), ARRAY_A);
        if (!$sub) rto_json_err('Subscription not found.', 404);

        $newSecret = bin2hex(random_bytes(self::SECRET_BYTES));
        $updated = $this->db->update($this->p . 'rto_webhook_subscriptions', [
            'secret'     => $newSecret,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        if ($updated === false) rto_json_err('Could not rotate the secret. Please try again.', 500);

        AuditService::log('webhook_subscription.secret_rotated', null, [
            'subscription_id' => $id, 'event_type' => $sub['event_type'], 'target_url' => $sub['target_url'],
        ]);
        // The new secret is returned ONLY here, once — same posture as create().
        rto_json_ok(['subscription_id' => $id, 'secret' => $newSecret], 'Secret rotated. Copy it now — it will not be shown again.');
    }

    // ── AJAX: toggle active/inactive ─────────────────────────────────────────
    public function toggleActive(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['subscription_id'] ?? 0, 1);
        $sub = $this->db->get_row($this->db->prepare(
            "SELECT id, is_active FROM {$this->p}rto_webhook_subscriptions WHERE id=%d", $id
        ), ARRAY_A);
        if (!$sub) rto_json_err('Subscription not found.', 404);

        $newState = $sub['is_active'] ? 0 : 1;
        $updated = $this->db->update($this->p . 'rto_webhook_subscriptions', ['is_active' => $newState], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the subscription. Please try again.', 500);
        AuditService::log('webhook_subscription.toggled', null, ['subscription_id' => $id, 'is_active' => $newState]);
        $this->snapshotVersion('Subscription #' . $id . ' ' . ($newState ? 'enabled' : 'disabled'));
        rto_json_ok(['is_active' => $newState], $newState ? 'Subscription enabled.' : 'Subscription disabled.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 8 — "no versioning/rollback for
    // ... Webhooks ..."): snapshots event_type/target_url/is_active only —
    // deliberately EXCLUDES `secret` from both the snapshot and restore.
    // rotateSecret() is a deliberate security action (e.g. after a suspected
    // leak); a version rollback silently reinstating an old, possibly
    // compromised secret would defeat that, so secret rotation is not
    // itself a versioned/rollback-able event and restoring an older version
    // never touches the current secret.
    private function snapshotVersion(string $note): void
    {
        $rows = $this->db->get_results(
            "SELECT id, event_type, target_url, is_active FROM {$this->p}rto_webhook_subscriptions ORDER BY id", ARRAY_A
        ) ?: [];
        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft('webhook_subscriptions', $rows, get_current_user_id() ?: 0, $note);
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }
    }

    // ── Rollback target: called from Bootstrap.php's
    // 'rtoflow_config_published' listener when config_key ===
    // 'webhook_subscriptions'. Restores event_type/target_url/is_active for
    // rows that still exist and re-creates deleted ones with a FRESH secret
    // (never a stored one — none was ever stored in the snapshot); never
    // deletes a subscription created after the snapshot, since an
    // accidental full-restore silently disabling a brand-new integration
    // is a worse failure mode than leaving one "extra" subscription for an
    // admin to manually remove if genuinely unwanted.
    public function applyVersionedPayload(array $payload): void
    {
        $existingIds = array_map('intval', $this->db->get_col("SELECT id FROM {$this->p}rto_webhook_subscriptions"));
        foreach ($payload as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            $data = [
                'event_type' => $row['event_type'] ?? '',
                'target_url' => $row['target_url'] ?? '',
                'is_active'  => isset($row['is_active']) ? (int)$row['is_active'] : 1,
            ];
            if ($id > 0 && in_array($id, $existingIds, true)) {
                $this->db->update($this->p . 'rto_webhook_subscriptions', $data, ['id' => $id]);
            } else {
                $data['secret']     = bin2hex(random_bytes(self::SECRET_BYTES));
                $data['created_at'] = current_time('mysql');
                $this->db->insert($this->p . 'rto_webhook_subscriptions', $data);
            }
        }
        AuditService::log('webhook_subscriptions.version_restored', null, ['restored_count' => count($payload)]);
    }

    // ── AJAX: manually replay one logged delivery attempt ───────────────────
    // ENTERPRISE GAP FIX (Phase 1, item 4 — "webhooks ... delivery-log
    // admin screen with a manual 'replay' action"): see
    // WebhookDispatchService::replayLog() for the actual re-send logic.
    public function replayDelivery(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $logId = Sanitiser::int($_POST['log_id'] ?? 0, 1);
        if ($logId < 1) rto_json_err('Invalid delivery log entry.');

        $result = (new \RTOFLOW\Services\WebhookDispatchService())->replayLog($logId);

        AuditService::log($result['ok'] ? 'webhook.delivery_replayed' : 'webhook.delivery_replay_failed', null, [
            'log_id' => $logId, 'result' => $result['message'],
        ]);

        if (!$result['ok']) rto_json_err($result['message']);
        rto_json_ok(null, $result['message']);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /** Returns an error message string, or '' when valid. */
    private function validate(string $eventType, string $targetUrl): string
    {
        if (!in_array($eventType, self::VALID_EVENTS, true)) return 'Unknown event type.';
        if (!$targetUrl || !filter_var($targetUrl, FILTER_VALIDATE_URL)) return 'A valid target URL is required.';
        if (stripos($targetUrl, 'https://') !== 0) return 'Target URL must use HTTPS.';
        return '';
    }
}
