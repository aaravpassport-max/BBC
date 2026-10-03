<?php
defined('ABSPATH') || exit;

class IA_API_Profile {
    public static function register() {
        $ns = 'ia/v1';
        $a  = ['IA_Auth_Middleware','validate'];
        register_rest_route($ns, '/profile',             ['methods'=>'GET', 'callback'=>[__CLASS__,'get'],         'permission_callback'=>$a]);
        register_rest_route($ns, '/profile',             ['methods'=>'PUT', 'callback'=>[__CLASS__,'update'],      'permission_callback'=>$a]);
        register_rest_route($ns, '/profile/jd',          ['methods'=>'POST','callback'=>[__CLASS__,'parse_jd'],    'permission_callback'=>$a]);
        register_rest_route($ns, '/profile/stats',       ['methods'=>'GET', 'callback'=>[__CLASS__,'stats'],       'permission_callback'=>$a]);
        register_rest_route($ns, '/profile/gap-analysis',['methods'=>'GET', 'callback'=>[__CLASS__,'gap_analysis'],'permission_callback'=>$a]);
    }

    public static function get(WP_REST_Request $req) {
        global $wpdb;
        $uid = IA_Auth_Middleware::uid($req);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        return new WP_REST_Response(['profile'=>$row?self::fmt($row):null]);
    }

