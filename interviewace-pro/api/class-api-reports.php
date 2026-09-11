<?php
defined('ABSPATH') || exit;

class IA_API_Reports {
    public static function register(){
        $a=['IA_Auth_Middleware','validate'];
        register_rest_route('ia/v1','/reports/(?P<id>\d+)',                     ['methods'=>'GET','callback'=>[__CLASS__,'get'],            'permission_callback'=>$a]);
        register_rest_route('ia/v1','/reports/by-interview/(?P<interview_id>\d+)',['methods'=>'GET','callback'=>[__CLASS__,'by_interview'],  'permission_callback'=>$a]);
        /*
         * ROOT-CAUSE FIX: a failed report generation used to be a permanent
         * dead end — cron/class-report-generator.php correctly recorded
         * status='failed' with an error message, but no route anywhere
         * let the user (or the system) try again. A candidate who completed
         * a full interview and hit a transient Claude/JSON failure got
         * nothing back, forever, unless an admin manually reset the DB row.
         */
        register_rest_route('ia/v1','/reports/(?P<id>\d+)/retry', ['methods'=>'POST','callback'=>[__CLASS__,'retry'], 'permission_callback'=>$a]);
    }

    public static function retry(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid=(int)IA_Auth_Middleware::uid($req); $rid=(int)$req->get_param('id');
        $row=$wpdb->get_row($wpdb->prepare("SELECT r.id,r.interview_id,r.status,r.retry_count,i.user_id FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE r.id=%d",$rid));
        if(!$row) return new WP_Error('ia_404','Report not found.',['status'=>404]);
        if((int)$row->user_id!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        if(!in_array($row->status,['failed'])) return new WP_Error('ia_state','Only a failed report can be retried.',['status'=>422]);
        $retries = (int)($row->retry_count ?? 0);
        if ($retries >= 3) return new WP_Error('ia_state','This report has failed too many times. Please contact support.',['status'=>422,'code'=>'max_retries']);
        $wpdb->update("{$p}ia_reports",['status'=>'pending','error_message'=>null,'retry_count'=>$retries+1],['id'=>$rid]);
        wp_schedule_single_event(time(),'ia_generate_report',[(int)$row->interview_id]);
        spawn_cron();
        return new WP_REST_Response(['success'=>true,'status'=>'pending']);
    }

    public static function get(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid=(int)IA_Auth_Middleware::uid($req); $rid=(int)$req->get_param('id');
        $row=$wpdb->get_row($wpdb->prepare("SELECT r.*,i.type,i.user_id,i.turn_count,i.duration_seconds,i.company_pack,i.language_mode FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE r.id=%d",$rid));
        if(!$row) return new WP_Error('ia_404','Report not found.',['status'=>404]);
        if((int)$row->user_id!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        if(in_array($row->status,['pending','generating'])) return new WP_REST_Response(['status'=>$row->status,'report_id'=>$rid,'message'=>'Report is being generated.'],202);
        /*
         * FIX (found wiring the frontend's "arrive via interview id" flow):
         * the failed branch used to omit report_id entirely, which made it
         * impossible for a client that only knows the interview_id (not the
         * report_id) to call POST /reports/{id}/retry — exactly the
         * "history list -> failed report -> retry" path a real user takes.
         * Always include report_id now, on every status branch.
         */
        if($row->status==='failed') return new WP_REST_Response(['status'=>'failed','report_id'=>$rid,'error'=>$row->error_message?:'Generation failed.']);
        return new WP_REST_Response(['report'=>self::fmt($row)]);
    }

    public static function by_interview(WP_REST_Request $req){
        global $wpdb;
        $uid=(int)IA_Auth_Middleware::uid($req); $iid=(int)$req->get_param('interview_id');
        $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ia_interviews WHERE id=%d",$iid));
        if($owner!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        $rid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ia_reports WHERE interview_id=%d",$iid));
        if(!$rid) return new WP_Error('ia_404','Report not found.',['status'=>404]);
        $req2=new WP_REST_Request('GET'); $req2->set_param('id',$rid); $req2->set_param('_uid',$uid);
        return self::get($req2);
    }

    private static function fmt(object $r){
        return [
            'id'=>(int)$r->id,'interview_id'=>(int)$r->interview_id,'status'=>$r->status,
            'type'=>$r->type,'company_pack'=>$r->company_pack,'duration_seconds'=>(int)$r->duration_seconds,'turn_count'=>(int)$r->turn_count,
            'overall_score'=>$r->overall_score!==null?(int)$r->overall_score:null,
            'comm_score'=>$r->comm_score!==null?(int)$r->comm_score:null,
            'tech_score'=>$r->tech_score!==null?(int)$r->tech_score:null,
            'conf_score'=>$r->conf_score!==null?(int)$r->conf_score:null,
            'recommendation'=>$r->recommendation,'recommendation_reason'=>$r->recommendation_reason,
            'summary'=>$r->summary,
            'strengths'=>$r->strengths_json?json_decode($r->strengths_json,true):[],
            'weaknesses'=>$r->weaknesses_json?json_decode($r->weaknesses_json,true):[],
            'improvements'=>$r->improvements_json?json_decode($r->improvements_json,true):[],
            'communication_breakdown'=>$r->communication_breakdown_json?json_decode($r->communication_breakdown_json,true):null,
            'hiring_radar'=>$r->hiring_radar_json?json_decode($r->hiring_radar_json,true):null,
            'question_evaluations'=>$r->question_evaluations_json?json_decode($r->question_evaluations_json,true):[],
            'filler_total'=>$r->filler_total_json?json_decode($r->filler_total_json,true):[],
            'wpm_avg'=>$r->wpm_avg?(int)$r->wpm_avg:null,
            'filler_word_feedback'=>$r->filler_word_feedback,'wpm_feedback'=>$r->wpm_feedback,
            'star_compliance'=>$r->star_compliance,'generated_at'=>$r->generated_at,
        ];
    }
}

// ═══════════════════════════════════════════
// BILLING
// ═══════════════════════════════════════════
