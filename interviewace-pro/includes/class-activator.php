<?php
defined('ABSPATH') || exit;

class IA_Activator {
    /**
     * Migration safety net for existing installs upgrading into the new
     * uk_interview_turn_role UNIQUE key: if any legacy duplicate
     * (interview_id, turn_number, role) rows already exist from before the
     * idempotency fix, dbDelta's ADD UNIQUE KEY would fail outright and the
     * whole migration would silently not apply. We dedupe first (keeping
     * the earliest row per group, which is always the original — the
     * duplicates were always re-insertions of the same data), so the
     * upgrade path is safe on a real production DB, not just a fresh
     * install.
     */
    private static function migrate_dedupe_turns($wpdb, string $p): void {
        $table = "{$p}ia_turns";
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return; // fresh install, nothing to dedupe
        $wpdb->query(
            "DELETE t1 FROM $table t1
             INNER JOIN $table t2
             ON t1.interview_id = t2.interview_id
             AND t1.turn_number  = t2.turn_number
             AND t1.role         = t2.role
             AND t1.id > t2.id"
        );
    }

    /**
     * ROOT-CAUSE FIX ("Could not save your profile — a database error
     * occurred", confirmed live via the ErrorBlock diagnostic in
     * api/class-api-profile.php::update()): `experience_level` and
     * `language_pref` were defined as MySQL ENUM columns — a fixed,
     * exact list of allowed strings baked into the table itself, on top
     * of (and separate from) the identical allowlist api/class-api-
     * profile.php already enforces in PHP. dbDelta() is well known to be
     * unable to reliably ALTER an already-existing column's type or its
     * enum value list on an existing table — it only adds missing
     * columns/keys. Across this project's several rebuilds, the version
     * of this table that already exists on a real site can therefore be
     * running an enum definition that no longer matches the current
     * code's allowed values, so MySQL (correctly, per its own strict
     * rules) rejects any UPDATE/INSERT carrying a value it doesn't
     * recognize — silently, as a plain query failure, which is exactly
     * the "database error occurred" that surfaced once
     * class-api-profile.php stopped hiding write failures.
     *
     * The fix: stop using a database-level enum at all. PHP already does
     * 100% of the actual validation (see update()'s in_array() checks) —
     * the database column only needs to hold plain text. Converting to
     * VARCHAR permanently removes this whole class of schema-drift bug:
     * there is no longer a second, independently-versioned list for the
     * database and the code to disagree about. Existing data is
     * preserved as-is (MODIFY COLUMN keeps the stored values; only the
     * column's own type declaration changes), and this only runs against
     * a table that already exists — a fresh install's dbDelta CREATE
     * TABLE below already declares these as VARCHAR from the start.
     */
    private static function migrate_widen_profile_enums($wpdb, string $p): void {
        $table = "{$p}ia_profiles";
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return; // fresh install — CREATE TABLE below already uses varchar
        $wpdb->query("ALTER TABLE $table MODIFY COLUMN experience_level varchar(20) NOT NULL DEFAULT 'fresher'");
        $wpdb->query("ALTER TABLE $table MODIFY COLUMN language_pref varchar(10) NOT NULL DEFAULT 'english'");
    }

