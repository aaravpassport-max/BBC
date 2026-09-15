<?php

namespace RTOFLOW\Security;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * Encryption — AES-256-GCM authenticated encryption for sensitive PII.
 *
 * Key resolution order (highest to lowest security):
 *   1. ENCRYPTION_KEY in .env file        → recommended for production
 *   2. Auto-generated key in wp_options   → works out-of-the-box, no setup needed
 *
 * The plugin works fully without a .env file. The auto-generated key is created
 * once on activation and stored securely in the WordPress database.
 * Users can optionally move it to .env for added security (key not in database).
 */
final class Encryption
{
    private const CIPHER   = 'aes-256-gcm';
    private const TAG_LEN  = 16;
    private const IV_LEN   = 12;
    private const VERSION  = 1;
    private const WP_KEY_OPTION = 'rtoflow_encryption_key';

    private static ?string $key = null;

    // ── Key resolution ────────────────────────────────────────────────────────

    private static function key(): string
    {
        if (self::$key !== null) return self::$key;

        // 1. .env file key — 64-char hex string → 32 raw bytes
        $hex = Env::string('ENCRYPTION_KEY', '');
        if (strlen($hex) === 64 && ctype_xdigit($hex)) {
            self::$key = hex2bin($hex);
            return self::$key;
        }

        // 2. Auto-generated key stored in wp_options (created on first use)
        $stored = get_option(self::WP_KEY_OPTION, '');
        if (strlen($stored) === 64 && ctype_xdigit($stored)) {
            self::$key = hex2bin($stored);
            return self::$key;
        }

        // 3. Generate a new key, save to wp_options, and use it
        $generated = bin2hex(random_bytes(32)); // 64 hex chars = 32 bytes
        update_option(self::WP_KEY_OPTION, $generated, false); // false = not autoloaded
        self::$key = hex2bin($generated);
        return self::$key;
    }

    /**
     * Returns true if using the .env key (most secure),
     * false if using the auto-generated wp_options key.
     */
    public static function isUsingEnvKey(): bool
    {
        $hex = Env::string('ENCRYPTION_KEY', '');
        return strlen($hex) === 64 && ctype_xdigit($hex);
    }

    // ── Encrypt / Decrypt ─────────────────────────────────────────────────────

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') return '';

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, self::key(),
            OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('RTOFLOW Encryption: openssl_encrypt failed.');
        }

        return base64_encode(pack('C', self::VERSION) . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') return '';

        $raw = base64_decode($ciphertext, true);
        if ($raw === false) {
            throw new \RuntimeException('RTOFLOW Encryption: invalid base64.');
        }

        $minLen = 1 + self::IV_LEN + self::TAG_LEN + 1;
        if (strlen($raw) < $minLen) {
            throw new \RuntimeException('RTOFLOW Encryption: ciphertext too short.');
        }

        $version = ord($raw[0]);
        if ($version !== self::VERSION) {
            throw new \RuntimeException('RTOFLOW Encryption: unsupported version.');
        }

        $offset    = 1;
        $iv        = substr($raw, $offset, self::IV_LEN);  $offset += self::IV_LEN;
        $tag       = substr($raw, $offset, self::TAG_LEN); $offset += self::TAG_LEN;
        $encrypted = substr($raw, $offset);

        $plaintext = openssl_decrypt(
            $encrypted, self::CIPHER, self::key(),
            OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('RTOFLOW Encryption: decryption failed — key mismatch or tampered data.');
        }

        return $plaintext;
    }

    /**
     * Safe decrypt — returns empty string on failure instead of throwing.
     * Use for non-critical fields where corruption should not block rendering.
     */
    public static function decryptSafe(string $ciphertext): string
    {
        if ($ciphertext === '') return '';
        try {
            return self::decrypt($ciphertext);
        } catch (\Throwable) {
            return '';
        }
    }

    // ── PII masking ───────────────────────────────────────────────────────────

    public static function maskAadhaar(string $v): string
    {
        $c = preg_replace('/\D/', '', $v);
        return strlen($c) === 12 ? 'XXXX-XXXX-' . substr($c, -4) : 'XXXX-XXXX-XXXX';
    }

    public static function maskPan(string $v): string
    {
        $c = strtoupper(preg_replace('/\s+/', '', $v));
        return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $c) ? 'XXXXXX' . substr($c, -4) : 'XXXXXXXXXX';
    }

    public static function maskMobile(string $v): string
    {
        $c = preg_replace('/\D/', '', $v);
        return strlen($c) >= 10 ? str_repeat('X', strlen($c) - 4) . substr($c, -4) : 'XXXXXXXXXX';
    }

    public static function maskEmail(string $v): string
    {
        $p = explode('@', $v, 2);
        if (count($p) !== 2) return '****@****.***';
        return substr($p[0], 0, 2) . str_repeat('*', max(0, strlen($p[0]) - 2)) . '@' . $p[1];
    }

    // ── HMAC ──────────────────────────────────────────────────────────────────

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    public static function verifyHmac(string $data, string $hash): bool
    {
        return hash_equals(hash_hmac('sha256', $data, self::key()), $hash);
    }

    // ── Signed tokens (invoice download URLs, email verification) ────────────

    public static function signedToken(string $payload, int $ttl = 3600): string
    {
        $data = json_encode([
            'payload' => $payload,
            'expires' => time() + $ttl,
            'nonce'   => bin2hex(random_bytes(8)),
        ]);
        $sig = hash_hmac('sha256', $data, self::key());
        return rtrim(strtr(base64_encode($sig . '.' . $data), '+/', '-_'), '=');
    }

    public static function verifySignedToken(string $token): ?string
    {
        try {
            $raw = base64_decode(strtr($token . str_repeat('=', 4), '-_', '+/'), true);
            if ($raw === false) return null;

            $dot = strpos($raw, '.');
            if ($dot === false) return null;

            $sig  = substr($raw, 0, $dot);
            $data = substr($raw, $dot + 1);

            if (!hash_equals(hash_hmac('sha256', $data, self::key()), $sig)) return null;

            $parsed = json_decode($data, true);
            if (!is_array($parsed) || !isset($parsed['payload'], $parsed['expires'])) return null;
            if ($parsed['expires'] < time()) return null;

            return $parsed['payload'];
        } catch (\Throwable) {
            return null;
        }
    }
}
