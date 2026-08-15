<?php
// ═══════════════════════════════════════════════════════════════════════════
// PAYMENT CONTROLLER (standalone payments with invoice linking)
// ═══════════════════════════════════════════════════════════════════════════
class BOS_PaymentController {

    public static function index(): void {
        BOS_Auth::require_auth();
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;
        $t        = BOS_DB::t('payments');
        $where = ['p.deleted_at IS NULL']; $params = [];

        $client = $_GET['client_id'] ?? '';
        if ($client) {
            $c = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=?', [$client]);
            if ($c) { $where[] = 'p.client_id=?'; $params[] = $c->id; }
        }
        $search = $_GET['search'] ?? '';
        if ($search) { $like='%'.$search.'%'; $where[]='(p.payment_number LIKE ? OR cl.display_name LIKE ?)'; $params=array_merge($params,[$like,$like]); }

        $w     = implode(' AND ', $where);
        $tc    = BOS_DB::t('clients');
        $total = (int) BOS_DB::get_var("SELECT COUNT(*) FROM `$t` p LEFT JOIN `$tc` cl ON cl.id=p.client_id WHERE $w", $params);
        $rows  = BOS_DB::get_results(
            "SELECT p.*, cl.display_name as client_name FROM `$t` p
             LEFT JOIN `$tc` cl ON cl.id=p.client_id
             WHERE $w ORDER BY p.payment_date DESC, p.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $per_page, $offset]
        );

        // Attach invoice links
        foreach ($rows as $row) {
            $row->invoices = BOS_DB::get_results(
                'SELECT i.invoice_number, pil.amount FROM `' . BOS_DB::t('payment_invoice_links') . '` pil
                 JOIN `' . BOS_DB::t('invoices') . '` i ON i.id=pil.invoice_id
                 WHERE pil.payment_id=?', [$row->id]
            );
        }
        BOS_Helpers::paginated($rows, $total, $page, $per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $uuid = $_GET['uuid'];
        $p = BOS_DB::get_row(
            'SELECT p.*, cl.display_name as client_name FROM `' . BOS_DB::t('payments') . '` p
             LEFT JOIN `' . BOS_DB::t('clients') . '` cl ON cl.id=p.client_id
             WHERE p.uuid=? AND p.deleted_at IS NULL', [$uuid]
        );
        if (!$p) BOS_Helpers::error('NOT_FOUND', 'Payment not found.', 404);
        $p->invoices = BOS_DB::get_results(
            'SELECT i.uuid, i.invoice_number, pil.amount FROM `' . BOS_DB::t('payment_invoice_links') . '` pil
             JOIN `' . BOS_DB::t('invoices') . '` i ON i.id=pil.invoice_id WHERE pil.payment_id=?', [$p->id]
        );
        BOS_Helpers::ok($p);
    }

    public static function create(): void {
        $u = BOS_Auth::require_auth();
        $b = BOS_Helpers::body();
        foreach (['client_uuid','payment_date','amount_received','payment_method'] as $req) {
            if (empty($b[$req])) BOS_Helpers::error('VALIDATION_ERROR', "$req required.", 422);
        }
        $client = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('clients') . '` WHERE uuid=? AND deleted_at IS NULL', [$b['client_uuid']]);
        if (!$client) BOS_Helpers::error('NOT_FOUND', 'Client not found.', 404);

        $pay_num = BOS_Helpers::next_number('payment');
        $uuid    = BOS_Helpers::uuid();
        $bank_id = null;
        if (!empty($b['bank_account_uuid'])) {
            $bank_id = BOS_DB::get_var('SELECT id FROM `' . BOS_DB::t('bank_accounts') . '` WHERE uuid=?', [$b['bank_account_uuid']]);
        }