    public static function activate() {
        global $wpdb; $p=$wpdb->prefix;
        $cs=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        self::migrate_dedupe_turns($wpdb, $p);
        self::migrate_widen_profile_enums($wpdb, $p);

        dbDelta("CREATE TABLE {$p}ia_profiles (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            experience_level varchar(20) NOT NULL DEFAULT 'fresher',
            industry varchar(100) DEFAULT NULL,
            `current_role` varchar(100) DEFAULT NULL,
            target_role varchar(100) DEFAULT NULL,
            resume_url varchar(500) DEFAULT NULL,
            resume_text longtext DEFAULT NULL,
            resume_parsed_json longtext DEFAULT NULL,
            jd_text longtext DEFAULT NULL,
            jd_parsed_json longtext DEFAULT NULL,
            language_pref varchar(10) NOT NULL DEFAULT 'english',
            email_verified tinyint(1) NOT NULL DEFAULT 0,
            onboarding_complete tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uk_user (user_id)
        ) $cs;");

        /*
         * ROOT-CAUSE FIX: IA_API_Gamification::after_interview_complete()
         * (badge/XP awarding) reads and filters on `round_type` on this
         * table — a column that never existed here (only `type`, a free-text
         * label like "Software Engineer", did). Every query referencing it
         * was throwing "Unknown column" and failing silently, which broke
         * the hr_specialist/tech_specialist/multi_round badge checks
         * entirely. Added the real column, populated at interview creation
         * (see IA_API_Interviews::create()) from an explicit client-supplied
         * classification rather than guessed from free text, since the
         * badge logic needs a small closed set of values to check against.
         */
        dbDelta("CREATE TABLE {$p}ia_interviews (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            type varchar(100) NOT NULL,
            round_type enum('technical','hr','behavioral','general') NOT NULL DEFAULT 'general',
            status enum('created','active','completed','abandoned') NOT NULL DEFAULT 'created',
            plan_json longtext DEFAULT NULL,
            company_pack varchar(100) DEFAULT NULL,
            language_mode enum('english','hinglish') NOT NULL DEFAULT 'english',
            started_at datetime DEFAULT NULL,
            ended_at datetime DEFAULT NULL,
            duration_seconds int unsigned NOT NULL DEFAULT 0,
            turn_count smallint unsigned NOT NULL DEFAULT 0,
            subscription_tier enum('free','pro','premium','b2b') NOT NULL DEFAULT 'free',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_status (user_id, status),
            KEY idx_user_created (user_id, created_at)
        ) $cs;");

        /*
         * ROOT-CAUSE FIX: (interview_id, turn_number, role) is now UNIQUE.
         * Previously there was no constraint preventing a duplicate insert
         * for the same turn — a client retry after a network timeout (very
         * common mid-interview on mobile networks) could silently double a
         * turn in the conversation history, degrading AI question quality
         * and doubling the Claude API cost for that turn. The API layer
         * (IA_API_Interviews::turn()) now relies on this constraint for
         * idempotent retry handling: a duplicate insert fails fast and is
         * treated as a safe no-op / replay rather than new state.
         */
        dbDelta("CREATE TABLE {$p}ia_turns (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            interview_id bigint unsigned NOT NULL,
            turn_number smallint unsigned NOT NULL,
            role enum('ai','user') NOT NULL,
            transcript longtext NOT NULL,
            filler_words_json text DEFAULT NULL,
            wpm smallint unsigned DEFAULT NULL,
            duration_ms int unsigned DEFAULT NULL,
            timestamp_ms bigint unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_interview_turn_role (interview_id, turn_number, role),
            KEY idx_interview (interview_id)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_reports (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            interview_id bigint unsigned NOT NULL,
            status enum('pending','generating','done','failed') NOT NULL DEFAULT 'pending',
            overall_score tinyint unsigned DEFAULT NULL,
            comm_score tinyint unsigned DEFAULT NULL,
            tech_score tinyint unsigned DEFAULT NULL,
            conf_score tinyint unsigned DEFAULT NULL,
            recommendation enum('strong_hire','hire','borderline','reject') DEFAULT NULL,
            recommendation_reason text DEFAULT NULL,
            summary text DEFAULT NULL,
            strengths_json text DEFAULT NULL,
            weaknesses_json text DEFAULT NULL,
            improvements_json longtext DEFAULT NULL,
            communication_breakdown_json text DEFAULT NULL,
            hiring_radar_json text DEFAULT NULL,
            question_evaluations_json longtext DEFAULT NULL,
            filler_total_json text DEFAULT NULL,
            wpm_avg smallint unsigned DEFAULT NULL,
            filler_word_feedback text DEFAULT NULL,
            wpm_feedback text DEFAULT NULL,
            star_compliance text DEFAULT NULL,
            generated_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            retry_count tinyint unsigned NOT NULL DEFAULT 0,
            best_turn_number smallint unsigned DEFAULT NULL,
            worst_turn_number smallint unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_interview (interview_id)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_saved_answers (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            interview_id bigint unsigned DEFAULT NULL,
            question text NOT NULL,
            user_answer longtext DEFAULT NULL,
            ideal_answer longtext DEFAULT NULL,
            tags varchar(500) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_user (user_id)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_subscriptions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            plan enum('free','pro','premium','b2b') NOT NULL DEFAULT 'free',
            status enum('active','cancelled','past_due','completed','created') NOT NULL DEFAULT 'created',
            razorpay_sub_id varchar(100) DEFAULT NULL,
            current_period_start datetime DEFAULT NULL,
            current_period_end datetime DEFAULT NULL,
            cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uk_user (user_id)
        ) $cs;");

        /*
         * ROOT-CAUSE FIX: api/class-api-billing.php's webhook handlers
         * (wh_charged, wh_pay_failed) insert `event_type` and
         * `razorpay_event_id` — neither column existed on this table. Every
         * webhook-driven payment insert was silently failing (unknown
         * column), which meant GET /billing/invoices was permanently empty
         * for every user regardless of how many times they were actually
         * charged. Added both columns, plus a UNIQUE key on
         * razorpay_event_id: Razorpay webhooks are delivered at-least-once
         * and can retry the same event, so without this a retried webhook
         * would have double-recorded the same payment once the missing-
         * column bug was fixed — the unique key makes the insert itself
         * idempotent instead of needing extra application-level dedup logic.
         */
        dbDelta("CREATE TABLE {$p}ia_payments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            razorpay_payment_id varchar(100) DEFAULT NULL,
            razorpay_sub_id varchar(100) DEFAULT NULL,
            amount_paise int unsigned NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL DEFAULT 'INR',
            status enum('captured','failed','refunded','pending') NOT NULL DEFAULT 'pending',
            plan varchar(20) DEFAULT NULL,
            event_type varchar(60) DEFAULT NULL,
            razorpay_event_id varchar(150) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_user (user_id),
            UNIQUE KEY uk_event (razorpay_event_id)
        ) $cs;");

        /* minutes_used = cumulative per month, not per day — sessions can be split */
        dbDelta("CREATE TABLE {$p}ia_usage (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            month_year varchar(7) NOT NULL,
            interviews_count smallint unsigned NOT NULL DEFAULT 0,
            minutes_used smallint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_month (user_id, month_year)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_refresh_tokens (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            token_hash varchar(64) NOT NULL,
            expires_at datetime NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_hash (token_hash),
            KEY idx_user (user_id)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_otp_codes (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            otp_hash varchar(64) NOT NULL,
            purpose enum('email_verify','password_reset') NOT NULL DEFAULT 'email_verify',
            expires_at datetime NOT NULL,
            used tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY idx_user_purpose (user_id, purpose)
        ) $cs;");

        /* Signup rate limiting: 1/day per fingerprint, 3 total per IP */
        dbDelta("CREATE TABLE {$p}ia_signup_attempts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            fingerprint_hash varchar(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip (ip_address),
            KEY idx_fp (fingerprint_hash),
            KEY idx_created (created_at)
        ) $cs;");


        /* ── PHASE 2: Score aggregates for percentile benchmarking ── */
        dbDelta("CREATE TABLE {$p}ia_score_aggregates (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            interview_type varchar(100) NOT NULL,
            week_start date NOT NULL,
            score_p25 tinyint unsigned NOT NULL DEFAULT 0,
            score_p50 tinyint unsigned NOT NULL DEFAULT 0,
            score_p75 tinyint unsigned NOT NULL DEFAULT 0,
            score_avg tinyint unsigned NOT NULL DEFAULT 0,
            sample_size int unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_type_week (interview_type, week_start),
            KEY idx_type (interview_type)
        ) $cs;");

        /* ── PHASE 2: Gamification ── */
        dbDelta("CREATE TABLE {$p}ia_badges (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            badge_slug varchar(60) NOT NULL,
            badge_name varchar(120) NOT NULL,
            badge_desc varchar(255) NOT NULL,
            badge_icon varchar(10) NOT NULL DEFAULT '🏅',
            awarded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_badge (user_id, badge_slug),
            KEY idx_user (user_id)
        ) $cs;");

        dbDelta("CREATE TABLE {$p}ia_xp_events (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            event_type varchar(60) NOT NULL,
            xp_delta smallint NOT NULL DEFAULT 0,
            description varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) $cs;");

        /* ── PHASE 2: Spaced repetition for answer library ── */
        dbDelta("CREATE TABLE {$p}ia_review_queue (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            saved_answer_id bigint unsigned NOT NULL,
            due_date date NOT NULL,
            interval_days smallint unsigned NOT NULL DEFAULT 1,
            ease_factor decimal(4,2) NOT NULL DEFAULT 2.50,
            review_count smallint unsigned NOT NULL DEFAULT 0,
            last_quality tinyint unsigned DEFAULT NULL,
            last_reviewed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_answer (user_id, saved_answer_id),
            KEY idx_user_due (user_id, due_date)
        ) $cs;");


        /* ── Phase 2: API cost tracking ── */
        dbDelta("CREATE TABLE {$p}ia_api_costs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            interview_id bigint unsigned DEFAULT NULL,
            service enum('claude','deepgram','elevenlabs') NOT NULL,
            operation varchar(60) NOT NULL,
            units int unsigned NOT NULL DEFAULT 0,
            unit_type enum('tokens','seconds','characters') NOT NULL DEFAULT 'tokens',
            cost_paise int unsigned NOT NULL DEFAULT 0,
            recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_service_date (service, recorded_at),
            KEY idx_interview (interview_id)
        ) $cs;");

        /*
         * ROOT-CAUSE FIX (confirmed live: "Table 'wp_ia_profiles' doesn't
         * exist" — every save failing with a real, specific MySQL error):
         * dbDelta() silently failed to create ia_profiles (and potentially
         * other tables) on this site's very first activation — a known
         * dbDelta quirk on some hosts (strict SQL modes, certain
         * collations, or a restrictive DB user) — and activate() never
         * checked whether its CREATE TABLE calls actually succeeded before
         * unconditionally recording ia_db_version as done. Every later
         * plugin update then saw a matching version number and concluded
         * there was nothing to do, permanently masking the fact the table
         * was never really there. Fixed two ways: (1) verify the most
         * critical table actually exists right now, with a raw CREATE
         * TABLE IF NOT EXISTS fallback (far more reliable than dbDelta,
         * which is fussy about exact formatting) if it doesn't; (2) only
         * record ia_db_version as up to date when that verification
         * actually passes — so on a host where this keeps failing, every
         * single page load keeps retrying instead of giving up silently
         * after one bad attempt, and a clear admin-visible warning
         * explains why if it still can't succeed.
         */
        $tables_ok = self::verify_and_repair_core_tables($wpdb, $p, $cs);
        if ($tables_ok) {
            update_option('ia_db_version', IA_DB_VER);
            delete_option('ia_db_migration_error');
        }
        self::create_root_page();
        self::create_public_pages();
        self::ensure_pretty_permalinks();
        self::schedule_cron();
        flush_rewrite_rules();
    }

    /**
     * Verifies the plugin's most critical tables actually exist after the
     * dbDelta() calls above, and repairs any that don't with a plain,
     * direct CREATE TABLE IF NOT EXISTS — see the root-cause note in
     * activate() for why this exists. Returns true only when every table
     * checked here is confirmed present.
     */
    private static function verify_and_repair_core_tables($wpdb, string $p, string $cs): bool {
        $critical = [
            'ia_profiles' => "CREATE TABLE IF NOT EXISTS {$p}ia_profiles (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                name varchar(255) NOT NULL DEFAULT '',
                experience_level varchar(20) NOT NULL DEFAULT 'fresher',
                industry varchar(100) DEFAULT NULL,
                `current_role` varchar(100) DEFAULT NULL,
                target_role varchar(100) DEFAULT NULL,
                resume_url varchar(500) DEFAULT NULL,
                resume_text longtext DEFAULT NULL,
                resume_parsed_json longtext DEFAULT NULL,
                jd_text longtext DEFAULT NULL,
                jd_parsed_json longtext DEFAULT NULL,
                language_pref varchar(10) NOT NULL DEFAULT 'english',
                email_verified tinyint(1) NOT NULL DEFAULT 0,
                onboarding_complete tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uk_user (user_id)
            ) $cs",
            'ia_interviews' => "CREATE TABLE IF NOT EXISTS {$p}ia_interviews (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                type varchar(100) NOT NULL DEFAULT '',
                round_type varchar(30) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'created',
                company_pack varchar(100) DEFAULT NULL,
                language_mode varchar(10) NOT NULL DEFAULT 'english',
                started_at datetime DEFAULT NULL,
                ended_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_user (user_id)
            ) $cs",
        ];
        $all_ok = true;
        $errors = [];
        foreach ($critical as $name => $sql) {
            $table = "{$p}{$name}";
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            if (!$exists) {
                $wpdb->query($sql);
                $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            }
            if (!$exists) {
                $all_ok = false;
                $errors[$name] = $wpdb->last_error ?: '(no error text returned by the database)';
            }
        }
        if (!$all_ok) {
            update_option('ia_db_migration_error', $errors);
        }
        return $all_ok;
    }

    /**
     * ROOT-CAUSE FIX ("URL says /app/dashboard but the homepage shows"):
     * WordPress's default permalink setting is "Plain" (get_option
     * ('permalink_structure') === ''). Under Plain permalinks, WordPress
     * does NOT run custom add_rewrite_rule() rules at all — the entire
     * `/app/*` → app-page.php routing this plugin depends on (see the
     * rewrite rule in interviewace.php) silently never engages. Every
     * request to a path WordPress doesn't already recognize as a real
     * page (which is every `/app/...` URL) falls through to its default
     * behavior, which on most hosts resolves to the front page — so every
     * screen in the app looked identical to the homepage, no matter what
     * the browser's address bar said, because the browser's address bar
     * was the only thing that was actually changing.
     *
     * A fresh WordPress install ships with Plain permalinks by default,
     * so any site owner who installs this plugin without separately
     * knowing to visit Settings → Permalinks and pick a non-Plain
     * structure would hit this immediately and have no way to know why.
     * Since this plugin cannot function under Plain permalinks at all,
     * it now sets a sane default itself on activation — but only if the
     * site doesn't already have its own custom structure, so an existing
     * site's chosen permalink format (their own SEO URLs, etc.) is never
     * silently overwritten.
     */
    private static function ensure_pretty_permalinks(): void {
        if (get_option('permalink_structure')) return; // site already has a non-Plain structure — leave it alone
        update_option('permalink_structure', '/%postname%/');
        update_option('category_base', '');
        update_option('tag_base', '');
    }

    private static function create_root_page() {
        $ex = get_posts(['post_type'=>'page','post_status'=>'publish','name'=>'interviewace-root','posts_per_page'=>1]);
        if (!empty($ex)) { update_option('ia_root_page_id',$ex[0]->ID); return; }
        $id = wp_insert_post([
            'post_title'  =>'InterviewAce',
            'post_name'   =>'interviewace-root',
            'post_status' =>'publish',
            'post_type'   =>'page',
            'post_content'=>'',
            'post_author' =>get_current_user_id()?:1,
        ]);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id,'_wp_page_template','default');
            update_option('ia_root_page_id',$id);
            /*
             * ROOT-CAUSE FIX (black homepage bug): this used to also set
             * show_on_front/page_on_front to THIS page — which serves the
             * dark (#0F0F1A) React app shell via app-page.php. That made
             * the app shell the site's literal homepage ("/"), instead of
             * the marketing home.php template that create_public_pages()
             * below creates at the 'ia-home' slug. A visitor landing on
             * the bare domain saw the app's near-black boot background
             * (and nothing more, if app.js hadn't mounted yet), not the
             * marketing site. The front page is now set to the 'ia-home'
             * page instead, inside create_public_pages(). This page still
             * exists and its ID is still recorded (ia_root_page_id) — it's
             * what the rewrite rule below points every /app/* request at —
             * it just no longer doubles as the WordPress front page.
             */
        }
    }

