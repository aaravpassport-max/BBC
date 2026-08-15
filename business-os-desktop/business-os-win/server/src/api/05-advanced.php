<?php
// ═══════════════════════════════════════════════════════════════════════════
// SUBSCRIPTION CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_SubscriptionController {
    private static function t($n){return BOS_DB::t($n);}

    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$pp=min(100,max(1,(int)($_GET['per_page']??25)));$off=($page-1)*$pp;
        $t=self::t('subscriptions');$tc=self::t('clients');
        $where=['s.deleted_at IS NULL'];$params=[];
        $status=$_GET['filter']['status']??$_GET['filter[status]']??'';if($status){$where[]='s.status=?';$params[]=$status;}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` s LEFT JOIN `$tc` c ON c.id=s.client_id WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT s.*,c.display_name as client_name FROM `$t` s LEFT JOIN `$tc` c ON c.id=s.client_id WHERE $w ORDER BY s.created_at DESC LIMIT ? OFFSET ?",[...$params,$pp,$off]);
        BOS_Helpers::paginated($rows,$total,$page,$pp);
    }
    public static function show(): void {
        BOS_Auth::require_auth();
        $s=BOS_DB::get_row('SELECT s.*,c.display_name as client_name FROM `'.self::t('subscriptions').'` s LEFT JOIN `'.self::t('clients').'` c ON c.id=s.client_id WHERE s.uuid=? AND s.deleted_at IS NULL',[$_GET['uuid']]);
        if(!$s)BOS_Helpers::error('NOT_FOUND','Subscription not found.',404);BOS_Helpers::ok($s);
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        foreach(['client_uuid','title','billing_amount','billing_cycle','start_date'] as $r){if(empty($b[$r]))BOS_Helpers::error('VALIDATION_ERROR',"$r required.",422);}
        $c=BOS_DB::get_row('SELECT id FROM `'.self::t('clients').'` WHERE uuid=? AND deleted_at IS NULL',[$b['client_uuid']]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Client not found.',404);
        $uuid=BOS_Helpers::uuid();$num=BOS_Helpers::next_number('subscription');
        $next=self::calc_next($b['start_date'],$b['billing_cycle']);
        BOS_DB::insert(self::t('subscriptions'),['uuid'=>$uuid,'sub_number'=>$num,'client_id'=>$c->id,'title'=>BOS_Helpers::str($b['title']),'billing_amount'=>(float)$b['billing_amount'],'billing_cycle'=>BOS_Helpers::str($b['billing_cycle']),'start_date'=>$b['start_date'],'end_date'=>$b['end_date']??null,'next_invoice_date'=>$next,'grace_period_days'=>(int)($b['grace_period_days']??7),'auto_generate'=>(int)($b['auto_generate']??1),'gst_rate'=>(float)($b['gst_rate']??0),'status'=>'Active','notes'=>$b['notes']??null,'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Subscription created.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $s=BOS_DB::get_row('SELECT id FROM `'.self::t('subscriptions').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$s)BOS_Helpers::error('NOT_FOUND','Subscription not found.',404);
        $f=['updated_by'=>$u->id];$map=['title','billing_cycle','end_date','notes'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['billing_amount']))$f['billing_amount']=(float)$b['billing_amount'];
        if(isset($b['gst_rate']))$f['gst_rate']=(float)$b['gst_rate'];
        BOS_DB::update(self::t('subscriptions'),$f,['id'=>$s->id]);BOS_Helpers::ok([],'Subscription updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('subscriptions',$_GET['uuid'],$u);BOS_Helpers::ok([],'Subscription deleted.');}
    public static function cancel(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $s=BOS_DB::get_row('SELECT id FROM `'.self::t('subscriptions').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$s)BOS_Helpers::error('NOT_FOUND','Subscription not found.',404);
        BOS_DB::update(self::t('subscriptions'),['status'=>'Cancelled','cancelled_at'=>BOS_Helpers::now(),'cancel_reason'=>$b['reason']??null,'updated_by'=>$u->id],['id'=>$s->id]);
        BOS_Helpers::ok([],'Subscription cancelled.');
    }
    public static function pause(): void {
        $u=BOS_Auth::require_auth();$s=BOS_DB::get_row('SELECT id,status FROM `'.self::t('subscriptions').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);
        if(!$s||$s->status!=='Active')BOS_Helpers::error('FORBIDDEN','Only Active subscriptions can be paused.',403);
        BOS_DB::update(self::t('subscriptions'),['status'=>'Paused','updated_by'=>$u->id],['id'=>$s->id]);BOS_Helpers::ok([],'Paused.');
    }
    public static function resume(): void {
        $u=BOS_Auth::require_auth();$s=BOS_DB::get_row('SELECT id,status FROM `'.self::t('subscriptions').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);
        if(!$s||$s->status!=='Paused')BOS_Helpers::error('FORBIDDEN','Only Paused subscriptions can be resumed.',403);
        BOS_DB::update(self::t('subscriptions'),['status'=>'Active','updated_by'=>$u->id],['id'=>$s->id]);BOS_Helpers::ok([],'Resumed.');
    }
    private static function calc_next(string $start, string $cycle): string {
        return match($cycle){
            'Monthly'    => date('Y-m-d',strtotime($start.' +1 month')),
            'Quarterly'  => date('Y-m-d',strtotime($start.' +3 months')),
            'Half-Yearly'=> date('Y-m-d',strtotime($start.' +6 months')),
            'Annual'     => date('Y-m-d',strtotime($start.' +1 year')),
            default      => date('Y-m-d',strtotime($start.' +1 month')),
        };
    }
    public static function plans_index(): void {
        BOS_Auth::require_auth();
        BOS_Helpers::ok(BOS_DB::get_results('SELECT * FROM `'.self::t('subscription_plans').'` WHERE deleted_at IS NULL ORDER BY created_at ASC'));
    }
    public static function plans_create(): void {
        $u=BOS_Auth::require_admin();$b=BOS_Helpers::body();
        if(empty($b['name'])||empty($b['billing_cycle'])||!isset($b['price']))BOS_Helpers::error('VALIDATION_ERROR','name, billing_cycle, price required.',422);
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(self::t('subscription_plans'),['uuid'=>$uuid,'name'=>BOS_Helpers::str($b['name']),'code'=>strtoupper(BOS_Helpers::str($b['code']??'')),'description'=>$b['description']??null,'billing_cycle'=>BOS_Helpers::str($b['billing_cycle']),'price'=>(float)$b['price'],'setup_fee'=>(float)($b['setup_fee']??0),'trial_days'=>(int)($b['trial_days']??0),'gst_rate'=>(float)($b['gst_rate']??0),'status'=>'Active','created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Plan created.');
    }
    public static function plans_update(): void {
        $u=BOS_Auth::require_admin();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $p=BOS_DB::get_row('SELECT id FROM `'.self::t('subscription_plans').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$p)BOS_Helpers::error('NOT_FOUND','Plan not found.',404);
        $f=['updated_by'=>$u->id];$map=['name','code','description','billing_cycle','status'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['price']))$f['price']=(float)$b['price'];if(isset($b['gst_rate']))$f['gst_rate']=(float)$b['gst_rate'];
        BOS_DB::update(self::t('subscription_plans'),$f,['id'=>$p->id]);BOS_Helpers::ok([],'Plan updated.');
    }
    public static function plans_delete(): void {$u=BOS_Auth::require_admin();BOS_Helpers::soft_delete('subscription_plans',$_GET['uuid'],$u);BOS_Helpers::ok([],'Plan deleted.');}
}