        $pay_id = BOS_DB::insert(BOS_DB::t('payments'), [
            'uuid'            => $uuid,
            'payment_number'  => $pay_num,
            'payment_date'    => $b['payment_date'],
            'client_id'       => $client->id,
            'amount_received' => (float)$b['amount_received'],
            'payment_method'  => BOS_Helpers::str($b['payment_method']),
            'bank_account_id' => $bank_id,
            'transaction_ref' => BOS_Helpers::str($b['transaction_ref'] ?? ''),
            'transaction_date'=> $b['transaction_date'] ?? null,
            'tds_deducted'    => (float)($b['tds_deducted'] ?? 0),
            'tds_section'     => BOS_Helpers::str($b['tds_section'] ?? ''),
            'notes'           => $b['notes'] ?? null,
            'is_advance'      => (int)($b['is_advance'] ?? 0),
            'currency'        => BOS_Helpers::str($b['currency'] ?? 'INR'),
            'created_by'      => $u->id,
        ]);

        // Link to invoices
        $invoices = $b['invoices'] ?? [];
        foreach ($invoices as $link) {
            if (empty($link['invoice_uuid']) || empty($link['amount'])) continue;
            $inv = BOS_DB::get_row('SELECT id FROM `' . BOS_DB::t('invoices') . '` WHERE uuid=?', [$link['invoice_uuid']]);
            if (!$inv) continue;
            BOS_DB::insert(BOS_DB::t('payment_invoice_links'), [
                'payment_id' => $pay_id,
                'invoice_id' => $inv->id,
                'amount'     => (float)$link['amount'],
            ]);
            // Create a corresponding invoice_payment record
            BOS_DB::insert(BOS_DB::t('invoice_payments'), [
                'uuid'           => BOS_Helpers::uuid(),
                'invoice_id'     => $inv->id,
                'payment_date'   => $b['payment_date'],
                'amount'         => (float)$link['amount'],
                'payment_method' => BOS_Helpers::str($b['payment_method']),
                'reference'      => $pay_num,
                'created_by'     => $u->id,
            ]);
            BOS_InvoiceController::sync_payment_status((int)$inv->id);
        }

