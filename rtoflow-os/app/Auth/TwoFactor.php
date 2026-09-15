<?php

namespace RTOFLOW\Auth;

use RTOFLOW\Security\Encryption;

if (!defined('ABSPATH')) exit;

/**
 * Two-Factor Authentication (TOTP)
 *
 * Implements RFC 6238 TOTP compatible with Google Authenticator,
 * Authy, Microsoft Authenticator, and any other TOTP app.
 *
 * Implementation is pure PHP — no external library required.
 * The secret is encrypted at rest using the field-level encryption layer.
 *
 * Usage:
 *   // Setup (admin enabling 2FA for themselves)
 *   $secret = TwoFactor::generateSecret();
 *   $qrUrl  = TwoFactor::qrUrl($secret, $email);
 *   TwoFactor::storeSecret($userId, $secret);
 *
 *   // Verification at login
 *   if (!TwoFactor::verify($userId, $code)) {
 *       // reject login
 *   }
 *
 *   // Check if user has 2FA enabled
 *   TwoFactor::isEnabled($userId);
 */
final class TwoFactor
{
    private const SECRET_META_KEY  = 'rtoflow_2fa_secret';
    private const ENABLED_META_KEY = 'rtoflow_2fa_enabled';
    private const BACKUP_META_KEY  = 'rtoflow_2fa_backup_codes';
    private const TOTP_PERIOD      = 30;   // seconds
    private const TOTP_DIGITS      = 6;
    private const TOTP_WINDOW      = 1;    // accept 1 period before/after
    private const SECRET_LENGTH    = 20;   // bytes → 32 char base32

    // ── Secret management ─────────────────────────────────────────────────