    public static function update(WP_REST_Request $req) {
        global $wpdb;
        $uid=IA_Auth_Middleware::uid($req); $data=[];
        foreach(['name','experience_level','industry','current_role','target_role','language_pref'] as $f) {
            $v=$req->get_param($f); if($v===null) continue; $v=sanitize_text_field($v);
            if($f==='experience_level'&&!in_array($v,['fresher','0-2','2-5','5-10','10+'])) continue;
            if($f==='language_pref'&&!in_array($v,['english','hinglish'])) continue;
            if($f==='name'&&strlen($v)<2) return new WP_Error('ia_val','Name too short.',['status'=>400]);
            $data[$f]=$v;
        }
        if(!empty($data['target_role'])) $data['onboarding_complete']=1;
        /*
         * ROOT-CAUSE FIX: onboarding_complete could previously ONLY become
         * true as a side effect of submitting a non-empty target_role. The
         * frontend onboarding flow (Onboarding.tsx) is deliberately
         * skippable at every step, including the role step itself — but a
         * user who skipped all three steps had no way to ever flip this
         * flag. ProtectedRoute.tsx redirects any authenticated user with
         * onboarding_complete=false straight back to /onboarding on every
         * protected route, /dashboard included — so that user would land
         * back on the onboarding screen the instant they tried to leave it,
         * permanently, with no visible error and no way out. Accepting an
         * explicit onboarding_complete from the client lets the frontend
         * mark the flow done when the user finishes it, skips included.
         */
        $explicit_complete = $req->get_param('onboarding_complete');
        if ($explicit_complete !== null) $data['onboarding_complete'] = $explicit_complete ? 1 : 0;
        if(empty($data)) return new WP_Error('ia_val','No valid fields.',['status'=>400]);
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        /*
         * ROOT-CAUSE FIX ("submission succeeds, then bounces back to
         * onboarding anyway"): this used to call $wpdb->update()/insert()
         * and completely ignore the result — if the write failed for ANY
         * reason (a column missing from an out-of-date schema on a site
         * that never re-ran its DB migration, a truncated/invalid value
         * MySQL's strict mode rejected, a deadlock, anything), this
         * endpoint still returned {success:true} with the OLD unsaved row
         * underneath it. The frontend would optimistically believe the
         * save worked, navigate to /dashboard, and then get silently
         * bounced back to /onboarding the moment it re-checked the real
         * server state and found onboarding_complete still false — with
         * no error ever shown anywhere, which is exactly what looks like
         * an unexplained loop. Now the actual write result is checked: a
         * hard failure (false — a real DB error) is surfaced as a real
         * error response instead of a lie, and after writing, the row is
         * re-read and checked field-by-field against what was actually
         * asked to be saved — if anything didn't stick, that's reported
         * explicitly too, with $wpdb->last_error attached for diagnosis.
         */
        if ($exists) {
            $result = $wpdb->update("{$wpdb->prefix}ia_profiles", $data, ['user_id' => $uid]);
        } else {
            $data['user_id'] = $uid;
            $result = $wpdb->insert("{$wpdb->prefix}ia_profiles", $data);
        }
        /*
         * SELF-HEALING RETRY: the activation-time migration
         * (IA_Activator::migrate_widen_profile_enums()) converts
         * experience_level/language_pref from a fragile MySQL ENUM to
         * plain VARCHAR — but that migration only actually runs when
         * WordPress fires the activation check, which depends on the
         * site's stored ia_db_version option genuinely differing from
         * this plugin build's IA_DB_VER. If anything short-circuited that
         * (the option already matched from an earlier partial run, or the
         * ALTER TABLE itself failed silently for some host-specific
         * reason — restricted DB permissions, a table lock, etc.), the
         * column could still be the old rigid ENUM right now, and this
         * write would still fail the exact same way. Rather than staying
         * dependent on activation having gone perfectly, retry the widen
         * + write directly, once, right here — so the save can recover on
         * its own the very next attempt regardless of what happened at
         * activation.
         */
        $err = $wpdb->last_error ?? '';
        $looks_like_enum_mismatch = $err !== '' && (
            stripos($err, 'experience_level') !== false ||
            stripos($err, 'language_pref') !== false ||
            stripos($err, 'truncated') !== false ||
            stripos($err, 'Data too long') !== false
        );
        if ($result === false && $looks_like_enum_mismatch) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ia_profiles MODIFY COLUMN experience_level varchar(20) NOT NULL DEFAULT 'fresher'");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ia_profiles MODIFY COLUMN language_pref varchar(10) NOT NULL DEFAULT 'english'");
            if ($exists) {
                $result = $wpdb->update("{$wpdb->prefix}ia_profiles", $data, ['user_id' => $uid]);
            } else {
                $result = $wpdb->insert("{$wpdb->prefix}ia_profiles", $data);
            }
        }
        if ($result === false) {
            /*
             * DIAGNOSTIC FIX: $wpdb->last_error (the actual raw MySQL error
             * text) was already being attached in the 'data' payload, but
             * nothing in the frontend (ErrorBlock — see
             * frontend/src/components/StateViews.tsx) renders anything
             * beyond the plain `message` string, so the one piece of
             * information that would say EXACTLY what's wrong never
             * reached the screen. Folding it directly into the visible
             * message itself — no frontend change/rebuild needed for this
             * to show up, since the message text comes straight from this
             * response.
             */
            return new WP_Error('ia_db_write_failed',
                'Could not save your profile. Database says: ' . ($wpdb->last_error ?: '(no error text returned)') . ' — please screenshot this exact message and send it to support.',
                ['status' => 500, 'db_error' => $wpdb->last_error]
            );
        }
        if(!empty($data['name'])) wp_update_user(['ID'=>$uid,'display_name'=>$data['name']]);
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        if (!$row) {
            return new WP_Error('ia_db_write_failed',
                'Could not save your profile — no profile record was found after saving. Please try again, and if this keeps happening, contact support.',
                ['status' => 500]
            );
        }
        // Verify every field we asked to change actually holds the new value now.
        $mismatches = [];
        foreach ($data as $field => $expected) {
            if ($field === 'user_id') continue;
            $actual = $row->$field ?? null;
            if ((string) $actual !== (string) $expected) $mismatches[$field] = ['expected' => $expected, 'actual' => $actual];
        }
        if ($mismatches) {
            return new WP_Error('ia_db_write_mismatch',
                'Your profile save did not fully take effect. Details: ' . wp_json_encode($mismatches) . ($wpdb->last_error ? ' — database says: ' . $wpdb->last_error : '') . ' — please screenshot this exact message and send it to support.',
                ['status' => 500, 'mismatches' => $mismatches, 'db_error' => $wpdb->last_error]
            );
        }
        return new WP_REST_Response(['success'=>true,'profile'=>self::fmt($row)]);
    }

    public static function parse_jd(WP_REST_Request $req) {
        global $wpdb;
        $uid = IA_Auth_Middleware::uid($req);
        $jd  = sanitize_textarea_field($req->get_param('jd_text') ?? '');
        if (strlen($jd) < 50) return new WP_Error('ia_val','JD too short (min 50 chars).',['status'=>400]);

        /* Always save the raw JD text first so it's not lost even if Claude fails */
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d", $uid
        ));
        if ($exists) {
            $wpdb->update("{$wpdb->prefix}ia_profiles", ['jd_text'=>$jd], ['user_id'=>$uid]);
        } else {
            $wpdb->insert("{$wpdb->prefix}ia_profiles", ['user_id'=>$uid,'jd_text'=>$jd]);
        }

        /* Try to parse with Claude — optional, interview works without it */
        $parsed = IA_Claude::parse_jd(substr($jd, 0, 5000));
        if (is_wp_error($parsed)) {
            /* Claude key not set or API error — JD text is saved, parsing skipped */
            return new WP_REST_Response([
                'success' => true,
                'parsed'  => null,
                'note'    => 'JD saved. Configure Claude API key in WP Admin for full analysis.',
            ]);
        }

        $wpdb->update("{$wpdb->prefix}ia_profiles",
            ['jd_parsed_json' => wp_json_encode($parsed)],
            ['user_id' => $uid]
        );
        return new WP_REST_Response(['success'=>true,'parsed'=>$parsed]);
    }

    public static function gap_analysis(WP_REST_Request $req) {
        global $wpdb; $uid=IA_Auth_Middleware::uid($req);
        $prof=$wpdb->get_row($wpdb->prepare("SELECT resume_parsed_json,jd_parsed_json FROM {$wpdb->prefix}ia_profiles WHERE user_id=%d",$uid));
        if(!$prof||!$prof->resume_parsed_json) return new WP_Error('ia_missing','Upload your resume first.',['status'=>400]);
        if(!$prof->jd_parsed_json) return new WP_Error('ia_missing','Add a job description first.',['status'=>400]);
        $r=IA_Claude::ideal_answer('gap analysis',json_encode(['resume'=>json_decode($prof->resume_parsed_json,true),'jd'=>json_decode($prof->jd_parsed_json,true)]),'profile','analysis');
        if(is_wp_error($r)) return $r;
        return new WP_REST_Response(['success'=>true,'analysis'=>$r]);
    }

    public static function stats(WP_REST_Request $req) {
        global $wpdb; $uid=IA_Auth_Middleware::uid($req); $p=$wpdb->prefix;
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ia_interviews WHERE user_id=%d AND status='completed'",$uid));
        $avg=(float)$wpdb->get_var($wpdb->prepare("SELECT AVG(r.overall_score) FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE i.user_id=%d AND r.status='done'",$uid));
        $best=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(r.overall_score) FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE i.user_id=%d AND r.status='done'",$uid));
        $trend=$wpdb->get_results($wpdb->prepare("SELECT DATE(i.ended_at) as date,AVG(r.overall_score) as score FROM {$p}ia_reports r JOIN {$p}ia_interviews i ON i.id=r.interview_id WHERE i.user_id=%d AND r.status='done' GROUP BY DATE(i.ended_at) ORDER BY date ASC LIMIT 30",$uid));
        /*
         * ROOT-CAUSE FIX: this used to query ia_usage WHERE date=%s — that
         * column doesn't exist on ia_usage (it's month_year-keyed; see the
         * matching fix in IA_API_Interviews::do_end()), so this always
         * errored and $usage was always null. Separately, `today_limit=>1`
         * for free users didn't match the plan's REAL enforcement rule
         * (IA_Plan_Enforcer::can_create() — 2 interviews per WEEK, not
         * per day) — the stats screen was reporting a limit the backend
         * doesn't actually apply. Fixed to reflect the real rule: a
         * weekly count/limit sourced from the same query can_create() uses,
         * so the dashboard and the actual enforcement can never disagree.
         */
        $week_start = gmdate('Y-m-d', strtotime('monday this week'));
        $week_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_interviews WHERE user_id=%d AND status IN('completed','active','created') AND created_at>=%s",
            $uid, $week_start.' 00:00:00'
        ));
        $month_usage=$wpdb->get_row($wpdb->prepare("SELECT minutes_used,interviews_count FROM {$p}ia_usage WHERE user_id=%d AND month_year=%s",$uid,gmdate('Y-m')));
        $dates=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT DATE(ended_at) as d FROM {$p}ia_interviews WHERE user_id=%d AND status='completed' ORDER BY d DESC LIMIT 60",$uid));
        $streak=0; $check=gmdate('Y-m-d');
        foreach($dates as $d){if($d===$check){$streak++;$check=gmdate('Y-m-d',strtotime($check.' -1 day'));}else break;}
        $plan=IA_Plan_Enforcer::get_plan($uid);
        return new WP_REST_Response([
            'total_interviews'=>$total,'avg_score'=>$avg?round($avg,1):null,'best_score'=>$best?:null,
            'trend'=>$trend,'streak_days'=>$streak,'plan'=>$plan,
            'week_count'=>$week_count,'week_limit'=>$plan==='free'?IA_Plan_Enforcer::quota('free_weekly_interviews'):null,
            'minutes_used_this_month'=>(int)($month_usage->minutes_used??0),
            'minutes_remaining_this_month'=>IA_Plan_Enforcer::remaining_minutes($uid),
        ]);
    }

    private static function fmt($r) {
        return ['user_id'=>(int)$r->user_id,'name'=>$r->name,'experience_level'=>$r->experience_level,'industry'=>$r->industry,'current_role'=>$r->current_role,'target_role'=>$r->target_role,'language_pref'=>$r->language_pref,'email_verified'=>(bool)$r->email_verified,'onboarding_complete'=>(bool)$r->onboarding_complete,'has_resume'=>!empty($r->resume_url),'resume_parsed'=>$r->resume_parsed_json?json_decode($r->resume_parsed_json,true):null,'jd_parsed'=>$r->jd_parsed_json?json_decode($r->jd_parsed_json,true):null];
    }
}
