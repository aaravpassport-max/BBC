<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Auth\TwoFactor;
use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

// ENTERPRISE GAP FIX (gap-analysis Section 1 — "critical missing
// functionality: 2FA has no setup UI"): app/Auth/TwoFactor.php is a
// complete, correct RFC-6238 TOTP implementation — generateSecret(),
// storeSecret(), enable()/disable(), verify(), backup codes, and a
// session-verification gate already wired into Bootstrap.php's
// template_redirect — but NOTHING in the codebase ever called
// generateSecret(), storeSecret(), or enable(). There was no screen where a
// user could turn 2FA on. isEnabled() could therefore never return true for
// anyone, making the entire 2FA gate a permanent no-op regardless of how
// security-conscious an admin wanted to be. This is the missing UI layer —
// no changes to TwoFactor.php's actual cryptography were needed, it was
// already correct.
class TwoFactorSettingsController
{
    private const PENDING_SECRET_META = 'rtoflow_2fa_pending_secret';

    // TRACE: any logged-in admin/staff user visits /rto-admin/my-security/ →
    //        shows current 2FA status (enabled/disabled) →
    //        if disabled: renders nothing sensitive yet (secret is generated
    //        only on the "Set Up 2FA" click, via AJAX, never embedded in the page load) →
    //        if enabled: shows remaining backup-code count and a Disable control →
    //        preconditions: is_user_logged_in() →
    //        postconditions: none (read-only page load)
    public function index(): void
    {
        if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }

        $userId  = get_current_user_id();
        $enabled = TwoFactor::isEnabled($userId);
        $remainingBackupCodes = $enabled ? TwoFactor::remainingBackupCodes($userId) : 0;

        rto_view('admin.security.index', compact('enabled', 'remainingBackupCodes'));
    }

    // ── AJAX: step 1 — generate a pending secret + QR, not yet enabled ────────
    // TRACE: user clicks "Set Up 2FA" → this generates a fresh secret →
    //        stores it as a PENDING (unconfirmed) user meta value, distinct
    //        from TwoFactor's real SECRET_META_KEY — a secret is never
    //        "live" (isEnabled()=true) until confirm() below verifies the
    //        user actually has it loaded in an authenticator app →
    //        returns the otpauth:// URL + QR image URL + raw secret (for
    //        manual entry) to the browser →
    //        preconditions: logged in →
    //        postconditions: rtoflow_2fa_pending_secret user meta set (plaintext —
    //        this is a short-lived setup-flow value, superseded by the encrypted
    //        real secret the moment confirm() succeeds; matches how every other
    //        TOTP setup flow, including Google's own, necessarily shows the
    //        secret in plaintext during setup)
    public function generate(): void
    {
        if (!is_user_logged_in()) rto_json_err('You must be logged in.', 401);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $userId = get_current_user_id();
        if (TwoFactor::isEnabled($userId)) rto_json_err('Two-factor authentication is already enabled on this account.');

        $secret = TwoFactor::generateSecret();
        update_user_meta($userId, self::PENDING_SECRET_META, $secret);

        $user = get_userdata($userId);
        $otpauthUrl = TwoFactor::otpauthUrl($secret, $user->user_email ?: $user->user_login);

        rto_json_ok([
            'secret'   => $secret,
            'qr_url'   => TwoFactor::qrImageUrl($otpauthUrl),
            'otpauth'  => $otpauthUrl,
        ]);
    }

    // ── AJAX: step 2 — confirm the user actually has the code, then enable ──
    public function confirm(): void
    {
        if (!is_user_logged_in()) rto_json_err('You must be logged in.', 401);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $userId = get_current_user_id();
        $code   = Sanitiser::text($_POST['code'] ?? '');
        $secret = get_user_meta($userId, self::PENDING_SECRET_META, true);

        if (!$secret) rto_json_err('No pending 2FA setup found. Please click "Set Up 2FA" again.');
        if (!preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code))) rto_json_err('Enter the 6-digit code from your authenticator app.');

        // Verify against the PENDING secret directly (TwoFactor::verify()
        // reads the already-enabled SECRET_META_KEY, which isn't set yet at
        // this point in the flow — that's the whole reason confirmation
        // exists, so we can't call it here).
        $valid = false;
        $timestamp = time();
        for ($i = -1; $i <= 1; $i++) {
            $counter = (int)floor(($timestamp + ($i * 30)) / 30);
            if (hash_equals((string)$this->generateCodeForSecret($secret, $counter), preg_replace('/\s/', '', $code))) {
                $valid = true;
                break;
            }
        }
        if (!$valid) rto_json_err('That code is incorrect or expired. Please try again with a fresh code.');

        TwoFactor::storeSecret($userId, $secret);
        $backupCodes = TwoFactor::enable($userId);
        delete_user_meta($userId, self::PENDING_SECRET_META);
        TwoFactor::setVerified($userId); // this session is already proven — don't force an immediate re-login

        AuditService::log('user.2fa_enabled', null, ['user_id' => $userId]);

        // This is the ONLY moment these codes are ever available in
        // plaintext — enable() persists only their sha256 hashes. The
        // frontend must show these to the user now with a "save them, you
        // will not see them again" warning.
        rto_json_ok([
            'message'      => 'Two-factor authentication is now enabled.',
            'backup_codes' => $backupCodes,
        ]);
    }

    // ── AJAX: disable — requires proof of possession, not just a click ───────
    public function disable(): void
    {
        if (!is_user_logged_in()) rto_json_err('You must be logged in.', 401);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $userId = get_current_user_id();
        $code   = Sanitiser::text($_POST['code'] ?? '');

        if (!TwoFactor::isEnabled($userId)) rto_json_err('Two-factor authentication is not enabled on this account.');
        if (!TwoFactor::verify($userId, $code) && !TwoFactor::verifyBackup($userId, $code)) {
            rto_json_err('Incorrect code. Enter a current 6-digit code (or a backup code) to confirm disabling 2FA.');
        }

        TwoFactor::disable($userId);
        AuditService::log('user.2fa_disabled', null, ['user_id' => $userId]);
        rto_json_ok(['message' => 'Two-factor authentication has been disabled.']);
    }

    // Duplicated from TwoFactor's private generateCode() — needed here
    // because the pending secret isn't stored under TwoFactor's own
    // SECRET_META_KEY yet, so TwoFactor::verify() can't be reused as-is for
    // the confirm() step. Same RFC 6238 HOTP calculation, byte-for-byte.
    private function generateCodeForSecret(string $secret, int $counter): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0; $bitsLeft = 0; $secretBytes = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $buffer = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $secretBytes .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash   = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }
}
