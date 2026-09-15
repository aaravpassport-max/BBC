<?php

namespace RTOFLOW\Config;

if (!defined('ABSPATH')) exit;

/**
 * Environment Configuration Loader
 *
 * Loads .env from the plugin root (or a parent directory) and provides
 * typed, validated accessors for all configuration values.
 *
 * Secrets (API keys, encryption keys) are NEVER stored in wp_options.
 * They always come from the environment file or server environment variables.
 *
 * Usage:
 *   Env::get('RAZORPAY_KEY')            // string|null
 *   Env::string('APP_NAME', 'RTOFLOW')  // typed with default
 *   Env::bool('APP_DEBUG', false)
 *   Env::int('RATE_LIMIT_PUBLIC', 60)
 */
final class Env
{
    private static bool $loaded = false;
    private static array $data  = [];

    // ── Possible .env locations (checked in order) ────────────────────────
    private static array $search_paths = [];

    public static function load(): void
    {
        if (self::$loaded) return;

        self::$search_paths = [
            RTOFLOW_DIR . '.env',                       // plugin directory
            dirname(RTOFLOW_DIR) . '/.env',             // wp-content/plugins/
            dirname(RTOFLOW_DIR, 2) . '/.env',          // wp-content/
            dirname(RTOFLOW_DIR, 3) . '/.env',          // WordPress root
            dirname(RTOFLOW_DIR, 4) . '/.env',          // one above WordPress root
        ];

        $file = null;
        foreach (self::$search_paths as $path) {
            if (is_file($path) && is_readable($path)) {
                $file = $path;
                break;
            }
        }

        if ($file) {
            self::parse($file);
        }

        // Server environment variables take precedence over .env file
        // (allows Docker / cPanel environment injection)
        self::$loaded = true;
    }

    // ── Parse .env file ───────────────────────────────────────────────────
    private static function parse(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            if (!str_contains($line, '=')) continue;

            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);

            // Strip inline comments
            if (str_contains($val, ' #')) {
                $val = trim(explode(' #', $val, 2)[0]);
            }

            // Strip surrounding quotes
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            // Handle multiline values with \n
            $val = str_replace('\n', "\n", $val);

            self::$data[$key] = $val;
        }
    }

    // ── Raw getter ────────────────────────────────────────────────────────
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        // 1. System/Docker environment variable (highest priority)
        $sysVal = getenv($key);
        if ($sysVal !== false) return $sysVal;

        // 2. $_ENV superglobal
        if (isset($_ENV[$key])) return $_ENV[$key];

        // 3. Parsed .env file
        if (array_key_exists($key, self::$data)) return self::$data[$key];

        return $default;
    }

    // ── Typed accessors ───────────────────────────────────────────────────
    public static function string(string $key, string $default = ''): string
    {
        $val = self::get($key);
        return $val !== null ? (string)$val : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val !== null ? (int)$val : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $val = self::get($key);
        return $val !== null ? (float)$val : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) return $default;
        return in_array(strtolower((string)$val), ['true', '1', 'yes', 'on'], true);
    }

    public static function required(string $key): string
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            // Log a warning but do NOT throw — plugin works without .env file.
            // Configure values in WP Admin → RTOFLOW → Settings instead.
            error_log("RTOFLOW: Environment variable [{$key}] not set. Configure in Admin → Settings or create a .env file.");
            return '';
        }
        return (string)$val;
    }

    /** Return all currently loaded env values (for debugging — never expose in prod) */
    public static function all(): array
    {
        self::load();
        return self::$data;
    }

    /** Check if we're in a specific environment */
    public static function is(string $env): bool
    {
        return strtolower(self::string('APP_ENV', 'production')) === strtolower($env);
    }

    public static function isProduction(): bool { return self::is('production'); }
    public static function isStaging(): bool    { return self::is('staging'); }
    public static function isDevelopment(): bool{ return self::is('development') || self::is('local'); }
    public static function isDebug(): bool      { return self::bool('APP_DEBUG', false); }
}
