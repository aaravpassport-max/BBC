<?php
/**
 * Invoice Controller — complete invoice lifecycle.
 * Handles create/update/delete, status transitions, email send, share tokens,
 * and clone. Invoice payment sync updates amount_paid + payment_status.
 */
class BOS_InvoiceController {

    // ── Compute totals from items array ──────────────────────────────────────
    private static function compute_totals(array $items, bool $is_igst): array {
        $subtotal = $taxable = $cgst = $sgst = $igst = $discount = 0;
        $computed = [];

        foreach ($items as $i => $item) {
            $qty        = (float)($item['quantity']   ?? 1);
            $price      = (float)($item['unit_price'] ?? 0);
            $disc_type  = $item['discount_type']  ?? '';
            $disc_val   = (float)($item['discount_value'] ?? 0);
            $gst_rate   = (float)($item['gst_rate']  ?? 0);

            $line_before_disc = $qty * $price;
            $disc_amt = $disc_type === 'percent'
                ? round($line_before_disc * $disc_val / 100, 2)
                : min($disc_val, $line_before_disc);

            $taxable_line = $line_before_disc - $disc_amt;
            $gst_amt      = round($taxable_line * $gst_rate / 100, 2);

            $cgst_line = $is_igst ? 0 : round($gst_amt / 2, 2);
            $sgst_line = $is_igst ? 0 : round($gst_amt / 2, 2);
            $igst_line = $is_igst ? $gst_amt : 0;

            $line_total = $taxable_line + $gst_amt;

            $discount += $disc_amt;
            $subtotal += $line_before_disc;
            $taxable  += $taxable_line;
            $cgst     += $cgst_line;
            $sgst     += $sgst_line;
            $igst     += $igst_line;

            $computed[] = array_merge($item, [
                'line_number'     => $i + 1,
                'discount_amount' => round($disc_amt, 2),
                'taxable_amount'  => round($taxable_line, 2),
                'cgst_amount'     => $cgst_line,
                'sgst_amount'     => $sgst_line,
                'igst_amount'     => $igst_line,
                'line_total'      => round($line_total, 2),
            ]);
        }

        $grand_total = $subtotal - $discount + $cgst + $sgst + $igst;
        $round_off   = round(round($grand_total) - $grand_total, 2);

        return [
            'subtotal'      => round($subtotal, 2),
            'total_discount'=> round($discount, 2),
            'taxable_amount'=> round($taxable, 2),
            'total_cgst'    => round($cgst, 2),
            'total_sgst'    => round($sgst, 2),
            'total_igst'    => round($igst, 2),
            'round_off'     => $round_off,
            'grand_total'   => round($grand_total + $round_off, 2),
            'items'         => $computed,
        ];
    }

    // ── Sync payment status on the invoice ───────────────────────────────────
    public static function sync_payment_status(int $invoice_id): void {
        $paid = (float) BOS_DB::get_var(
            'SELECT COALESCE(SUM(amount),0) FROM `' . BOS_DB::t('invoice_payments') . '` WHERE invoice_id=?',
            [$invoice_id]
        );
        $inv = BOS_DB::get_row('SELECT grand_total, tds_deducted, status FROM `' . BOS_DB::t('invoices') . '` WHERE id=?', [$invoice_id]);
        if (!$inv) return;

        $net        = (float)$inv->grand_total - (float)$inv->tds_deducted;
        $outstanding = max(0, $net - $paid);

        $pstatus = 'Unpaid';
        if ($paid >= $net) $pstatus = 'Paid';
        elseif ($paid > 0) $pstatus = 'Partially_Paid';

        $fields = [
            'amount_paid'       => $paid,
            'amount_outstanding'=> $outstanding,
            'net_receivable'    => $net,
            'payment_status'    => $pstatus,
        ];
        // Also update invoice status if fully paid
        if ($pstatus === 'Paid' && !in_array($inv->status, ['Paid','Cancelled'])) {
            $fields['status']  = 'Paid';
            $fields['paid_at'] = BOS_Helpers::now();
        } elseif ($pstatus === 'Partially_Paid' && $inv->status === 'Sent') {
            $fields['status'] = 'Partially_Paid';
        }
        BOS_DB::update(BOS_DB::t('invoices'), $fields, ['id' => $invoice_id]);
    }

