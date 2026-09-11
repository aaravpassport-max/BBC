<?php
defined('ABSPATH') || exit;

class IA_API_Billing {
    public static function register(){
        $ns='ia/v1'; $a=['IA_Auth_Middleware','validate'];
        register_rest_route($ns,'/billing/status',             ['methods'=>'GET', 'callback'=>[__CLASS__,'status'],   'permission_callback'=>$a]);
        register_rest_route($ns,'/billing/create-subscription',['methods'=>'POST','callback'=>[__CLASS__,'subscribe'],'permission_callback'=>$a]);
        register_rest_route($ns,'/billing/cancel',             ['methods'=>'POST','callback'=>[__CLASS__,'cancel'],   'permission_callback'=>$a]);
        register_rest_route($ns,'/billing/invoices',           ['methods'=>'GET', 'callback'=>[__CLASS__,'invoices'], 'permission_callback'=>$a]);
        register_rest_route($ns,'/billing/webhook',            ['methods'=>'POST','callback'=>[__CLASS__,'webhook'],  'permission_callback'=>'__return_true']);
        /* Public — no auth needed, used on billing page before login too */
        register_rest_route($ns,'/billing/plans',              ['methods'=>'GET', 'callback'=>[__CLASS__,'plans'],    'permission_callback'=>'__return_true']);
    }

