<?php
defined('ABSPATH') || exit;

class IA_Claude {
    const URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Model ID is read from an admin/site option (`ia_claude_model`) with a
     * conservative hardcoded fallback, instead of a hardcoded constant.
     * Root cause fixed: a stale/incorrect model ID hardcoded in source used
     * to be a one-line change away from a total production outage on every
     * interview turn, plan and report, with no way to hot-fix it without a
     * plugin redeploy. Now an admin can correct it from Settings instantly,
     * and IA_Settings_Page validates the value against a live model list.
     */
    const DEFAULT_MODEL = 'claude-sonnet-4-5-20250929';

    public static function model(): string {
        $m = get_option('ia_claude_model');
        return (is_string($m) && $m !== '') ? $m : self::DEFAULT_MODEL;
    }

    /** Set cost-tracking context for the next API call */
    public static function set_ctx(int $uid, ?int $iid, string $op): void {
        self::$_ctx = ['uid'=>$uid,'iid'=>$iid,'op'=>$op];
    }
    private static $_ctx = ['uid'=>0,'iid'=>null,'op'=>'api_call'];

    /**
     * Root cause fixed: previously a single wp_remote_post with no retry —
     * any transient network blip, Claude 5xx, or 429 rate-limit failed the
     * entire user-facing action immediately (losing a live-interview turn).
     * Now retries transient failures with exponential backoff + jitter, and
     * only surfaces an error to the caller after genuinely exhausting
     * retries. Distinguishes retryable (timeout, 429, 500-599) from
     * non-retryable (400, 401, 403, malformed request) failures so we don't
     * waste time/money retrying something that will never succeed.
     */
    private static function call(string $system, array $messages, int $max=1024, float $temp=0.7, int $max_retries=2){
        if (empty(ia_key('IA_CLAUDE_KEY'))) return new WP_Error('ia_no_key','Claude API key not set.', ['status'=>503]);

        $attempt = 0;
        $last_error = null;
        while ($attempt <= $max_retries) {
            $res = wp_remote_post(self::URL, [
                'timeout' => 55, // keep comfortably under typical PHP-FPM/proxy 60s ceilings
                'headers' => ['Content-Type'=>'application/json','x-api-key'=>ia_key('IA_CLAUDE_KEY'),'anthropic-version'=>'2023-06-01'],
                'body'    => wp_json_encode(['model'=>self::model(),'max_tokens'=>$max,'temperature'=>$temp,'system'=>$system,'messages'=>$messages]),
            ]);

            if (is_wp_error($res)) {
                $last_error = new WP_Error('ia_api_network', 'AI service network error: '.$res->get_error_message(), ['status'=>502,'retryable'=>true]);
            } else {
                $code = wp_remote_retrieve_response_code($res);
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if ($code === 200) {
                    if (!empty($data['usage']) && class_exists('IA_Cost_Tracker')) {
                        IA_Cost_Tracker::record_claude(
                            (int)(self::$_ctx['uid'] ?? 0),
                            isset(self::$_ctx['iid']) ? (int)self::$_ctx['iid'] : null,
                            (string)(self::$_ctx['op'] ?? 'api_call'),
                            (int)($data['usage']['input_tokens']  ?? 0),
                            (int)($data['usage']['output_tokens'] ?? 0)
                        );
                    }
                    return $data;
                }
                $msg = $data['error']['message'] ?? 'unknown error';
                $retryable = ($code === 429 || $code === 408 || ($code >= 500 && $code < 600));
                if ($code === 401 || $code === 403) {
                    error_log("[InterviewAce] Claude API auth failure ($code): check IA_CLAUDE_KEY / model access.");
                }
                if ($code === 404 && stripos($msg, 'model') !== false) {
                    error_log("[InterviewAce] Claude model '".self::model()."' rejected as not found — check Settings > AI Model.");
                }
                $last_error = new WP_Error('ia_api', "AI service error ($code): $msg", ['status'=>$code===429?429:502,'retryable'=>$retryable]);
                if (!$retryable) return $last_error;
            }

            $attempt++;
            if ($attempt <= $max_retries) {
                $backoff_ms = (int)(300 * (2 ** ($attempt - 1)) + random_int(0, 250));
                usleep($backoff_ms * 1000);
            }
        }
        return $last_error;
    }