// ═══════════════════════════════════════════════════════════════════════════
// TIME ENTRY CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_TimeEntryController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$pp=min(100,max(1,(int)($_GET['per_page']??25)));$off=($page-1)*$pp;
        $t=BOS_DB::t('time_entries');$tc=BOS_DB::t('clients');$where=['te.deleted_at IS NULL'];$params=[];
        $client=$_GET['client_uuid']??'';if($client){$c=BOS_DB::get_row('SELECT id FROM `'.$tc.'` WHERE uuid=?',[$client]);if($c){$where[]='te.client_id=?';$params[]=$c->id;}}
        $status=$_GET['status']??'';if($status){$where[]='te.status=?';$params[]=$status;}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` te LEFT JOIN `$tc` c ON c.id=te.client_id WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT te.*,c.display_name as client_name FROM `$t` te LEFT JOIN `$tc` c ON c.id=te.client_id WHERE $w ORDER BY te.entry_date DESC LIMIT ? OFFSET ?",[...$params,$pp,$off]);
        BOS_Helpers::paginated($rows,$total,$page,$pp);
    }
    public static function show(): void {BOS_Auth::require_auth();$e=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('time_entries').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);if(!$e)BOS_Helpers::error('NOT_FOUND','Entry not found.',404);BOS_Helpers::ok($e);}
    public static function unbilled(): void {
        BOS_Auth::require_auth();$params=[];
        $sql='SELECT te.*,c.display_name as client_name FROM `'.BOS_DB::t('time_entries').'` te LEFT JOIN `'.BOS_DB::t('clients').'` c ON c.id=te.client_id WHERE te.deleted_at IS NULL AND te.status="Unbilled" AND te.is_billable=1';
        $client=$_GET['client_uuid']??'';if($client){$c=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('clients').'` WHERE uuid=?',[$client]);if($c){$sql.=' AND te.client_id=?';$params[]=$c->id;}}
        BOS_Helpers::ok(BOS_DB::get_results($sql,$params));
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        foreach(['client_uuid','entry_date','duration'] as $r){if(empty($b[$r]))BOS_Helpers::error('VALIDATION_ERROR',"$r required.",422);}
        $c=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('clients').'` WHERE uuid=? AND deleted_at IS NULL',[$b['client_uuid']]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Client not found.',404);
        $dur=(float)$b['duration'];$rate=(float)($b['hourly_rate']??0);
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('time_entries'),['uuid'=>$uuid,'entry_date'=>$b['entry_date'],'client_id'=>$c->id,'task'=>$b['task']??null,'start_time'=>$b['start_time']??null,'end_time'=>$b['end_time']??null,'duration'=>$dur,'hourly_rate'=>$rate,'amount'=>round($dur*$rate,2),'is_billable'=>(int)($b['is_billable']??1),'status'=>'Unbilled','notes'=>$b['notes']??null,'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Time entry saved.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $e=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('time_entries').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$e)BOS_Helpers::error('NOT_FOUND','Entry not found.',404);
        $f=['updated_by'=>$u->id];$map=['entry_date','task','start_time','end_time','notes','status'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['duration']))$f['duration']=(float)$b['duration'];if(isset($b['hourly_rate']))$f['hourly_rate']=(float)$b['hourly_rate'];if(isset($b['is_billable']))$f['is_billable']=(int)$b['is_billable'];
        BOS_DB::update(BOS_DB::t('time_entries'),$f,['id'=>$e->id]);BOS_Helpers::ok([],'Entry updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('time_entries',$_GET['uuid'],$u);BOS_Helpers::ok([],'Entry deleted.');}
}

