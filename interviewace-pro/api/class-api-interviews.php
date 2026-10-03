<?php
defined('ABSPATH') || exit;

class IA_API_Interviews {
    public static function register(){
        $ns='ia/v1'; $a=['IA_Auth_Middleware','validate'];
        register_rest_route($ns,'/interviews',                    ['methods'=>'POST','callback'=>[__CLASS__,'create'],      'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)',        ['methods'=>'GET', 'callback'=>[__CLASS__,'get'],         'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/start',  ['methods'=>'POST','callback'=>[__CLASS__,'start'],       'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/turn',   ['methods'=>'POST','callback'=>[__CLASS__,'turn'],        'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/end',    ['methods'=>'POST','callback'=>[__CLASS__,'end'],         'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/turns',  ['methods'=>'GET', 'callback'=>[__CLASS__,'get_turns'],   'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/ideal',  ['methods'=>'POST','callback'=>[__CLASS__,'ideal'],       'permission_callback'=>$a]);
        register_rest_route($ns,'/interviews/(?P<id>\d+)/report-cost',['methods'=>'POST','callback'=>[__CLASS__,'report_cost'],'permission_callback'=>$a]);
    }

    public static function create(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid  = IA_Auth_Middleware::uid($req);
        $ok   = IA_Plan_Enforcer::can_create($uid);
        if (is_wp_error($ok)) return $ok;
        $type = sanitize_text_field($req->get_param('type')?:'');
        if (!$type) return new WP_Error('ia_val','Interview type required.',['status'=>400]);
        $pack = sanitize_text_field($req->get_param('company_pack')?:'');
        $lang = in_array($req->get_param('language_mode'),['english','hinglish'])?$req->get_param('language_mode'):'english';
        /* Explicit classification for gamification badge checks (hr_specialist/tech_specialist/multi_round) — see class-activator.php migration note. Defaulted from client input; falls back to 'general' rather than guessing from free-text $type. */
        $round_type = $req->get_param('round_type');
        $round_type = in_array($round_type,['technical','hr','behavioral','general'],true) ? $round_type : 'general';
        $plan = IA_Plan_Enforcer::get_plan($uid);
        $prof = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_profiles WHERE user_id=%d",$uid));
        $res_sum='No resume.'; $jd_sum='No JD.';
        if ($prof&&$prof->resume_parsed_json){$rd=json_decode($prof->resume_parsed_json,true);$res_sum='Skills: '.implode(', ',array_slice($rd['skills']??[],0,15)).'. '.($rd['summary']??'');}
        if ($prof&&$prof->jd_parsed_json){$jd=json_decode($prof->jd_parsed_json,true);$jd_sum='Required: '.implode(', ',array_slice($jd['required_skills']??[],0,10));}
        IA_Claude::set_ctx($uid, 0, 'generate_plan');
        $plan_data = IA_Claude::generate_plan(['interview_type'=>$type,'experience_level'=>$prof->experience_level??'fresher','resume_summary'=>$res_sum,'jd_summary'=>$jd_sum,'company_pack'=>$pack]);
        if (is_wp_error($plan_data)) $plan_data=self::default_plan($type);
        $wpdb->insert("{$p}ia_interviews",['user_id'=>$uid,'type'=>$type,'round_type'=>$round_type,'status'=>'created','plan_json'=>wp_json_encode($plan_data),'company_pack'=>$pack?:null,'language_mode'=>$lang,'subscription_tier'=>$plan]);
        $iid = (int)$wpdb->insert_id;
        IA_Plan_Enforcer::inc_usage($uid);
        $opening = "Hello ".($prof->name??'there').", thank you for joining us today. I'm Priya, and I'll be conducting your $type interview".($pack?" at $pack":'').". This is a relaxed conversation — I'm here to learn about you. Shall we start with you telling me a little about yourself?";
        return new WP_REST_Response(['success'=>true,'interview_id'=>$iid,'plan'=>$plan_data,'opening_line'=>$opening,'max_minutes'=>IA_Plan_Enforcer::max_minutes($uid)],201);
    }

    public static function get(WP_REST_Request $req){
        global $wpdb;
        $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_interviews WHERE id=%d AND user_id=%d",$id,$uid));
        if(!$row) return new WP_Error('ia_404','Interview not found.',['status'=>404]);
        return new WP_REST_Response(['interview'=>self::fmt($row)]);
    }

    public static function start(WP_REST_Request $req){
        global $wpdb;
        $uid=(int)(IA_Auth_Middleware::uid($req)); $id=(int)$req->get_param('id');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_interviews WHERE id=%d AND user_id=%d",$id,$uid));
        if(!$row) return new WP_Error('ia_404','Not found.',['status'=>404]);
        if($row->status==='active') return new WP_REST_Response(['success'=>true]);
        if($row->status!=='created') return new WP_Error('ia_state','Cannot start.',['status'=>422]);
        $wpdb->update("{$wpdb->prefix}ia_interviews",['status'=>'active','started_at'=>current_time('mysql')],['id'=>$id]);
        return new WP_REST_Response(['success'=>true,'started_at'=>current_time('mysql')]);
    }

    public static function turn(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        $iv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_interviews WHERE id=%d AND user_id=%d",$id,$uid));
        if(!$iv) return new WP_Error('ia_404','Not found.',['status'=>404]);
        if($iv->status!=='active') return new WP_Error('ia_state','Not active.',['status'=>422]);

        // Time limit for free
        if($iv->subscription_tier==='free'&&$iv->started_at){
            $elapsed=time()-strtotime($iv->started_at);
            if($elapsed>IA_Plan_Enforcer::max_minutes($uid)*60){
                self::do_end($id,$uid);
                return new WP_Error('ia_time','Free plan time limit reached.',['status'=>403,'code'=>'time_limit','upgrade_url'=>home_url('/app/billing')]);
            }
        }

        $text  = sanitize_textarea_field($req->get_param('transcript')?:'');
        $tnum  = max(1,(int)($req->get_param('turn_number')??1));
        $fj    = sanitize_text_field($req->get_param('filler_json')?:'{}');
        $wpm   = (int)($req->get_param('wpm')??0);
        $dur   = (int)($req->get_param('duration_ms')??0);
        if(!$text) return new WP_Error('ia_val','No transcript.',['status'=>400]);

        /*
         * IDEMPOTENCY FIX (root cause of the "double-submit duplicates the
         * conversation" gap): (interview_id, turn_number, role) is now a
         * unique key (see IA_Activator migration). If this exact user turn
         * was already recorded — e.g. the client retried after a network
         * timeout that actually succeeded server-side — we do NOT re-insert
         * or re-call Claude. We instead return the AI reply that was already
         * generated for it, so a retry is always safe and never costs a
         * duplicate AI call or corrupts conversation history.
         */
        $existing_ai = $wpdb->get_row($wpdb->prepare(
            "SELECT transcript FROM {$p}ia_turns WHERE interview_id=%d AND turn_number=%d AND role='ai' LIMIT 1",
            $id, $tnum + 1
        ));
        $already_have_user_turn = (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}ia_turns WHERE interview_id=%d AND turn_number=%d AND role='user' LIMIT 1",
            $id, $tnum
        ));
        if ($already_have_user_turn && $existing_ai) {
            $complete_again = strpos($existing_ai->transcript, '[INTERVIEW_COMPLETE]') !== false;
            /*
             * ROOT-CAUSE FIX: this replay branch used to omit `report_id`
             * even when $complete_again is true. The frontend's turn-submit
             * flow navigates to the report only when both is_complete AND
             * report_id are present (LiveInterview.tsx) — exactly the
             * scenario this idempotency path exists for is a network
             * failure on the COMPLETING turn, so a client retrying that
             * exact turn (the normal, expected use of this replay path)
             * would get is_complete=true with no id to navigate to,
             * stranding the user on the interview screen with no way to
             * reach a report that was, in fact, already generated. do_end()
             * always creates the ia_reports row before the original request
             * ever returns, so it's always safe to look it up here.
             */
            $report_id = $complete_again
                ? (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ia_reports WHERE interview_id=%d", $id))
                : null;
            return new WP_REST_Response([
                'success'=>true,
                'ai_response'=>trim(str_replace('[INTERVIEW_COMPLETE]','',$existing_ai->transcript)),
                'is_complete'=>$complete_again,
                'turn_count'=>$tnum+1,
                'report_id'=>$report_id ?: null,
                'replayed'=>true, // lets the client know this was a safe retry, not a new turn
            ]);
        }
        if (!$already_have_user_turn) {
            $inserted = $wpdb->insert("{$p}ia_turns",['interview_id'=>$id,'turn_number'=>$tnum,'role'=>'user','transcript'=>$text,'filler_words_json'=>$fj,'wpm'=>$wpm?:null,'duration_ms'=>$dur?:null,'timestamp_ms'=>round(microtime(true)*1000)]);
            if ($inserted === false) {
                // Unique-key collision from a concurrent duplicate request — treat as a safe no-op retry, not an error.
                return new WP_Error('ia_retry','Please retry — this turn is already being processed.',['status'=>409,'retryable'=>true]);
            }
        }

        // Build conversation history
        $all  = $wpdb->get_results($wpdb->prepare("SELECT role,transcript FROM {$p}ia_turns WHERE interview_id=%d ORDER BY turn_number ASC",$id));
        $conv = array_map(fn($t)=>['role'=>$t->role,'content'=>$t->transcript],$all);
        $prof = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_profiles WHERE user_id=%d",$uid));
        $plan = json_decode($iv->plan_json,true)??[];
        $plan_txt = implode("\n",array_map(fn($q,$i)=>($i+1).". [{$q['category']}] {$q['question']}",array_values($plan),array_keys($plan)));
        $res_sum  = ($prof&&$prof->resume_parsed_json)?'Skills: '.implode(', ',array_slice(json_decode($prof->resume_parsed_json,true)['skills']??[],0,12)):'';
        $scores   = array_column($wpdb->get_results($wpdb->prepare("SELECT quality_score FROM {$p}ia_turns WHERE interview_id=%d AND role='user' AND quality_score IS NOT NULL ORDER BY turn_number DESC LIMIT 3",$id)),'quality_score');

        IA_Claude::set_ctx($uid, $id, 'interview_turn');
        $ai_text = IA_Claude::interview_turn(
            ['interview_type'=>$iv->type,'candidate_name'=>$prof->name??'candidate','experience_level'=>$prof->experience_level??'fresher','resume_summary'=>$res_sum,'plan_text'=>$plan_txt,'company_note'=>$iv->company_pack?"Simulate a {$iv->company_pack} interview.":''],
            $conv, $tnum, array_map('intval',$scores), $iv->language_mode==='hinglish'
        );
        if(is_wp_error($ai_text)) return $ai_text;

        $complete = strpos($ai_text,'[INTERVIEW_COMPLETE]')!==false;
        $clean    = trim(str_replace('[INTERVIEW_COMPLETE]','',$ai_text));
        $anum     = $tnum+1;
        $wpdb->insert("{$p}ia_turns",['interview_id'=>$id,'turn_number'=>$anum,'role'=>'ai','transcript'=>$clean,'timestamp_ms'=>round(microtime(true)*1000)]);
        $wpdb->query($wpdb->prepare("UPDATE {$p}ia_interviews SET turn_count=%d WHERE id=%d",$anum,$id));

        if($complete){
            $rid=self::do_end($id,$uid);
            return new WP_REST_Response(['success'=>true,'ai_response'=>$clean,'is_complete'=>true,'report_id'=>$rid]);
        }
        return new WP_REST_Response(['success'=>true,'ai_response'=>$clean,'is_complete'=>false,'turn_count'=>$anum]);
    }

    public static function end(WP_REST_Request $req){
        $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        global $wpdb;
        $iv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_interviews WHERE id=%d AND user_id=%d",$id,$uid));
        if(!$iv) return new WP_Error('ia_404','Not found.',['status'=>404]);
        if(!in_array($iv->status,['active','created'])) return new WP_Error('ia_state','Already ended.',['status'=>422]);
        $rid=self::do_end($id,$uid);
        return new WP_REST_Response(['success'=>true,'report_id'=>$rid]);
    }

    public static function get_turns(WP_REST_Request $req){
        global $wpdb;
        $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ia_interviews WHERE id=%d",$id));
        if($owner!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_turns WHERE interview_id=%d ORDER BY turn_number ASC",$id));
        return new WP_REST_Response(['turns'=>array_map(fn($t)=>[
            'id'=>(int)$t->id,'turn_number'=>(int)$t->turn_number,'role'=>$t->role,'transcript'=>$t->transcript,
            'filler_words'=>$t->filler_words_json?json_decode($t->filler_words_json,true):[],
            'wpm'=>$t->wpm?(int)$t->wpm:null,'duration_ms'=>$t->duration_ms?(int)$t->duration_ms:null,
        ],$rows)]);
    }

    public static function ideal(WP_REST_Request $req){
        global $wpdb;
        $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        $iv=$wpdb->get_row($wpdb->prepare("SELECT type,user_id FROM {$wpdb->prefix}ia_interviews WHERE id=%d",$id));
        if(!$iv||(int)$iv->user_id!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        $prof=$wpdb->get_row($wpdb->prepare("SELECT experience_level FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        $r=IA_Claude::ideal_answer(sanitize_textarea_field($req->get_param('question')?:''),sanitize_textarea_field($req->get_param('user_answer')?:''),$iv->type,$prof->experience_level??'fresher');
        if(is_wp_error($r)) return $r;
        return new WP_REST_Response(['success'=>true,'ideal_answer'=>$r]);
    }

    private static function do_end(int $id, int $uid){
        global $wpdb; $p=$wpdb->prefix;
        $iv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_interviews WHERE id=%d",$id));
        $dur=$iv->started_at?max(0,time()-strtotime($iv->started_at)):0;
        $wpdb->update("{$p}ia_interviews",['status'=>'completed','ended_at'=>current_time('mysql'),'duration_seconds'=>$dur],['id'=>$id]);
        /*
         * ROOT-CAUSE FIX (found while wiring the frontend's usage/stats
         * screen, not in the original audit pass): this used to hand-roll
         * an INSERT against a column called `date` with a daily-granularity
         * value. The real `ia_usage` table (class-activator.php) has no
         * `date` column at all — it's keyed on `month_year varchar(7)`
         * (UNIQUE KEY uk_user_month). Every single interview completion was
         * silently throwing a MySQL "Unknown column" error here (swallowed
         * because $wpdb->query()'s return value was never checked), which
         * means monthly minute usage was NEVER actually being recorded —
         * IA_Plan_Enforcer::remaining_minutes() always saw 0 used, so
         * monthly quotas for pro/premium/b2b were silently unenforceable.
         * Fixed by routing through the one correct, already-existing
         * implementation (IA_Plan_Enforcer::inc_usage(), which writes
         * month_year correctly) instead of a second, broken, duplicate
         * implementation — eliminates the drift entirely rather than
         * patching the SQL in two places that can drift again.
         */
        IA_Plan_Enforcer::inc_usage($uid, (int)ceil($dur/60), false);
        $ex=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ia_reports WHERE interview_id=%d",$id));
        if(!$ex){$wpdb->insert("{$p}ia_reports",['interview_id'=>$id,'status'=>'pending']);}
        $rid=$ex?:$wpdb->insert_id;
        wp_schedule_single_event(time(),'ia_generate_report',[$id]);
        /* Kick WP-Cron immediately so report doesn't wait for next page visit */
        spawn_cron();
        return (int)$rid;
    }


    /**
     * Client reports Deepgram seconds + ElevenLabs chars used in a session.
     * Called once at interview end from the React frontend.
     */
    public static function report_cost(WP_REST_Request $req){
        global $wpdb;
        $uid = (int)IA_Auth_Middleware::uid($req);
        $id  = (int)$req->get_param('id');
        $iv  = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ia_interviews WHERE id=%d", $id
        ));
        if (!$iv || (int)$iv->user_id !== $uid)
            return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        if (class_exists('IA_Cost_Tracker')) {
            $dg_sec = (float)($req->get_param('deepgram_seconds') ?? 0);
            $el_chr = (int)($req->get_param('elevenlabs_chars')  ?? 0);
            if ($dg_sec > 0) IA_Cost_Tracker::record_deepgram($uid,    $id, $dg_sec);
            if ($el_chr > 0) IA_Cost_Tracker::record_elevenlabs($uid, $id, $el_chr);
        }
        return new WP_REST_Response(['success'=>true]);
    }

    private static function fmt(object $r){
        return ['id'=>(int)$r->id,'type'=>$r->type,'status'=>$r->status,'company_pack'=>$r->company_pack,'language_mode'=>$r->language_mode,'plan'=>json_decode($r->plan_json,true),'started_at'=>$r->started_at,'ended_at'=>$r->ended_at,'duration_seconds'=>(int)$r->duration_seconds,'turn_count'=>(int)$r->turn_count];
    }

    private static function default_plan(string $type){
        $qs=[
            ['introduction','Tell me about yourself and your background.'],
            ['introduction',"Why are you interested in a $type role?"],
            ['introduction','What are your key strengths for this position?'],
            ['resume','Walk me through your most recent work experience.'],
            ['resume','Tell me about a project you are most proud of.'],
            ['technical','What technical skills do you bring to this role?'],
            ['technical','How do you stay updated with industry trends?'],
            ['technical','Describe a technical challenge you solved recently.'],
            ['behavioral','Tell me about a time you had a conflict with a colleague.'],
            ['behavioral','Describe a situation where you had to meet a tight deadline.'],
            ['technical','How do you approach problems you have never seen before?'],
            ['behavioral','Give an example of when you showed initiative beyond your role.'],
            ['situational','If you disagreed with your manager on a decision, what would you do?'],
            ['technical','What are your areas for improvement and what are you doing about them?'],
            ['resume','Where do you see yourself in 3-5 years?'],
            ['resume','Why are you looking to move from your current position?'],
            ['technical','What salary range are you expecting?'],
            ['closing','Do you have any questions for me?'],
        ];
        return array_map(fn($q,$i)=>['question_number'=>$i+1,'category'=>$q[0],'question'=>$q[1],'is_star_expected'=>$q[0]==='behavioral','difficulty'=>$i<3?'easy':($i>12?'easy':'medium')],$qs,array_keys($qs));
    }
}

// ═══════════════════════════════════════════
// REPORTS
// ═══════════════════════════════════════════
