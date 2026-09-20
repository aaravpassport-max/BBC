<?php
defined('ABSPATH') || exit;

class IA_Report_Generator {
    public static function generate(int $interview_id){
        global $wpdb; $p=$wpdb->prefix;
        $updated=$wpdb->update("{$p}ia_reports",['status'=>'generating'],['interview_id'=>$interview_id,'status'=>'pending']);
        if(!$updated) return;
        try {
            $iv=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_interviews WHERE id=%d",$interview_id));
            if(!$iv) throw new RuntimeException("Interview $interview_id not found.");
            $turns=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}ia_turns WHERE interview_id=%d ORDER BY turn_number ASC",$interview_id),ARRAY_A);
            $prof=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ia_profiles WHERE user_id=%d",$iv->user_id));
            if(empty($turns)) throw new RuntimeException("No turns for interview $interview_id.");

            IA_Claude::set_ctx((int)$iv->user_id, $interview_id, 'generate_report');
            $data=IA_Claude::generate_report($turns,(array)$iv,$prof?(array)$prof:['experience_level'=>'fresher']);
            if(is_wp_error($data)) throw new RuntimeException($data->get_error_message());

            // Aggregate fillers and WPM
            $fa=[]; $wv=[];
            foreach($turns as $t){
                if($t['role']==='user'){
                    foreach((json_decode($t['filler_words_json']??'{}',true)?:[]) as $w=>$c) $fa[$w]=($fa[$w]??0)+(int)$c;
                    if(($t['wpm']??0)>0) $wv[]=(int)$t['wpm'];
                }
            }
            $avg_wpm=$wv?round(array_sum($wv)/count($wv)):null;
            $user_turns=array_values(array_filter($turns,fn($t)=>$t['role']==='user'));
            $bi=(int)($data['best_answer_index']??0); $wi=(int)($data['worst_answer_index']??0);

            $wpdb->update("{$p}ia_reports",[
                'status'=>'done',
                'overall_score'=>(int)($data['overall_score']??0),
                'comm_score'=>(int)($data['comm_score']??0),
                'tech_score'=>(int)($data['tech_score']??0),
                'conf_score'=>(int)($data['conf_score']??0),
                'recommendation'=>$data['recommendation']??null,
                'recommendation_reason'=>$data['recommendation_reason']??null,
                'summary'=>$data['summary']??null,
                'strengths_json'=>wp_json_encode($data['strengths']??[]),
                'weaknesses_json'=>wp_json_encode($data['weaknesses']??[]),
                'improvements_json'=>wp_json_encode($data['improvements']??[]),
                'communication_breakdown_json'=>wp_json_encode($data['communication_breakdown']??[]),
                'hiring_radar_json'=>wp_json_encode($data['hiring_radar']??[]),
                'question_evaluations_json'=>wp_json_encode($data['question_evaluations']??[]),
                'best_turn_number'=>$user_turns[$bi]['turn_number']??null,
                'worst_turn_number'=>$user_turns[$wi]['turn_number']??null,
                'filler_total_json'=>wp_json_encode($fa),
                'wpm_avg'=>$avg_wpm,
                'filler_word_feedback'=>$data['filler_word_feedback']??null,
                'wpm_feedback'=>$data['wpm_feedback']??null,
                'star_compliance'=>$data['star_compliance']??null,
                'generated_at'=>current_time('mysql'),
            ],['interview_id'=>$interview_id]);

            /*
             * ROOT-CAUSE FIX: IA_API_Gamification::after_interview_complete()
             * existed as a fully-written method but was never called from
             * anywhere in the codebase — the entire XP/badge system was
             * dead code that would show a permanently-empty gamification
             * screen. Report generation (here, once scores are known) is
             * the correct place to fire it. Wrapped in its own try/catch:
             * a gamification failure must never take down report
             * generation, which is the actually-critical path.
             */
            if (class_exists('IA_API_Gamification')) {
                try {
                    IA_API_Gamification::after_interview_complete((int)$iv->user_id, $interview_id, $data, $iv);
                } catch (\Throwable $ge) {
                    error_log("[InterviewAce] Gamification award failed for interview $interview_id: ".$ge->getMessage());
                }
            }

            // Email notification
            $user=get_userdata($iv->user_id);
            if($user){
                $score=$data['overall_score']??'N/A';
                $reco_map=['strong_hire'=>'Strong Hire 🌟','hire'=>'Hire ✅','borderline'=>'Borderline 🔄','reject'=>'Needs Improvement 📈'];
                $reco=$reco_map[$data['recommendation']??'']??'';
                /*
                 * ROOT-CAUSE FIX: this link used to be home_url('/report/'.$interview_id) —
                 * two bugs at once. (1) missing the SPA's /app basename prefix, same class of
                 * bug as every marketing-page CTA fixed this pass. (2) /report/:id (App.tsx)
                 * expects a *report* id, but only the *interview* id is known here — this would
                 * have 404'd even with the prefix fixed. The correct link is
                 * /app/history/:interviewId, which the SPA (ReportByInterview.tsx) resolves to
                 * the right report via GET /reports/by-interview/{interviewId} before rendering.
                 */
                $report_link = home_url('/app/history/'.$interview_id);
                wp_mail($user->user_email,"Your {$iv->type} interview report is ready","Hi {$user->display_name},\n\nYour report is ready!\n\nScore: $score/100\nVerdict: $reco\n\nView it here: $report_link\n\n— InterviewAce");
            }
        } catch(\Throwable $e) {
            $wpdb->update("{$p}ia_reports",['status'=>'failed','error_message'=>$e->getMessage()],['interview_id'=>$interview_id]);
            error_log("[InterviewAce] Report failed for interview $interview_id: ".$e->getMessage());
        }
    }
}
