<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 4 — partner REST API): key management
 * for RTOFLOW\Http\ApiController's endpoints. See migration
 * 2024_01_01_000033_create_api_keys for the storage model.
 */
class ApiKeyService
{
    private const KEY_BYTES = 24;

    /** @return array{id:int, raw_key:string} raw_key is shown ONLY here, once */
    public static function generate(string $partnerName, int $createdBy): array
    {
        global $wpdb;
        $raw    = 'rtofl_' . bin2hex(random_bytes(self::KEY_BYTES));
        $prefix = substr($raw, 0, 12);
        $hash   = hash('sha256', $raw);

        $wpdb->insert($wpdb->prefix . 'rto_api_keys', [
            'partner_name' => $partnerName,
            'key_prefix'   => $prefix,
            'key_hash'     => $hash,
            'is_active'    => 1,
            'created_by'   => $createdBy,
            'created_at'   => current_time('mysql'),
        ]);
        $id = (int)$wpdb->insert_id;

        AuditService::log('api_key.created', null, ['api_key_id' => $id, 'partner_name' => $partnerName]);
        return ['id' => $id, 'raw_key' => $raw];
    }

    /** Verify a raw key from a request header. @return array|null the active key row, or null if invalid/revoked */
    public static function verify(string $rawKey): ?array
    {
        if ($rawKey === '') return null;
        global $wpdb;
        $hash = hash('sha256', $rawKey);
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_api_keys WHERE key_hash = %s AND is_active = 1",
            $hash
        ), ARRAY_A);

        if ($row) {
            $wpdb->update($wpdb->prefix . 'rto_api_keys', ['last_used_at' => current_time('mysql')], ['id' => (int)$row['id']]);
        }
        return $row ?: null;
    }

    public static function revoke(int $id): bool
    {
        global $wpdb;
        $ok = $wpdb->update($wpdb->prefix . 'rto_api_keys', [
            'is_active'  => 0,
            'revoked_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;

        if ($ok) AuditService::log('api_key.revoked', null, ['api_key_id' => $id]);
        return $ok;
    }

    /** @return array all keys, newest first — key_hash never returned */
    public static function list(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, partner_name, key_prefix, is_active, created_by, created_at, last_used_at, revoked_at
             FROM {$wpdb->prefix}rto_api_keys ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
        return $rows;
    }
}