    /** Generate a cryptographically secure random TOTP secret */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_LENGTH));
    }

    /** Store the encrypted secret for a user */
    public static function storeSecret(int $userId, string $secret): void
    {
        $encrypted = Encryption::encrypt($secret);
        update_user_meta($userId, self::SECRET_META_KEY, $encrypted);
    }

    /**
     * Enable 2FA for a user (after they've verified a code).
     *
     * ENTERPRISE GAP FIX (found while wiring the first-ever caller of this
     * method, TwoFactorSettingsController::confirm() — Section 1): this
     * generated backup codes and immediately hashed them for storage
     * without ever returning the plaintext values to the caller. Since only
     * the sha256 hash is persisted (by design — codes must not be
     * recoverable from the database), the plaintext existed for exactly the
     * duration of this function call and then was gone forever. A user
     * enabling 2FA would never see their own backup codes, making them
     * completely unusable for their entire purpose (recovering account
     * access after losing the authenticator device). Now returns the
     * plaintext codes so the setup UI can display them exactly once, with
     * an explicit "save these now" warning — the same one-time-reveal
     * pattern used for API keys/secrets industry-wide.
     *
     * @return string[] the 8 plaintext backup codes, shown to the user once
     */
    public static function enable(int $userId): array
    {
        update_user_meta($userId, self::ENABLED_META_KEY, '1');
        // Generate and store backup codes
        $codes = self::generateBackupCodes();
        update_user_meta($userId, self::BACKUP_META_KEY, wp_json_encode(array_map(
            fn($c) => ['code' => hash('sha256', $c), 'used' => false],
            $codes
        )));
        return $codes;
    }

    /** Disable 2FA for a user (admin action or user disable) */
    public static function disable(int $userId): void
    {
        delete_user_meta($userId, self::ENABLED_META_KEY);
        delete_user_meta($userId, self::SECRET_META_KEY);
        delete_user_meta($userId, self::BACKUP_META_KEY);
    }

    public static function isEnabled(int $userId): bool
    {
        return get_user_meta($userId, self::ENABLED_META_KEY, true) === '1';
    }

    /** Return the decrypted TOTP secret for a user */
    private static function getSecret(int $userId): ?string
    {
        $encrypted = get_user_meta($userId, self::SECRET_META_KEY, true);
        if (!$encrypted) return null;
        try {
            return Encryption::decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Verification ──────────────────────────────────────────────────────

    /**
     * Verify a 6-digit TOTP code for a user.
     * Checks current window ± TOTP_WINDOW periods.
     */
    public static function verify(int $userId, string $code): bool
    {
        $code = preg_replace('/\s/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $secret = self::getSecret($userId);
        if (!$secret) return false;

        $timestamp = time();

        for ($i = -self::TOTP_WINDOW; $i <= self::TOTP_WINDOW; $i++) {
            $counter = (int)floor(($timestamp + ($i * self::TOTP_PERIOD)) / self::TOTP_PERIOD);
            if (hash_equals((string)self::generateCode($secret, $counter), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Verify a backup code (one-time use) */
    public static function verifyBackup(int $userId, string $code): bool
    {
        $code    = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
        $raw     = get_user_meta($userId, self::BACKUP_META_KEY, true);
        $codes   = json_decode($raw ?: '[]', true);

        foreach ($codes as &$entry) {
            if (!$entry['used'] && hash_equals($entry['code'], hash('sha256', $code))) {
                $entry['used'] = true;
                update_user_meta($userId, self::BACKUP_META_KEY, wp_json_encode($codes));
                return true;
            }
        }

        return false;
    }

    // ── QR Code URL ───────────────────────────────────────────────────────

    /**
     * Generate an otpauth:// URL for QR code display
     */
    public static function otpauthUrl(string $secret, string $accountName, string $issuer = ''): string
    {
        $issuer = $issuer ?: get_option('rtoflow_company_name', 'RTOFLOW');
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode("{$issuer}:{$accountName}"),
            $secret,
            rawurlencode($issuer),
            self::TOTP_DIGITS,
            self::TOTP_PERIOD
        );
    }

    /**
     * Generate a Google Charts QR code URL (client-side rendering preferred)
     */
    public static function qrImageUrl(string $otpauthUrl): string
    {
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='
             . rawurlencode($otpauthUrl);
    }

    // ── Backup codes ──────────────────────────────────────────────────────

    /** Generate 8 one-time backup codes */
    public static function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 char hex
        }
        return $codes;
    }

    /** Get remaining (unused) backup codes count */
    public static function remainingBackupCodes(int $userId): int
    {
        $raw   = get_user_meta($userId, self::BACKUP_META_KEY, true);
        $codes = json_decode($raw ?: '[]', true);
        return count(array_filter($codes, fn($c) => !$c['used']));
    }

    // ── Session management ────────────────────────────────────────────────

    /**
     * Mark that 2FA has been completed for this session.
     *
     * ENTERPRISE GAP FIX (Section 6 — "hidden infrastructure: 2FA session
     * state uses $_SESSION"): this used to also write to PHP's $_SESSION
     * superglobal — but isVerifiedForSession() below never read it (it only
     * ever checked WP_Session_Tokens, which is the correct, durable,
     * per-login-session store WordPress itself uses for "remember me" and
     * force-logout). The $_SESSION write was dead code: harmless today only
     * because nothing else in this codebase calls session_start(), but it
     * would silently break under a load-balanced multi-server deployment
     * with no shared session backend (each request could land on a
     * different server with a different in-memory $_SESSION), and it
     * misleadingly suggested $_SESSION was part of the real mechanism to
     * anyone reading this file. WP_Session_Tokens is already
     * server-agnostic (stored in user meta), so it alone is both correct
     * and sufficient — removing the dead write, not adding a replacement.
     */
    public static function setVerified(int $userId): void
    {
        $sessions = \WP_Session_Tokens::get_instance($userId);
        $token    = wp_get_session_token();
        if ($token) {
            $sessions->update($token, ['rtoflow_2fa_verified' => true]);
        }
    }

    public static function isVerifiedForSession(int $userId): bool
    {
        $token = wp_get_session_token();
        if (!$token) return false;
        $sessions = \WP_Session_Tokens::get_instance($userId);
        $session  = $sessions->get($token);
        return !empty($session['rtoflow_2fa_verified']);
    }

    // ── TOTP algorithm ────────────────────────────────────────────────────

    private static function generateCode(string $secret, int $counter): string
    {
        $secretBytes = self::base32Decode($secret);
        $counterBytes = pack('N*', 0) . pack('N*', $counter); // 8 bytes big-endian

        $hash   = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::TOTP_DIGITS);

        return str_pad((string)$code, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    // ── Base32 (RFC 4648) ─────────────────────────────────────────────────

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer  = ($buffer << 8) | ord($char);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result   .= $alphabet[($buffer >> $bitsLeft) & 31];
            }
        }

        if ($bitsLeft > 0) {
            $result .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $result;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result   = '';
        $buffer   = 0;
        $bitsLeft = 0;

        foreach (str_split(strtoupper($data)) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $buffer  = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result   .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}
