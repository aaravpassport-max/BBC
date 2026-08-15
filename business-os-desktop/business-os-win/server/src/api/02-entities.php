<?php
// ═══════════════════════════════════════════════════════════════════════════
// CLIENT CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ClientController {

    private static function client_fields(array $b, object $user, bool $is_new = true): array {
        $f = [
            'client_type'    => BOS_Helpers::str($b['client_type'] ?? 'Business'),
            'display_name'   => BOS_Helpers::str($b['display_name'] ?? ''),
            'first_name'     => BOS_Helpers::str($b['first_name'] ?? ''),
            'last_name'      => BOS_Helpers::str($b['last_name'] ?? ''),
            'business_name'  => BOS_Helpers::str($b['business_name'] ?? ''),
            'primary_email'  => BOS_Helpers::email($b['primary_email'] ?? ''),
            'secondary_email'=> BOS_Helpers::email($b['secondary_email'] ?? ''),
            'primary_phone'  => BOS_Helpers::str($b['primary_phone'] ?? ''),
            'secondary_phone'=> BOS_Helpers::str($b['secondary_phone'] ?? ''),
            'whatsapp'       => BOS_Helpers::str($b['whatsapp'] ?? ''),
            'website'        => BOS_Helpers::str($b['website'] ?? ''),
            'address1'       => BOS_Helpers::str($b['address1'] ?? ''),
            'address2'       => BOS_Helpers::str($b['address2'] ?? ''),
            'city'           => BOS_Helpers::str($b['city'] ?? ''),
            'state'          => BOS_Helpers::str($b['state'] ?? ''),
            'pin_code'       => BOS_Helpers::str($b['pin_code'] ?? ''),
            'country'        => BOS_Helpers::str($b['country'] ?? 'India'),
            'pan'            => strtoupper(BOS_Helpers::str($b['pan'] ?? '')),
            'gstin'          => strtoupper(BOS_Helpers::str($b['gstin'] ?? '')),
            'gst_type'       => BOS_Helpers::str($b['gst_type'] ?? ''),
            'tds_applicable' => (int)($b['tds_applicable'] ?? 0),
            'tds_rate'       => isset($b['tds_rate']) ? (float)$b['tds_rate'] : null,
            'tds_section'    => BOS_Helpers::str($b['tds_section'] ?? ''),
            'credit_limit'   => (float)($b['credit_limit'] ?? 0),
            'lead_source'    => BOS_Helpers::str($b['lead_source'] ?? ''),
            'client_since'   => $b['client_since'] ?? null,
            'status'         => BOS_Helpers::str($b['status'] ?? 'Active'),
            'internal_notes' => $b['internal_notes'] ?? null,
            'updated_by'     => $user->id,
        ];
        if ($is_new) {
            $f['uuid']       = BOS_Helpers::uuid();
            $f['created_by'] = $user->id;
        }
        return $f;
    }

    public static function index(): void {
        BOS_Auth::require_auth();
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;

        $where  = ['deleted_at IS NULL'];
        $params = [];

        $search = $_GET['search'] ?? '';
        if ($search) {
            $like = '%' . $search . '%';
            $where[] = '(display_name LIKE ? OR primary_email LIKE ? OR primary_phone LIKE ? OR gstin LIKE ? OR pan LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $status = $_GET['filter']['status'] ?? $_GET['filter[status]'] ?? '';
        if ($status) { $where[] = 'status=?'; $params[] = $status; }

        $w   = implode(' AND ', $where);
        $t   = BOS_DB::t('clients');
        $total = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE $w", $params);
        $rows  = BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY display_name ASC LIMIT ? OFFSET ?",
            [...$params, $per_page, $offset]
        );
        BOS_Helpers::paginated($rows, $total, $page, $per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $c = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$c) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);
        BOS_Helpers::ok($c);
    }

    public static function create(): void {
        $user = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        if (empty($b['display_name'])) BOS_Helpers::error('VALIDATION_ERROR', 'display_name required.', 422);
        $fields = self::client_fields($b, $user, true);
        BOS_DB::insert(BOS_DB::t('clients'), $fields);
        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$fields['uuid']]);
        BOS_Helpers::audit('Clients', 'CREATE', $user, $fields['uuid'], $b['display_name']);
        BOS_Helpers::ok($row, 'Client created.');
    }

    public static function update(): void {
        $user = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $c    = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$c) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);
        $fields = self::client_fields($b, $user, false);
        BOS_DB::update(BOS_DB::t('clients'), $fields, ['id' => $c->id]);
        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE id=?', [$c->id]);
        BOS_Helpers::ok($row, 'Client updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $c = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$c) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);
        BOS_Helpers::soft_delete('clients', $uuid, $u);
        BOS_Helpers::ok([], 'Client archived.');
    }

    public static function merge(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();
        $from_uuid = $b['source_uuid'] ?? '';
        $into_uuid = $b['target_uuid'] ?? '';
        if (!$from_uuid || !$into_uuid) BOS_Helpers::error('VALIDATION_ERROR', 'source_uuid and target_uuid required.', 422);
        $from = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$from_uuid]);
        $into = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$into_uuid]);
        if (!$from || !$into) BOS_Helpers::error('NOT_FOUND', 'One or both clients not found.', 404);
        // Reassign all invoices, payments, quotations, expenses, subscriptions
        foreach (['invoices','payments','quotations','expenses','subscriptions','time_entries','communication_logs','credit_notes'] as $table) {
            BOS_DB::query("UPDATE `" . BOS_DB::t($table) . "` SET client_id=? WHERE client_id=?", [$into->id, $from->id]);
        }
        BOS_Helpers::soft_delete('clients', $from_uuid, $u);
        BOS_Helpers::ok([], 'Clients merged.');
    }

    public static function financial_summary(): void {
        BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $c = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$c) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);

        $inv = BOS_DB::get_row(
            'SELECT COUNT(*) as total_invoices, COALESCE(SUM(grand_total),0) as total_billed, COALESCE(SUM(amount_paid),0) as total_paid, COALESCE(SUM(grand_total-amount_paid),0) as outstanding
             FROM `' . BOS_DB::t('invoices') . '` WHERE client_id=? AND deleted_at IS NULL AND status NOT IN ("Cancelled")',
            [$c->id]
        );
        $pay = BOS_DB::get_row(
            'SELECT COUNT(*) as total_payments, COALESCE(SUM(amount_received),0) as total_received FROM `' . BOS_DB::t('payments') . '` WHERE client_id=? AND deleted_at IS NULL AND is_reversed=0',
            [$c->id]
        );
        BOS_Helpers::ok([
            'total_invoices'  => (int)$inv->total_invoices,
            'total_billed'    => (float)$inv->total_billed,
            'total_paid'      => (float)$inv->total_paid,
            'outstanding'     => (float)$inv->outstanding,
            'total_payments'  => (int)$pay->total_payments,
            'total_received'  => (float)$pay->total_received,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// VENDOR CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_VendorController {

    private static function fields(array $b, object $user, bool $is_new = true): array {
        $f = [
            'vendor_type'         => BOS_Helpers::str($b['vendor_type'] ?? 'Supplier'),
            'display_name'        => BOS_Helpers::str($b['display_name'] ?? ''),
            'business_name'       => BOS_Helpers::str($b['business_name'] ?? ''),
            'contact_person'      => BOS_Helpers::str($b['contact_person'] ?? ''),
            'primary_email'       => BOS_Helpers::email($b['primary_email'] ?? ''),
            'primary_phone'       => BOS_Helpers::str($b['primary_phone'] ?? ''),
            'address1'            => BOS_Helpers::str($b['address1'] ?? ''),
            'city'                => BOS_Helpers::str($b['city'] ?? ''),
            'state'               => BOS_Helpers::str($b['state'] ?? ''),
            'pin_code'            => BOS_Helpers::str($b['pin_code'] ?? ''),
            'country'             => BOS_Helpers::str($b['country'] ?? 'India'),
            'pan'                 => strtoupper(BOS_Helpers::str($b['pan'] ?? '')),
            'gstin'               => strtoupper(BOS_Helpers::str($b['gstin'] ?? '')),
            'gst_type'            => BOS_Helpers::str($b['gst_type'] ?? ''),
            'tds_applicable'      => (int)($b['tds_applicable'] ?? 0),
            'tds_rate'            => isset($b['tds_rate']) ? (float)$b['tds_rate'] : null,
            'tds_section'         => BOS_Helpers::str($b['tds_section'] ?? ''),
            'bank_account_name'   => BOS_Helpers::str($b['bank_account_name'] ?? ''),
            'bank_account_number' => BOS_Helpers::str($b['bank_account_number'] ?? ''),
            'bank_ifsc'           => strtoupper(BOS_Helpers::str($b['bank_ifsc'] ?? '')),
            'bank_name'           => BOS_Helpers::str($b['bank_name'] ?? ''),
            'upi_id'              => BOS_Helpers::str($b['upi_id'] ?? ''),
            'status'              => BOS_Helpers::str($b['status'] ?? 'Active'),
            'internal_notes'      => $b['internal_notes'] ?? null,
            'updated_by'          => $user->id,
        ];
        if ($is_new) { $f['uuid'] = BOS_Helpers::uuid(); $f['created_by'] = $user->id; }
        return $f;
    }

    public static function index(): void {
        BOS_Auth::require_auth();
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;
        $where = ['deleted_at IS NULL']; $params = [];
        $search = $_GET['search'] ?? '';
        if ($search) { $like = '%'.$search.'%'; $where[] = '(display_name LIKE ? OR primary_email LIKE ?)'; $params = [$like, $like]; }
        $status = $_GET['filter']['status'] ?? $_GET['filter[status]'] ?? '';
        if ($status) { $where[] = 'status=?'; $params[] = $status; }
        $w = implode(' AND ', $where); $t = BOS_DB::t('vendors');
        $total = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE $w", $params);
        $rows  = BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY display_name ASC LIMIT ? OFFSET ?", [...$params, $per_page, $offset]);
        BOS_Helpers::paginated($rows, $total, $page, $per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $v = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('vendors') . '` WHERE uuid=? AND deleted_at IS NULL', [$_GET['uuid']]);
        if (!$v) BOS_Helpers::error('NOT_FOUND', 'Vendor not found.', 404);
        BOS_Helpers::ok($v);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        if (empty($b['display_name'])) BOS_Helpers::error('VALIDATION_ERROR', 'display_name required.', 422);
        $fields = self::fields($b, $u, true);
        BOS_DB::insert(BOS_DB::t('vendors'), $fields);
        BOS_Helpers::ok([], 'Vendor created.');
    }

    public static function update(): void {
        $u = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $v = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('vendors') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$v) BOS_Helpers::error('NOT_FOUND', 'Vendor not found.', 404);
        BOS_DB::update(BOS_DB::t('vendors'), self::fields(BOS_Helpers::body(), $u, false), ['id' => $v->id]);
        BOS_Helpers::ok([], 'Vendor updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        BOS_Helpers::soft_delete('vendors', $uuid, $u);
        BOS_Helpers::ok([], 'Vendor archived.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SERVICE CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ServiceController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $where = ['deleted_at IS NULL']; $params = [];
        $status = $_GET['filter']['status'] ?? $_GET['filter[status]'] ?? 'Active';
        if ($status !== '') { $where[] = 'status=?'; $params[] = $status; }
        $w = implode(' AND ', $where); $t = BOS_DB::t('services');
        $rows = BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY sort_order ASC, name ASC", $params);
        BOS_Helpers::ok($rows);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $s = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('services') . '` WHERE uuid=? AND deleted_at IS NULL', [$_GET['uuid']]);
        if (!$s) BOS_Helpers::error('NOT_FOUND', 'Service not found.', 404);
        BOS_Helpers::ok($s);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        if (empty($b['name'])) BOS_Helpers::error('VALIDATION_ERROR', 'name required.', 422);
        $uuid = BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('services'), [
            'uuid'          => $uuid,
            'name'          => BOS_Helpers::str($b['name']),
            'code'          => strtoupper(BOS_Helpers::str($b['code'] ?? '')),
            'description'   => $b['description'] ?? null,
            'unit_type'     => BOS_Helpers::str($b['unit_type'] ?? 'Fixed'),
            'default_price' => (float)($b['default_price'] ?? 0),
            'minimum_price' => isset($b['minimum_price']) ? (float)$b['minimum_price'] : null,
            'hsn_sac_code'  => BOS_Helpers::str($b['hsn_sac_code'] ?? ''),
            'gst_rate'      => isset($b['gst_rate']) ? (float)$b['gst_rate'] : null,
            'gst_inclusive' => (int)($b['gst_inclusive'] ?? 0),
            'tds_applicable'=> (int)($b['tds_applicable'] ?? 0),
            'tds_section'   => BOS_Helpers::str($b['tds_section'] ?? ''),
            'status'        => 'Active',
            'is_active'     => 1,
            'sort_order'    => (int)($b['sort_order'] ?? 0),
            'created_by'    => $u->id,
        ]);
        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('services') . '` WHERE uuid=?', [$uuid]);
        BOS_Helpers::ok($row, 'Service created.');
    }

    public static function update(): void {
        $u = BOS_Auth::require_auth();
        $uuid = $_GET['uuid']; $b = BOS_Helpers::body();
        $s = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('services') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$s) BOS_Helpers::error('NOT_FOUND', 'Service not found.', 404);
        $fields = ['updated_by' => $u->id];
        $map = ['name','code','description','unit_type','hsn_sac_code','tds_section'];
        foreach ($map as $k) { if (isset($b[$k])) $fields[$k] = BOS_Helpers::str($b[$k]); }
        if (isset($b['default_price'])) $fields['default_price'] = (float)$b['default_price'];
        if (isset($b['gst_rate']))      $fields['gst_rate']      = (float)$b['gst_rate'];
        if (isset($b['gst_inclusive'])) $fields['gst_inclusive'] = (int)$b['gst_inclusive'];
        if (isset($b['tds_applicable']))$fields['tds_applicable']= (int)$b['tds_applicable'];
        if (isset($b['is_active'])) {
            $fields['is_active'] = (int)$b['is_active'];
            $fields['status']    = $b['is_active'] ? 'Active' : 'Inactive';
        }
        BOS_DB::update(BOS_DB::t('services'), $fields, ['id' => $s->id]);
        BOS_Helpers::ok([], 'Service updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_auth();
        BOS_Helpers::soft_delete('services', $_GET['uuid'], $u);
        BOS_Helpers::ok([], 'Service archived.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SERVICE RATE CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ServiceRateController {
    public static function resolve(): void {
        BOS_Auth::require_auth();
        $service_uuid = $_GET['service_uuid'] ?? '';
        $client_uuid  = $_GET['client_uuid'] ?? '';

        $service = BOS_DB::get_row('SELECT id, default_price FROM `' . BOS_DB::t('services') . '` WHERE uuid=?', [$service_uuid]);
        if (!$service) BOS_Helpers::error('NOT_FOUND', 'Service not found.', 404);

        $client_id = null;
        if ($client_uuid) {
            $c = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$client_uuid]);
            if ($c) $client_id = $c->id;
        }

        $price = $service->default_price;
        if ($client_id) {
            $rate = BOS_DB::get_row(
                'SELECT price FROM `' . BOS_DB::t('service_rates') . '` WHERE service_id=? AND client_id=? AND effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE()) ORDER BY effective_from DESC LIMIT 1',
                [$service->id, $client_id]
            );
            if ($rate) $price = $rate->price;
        }
        BOS_Helpers::ok(['price' => (float)$price]);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        BOS_DB::insert(BOS_DB::t('service_rates'), [
            'service_id'     => (int)$b['service_id'],
            'client_id'      => isset($b['client_id']) ? (int)$b['client_id'] : null,
            'price'          => (float)$b['price'],
            'effective_from' => $b['effective_from'] ?? BOS_Helpers::today(),
            'effective_to'   => $b['effective_to'] ?? null,
        ]);
        BOS_Helpers::ok([], 'Rate created.');
    }

    public static function delete(): void {
        BOS_Auth::require_admin();
        BOS_DB::delete(BOS_DB::t('service_rates'), ['id' => (int)$_GET['id']]);
        BOS_Helpers::ok([], 'Rate deleted.');
    }
}