        BOS_Helpers::ok(['uuid' => $uuid, 'payment_number' => $pay_num], 'Payment recorded.');
    }

    public static function delete(): void {
        $u    = BOS_Auth::require_admin();
        $uuid = $_GET['uuid'];
        $b    = BOS_Helpers::body();
        $pay  = BOS_DB::get_row('SELECT * FROM `' . BOS_DB::t('payments') . '` WHERE uuid=? AND deleted_at IS NULL', [$uuid]);
        if (!$pay) BOS_Helpers::error('NOT_FOUND', 'Payment not found.', 404);

        // Reverse: update linked invoices
        $links = BOS_DB::get_results('SELECT * FROM `' . BOS_DB::t('payment_invoice_links') . '` WHERE payment_id=?', [$pay->id]);
        foreach ($links as $link) {
            BOS_DB::query('DELETE FROM `' . BOS_DB::t('invoice_payments') . '` WHERE invoice_id=? AND reference=? AND amount=?',
                [$link->invoice_id, $pay->payment_number, $link->amount]);
            BOS_InvoiceController::sync_payment_status((int)$link->invoice_id);
        }
        BOS_DB::update(BOS_DB::t('payments'), [
            'is_reversed'    => 1,
            'reversal_reason'=> BOS_Helpers::str($b['reason'] ?? 'Reversed'),
            'reversed_at'    => BOS_Helpers::now(),
            'deleted_at'     => BOS_Helpers::now(),
            'updated_by'     => $u->id,
        ], ['id' => $pay->id]);
        BOS_Helpers::ok([], 'Payment reversed.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// EXPENSE CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ExpenseController {

    public static function index(): void {
        BOS_Auth::require_auth();
        $page = max(1,(int)($_GET['page']??1)); $per_page=min(100,max(1,(int)($_GET['per_page']??25))); $offset=($page-1)*$per_page;
        $where=['e.deleted_at IS NULL']; $params=[];
        $search=$_GET['search']??''; if($search){$like='%'.$search.'%';$where[]='(e.title LIKE ?)';$params[]=$like;}
        $w=implode(' AND ',$where); $t=BOS_DB::t('expenses'); $tv=BOS_DB::t('vendors');
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` e LEFT JOIN `$tv` v ON v.id=e.vendor_id WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT e.*,v.display_name as vendor_name FROM `$t` e LEFT JOIN `$tv` v ON v.id=e.vendor_id WHERE $w ORDER BY e.expense_date DESC, e.created_at DESC LIMIT ? OFFSET ?",[...$params,$per_page,$offset]);
        BOS_Helpers::paginated($rows,$total,$page,$per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $e=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('expenses').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);
        if(!$e) BOS_Helpers::error('NOT_FOUND','Expense not found.',404);
        BOS_Helpers::ok($e);
    }

    public static function create(): void {
        $u=BOS_Auth::require_auth(); $b=BOS_Helpers::body();
        foreach(['title','expense_date','category_id','amount'] as $req){if(empty($b[$req]))BOS_Helpers::error('VALIDATION_ERROR',"$req required.",422);}
        $uuid=BOS_Helpers::uuid();
        $cat=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('categories').'` WHERE uuid=?',[$b['category_id']]);
        $cat_id=$cat?$cat->id:(int)$b['category_id'];
        $vendor_id=null; if(!empty($b['vendor_uuid'])){$v=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('vendors').'` WHERE uuid=?',[$b['vendor_uuid']]);if($v)$vendor_id=$v->id;}
        $exp_num=BOS_Helpers::next_number('expense');
        BOS_DB::insert(BOS_DB::t('expenses'),[
            'uuid'=>$uuid,'expense_number'=>$exp_num,'expense_date'=>$b['expense_date'],'title'=>BOS_Helpers::str($b['title']),
            'category_id'=>$cat_id,'vendor_id'=>$vendor_id,'amount'=>(float)$b['amount'],'currency'=>BOS_Helpers::str($b['currency']??'INR'),
            'payment_method'=>BOS_Helpers::str($b['payment_method']??''),'reference'=>BOS_Helpers::str($b['reference']??''),
            'bill_date'=>$b['bill_date']??null,'due_date'=>$b['due_date']??null,'payment_status'=>BOS_Helpers::str($b['payment_status']??'Paid'),
            'amount_paid'=>(float)($b['amount_paid']??$b['amount']??0),'gst_paid'=>(float)($b['gst_paid']??0),'gst_rate'=>isset($b['gst_rate'])?(float)$b['gst_rate']:null,
            'hsn_sac_code'=>BOS_Helpers::str($b['hsn_sac_code']??''),'itc_eligible'=>(int)($b['itc_eligible']??0),
            'tds_deducted'=>(float)($b['tds_deducted']??0),'tds_section'=>BOS_Helpers::str($b['tds_section']??''),
            'description'=>$b['description']??null,'is_recurring'=>(int)($b['is_recurring']??0),
            'recurring_frequency'=>BOS_Helpers::str($b['recurring_frequency']??''),'is_reimbursable'=>(int)($b['is_reimbursable']??0),
            'created_by'=>$u->id,
        ]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Expense added.');
    }

    public static function update(): void {
        $u=BOS_Auth::require_auth(); $uuid=$_GET['uuid']; $b=BOS_Helpers::body();
        $e=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('expenses').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$e)BOS_Helpers::error('NOT_FOUND','Expense not found.',404);
        $fields=['updated_by'=>$u->id];
        $map=['title','expense_date','payment_method','reference','bill_date','due_date','payment_status','description','tds_section','recurring_frequency','hsn_sac_code'];
        foreach($map as $k){if(isset($b[$k]))$fields[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['amount']))$fields['amount']=(float)$b['amount'];
        if(isset($b['gst_paid']))$fields['gst_paid']=(float)$b['gst_paid'];
        if(isset($b['tds_deducted']))$fields['tds_deducted']=(float)$b['tds_deducted'];
        if(isset($b['itc_eligible']))$fields['itc_eligible']=(int)$b['itc_eligible'];
        if(isset($b['is_recurring']))$fields['is_recurring']=(int)$b['is_recurring'];
        BOS_DB::update(BOS_DB::t('expenses'),$fields,['id'=>$e->id]);
        BOS_Helpers::ok([],'Expense updated.');
    }

    public static function delete(): void {
        $u=BOS_Auth::require_auth();
        BOS_Helpers::soft_delete('expenses',$_GET['uuid'],$u);
        BOS_Helpers::ok([],'Expense deleted.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// QUOTATION CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_QuotationController {

    private static function write_items(int $quot_id, array $items): void {
        BOS_DB::query('DELETE FROM `'.BOS_DB::t('quotation_items').'` WHERE quotation_id=?',[$quot_id]);
        foreach($items as $i=>$item){
            $qty=(float)($item['quantity']??1); $price=(float)($item['unit_price']??0);
            $disc_type=$item['discount_type']??''; $disc_val=(float)($item['discount_value']??0);
            $gst_rate=(float)($item['gst_rate']??0);
            $before_disc=$qty*$price;
            $disc_amt=$disc_type==='percent'?round($before_disc*$disc_val/100,2):min($disc_val,$before_disc);
            $taxable=$before_disc-$disc_amt; $gst_amt=round($taxable*$gst_rate/100,2);
            BOS_DB::insert(BOS_DB::t('quotation_items'),[
                'quotation_id'=>$quot_id,'service_id'=>$item['service_id']??null,'line_number'=>$i+1,
                'description'=>BOS_Helpers::str($item['description']??''),'hsn_sac_code'=>BOS_Helpers::str($item['hsn_sac_code']??''),
                'quantity'=>$qty,'unit'=>BOS_Helpers::str($item['unit']??'Fixed'),'unit_price'=>$price,
                'discount_type'=>$disc_type??null,'discount_value'=>$disc_val,'discount_amount'=>round($disc_amt,2),
                'taxable_amount'=>round($taxable,2),'gst_rate'=>$gst_rate,'cgst_amount'=>round($gst_amt/2,2),
                'sgst_amount'=>round($gst_amt/2,2),'igst_amount'=>0,'line_total'=>round($taxable+$gst_amt,2),'sort_order'=>$i,
            ]);
        }
    }

    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$per_page=min(100,max(1,(int)($_GET['per_page']??25)));$offset=($page-1)*$per_page;
        $t=BOS_DB::t('quotations');$tc=BOS_DB::t('clients');$where=['q.deleted_at IS NULL'];$params=[];
        $status=$_GET['filter']['status']??$_GET['filter[status]']??'';if($status){$where[]='q.status=?';$params[]=$status;}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` q LEFT JOIN `$tc` cl ON cl.id=q.client_id WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT q.*,cl.display_name as client_name FROM `$t` q LEFT JOIN `$tc` cl ON cl.id=q.client_id WHERE $w ORDER BY q.created_at DESC LIMIT ? OFFSET ?",[...$params,$per_page,$offset]);
        BOS_Helpers::paginated($rows,$total,$page,$per_page);
    }

    public static function show(): void {
        BOS_Auth::require_auth();
        $q=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        $q->items=BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('quotation_items').'` WHERE quotation_id=? ORDER BY sort_order ASC',[$q->id]);
        $c=BOS_DB::get_row('SELECT display_name,primary_email,business_name FROM `'.BOS_DB::t('clients').'` WHERE id=?',[$q->client_id]);
        if($c){$q->client_name=$c->display_name;$q->client_email=$c->primary_email;$q->business_name=$c->business_name;}
        BOS_Helpers::ok($q);
    }

    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        foreach(['client_uuid','quote_date','valid_until','items'] as $req){if(empty($b[$req]))BOS_Helpers::error('VALIDATION_ERROR',"$req required.",422);}
        $c=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('clients').'` WHERE uuid=? AND deleted_at IS NULL',[$b['client_uuid']]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Client not found.',404);
        $items=(array)$b['items'];$subtotal=$taxable=$cgst=$sgst=$igst=$disc=0;
        foreach($items as $item){$qty=(float)($item['quantity']??1);$price=(float)($item['unit_price']??0);$gst=(float)($item['gst_rate']??0);$dt=(float)($item['discount_value']??0);$disc_type=$item['discount_type']??'';$disc_amt=$disc_type==='percent'?round($qty*$price*$dt/100,2):$dt;$tax=$qty*$price-$disc_amt;$g=round($tax*$gst/100,2);$subtotal+=$qty*$price;$disc+=$disc_amt;$taxable+=$tax;$cgst+=round($g/2,2);$sgst+=round($g/2,2);}
        $grand=$taxable+$cgst+$sgst; $roff=round(round($grand)-$grand,2);
        $uuid=BOS_Helpers::uuid();$num=BOS_Helpers::next_number('quotation');
        $id=BOS_DB::insert(BOS_DB::t('quotations'),['uuid'=>$uuid,'quote_number'=>$num,'quote_date'=>$b['quote_date'],'valid_until'=>$b['valid_until'],'client_id'=>$c->id,'prepared_by'=>$u->id,'title'=>BOS_Helpers::str($b['title']??''),'currency'=>BOS_Helpers::str($b['currency']??'INR'),'status'=>'Draft','place_of_supply'=>BOS_Helpers::str($b['place_of_supply']??''),'subtotal'=>round($subtotal,2),'total_discount'=>round($disc,2),'taxable_amount'=>round($taxable,2),'total_cgst'=>round($cgst,2),'total_sgst'=>round($sgst,2),'total_igst'=>0,'round_off'=>$roff,'grand_total'=>round($grand+$roff,2),'terms'=>$b['terms']??null,'notes_to_client'=>$b['notes_to_client']??null,'invoice_title'=>BOS_Helpers::str($b['invoice_title']??''),'template_id'=>BOS_Helpers::str($b['template_id']??'classic'),'color_theme'=>BOS_Helpers::str($b['color_theme']??'navy'),'created_by'=>$u->id]);
        self::write_items((int)$id,$items);
        BOS_Helpers::ok(['uuid'=>$uuid,'quote_number'=>$num],'Quotation created.');
    }

    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $q=BOS_DB::get_row('SELECT id,status FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        if(!in_array($q->status,['Draft']))BOS_Helpers::error('FORBIDDEN','Only Draft quotations can be edited.',403);
        $fields=['updated_by'=>$u->id];$map=['quote_date','valid_until','title','terms','notes_to_client','place_of_supply','invoice_title','template_id','color_theme'];
        foreach($map as $k){if(isset($b[$k]))$fields[$k]=BOS_Helpers::str($b[$k]);}
        BOS_DB::update(BOS_DB::t('quotations'),$fields,['id'=>$q->id]);
        if(isset($b['items']))self::write_items((int)$q->id,$b['items']);
        BOS_Helpers::ok([],'Quotation updated.');
    }

    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('quotations',$_GET['uuid'],$u);BOS_Helpers::ok([],'Quotation deleted.');}

    public static function send_email(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        if(!BOS_Email::is_configured())BOS_Helpers::error('SMTP_NOT_CONFIGURED','SMTP not configured. Go to Settings > Email.',503);
        $q=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        $c=BOS_DB::get_row('SELECT display_name,primary_email FROM `'.BOS_DB::t('clients').'` WHERE id=?',[$q->client_id]);
        $to=$b['to']??$c->primary_email??'';if(!$to)BOS_Helpers::error('VALIDATION_ERROR','Recipient email required.',422);
        $biz=BOS_DB::get_setting('business_name','Business OS');$sym=BOS_DB::get_setting('currency_symbol','₹');
        $html='<p>'.nl2br(htmlspecialchars($b['message']??'Please find attached quotation.')).'</p><p><strong>Quotation #:</strong> '.$q->quote_number.'<br><strong>Amount:</strong> '.$sym.number_format((float)$q->grand_total,2).'<br><strong>Valid Until:</strong> '.$q->valid_until.'</p>';
        $result=BOS_Email::send($to,$c->display_name,'Quotation '.$q->quote_number.' from '.$biz,$html);
        if(!$result['success'])BOS_Helpers::error('EMAIL_FAILED',$result['error'],503);
        if($q->status==='Draft')BOS_DB::update(BOS_DB::t('quotations'),['status'=>'Sent','sent_at'=>BOS_Helpers::now(),'updated_by'=>$u->id],['id'=>$q->id]);
        BOS_Helpers::ok([],'Quotation emailed.');
    }

    public static function accept(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];
        $q=BOS_DB::get_row('SELECT id,status FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        BOS_DB::update(BOS_DB::t('quotations'),['status'=>'Accepted','accepted_at'=>BOS_Helpers::now(),'updated_by'=>$u->id],['id'=>$q->id]);
        BOS_Helpers::ok([],'Quotation accepted.');
    }

    public static function reject(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $q=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        BOS_DB::update(BOS_DB::t('quotations'),['status'=>'Rejected','rejected_at'=>BOS_Helpers::now(),'rejection_reason'=>$b['reason']??null,'updated_by'=>$u->id],['id'=>$q->id]);
        BOS_Helpers::ok([],'Quotation rejected.');
    }

    public static function convert(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];
        $q=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        if(!in_array($q->status,['Accepted','Sent']))BOS_Helpers::error('FORBIDDEN','Only Accepted or Sent quotations can be converted.',403);
        $client=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('clients').'` WHERE id=?',[$q->client_id]);
        $due_days=(int)BOS_DB::get_setting('default_invoice_due_days','30');
        $inv_uuid=BOS_Helpers::uuid();$inv_num=BOS_Helpers::next_number('invoice');
        $items=BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('quotation_items').'` WHERE quotation_id=? ORDER BY sort_order ASC',[$q->id]);
        $inv_id=BOS_DB::insert(BOS_DB::t('invoices'),[
            'uuid'=>$inv_uuid,'invoice_number'=>$inv_num,'invoice_date'=>BOS_Helpers::today(),
            'due_date'=>date('Y-m-d',strtotime('+'.($due_days).' days')),'client_id'=>$q->client_id,'quotation_id'=>$q->id,
            'client_name'=>$client->display_name??'','client_email'=>$client->primary_email??'','business_name'=>$client->business_name??'',
            'address1'=>$client->address1??'','client_city'=>$client->city??'','client_state'=>$client->state??'',
            'client_pin'=>$client->pin_code??'','client_gstin'=>$client->gstin??'','client_pan'=>$client->pan??'',
            'currency'=>$q->currency,'status'=>'Draft','subtotal'=>$q->subtotal,'total_discount'=>$q->total_discount,
            'taxable_amount'=>$q->taxable_amount,'total_cgst'=>$q->total_cgst,'total_sgst'=>$q->total_sgst,
            'total_igst'=>$q->total_igst,'round_off'=>$q->round_off,'grand_total'=>$q->grand_total,
            'net_receivable'=>$q->grand_total,'amount_outstanding'=>$q->grand_total,'terms'=>$q->terms,
            'notes_to_client'=>$q->notes_to_client,'invoice_title'=>$q->invoice_title,'template_id'=>$q->template_id,'color_theme'=>$q->color_theme,'created_by'=>$u->id,
        ]);
        foreach($items as $item){$ia=(array)$item;unset($ia['id'],$ia['quotation_id']);$ia['invoice_id']=(int)$inv_id;BOS_DB::insert(BOS_DB::t('invoice_items'),$ia);}
        BOS_DB::update(BOS_DB::t('quotations'),['status'=>'Accepted','converted_invoice_id'=>$inv_id,'accepted_at'=>BOS_Helpers::now(),'updated_by'=>$u->id],['id'=>$q->id]);
        $inv=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('invoices').'` WHERE id=?',[$inv_id]);
        BOS_Helpers::ok($inv,'Quotation converted to invoice.');
    }

    public static function duplicate(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];
        $q=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('quotations').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$q)BOS_Helpers::error('NOT_FOUND','Quotation not found.',404);
        $new_uuid=BOS_Helpers::uuid();$new_num=BOS_Helpers::next_number('quotation');
        $f=(array)$q;unset($f['id'],$f['created_at'],$f['updated_at']);
        $f['uuid']=$new_uuid;$f['quote_number']=$new_num;$f['status']='Draft';$f['quote_date']=BOS_Helpers::today();
        $f['sent_at']=null;$f['accepted_at']=null;$f['rejected_at']=null;$f['converted_invoice_id']=null;$f['created_by']=$u->id;$f['updated_by']=null;$f['deleted_at']=null;
        $new_id=BOS_DB::insert(BOS_DB::t('quotations'),$f);
        $items=BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('quotation_items').'` WHERE quotation_id=?',[$q->id]);
        foreach($items as $item){$ia=(array)$item;unset($ia['id']);$ia['quotation_id']=(int)$new_id;BOS_DB::insert(BOS_DB::t('quotation_items'),$ia);}
        BOS_Helpers::ok(['uuid'=>$new_uuid,'quote_number'=>$new_num],'Quotation duplicated.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CREDIT NOTE CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_CreditNoteController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$per_page=min(100,max(1,(int)($_GET['per_page']??25)));$offset=($page-1)*$per_page;
        $t=BOS_DB::t('credit_notes');$total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE deleted_at IS NULL");
        $rows=BOS_DB::get_results("SELECT cn.*,cl.display_name as client_name FROM `$t` cn LEFT JOIN `".BOS_DB::t('clients')."` cl ON cl.id=cn.client_id WHERE cn.deleted_at IS NULL ORDER BY cn.cn_date DESC LIMIT ? OFFSET ?",[$per_page,$offset]);
        BOS_Helpers::paginated($rows,$total,$page,$per_page);
    }
    public static function show(): void {
        BOS_Auth::require_auth();
        $c=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('credit_notes').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Credit note not found.',404);BOS_Helpers::ok($c);
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        $inv=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('invoices').'` WHERE uuid=? AND deleted_at IS NULL',[$b['invoice_uuid']??'']);
        if(!$inv)BOS_Helpers::error('NOT_FOUND','Invoice not found.',404);
        $amount=(float)($b['amount']??$inv->grand_total);$gst_rate=(float)($b['gst_rate']??0);$gst_amt=round($amount*$gst_rate/100,2);
        $uuid=BOS_Helpers::uuid();$num=BOS_Helpers::next_number('credit_note');
        BOS_DB::insert(BOS_DB::t('credit_notes'),['uuid'=>$uuid,'cn_number'=>$num,'cn_date'=>$b['cn_date']??BOS_Helpers::today(),'invoice_id'=>$inv->id,'client_id'=>$inv->client_id,'reason'=>BOS_Helpers::str($b['reason']??''),'cn_type'=>BOS_Helpers::str($b['cn_type']??'Partial_Refund'),'subtotal'=>$amount,'total_cgst'=>round($gst_amt/2,2),'total_sgst'=>round($gst_amt/2,2),'total_igst'=>0,'grand_total'=>$amount+$gst_amt,'refund_method'=>BOS_Helpers::str($b['refund_method']??''),'refund_reference'=>BOS_Helpers::str($b['refund_reference']??''),'refund_date'=>$b['refund_date']??null,'status'=>'Issued','created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid,'cn_number'=>$num],'Credit note issued.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $c=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('credit_notes').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Credit note not found.',404);
        $fields=['updated_by'=>$u->id];$map=['reason','refund_method','refund_reference','refund_date','status'];
        foreach($map as $k){if(isset($b[$k]))$fields[$k]=BOS_Helpers::str($b[$k]);}
        BOS_DB::update(BOS_DB::t('credit_notes'),$fields,['id'=>$c->id]);BOS_Helpers::ok([],'Credit note updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('credit_notes',$_GET['uuid'],$u);BOS_Helpers::ok([],'Credit note deleted.');}
}
