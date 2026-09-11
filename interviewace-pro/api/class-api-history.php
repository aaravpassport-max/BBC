<?php
defined('ABSPATH') || exit;

class IA_API_History {
    public static function register(){
        $a=['IA_Auth_Middleware','validate'];
        register_rest_route('ia/v1','/history',              ['methods'=>'GET', 'callback'=>[__CLASS__,'history'],     'permission_callback'=>$a]);
        register_rest_route('ia/v1','/library',              ['methods'=>'GET', 'callback'=>[__CLASS__,'get_lib'],     'permission_callback'=>$a]);
        register_rest_route('ia/v1','/library',              ['methods'=>'POST','callback'=>[__CLASS__,'save_answer'], 'permission_callback'=>$a]);
        register_rest_route('ia/v1','/library/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>[__CLASS__,'del_answer'],'permission_callback'=>$a]);
        register_rest_route('ia/v1','/benchmark/(?P<type>[^/]+)',['methods'=>'GET','callback'=>[__CLASS__,'benchmark'],'permission_callback'=>$a]);
    }

    public static function history(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid=(int)IA_Auth_Middleware::uid($req);
        $pg=max(1,(int)($req->get_param('page')??1)); $per=min(50,max(5,(int)($req->get_param('per_page')??10)));
        $type=sanitize_text_field($req->get_param('type')?:''); $offset=($pg-1)*$per;
        $where="WHERE i.user_id=%d AND i.status='completed'"; $args=[$uid];
        if($type){$where.=" AND i.type=%s";$args[]=$type;}
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ia_interviews i $where",...$args));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT i.id,i.type,i.ended_at,i.duration_seconds,i.turn_count,i.company_pack,i.language_mode,r.overall_score,r.comm_score,r.tech_score,r.conf_score,r.recommendation,r.status as report_status FROM {$p}ia_interviews i LEFT JOIN {$p}ia_reports r ON r.interview_id=i.id $where ORDER BY i.ended_at DESC LIMIT %d OFFSET %d",...array_merge($args,[$per,$offset])));
        return new WP_REST_Response(['interviews'=>array_map(fn($r)=>['id'=>(int)$r->id,'type'=>$r->type,'company_pack'=>$r->company_pack,'language_mode'=>$r->language_mode,'ended_at'=>$r->ended_at,'duration_seconds'=>(int)$r->duration_seconds,'turn_count'=>(int)$r->turn_count,'overall_score'=>$r->overall_score!==null?(int)$r->overall_score:null,'recommendation'=>$r->recommendation,'report_status'=>$r->report_status],$rows),'total'=>$total,'page'=>$pg,'pages'=>(int)ceil($total/$per)]);
    }

    public static function get_lib(WP_REST_Request $req){
        global $wpdb; $uid=(int)IA_Auth_Middleware::uid($req);
        $s=sanitize_text_field($req->get_param('search')?:'');
        $w='WHERE user_id=%d'; $args=[$uid];
        if($s){$w.=" AND (question LIKE %s OR tags LIKE %s)";$l='%'.$wpdb->esc_like($s).'%';$args[]=$l;$args[]=$l;}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_saved_answers $w ORDER BY created_at DESC LIMIT 100",...$args));
        return new WP_REST_Response(['answers'=>array_map(fn($r)=>['id'=>(int)$r->id,'question'=>$r->question,'user_answer'=>$r->user_answer,'ideal_answer'=>$r->ideal_answer,'tags'=>$r->tags?explode(',',$r->tags):[],'created_at'=>$r->created_at],$rows)]);
    }

    public static function save_answer(WP_REST_Request $req){
        global $wpdb; $uid=(int)IA_Auth_Middleware::uid($req);
        $q=sanitize_textarea_field($req->get_param('question')?:'');
        if(!$q) return new WP_Error('ia_val','Question required.',['status'=>400]);
        $ua=sanitize_textarea_field($req->get_param('user_answer')?:'');
        $ia=sanitize_textarea_field($req->get_param('ideal_answer')?:'');
        if(!$ia&&$ua){$r=IA_Claude::ideal_answer($q,$ua,sanitize_text_field($req->get_param('interview_type')?:'General'),sanitize_text_field($req->get_param('experience_level')?:'fresher'));if(!is_wp_error($r))$ia=$r;}
        $wpdb->insert("{$wpdb->prefix}ia_saved_answers",['user_id'=>$uid,'interview_id'=>(int)($req->get_param('interview_id')??0)?:null,'question'=>$q,'user_answer'=>$ua,'ideal_answer'=>$ia,'tags'=>sanitize_text_field($req->get_param('tags')?:'')]);
        return new WP_REST_Response(['success'=>true,'id'=>$wpdb->insert_id]);
    }

    public static function del_answer(WP_REST_Request $req){
        global $wpdb; $uid=(int)IA_Auth_Middleware::uid($req); $id=(int)$req->get_param('id');
        $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ia_saved_answers WHERE id=%d",$id));
        if($owner!==$uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);
        $wpdb->delete("{$wpdb->prefix}ia_saved_answers",['id'=>$id]);
        return new WP_REST_Response(['success'=>true]);
    }

    public static function benchmark(WP_REST_Request $req){
        global $wpdb; $p=$wpdb->prefix;
        $uid=(int)IA_Auth_Middleware::uid($req); $type=sanitize_text_field($req->get_param('type'));
        $agg=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_score_aggregates WHERE interview_type=%s ORDER BY week_start DESC LIMIT 1",$type));
        if(!$agg||$agg->sample_size<10) return new WP_REST_Response(['available'=>false]);
        $us=(int)$wpdb->get_var($wpdb->prepare("SELECT r.overall_score FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE i.user_id=%d AND i.type=%s AND r.status='done' ORDER BY r.generated_at DESC LIMIT 1",$uid,$type));
        $below=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE i.type=%s AND r.status='done' AND r.generated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND r.overall_score<%d",$type,$us));
        $pct=$agg->sample_size>0?round(($below/$agg->sample_size)*100):0;
        return new WP_REST_Response(['available'=>true,'type'=>$type,'p25'=>(int)$agg->score_p25,'p50'=>(int)$agg->score_p50,'p75'=>(int)$agg->score_p75,'sample_size'=>(int)$agg->sample_size,'user_score'=>$us,'percentile'=>$pct]);
    }
}
