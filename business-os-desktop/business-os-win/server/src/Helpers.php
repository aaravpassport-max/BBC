<?php
/**
 * BOS_Helpers — Utility functions replacing BOS_Helpers (WordPress version).
 * No WordPress dependencies.
 */
class BOS_Helpers {

    // ── Response builders ────────────────────────────────────────────────────
    public static function ok(array $data = [], string $message = 'OK'): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $code, string $message, int $status = 400): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function paginated(array $data, int $total, int $page, int $per_page): void {
        $last_page = max(1, (int) ceil($total / $per_page));
        $from = $total > 0 ? (($page - 1) * $per_page) + 1 : 0;
        $to   = min($page * $per_page, $total);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'        => $total,
                'per_page'     => $per_page,
                'current_page' => $page,
                'last_page'    => $last_page,
                'from'         => $from,
                'to'           => $to,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Input parsing ────────────────────────────────────────────────────────
    public static function body(): array {
        static $parsed = null;
        if ($parsed !== null) return $parsed;
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true) ?? [];
        // Also merge POST fields (for form submissions)
        $parsed = array_merge($_POST, $parsed);
        return $parsed;
    }

    public static function get(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    public static function param(string $key, mixed $default = null): mixed {
        return self::body()[$key] ?? $_GET[$key] ?? $default;
    }

    public static function params(): array {
        return array_merge($_GET, self::body());
    }

    // ── Sanitization (replacing WP sanitize_* functions) ─────────────────────
    public static function str(string $val): string {
        return trim(strip_tags($val));
    }

    public static function email(string $val): string {
        return strtolower(trim($val));
    }

    public static function int_val(mixed $val): int {
        return (int) $val;
    }

    public static function float_val(mixed $val): float {
        return (float) $val;
    }

    public static function is_email(string $val): bool {
        if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        // Desktop/local accounts may use .local or other non-public TLDs
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $val);
    }

    // ── UUID generation ──────────────────────────────────────────────────────
    public static function uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ── Current datetime ──────────────────────────────────────────────────────
    public static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function today(): string {
        return gmdate('Y-m-d');
    }

    // ── Client IP ─────────────────────────────────────────────────────────────
    public static function client_ip(): string {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    // ── Audit logging ─────────────────────────────────────────────────────────
    public static function audit(
        string $module,
        string $action,
        ?object $user,
        string $record_id = '',
        string $record_label = '',
        array $old = [],
        array $new = [],
        string $result = 'SUCCESS',
        string $error_msg = ''
    ): void {
        try {
            BOS_DB::insert(BOS_DB::t('audit_logs'), [
                'timestamp'      => self::now(),
                'user_id'        => $user?->id,
                'user_name'      => $user?->name,
                'user_role'      => $user?->role,
                'ip_address'     => self::client_ip(),
                'user_agent'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'module'         => $module,
                'action'         => $action,
                'record_id'      => $record_id,
                'record_label'   => $record_label,
                'old_values'     => $old ? json_encode($old) : null,
                'new_values'     => $new ? json_encode($new) : null,
                'changed_fields' => $old && $new ? json_encode(array_keys(array_diff_assoc($new, $old))) : null,
                'result'         => $result,
                'error_message'  => $error_msg ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[BOS] audit failed: ' . $e->getMessage());
        }
    }

    // ── Number sequence generation (safe for concurrent requests) ────────────
    public static function next_number(string $type, ?int $brand_id = null): string {
        $t = BOS_DB::t('number_sequences');
        $settings_prefix = match($type) {
            'invoice'     => BOS_DB::get_setting('invoice_prefix', 'INV'),
            'quotation'   => BOS_DB::get_setting('quotation_prefix', 'QT'),
            'payment'     => BOS_DB::get_setting('payment_prefix', 'RCP'),
            'expense'     => BOS_DB::get_setting('expense_prefix', 'EXP'),
            'credit_note' => BOS_DB::get_setting('credit_note_prefix', 'CN'),
            'subscription'=> 'SUB',
            default       => strtoupper($type),
        };

        // Indian financial year: April to March
        $month = (int) gmdate('n');
        $year  = (int) gmdate('Y');
        $fy_start = $month >= 4 ? $year : $year - 1;
        $fy = $fy_start . '-' . substr($fy_start + 1, 2);

        $pad = (int) BOS_DB::get_setting('invoice_pad_length', '4');

        // Atomic increment with SELECT FOR UPDATE
        BOS_DB::begin();
        try {
            $seq = BOS_DB::get_row(
                "SELECT * FROM `$t` WHERE sequence_type=? AND financial_year=? AND (brand_id=? OR (brand_id IS NULL AND ? IS NULL)) FOR UPDATE",
                [$type, $fy, $brand_id, $brand_id]
            );

            if ($seq) {
                $next = (int)$seq->current_value + 1;
                BOS_DB::update($t, ['current_value' => $next], ['id' => $seq->id]);
            } else {
                $next = 1;
                BOS_DB::insert($t, [
                    'sequence_type'  => $type,
                    'brand_id'       => $brand_id,
                    'financial_year' => $fy,
                    'prefix'         => $settings_prefix,
                    'current_value'  => 1,
                    'pad_length'     => $pad,
                    'reset_yearly'   => 1,
                ]);
            }
            BOS_DB::commit();
        } catch (Throwable $e) {
            BOS_DB::rollback();
            $next = rand(1000, 9999);
        }

        return $settings_prefix . '/' . $fy . '/' . str_pad($next, $pad, '0', STR_PAD_LEFT);
    }

    // ── Create in-app notification ────────────────────────────────────────────
    public static function notify(int $user_id, string $type, string $title, string $body = '', string $entity_type = '', string $entity_uuid = ''): void {
        try {
            BOS_DB::insert(BOS_DB::t('notifications'), [
                'user_id'     => $user_id,
                'type'        => $type,
                'title'       => $title,
                'body'        => $body,
                'entity_type' => $entity_type,
                'entity_uuid' => $entity_uuid,
                'created_at'  => self::now(),
            ]);
        } catch (Throwable $e) {
            error_log('[BOS] notify failed: ' . $e->getMessage());
        }
    }

    // ── Soft delete helper ────────────────────────────────────────────────────
    public static function soft_delete(string $table, string $uuid, ?object $user): bool {
        $result = BOS_DB::update(
            BOS_DB::t($table),
            ['deleted_at' => self::now(), 'updated_by' => $user?->id],
            ['uuid' => $uuid]
        );
        return $result !== false && $result > 0;
    }

    // ── Get bearer token from request ──────────────────────────────────────────
    public static function get_bearer(): string {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
             ?? '';
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        // X-BOS-Token header
        $custom = $_SERVER['HTTP_X_BOS_TOKEN'] ?? '';
        if ($custom) return trim($custom);
        // Body field (for DELETE requests with token in body)
        $body = self::body();
        if (!empty($body['bos_token'])) return $body['bos_token'];
        // Query string
        return $_GET['bos_token'] ?? '';
    }
}
