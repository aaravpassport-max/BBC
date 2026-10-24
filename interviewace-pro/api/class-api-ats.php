<?php
defined('ABSPATH') || exit;

/* ══════════════════════════════════════════════════════════════════
   ATS Resume Scoring API
   Keyword match % vs JD, missing skills, section analysis
══════════════════════════════════════════════════════════════════ */
class IA_API_ATS {

    public static function register() {
        $a  = ['IA_Auth_Middleware','validate'];
        $ns = 'ia/v1';
        register_rest_route($ns, '/ats/score',    ['methods'=>'GET', 'callback'=>[__CLASS__,'score'],   'permission_callback'=>$a]);
        register_rest_route($ns, '/ats/keywords', ['methods'=>'GET', 'callback'=>[__CLASS__,'keywords'],'permission_callback'=>$a]);
    }

    /* GET /ats/score — full ATS analysis of resume vs JD */
    public static function score(WP_REST_Request $req) {
        global $wpdb;
        $uid  = (int)IA_Auth_Middleware::uid($req);
        $prof = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_parsed_json, jd_parsed_json, resume_text FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d", $uid
        ));

        if (!$prof || !$prof->resume_parsed_json)
            return new WP_Error('ia_missing', 'Upload your resume first to get ATS analysis.', ['status'=>400]);

        $resume = json_decode($prof->resume_parsed_json, true) ?: [];
        $has_jd = !empty($prof->jd_parsed_json);
        $jd     = $has_jd ? (json_decode($prof->jd_parsed_json, true) ?: []) : [];

        // Pull resume keywords
        $res_skills    = array_map('strtolower', array_merge(
            $resume['skills']           ?? [],
            $resume['technical_skills'] ?? []
        ));
        $res_skills = array_unique($res_skills);

        // Pull JD keywords
        $jd_required  = array_map('strtolower', $jd['required_skills']  ?? []);
        $jd_preferred = array_map('strtolower', $jd['preferred_skills']  ?? []);
        $jd_keywords  = array_map('strtolower', $jd['key_keywords']      ?? []);
        $all_jd       = array_unique(array_merge($jd_required, $jd_preferred, $jd_keywords));

        // Keyword match
        $matched        = [];
        $missing_req    = [];
        $missing_pref   = [];

        foreach ($jd_required as $kw) {
            if (self::fuzzy_match($kw, $res_skills)) $matched[] = $kw;
            else $missing_req[] = $kw;
        }
        foreach ($jd_preferred as $kw) {
            if (self::fuzzy_match($kw, $res_skills)) $matched[] = $kw;
            else $missing_pref[] = $kw;
        }
        $matched = array_unique($matched);

        // Score
        $req_total  = count($jd_required)  ?: 1;
        $pref_total = count($jd_preferred) ?: 1;
        $req_matched  = count(array_filter($jd_required,  fn($k) => self::fuzzy_match($k, $res_skills)));
        $pref_matched = count(array_filter($jd_preferred, fn($k) => self::fuzzy_match($k, $res_skills)));
        $keyword_score = $has_jd
            ? (int)round(($req_matched / $req_total) * 65 + ($pref_matched / $pref_total) * 20)
            : null;

        // Section completeness checks (resume quality regardless of JD)
        $sections = self::section_scores($resume, $prof->resume_text ?? '');
        $section_avg = array_sum(array_column($sections, 'score')) / max(1, count($sections));
        $overall = $has_jd
            ? (int)round($keyword_score * 0.75 + $section_avg * 0.25)
            : (int)round($section_avg);

        // Claude-powered suggestions if JD present
        $suggestions = [];
        if ($has_jd && count($missing_req) > 0) {
            $result = IA_Claude::ats_suggestions(
                $resume['summary'] ?? '',
                $missing_req,
                $missing_pref,
                $jd['role_title'] ?? 'the target role'
            );
            if (!is_wp_error($result)) $suggestions = $result;
        }

        return new WP_REST_Response([
            'has_jd'          => $has_jd,
            'overall_score'   => $overall,
            'keyword_score'   => $keyword_score,
            'section_score'   => (int)round($section_avg),
            'matched_keywords'=> array_values($matched),
            'missing_required'=> array_values($missing_req),
            'missing_preferred'=> array_values($missing_pref),
            'match_count'     => count($matched),
            'total_jd_keywords'=> count($all_jd),
            'sections'        => $sections,
            'suggestions'     => $suggestions,
            'resume_skills'   => array_values($res_skills),
            'jd_role'         => $jd['role_title'] ?? null,
        ]);
    }

    /* GET /ats/keywords — just keyword comparison, fast */
    public static function keywords(WP_REST_Request $req) {
        global $wpdb;
        $uid  = (int)IA_Auth_Middleware::uid($req);
        $prof = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_parsed_json, jd_parsed_json FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d", $uid
        ));
        if (!$prof || !$prof->resume_parsed_json)
            return new WP_Error('ia_missing', 'Upload resume first.', ['status'=>400]);

        $resume = json_decode($prof->resume_parsed_json, true) ?: [];
        $jd     = $prof->jd_parsed_json ? json_decode($prof->jd_parsed_json, true) : [];

        $res_skills = array_unique(array_map('strtolower', array_merge(
            $resume['skills'] ?? [], $resume['technical_skills'] ?? []
        )));
        $jd_required = array_map('strtolower', $jd['required_skills'] ?? []);

        $matched = array_filter($jd_required, fn($k) => self::fuzzy_match($k, $res_skills));
        $missing = array_filter($jd_required, fn($k) => !self::fuzzy_match($k, $res_skills));

        return new WP_REST_Response([
            'matched' => array_values($matched),
            'missing' => array_values($missing),
            'pct'     => $jd_required ? (int)round(count($matched)/count($jd_required)*100) : null,
        ]);
    }

    /* Fuzzy keyword match — handles plurals, partial matches, common abbreviations */
    private static function fuzzy_match(string $needle, array $haystack): bool {
        $n = strtolower(trim($needle));
        foreach ($haystack as $h) {
            $h = strtolower(trim($h));
            if ($h === $n) return true;
            if (strpos($h, $n) !== false || strpos($n, $h) !== false) return true;
            // Handle common variations
            $variants = [
                rtrim($n, 's'),                          // plurals
                $n . 's',
                str_replace(['.','js','ts'], '', $n),    // node.js → node
                str_replace(' ', '', $n),                // "react native" → "reactnative"
                str_replace('-', ' ', $n),               // "ci-cd" → "ci cd"
            ];
            foreach ($variants as $v) {
                if ($h === $v || strpos($h, $v) !== false) return true;
            }
        }
        return false;
    }

    /* Resume section completeness scoring */
    private static function section_scores(array $resume, string $raw_text): array {
        $out   = [];
        $text  = strtolower($raw_text);

        $summary_ok = !empty($resume['summary']) && strlen($resume['summary']) > 50;
        $out[] = ['section'=>'Summary / Objective','score'=>$summary_ok?90:25,'issue'=>$summary_ok?null:'Missing or very short summary section'];

        $skills_count = count(array_merge($resume['skills']??[], $resume['technical_skills']??[]));
        $skills_s = $skills_count >= 10 ? 95 : ($skills_count >= 5 ? 70 : ($skills_count > 0 ? 45 : 10));
        $out[] = ['section'=>'Skills','score'=>$skills_s,'issue'=>$skills_s<70?"Only $skills_count skills listed (aim for 10+)":null];

        $exp_count = count($resume['experience']??[]);
        $exp_s = $exp_count >= 3 ? 95 : ($exp_count >= 1 ? 70 : 20);
        $out[] = ['section'=>'Work Experience','score'=>$exp_s,'issue'=>$exp_s<70?'Limited experience entries — add more roles/projects':null];

        $edu_ok = count($resume['education']??[]) > 0;
        $out[] = ['section'=>'Education','score'=>$edu_ok?90:30,'issue'=>$edu_ok?null:'Education section appears missing'];

        // Check for ATS-unfriendly patterns in raw text
        $has_tables   = preg_match('/\|.+\|/', $raw_text);
        $has_columns  = substr_count($raw_text, "\t") > 20;
        $format_s     = ($has_tables || $has_columns) ? 50 : 88;
        $out[] = ['section'=>'ATS Formatting','score'=>$format_s,'issue'=>$format_s<70?'Possible tables or columns detected — ATS parsers may miss content':null];

        $proj_count = count($resume['projects']??[]);
        $proj_s = $proj_count >= 2 ? 90 : ($proj_count >= 1 ? 70 : 50);
        $out[] = ['section'=>'Projects','score'=>$proj_s,'issue'=>$proj_s<70?'Add 2+ projects with tech stack details':null];

        return $out;
    }
}
