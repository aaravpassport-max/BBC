<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval workflow):
 * see migration 2024_01_01_000032_create_config_change_requests for the
 * full problem statement. This is the maker-checker enforcement point —
 * the same "the same admin cannot do both steps" rule already used by
 * PayoutsController::markPaid() is applied here to config changes.
 */
class ConfigApprovalService
{
    public static function request(string $tab, array $payload, int $requestedBy): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rto_config_change_requests', [
            'tab'          => $tab,
            'payload_json' => wp_json_encode($payload),
            'status'       => 'pending',
            'requested_by' => $requestedBy,
            'requested_at' => current_time('mysql'),
        ]);
        $id = (int)$wpdb->insert_id;

        AuditService::log('config_change.requested', null, ['request_id' => $id, 'tab' => $tab]);
        return $id;
    }

    /** @return array{success:bool, message:string} */
    public static function approve(int $id, int $approvedBy): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rto_config_change_requests';
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);

        if (!$req) return ['success' => false, 'message' => 'Request not found.'];
        if ($req['status'] !== 'pending') return ['success' => false, 'message' => 'This request has already been decided.'];
        if ((int)$req['requested_by'] === $approvedBy) {
            $requester = get_userdata((int)$req['requested_by']);
            return ['success' => false, 'message' => 'You requested this change — a different admin (' . ($requester->display_name ?? 'another admin') . ' requested it) must approve it.'];
        }

        $payload = json_decode($req['payload_json'], true) ?: [];

        // Replay the payload through the real save logic. SettingsController::
        // save() is a private method that reads directly from $_POST — rather
        // than duplicating ~150 lines of tab-specific sanitisation/encryption
        // logic here (and risking the two copies drifting apart), the
        // approved payload is temporarily substituted for $_POST for the
        // duration of this one call, then restored. This guarantees the
        // change that actually gets applied is byte-identical to what the
        // requester submitted and what SettingsController would do for any
        // other tab — no second, parallel write path to keep in sync.
        $backupPost = $_POST;
        $_POST = $payload;
        try {
            $controller = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Controllers\Admin\SettingsController::class);
            $error = $controller->applyApprovedSave($req['tab']);
        } finally {
            $_POST = $backupPost;
        }

        if ($error !== null) {
            return ['success' => false, 'message' => 'Approved change could not be applied: ' . $error];
        }

        $wpdb->update($table, [
            'status'     => 'approved',
            'decided_by' => $approvedBy,
            'decided_at' => current_time('mysql'),
        ], ['id' => $id]);

        AuditService::log('config_change.approved', null, ['request_id' => $id, 'tab' => $req['tab']]);
        return ['success' => true, 'message' => 'Change approved and applied.'];
    }

    /** @return array{success:bool, message:string} */
    public static function reject(int $id, int $rejectedBy, string $note = ''): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rto_config_change_requests';
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);

        if (!$req) return ['success' => false, 'message' => 'Request not found.'];
        if ($req['status'] !== 'pending') return ['success' => false, 'message' => 'This request has already been decided.'];

        $wpdb->update($table, [
            'status'        => 'rejected',
            'decided_by'    => $rejectedBy,
            'decided_at'    => current_time('mysql'),
            'decision_note' => $note,
        ], ['id' => $id]);

        AuditService::log('config_change.rejected', null, ['request_id' => $id, 'tab' => $req['tab'], 'note' => $note]);
        return ['success' => true, 'message' => 'Change rejected — nothing was applied.'];
    }

    /** @return array pending requests, oldest first */
    public static function pending(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rto_config_change_requests WHERE status='pending' ORDER BY requested_at ASC",
            ARRAY_A
        ) ?: [];
    }

    /** @return array all requests, newest decided/pending first — history view */
    public static function history(int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_config_change_requests ORDER BY requested_at DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }
}
