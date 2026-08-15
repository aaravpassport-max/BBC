<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * BOS_Auth — JWT authentication, direct port of BOS_Auth without WordPress.
 * JWT secret stored in bos_settings table on first boot.
 */
class BOS_Auth {

    const ACCESS_TTL  = 8  * 3600;    // 8 hours
    const REFRESH_TTL = 30 * 86400;   // 30 days
    const ALGORITHM   = 'HS256';

    private static ?string $secret = null;

    public static function secret(): string {
        if (self::$secret) return self::$secret;
        $stored = BOS_DB::get_setting('jwt_secret');
        if (!$stored || strlen($stored) < 32) {
            $stored = bin2hex(random_bytes(32));
            BOS_DB::set_setting('jwt_secret', $stored);
        }
        self::$secret = $stored;
        return $stored;
    }

    // ── Issue access + refresh tokens ─────────────────────────────────────────
    public static function issue_tokens(object $user, string $ip = ''): array {
        $now = time();
        $uid = (int) $user->id;

        $access_payload = [
            'iss'  => 'businessos-desktop',
            'iat'  => $now,
            'exp'  => $now + self::ACCESS_TTL,
            'sub'  => $user->uuid,
            'uid'  => $uid,
            'role' => $user->role,
            'name' => $user->name,
            'type' => 'access',
        ];

        $refresh_payload = [
            'iss'  => 'businessos-desktop',
            'iat'  => $now,
            'exp'  => $now + self::REFRESH_TTL,
            'sub'  => $user->uuid,
            'uid'  => $uid,
            'type' => 'refresh',
        ];

        $secret = self::secret();
        $access_token  = JWT::encode($access_payload,  $secret, self::ALGORITHM);
        $refresh_token = JWT::encode($refresh_payload, $secret, self::ALGORITHM);

        // Store session
        BOS_DB::insert(BOS_DB::t('user_sessions'), [
            'user_id'      => $user->id,
            'token_hash'   => hash('sha256', $access_token),
            'refresh_hash' => hash('sha256', $refresh_token),
            'ip_address'   => $ip ?: BOS_Helpers::client_ip(),
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'expires_at'   => gmdate('Y-m-d H:i:s', $now + self::REFRESH_TTL),
        ]);

        return [
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_in'    => self::ACCESS_TTL,
            'token_type'    => 'Bearer',
        ];
    }

    // ── Decode and validate access token ──────────────────────────────────────
    public static function decode_token(string $token): ?array {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), self::ALGORITHM));
            $payload = (array) $decoded;
            if (($payload['type'] ?? '') !== 'access') return null;
            if (self::is_blacklisted($token)) return null;
            return $payload;
        } catch (Throwable $e) {
            error_log('[BOS] decode_token: ' . $e->getMessage());
            return null;
        }
    }

    // ── Get current authenticated user from request ────────────────────────────
    public static function current_user(): ?object {
        $token = BOS_Helpers::get_bearer();
        if (!$token) return null;

        $payload = self::decode_token($token);
        if (!$payload) return null;

        return BOS_DB::get_row(
            'SELECT * FROM `' . BOS_DB::t('users') . '` WHERE id=? AND uuid=? AND status=? AND deleted_at IS NULL',
            [(int)$payload['uid'], $payload['sub'], 'Active']
        );
    }

    // ── Require authenticated user or send 401 ────────────────────────────────
    public static function require_auth(): object {
        $user = self::current_user();
        if (!$user) {
            BOS_Helpers::error('UNAUTHORIZED', 'Authentication required.', 401);
        }
        return $user;
    }

    // ── Require admin role ────────────────────────────────────────────────────
    public static function require_admin(): object {
        $user = self::require_auth();
        if ($user->role !== 'Admin') {
            BOS_Helpers::error('FORBIDDEN', 'Admin access required.', 403);
        }
        return $user;
    }

    // ── Refresh token flow ────────────────────────────────────────────────────
    public static function refresh(string $refresh_token): ?array {
        try {
            $decoded = JWT::decode($refresh_token, new Key(self::secret(), self::ALGORITHM));
            $payload = (array) $decoded;

            if (($payload['type'] ?? '') !== 'refresh') return null;

            $hash    = hash('sha256', $refresh_token);
            $session = BOS_DB::get_row(
                'SELECT * FROM `' . BOS_DB::t('user_sessions') . '` WHERE refresh_hash=? AND expires_at > NOW()',
                [$hash]
            );
            if (!$session) return null;

            $user = BOS_DB::get_row(
                'SELECT * FROM `' . BOS_DB::t('users') . '` WHERE id=? AND status=? AND deleted_at IS NULL',
                [(int)$payload['uid'], 'Active']
            );
            if (!$user) return null;

            // Revoke old session
            BOS_DB::delete(BOS_DB::t('user_sessions'), ['id' => $session->id]);

            return self::issue_tokens($user);
        } catch (Throwable $e) {
            error_log('[BOS] refresh: ' . $e->getMessage());
            return null;
        }
    }

    // ── Blacklist a token ─────────────────────────────────────────────────────
    public static function blacklist_token(string $token): void {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), self::ALGORITHM));
            $exp = $decoded->exp ?? (time() + 3600);
            BOS_DB::insert(BOS_DB::t('token_blacklist'), [
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', $exp),
            ]);
        } catch (Throwable $e) {
            error_log('[BOS] blacklist_token: ' . $e->getMessage());
        }
    }

    public static function is_blacklisted(string $token): bool {
        $hash = hash('sha256', $token);
        return (bool) BOS_DB::get_var(
            'SELECT id FROM `' . BOS_DB::t('token_blacklist') . '` WHERE token_hash=? AND expires_at > NOW()',
            [$hash]
        );
    }

    // ── Revoke all sessions for a user ────────────────────────────────────────
    public static function revoke_all_sessions(int $user_id): void {
        BOS_DB::query(
            'DELETE FROM `' . BOS_DB::t('user_sessions') . '` WHERE user_id=?',
            [$user_id]
        );
    }

    // ── Cleanup expired tokens (called on startup) ────────────────────────────
    public static function cleanup_expired(): void {
        BOS_DB::query('DELETE FROM `' . BOS_DB::t('token_blacklist') . '` WHERE expires_at < NOW()');
        BOS_DB::query('DELETE FROM `' . BOS_DB::t('user_sessions') . '` WHERE expires_at < NOW()');
    }

    // ── One-time password reset token (replaces WP admin reset flow) ──────────
    public static function generate_reset_token(int $user_id): string {
        $raw   = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $raw);
        $exp   = BOS_Helpers::now(); // will be replaced below

        BOS_DB::set_setting('pwd_reset_token', json_encode([
            'hash'    => $hash,
            'expires' => time() + 900,
            'user_id' => $user_id,
        ]));
        return $raw;
    }

    public static function consume_reset_token(string $raw_token, string $new_password): bool {
        $stored_json = BOS_DB::get_setting('pwd_reset_token');
        if (!$stored_json) return false;

        $stored = json_decode($stored_json, true);
        if (!$stored || time() > (int)$stored['expires']) {
            BOS_DB::set_setting('pwd_reset_token', '');
            return false;
        }
        if (!hash_equals($stored['hash'], hash('sha256', $raw_token))) return false;

        // Consume immediately (single-use)
        BOS_DB::set_setting('pwd_reset_token', '');

        $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        BOS_DB::update(BOS_DB::t('users'),
            ['password_hash' => $hash, 'failed_attempts' => 0, 'locked_until' => null],
            ['id' => (int)$stored['user_id']]
        );
        self::revoke_all_sessions((int)$stored['user_id']);
        return true;
    }
}
