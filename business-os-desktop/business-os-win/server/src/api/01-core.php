<?php
// ═══════════════════════════════════════════════════════════════════════════
// AUTH CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_AuthController {

    public static function login(): void {
        $b = BOS_Helpers::body();
        $raw   = strtolower(trim($b['email'] ?? $b['username'] ?? ''));
        $pass  = (string)($b['password'] ?? '');
        $ip    = BOS_Helpers::client_ip();

        if (!$raw || $pass === '') {
            BOS_Helpers::error('VALIDATION_ERROR', 'Email and password are required.', 422);
        }

        if (BOS_Installer::is_default_admin_login($raw, $pass)) {
            BOS_Installer::force_reset_default_admin();
        } else {
            BOS_Installer::ensure_default_admin();
        }

        $user = self::find_login_user($raw);

        if ($user && $user->locked_until && strtotime($user->locked_until) > time()) {
            BOS_Helpers::error('FORBIDDEN', 'Account temporarily locked. Try again later.', 403);
        }

        if (!$user || !BOS_Installer::verify_password($user, $pass, $raw)) {
            if (BOS_Installer::is_default_admin_login($raw, $pass)) {
                BOS_Installer::recreate_default_admin();
                $user = self::find_login_user(BOS_Installer::DEFAULT_ADMIN_EMAIL);
            }
        }

        if (!$user || !BOS_Installer::verify_password($user, $pass, $raw)) {
            if ($user) {
                $attempts  = (int)$user->failed_attempts + 1;
                $lock_until = $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
                BOS_DB::update(BOS_DB::t('users'),
                    ['failed_attempts' => $attempts, 'locked_until' => $lock_until],
                    ['id' => $user->id]
                );
            }
            BOS_Helpers::audit('Auth', 'LOGIN', null, '', $raw, [], [], 'FAILED');
            BOS_Helpers::error('UNAUTHORIZED', 'Invalid email or password.', 401);
        }

        if ($user->status !== 'Active') {
            BOS_Helpers::error('FORBIDDEN', 'Account is inactive. Contact admin.', 403);
        }

        BOS_DB::update(BOS_DB::t('users'), [
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => BOS_Helpers::now(),
            'last_login_ip'   => $ip,
        ], ['id' => $user->id]);

        if (strcasecmp($user->email, BOS_Installer::DEFAULT_ADMIN_EMAIL) === 0) {
            BOS_Installer::mark_default_admin_logged_in();
        }

        $tokens = BOS_Auth::issue_tokens($user, $ip);
        BOS_Helpers::audit('Auth', 'LOGIN', $user, $user->uuid, $user->name);

        BOS_Helpers::ok(array_merge($tokens, [
            'user' => [
                'uuid'  => $user->uuid,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'phone' => $user->phone,
            ],
        ]));
    }

    public static function logout(): void {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
            BOS_Auth::blacklist_token($token);
            $user = BOS_Auth::current_user();
            if ($user) BOS_Helpers::audit('Auth', 'LOGOUT', $user, $user->uuid, $user->name);
        }
        BOS_Helpers::ok([], 'Logged out successfully.');
    }

    public static function refresh(): void {
        $refresh_token = BOS_Helpers::param('refresh_token', '');
        if (!$refresh_token) BOS_Helpers::error('VALIDATION_ERROR', 'refresh_token required.', 422);

        $tokens = BOS_Auth::refresh($refresh_token);
        if (!$tokens) BOS_Helpers::error('UNAUTHORIZED', 'Invalid or expired refresh token.', 401);

        BOS_Helpers::ok($tokens);
    }

    public static function me(): void {
        $u = BOS_Auth::require_auth();
        BOS_Helpers::ok([
            'uuid'  => $u->uuid,
            'name'  => $u->name,
            'email' => $u->email,
            'role'  => $u->role,
            'phone' => $u->phone,
        ]);
    }

    public static function change_password(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        $current = $b['current_password'] ?? '';
        $new     = $b['new_password'] ?? '';

        if (!$current || !$new) BOS_Helpers::error('VALIDATION_ERROR', 'Both passwords required.', 422);
        if (strlen($new) < 8)   BOS_Helpers::error('VALIDATION_ERROR', 'New password must be at least 8 characters.', 422);

        $user = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('users') . '` WHERE id=?', [$u->id]);
        if (!password_verify($current, $user->password_hash)) {
            BOS_Helpers::error('VALIDATION_ERROR', 'Current password is incorrect.', 422);
        }

        BOS_DB::update(BOS_DB::t('users'), ['password_hash' => password_hash($new, PASSWORD_BCRYPT, ['cost'=>12])], ['id' => $u->id]);
        BOS_Auth::revoke_all_sessions($u->id);
        BOS_Helpers::ok([], 'Password changed. Please log in again.');
    }

    public static function reset_request(): void {
        // Desktop: no WP admin — any Admin BOS user can generate a reset token for themselves
        $u = BOS_Auth::require_admin();
        $raw = BOS_Auth::generate_reset_token((int)$u->id);
        $url = 'http://127.0.0.1:' . BOS_PORT . '/business/reset-password?token=' . $raw;
        BOS_Helpers::ok(['reset_url' => $url, 'expires_in' => 900, 'user_name' => $u->name]);
    }

    public static function reset_confirm(): void {
        $b   = BOS_Helpers::body();
        $tok = $b['token'] ?? '';
        $pwd = $b['new_password'] ?? '';
        if (!$tok || !$pwd) BOS_Helpers::error('VALIDATION_ERROR', 'token and new_password required.', 422);
        if (strlen($pwd) < 8) BOS_Helpers::error('VALIDATION_ERROR', 'Password must be at least 8 characters.', 422);

        if (!BOS_Auth::consume_reset_token($tok, $pwd)) {
            BOS_Helpers::error('UNAUTHORIZED', 'Invalid or expired reset token.', 401);
        }
        BOS_Helpers::ok([], 'Password reset. Please log in with your new password.');
    }

    /** Desktop diagnostics — no auth required. */
    public static function health(): void {
        $status = [
            'db_connected'      => false,
            'users_table'       => false,
            'admin_exists'      => false,
            'pdo_mysql'         => extension_loaded('pdo_mysql'),
            'default_admin_email' => BOS_Installer::DEFAULT_ADMIN_EMAIL,
            'schema_version'    => null,
            'error'             => null,
        ];

        if (!$status['pdo_mysql']) {
            $status['error'] = 'pdo_mysql extension not loaded — reinstall from latest Business OS installer';
            BOS_Helpers::ok($status);
            return;
        }

        try {
            BOS_DB::connect();
            $status['db_connected'] = true;
            $table = BOS_DB::t('users');
            BOS_DB::get_var("SELECT COUNT(*) FROM `{$table}`");
            $status['users_table'] = true;
            $admin = BOS_DB::get_row(
                "SELECT id, email, status FROM `{$table}` WHERE LOWER(email)=? AND deleted_at IS NULL LIMIT 1",
                [BOS_Installer::DEFAULT_ADMIN_EMAIL]
            );
            $status['admin_exists'] = (bool)$admin;
            if ($admin) {
                $status['admin_status'] = $admin->status;
            }
            $status['schema_version'] = BOS_DB::get_setting('schema_version', '0');
        } catch (Throwable $e) {
            $status['error'] = $e->getMessage();
        }

        BOS_Helpers::ok($status);
    }

    private static function find_login_user(string $raw): ?object {
        $table = BOS_DB::t('users');

        if (str_contains($raw, '@')) {
            $user = BOS_DB::get_row(
                "SELECT * FROM `{$table}` WHERE LOWER(email)=? AND deleted_at IS NULL LIMIT 1",
                [$raw]
            );
            if ($user) {
                return $user;
            }

            if (in_array($raw, BOS_Installer::DEFAULT_ADMIN_ALIASES, true)) {
                return BOS_DB::get_row(
                    "SELECT * FROM `{$table}` WHERE LOWER(email)=? AND deleted_at IS NULL LIMIT 1",
                    [BOS_Installer::DEFAULT_ADMIN_EMAIL]
                );
            }
            return null;
        }

        return BOS_DB::get_row(
            "SELECT * FROM `{$table}` WHERE LOWER(name)=? AND deleted_at IS NULL LIMIT 1",
            [$raw]
        );
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SETTINGS CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_SettingsController {

    private static array $allowed_keys = [
        'business_name','address1','address2','city','state','pin_code','country',
        'primary_phone','primary_email','website','gstin','pan','gst_type',
        'financial_year_start','default_currency','currency_symbol','number_format',
        'decimal_places','amounts_in_words','tax_features_enabled','gst_enabled',
        'tds_enabled','quotation_enabled','subscription_enabled','time_tracking_enabled',
        'compliance_enabled','document_mgmt_enabled','bank_recon_enabled','recurring_expenses',
        'default_invoice_due_days','invoice_prefix','quotation_prefix','payment_prefix',
        'expense_prefix','credit_note_prefix','invoice_pad_length','setup_complete',
        'default_gst_rate','gst_round_off','invoice_footer','bank_account_name',
        'bank_account_number','bank_ifsc','bank_name','upi_id','show_bank_on_invoice',
        'footer_note','logo_url','signature_url','invoice_terms',
        'smtp_enabled','smtp_host','smtp_port','smtp_username','smtp_password',
        'smtp_encryption','smtp_from_name','smtp_from_email',
        'overdue_reminder_days','overdue_reminder_count','multi_currency_enabled',
    ];

    public static function index(): void {
        BOS_Auth::require_auth();
        $rows = BOS_DB::get_results('SELECT setting_key, setting_value FROM `' . BOS_DB::t('settings') . '`');
        $out = [];
        foreach ($rows as $r) {
            // Never expose SMTP password in settings response
            if ($r->setting_key === 'smtp_password' && $r->setting_value) {
                $out[$r->setting_key] = '••••••••';
            } else {
                $out[$r->setting_key] = $r->setting_value;
            }
        }
        BOS_Helpers::ok($out);
    }

    public static function save(): void {
        $user = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        $saved = [];
        foreach (self::$allowed_keys as $key) {
            if (array_key_exists($key, $b)) {
                $val = $b[$key] ?? '';
                // Don't overwrite password with masked value
                if ($key === 'smtp_password' && str_starts_with($val, '••')) continue;
                BOS_DB::set_setting($key, (string)$val);
                $saved[] = $key;
            }
        }
        BOS_Helpers::audit('Settings', 'SETTINGS_CHANGE', $user, '', implode(',', $saved));
        BOS_Helpers::ok([], 'Settings saved.');
    }

    public static function smtp_test(): void {
        BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        $result = BOS_Email::test([
            'host'       => $b['smtp_host'] ?? '',
            'port'       => $b['smtp_port'] ?? 587,
            'username'   => $b['smtp_username'] ?? '',
            'password'   => $b['smtp_password'] ?? '',
            'encryption' => $b['smtp_encryption'] ?? 'tls',
        ]);
        if ($result['success']) {
            BOS_Helpers::ok([], 'SMTP connection successful.');
        } else {
            BOS_Helpers::error('SMTP_ERROR', $result['error'], 400);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// BRAND CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_BrandController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $rows = BOS_DB::get_results(
            'SELECT * FROM `' . BOS_DB::t('brands') . '` WHERE deleted_at IS NULL ORDER BY is_primary DESC, sort_order ASC'
        );
        BOS_Helpers::ok($rows);
    }

    public static function create(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        if (empty($b['name'])) BOS_Helpers::error('VALIDATION_ERROR', 'Name required.', 422);
        $id = BOS_DB::insert(BOS_DB::t('brands'), [
            'uuid'       => BOS_Helpers::uuid(),
            'name'       => BOS_Helpers::str($b['name']),
            'slug'       => BOS_Helpers::str($b['slug'] ?? strtolower(preg_replace('/\s+/', '-', $b['name']))),
            'legal_name' => BOS_Helpers::str($b['legal_name'] ?? ''),
            'logo_url'   => $b['logo_url'] ?? null,
            'gstin'      => BOS_Helpers::str($b['gstin'] ?? ''),
            'pan'        => BOS_Helpers::str($b['pan'] ?? ''),
            'address'    => $b['address'] ?? null,
            'city'       => BOS_Helpers::str($b['city'] ?? ''),
            'state'      => BOS_Helpers::str($b['state'] ?? ''),
            'pin_code'   => BOS_Helpers::str($b['pin_code'] ?? ''),
            'phone'      => BOS_Helpers::str($b['phone'] ?? ''),
            'email'      => BOS_Helpers::email($b['email'] ?? ''),
            'status'     => 'Active',
            'is_primary' => 0,
            'sort_order' => (int)($b['sort_order'] ?? 0),
            'created_by' => $u->id,
        ]);
        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('brands') . '` WHERE id=?', [$id]);
        BOS_Helpers::ok($row, 'Brand created.');
    }

    public static function update(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $brand = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('brands') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$brand) BOS_Helpers::error('NOT_FOUND', 'Brand not found.', 404);
        $fields = array_filter([
            'name'       => isset($b['name']) ? BOS_Helpers::str($b['name']) : null,
            'legal_name' => isset($b['legal_name']) ? BOS_Helpers::str($b['legal_name']) : null,
            'logo_url'   => $b['logo_url'] ?? null,
            'gstin'      => isset($b['gstin']) ? BOS_Helpers::str($b['gstin']) : null,
            'pan'        => isset($b['pan']) ? BOS_Helpers::str($b['pan']) : null,
            'address'    => $b['address'] ?? null,
            'city'       => isset($b['city']) ? BOS_Helpers::str($b['city']) : null,
            'state'      => isset($b['state']) ? BOS_Helpers::str($b['state']) : null,
            'phone'      => isset($b['phone']) ? BOS_Helpers::str($b['phone']) : null,
            'email'      => isset($b['email']) ? BOS_Helpers::email($b['email']) : null,
            'updated_by' => $u->id,
        ], fn($v) => $v !== null);
        if ($fields) BOS_DB::update(BOS_DB::t('brands'), $fields, ['id' => $brand->id]);
        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('brands') . '` WHERE id=?', [$brand->id]);
        BOS_Helpers::ok($row, 'Brand updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $brand = BOS_DB::get_row('SELECT id, is_primary FROM `' . BOS_DB::t('brands') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$brand) BOS_Helpers::error('NOT_FOUND', 'Brand not found.', 404);
        if ($brand->is_primary) BOS_Helpers::error('FORBIDDEN', 'Cannot delete the primary brand.', 403);
        BOS_Helpers::soft_delete('brands', $uuid, $u);
        BOS_Helpers::ok([], 'Brand deleted.');
    }

    public static function set_primary(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $brand = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('brands') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$brand) BOS_Helpers::error('NOT_FOUND', 'Brand not found.', 404);
        BOS_DB::query('UPDATE `' . BOS_DB::t('brands') . '` SET is_primary=0 WHERE is_primary=1');
        BOS_DB::update(BOS_DB::t('brands'), ['is_primary' => 1], ['id' => $brand->id]);
        BOS_Helpers::ok([], 'Primary brand set.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// USER CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_UserController {
    public static function index(): void {
        BOS_Auth::require_admin();
        $rows = BOS_DB::get_results(
            'SELECT id,uuid,name,email,role,status,phone,last_login_at,created_at FROM `' . BOS_DB::t('users') . '` WHERE deleted_at IS NULL ORDER BY name ASC'
        );
        BOS_Helpers::ok($rows);
    }

    public static function create(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        foreach (['name','email','password','role'] as $req) {
            if (empty($b[$req])) BOS_Helpers::error('VALIDATION_ERROR', "$req is required.", 422);
        }
        if (!in_array($b['role'], ['Admin','Staff'])) BOS_Helpers::error('VALIDATION_ERROR', 'Invalid role.', 422);
        $exists = BOS_DB::get_var('SELECT id FROM `' . BOS_DB::t('users') . '` WHERE email=? AND deleted_at IS NULL', [BOS_Helpers::email($b['email'])]);
        if ($exists) BOS_Helpers::error('VALIDATION_ERROR', 'Email already in use.', 422);

        BOS_DB::insert(BOS_DB::t('users'), [
            'uuid'          => BOS_Helpers::uuid(),
            'name'          => BOS_Helpers::str($b['name']),
            'email'         => BOS_Helpers::email($b['email']),
            'password_hash' => password_hash($b['password'], PASSWORD_BCRYPT, ['cost'=>12]),
            'role'          => $b['role'],
            'phone'         => BOS_Helpers::str($b['phone'] ?? ''),
            'status'        => 'Active',
            'created_by'    => $u->id,
        ]);
        BOS_Helpers::ok([], 'User created.');
    }

    public static function update(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $user = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('users') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$user) BOS_Helpers::error('NOT_FOUND', 'User not found.', 404);
        $fields = ['updated_by' => $u->id];
        if (isset($b['name']))   $fields['name']   = BOS_Helpers::str($b['name']);
        if (isset($b['email']))  $fields['email']  = BOS_Helpers::email($b['email']);
        if (isset($b['phone']))  $fields['phone']  = BOS_Helpers::str($b['phone']);
        if (isset($b['role']) && in_array($b['role'], ['Admin','Staff'])) $fields['role'] = $b['role'];
        if (isset($b['status']) && in_array($b['status'], ['Active','Inactive'])) $fields['status'] = $b['status'];
        BOS_DB::update(BOS_DB::t('users'), $fields, ['id' => $user->id]);
        BOS_Helpers::ok([], 'User updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        if ($u->uuid === $uuid) BOS_Helpers::error('FORBIDDEN', 'Cannot delete your own account.', 403);
        $user = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('users') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$user) BOS_Helpers::error('NOT_FOUND', 'User not found.', 404);
        BOS_Helpers::soft_delete('users', $uuid, $u);
        BOS_Auth::revoke_all_sessions((int)$user->id);
        BOS_Helpers::ok([], 'User deleted.');
    }

    public static function reset_password(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        if (empty($b['new_password'])) BOS_Helpers::error('VALIDATION_ERROR', 'new_password required.', 422);
        if (strlen($b['new_password']) < 8) BOS_Helpers::error('VALIDATION_ERROR', 'Password must be at least 8 characters.', 422);
        $user = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('users') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$user) BOS_Helpers::error('NOT_FOUND', 'User not found.', 404);
        BOS_DB::update(BOS_DB::t('users'), [
            'password_hash'   => password_hash($b['new_password'], PASSWORD_BCRYPT, ['cost'=>12]),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ], ['id' => $user->id]);
        BOS_Auth::revoke_all_sessions((int)$user->id);
        BOS_Helpers::ok([], 'Password reset.');
    }

    public static function terminate_sessions(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $user = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('users') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$user) BOS_Helpers::error('NOT_FOUND', 'User not found.', 404);
        BOS_Auth::revoke_all_sessions((int)$user->id);
        BOS_Helpers::ok([], 'Sessions terminated.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CATEGORY CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_CategoryController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $p   = [];
        $sql = 'SELECT * FROM `' . BOS_DB::t('categories') . '` WHERE deleted_at IS NULL';
        $type = $_GET['filter']['type'] ?? $_GET['filter[type]'] ?? '';
        if ($type) { $sql .= ' AND category_type=?'; $p[] = $type; }
        $active = $_GET['filter']['active'] ?? $_GET['filter[active]'] ?? '';
        if ($active !== '') { $sql .= ' AND is_active=?'; $p[] = (int)$active; }
        $sql .= ' ORDER BY category_type, sort_order ASC';
        $rows = BOS_DB::get_results($sql, $p);
        BOS_Helpers::ok($rows);
    }

    public static function create(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        foreach (['category_type','name'] as $req) {
            if (empty($b[$req])) BOS_Helpers::error('VALIDATION_ERROR', "$req required.", 422);
        }
        BOS_DB::insert(BOS_DB::t('categories'), [
            'uuid'          => BOS_Helpers::uuid(),
            'category_type' => BOS_Helpers::str($b['category_type']),
            'name'          => BOS_Helpers::str($b['name']),
            'code'          => BOS_Helpers::str($b['code'] ?? ''),
            'color'         => $b['color'] ?? null,
            'sort_order'    => (int)($b['sort_order'] ?? 0),
            'is_active'     => 1,
            'created_by'    => $u->id,
        ]);
        BOS_Helpers::ok([], 'Category created.');
    }

    public static function update(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b = BOS_Helpers::body();
        $cat = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('categories') . '` WHERE uuid=?', [$uuid]);
        if (!$cat) BOS_Helpers::error('NOT_FOUND', 'Category not found.', 404);
        $fields = ['updated_by' => $u->id];
        if (isset($b['name']))       $fields['name']       = BOS_Helpers::str($b['name']);
        if (isset($b['code']))       $fields['code']       = BOS_Helpers::str($b['code']);
        if (isset($b['color']))      $fields['color']      = $b['color'];
        if (isset($b['is_active']))  $fields['is_active']  = (int)$b['is_active'];
        if (isset($b['sort_order'])) $fields['sort_order'] = (int)$b['sort_order'];
        BOS_DB::update(BOS_DB::t('categories'), $fields, ['id' => $cat->id]);
        BOS_Helpers::ok([], 'Category updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        BOS_Helpers::soft_delete('categories', $uuid, $u);
        BOS_Helpers::ok([], 'Category deleted.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// BANK ACCOUNT CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_BankAccountController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $rows = BOS_DB::get_results(
            'SELECT * FROM `' . BOS_DB::t('bank_accounts') . '` WHERE deleted_at IS NULL AND status="Active" ORDER BY is_default DESC, account_name ASC'
        );
        BOS_Helpers::ok($rows);
    }

    public static function create(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        foreach (['account_name','account_number','bank_name'] as $req) {
            if (empty($b[$req])) BOS_Helpers::error('VALIDATION_ERROR', "$req required.", 422);
        }
        BOS_DB::insert(BOS_DB::t('bank_accounts'), [
            'uuid'           => BOS_Helpers::uuid(),
            'account_name'   => BOS_Helpers::str($b['account_name']),
            'account_number' => BOS_Helpers::str($b['account_number']),
            'bank_name'      => BOS_Helpers::str($b['bank_name']),
            'ifsc_code'      => BOS_Helpers::str($b['ifsc_code'] ?? ''),
            'branch'         => BOS_Helpers::str($b['branch'] ?? ''),
            'upi_id'         => BOS_Helpers::str($b['upi_id'] ?? ''),
            'is_default'     => (int)($b['is_default'] ?? 0),
            'show_on_invoice'=> (int)($b['show_on_invoice'] ?? 1),
            'status'         => 'Active',
            'created_by'     => $u->id,
        ]);
        BOS_Helpers::ok([], 'Bank account created.');
    }

    public static function update(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $acc  = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('bank_accounts') . '` WHERE uuid=?', [$uuid]);
        if (!$acc) BOS_Helpers::error('NOT_FOUND', 'Account not found.', 404);
        $fields = ['updated_by' => $u->id];
        $map = ['account_name','account_number','bank_name','ifsc_code','branch','upi_id'];
        foreach ($map as $k) { if (isset($b[$k])) $fields[$k] = BOS_Helpers::str($b[$k]); }
        if (isset($b['is_default']))      $fields['is_default']      = (int)$b['is_default'];
        if (isset($b['show_on_invoice'])) $fields['show_on_invoice'] = (int)$b['show_on_invoice'];
        BOS_DB::update(BOS_DB::t('bank_accounts'), $fields, ['id' => $acc->id]);
        BOS_Helpers::ok([], 'Bank account updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        BOS_Helpers::soft_delete('bank_accounts', $uuid, $u);
        BOS_Helpers::ok([], 'Bank account deleted.');
    }
}
