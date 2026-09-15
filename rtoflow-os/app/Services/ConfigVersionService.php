<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Config Version Service — Generic Draft/Publish/Rollback for Config Surfaces
 *
 * Backs the enterprise-grade "Customizer" workflow (validation, preview,
 * draft/publish, version history, rollback, audit) shared by every admin
 * configuration screen: Eligibility Rules, Matching config, Feature Flags,
 * City/Service pricing, and any future one. Each surface is identified by
 * an arbitrary `config_key` string; this service knows nothing about the
 * shape of any particular payload — it just stores, versions, and audits
 * whatever array a caller hands it as JSON.
 *
 * Rules enforced here:
 *   - At most one 'published' version per config_key at any time. Publishing
 *     a version archives whichever version was previously published.
 *   - Version numbers are sequential per config_key, starting at 1, and are
 *     never reused — history is append-only.
 *   - Rollback never deletes or rewrites history. It clones the target
 *     version's payload into a brand-new version and publishes that clone,
 *     so "what was live and when" remains fully reconstructable from the
 *     version table alone.
 *   - Every mutation is mirrored into AuditService::log() so the generic
 *     admin audit trail also captures config-versioning activity.
 *
 * Usage:
 *   $id = ConfigVersionService::saveDraft('matching_config', $payload, get_current_user_id());
 *   ConfigVersionService::publish($id);
 *   ConfigVersionService::rollback('matching_config', $olderVersionId);
 *   ConfigVersionService::getHistory('matching_config');
 *   ConfigVersionService::getCurrentPublished('matching_config');
 */
class ConfigVersionService
{
    private const TABLE = 'rto_config_versions';

    // ── Write ────────────────────────────────────────────────────────────

    /**
     * Insert a new draft version for a config surface.
     *
     * @param string $configKey Which config surface, e.g. 'matching_config'.
     * @param array  $payload   Full config payload snapshot (JSON-encoded as-is).
     * @param int    $userId    Admin user creating this draft.
     * @param string $notes     Optional free-text note describing the change.
     * @return int              The new version's row id, or 0 on failure.
     */
    public static function saveDraft(string $configKey, array $payload, int $userId, string $notes = ''): int
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $nextVersion = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$p}" . self::TABLE . " WHERE config_key = %s",
            $configKey
        ));

        $inserted = $wpdb->insert($p . self::TABLE, [
            'config_key'     => $configKey,
            'version_number' => $nextVersion,
            'payload_json'   => wp_json_encode($payload),
            'status'         => 'draft',
            'created_by'     => $userId ?: null,
            'created_at'     => current_time('mysql'),
            'published_at'   => null,
            'notes'          => $notes !== '' ? substr($notes, 0, 500) : null,
        ]);

        if (!$inserted) {
            return 0;
        }

        $versionId = (int)$wpdb->insert_id;

        AuditService::log('config.draft_saved', null, [
            'config_key'     => $configKey,
            'version_id'     => $versionId,
            'version_number' => $nextVersion,
            'notes'          => $notes,
        ], [], $userId ?: null);

        return $versionId;
    }

    /**
     * Publish a version: mark it 'published' and archive whatever version
     * for the same config_key was previously published. Never leaves more
     * than one published version per config_key.
     */
    // FIX (Config Versioning wiring): publish() previously only ever wrote
    // to the version-history table — nothing actually applied the payload
    // back to the live config store a feature reads at runtime, so
    // "publish"/"rollback" from the admin UI recorded history without
    // changing behavior. $fireApply controls whether the generic
    // 'rtoflow_config_published' action fires (see Bootstrap.php for the
    // per-config_key appliers registered on it). Defaults to true — the
    // AJAX-driven publish/rollback paths (ConfigVersionController) want the
    // live apply. A config surface's own save handler (e.g.
    // MatchingConfig::save()) passes false when it records its own version
    // AFTER already applying the value directly, to avoid a
    // save→publish→apply→save loop.
    public static function publish(int $versionId, bool $fireApply = true): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $version = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}" . self::TABLE . " WHERE id = %d",
            $versionId
        ), ARRAY_A);

        if (!$version) {
            return false;
        }

        $configKey = $version['config_key'];

        // Archive whatever was previously published for this config_key.
        // Common-mistakes audit fix: this write's result was discarded.
        // getCurrentPublished() below orders by version_number DESC LIMIT 1,
        // so a silent failure here wouldn't misroute reads today — but it
        // would still leave a stale 'published' row lying around, which is
        // real incorrect state regardless of whether today's one reader
        // happens to be resilient to it.
        $archived = $wpdb->query($wpdb->prepare(
            "UPDATE {$p}" . self::TABLE . "
             SET status = 'archived'
             WHERE config_key = %s AND status = 'published' AND id != %d",
            $configKey, $versionId
        ));
        if ($archived === false) {
            error_log('[RTOFLOW] ConfigVersionService::publish: failed to archive previous published version for ' . $configKey . ': ' . $wpdb->last_error);
            return false;
        }

        $updated = $wpdb->update(
            $p . self::TABLE,
            ['status' => 'published', 'published_at' => current_time('mysql')],
            ['id' => $versionId]
        );

        if ($updated === false) {
            return false;
        }

        AuditService::log('config.published', null, [
            'config_key'     => $configKey,
            'version_id'     => $versionId,
            'version_number' => (int)$version['version_number'],
        ]);

        if ($fireApply) {
            $payload = json_decode($version['payload_json'], true);
            if (is_array($payload)) {
                do_action('rtoflow_config_published', $configKey, $payload);
            }
        }

        return true;
    }

    /**
     * Roll back a config surface to an older version. This never mutates or
     * deletes history: it clones the target version's payload into a new
     * draft version, then publishes that new version, so the rollback itself
     * is a fully auditable, first-class entry in the version history.
     */
    public static function rollback(string $configKey, int $toVersionId): bool
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $target = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}" . self::TABLE . " WHERE id = %d AND config_key = %s",
            $toVersionId, $configKey
        ), ARRAY_A);

        if (!$target) {
            return false;
        }

        $payload = json_decode($target['payload_json'], true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $userId = get_current_user_id();
        $notes  = 'Rollback to version #' . (int)$target['version_number'];

        $newVersionId = self::saveDraft($configKey, $payload, $userId, $notes);
        if (!$newVersionId) {
            return false;
        }

        $published = self::publish($newVersionId);
        if (!$published) {
            return false;
        }

        AuditService::log('config.rolled_back', null, [
            'config_key'        => $configKey,
            'rolled_back_to'    => $toVersionId,
            'rolled_back_to_no' => (int)$target['version_number'],
            'new_version_id'    => $newVersionId,
        ], [], $userId ?: null);

        return true;
    }

    // ── Read ─────────────────────────────────────────────────────────────

    /**
     * All versions for a config surface, newest first.
     */
    public static function getHistory(string $configKey): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, u.display_name AS created_by_name
             FROM {$p}" . self::TABLE . " v
             LEFT JOIN {$p}users u ON u.ID = v.created_by
             WHERE v.config_key = %s
             ORDER BY v.version_number DESC",
            $configKey
        ), ARRAY_A) ?: [];
    }

    /**
     * The currently published version's row for a config surface, or null
     * if nothing has ever been published.
     */
    public static function getCurrentPublished(string $configKey): ?array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}" . self::TABLE . "
             WHERE config_key = %s AND status = 'published'
             ORDER BY version_number DESC LIMIT 1",
            $configKey
        ), ARRAY_A);

        return $row ?: null;
    }
}
