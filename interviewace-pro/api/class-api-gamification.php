<?php
defined('ABSPATH') || exit;

/* ══════════════════════════════════════════════════════════════════
   Gamification API — XP, Badges, Levels, Review Queue
══════════════════════════════════════════════════════════════════ */
class IA_API_Gamification {

    /* ── Badge definitions ── */
    const BADGES = [
        'first_interview'  => ['🎤','First Step',       'Completed your first interview',         10],
        'streak_3'         => ['🔥','On Fire',           'Maintained a 3-day practice streak',     20],
        'streak_7'         => ['💫','Week Warrior',      '7-day practice streak',                  50],
        'streak_30'        => ['🏆','Iron Discipline',   '30-day practice streak',                 150],
        'score_70'         => ['⭐','Strong Performer',  'Scored 70+ in an interview',             25],
        'score_85'         => ['🌟','Top Performer',     'Scored 85+ in an interview',             50],
        'score_95'         => ['💎','Elite',             'Scored 95+ in an interview',             100],
        'interviews_5'     => ['📚','Getting Serious',  'Completed 5 interviews',                  20],
        'interviews_10'    => ['🎯','Dedicated Learner', 'Completed 10 interviews',                40],
        'interviews_25'    => ['🚀','Interview Pro',     'Completed 25 interviews',                100],
        'interviews_50'    => ['👑','Master Practitioner','Completed 50 interviews',              200],
        'perfect_comm'     => ['💬','Silver Tongue',     'Communication score 90+ in an interview',30],
        'perfect_tech'     => ['⚙️','Tech Wizard',       'Technical score 90+ in an interview',   30],
        'library_10'       => ['📖','Knowledge Builder', 'Saved 10 answers to your library',       15],
        'library_25'       => ['🧠','Deep Learner',      'Saved 25 answers to your library',       40],
        'resume_uploaded'  => ['📄','Profile Complete',  'Uploaded your resume',                   10],
        'hinglish_user'    => ['🇮🇳','Desi Pro',         'Completed an interview in Hinglish',     15],
        'multi_round'      => ['🎭','All Rounder',       'Completed 3 different round types',      40],
        'hr_specialist'    => ['🤝','Culture Champion',  'Completed 3 HR round interviews',        30],
        'tech_specialist'  => ['💻','Tech Guru',         'Completed 3 Technical round interviews', 30],
    ];

    /* ── Levels ── */
    const LEVELS = [
        0   => ['Fresher',    '🌱'],
        100 => ['Candidate',  '🎯'],
        300 => ['Practised',  '⭐'],
        600 => ['Confident',  '🔥'],
        1200=> ['Expert',     '💫'],
        2500=> ['Master',     '🏆'],
        5000=> ['Legend',     '💎'],
    ];

    public static function register() {
        $a = ['IA_Auth_Middleware','validate'];
        $ns = 'ia/v1';
        register_rest_route($ns, '/gamification/profile',           ['methods'=>'GET', 'callback'=>[__CLASS__,'get_profile'],    'permission_callback'=>$a]);
        register_rest_route($ns, '/gamification/review-queue',      ['methods'=>'GET', 'callback'=>[__CLASS__,'get_queue'],      'permission_callback'=>$a]);
        register_rest_route($ns, '/gamification/review',            ['methods'=>'POST','callback'=>[__CLASS__,'submit_review'],  'permission_callback'=>$a]);
        register_rest_route($ns, '/gamification/enqueue/(?P<id>\d+)',['methods'=>'POST','callback'=>[__CLASS__,'enqueue_answer'],'permission_callback'=>$a]);
    }