    private static function text($r){
        if (is_wp_error($r)) return $r;
        return $r['content'][0]['text'] ?? '';
    }

    private static function json(string $sys, array $msgs, int $max=2048, float $temp=0.3){
        $r = self::call($sys, $msgs, $max, $temp);
        if (is_wp_error($r)) return $r;
        $t = self::text($r);
        if (is_wp_error($t)) return $t;
        $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $t));
        $d = json_decode($t, true);
        if (!is_array($d)) return new WP_Error('ia_json','Claude returned invalid JSON: '.substr($t,0,200));
        return $d;
    }

    public static function parse_resume(string $text){
        return self::json(
            'You are a resume parser. Return ONLY valid JSON, no other text.',
            [['role'=>'user','content'=>"Parse this resume and return JSON with keys: name, email, total_experience_years, current_role, skills (array), technical_skills (array), education (array of {degree,institution,year}), experience (array of {company,role,duration,highlights}), projects (array of {name,description,tech_stack}), certifications (array), summary (string).\n\nResume:\n$text"]],
            2048, 0.1
        );
    }

    public static function parse_jd(string $text){
        return self::json(
            'You are a job description analyser. Return ONLY valid JSON.',
            [['role'=>'user','content'=>"Analyse this job description and return JSON with keys: role_title, required_skills (array), preferred_skills (array), min_experience_years, key_responsibilities (array), tech_stack (array), key_keywords (array).\n\nJD:\n$text"]],
            1024, 0.1
        );
    }

    public static function generate_plan(array $ctx){
        $type  = $ctx['interview_type']   ?? 'General';
        $exp   = $ctx['experience_level'] ?? 'fresher';
        $res   = $ctx['resume_summary']   ?? 'No resume provided.';
        $jd    = $ctx['jd_summary']       ?? 'No JD provided.';
        $pack  = $ctx['company_pack']     ? "Simulate a {$ctx['company_pack']} interview." : '';

        return self::json(
            'You are an expert interview coach. Return ONLY a valid JSON array of question objects.',
            [['role'=>'user','content'=>"Generate 18 interview questions for a $type role. Experience: $exp. $pack\nCandidate: $res\nJob: $jd\n\nReturn JSON array where each item has: question_number(int), category(introduction|resume|technical|behavioral|situational|closing), question(string), is_star_expected(bool), difficulty(easy|medium|hard), follow_up_triggers(array of strings).\n\nDistribution: 3 introduction, 4 resume, 6 technical, 3 behavioral, 1 situational, 1 closing."]],
            3000, 0.5
        );
    }

    public static function interview_turn(array $ctx, array $conv, int $turn_num, array $recent_scores = [], bool $hinglish = false){
        $diff = '';
        if (count($recent_scores) >= 2) {
            $avg = array_sum($recent_scores)/count($recent_scores);
            if ($avg < 3.5) $diff = 'ADJUST: Candidate struggling — ask more accessible questions, be encouraging.';
            elseif ($avg > 7) $diff = 'ADJUST: Candidate excelling — probe deeper, ask more advanced follow-ups.';
        }
        $lang = $hinglish ? 'Speak in natural Hinglish (Hindi-English mix). Use phrases like "Achha, tell me more" or "Theek hai, let\'s continue". Keep technical terms in English.' : 'Speak in clear professional Indian English.';

        $sys = "You are Priya, a senior recruiter conducting a professional job interview for the role of {$ctx['interview_type']}.
Candidate: {$ctx['candidate_name']}, experience: {$ctx['experience_level']}.
{$ctx['company_note']}

Background: {$ctx['resume_summary']}
Plan: {$ctx['plan_text']}

RULES:
1. Ask EXACTLY ONE question per response. Never two.
2. Weak/vague answer → probe deeper before moving on.
3. Candidate mentions project/tech → ask one follow-up before moving on.
4. Behavioral questions missing STAR element → ask for the missing part.
5. Start responses with natural acknowledgment (e.g. 'That's interesting.' / 'I see.' / 'Good point.').
6. Never repeat a question already asked.
7. Turn {$turn_num} of ~18. After turn 15, transition: 'We're nearly done. Do you have any questions for me?'
8. When candidate has no more questions, give a warm closing and write exactly: [INTERVIEW_COMPLETE]
$diff
$lang";

        $msgs = array_map(fn($t) => ['role'=>$t['role']==='ai'?'assistant':'user','content'=>$t['content']], $conv);
        $r    = self::call($sys, $msgs, 512, 0.75);
        return self::text($r);
    }

    public static function generate_report(array $turns, array $interview, array $profile){
        $lines = [];
        $user_turns = [];
        foreach ($turns as $t) {
            $who = $t['role']==='ai' ? 'Interviewer (Priya)' : 'Candidate';
            $lines[] = "$who: {$t['transcript']}";
            if ($t['role']==='user') $user_turns[] = $t;
        }
        $transcript  = implode("\n\n", $lines);
        $filler_agg  = [];
        foreach ($user_turns as $t) {
            foreach ((json_decode($t['filler_words_json']??'{}', true) ?: []) as $w=>$c)
                $filler_agg[$w] = ($filler_agg[$w]??0)+(int)$c;
        }
        $filler_str  = $filler_agg ? implode(', ', array_map(fn($w,$c)=>"$w:$c", array_keys($filler_agg), $filler_agg)) : 'none';
        $wpm_vals    = array_filter(array_column(array_filter($user_turns, fn($t)=>($t['wpm']??0)>0), 'wpm'));
        $avg_wpm     = $wpm_vals ? round(array_sum($wpm_vals)/count($wpm_vals)) : 0;

        return self::json(
            'You are an expert interview evaluator. Return ONLY valid JSON, no markdown.',
            [['role'=>'user','content'=>"Evaluate this {$interview['type']} interview. Candidate experience: {$profile['experience_level']}.\nFiller words: $filler_str\nAvg WPM: $avg_wpm (optimal: 120-150)\n\nTRANSCRIPT:\n$transcript\n\nReturn JSON with ALL these keys:\n- overall_score (0-100)\n- comm_score (0-100)\n- tech_score (0-100)\n- conf_score (0-100)\n- recommendation (strong_hire|hire|borderline|reject)\n- recommendation_reason (string)\n- summary (3-4 sentences)\n- strengths (array of 3 strings)\n- weaknesses (array of 2-3 strings)\n- best_answer_index (0-based index of user turns, integer)\n- worst_answer_index (0-based index of user turns, integer)\n- filler_word_feedback (string)\n- wpm_feedback (string)\n- star_compliance (string)\n- communication_breakdown ({clarity,structure,vocabulary,grammar,listening} each 0-100)\n- hiring_radar ({technical_knowledge,problem_solving,communication,culture_fit,leadership_potential,growth_mindset} each 0-100)\n- improvements (array of {area,issue,suggestion})\n- question_evaluations (array of {question,answer_summary,score 0-10,feedback,ideal_answer_hint})"]],
            2500, 0.2
        );
    }

    public static function ideal_answer(string $question, string $answer, string $type, string $exp){
        $r = self::call(
            'You are an expert interview coach. Write a model answer. Start directly with the answer text, no preamble.',
            [['role'=>'user','content'=>"Interview type: $type. Experience: $exp.\nQuestion: $question\nCandidate's answer: $answer\n\nWrite a model answer (150-200 words) that is specific, uses STAR if behavioural, sounds natural and builds on what was good in their answer."]],
            400, 0.6
        );
        return self::text($r);
    }

    public static function ats_suggestions(string $resume_summary, array $missing_req, array $missing_pref, string $target_role) {
        $miss_r = implode(', ', array_slice($missing_req, 0, 10));
        $miss_p = implode(', ', array_slice($missing_pref, 0, 8));
        return self::json(
            'You are an expert career coach and ATS specialist. Return ONLY valid JSON.',
            [['role'=>'user','content'=>"Target role: $target_role\nResume summary: $resume_summary\nMissing required skills: $miss_r\nMissing preferred skills: $miss_p\n\nReturn JSON array of 3-5 actionable suggestions. Each item: {type: 'add_skill'|'rephrase'|'project'|'certification', title: string, detail: string, priority: 'high'|'medium'|'low'}."]],
            800, 0.4
        );
    }
}