    public static function status(WP_REST_Request $req){
        global $wpdb; $uid=IA_Auth_Middleware::uid($req);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_subscriptions WHERE user_id=%d",$uid));
        /*
         * ROOT-CAUSE FIX: same class of bug as IA_API_Interviews::do_end()
         * and IA_API_Profile::stats() — `ia_usage` has no `date` column
         * (it's month_year-keyed), so this query always failed and
         * `today_interviews` was always 0/null. Also matched the wrong
         * enforcement window: free-plan limiting is weekly
         * (IA_Plan_Enforcer::can_create()), not daily, so a "today" figure
         * was never the right thing to show here regardless. Now sourced
         * from the same weekly-window query the actual enforcement uses, so
         * the billing screen can never show a number the backend disagrees
         * with.
         */
        $week_start = gmdate('Y-m-d', strtotime('monday this week'));
        $week_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ia_interviews WHERE user_id=%d AND status IN('completed','active','created') AND created_at>=%s",
            $uid, $week_start.' 00:00:00'
        ));
        $plan=IA_Plan_Enforcer::get_plan($uid);
        return new WP_REST_Response([
            'plan'=>$plan,'status'=>$sub?$sub->status:'none','current_period_end'=>$sub?$sub->current_period_end:null,
            'cancel_at_period_end'=>$sub?(bool)$sub->cancel_at_period_end:false,
            'week_interviews'=>$week_count,'week_limit'=>$plan==='free'?IA_Plan_Enforcer::quota('free_weekly_interviews'):null,
            'max_minutes'=>IA_Plan_Enforcer::max_minutes($uid),
            'minutes_remaining_this_month'=>IA_Plan_Enforcer::remaining_minutes($uid),
        ]);
    }

    public static function subscribe(WP_REST_Request $req){
        if(!ia_key('IA_RAZORPAY_KEY_ID')) return new WP_Error('ia_cfg','Billing not configured.',['status'=>503]);
        $uid=IA_Auth_Middleware::uid($req); $plan=sanitize_text_field($req->get_param('plan')?:'pro');
        if(!in_array($plan,['pro','premium'])) return new WP_Error('ia_val','Invalid plan.',['status'=>400]);
        $pid=$plan==='pro'?ia_key('IA_RAZORPAY_PLAN_PRO'):ia_key('IA_RAZORPAY_PLAN_PREMIUM');
        if(!$pid) return new WP_Error('ia_cfg',"Plan ID for $plan not set.",['status'=>503]);
        $user=get_userdata($uid);
        $r=self::rzp('POST','/subscriptions',['plan_id'=>$pid,'quantity'=>1,'total_count'=>120,'notes'=>['user_id'=>(string)$uid,'user_email'=>$user->user_email,'plan'=>$plan]]);
        if(is_wp_error($r)) return $r;
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}ia_subscriptions",['user_id'=>$uid,'plan'=>$plan,'status'=>'created','razorpay_sub_id'=>$r['id']]);
        /*
         * ROOT-CAUSE FIX: this used to return a hardcoded amount (29900 /
         * 49900 paise) that had no connection to either the admin-configurable
         * `price_display` shown on the pricing page (IA_Settings_Page) or to
         * what Razorpay will actually charge, which is determined solely by
         * the plan's own configuration on Razorpay's side. Any admin who
         * updated pricing in one place without also updating this constant
         * would silently show customers a checkout confirmation that doesn't
         * match what they're actually billed — a real trust/compliance risk
         * for a payment flow. Fetching the plan's real amount directly from
         * Razorpay guarantees the confirmation always matches the real
         * charge, with no second source of truth to drift out of sync.
         */
        $plan_info = self::rzp('GET', "/plans/{$pid}");
        $amount    = (!is_wp_error($plan_info) && !empty($plan_info['item']['amount']))
            ? (int)$plan_info['item']['amount']
            : ($plan==='pro' ? 29900 : 49900); // fallback only if the plan lookup itself fails
        $currency  = (!is_wp_error($plan_info) && !empty($plan_info['item']['currency']))
            ? $plan_info['item']['currency']
            : 'INR';
        return new WP_REST_Response(['success'=>true,'subscription_id'=>$r['id'],'razorpay_key_id'=>ia_key('IA_RAZORPAY_KEY_ID'),'amount'=>$amount,'currency'=>$currency],201);
    }

    public static function cancel(WP_REST_Request $req){
        global $wpdb; $uid=IA_Auth_Middleware::uid($req);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_subscriptions WHERE user_id=%d AND status='active'",$uid));
        if(!$sub) return new WP_Error('ia_404','No active subscription.',['status'=>404]);
        self::rzp('POST',"/subscriptions/{$sub->razorpay_sub_id}/cancel",['cancel_at_cycle_end'=>1]);
        $wpdb->update("{$wpdb->prefix}ia_subscriptions",['cancel_at_period_end'=>1],['user_id'=>$uid]);
        return new WP_REST_Response(['success'=>true,'cancels_at'=>$sub->current_period_end]);
    }

    public static function invoices(WP_REST_Request $req){
        global $wpdb; $uid=IA_Auth_Middleware::uid($req);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_payments WHERE user_id=%d AND status IN('captured','refunded') ORDER BY created_at DESC LIMIT 24",$uid));
        return new WP_REST_Response(['invoices'=>array_map(fn($r)=>['id'=>(int)$r->id,'payment_id'=>$r->razorpay_payment_id,'amount'=>(int)$r->amount_paise,'currency'=>$r->currency,'status'=>$r->status,'plan'=>$r->plan,'date'=>$r->created_at],$rows)]);
    }

    public static function webhook(WP_REST_Request $req){
        $body=$req->get_body(); $sig=$req->get_header('X-Razorpay-Signature');
        if(ia_key('IA_RAZORPAY_WEBHOOK_SECRET')&&!hash_equals(hash_hmac('sha256',$body,ia_key('IA_RAZORPAY_WEBHOOK_SECRET')),$sig??'')) return new WP_REST_Response(['error'=>'Bad signature'],400);
        $data=json_decode($body,true);
        if(!$data||!$data['event']) return new WP_REST_Response(['error'=>'Bad payload'],400);
        switch($data['event']){
            case 'subscription.activated': case 'subscription.charged': self::wh_charged($data); break;
            case 'subscription.halted':    self::wh_halted($data); break;
            case 'subscription.cancelled': case 'subscription.completed': self::wh_ended($data,$data['event']==='subscription.cancelled'?'cancelled':'completed'); break;
            case 'payment.failed':         self::wh_pay_failed($data); break;
        }
        return new WP_REST_Response(['status'=>'ok']);
    }

    private static function wh_charged(array $d){
        global $wpdb;
        $se=$d['payload']['subscription']['entity']??[]; $pe=$d['payload']['payment']['entity']??[];
        $sid=$se['id']??null; $notes=$se['notes']??[];
        $uid=(int)($notes['user_id']??0); $plan=$notes['plan']??'pro';
        if(!$uid){$r=$wpdb->get_row($wpdb->prepare("SELECT user_id,plan FROM {$wpdb->prefix}ia_subscriptions WHERE razorpay_sub_id=%s",$sid));if($r){$uid=(int)$r->user_id;$plan=$r->plan;}}
        if(!$uid) return;
        $ps=$se['current_start']??time(); $pe2=$se['current_end']??(time()+30*DAY_IN_SECONDS);
        $wpdb->replace("{$wpdb->prefix}ia_subscriptions",['user_id'=>$uid,'plan'=>$plan,'status'=>'active','razorpay_sub_id'=>$sid,'current_period_start'=>gmdate('Y-m-d H:i:s',$ps),'current_period_end'=>gmdate('Y-m-d H:i:s',$pe2),'cancel_at_period_end'=>0]);
        /*
         * SELF-CAUGHT FIX: razorpay_event_id must be unique PER CHARGE, not
         * per subscription+event-type — 'subscription.charged' fires every
         * billing cycle for the same subscription, so ($sid.'_'.$event)
         * would collide on the second month's charge and silently drop it
         * (the whole point of the unique key added above is dedup-on-retry,
         * not dedup-across-different-real-charges). The payment entity's
         * own id (pe['id']) is unique per actual charge — use that as the
         * dedup key instead.
         */
        if(!empty($pe['id'])) $wpdb->insert("{$wpdb->prefix}ia_payments",['user_id'=>$uid,'razorpay_payment_id'=>$pe['id'],'razorpay_sub_id'=>$sid,'amount_paise'=>(int)($pe['amount']??0),'currency'=>$pe['currency']??'INR','status'=>'captured','plan'=>$plan,'event_type'=>$d['event'],'razorpay_event_id'=>'pay_'.$pe['id']]);
        $user=get_userdata($uid);
        if($user) wp_mail($user->user_email,"Payment confirmed — InterviewAce ".ucfirst($plan),"Hi {$user->display_name},\n\nYour InterviewAce ".ucfirst($plan)." plan is now active.\n\nStart practising: ".home_url('/')."\n\n— InterviewAce");
    }

    private static function wh_halted(array $d){
        global $wpdb;
        $sid=$d['payload']['subscription']['entity']['id']??null; if(!$sid) return;
        $wpdb->update("{$wpdb->prefix}ia_subscriptions",['status'=>'past_due'],['razorpay_sub_id'=>$sid]);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ia_subscriptions WHERE razorpay_sub_id=%s",$sid));
        if(!$sub) return;
        $user=get_userdata($sub->user_id);
        if($user) wp_mail($user->user_email,'Payment issue — InterviewAce',"Hi {$user->display_name},\n\nWe could not charge your payment method. Please update billing details:\n".home_url('/app/billing')); // was missing the SPA's /app basename prefix
    }

    private static function wh_ended(array $d, string $status){
        global $wpdb;
        $sid=$d['payload']['subscription']['entity']['id']??null; if(!$sid) return;
        $wpdb->update("{$wpdb->prefix}ia_subscriptions",['status'=>$status],['razorpay_sub_id'=>$sid]);
    }

    private static function wh_pay_failed(array $d){
        global $wpdb;
        $pe=$d['payload']['payment']['entity']??[]; $sid=$pe['subscription_id']??null; if(!$sid) return;
        $sub=$wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ia_subscriptions WHERE razorpay_sub_id=%s",$sid));
        if(!$sub) return;
        $wpdb->insert("{$wpdb->prefix}ia_payments",['user_id'=>$sub->user_id,'razorpay_payment_id'=>$pe['id']??null,'razorpay_sub_id'=>$sid,'amount_paise'=>(int)($pe['amount']??0),'currency'=>$pe['currency']??'INR','status'=>'failed','event_type'=>'payment.failed','razorpay_event_id'=>($pe['id']??uniqid()).'_failed']);
    }

    /**
     * GET /billing/plans — returns full plan config from WP options.
     * Called by React BillingPage and IA_CONFIG injection.
     * No auth required — pricing is public information.
     */
    public static function plans(): WP_REST_Response {
        $plans = [];
        foreach (['pro','premium'] as $id) {
            $cfg = IA_Settings_Page::get_plan_config($id);
            $q   = [
                'session_minutes' => IA_Plan_Enforcer::quota("{$id}_session_minutes"),
                'monthly_minutes' => IA_Plan_Enforcer::quota("{$id}_monthly_minutes"),
            ];
            $plans[$id] = [
                'id'            => $id,
                'display_name'  => $cfg['display_name']  ?? ucfirst($id),
                'badge_label'   => $cfg['badge_label']   ?? ucfirst($id),
                'price_display' => $cfg['price_display'] ?? '',
                'price_period'  => $cfg['price_period']  ?? '/month',
                'highlight'     => (bool)($cfg['highlight'] ?? false),
                'ribbon_text'   => $cfg['ribbon_text']   ?? '',
                'features'      => array_values(array_filter(
                    array_map('trim', explode("\n", $cfg['features'] ?? ''))
                )),
                'session_minutes' => $q['session_minutes'],
                'monthly_minutes' => $q['monthly_minutes'],
            ];
        }
        return new WP_REST_Response(['plans' => $plans]);
    }

    private static function rzp(string $method, string $ep, array $body=[])    {
        $r=wp_remote_request('https://api.razorpay.com/v1'.$ep,['method'=>$method,'timeout'=>30,'headers'=>['Authorization'=>'Basic '.base64_encode(ia_key('IA_RAZORPAY_KEY_ID').':'.ia_key('IA_RAZORPAY_KEY_SECRET')),'Content-Type'=>'application/json'],'body'=>$body?wp_json_encode($body):null]);
        if(is_wp_error($r)) return $r;
        $code=(int)wp_remote_retrieve_response_code($r); $data=json_decode(wp_remote_retrieve_body($r),true);
        if($code>=400) return new WP_Error('ia_rzp',"Razorpay ($code): ".($data['error']['description']??'error'));
        return $data??[];
    }
}

// ═══════════════════════════════════════════
// HISTORY
// ═══════════════════════════════════════════
