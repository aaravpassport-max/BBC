<?php

namespace RTOFLOW\Security;

if (!defined('ABSPATH')) exit;

/**
 * Central Sanitisation Layer
 *
 * All user input MUST pass through this class before use.
 * Never trust $_POST, $_GET, or $request->all() directly.
 *
 * Usage:
 *   $name   = Sanitiser::text($_POST['name']);
 *   $amount = Sanitiser::amount($_POST['amount']);
 *   $mobile = Sanitiser::mobile($_POST['mobile']);
 *   $errors = Sanitiser::validate(['name' => 'required|max:200'], $_POST);
 */
final class Sanitiser
{
    // ── Text fields ───────────────────────────────────────────────────────

    /** Strip tags, trim, limit length */
    public static function text(mixed $val, int $maxLen = 500): string
    {
        if (!is_string($val) && !is_numeric($val)) return '';
        return mb_substr(trim(strip_tags((string)$val)), 0, $maxLen, 'UTF-8');
    }

    /** Alphanumeric only (names, codes) */
    public static function alphanumeric(mixed $val, int $maxLen = 100): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s\-_.]/', '', (string)$val);
        return mb_substr(trim((string)$clean), 0, $maxLen, 'UTF-8');
    }

    /** Email — lowercase, validated format */
    public static function email(mixed $val): string
    {
        $clean = strtolower(trim((string)$val));
        return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : '';
    }

    /** Indian mobile number — normalise to 10 digits */
    public static function mobile(mixed $val): string
    {
        $digits = preg_replace('/\D/', '', (string)$val);
        // Remove country code if present
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '+91')) {
            $digits = substr($digits, 3);
        }
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
            return $digits;
        }
        return '';
    }

    /** Indian Aadhaar number — 12 digits */
    public static function aadhaar(mixed $val): string
    {
        $digits = preg_replace('/\D/', '', (string)$val);
        return strlen($digits) === 12 ? $digits : '';
    }

    /** Indian PAN — uppercase, 10 chars */
    public static function pan(mixed $val): string
    {
        $pan = strtoupper(preg_replace('/\s/', '', (string)$val));
        return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan) ? $pan : '';
    }

    /** GST number — 15 chars */
    public static function gstin(mixed $val): string
    {
        $gstin = strtoupper(preg_replace('/\s/', '', (string)$val));
        return preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z\d]{1}[Z]{1}[A-Z\d]{1}$/', $gstin) ? $gstin : '';
    }

    /** IFSC code */
    public static function ifsc(mixed $val): string
    {
        $ifsc = strtoupper(trim((string)$val));
        return preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) ? $ifsc : '';
    }

    /** Positive monetary amount — 2 decimal places max */
    public static function amount(mixed $val): float
    {
        $f = (float)preg_replace('/[^0-9.]/', '', (string)$val);
        return $f >= 0 ? round($f, 2) : 0.0;
    }

    /** Positive integer */
    public static function int(mixed $val, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $i = (int)$val;
        return max($min, min($max, $i));
    }

    /** Slug — lowercase letters, numbers, hyphens */
    public static function slug(mixed $val, int $maxLen = 200): string
    {
        $clean = strtolower(trim((string)$val));
        $clean = preg_replace('/[^a-z0-9\-]/', '-', $clean);
        $clean = preg_replace('/-+/', '-', $clean);
        return mb_substr(trim($clean, '-'), 0, $maxLen, 'UTF-8');
    }

    /** URL — must be http or https */
    public static function url(mixed $val): string
    {
        $url = filter_var(trim((string)$val), FILTER_VALIDATE_URL);
        if (!$url) return '';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /** Date in Y-m-d format */
    public static function date(mixed $val): string
    {
        $d = trim((string)$val);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false) {
            return $d;
        }
        return '';
    }

    /** JSON string — parse and re-encode to normalise */
    public static function json(mixed $val): string
    {
        if (is_array($val) || is_object($val)) {
            return wp_json_encode($val) ?: '{}';
        }
        $decoded = json_decode((string)$val, true);
        return $decoded !== null ? wp_json_encode($decoded) : '{}';
    }

    /** Boolean — accepts 1, '1', 'true', 'yes', 'on' */
    public static function bool(mixed $val): bool
    {
        return in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
    }

    /** HTML — strip dangerous tags but allow safe formatting */
    public static function html(mixed $val): string
    {
        $allowed = [
            'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'u' => [],
            'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href' => [], 'title' => []],
            'h3' => [], 'h4' => [], 'h5' => [], 'blockquote' => [], 'code' => [],
        ];
        return wp_kses((string)$val, $allowed);
    }

    /** Array of integers */
    public static function intArray(mixed $val): array
    {
        if (!is_array($val)) {
            $val = explode(',', (string)$val);
        }
        return array_values(array_filter(array_map('intval', $val), fn($v) => $v > 0));
    }

    /** Sanitise an entire associative array by field rules */
    public static function array(array $data, array $rules): array
    {
        $clean = [];
        foreach ($rules as $field => $type) {
            $val = $data[$field] ?? null;
            $clean[$field] = match($type) {
                'text'          => self::text($val),
                'email'         => self::email($val),
                'mobile'        => self::mobile($val),
                'amount'        => self::amount($val),
                'int'           => self::int($val),
                'bool'          => self::bool($val),
                'slug'          => self::slug($val),
                'url'           => self::url($val),
                'date'          => self::date($val),
                'json'          => self::json($val),
                'html'          => self::html($val),
                'aadhaar'       => self::aadhaar($val),
                'pan'           => self::pan($val),
                'gstin'         => self::gstin($val),
                'ifsc'          => self::ifsc($val),
                'alphanumeric'  => self::alphanumeric($val),
                default         => self::text($val),
            };
        }
        return $clean;
    }

    // ── Validation ────────────────────────────────────────────────────────

    /**
     * Validate an array of data against rules.
     *
     * Rule format: 'field' => 'required|min:2|max:100|email'
     *
     * Returns ['errors' => [...], 'passed' => bool]
     */
    public static function validate(array $rules, array $data): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleStr) {
            $ruleList = explode('|', $ruleStr);
            $val      = $data[$field] ?? null;
            $label    = ucfirst(str_replace('_', ' ', $field));

            foreach ($ruleList as $rule) {
                [$ruleName, $param] = array_pad(explode(':', $rule, 2), 2, null);

                $error = match($ruleName) {
                    'required'  => (($val === null || $val === '') ? "{$label} is required." : null),
                    'min'       => (is_string($val) && mb_strlen($val) < (int)$param) ? "{$label} must be at least {$param} characters." : null,
                    'max'       => (is_string($val) && mb_strlen($val) > (int)$param) ? "{$label} must not exceed {$param} characters." : null,
                    'min_val'   => ((float)$val < (float)$param) ? "{$label} must be at least {$param}." : null,
                    'max_val'   => ((float)$val > (float)$param) ? "{$label} must not exceed {$param}." : null,
                    'email'     => (($val !== null && $val !== '') && !filter_var($val, FILTER_VALIDATE_EMAIL)) ? "{$label} must be a valid email address." : null,
                    'numeric'   => (($val !== null && $val !== '') && !is_numeric($val)) ? "{$label} must be a number." : null,
                    'integer'   => (($val !== null && $val !== '') && !ctype_digit((string)$val)) ? "{$label} must be a whole number." : null,
                    'mobile'    => (($val !== null && $val !== '') && self::mobile($val) === '') ? "{$label} must be a valid 10-digit Indian mobile number." : null,
                    'in'        => (($val !== null && $val !== '') && !in_array($val, explode(',', $param ?? ''), true)) ? "{$label} must be one of: {$param}." : null,
                    'url'       => (($val !== null && $val !== '') && self::url($val) === '') ? "{$label} must be a valid URL." : null,
                    'date'      => (($val !== null && $val !== '') && self::date($val) === '') ? "{$label} must be a valid date (YYYY-MM-DD)." : null,
                    'boolean'   => null, // Always valid
                    'nullable'  => null, // Always valid
                    default     => null,
                };

                if ($error !== null) {
                    $errors[$field][] = $error;
                    break; // Stop checking rules for this field on first failure
                }
            }
        }

        return [
            'errors' => $errors,
            'passed' => empty($errors),
        ];
    }

    // ── Output escaping ───────────────────────────────────────────────────

    /** Safe HTML output (use in views instead of echo $var) */
    public static function esc(mixed $val): string
    {
        return esc_html((string)$val);
    }

    /** Safe attribute output */
    public static function escAttr(mixed $val): string
    {
        return esc_attr((string)$val);
    }

    /** Safe URL output */
    public static function escUrl(mixed $val): string
    {
        return esc_url((string)$val);
    }

    /**
     * Deep-clean an entire array (e.g. $_POST) recursively.
     * Returns array with all string values sanitised with sanitize_text_field.
     */
    public static function deepClean(array $data, int $maxDepth = 3): array
    {
        $result = [];
        foreach ($data as $key => $val) {
            $cleanKey = sanitize_key((string)$key);
            if (is_array($val) && $maxDepth > 0) {
                $result[$cleanKey] = self::deepClean($val, $maxDepth - 1);
            } else {
                $result[$cleanKey] = sanitize_text_field((string)$val);
            }
        }
        return $result;
    }
}