// ═══════════════════════════════════════════════════════════════════════════
// COMPLIANCE CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ComplianceController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $where=['deleted_at IS NULL'];$params=[];
        $status=$_GET['status']??'';if($status){$where[]='status=?';$params[]=$status;}
        $w=implode(' AND ',$where);
        BOS_Helpers::ok(BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('compliance_items').'` WHERE '.$w.' ORDER BY due_date ASC',$params));
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        if(empty($b['title'])||empty($b['due_date']))BOS_Helpers::error('VALIDATION_ERROR','title and due_date required.',422);
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('compliance_items'),['uuid'=>$uuid,'title'=>BOS_Helpers::str($b['title']),'due_date'=>$b['due_date'],'status'=>'Pending','description'=>$b['description']??null,'period'=>BOS_Helpers::str($b['period']??''),'amount_due'=>isset($b['amount_due'])?(float)$b['amount_due']:null,'notes'=>$b['notes']??null,'is_recurring'=>(int)($b['is_recurring']??0),'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Compliance item created.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $c=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('compliance_items').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$c)BOS_Helpers::error('NOT_FOUND','Item not found.',404);
        $f=['updated_by'=>$u->id];$map=['title','due_date','status','description','period','notes','filing_reference'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['amount_paid']))$f['amount_paid']=(float)$b['amount_paid'];
        if($b['status']??''==='Filed')$f['filing_date']=$b['filing_date']??BOS_Helpers::today();
        BOS_DB::update(BOS_DB::t('compliance_items'),$f,['id'=>$c->id]);BOS_Helpers::ok([],'Item updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('compliance_items',$_GET['uuid'],$u);BOS_Helpers::ok([],'Item deleted.');}
}