    /* ── GET /gamification/profile ── */
    public static function get_profile(WP_REST_Request $req) {
        global $wpdb; $p = $wpdb->prefix;
        $uid = (int)IA_Auth_Middleware::uid($req);

        $total_xp = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(xp_delta),0) FROM {$p}ia_xp_events WHERE user_id=%d", $uid
        ));

        $badges = $wpdb->get_results($wpdb->prepare(
            "SELECT badge_slug,badge_name,badge_desc,badge_icon,awarded_at
             FROM {$p}ia_badges WHERE user_id=%d ORDER BY awarded_at DESC", $uid
        ));

        $recent_xp = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type,xp_delta,description,created_at
             FROM {$p}ia_xp_events WHERE user_id=%d ORDER BY created_at DESC LIMIT 20", $uid
        ));

        [$level_name, $level_icon, $current_threshold, $next_threshold] = self::level_info($total_xp);
        $progress_pct = $next_threshold > $current_threshold
            ? round((($total_xp - $current_threshold) / ($next_threshold - $current_threshold)) * 100)
            : 100;

        return new WP_REST_Response([
            'total_xp'          => $total_xp,
            'level_name'        => $level_name,
            'level_icon'        => $level_icon,
            'level_progress_pct'=> $progress_pct,
            'xp_to_next'        => max(0, $next_threshold - $total_xp),
            'badges'            => $badges,
            'badge_count'       => count($badges),
            'recent_xp_events'  => $recent_xp,
            'all_badges'        => self::all_badge_definitions(),
        ]);
    }

    /* ── GET /gamification/review-queue ── */
    public static function get_queue(WP_REST_Request $req) {
        global $wpdb; $p = $wpdb->prefix;
        $uid = (int)IA_Auth_Middleware::uid($req);
        $today = gmdate('Y-m-d');

        $due = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*,sa.question,sa.user_answer,sa.ideal_answer,sa.tags
             FROM {$p}ia_review_queue q
             JOIN {$p}ia_saved_answers sa ON sa.id = q.saved_answer_id
             WHERE q.user_id=%d AND q.due_date <= %s
             ORDER BY q.due_date ASC, q.ease_factor ASC
             LIMIT 20",
            $uid, $today
        ));

        $upcoming = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_review_queue WHERE user_id=%d AND due_date > %s",
            $uid, $today
        ));

        return new WP_REST_Response([
            'due_today' => array_map([__CLASS__, 'fmt_queue_item'], $due),
            'due_count' => count($due),
            'upcoming_count' => $upcoming,
        ]);
    }

    /* ── POST /gamification/review ── */
    public static function submit_review(WP_REST_Request $req) {
        global $wpdb; $p = $wpdb->prefix;
        $uid     = (int)IA_Auth_Middleware::uid($req);
        $qid     = (int)$req->get_param('queue_id');
        $quality = (int)$req->get_param('quality'); /* 0=blackout,1=wrong,2=hard,3=ok,4=good,5=perfect */
        if ($quality < 0 || $quality > 5) return new WP_Error('ia_val','Quality must be 0-5.',['status'=>400]);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}ia_review_queue WHERE id=%d AND user_id=%d", $qid, $uid
        ));
        if (!$row) return new WP_Error('ia_404','Queue item not found.',['status'=>404]);

        /* SM-2 spaced repetition algorithm */
        $ef  = max(1.3, (float)$row->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02)));
        $n   = (int)$row->review_count + 1;
        if ($quality < 3) {
            /* Wrong answer — reset interval */
            $interval = 1;
            $n = 0;
        } elseif ($n === 1) {
            $interval = 1;
        } elseif ($n === 2) {
            $interval = 6;
        } else {
            $interval = (int)round((int)$row->interval_days * $ef);
        }
        $interval = max(1, min(365, $interval));
        $next_due = gmdate('Y-m-d', strtotime("+{$interval} days"));

        $wpdb->update("{$p}ia_review_queue", [
            'due_date'         => $next_due,
            'interval_days'    => $interval,
            'ease_factor'      => $ef,
            'review_count'     => $n,
            'last_quality'     => $quality,
            'last_reviewed_at' => current_time('mysql'),
        ], ['id' => $qid]);

        /* XP for review */
        self::award_xp($uid, 'review_complete', 3, 'Reviewed a flashcard');

        return new WP_REST_Response([
            'success'      => true,
            'next_due'     => $next_due,
            'interval_days'=> $interval,
            'ease_factor'  => round($ef, 2),
        ]);
    }

    /* ── POST /gamification/enqueue/:saved_answer_id ── */
    public static function enqueue_answer(WP_REST_Request $req) {
        global $wpdb; $p = $wpdb->prefix;
        $uid = (int)IA_Auth_Middleware::uid($req);
        $sid = (int)$req->get_param('id');

        $owner = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$p}ia_saved_answers WHERE id=%d", $sid
        ));
        if ($owner !== $uid) return new WP_Error('ia_403','Forbidden.',['status'=>403]);

        /* Upsert — don't reset if already queued */
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}ia_review_queue WHERE user_id=%d AND saved_answer_id=%d", $uid, $sid
        ));
        if ($exists) return new WP_REST_Response(['success'=>true,'already_queued'=>true]);

        $wpdb->insert("{$p}ia_review_queue", [
            'user_id'        => $uid,
            'saved_answer_id'=> $sid,
            'due_date'       => gmdate('Y-m-d'),
            'interval_days'  => 1,
            'ease_factor'    => 2.50,
            'review_count'   => 0,
        ]);

        return new WP_REST_Response(['success'=>true,'queue_id'=>$wpdb->insert_id]);
    }

    /* ═══════════════ STATIC AWARD METHODS (called from other controllers) ═══ */

    public static function after_interview_complete(int $uid, int $interview_id, array $report_data, object $interview) {
        global $wpdb; $p = $wpdb->prefix;
        $score      = (int)($report_data['overall_score'] ?? 0);
        $comm       = (int)($report_data['comm_score']    ?? 0);
        $tech       = (int)($report_data['tech_score']    ?? 0);
        $round_type = $interview->round_type ?? 'general';
        $lang_mode  = $interview->language_mode ?? 'english';

        /* XP for completing interview */
        $base_xp = 20;
        if ($score >= 80) $base_xp += 15;
        if ($score >= 90) $base_xp += 20;
        self::award_xp($uid, 'interview_complete', $base_xp, "Completed {$interview->type} interview (score: {$score})");

        /* Total interview count */
        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_interviews WHERE user_id=%d AND status='completed'", $uid
        ));

        /* Streak */
        $streak = self::get_streak($uid);

        /* Library size */
        $lib_size = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ia_saved_answers WHERE user_id=%d", $uid
        ));

        /* Multi-round types */
        $round_types = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT round_type FROM {$p}ia_interviews WHERE user_id=%d AND status='completed'", $uid
        ));
        $has_resume = !empty($wpdb->get_var($wpdb->prepare(
            "SELECT resume_url FROM {$p}ia_profiles WHERE user_id=%d", $uid
        )));

        /* HR / Tech interview counts */
        $hr_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ia_interviews WHERE user_id=%d AND round_type='hr' AND status='completed'", $uid));
        $tech_count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ia_interviews WHERE user_id=%d AND round_type='technical' AND status='completed'", $uid));

        /* Evaluate every badge */
        $checks = [
            'first_interview' => $total >= 1,
            'streak_3'        => $streak >= 3,
            'streak_7'        => $streak >= 7,
            'streak_30'       => $streak >= 30,
            'score_70'        => $score >= 70,
            'score_85'        => $score >= 85,
            'score_95'        => $score >= 95,
            'interviews_5'    => $total >= 5,
            'interviews_10'   => $total >= 10,
            'interviews_25'   => $total >= 25,
            'interviews_50'   => $total >= 50,
            'perfect_comm'    => $comm >= 90,
            'perfect_tech'    => $tech >= 90,
            'library_10'      => $lib_size >= 10,
            'library_25'      => $lib_size >= 25,
            'resume_uploaded' => $has_resume,
            'hinglish_user'   => $lang_mode === 'hinglish',
            'multi_round'     => count($round_types) >= 3,
            'hr_specialist'   => $hr_count >= 3,
            'tech_specialist' => $tech_count >= 3,
        ];

        $new_badges = [];
        foreach ($checks as $slug => $earned) {
            if (!$earned) continue;
            $def = self::BADGES[$slug] ?? null;
            if (!$def) continue;
            [$icon,$name,$desc,$xp] = $def;
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}ia_badges (user_id,badge_slug,badge_name,badge_desc,badge_icon) VALUES(%d,%s,%s,%s,%s)",
                $uid,$slug,$name,$desc,$icon
            ));
            if ($inserted) {
                self::award_xp($uid, 'badge_earned', $xp, "Badge earned: $name");
                $new_badges[] = ['slug'=>$slug,'name'=>$name,'desc'=>$desc,'icon'=>$icon,'xp'=>$xp];
            }
        }

        return $new_badges;
    }

    public static function award_xp(int $uid, string $event_type, int $xp, string $desc='') {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ia_xp_events", [
            'user_id'    => $uid,
            'event_type' => $event_type,
            'xp_delta'   => $xp,
            'description'=> $desc,
        ]);
    }

    public static function get_streak(int $uid): int {
        global $wpdb; $p = $wpdb->prefix;
        $dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT DATE(ended_at) as d FROM {$p}ia_interviews
             WHERE user_id=%d AND status='completed' ORDER BY d DESC LIMIT 60", $uid
        ));
        $streak = 0; $check = gmdate('Y-m-d');
        foreach ($dates as $d) {
            if ($d === $check) {
                $streak++;
                $check = gmdate('Y-m-d', strtotime($check . ' -1 day'));
            } else {
                break;
            }
        }
        return $streak;
    }

    /* ─── Helpers ─── */
    private static function level_info(int $xp): array {
        $levels   = self::LEVELS;
        $thresholds = array_keys($levels);
        sort($thresholds);

        $name = 'Fresher'; $icon = '🌱'; $cur = 0; $nxt = $thresholds[1] ?? 100;
        foreach ($thresholds as $i => $threshold) {
            if ($xp >= $threshold) {
                [$name,$icon] = $levels[$threshold];
                $cur  = $threshold;
                $nxt  = $thresholds[$i+1] ?? $threshold;
            }
        }
        return [$name, $icon, $cur, $nxt];
    }

    private static function all_badge_definitions(): array {
        $out = [];
        foreach (self::BADGES as $slug => [$icon,$name,$desc,$xp]) {
            $out[] = ['slug'=>$slug,'name'=>$name,'desc'=>$desc,'icon'=>$icon,'xp'=>$xp];
        }
        return $out;
    }

    private static function fmt_queue_item(object $r): array {
        return [
            'queue_id'       => (int)$r->id,
            'saved_answer_id'=> (int)$r->saved_answer_id,
            'question'       => $r->question,
            'user_answer'    => $r->user_answer,
            'ideal_answer'   => $r->ideal_answer,
            'tags'           => $r->tags ? explode(',', $r->tags) : [],
            'due_date'       => $r->due_date,
            'review_count'   => (int)$r->review_count,
            'interval_days'  => (int)$r->interval_days,
            'last_quality'   => $r->last_quality !== null ? (int)$r->last_quality : null,
        ];
    }
}