    private static function create_public_pages() {
        $pages = [
            'ia-home'    => 'InterviewAce — AI-Powered Interview Preparation for Indian Job Seekers',
            'ia-pricing' => 'Pricing Plans — InterviewAce',
            'ia-about'   => 'About InterviewAce — Our Story',
            'ia-privacy' => 'Privacy Policy — InterviewAce',
            'ia-terms'   => 'Terms of Service — InterviewAce',
        ];
        foreach ($pages as $slug => $title) {
            $existing = get_page_by_path($slug);
            if (!$existing) {
                $new_id = wp_insert_post([
                    'post_title'  => $title,
                    'post_name'   => $slug,
                    'post_content'=> '',
                    'post_status' => 'publish',
                    'post_type'   => 'page',
                    'post_author' => 1,
                ]);
                if ($slug === 'ia-home' && $new_id && !is_wp_error($new_id)) {
                    $existing_id = $new_id;
                }
            } else {
                $existing_id = $existing->ID;
            }
            /* Make the marketing home page the site's actual front page. */
            if ($slug === 'ia-home' && !empty($existing_id)) {
                update_option('show_on_front','page');
                update_option('page_on_front',$existing_id);
            }
        }
    }

    private static function schedule_cron() {
        /* 'monthly'/'weekly' schedules are registered via the cron_schedules
           filter in IA_Cron_Manager::register_schedules(), which is hooked
           in interviewace.php before this runs. */
        if (!wp_next_scheduled('ia_daily_usage_reset'))
            wp_schedule_event(time(),'daily','ia_daily_usage_reset');
        if (!wp_next_scheduled('ia_monthly_usage_reset'))
            wp_schedule_event(time(),'monthly','ia_monthly_usage_reset');
        if (!wp_next_scheduled('ia_subscription_sync'))
            wp_schedule_event(time(),'hourly','ia_subscription_sync');
        if (!wp_next_scheduled('ia_cleanup_abandoned'))
            wp_schedule_event(time(),'daily','ia_cleanup_abandoned');
        if (!wp_next_scheduled('ia_cleanup_signup_attempts'))
            wp_schedule_event(time(),'daily','ia_cleanup_signup_attempts');
        if (!wp_next_scheduled('ia_weekly_aggregate'))
            wp_schedule_event(strtotime('next monday midnight'), 'weekly', 'ia_weekly_aggregate');
        if (!wp_next_scheduled('ia_weekly_digest'))
            wp_schedule_event(strtotime('next monday 08:00'), 'weekly', 'ia_weekly_digest');
    }