// ═══════════════════════════════════════════════════════════════════════════
// DOCUMENT CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_DocumentController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $where=['deleted_at IS NULL'];$params=[];
        $w=implode(' AND ',$where);
        BOS_Helpers::ok(BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('documents').'` WHERE '.$w.' ORDER BY created_at DESC',$params));
    }
    public static function show(): void {BOS_Auth::require_auth();$d=BOS_DB::get_row('SELECT * FROM `'.BOS_DB::t('documents').'` WHERE uuid=? AND deleted_at IS NULL',[$_GET['uuid']]);if(!$d)BOS_Helpers::error('NOT_FOUND','Document not found.',404);BOS_Helpers::ok($d);}
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        if(empty($b['title']))BOS_Helpers::error('VALIDATION_ERROR','title required.',422);
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('documents'),['uuid'=>$uuid,'title'=>BOS_Helpers::str($b['title']),'doc_number'=>BOS_Helpers::str($b['doc_number']??''),'doc_date'=>$b['doc_date']??null,'expiry_date'=>$b['expiry_date']??null,'alert_days'=>(int)($b['alert_days']??30),'description'=>$b['description']??null,'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Document created.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $d=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('documents').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$d)BOS_Helpers::error('NOT_FOUND','Document not found.',404);
        $f=['updated_by'=>$u->id];$map=['title','doc_number','doc_date','expiry_date','description'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['alert_days']))$f['alert_days']=(int)$b['alert_days'];
        BOS_DB::update(BOS_DB::t('documents'),$f,['id'=>$d->id]);BOS_Helpers::ok([],'Document updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('documents',$_GET['uuid'],$u);BOS_Helpers::ok([],'Document deleted.');}
}

