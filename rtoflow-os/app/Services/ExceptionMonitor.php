<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 6 — structured exception monitoring):
 * see migration 2024_01_01_000031_create_exception_log.php for the full
 * problem statement. This class is the single write path into
 * rto_exception_log, called from three places wired in Bootstrap.php:
 *   - set_exception_handler()  → an uncaught \Throwable
 *   - set_error_handler()      → a PHP warning/notice/deprecation
 *   - register_shutdown_function() → a fatal error PHP itself terminates on
 *     (out-of-memory, parse error in a required file, etc. — none of which
 *     reach the other two handlers)
 *
 * De-duplication: capture() groups by (level, file, line, message) within
 * the existing row rather than inserting a new row per occurrence — a
 * hot-path bug that fires 500 times a minute must not fill this table with
 * 500 near-identical rows; it should show as one row with occurrences=500
 * and an updated last_seen.
 */
class ExceptionMonitor
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'onUncaughtException']);
        set_error_handler([self::class, 'onPhpError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onUncaughtException(\Throwable $e): void
    {
        self::capture('exception', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

        // LIVE-SITE FATAL FIX: registering set_exception_handler() replaces
        // PHP's own default handler, which normally prints "Fatal error:
        // Uncaught ..." (or, under WordPress, its "There has been a critical
        // error" screen). Before this fix, capture() above logged the error
        // to rto_exception_log and then returned with nothing written to the
        // response — so instead of either of those, the user got a
        // completely blank white page with zero indication anything went
        // wrong (the exact symptom reported for /rto-admin/my-security/ and
        // every other page hitting an unbound container class — see the
        // Bootstrap::buildContainer() fix delivered alongside this one).
        // This restores a visible, human-readable failure instead of
        // silence: a plain admin-facing message for RTOFLOW page loads (not
        // WP_DEBUG's raw stack trace — this may run in front of any visitor,
        // not just an admin who can read one), and a proper JSON error for
        // AJAX/REST calls so JS error handlers still fire correctly instead
        // of failing to parse an empty body.
        if (headers_sent()) return;
        $isAjax = (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST);
        http_response_code(500);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode([
                'success' => false,
                'message' => 'Something went wrong processing that request. Please try again, and contact support if this keeps happening.',
            ]);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Something went wrong</title></head><body style="font-family:sans-serif;max-width:560px;margin:80px auto;text-align:center;color:#333">'
               . '<h2>Something went wrong loading this page</h2>'
               . '<p>The error has been logged. Please try again, or contact support if this keeps happening.</p>'
               . '</body></html>';
        }
    }

    /** @return bool false so PHP's normal error handling / logging still also runs */
    public static function onPhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // Only capture levels that indicate an actual code defect worth an
        // admin's attention — E_STRICT/E_DEPRECATED noise from third-party
        // WordPress core/plugin code is not something this platform's own
        // exception screen should be flooded with.
        $capturable = [E_WARNING, E_USER_WARNING, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (in_array($errno, $capturable, true)) {
            self::capture('warning', $errstr, $errfile, $errline, null);
        }
        return false;
    }

    public static function onShutdown(): void
    {
        $error = error_get_last();
        if (!$error) return;
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) return;
        self::capture('fatal', $error['message'], $error['file'], $error['line'], null);
    }

    public static function capture(string $level, string $message, ?string $file, ?int $line, ?string $trace): void
    {
        global $wpdb;
        // A failure in the monitor itself must never become a second,
        // cascading failure — this is the last line of defence around a
        // codebase-wide error handler, so every path here is wrapped.
        try {
            $context = [
                'area'    => $_POST['rto_area'] ?? $_GET['rto_area'] ?? null,
                'action'  => $_POST['rto_action'] ?? null,
                'page'    => $_GET['rto_page'] ?? null,
                'url'     => $_SERVER['REQUEST_URI'] ?? null,
                'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : null,
            ];

            $table = $wpdb->prefix . 'rto_exception_log';
            $now   = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');

            // De-dup window: same level+file+line+message not yet resolved.
            $existingId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE level=%s AND file=%s AND line=%d AND message=%s AND resolved=0 ORDER BY id DESC LIMIT 1",
                $level, (string)$file, (int)$line, $message
            ));

            if ($existingId) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET occurrences = occurrences + 1, last_seen = %s WHERE id = %d",
                    $now, (int)$existingId
                ));
                return;
            }

            $wpdb->insert($table, [
                'level'        => $level,
                'message'      => $message,
                'file'         => $file,
                'line'         => $line,
                'trace'        => $trace,
                'context_json' => wp_json_encode($context),
                'occurrences'  => 1,
                'first_seen'   => $now,
                'last_seen'    => $now,
                'resolved'     => 0,
            ]);
        } catch (\Throwable $inner) {
            // Genuinely nothing more can be done here — fall back to the
            // PHP error log exactly as if this monitor did not exist.
            error_log('RTOFLOW ExceptionMonitor: failed to record — ' . $inner->getMessage());
        }
    }

    /** @return array{rows: array, total: int} */
    public static function recent(bool $unresolvedOnly = true, int $page = 1, int $perPage = 30): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'rto_exception_log';
        $where = $unresolvedOnly ? 'WHERE resolved = 0' : '';
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY last_seen DESC LIMIT %d OFFSET %d",
            $perPage, max(0, ($page - 1) * $perPage)
        ), ARRAY_A) ?: [];
        return ['rows' => $rows, 'total' => $total];
    }

    public static function markResolved(int $id): bool
    {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'rto_exception_log', ['resolved' => 1], ['id' => $id]) !== false;
    }
}