    /**
     * Returns every required table's CREATE TABLE IF NOT EXISTS statement,
     * keyed by table suffix (without prefix), built against the site's
     * actual prefix/charset. Used by IA_Diagnostics to generate a
     * copy-paste SQL block containing ONLY whichever tables are currently
     * missing on a given site, instead of the site owner having to run
     * (or figure out which parts of) the full 16-table file by hand.
     * Kept as raw CREATE TABLE IF NOT EXISTS (not dbDelta-formatted) on
     * purpose — this is meant to be pasted directly into phpMyAdmin,
     * which runs it exactly as written, same as verify_and_repair_core_tables().
     */
    public static function get_table_sql_map(string $p, string $cs): array {
        return [
            'ia_profiles' => "CREATE TABLE IF NOT EXISTS {$p}ia_profiles (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                name varchar(255) NOT NULL DEFAULT '',
                experience_level varchar(20) NOT NULL DEFAULT 'fresher',
                industry varchar(100) DEFAULT NULL,
                `current_role` varchar(100) DEFAULT NULL,
                target_role varchar(100) DEFAULT NULL,
                resume_url varchar(500) DEFAULT NULL,
                resume_text longtext DEFAULT NULL,
                resume_parsed_json longtext DEFAULT NULL,
                jd_text longtext DEFAULT NULL,
                jd_parsed_json longtext DEFAULT NULL,
                language_pref varchar(10) NOT NULL DEFAULT 'english',
                email_verified tinyint(1) NOT NULL DEFAULT 0,
                onboarding_complete tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uk_user (user_id)
            ) {$cs};",
            'ia_interviews' => "CREATE TABLE IF NOT EXISTS {$p}ia_interviews (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                type varchar(100) NOT NULL,
                round_type enum('technical','hr','behavioral','general') NOT NULL DEFAULT 'general',
                status enum('created','active','completed','abandoned') NOT NULL DEFAULT 'created',
                plan_json longtext DEFAULT NULL,
                company_pack varchar(100) DEFAULT NULL,
                language_mode enum('english','hinglish') NOT NULL DEFAULT 'english',
                started_at datetime DEFAULT NULL,
                ended_at datetime DEFAULT NULL,
                duration_seconds int unsigned NOT NULL DEFAULT 0,
                turn_count smallint unsigned NOT NULL DEFAULT 0,
                subscription_tier enum('free','pro','premium','b2b') NOT NULL DEFAULT 'free',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_status (user_id, status),
                KEY idx_user_created (user_id, created_at)
            ) {$cs};",
            'ia_turns' => "CREATE TABLE IF NOT EXISTS {$p}ia_turns (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                interview_id bigint unsigned NOT NULL,
                turn_number smallint unsigned NOT NULL,
                role enum('ai','user') NOT NULL,
                transcript longtext NOT NULL,
                filler_words_json text DEFAULT NULL,
                wpm smallint unsigned DEFAULT NULL,
                duration_ms int unsigned DEFAULT NULL,
                timestamp_ms bigint unsigned NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_interview_turn_role (interview_id, turn_number, role),
                KEY idx_interview (interview_id)
            ) {$cs};",
            'ia_reports' => "CREATE TABLE IF NOT EXISTS {$p}ia_reports (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                interview_id bigint unsigned NOT NULL,
                status enum('pending','generating','done','failed') NOT NULL DEFAULT 'pending',
                overall_score tinyint unsigned DEFAULT NULL,
                comm_score tinyint unsigned DEFAULT NULL,
                tech_score tinyint unsigned DEFAULT NULL,
                conf_score tinyint unsigned DEFAULT NULL,
                recommendation enum('strong_hire','hire','borderline','reject') DEFAULT NULL,
                recommendation_reason text DEFAULT NULL,
                summary text DEFAULT NULL,
                strengths_json text DEFAULT NULL,
                weaknesses_json text DEFAULT NULL,
                improvements_json longtext DEFAULT NULL,
                communication_breakdown_json text DEFAULT NULL,
                hiring_radar_json text DEFAULT NULL,
                question_evaluations_json longtext DEFAULT NULL,
                filler_total_json text DEFAULT NULL,
                wpm_avg smallint unsigned DEFAULT NULL,
                filler_word_feedback text DEFAULT NULL,
                wpm_feedback text DEFAULT NULL,
                star_compliance text DEFAULT NULL,
                generated_at datetime DEFAULT NULL,
                error_message text DEFAULT NULL,
                retry_count tinyint unsigned NOT NULL DEFAULT 0,
                best_turn_number smallint unsigned DEFAULT NULL,
                worst_turn_number smallint unsigned DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_interview (interview_id)
            ) {$cs};",
            'ia_saved_answers' => "CREATE TABLE IF NOT EXISTS {$p}ia_saved_answers (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                interview_id bigint unsigned DEFAULT NULL,
                question text NOT NULL,
                user_answer longtext DEFAULT NULL,
                ideal_answer longtext DEFAULT NULL,
                tags varchar(500) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_user (user_id)
            ) {$cs};",
            'ia_subscriptions' => "CREATE TABLE IF NOT EXISTS {$p}ia_subscriptions (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                plan enum('free','pro','premium','b2b') NOT NULL DEFAULT 'free',
                status enum('active','cancelled','past_due','completed','created') NOT NULL DEFAULT 'created',
                razorpay_sub_id varchar(100) DEFAULT NULL,
                current_period_start datetime DEFAULT NULL,
                current_period_end datetime DEFAULT NULL,
                cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uk_user (user_id)
            ) {$cs};",
            'ia_payments' => "CREATE TABLE IF NOT EXISTS {$p}ia_payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                razorpay_payment_id varchar(100) DEFAULT NULL,
                razorpay_sub_id varchar(100) DEFAULT NULL,
                amount_paise int unsigned NOT NULL DEFAULT 0,
                currency varchar(3) NOT NULL DEFAULT 'INR',
                status enum('captured','failed','refunded','pending') NOT NULL DEFAULT 'pending',
                plan varchar(20) DEFAULT NULL,
                event_type varchar(60) DEFAULT NULL,
                razorpay_event_id varchar(150) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_user (user_id),
                UNIQUE KEY uk_event (razorpay_event_id)
            ) {$cs};",
            'ia_usage' => "CREATE TABLE IF NOT EXISTS {$p}ia_usage (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                month_year varchar(7) NOT NULL,
                interviews_count smallint unsigned NOT NULL DEFAULT 0,
                minutes_used smallint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uk_user_month (user_id, month_year)
            ) {$cs};",
            'ia_refresh_tokens' => "CREATE TABLE IF NOT EXISTS {$p}ia_refresh_tokens (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                token_hash varchar(64) NOT NULL,
                expires_at datetime NOT NULL,
                revoked tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uk_hash (token_hash),
                KEY idx_user (user_id)
            ) {$cs};",
            'ia_otp_codes' => "CREATE TABLE IF NOT EXISTS {$p}ia_otp_codes (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                otp_hash varchar(64) NOT NULL,
                purpose enum('email_verify','password_reset') NOT NULL DEFAULT 'email_verify',
                expires_at datetime NOT NULL,
                used tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id), KEY idx_user_purpose (user_id, purpose)
            ) {$cs};",
            'ia_signup_attempts' => "CREATE TABLE IF NOT EXISTS {$p}ia_signup_attempts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                ip_address varchar(45) NOT NULL,
                fingerprint_hash varchar(64) NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ip (ip_address),
                KEY idx_fp (fingerprint_hash),
                KEY idx_created (created_at)
            ) {$cs};",
            'ia_score_aggregates' => "CREATE TABLE IF NOT EXISTS {$p}ia_score_aggregates (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                interview_type varchar(100) NOT NULL,
                week_start date NOT NULL,
                score_p25 tinyint unsigned NOT NULL DEFAULT 0,
                score_p50 tinyint unsigned NOT NULL DEFAULT 0,
                score_p75 tinyint unsigned NOT NULL DEFAULT 0,
                score_avg tinyint unsigned NOT NULL DEFAULT 0,
                sample_size int unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_type_week (interview_type, week_start),
                KEY idx_type (interview_type)
            ) {$cs};",
            'ia_badges' => "CREATE TABLE IF NOT EXISTS {$p}ia_badges (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                badge_slug varchar(60) NOT NULL,
                badge_name varchar(120) NOT NULL,
                badge_desc varchar(255) NOT NULL,
                badge_icon varchar(10) NOT NULL DEFAULT '🏅',
                awarded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_user_badge (user_id, badge_slug),
                KEY idx_user (user_id)
            ) {$cs};",
            'ia_xp_events' => "CREATE TABLE IF NOT EXISTS {$p}ia_xp_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                event_type varchar(60) NOT NULL,
                xp_delta smallint NOT NULL DEFAULT 0,
                description varchar(255) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (user_id),
                KEY idx_created (created_at)
            ) {$cs};",
            'ia_review_queue' => "CREATE TABLE IF NOT EXISTS {$p}ia_review_queue (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                saved_answer_id bigint unsigned NOT NULL,
                due_date date NOT NULL,
                interval_days smallint unsigned NOT NULL DEFAULT 1,
                ease_factor decimal(4,2) NOT NULL DEFAULT 2.50,
                review_count smallint unsigned NOT NULL DEFAULT 0,
                last_quality tinyint unsigned DEFAULT NULL,
                last_reviewed_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_user_answer (user_id, saved_answer_id),
                KEY idx_user_due (user_id, due_date)
            ) {$cs};",
            'ia_api_costs' => "CREATE TABLE IF NOT EXISTS {$p}ia_api_costs (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                interview_id bigint unsigned DEFAULT NULL,
                service enum('claude','deepgram','elevenlabs') NOT NULL,
                operation varchar(60) NOT NULL,
                units int unsigned NOT NULL DEFAULT 0,
                unit_type enum('tokens','seconds','characters') NOT NULL DEFAULT 'tokens',
                cost_paise int unsigned NOT NULL DEFAULT 0,
                recorded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (user_id),
                KEY idx_service_date (service, recorded_at),
                KEY idx_interview (interview_id)
            ) {$cs};",
        ];
    }
}