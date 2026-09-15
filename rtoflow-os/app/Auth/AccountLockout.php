<?php

namespace RTOFLOW\Auth;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * Account Lockout — Brute Force Protection
 *
 * Tracks failed login attempts per username AND per IP separately.
 * Locks out an account after N consecutive failures, with escalating
 * lockout periods.
 *
 * Progressive lockout:
 *   Attempt 1–4  : No lockout
 *   Attempt 5    : 30-minute lockout
 *   Attempt 6–9  : Each failure extends by 30 minutes
 *   Attempt 10+  : 24-hour lockout, admin notified
 *
 * Usage:
 *   if (AccountLockout::isLocked($username, $ip)) {
 *       wp_die('Too many failed attempts. Try again later.');
 *   }
 *   AccountLockout::recordFailure($username, $ip);
 *   AccountLockout::clearFailures($username);
 */
final class AccountLockout
{
    private const MAX_ATTEMPTS      = 5;    // Configurable via env
    private const BASE_LOCKOUT_MINS = 30;   // First lockout duration
    private const MAX_LOCKOUT_HOURS = 24;   // Maximum lockout duration
    private const ATTEMPT_TTL       = 3600; // Track attempts for 1 hour

    private const OPTION_PREFIX     = 'rtoflow_lockout_';
    private const IP_PREFIX         = 'rtofl_ip_lockout_';

    // ── Check ─────────────────────────────────────────────────────────────

    /**
     * Returns true if the account or IP is currently locked.
     */
    public static function isLocked(string $username, string $ip = ''): bool
    {
        $ip = $ip ?: \RTOFLOW\Http\Middleware\RateLimiter::clientIp();

        return self::isAccountLocked($username) || self::isIpLocked($ip);
    }

    public static function isAccountLocked(string $username): bool
    {
        $data = self::getData('user_' . self::normalise($username));
        if (!$data || empty($data['locked_until'])) return false;
        if ($data['locked_until'] > time()) return true;
        // Lock expired — clear it
        self::clearFailures($username);
        return false;
    }

    public static function isIpLocked(string $ip): bool
    {
        $data = self::getData('ip_' . self::normalise($ip));
        if (!$data || empty($data['locked_until'])) return false;
        if ($data['locked_until'] > time()) return true;
        self::clearIpLockout($ip);
        return false;
    }

    // ── Record failure ────────────────────────────────────────────────────

    /**
     * Record a failed login attempt for username and IP.
     * Returns ['locked' => bool, 'remaining_attempts' => int, 'locked_until' => int|null]
     */
    public static function recordFailure(string $username, string $ip = ''): array
    {
        $ip       = $ip ?: \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
        $maxAttempts = Env::int('AUTH_MAX_ATTEMPTS', self::MAX_ATTEMPTS);

        // Record against username
        $userResult = self::recordFailureFor('user_' . self::normalise($username), $maxAttempts);

        // Record against IP (separate counter, higher threshold for IP)
        $ipResult   = self::recordFailureFor('ip_' . self::normalise($ip), $maxAttempts * 3);

        // Notify admin on severe lockout
        if ($userResult['attempts'] >= 10) {
            self::notifyAdmin($username, $ip, $userResult['attempts']);
        }

        // Log the failure
        error_log(sprintf(
            'RTOFLOW Auth: Failed login for [%s] from [%s] — attempt #%d',
            $username, $ip, $userResult['attempts']
        ));

        return $userResult;
    }

    // ── Clear failures ────────────────────────────────────────────────────

    /** Call this after a successful login */
    public static function clearFailures(string $username): void
    {
        delete_option(self::OPTION_PREFIX . 'user_' . self::normalise($username));
    }

    public static function clearIpLockout(string $ip): void
    {
        delete_option(self::OPTION_PREFIX . 'ip_' . self::normalise($ip));
    }

    // ── Information ───────────────────────────────────────────────────────

    /**
     * Get lockout info for a username. Returns null if not locked.
     */
    public static function getLockoutInfo(string $username): ?array
    {
        $data = self::getData('user_' . self::normalise($username));
        if (!$data || empty($data['locked_until']) || $data['locked_until'] <= time()) {
            return null;
        }

        $remaining = $data['locked_until'] - time();
        $minutes   = ceil($remaining / 60);

        return [
            'locked_until'    => $data['locked_until'],
            'remaining_secs'  => $remaining,
            'remaining_mins'  => $minutes,
            'attempts'        => $data['attempts'] ?? 0,
            'message'         => "Your account is locked for {$minutes} " . ($minutes === 1 ? 'minute' : 'minutes') . " due to too many failed login attempts.",
        ];
    }

    // ── Admin unlock ──────────────────────────────────────────────────────

    /** Admin can manually unlock an account */
    public static function adminUnlock(string $username): void
    {
        self::clearFailures($username);
        error_log("RTOFLOW Auth: Account [{$username}] manually unlocked by admin.");
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private static function recordFailureFor(string $key, int $maxAttempts): array
    {
        $data     = self::getData($key) ?: ['attempts' => 0, 'first_attempt' => time(), 'locked_until' => null];
        $data['attempts']++;
        $data['last_attempt'] = time();

        if ($data['attempts'] >= $maxAttempts) {
            // Calculate progressive lockout
            $lockoutNumber = floor($data['attempts'] / $maxAttempts);
            $lockoutMins   = min(
                self::MAX_LOCKOUT_HOURS * 60,
                self::BASE_LOCKOUT_MINS * (2 ** ($lockoutNumber - 1)) // Exponential backoff
            );
            $data['locked_until'] = time() + ($lockoutMins * 60);
        }

        self::saveData($key, $data);

        return [
            'locked'           => isset($data['locked_until']) && $data['locked_until'] > time(),
            'attempts'         => $data['attempts'],
            'remaining_attempts' => max(0, $maxAttempts - $data['attempts']),
            'locked_until'     => $data['locked_until'] ?? null,
        ];
    }

    private static function getData(string $key): ?array
    {
        // P9-WP-003 FIX: use transients (auto-expiring) not wp_options (permanent)
        $data = get_transient(self::OPTION_PREFIX . $key);
        if ($data === false) return null;
        if (!is_array($data)) return null;

        return $data;
    }

    private static function saveData(string $key, array $data): void
    {
        // P9-WP-003 FIX: TTL based on lockout expiry or attempt window
        $ttl = isset($data['locked_until']) && $data['locked_until'] > time()
            ? max(3600, $data['locked_until'] - time() + 300)
            : self::ATTEMPT_TTL;
        set_transient(self::OPTION_PREFIX . $key, $data, $ttl);
    }

    private static function normalise(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_@.\-]/', '_', strtolower($key));
    }

    private static function notifyAdmin(string $username, string $ip, int $attempts): void
    {
        $adminEmail = get_option('admin_email', '');
        if (!$adminEmail) return;

        // Throttle admin notifications (max 1 per hour per username)
        $throttleKey = 'rtofl_lockout_notif_' . md5($username);
        if (get_transient($throttleKey)) return;
        set_transient($throttleKey, 1, 3600);

        $subject = '[RTOFLOW] Security Alert: Account Lockout';
        $message = sprintf(
            "Multiple failed login attempts detected.\n\nUsername: %s\nIP Address: %s\nAttempts: %d\nTime: %s\n\nIf this was not you, please investigate immediately.",
            $username, $ip, $attempts, current_time('Y-m-d H:i:s')
        );

        wp_mail($adminEmail, $subject, $message);
    }
}