    // ── Write invoice items ───────────────────────────────────────────────────
    private static function write_items(int $invoice_id, array $items): void {
        BOS_DB::query('DELETE FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=?', [$invoice_id]);
        foreach ($items as $item) {
            BOS_DB::insert(BOS_DB::t('invoice_items'), [
                'invoice_id'      => $invoice_id,
                'service_id'      => $item['service_id'] ?? null,
                'line_number'     => $item['line_number'] ?? 1,
                'description'     => BOS_Helpers::str($item['description'] ?? ''),
                'hsn_sac_code'    => BOS_Helpers::str($item['hsn_sac_code'] ?? ''),
                'quantity'        => (float)($item['quantity'] ?? 1),
                'unit'            => BOS_Helpers::str($item['unit'] ?? 'Fixed'),
                'unit_price'      => (float)($item['unit_price'] ?? 0),
                'discount_type'   => $item['discount_type'] ?? null,
                'discount_value'  => (float)($item['discount_value'] ?? 0),
                'discount_amount' => (float)($item['discount_amount'] ?? 0),
                'taxable_amount'  => (float)($item['taxable_amount'] ?? 0),
                'gst_rate'        => (float)($item['gst_rate'] ?? 0),
                'cgst_amount'     => (float)($item['cgst_amount'] ?? 0),
                'sgst_amount'     => (float)($item['sgst_amount'] ?? 0),
                'igst_amount'     => (float)($item['igst_amount'] ?? 0),
                'line_total'      => (float)($item['line_total'] ?? 0),
                'sort_order'      => (int)($item['sort_order'] ?? 0),
            ]);
        }
    }

    // ── Attach client snapshot to invoice row ─────────────────────────────────
    private static function client_snapshot(object $client): array {
        return [
            'client_name'  => $client->display_name,
            'client_email' => $client->primary_email ?? '',
            'business_name'=> $client->business_name ?? '',
            'address1'     => $client->address1 ?? '',
            'client_city'  => $client->city ?? '',
            'client_state' => $client->state ?? '',
            'client_pin'   => $client->pin_code ?? '',
            'client_gstin' => $client->gstin ?? '',
            'client_pan'   => $client->pan ?? '',
        ];
    }

