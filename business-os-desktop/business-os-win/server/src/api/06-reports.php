<?php
// ═══════════════════════════════════════════════════════════════════════════
// DASHBOARD CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_DashboardController {

    public static function index(): void {
        BOS_Auth::require_auth();
        $ti = BOS_DB::t('invoices');
        $tp = BOS_DB::t('payments');
        $te = BOS_DB::t('expenses');
        $tc = BOS_DB::t('clients');

        $month_start = date('Y-m-01');
        $today = BOS_Helpers::today();

        $total_clients   = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$tc` WHERE deleted_at IS NULL AND status='Active'");
        $total_invoices  = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$ti` WHERE deleted_at IS NULL");
        $revenue_month   = (float) BOS_DB::get_var("SELECT COALESCE(SUM(amount_paid),0) FROM `$ti` WHERE deleted_at IS NULL AND invoice_date >= ?", [$month_start]);
        $outstanding     = (float) BOS_DB::get_var("SELECT COALESCE(SUM(amount_outstanding),0) FROM `$ti` WHERE deleted_at IS NULL AND status IN('Sent','Partially_Paid','Overdue')");
        $expenses_month  = (float) BOS_DB::get_var("SELECT COALESCE(SUM(amount),0) FROM `$te` WHERE deleted_at IS NULL AND expense_date >= ?", [$month_start]);
        $overdue_count   = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$ti` WHERE deleted_at IS NULL AND status IN('Sent','Partially_Paid') AND due_date < ?", [$today]);

        // Recent invoices
        $recent_invoices = BOS_DB::get_results(
            "SELECT uuid, invoice_number, client_name, grand_total, status, due_date FROM `$ti` WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5"
        );

        BOS_Helpers::ok([
            'total_clients'   => $total_clients,
            'total_invoices'  => $total_invoices,
            'revenue_month'   => $revenue_month,
            'outstanding'     => $outstanding,
            'expenses_month'  => $expenses_month,
            'overdue_count'   => $overdue_count,
            'recent_invoices' => $recent_invoices,
        ]);
    }

    public static function charts(): void {
        BOS_Auth::require_auth();
        $ti = BOS_DB::t('invoices');
        $te = BOS_DB::t('expenses');

        // Last 6 months revenue vs expenses
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-$i months"));
        }

        $revenue_data = [];
        $expense_data = [];
        foreach ($months as $m) {
            $start = $m . '-01';
            $end   = date('Y-m-t', strtotime($start));
            $rev = (float) BOS_DB::get_var("SELECT COALESCE(SUM(amount_paid),0) FROM `$ti` WHERE deleted_at IS NULL AND invoice_date BETWEEN ? AND ?", [$start, $end]);
            $exp = (float) BOS_DB::get_var("SELECT COALESCE(SUM(amount),0) FROM `$te` WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?", [$start, $end]);
            $revenue_data[] = ['month' => $m, 'amount' => $rev];
            $expense_data[] = ['month' => $m, 'amount' => $exp];
        }

        // Invoice status breakdown
        $status_rows = BOS_DB::get_results(
            "SELECT status, COUNT(*) as count, COALESCE(SUM(grand_total),0) as total FROM `$ti` WHERE deleted_at IS NULL GROUP BY status"
        );

        BOS_Helpers::ok([
            'revenue_trend'  => $revenue_data,
            'expense_trend'  => $expense_data,
            'invoice_status' => $status_rows,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// REPORT CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ReportController {

    private static function date_params(): array {
        $from = $_GET['date_from'] ?? date('Y-04-01', strtotime(date('n') >= 4 ? 'now' : '-1 year'));
        $to   = $_GET['date_to']   ?? BOS_Helpers::today();
        return [$from, $to];
    }

    public static function profit_loss(): void {
        BOS_Auth::require_auth();
        [$from, $to] = self::date_params();

        $revenue = (float) BOS_DB::get_var(
            'SELECT COALESCE(SUM(amount_paid),0) FROM `' . BOS_DB::t('invoices') . '` WHERE deleted_at IS NULL AND invoice_date BETWEEN ? AND ? AND status NOT IN("Cancelled")',
            [$from, $to]
        );
        $expenses = (float) BOS_DB::get_var(
            'SELECT COALESCE(SUM(amount),0) FROM `' . BOS_DB::t('expenses') . '` WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?',
            [$from, $to]
        );

        $revenue_by_cat = BOS_DB::get_results(
            'SELECT c.name as category, COALESCE(SUM(i.amount_paid),0) as total
             FROM `' . BOS_DB::t('invoices') . '` i
             LEFT JOIN `' . BOS_DB::t('categories') . '` c ON c.id=i.client_id
             WHERE i.deleted_at IS NULL AND i.invoice_date BETWEEN ? AND ? AND i.status NOT IN("Cancelled")
             GROUP BY i.client_id ORDER BY total DESC LIMIT 10',
            [$from, $to]
        );

        $expense_by_cat = BOS_DB::get_results(
            'SELECT c.name as category, COALESCE(SUM(e.amount),0) as total
             FROM `' . BOS_DB::t('expenses') . '` e
             LEFT JOIN `' . BOS_DB::t('categories') . '` c ON c.id=e.category_id
             WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
             GROUP BY e.category_id ORDER BY total DESC',
            [$from, $to]
        );

        BOS_Helpers::ok([
            'period'         => ['from' => $from, 'to' => $to],
            'revenue'        => $revenue,
            'expenses'       => $expenses,
            'net_profit'     => $revenue - $expenses,
            'revenue_by_cat' => $revenue_by_cat,
            'expense_by_cat' => $expense_by_cat,
        ]);
    }

    public static function gst_summary(): void {
        BOS_Auth::require_auth();
        [$from, $to] = self::date_params();

        $rows = BOS_DB::get_results(
            'SELECT invoice_date, invoice_number, client_name, client_gstin, is_igst,
                    taxable_amount, total_cgst, total_sgst, total_igst,
                    (total_cgst+total_sgst+total_igst) as total_gst, grand_total
             FROM `' . BOS_DB::t('invoices') . '`
             WHERE deleted_at IS NULL AND invoice_date BETWEEN ? AND ? AND status NOT IN("Draft","Cancelled")
             ORDER BY invoice_date ASC',
            [$from, $to]
        );

        $totals = BOS_DB::get_row(
            'SELECT COALESCE(SUM(taxable_amount),0) as taxable,COALESCE(SUM(total_cgst),0) as cgst,COALESCE(SUM(total_sgst),0) as sgst,COALESCE(SUM(total_igst),0) as igst,COALESCE(SUM(grand_total),0) as grand
             FROM `' . BOS_DB::t('invoices') . '` WHERE deleted_at IS NULL AND invoice_date BETWEEN ? AND ? AND status NOT IN("Draft","Cancelled")',
            [$from, $to]
        );

        BOS_Helpers::ok([
            'period'   => ['from' => $from, 'to' => $to],
            'invoices' => $rows,
            'totals'   => $totals,
        ]);
    }

    public static function client_statement(): void {
        BOS_Auth::require_auth();
        [$from, $to] = self::date_params();
        $client_uuid = $_GET['client_uuid'] ?? '';
        if (!$client_uuid) BOS_Helpers::error('VALIDATION_ERROR', 'client_uuid required.', 422);

        $c = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$client_uuid]);
        if (!$c) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);

        $invoices = BOS_DB::get_results(
            'SELECT invoice_number, invoice_date, due_date, grand_total, amount_paid, amount_outstanding, status
             FROM `' . BOS_DB::t('invoices') . '` WHERE client_id=? AND deleted_at IS NULL AND invoice_date BETWEEN ? AND ? ORDER BY invoice_date ASC',
            [$c->id, $from, $to]
        );
        $payments = BOS_DB::get_results(
            'SELECT payment_number, payment_date, amount_received, payment_method
             FROM `' . BOS_DB::t('payments') . '` WHERE client_id=? AND deleted_at IS NULL AND payment_date BETWEEN ? AND ? ORDER BY payment_date ASC',
            [$c->id, $from, $to]
        );

        BOS_Helpers::ok([
            'client'   => $c,
            'period'   => ['from' => $from, 'to' => $to],
            'invoices' => $invoices,
            'payments' => $payments,
            'summary'  => [
                'total_billed'    => array_sum(array_column((array)$invoices, 'grand_total')),
                'total_paid'      => array_sum(array_column((array)$invoices, 'amount_paid')),
                'total_outstanding'=> array_sum(array_column((array)$invoices, 'amount_outstanding')),
            ],
        ]);
    }

    public static function expense_summary(): void {
        BOS_Auth::require_auth();
        [$from, $to] = self::date_params();

        $by_cat = BOS_DB::get_results(
            'SELECT c.name as category, COUNT(*) as count, COALESCE(SUM(e.amount),0) as total
             FROM `' . BOS_DB::t('expenses') . '` e
             LEFT JOIN `' . BOS_DB::t('categories') . '` c ON c.id=e.category_id
             WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
             GROUP BY e.category_id ORDER BY total DESC',
            [$from, $to]
        );
        $total = (float) BOS_DB::get_var(
            'SELECT COALESCE(SUM(amount),0) FROM `' . BOS_DB::t('expenses') . '` WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?',
            [$from, $to]
        );
        BOS_Helpers::ok(['period' => ['from'=>$from,'to'=>$to], 'by_category' => $by_cat, 'total' => $total]);
    }

    public static function aged_receivables(): void {
        BOS_Auth::require_auth();
        $today = BOS_Helpers::today();
        $rows = BOS_DB::get_results(
            "SELECT client_name, invoice_number, due_date, amount_outstanding,
                    DATEDIFF(?, due_date) as days_overdue,
                    CASE
                        WHEN DATEDIFF(?, due_date) <= 0 THEN 'Current'
                        WHEN DATEDIFF(?, due_date) <= 30 THEN '1-30 days'
                        WHEN DATEDIFF(?, due_date) <= 60 THEN '31-60 days'
                        WHEN DATEDIFF(?, due_date) <= 90 THEN '61-90 days'
                        ELSE '90+ days'
                    END as bucket
             FROM `" . BOS_DB::t('invoices') . "`
             WHERE deleted_at IS NULL AND status IN('Sent','Partially_Paid','Overdue') AND amount_outstanding > 0
             ORDER BY due_date ASC",
            [$today, $today, $today, $today, $today]
        );
        BOS_Helpers::ok($rows);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// AUDIT LOG CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_AuditController {
    public static function index(): void {
        BOS_Auth::require_admin();
        $page=max(1,(int)($_GET['page']??1));$pp=min(100,max(1,(int)($_GET['per_page']??50)));$off=($page-1)*$pp;
        $t=BOS_DB::t('audit_logs');$where=['1=1'];$params=[];
        $module=$_GET['module']??'';if($module){$where[]='module=?';$params[]=$module;}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY timestamp DESC LIMIT ? OFFSET ?",[...$params,$pp,$off]);
        BOS_Helpers::paginated($rows,$total,$page,$pp);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// BACKUP CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_BackupController {

    public static function export(): void {
        BOS_Auth::require_admin();

        $export = ['version' => '1.3.0', 'exported_at' => BOS_Helpers::now(), 'tables' => []];

        $tables = ['clients','vendors','services','categories','brands','invoices','invoice_items','invoice_payments',
                   'payments','payment_invoice_links','expenses','quotations','quotation_items','credit_notes',
                   'subscriptions','subscription_plans','time_entries','documents','compliance_items',
                   'bank_accounts','service_presets','payment_register','settings'];

        foreach ($tables as $tname) {
            $full = BOS_DB::$prefix . $tname;
            $export['tables'][$tname] = BOS_DB::get_results("SELECT * FROM `$full`");
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="businessos-backup-' . date('Y-m-d') . '.json"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function import(): void {
        $u = BOS_Auth::require_admin();
        $b = BOS_Helpers::body();

        if (empty($b['tables']) || empty($b['version'])) {
            BOS_Helpers::error('VALIDATION_ERROR', 'Invalid backup file.', 422);
        }

        $safe_tables = ['categories','brands','bank_accounts','service_presets'];
        $imported = [];

        foreach ($safe_tables as $tname) {
            if (empty($b['tables'][$tname])) continue;
            $full = BOS_DB::$prefix . $tname;
            $count = 0;
            foreach ((array)$b['tables'][$tname] as $row) {
                $row = (array)$row;
                if (empty($row['uuid'])) continue;
                $exists = BOS_DB::get_var("SELECT id FROM `$full` WHERE uuid=?", [$row['uuid']]);
                if (!$exists) {
                    unset($row['id']);
                    BOS_DB::insert($full, $row);
                    $count++;
                }
            }
            $imported[$tname] = $count;
        }

        BOS_Helpers::audit('Backup', 'CREATE', $u, '', 'Import');
        BOS_Helpers::ok(['imported' => $imported], 'Backup imported (categories, brands, bank accounts, presets only — existing data preserved).');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PUBLIC INVOICE CONTROLLER (no auth — share token access)
// ═══════════════════════════════════════════════════════════════════════════
class BOS_PublicController {
    public static function invoice(): void {
        $token = $_GET['token'] ?? '';
        if (!$token) BOS_Helpers::error('NOT_FOUND', 'Invalid link.', 404);

        $inv = BOS_DB::get_row(
            'SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE share_token=? AND share_token_exp > NOW() AND deleted_at IS NULL',
            [$token]
        );
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Link not found or expired.', 404);

        $inv->items = BOS_DB::get_results(
            'SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=? ORDER BY sort_order ASC',
            [$inv->id]
        );

        // Load settings for brand info
        $settings_rows = BOS_DB::get_results('SELECT setting_key, setting_value FROM `' . BOS_DB::t('settings') . '`');
        $settings = [];
        foreach ($settings_rows as $r) $settings[$r->setting_key] = $r->setting_value;

        // Remove internal fields before exposing
        unset($inv->id, $inv->client_id, $inv->brand_id, $inv->share_token, $inv->share_token_exp);

        BOS_Helpers::ok(['invoice' => $inv, 'settings' => $settings]);
    }
}
