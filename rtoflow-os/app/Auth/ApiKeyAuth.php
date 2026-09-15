<?php

namespace RTOFLOW\Auth;

use RTOFLOW\Security\Encryption;

if (!defined('ABSPATH')) exit;

/**
 * API Key Authentication
 *
 * Manages scoped, revocable API keys for external integrations.
 * Keys are stored as a SHA-256 hash — the plaintext is shown once on creation.
 *
 * Key format: rtoflow_live_<64 random hex chars>
 * Key scopes: read, write, webhook, admin
 *
 * Usage:
 *   [$key, $record] = ApiKeyAuth::create($userId, ['read', 'write'], 'Mobile App');
 *   $user = ApiKeyAuth::authenticate($keyFromHeader);
 *   ApiKeyAuth::revoke($keyId);
 */
final class ApiKeyAuth
{
    private const TABLE    = 'rto_api_keys';
    private const PREFIX   = 'rtoflow_';
    private const SCOPES   = ['read', 'write', 'webhook', 'admin', 'vendor', 'client'];

    // ── Create ────────────────────────────────────────────────────────────

    /**
     * Create a new API key.
     * Returns [plaintext_key, record_array] — plaintext is shown ONCE and never stored.
     */
    public static function create(int $userId, array $scopes = ['read'], string $name = 'API Key'): array
    {
        $scopes     = array_intersect($scopes, self::SCOPES);
        $env        = defined('RTOFLOW_TEST') ? 'test' : 'live';
        $raw        = bin2hex(random_bytes(32)); // 64 hex chars
        $plaintext  = self::PREFIX . $env . '_' . $raw;
        $hash       = hash('sha256', $plaintext);

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'user_id'    => $userId,
                'name'       => sanitize_text_field($name),
                'key_hash'   => $hash,
                'key_prefix' => self::PREFIX . $env . '_' . substr($raw, 0, 8),
                'scopes'     => wp_json_encode($scopes),
                'status'     => 'active',
                'last_used'  => null,
                'created_at' => current_time('mysql'),
            ]
        );

        $id = $wpdb->insert_id;

        return [
            $plaintext,
            [
                'id'         => $id,
                'user_id'    => $userId,
                'name'       => $name,
                'key_prefix' => self::PREFIX . $env . '_' . substr($raw, 0, 8),
                'scopes'     => $scopes,
                'status'     => 'active',
            ],
        ];
    }

    // ── Authenticate ──────────────────────────────────────────────────────

    /**
     * Authenticate a request using an API key from Authorization header.
     * Returns ['user_id' => int, 'scopes' => array] or null if invalid.
     */
    public static function authenticate(?string $key): ?array
    {
        if (!$key) return null;
        $key = self::extractFromBearer($key);
        if (!str_starts_with($key, self::PREFIX)) return null;

        $hash = hash('sha256', $key);

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE key_hash = %s AND status = 'active'",
                $hash
            ),
            ARRAY_A
        );

        if (!$record) return null;

        // Update last used timestamp (throttled — not every request)
        $lastUsed = strtotime($record['last_used'] ?? '2000-01-01');
        if ((time() - $lastUsed) > 300) { // Update at most every 5 minutes
            $wpdb->update(
                $wpdb->prefix . self::TABLE,
                ['last_used' => current_time('mysql'), 'last_ip' => \RTOFLOW\Http\Middleware\RateLimiter::clientIp()],
                ['id' => $record['id']]
            );
        }

        return [
            'user_id'    => (int)$record['user_id'],
            'key_id'     => (int)$record['id'],
            'scopes'     => json_decode($record['scopes'] ?? '[]', true),
            'name'       => $record['name'],
        ];
    }

    // ── Authorise ─────────────────────────────────────────────────────────

    /** Check if authenticated key has a required scope */
    public static function hasScope(array $authResult, string $scope): bool
    {
        return in_array($scope, $authResult['scopes'] ?? [], true)
            || in_array('admin', $authResult['scopes'] ?? [], true);
    }

    // ── Revoke ────────────────────────────────────────────────────────────

    public static function revoke(int $keyId, int $userId = 0): bool
    {
        global $wpdb;
        $where = ['id' => $keyId, 'status' => 'active'];
        if ($userId) $where['user_id'] = $userId;

        return (bool)$wpdb->update(
            $wpdb->prefix . self::TABLE,
            ['status' => 'revoked', 'revoked_at' => current_time('mysql')],
            $where
        );
    }

    /** Revoke all keys for a user */
    public static function revokeAll(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->update(
            $wpdb->prefix . self::TABLE,
            ['status' => 'revoked', 'revoked_at' => current_time('mysql')],
            ['user_id' => $userId, 'status' => 'active']
        );
    }

    // ── List ──────────────────────────────────────────────────────────────

    public static function listForUser(int $userId): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, key_prefix, scopes, status, last_used, last_ip, created_at
                 FROM {$wpdb->prefix}" . self::TABLE . "
                 WHERE user_id = %d
                 ORDER BY created_at DESC",
                $userId
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Schema ────────────────────────────────────────────────────────────

    public static function createTable(): void
    {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id    BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(200) NOT NULL,
            key_hash   CHAR(64) NOT NULL,
            key_prefix VARCHAR(50) NOT NULL,
            scopes     JSON NOT NULL,
            status     VARCHAR(20) NOT NULL DEFAULT 'active',
            last_used  DATETIME NULL,
            last_ip    VARCHAR(50) NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY key_hash (key_hash),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) {$c}";

        dbDelta($sql);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function extractFromBearer(string $header): string
    {
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return trim($header);
    }

    public static function fromRequest(): ?string
    {
        // Check Authorization: Bearer header
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($auth) return $auth;

        // Check X-API-Key header
        $xKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($xKey) return $xKey;

        return null;
    }
}