    public static function index(): void {
        BOS_Auth::require_auth();
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;
        $t        = BOS_DB::t('invoices');
        $tc       = BOS_DB::t('clients');

        $where = ['i.deleted_at IS NULL']; $params = [];

        $status = $_GET['filter']['status'] ?? $_GET['filter[status]'] ?? '';
        if ($status) {
            $statuses = array_map('trim', explode(',', $status));
            $ph = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "i.status IN ($ph)";
            $params  = array_merge($params, $statuses);
        }
        $client_id = $_GET['client_id'] ?? '';
        if ($client_id) {
            $c = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$client_id]);
            if ($c) { $where[] = 'i.client_id=?'; $params[] = $c->id; }
        }
        $search = $_GET['search'] ?? '';
        if ($search) { $like = '%'.$search.'%'; $where[] = '(i.invoice_number LIKE ? OR i.client_name LIKE ?)'; $params = array_merge($params, [$like, $like]); }

        $w     = implode(' AND ', $where);
        $total = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$t` i WHERE $w", $params);
        $rows  = BOS_DB::get_results("SELECT i.* FROM `$t` i WHERE $w ORDER BY i.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $per_page, $offset]
        );
        BOS_Helpers::paginated($rows, $total, $page, $per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $inv = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        $inv->items = BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=? ORDER BY sort_order ASC', [$inv->id]);
        BOS_Helpers::ok($inv);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();

        foreach (['client_uuid','invoice_date','due_date','items'] as $req) {
            if (empty($b[$req])) BOS_Helpers::error('VALIDATION_ERROR', "$req required.", 422);
        }

        $client = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$b['client_uuid']]);
        if (!$client) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);

        $is_igst = (bool)($b['is_igst'] ?? 0);
        $totals  = self::compute_totals($b['items'], $is_igst);
        $tds     = (float)($b['tds_deducted'] ?? 0);
        $inv_num = BOS_Helpers::next_number('invoice');
        $uuid    = BOS_Helpers::uuid();

        $row = array_merge(self::client_snapshot($client), [
            'uuid'           => $uuid,
            'invoice_number' => $inv_num,
            'invoice_date'   => $b['invoice_date'],
            'due_date'       => $b['due_date'],
            'client_id'      => $client->id,
            'brand_id'       => isset($b['brand_uuid']) ? BOS_DB::get_var('SELECT id FROM `' . BOS_DB::t('brands') . '` WHERE uuid=?', [$b['brand_uuid']]) : null,
            'po_number'      => BOS_Helpers::str($b['po_number'] ?? ''),
            'place_of_supply'=> BOS_Helpers::str($b['place_of_supply'] ?? ''),
            'is_igst'        => (int)$is_igst,
            'currency'       => BOS_Helpers::str($b['currency'] ?? 'INR'),
            'status'         => 'Draft',
            'subtotal'       => $totals['subtotal'],
            'total_discount' => $totals['total_discount'],
            'taxable_amount' => $totals['taxable_amount'],
            'total_cgst'     => $totals['total_cgst'],
            'total_sgst'     => $totals['total_sgst'],
            'total_igst'     => $totals['total_igst'],
            'round_off'      => $totals['round_off'],
            'grand_total'    => $totals['grand_total'],
            'tds_deducted'   => $tds,
            'tds_section'    => BOS_Helpers::str($b['tds_section'] ?? ''),
            'net_receivable' => $totals['grand_total'] - $tds,
            'amount_outstanding'=> $totals['grand_total'] - $tds,
            'terms'          => $b['terms'] ?? null,
            'notes_to_client'=> $b['notes_to_client'] ?? null,
            'internal_notes' => $b['internal_notes'] ?? null,
            'internal_ref'   => BOS_Helpers::str($b['internal_ref'] ?? ''),
            'invoice_title'  => BOS_Helpers::str($b['invoice_title'] ?? ''),
            'declaration'    => $b['declaration'] ?? null,
            'template_id'    => BOS_Helpers::str($b['template_id'] ?? 'classic'),
            'color_theme'    => BOS_Helpers::str($b['color_theme'] ?? 'navy'),
            'bank_account_id'=> isset($b['bank_account_uuid']) ? BOS_DB::get_var('SELECT id FROM `' . BOS_DB::t('bank_accounts') . '` WHERE uuid=?', [$b['bank_account_uuid']]) : null,
            'created_by'     => $u->id,
        ]);

        $id = BOS_DB::insert(BOS_DB::t('invoices'), $row);
        self::write_items((int)$id, $totals['items']);

        $inv = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE id=?', [$id]);
        $inv->items = BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=? ORDER BY sort_order ASC', [$id]);
        BOS_Helpers::audit('Invoices', 'CREATE', $u, $uuid, $inv_num);
        BOS_Helpers::ok($inv, 'Invoice created.');
    }

    public static function update(): void {
        $u    = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $inv  = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        if (!in_array($inv->status, ['Draft'])) BOS_Helpers::error('FORBIDDEN', 'Only Draft invoices can be edited.', 403);

        $client = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE id=?', [$inv->client_id]);
        if (isset($b['client_uuid'])) {
            $client = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$b['client_uuid']]);
        }

        $is_igst = (bool)($b['is_igst'] ?? $inv->is_igst);
        $items   = $b['items'] ?? BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=? ORDER BY sort_order ASC', [$inv->id]);
        $totals  = self::compute_totals((array)$items, $is_igst);
        $tds     = (float)($b['tds_deducted'] ?? $inv->tds_deducted);

        $fields = array_merge(self::client_snapshot($client), [
            'invoice_date'   => $b['invoice_date'] ?? $inv->invoice_date,
            'due_date'       => $b['due_date'] ?? $inv->due_date,
            'is_igst'        => (int)$is_igst,
            'subtotal'       => $totals['subtotal'],
            'total_discount' => $totals['total_discount'],
            'taxable_amount' => $totals['taxable_amount'],
            'total_cgst'     => $totals['total_cgst'],
            'total_sgst'     => $totals['total_sgst'],
            'total_igst'     => $totals['total_igst'],
            'round_off'      => $totals['round_off'],
            'grand_total'    => $totals['grand_total'],
            'tds_deducted'   => $tds,
            'net_receivable' => $totals['grand_total'] - $tds,
            'amount_outstanding'=> $totals['grand_total'] - $tds,
            'terms'          => $b['terms'] ?? $inv->terms,
            'notes_to_client'=> $b['notes_to_client'] ?? $inv->notes_to_client,
            'internal_ref'   => BOS_Helpers::str($b['internal_ref'] ?? $inv->internal_ref ?? ''),
            'invoice_title'  => BOS_Helpers::str($b['invoice_title'] ?? $inv->invoice_title ?? ''),
            'template_id'    => BOS_Helpers::str($b['template_id'] ?? $inv->template_id ?? 'classic'),
            'color_theme'    => BOS_Helpers::str($b['color_theme'] ?? $inv->color_theme ?? 'navy'),
            'updated_by'     => $u->id,
        ]);

        BOS_DB::update(BOS_DB::t('invoices'), $fields, ['id' => $inv->id]);
        if (isset($b['items'])) self::write_items((int)$inv->id, $totals['items']);

        $row = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE id=?', [$inv->id]);
        $row->items = BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=? ORDER BY sort_order ASC', [$inv->id]);
        BOS_Helpers::ok($row, 'Invoice updated.');
    }

    public static function delete(): void {
        $u   = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $inv  = BOS_DB::get_row('SELECT id, status FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        if (!in_array($inv->status, ['Draft','Cancelled'])) BOS_Helpers::error('FORBIDDEN', 'Only Draft or Cancelled invoices can be deleted.', 403);
        BOS_Helpers::soft_delete('invoices', $uuid, $u);
        BOS_Helpers::ok([], 'Invoice deleted.');
    }

    public static function mark_sent(): void {
        $u   = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $inv  = BOS_DB::get_row('SELECT id, status FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        if (!in_array($inv->status, ['Draft'])) BOS_Helpers::error('FORBIDDEN', 'Only Draft invoices can be marked as Sent.', 403);
        BOS_DB::update(BOS_DB::t('invoices'), ['status' => 'Sent', 'sent_at' => BOS_Helpers::now(), 'updated_by' => $u->id], ['id' => $inv->id]);
        BOS_Helpers::ok([], 'Invoice marked as Sent.');
    }

    public static function send_email(): void {
        $u    = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();

        if (!BOS_Email::is_configured()) {
            BOS_Helpers::error('SMTP_NOT_CONFIGURED', 'SMTP is not configured. Go to Settings > Email to enable email sending.', 503);
        }

        $inv = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);

        $to   = $b['to'] ?? $inv->client_email ?? '';
        $msg  = $b['message'] ?? '';
        if (!$to) BOS_Helpers::error('VALIDATION_ERROR', 'Recipient email address required.', 422);

        $settings = [];
        $rows = BOS_DB::get_results('SELECT setting_key, setting_value FROM `' . BOS_DB::t('settings') . '`');
        foreach ($rows as $r) $settings[$r->setting_key] = $r->setting_value;

        $inv_arr = (array)$inv;
        $html    = $msg ? nl2br(htmlspecialchars($msg)) : BOS_Email::invoice_html($inv_arr, $settings);
        if ($msg) $html = '<p>' . nl2br(htmlspecialchars($msg)) . '</p>' . BOS_Email::invoice_html($inv_arr, $settings);

        $result = BOS_Email::send(
            $to,
            $inv->client_name,
            'Invoice ' . $inv->invoice_number . ' from ' . ($settings['business_name'] ?? 'Business OS'),
            $html
        );

        if (!$result['success']) {
            BOS_Helpers::error('EMAIL_FAILED', $result['error'], 503);
        }

        // Mark as sent if still Draft
        if ($inv->status === 'Draft') {
            BOS_DB::update(BOS_DB::t('invoices'), ['status' => 'Sent', 'sent_at' => BOS_Helpers::now(), 'updated_by' => $u->id], ['id' => $inv->id]);
        }

        BOS_Helpers::audit('Invoices', 'UPDATE', $u, $inv->uuid, 'Invoice emailed: ' . $inv->invoice_number);
        BOS_Helpers::ok([], 'Invoice emailed successfully.');
    }

    public static function cancel(): void {
        $u    = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $inv  = BOS_DB::get_row('SELECT id, status FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        if (in_array($inv->status, ['Paid','Cancelled'])) BOS_Helpers::error('FORBIDDEN', 'Invoice cannot be cancelled in its current state.', 403);
        BOS_DB::update(BOS_DB::t('invoices'), [
            'status'       => 'Cancelled',
            'cancelled_at' => BOS_Helpers::now(),
            'cancel_reason'=> $b['reason'] ?? null,
            'updated_by'   => $u->id,
        ], ['id' => $inv->id]);
        BOS_Helpers::ok([], 'Invoice cancelled.');
    }

    public static function clone_invoice(): void {
        $u    = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $orig = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$orig) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);

        $new_uuid = BOS_Helpers::uuid();
        $new_num  = BOS_Helpers::next_number('invoice');

        $fields = (array)$orig;
        unset($fields['id'], $fields['created_at'], $fields['updated_at']);
        $fields['uuid']           = $new_uuid;
        $fields['invoice_number'] = $new_num;
        $fields['status']         = 'Draft';
        $fields['invoice_date']   = BOS_Helpers::today();
        $fields['sent_at']        = null;
        $fields['paid_at']        = null;
        $fields['cancelled_at']   = null;
        $fields['cancel_reason']  = null;
        $fields['amount_paid']    = 0;
        $fields['payment_status'] = 'Unpaid';
        $fields['share_token']    = null;
        $fields['share_token_exp']= null;
        $fields['created_by']     = $u->id;
        $fields['updated_by']     = null;
        $fields['deleted_at']     = null;

        $new_id = BOS_DB::insert(BOS_DB::t('invoices'), $fields);

        // Copy items
        $items = BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('invoice_items') . '` WHERE invoice_id=?', [$orig->id]);
        foreach ($items as $item) {
            $item_arr = (array)$item;
            unset($item_arr['id']);
            $item_arr['invoice_id'] = $new_id;
            BOS_DB::insert(BOS_DB::t('invoice_items'), $item_arr);
        }

        $new = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('invoices') . '` WHERE id=?', [$new_id]);
        BOS_Helpers::ok($new, 'Invoice cloned.');
    }

    public static function share_token(): void {
        $u    = BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $inv  = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);

        $token = bin2hex(random_bytes(24));
        $exp   = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        BOS_DB::update(BOS_DB::t('invoices'), ['share_token' => $token, 'share_token_exp' => $exp], ['id' => $inv->id]);

        $url = 'http://127.0.0.1:' . BOS_PORT . '/business/invoice/' . $token;
        BOS_Helpers::ok(['url' => $url, 'token' => $token]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// INVOICE PAYMENT CONTROLLER (split payments per invoice)
// ═══════════════════════════════════════════════════════════════════════════
class BOS_InvoicePaymentController {

    public static function index(): void {
        BOS_Auth::require_auth();
        $inv_uuid = $_GET['inv_uuid'];
        $inv = BOS_DB::get_row('SELECT id, grand_total, tds_deducted, payment_status FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=?', [$inv_uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);

        $payments = BOS_DB::get_results(
            'SELECT * FROM `' . BOS_DB::t('invoice_payments') . '` WHERE invoice_id=? ORDER BY payment_date ASC',
            [$inv->id]
        );
        $total_paid = array_sum(array_column((array)$payments, 'amount'));

        BOS_Helpers::ok([
            'payments'       => $payments,
            'payment_status' => $inv->payment_status,
            'total_paid'     => $total_paid,
            'grand_total'    => $inv->grand_total,
            'outstanding'    => max(0, $inv->grand_total - $inv->tds_deducted - $total_paid),
        ]);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $inv_uuid = $_GET['inv_uuid'];
        $b = BOS_Helpers::body();
        $inv = BOS_DB::get_row('SELECT id, status FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$inv_uuid]);
        if (!$inv) BOS_Helpers::error('NOT_FOUND', 'Invoice not found.', 404);
        if (in_array($inv->status, ['Paid','Cancelled'])) BOS_Helpers::error('FORBIDDEN', 'Cannot add payment to this invoice.', 403);
        if (empty($b['amount']) || (float)$b['amount'] <= 0) BOS_Helpers::error('VALIDATION_ERROR', 'Amount must be > 0.', 422);

        $uuid = BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('invoice_payments'), [
            'uuid'           => $uuid,
            'invoice_id'     => $inv->id,
            'payment_date'   => $b['payment_date'] ?? BOS_Helpers::today(),
            'amount'         => (float)$b['amount'],
            'payment_method' => BOS_Helpers::str($b['payment_method'] ?? 'UPI'),
            'reference'      => BOS_Helpers::str($b['reference'] ?? ''),
            'notes'          => $b['notes'] ?? null,
            'created_by'     => $u->id,
        ]);

        BOS_InvoiceController::sync_payment_status((int)$inv->id);
        BOS_Helpers::ok([], 'Payment recorded.');
    }

    public static function update(): void {
        $u = BOS_Auth::require_auth();
        $inv_uuid = $_GET['inv_uuid'];
        $p_uuid   = $_GET['uuid'];
        $b = BOS_Helpers::body();

        $inv = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$inv_uuid]);
        $pmt = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoice_payments') . '` WHERE uuid=? AND invoice_id=?', [$p_uuid, $inv->id ?? 0]);
        if (!$inv || !$pmt) BOS_Helpers::error('NOT_FOUND', 'Payment not found.', 404);

        $fields = ['updated_at' => BOS_Helpers::now()];
        if (isset($b['amount']))         $fields['amount']         = (float)$b['amount'];
        if (isset($b['payment_date']))   $fields['payment_date']   = $b['payment_date'];
        if (isset($b['payment_method'])) $fields['payment_method'] = BOS_Helpers::str($b['payment_method']);
        if (isset($b['reference']))      $fields['reference']      = BOS_Helpers::str($b['reference']);
        BOS_DB::update(BOS_DB::t('invoice_payments'), $fields, ['id' => $pmt->id]);
        BOS_InvoiceController::sync_payment_status((int)$inv->id);
        BOS_Helpers::ok([], 'Payment updated.');
    }

    public static function delete(): void {
        $u = BOS_Auth::require_auth();
        $inv_uuid = $_GET['inv_uuid'];
        $p_uuid   = $_GET['uuid'];
        $inv = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=? AND deleted_at IS NULL', [$inv_uuid]);
        $pmt = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoice_payments') . '` WHERE uuid=? AND invoice_id=?', [$p_uuid, $inv->id ?? 0]);
        if (!$inv || !$pmt) BOS_Helpers::error('NOT_FOUND', 'Payment not found.', 404);
        BOS_DB::delete(BOS_DB::t('invoice_payments'), ['id' => $pmt->id]);
        BOS_InvoiceController::sync_payment_status((int)$inv->id);
        BOS_Helpers::ok([], 'Payment deleted.');
    }
}