// ═══════════════════════════════════════════════════════════════════════════
// BANK RECONCILIATION CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_BankReconController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$pp=min(100,max(1,(int)($_GET['per_page']??50)));$off=($page-1)*$pp;
        $t=BOS_DB::t('bank_statements');$where=['1=1'];$params=[];
        $acct=$_GET['filter']['bank_account_uuid']??$_GET['filter[bank_account_uuid]']??'';
        if($acct){$a=BOS_DB::get_var('SELECT id FROM `'.BOS_DB::t('bank_accounts').'` WHERE uuid=?',[$acct]);if($a){$where[]='bank_account_id=?';$params[]=$a;}}
        $status=$_GET['filter']['status']??$_GET['filter[status]']??'';if($status){$where[]='status=?';$params[]=$status;}
        $from=$_GET['filter']['date_from']??$_GET['filter[date_from]']??'';if($from){$where[]='transaction_date>=?';$params[]=$from;}
        $to=$_GET['filter']['date_to']??$_GET['filter[date_to]']??'';if($to){$where[]='transaction_date<=?';$params[]=$to;}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY transaction_date DESC LIMIT ? OFFSET ?",[...$params,$pp,$off]);
        BOS_Helpers::paginated($rows,$total,$page,$pp);
    }
    public static function import(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        if(empty($b['bank_account_uuid'])||empty($b['entries']))BOS_Helpers::error('VALIDATION_ERROR','bank_account_uuid and entries required.',422);
        $acct=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('bank_accounts').'` WHERE uuid=?',[$b['bank_account_uuid']]);
        if(!$acct)BOS_Helpers::error('NOT_FOUND','Bank account not found.',404);
        $imported=0;
        foreach((array)$b['entries'] as $entry){
            if(empty($entry['transaction_date']))continue;
            $debit=(float)($entry['debit_amount']??0);$credit=(float)($entry['credit_amount']??0);
            BOS_DB::insert(BOS_DB::t('bank_statements'),['uuid'=>BOS_Helpers::uuid(),'bank_account_id'=>$acct->id,'transaction_date'=>$entry['transaction_date'],'description'=>$entry['description']??null,'debit_amount'=>$debit,'credit_amount'=>$credit,'balance'=>isset($entry['balance'])?(float)$entry['balance']:null,'status'=>'Unmatched','created_by'=>$u->id]);
            $imported++;
        }
        BOS_Helpers::ok(['imported'=>$imported],"Imported $imported entries.");
    }
    public static function match_entry(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $stmt=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('bank_statements').'` WHERE uuid=?',[$uuid]);
        if(!$stmt)BOS_Helpers::error('NOT_FOUND','Statement entry not found.',404);
        BOS_DB::update(BOS_DB::t('bank_statements'),['status'=>'Manually_Matched'],['id'=>$stmt->id]);
        BOS_DB::insert(BOS_DB::t('reconciliation_matches'),['statement_id'=>$stmt->id,'match_type'=>$b['match_type']??'payment','matched_id'=>(int)($b['matched_id']??0),'match_confidence'=>'Manual','matched_by'=>$u->id]);
        BOS_Helpers::ok([],'Entry matched.');
    }
    public static function exclude(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];
        $stmt=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('bank_statements').'` WHERE uuid=?',[$uuid]);
        if(!$stmt)BOS_Helpers::error('NOT_FOUND','Entry not found.',404);
        BOS_DB::update(BOS_DB::t('bank_statements'),['status'=>'Excluded','notes'=>BOS_Helpers::param('reason','Excluded')],['id'=>$stmt->id]);
        BOS_Helpers::ok([],'Entry excluded.');
    }
    public static function summary(): void {
        BOS_Auth::require_auth();$t=BOS_DB::t('bank_statements');$params=[];$where=['1=1'];
        $acct=$_GET['bank_account_uuid']??'';if($acct){$a=BOS_DB::get_var('SELECT id FROM `'.BOS_DB::t('bank_accounts').'` WHERE uuid=?',[$acct]);if($a){$where[]='bank_account_id=?';$params[]=$a;}}
        $from=$_GET['date_from']??'';if($from){$where[]='transaction_date>=?';$params[]=$from;}
        $to=$_GET['date_to']??'';if($to){$where[]='transaction_date<=?';$params[]=$to;}
        $w=implode(' AND ',$where);
        $sum=BOS_DB::get_row("SELECT COALESCE(SUM(credit_amount),0) as total_credits,COALESCE(SUM(debit_amount),0) as total_debits,COUNT(CASE WHEN status IN('Matched','Manually_Matched') THEN 1 END) as matched_count,COUNT(CASE WHEN status='Unmatched' THEN 1 END) as unmatched_count,COUNT(*) as total_count FROM `$t` WHERE $w",$params);
        $pct=$sum->total_count>0?round(($sum->matched_count/$sum->total_count)*100,1):0;
        BOS_Helpers::ok(['total_credits'=>(float)$sum->total_credits,'total_debits'=>(float)$sum->total_debits,'matched_count'=>(int)$sum->matched_count,'unmatched_count'=>(int)$sum->unmatched_count,'pct_reconciled'=>$pct]);
    }
    public static function auto_match(): void {
        BOS_Auth::require_auth();
        $unmatched=BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('bank_statements').'` WHERE status="Unmatched" LIMIT 500');
        $matched=0;
        foreach($unmatched as $s){
            $amt=(float)$s->credit_amount>0?(float)$s->credit_amount:(float)$s->debit_amount;
            $pay=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('payments').'` WHERE amount_received=? AND payment_date=? AND deleted_at IS NULL LIMIT 1',[$amt,$s->transaction_date]);
            if($pay){BOS_DB::update(BOS_DB::t('bank_statements'),['status'=>'Matched'],['id'=>$s->id]);BOS_DB::insert(BOS_DB::t('reconciliation_matches'),['statement_id'=>$s->id,'match_type'=>'payment','matched_id'=>$pay->id,'match_confidence'=>'Auto','matched_by'=>0]);$matched++;}
        }
        BOS_Helpers::ok(['matched'=>$matched],"Auto-matched $matched entries.");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// NOTIFICATION CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_NotificationController {
    public static function index(): void {
        $u=BOS_Auth::require_auth();
        $rows=BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('notifications').'` WHERE user_id=? ORDER BY created_at DESC LIMIT 50',[$u->id]);
        BOS_Helpers::ok($rows);
    }
    public static function unread_count(): void {
        $u=BOS_Auth::require_auth();
        $c=(int)BOS_DB::get_var('SELECT COUNT(*) FROM `'.BOS_DB::t('notifications').'` WHERE user_id=? AND is_read=0',[$u->id]);
        BOS_Helpers::ok(['count'=>$c]);
    }
    public static function mark_read(): void {
        $u=BOS_Auth::require_auth();$id=(int)$_GET['id'];
        BOS_DB::update(BOS_DB::t('notifications'),['is_read'=>1,'read_at'=>BOS_Helpers::now()],['id'=>$id,'user_id'=>$u->id]);
        BOS_Helpers::ok([],'Marked as read.');
    }
    public static function mark_all_read(): void {
        $u=BOS_Auth::require_auth();
        BOS_DB::query('UPDATE `'.BOS_DB::t('notifications').'` SET is_read=1,read_at=NOW() WHERE user_id=? AND is_read=0',[$u->id]);
        BOS_Helpers::ok([],'All marked as read.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// COMMUNICATION LOG CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_CommLogController {
    public static function index(): void {
        BOS_Auth::require_auth();$params=[];
        $sql='SELECT * FROM `'.BOS_DB::t('communication_logs').'` WHERE deleted_at IS NULL';
        $et=$_GET['entity_type']??'';$eu=$_GET['entity_uuid']??'';
        if($et&&$eu){
            $tid=null;$table=$et==='client'?BOS_DB::t('clients'):BOS_DB::t('vendors');
            $rec=BOS_DB::get_row("SELECT id FROM `$table` WHERE uuid=?",[$eu]);if($rec)$tid=$rec->id;
            if($tid){$sql.=' AND entity_type=? AND entity_id=?';$params=[$et,$tid];}
        }
        $sql.=' ORDER BY created_at DESC LIMIT 100';
        BOS_Helpers::ok(BOS_DB::get_results($sql,$params));
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        if(empty($b['entity_type'])||empty($b['entity_uuid']))BOS_Helpers::error('VALIDATION_ERROR','entity_type and entity_uuid required.',422);
        $table=$b['entity_type']==='client'?BOS_DB::t('clients'):BOS_DB::t('vendors');
        $rec=BOS_DB::get_row("SELECT id FROM `$table` WHERE uuid=?",[$b['entity_uuid']]);
        if(!$rec)BOS_Helpers::error('NOT_FOUND','Entity not found.',404);
        BOS_DB::insert(BOS_DB::t('communication_logs'),['uuid'=>BOS_Helpers::uuid(),'entity_type'=>$b['entity_type'],'entity_id'=>$rec->id,'comm_type'=>BOS_Helpers::str($b['comm_type']??'Note'),'subject'=>BOS_Helpers::str($b['subject']??''),'details'=>$b['details']??null,'follow_up'=>(int)($b['follow_up']??0),'follow_up_date'=>$b['follow_up_date']??null,'created_by'=>$u->id]);
        BOS_Helpers::ok([],'Log entry added.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SERVICE PRESET CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_ServicePresetController {
    public static function index(): void {
        BOS_Auth::require_auth();
        BOS_Helpers::ok(BOS_DB::get_results('SELECT * FROM `'.BOS_DB::t('service_presets').'` WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order ASC'));
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        if(empty($b['name']))BOS_Helpers::error('VALIDATION_ERROR','name required.',422);
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('service_presets'),['uuid'=>$uuid,'name'=>BOS_Helpers::str($b['name']),'icon'=>BOS_Helpers::str($b['icon']??'📄'),'invoice_title'=>BOS_Helpers::str($b['invoice_title']??''),'description'=>$b['description']??null,'amount'=>(float)($b['amount']??0),'gst_rate'=>(float)($b['gst_rate']??18),'notes'=>$b['notes']??null,'terms'=>$b['terms']??null,'declaration'=>$b['declaration']??null,'template_id'=>BOS_Helpers::str($b['template_id']??'classic'),'color_theme'=>BOS_Helpers::str($b['color_theme']??'navy'),'sort_order'=>(int)($b['sort_order']??0),'is_active'=>1,'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Preset created.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $p=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('service_presets').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$p)BOS_Helpers::error('NOT_FOUND','Preset not found.',404);
        $f=['updated_by'=>$u->id];$map=['name','icon','invoice_title','description','notes','terms','declaration','template_id','color_theme'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['amount']))$f['amount']=(float)$b['amount'];if(isset($b['gst_rate']))$f['gst_rate']=(float)$b['gst_rate'];if(isset($b['sort_order']))$f['sort_order']=(int)$b['sort_order'];
        BOS_DB::update(BOS_DB::t('service_presets'),$f,['id'=>$p->id]);BOS_Helpers::ok([],'Preset updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('service_presets',$_GET['uuid'],$u);BOS_Helpers::ok([],'Preset deleted.');}
    public static function reorder(): void {
        BOS_Auth::require_auth();$b=BOS_Helpers::body();$uuids=$b['uuids']??[];
        foreach($uuids as $i=>$uuid){BOS_DB::update(BOS_DB::t('service_presets'),['sort_order'=>$i],['uuid'=>$uuid]);}
        BOS_Helpers::ok([],'Reordered.');
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PAYMENT REGISTER CONTROLLER
// ═══════════════════════════════════════════════════════════════════════════
class BOS_PaymentRegisterController {
    public static function index(): void {
        BOS_Auth::require_auth();
        $page=max(1,(int)($_GET['page']??1));$pp=min(100,max(1,(int)($_GET['per_page']??25)));$off=($page-1)*$pp;
        $t=BOS_DB::t('payment_register');$where=['deleted_at IS NULL'];$params=[];
        $search=$_GET['search']??'';if($search){$like='%'.$search.'%';$where[]='(client_name LIKE ? OR mobile_number LIKE ? OR utr_txn_id LIKE ?)';$params=[$like,$like,$like];}
        $w=implode(' AND ',$where);
        $total=(int)BOS_DB::get_var("SELECT COUNT(*) FROM `$t` WHERE $w",$params);
        $rows=BOS_DB::get_results("SELECT * FROM `$t` WHERE $w ORDER BY payment_date DESC, created_at DESC LIMIT ? OFFSET ?",[...$params,$pp,$off]);
        BOS_Helpers::paginated($rows,$total,$page,$pp);
    }
    public static function create(): void {
        $u=BOS_Auth::require_auth();$b=BOS_Helpers::body();
        foreach(['client_name','payment_date','amount_received'] as $r){if(empty($b[$r]))BOS_Helpers::error('VALIDATION_ERROR',"$r required.",422);}
        $uuid=BOS_Helpers::uuid();
        BOS_DB::insert(BOS_DB::t('payment_register'),['uuid'=>$uuid,'client_name'=>BOS_Helpers::str($b['client_name']),'mobile_number'=>BOS_Helpers::str($b['mobile_number']??''),'email_address'=>BOS_Helpers::email($b['email_address']??''),'service_description'=>$b['service_description']??null,'invoice_amount'=>(float)($b['invoice_amount']??0),'amount_received'=>(float)$b['amount_received'],'payment_date'=>$b['payment_date'],'payment_method'=>BOS_Helpers::str($b['payment_method']??'Cash'),'utr_txn_id'=>BOS_Helpers::str($b['utr_txn_id']??''),'pan_number'=>strtoupper(BOS_Helpers::str($b['pan_number']??'')),'gstin'=>strtoupper(BOS_Helpers::str($b['gstin']??'')),'address'=>$b['address']??null,'city'=>BOS_Helpers::str($b['city']??''),'state'=>BOS_Helpers::str($b['state']??''),'pin_code'=>BOS_Helpers::str($b['pin_code']??''),'tds_deducted'=>(float)($b['tds_deducted']??0),'tds_section'=>BOS_Helpers::str($b['tds_section']??''),'notes'=>$b['notes']??null,'status'=>BOS_Helpers::str($b['status']??'Received'),'created_by'=>$u->id]);
        BOS_Helpers::ok(['uuid'=>$uuid],'Payment register entry created.');
    }
    public static function update(): void {
        $u=BOS_Auth::require_auth();$uuid=$_GET['uuid'];$b=BOS_Helpers::body();
        $r=BOS_DB::get_row('SELECT id FROM `'.BOS_DB::t('payment_register').'` WHERE uuid=? AND deleted_at IS NULL',[$uuid]);
        if(!$r)BOS_Helpers::error('NOT_FOUND','Entry not found.',404);
        $f=['updated_by'=>$u->id];$map=['client_name','mobile_number','email_address','service_description','payment_date','payment_method','utr_txn_id','pan_number','gstin','address','city','state','pin_code','tds_section','notes','status'];
        foreach($map as $k){if(isset($b[$k]))$f[$k]=BOS_Helpers::str($b[$k]);}
        if(isset($b['amount_received']))$f['amount_received']=(float)$b['amount_received'];if(isset($b['invoice_amount']))$f['invoice_amount']=(float)$b['invoice_amount'];if(isset($b['tds_deducted']))$f['tds_deducted']=(float)$b['tds_deducted'];
        BOS_DB::update(BOS_DB::t('payment_register'),$f,['id'=>$r->id]);BOS_Helpers::ok([],'Entry updated.');
    }
    public static function delete(): void {$u=BOS_Auth::require_auth();BOS_Helpers::soft_delete('payment_register',$_GET['uuid'],$u);BOS_Helpers::ok([],'Entry deleted.');}
}
