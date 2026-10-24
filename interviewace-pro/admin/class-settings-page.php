<?php
defined('ABSPATH') || exit;

class IA_Settings_Page {

    /* ── Default plan configuration ── */
    const PLAN_DEFAULTS = [
        'pro' => [
            'display_name'  => 'Pro',
            'badge_label'   => '⭐ Pro',
            'price_display' => '₹299',
            'price_period'  => '/month',
            'highlight'     => '0',
            'ribbon_text'   => '',
            'features'      => "Unlimited interviews per day\nUp to 60 min per session\nResume analysis & JD matching\nCompany-specific packs (TCS, Infosys…)\nSTAR answer coaching\nAdvanced reports with hiring radar\nPeer benchmarking\nAnswer Library",
        ],
        'premium' => [
            'display_name'  => 'Premium',
            'badge_label'   => '💎 Premium',
            'price_display' => '₹599',
            'price_period'  => '/month',
            'highlight'     => '1',
            'ribbon_text'   => 'Best value',
            'features'      => "Everything in Pro\nUp to 90 min per session\nSalary intelligence insights\nPriority report generation\nCoding round support\nHR round simulations\nEarly access to new features\nPriority support",
        ],
    ];

    public static function get_plan_config(string $plan): array {
        $defaults = self::PLAN_DEFAULTS[$plan] ?? [];
        $out = [];
        foreach ($defaults as $k => $default) {
            $v = get_option("ia_plan_{$plan}_{$k}");
            $out[$k] = ($v !== false && $v !== '') ? $v : $default;
        }
        return $out;
    }

    public static function init() {
        add_action('admin_menu',    [__CLASS__, 'add_menu']);
        add_action('admin_init',    [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'maybe_show_setup_notice']);
        add_action('wp_ajax_ia_save_quota', [__CLASS__, 'ajax_save_quota']);
    }

    public static function enqueue_media(string $hook): void {
        if (strpos($hook, 'interviewace') === false) return;
        wp_enqueue_media();
    }

    public static function add_menu() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_media']);
        add_menu_page('InterviewAce','InterviewAce','manage_options','interviewace',
            [__CLASS__,'render_page'],'dashicons-microphone',30);
        add_submenu_page('interviewace','API Keys',      'API Keys',      'manage_options','interviewace',         [__CLASS__,'render_page']);
        add_submenu_page('interviewace','Plan Builder',  'Plan Builder',  'manage_options','interviewace-plans',   [__CLASS__,'render_plans']);
        add_submenu_page('interviewace','Quota Settings','Quota Settings','manage_options','interviewace-quotas',  [__CLASS__,'render_quotas']);
    }

    public static function register_settings() {
        /* API keys */
        /* Priya avatar URL */
        register_setting('ia_settings_group', 'ia_priya_avatar_url', ['sanitize_callback'=>'esc_url_raw']);

        foreach (['ia_claude_key','ia_claude_model','ia_deepgram_key','ia_elevenlabs_key','ia_elevenlabs_voice',
            'ia_razorpay_key_id','ia_razorpay_key_secret','ia_razorpay_webhook_secret',
            'ia_razorpay_plan_pro','ia_razorpay_plan_premium',
            'ia_google_client_id','ia_google_client_secret'] as $k)
            register_setting('ia_settings_group', $k, ['sanitize_callback'=>'sanitize_text_field']);

        /* Plan config */
        foreach (['pro','premium'] as $plan) {
            foreach (array_keys(self::PLAN_DEFAULTS[$plan]) as $k) {
                $opt = "ia_plan_{$plan}_{$k}";
                $san = ($k === 'features') ? 'sanitize_textarea_field' : ($k === 'highlight' ? 'absint' : 'sanitize_text_field');
                register_setting('ia_plans_group', $opt, ['sanitize_callback' => $san]);
            }
        }

        /* Quota settings */
        foreach (['ia_quota_free_session_minutes','ia_quota_free_weekly_interviews','ia_quota_free_monthly_minutes',
            'ia_quota_pro_session_minutes','ia_quota_pro_monthly_minutes',
            'ia_quota_premium_session_minutes','ia_quota_premium_monthly_minutes',
            'ia_quota_b2b_session_minutes','ia_quota_b2b_monthly_minutes'] as $k)
            register_setting('ia_quota_group', $k, ['sanitize_callback'=>'absint']);
    }

    public static function maybe_show_setup_notice() {
        if (!current_user_can('manage_options')) return;
        if (get_current_screen()->id === 'toplevel_page_interviewace') return;
        if (get_option('ia_claude_key')) return;
        echo '<div class="notice notice-warning"><p><strong>InterviewAce:</strong> Paste your API keys to activate.
            <a href="'.admin_url('admin.php?page=interviewace').'">Open Settings →</a></p></div>';
    }

    /* ════════════════════════════════
       PAGE 1 — API KEYS
    ════════════════════════════════ */
    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $saved = isset($_GET['settings-updated']);
        ?>
        <div class="wrap"><?php self::admin_styles(); ?>
        <div class="ia-admin">
            <h1>🎤 InterviewAce <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px;">v<?php echo esc_html(IA_VERSION); ?></span></h1>
            <p class="ia-sub">AI-powered interview preparation platform</p>
            <?php if ($saved) echo '<div class="notice notice-success is-dismissible"><p>✓ Settings saved.</p></div>'; ?>

            <div style="display:flex;gap:10px;margin-bottom:26px;flex-wrap:wrap;">
                <a href="<?php echo home_url('/'); ?>" target="_blank" class="ia-btn-lnk">🚀 Open App</a>
                <a href="<?php echo admin_url('admin.php?page=interviewace-plans'); ?>"   class="ia-btn-lnk" style="background:#7c3aed;">🎨 Plan Builder</a>
                <a href="<?php echo admin_url('admin.php?page=interviewace-quotas'); ?>"  class="ia-btn-lnk" style="background:#059669;">⏱ Quota Settings</a>
                <a href="<?php echo admin_url('admin.php?page=interviewace-costs'); ?>"   class="ia-btn-lnk" style="background:#b45309;">💰 Cost Dashboard</a>
            </div>

            <?php if (isset($_GET['ia_reset'])) echo '<div class="notice notice-success is-dismissible"><p>✓ Test customer account reset — history, usage and onboarding cleared.</p></div>'; ?>

            <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:18px 20px;margin-bottom:26px;">
                <h3 style="margin:0 0 4px;">🧪 Try It Yourself</h3>
                <p style="margin:0 0 14px;color:#444;font-size:13px;">
                    Use the app as a customer would, without leaving WP Admin or creating a second account.
                    Neither button below touches your WordPress login — they only start an app session in this browser,
                    the same way a customer's own sign-in does. Click "Log Out" inside the app any time to end it.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank" style="margin:0;">
                        <?php wp_nonce_field('ia_try_as_admin'); ?>
                        <input type="hidden" name="action" value="ia_try_as_admin">
                        <button type="submit" class="ia-btn-lnk" style="background:#4f46e5;border:none;cursor:pointer;">
                            👤 Try it as yourself (unlimited)
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank" style="margin:0;">
                        <?php wp_nonce_field('ia_try_as_test_customer'); ?>
                        <input type="hidden" name="action" value="ia_try_as_test_customer">
                        <button type="submit" class="ia-btn-lnk" style="background:#0891b2;border:none;cursor:pointer;">
                            🧑‍💼 Try it as a Free-tier customer
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;"
                          onsubmit="return confirm('This deletes the test customer\'s interview history, reports, usage and saved answers so you can retest from scratch. Continue?');">
                        <?php wp_nonce_field('ia_reset_test_customer'); ?>
                        <input type="hidden" name="action" value="ia_reset_test_customer">
                        <button type="submit" class="ia-btn-lnk" style="background:#6b7280;border:none;cursor:pointer;">
                            ♻ Reset test customer
                        </button>
                    </form>
                </div>
                <p style="margin:12px 0 0;color:#666;font-size:12px;">
                    <strong>Try it as yourself</strong> opens the app as your own admin account — unlimited interviews, no session-length cap, so you can click through every feature freely.
                    <strong>Free-tier customer</strong> opens a separate, dedicated test account so you see exactly what a real free customer sees — the weekly interview limit, the 15-minute session cap, and the upgrade prompts. Each opens in a new tab.
                </p>
            </div>

            <?php
            $checks = [
                'Claude AI'    => (bool)get_option('ia_claude_key'),
                'Deepgram STT' => (bool)get_option('ia_deepgram_key'),
                'ElevenLabs'   => (bool)get_option('ia_elevenlabs_key'),
                'Razorpay'     => (bool)get_option('ia_razorpay_key_id'),
            ];
            echo '<div class="ia-status-bar">';
            foreach ($checks as $name => $ok)
                echo '<span class="ia-status-pill" style="background:'.($ok?'#d1fae5':'#fee2e2').';color:'.($ok?'#065f46':'#991b1b').';">'.($ok?'✓':'✗').' '.esc_html($name).'</span>';
            echo '<span style="margin-left:auto;font-size:13px;color:#666;">'.count(array_filter($checks)).'/'.count($checks).' configured</span></div>';
            ?>

            <form method="post" action="options.php">
            <?php settings_fields('ia_settings_group'); ?>
            <?php self::section('AI Core','Required for all AI features.',[
                ['ia_claude_key','Claude API Key','text','sk-ant-…','<a href="https://console.anthropic.com" target="_blank">Get key →</a>'],
                ['ia_claude_model','Claude Model ID','text',IA_Claude::DEFAULT_MODEL,'Fix this here immediately if interviews start failing with a "model" error — no code deploy needed. Leave blank to use the built-in default ('.esc_html(IA_Claude::DEFAULT_MODEL).').'],
            ]); ?>
            <?php self::section('Voice','Deepgram = speech-to-text. ElevenLabs = Priya\'s voice.',[
                ['ia_deepgram_key',     'Deepgram API Key',    'text','dg-…',                   '<a href="https://console.deepgram.com" target="_blank">Get key →</a>'],
                ['ia_elevenlabs_key',   'ElevenLabs API Key',  'text','el-…',                   '<a href="https://elevenlabs.io" target="_blank">Get key →</a> Optional — browser TTS used as fallback.'],
                ['ia_elevenlabs_voice', 'ElevenLabs Voice ID', 'text','EXAVITQu4vr4xnSDxMaL',  'Leave default for Priya\'s voice or paste any voice ID.'],
            ]); ?>
            <?php self::section('Payments (Razorpay)','Create plan IDs in Razorpay Dashboard → Subscriptions → Plans.',[
                ['ia_razorpay_key_id',        'Razorpay Key ID',           'text',    'rzp_live_…',''],
                ['ia_razorpay_key_secret',    'Razorpay Key Secret',       'password','',          ''],
                ['ia_razorpay_webhook_secret','Razorpay Webhook Secret',   'password','',          'Set in Razorpay Dashboard → Webhooks.'],
                ['ia_razorpay_plan_pro',      'Pro Plan ID',               'text',    'plan_…',   'Copy from Razorpay Dashboard. Price set in Plan Builder.'],
                ['ia_razorpay_plan_premium',  'Premium Plan ID',           'text',    'plan_…',   ''],
            ]); ?>
            <?php self::section('Google OAuth','Optional — enables "Continue with Google".',[
                ['ia_google_client_id',     'Client ID',     'text','…apps.googleusercontent.com','<a href="https://console.cloud.google.com" target="_blank">Create credentials →</a>'],
                ['ia_google_client_secret', 'Client Secret', 'text','GOCSPX-…',''],
            ]); ?>
            <p><button type="submit" class="button button-primary button-large">Save API Keys</button></p>
            </form>

            <?php self::render_priya_avatar_section(); ?>
        </div></div>
        <?php
    }

    /* ════════════════════════════════
       PAGE 2 — PLAN BUILDER
    ════════════════════════════════ */
    public static function render_plans() {
        if (!current_user_can('manage_options')) return;
        $saved  = isset($_GET['settings-updated']);
        $pro    = self::get_plan_config('pro');
        $prem   = self::get_plan_config('premium');
        ?>
        <div class="wrap"><?php self::admin_styles(); ?>
        <div class="ia-admin ia-admin--wide">
            <h1>🎨 Plan Builder</h1>
            <p class="ia-sub">Change plan names, prices, features and appearance. Every change shows immediately to users — no code deployment needed.</p>
            <?php if ($saved) echo '<div class="notice notice-success is-dismissible"><p>✓ Plan settings saved. Users will see the updated pricing instantly.</p></div>'; ?>

            <!-- Live preview -->
            <div class="ia-section" style="background:#0B0B16;border-color:#333;margin-bottom:24px;">
                <h2 style="color:#eee;margin-bottom:14px;">Live preview</h2>
                <div id="ia-plan-preview" style="display:flex;gap:16px;flex-wrap:wrap;"></div>
                <p style="color:#666;font-size:12px;margin-top:12px;">Updates as you type</p>
            </div>

            <form method="post" action="options.php" id="ia-plan-form">
            <?php settings_fields('ia_plans_group'); ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

            <!-- PRO -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #7C3AED;">
                <h2>⭐ Pro Plan</h2>
                <?php self::plan_fields('pro', $pro); ?>
            </div>

            <!-- PREMIUM -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #059669;">
                <h2>💎 Premium Plan</h2>
                <?php self::plan_fields('premium', $prem); ?>
            </div>

            </div>

            <div class="ia-section" style="background:#fffbeb;border-color:#fef3c7;margin-top:4px;">
                <h2 style="color:#92400e;">ℹ️ How plan changes work</h2>
                <ul style="margin:0;padding-left:20px;font-size:13.5px;color:#78350f;line-height:2;">
                    <li><strong>Price display</strong> — changes what users see on the billing page. You must separately update the actual charge amount in Razorpay Dashboard.</li>
                    <li><strong>Features list</strong> — one feature per line. Keep them short and benefit-focused.</li>
                    <li><strong>Session/monthly limits</strong> — set these in <a href="<?php echo admin_url('admin.php?page=interviewace-quotas'); ?>">Quota Settings</a>.</li>
                    <li><strong>Highlight</strong> — marks a plan with a coloured border and ribbon (e.g. "Best value").</li>
                </ul>
            </div>

            <p>
                <button type="submit" class="button button-primary button-large">Save Plan Settings</button>
                <a href="<?php echo admin_url('admin.php?page=interviewace-quotas'); ?>" class="button button-secondary button-large" style="margin-left:10px;">→ Quota Settings</a>
            </p>
            </form>
        </div></div>
        <script>
        function ia_updatePreview() {
            const plans = ['pro','premium'];
            const html = plans.map(id => {
                const f = n => document.getElementById('ia_plan_'+id+'_'+n)?.value || '';
                const features = f('features').split('\n').filter(Boolean).map(l=>`<li style="font-size:13px;color:#aaa;padding:3px 0">✓ ${l}</li>`).join('');
                const highlight = document.getElementById('ia_plan_'+id+'_highlight')?.checked;
                return `<div style="background:#13132A;border:${highlight?'2px solid #7C3AED':'1px solid #333'};border-radius:14px;padding:22px;min-width:220px;flex:1;position:relative;max-width:320px;">
                    ${highlight&&f('ribbon_text')?`<div style="position:absolute;top:-1px;right:16px;background:#7C3AED;color:#fff;font-size:11px;font-weight:700;padding:3px 12px;border-radius:0 0 8px 8px;">${f('ribbon_text')}</div>`:''}
                    <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px;">${f('display_name')||id}</div>
                    <div style="font-size:30px;font-weight:800;color:#A78BFA;margin-bottom:16px;">${f('price_display')||'₹—'}<span style="font-size:14px;font-weight:400;color:#666;">${f('price_period')||'/month'}</span></div>
                    <ul style="list-style:none;margin:0;padding:0;margin-bottom:18px;">${features}</ul>
                    <div style="background:#7C3AED;color:#fff;text-align:center;padding:10px;border-radius:9px;font-weight:600;font-size:14px;">Subscribe to ${f('display_name')||id}</div>
                </div>`;
            }).join('');
            document.getElementById('ia-plan-preview').innerHTML = html;
        }
        document.querySelectorAll('#ia-plan-form input,#ia-plan-form textarea,#ia-plan-form select').forEach(el=>{
            el.addEventListener('input', ia_updatePreview);
            el.addEventListener('change', ia_updatePreview);
        });
        ia_updatePreview();
        </script>
        <?php
    }

    private static function plan_fields(string $plan_id, array $vals) {
        $f = function(string $key, string $label, string $type, string $hint='') use ($plan_id, $vals) {
            $opt = "ia_plan_{$plan_id}_{$key}";
            $val = $vals[$key] ?? '';
            echo '<div class="ia-field-row">';
            echo '<label for="'.esc_attr($opt).'">'.esc_html($label).'</label>';
            if ($type === 'textarea') {
                echo '<textarea id="'.esc_attr($opt).'" name="'.esc_attr($opt).'" rows="7"
                    style="width:100%;font-size:13px;line-height:1.6;" class="large-text">'.esc_textarea($val).'</textarea>';
            } elseif ($type === 'checkbox') {
                $checked = $val ? 'checked' : '';
                echo '<label style="display:flex;align-items:center;gap:8px;font-weight:400;">
                    <input type="hidden" name="'.esc_attr($opt).'" value="0"/>
                    <input type="checkbox" id="'.esc_attr($opt).'" name="'.esc_attr($opt).'" value="1" '.$checked.'/>
                    Enable highlighted styling</label>';
            } else {
                echo '<input type="text" id="'.esc_attr($opt).'" name="'.esc_attr($opt).'"
                    value="'.esc_attr($val).'" class="regular-text"/>';
            }
            if ($hint) echo '<p class="description" style="margin-top:3px;font-size:12px;">'.esc_html($hint).'</p>';
            echo '</div>';
        };

        $f('display_name',  'Plan name',         'text',     'Shown as heading on the billing page');
        $f('badge_label',   'Badge label',        'text',     'Shown in user sidebar and current plan badge (e.g. ⭐ Pro)');
        $f('price_display', 'Price to show',      'text',     'Display only — e.g. ₹299. Actual charge is set in Razorpay.');
        $f('price_period',  'Period label',        'text',     'e.g. /month or /year');
        $f('highlight',     'Highlight this plan', 'checkbox', '');
        $f('ribbon_text',   'Ribbon text',         'text',     'Text on the highlight ribbon — e.g. "Best value". Leave blank to hide ribbon.');
        $f('features',      'Features list',       'textarea', 'One feature per line. These are the bullet points shown on the billing page.');
    }

    /* ════════════════════════════════
       PAGE 3 — QUOTA SETTINGS
    ════════════════════════════════ */
    public static function render_quotas() {
        if (!current_user_can('manage_options')) return;
        $saved = isset($_GET['settings-updated']);
        $q = [];
        foreach (IA_Plan_Enforcer::DEFAULTS as $key => $default) {
            $v = get_option('ia_quota_' . $key);
            $q[$key] = ($v !== false && is_numeric($v) && (int)$v > 0) ? (int)$v : $default;
        }
        /* Also pull display names from plan config for the headings */
        $pro_name  = get_option('ia_plan_pro_display_name', 'Pro');
        $prem_name = get_option('ia_plan_premium_display_name', 'Premium');
        $pro_price  = get_option('ia_plan_pro_price_display', '₹299');
        $prem_price = get_option('ia_plan_premium_price_display', '₹599');
        ?>
        <div class="wrap"><?php self::admin_styles(); ?>
        <div class="ia-admin ia-admin--wide">
            <h1>⏱ Quota Settings</h1>
            <p class="ia-sub">Set session durations and monthly allowances. Changes take effect immediately for all new interviews.</p>
            <?php if ($saved) echo '<div class="notice notice-success is-dismissible"><p>✓ Quota settings saved.</p></div>'; ?>

            <form method="post" action="options.php">
            <?php settings_fields('ia_quota_group'); ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <!-- Free -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #94a3b8;grid-column:1/-1;">
                <h2>🆓 Free Plan</h2>
                <p class="ia-desc">Users without any subscription.</p>
                <div class="ia-quota-grid ia-quota-grid--3">
                    <?php self::quota_field('free_session_minutes',   'Minutes per session',     $q['free_session_minutes'],   1, 120,  'min'); ?>
                    <?php self::quota_field('free_weekly_interviews', 'Interviews per week',      $q['free_weekly_interviews'], 1, 20,   ''); ?>
                    <?php self::quota_field('free_monthly_minutes',   'Total minutes per month',  $q['free_monthly_minutes'],   5, 600,  'min'); ?>
                </div>
            </div>

            <!-- Pro -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #7C3AED;">
                <h2>⭐ <?php echo esc_html($pro_name); ?> <span class="ia-price-badge"><?php echo esc_html($pro_price); ?>/mo</span></h2>
                <p class="ia-desc">Paid subscribers on the Pro tier.</p>
                <div class="ia-quota-grid">
                    <?php self::quota_field('pro_session_minutes', 'Minutes per session',    $q['pro_session_minutes'],  1, 600,  'min'); ?>
                    <?php self::quota_field('pro_monthly_minutes', 'Total minutes per month', $q['pro_monthly_minutes'],  30, 99999,'min'); ?>
                </div>
            </div>

            <!-- Premium -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #059669;">
                <h2>💎 <?php echo esc_html($prem_name); ?> <span class="ia-price-badge" style="background:#d1fae5;color:#065f46;"><?php echo esc_html($prem_price); ?>/mo</span></h2>
                <p class="ia-desc">Paid subscribers on the Premium tier.</p>
                <div class="ia-quota-grid">
                    <?php self::quota_field('premium_session_minutes', 'Minutes per session',    $q['premium_session_minutes'], 1, 600,  'min'); ?>
                    <?php self::quota_field('premium_monthly_minutes', 'Total minutes per month', $q['premium_monthly_minutes'], 30, 99999,'min'); ?>
                </div>
            </div>

            <!-- B2B -->
            <div class="ia-section ia-section--plan" style="border-left:4px solid #0284c7;grid-column:1/-1;">
                <h2>🏢 B2B / College Plan</h2>
                <p class="ia-desc">Bulk accounts for colleges and corporates.</p>
                <div class="ia-quota-grid ia-quota-grid--3">
                    <?php self::quota_field('b2b_session_minutes', 'Minutes per session',    $q['b2b_session_minutes'],  1, 600,  'min'); ?>
                    <?php self::quota_field('b2b_monthly_minutes', 'Total minutes per month', $q['b2b_monthly_minutes'],  30, 99999,'min'); ?>
                    <div class="ia-quota-field" style="display:flex;flex-direction:column;justify-content:center;">
                        <div style="font-size:12.5px;color:#64748b;line-height:1.6;">B2B accounts are assigned manually. Contact admin to assign the <code>b2b</code> plan to a subscription.</div>
                    </div>
                </div>
            </div>

            </div>

            <div class="ia-section" style="background:#fffbeb;border-color:#fef3c7;margin-top:4px;">
                <h2 style="color:#92400e;">ℹ️ How session vs monthly limits work</h2>
                <ul style="margin:0;padding-left:20px;font-size:13.5px;color:#78350f;line-height:2.2;">
                    <li><strong>Session minutes</strong> — hard cutoff per interview. Priya wraps up the interview when reached.</li>
                    <li><strong>Monthly minutes</strong> — running total across all sessions in a calendar month, resets on the 1st.</li>
                    <li><strong>Admin accounts</strong> — always unlimited regardless of these values.</li>
                    <li><strong>Effect timing</strong> — applies to the <em>next interview started</em>. In-progress sessions keep the limit set when they began.</li>
                    <li><strong>Unlimited monthly</strong> — set monthly minutes to a very high number (e.g. 99999) to make it effectively unlimited.</li>
                </ul>
            </div>

            <p>
                <button type="submit" class="button button-primary button-large">Save Quota Settings</button>
                <a href="<?php echo admin_url('admin.php?page=interviewace-plans'); ?>" class="button button-secondary button-large" style="margin-left:10px;">← Plan Builder</a>
            </p>
            </form>
            <?php self::render_usage_stats(); ?>
        </div></div>
        <?php
    }

    /* ── Usage stats widget ── */
    private static function render_usage_stats() {
        global $wpdb; $p = $wpdb->prefix;
        $month = gmdate('Y-m');
        $stats = [
            ['Total registered users',    (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$p}ia_profiles")],
            ['Active users this month',   (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$p}ia_usage WHERE month_year=%s AND interviews_count>0",$month))],
            ['Interviews (all time)',     (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}ia_interviews WHERE status='completed'")],
            ['Interviews this month',     (int)$wpdb->get_var($wpdb->prepare("SELECT SUM(interviews_count) FROM {$p}ia_usage WHERE month_year=%s",$month))],
        ];
        $plans = $wpdb->get_results("SELECT plan,COUNT(*) cnt FROM {$p}ia_subscriptions WHERE status='active' GROUP BY plan ORDER BY cnt DESC");
        ?>
        <div class="ia-section" style="margin-top:20px;">
            <h2>📊 Usage snapshot — <?php echo gmdate('F Y'); ?></h2>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:14px 0 18px;">
                <?php foreach ($stats as [$lbl,$val]): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:14px;text-align:center;">
                    <div style="font-size:26px;font-weight:800;color:#1e293b;"><?php echo number_format($val); ?></div>
                    <div style="font-size:11.5px;color:#64748b;margin-top:3px;"><?php echo esc_html($lbl); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($plans): ?>
            <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">Active subscriptions:</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php foreach ($plans as $row): ?>
                <span style="background:#ede9fe;color:#5b21b6;padding:4px 14px;border-radius:20px;font-size:13px;font-weight:600;">
                    <?php echo esc_html(ucfirst($row->plan)); ?>: <?php echo (int)$row->cnt; ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Shared helpers ── */
    private static function section(string $title, string $desc, array $fields) {
        echo '<div class="ia-section"><h2>'.esc_html($title).'</h2><p class="ia-desc">'.esc_html($desc).'</p>';
        foreach ($fields as [$key,$label,$type,$ph,$hint]) {
            $val = get_option($key,'');
            echo '<div class="ia-field-row">';
            echo '<label for="'.esc_attr($key).'">'.esc_html($label).'</label>';
            echo '<input type="'.($type==='password'?'text':$type).'" id="'.esc_attr($key).'" name="'.esc_attr($key).'"
                value="'.esc_attr($val).'" placeholder="'.esc_attr($type==='password'&&$val?str_repeat('•',16):$ph).'"
                class="regular-text" autocomplete="off" style="font-family:monospace;"/>';
            if ($hint) echo '<p class="description" style="margin-top:3px;">'.$hint.'</p>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function quota_field(string $key, string $label, int $current, int $min, int $max, string $unit) {
        $opt     = 'ia_quota_'.$key;
        $default = IA_Plan_Enforcer::DEFAULTS[$key] ?? $current;
        echo '<div class="ia-quota-field">';
        echo '<label for="'.esc_attr($opt).'">'.esc_html($label).'</label>';
        echo '<div class="ia-quota-input-wrap">';
        echo '<input type="number" id="'.esc_attr($opt).'" name="'.esc_attr($opt).'"
            value="'.esc_attr($current).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'"
            class="small-text ia-quota-num"/>';
        if ($unit) echo '<span class="ia-unit">'.esc_html($unit).'</span>';
        echo '<span class="ia-default-hint">default: '.esc_html($default).($unit?' '.$unit:'').'</span>';
        echo '</div></div>';
    }

    /* ── Priya Avatar Image Manager ── */
    private static function render_priya_avatar_section(): void {
        $current_url = get_option('ia_priya_avatar_url', IA_URL.'assets/priya-photo.jpg');
        ?>
        <div class="ia-section" style="margin-top:20px">
            <h2>🎭 Priya AI Recruiter — Avatar Image</h2>
            <p class="ia-desc">The photo shown as Priya during interviews. Upload any professional photo or choose from your Media Library. Changes appear immediately for all users — no code change needed.</p>

            <form method="post" action="options.php" style="margin-top:4px">
            <?php settings_fields('ia_settings_group'); ?>

            <div style="display:flex;align-items:flex-start;gap:28px;flex-wrap:wrap;margin-bottom:20px">
                <!-- Current preview -->
                <div style="flex-shrink:0">
                    <div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Current avatar</div>
                    <div id="ia-priya-preview-wrap" style="width:160px;height:200px;border-radius:12px;overflow:hidden;border:2px solid #e2e8f0;background:#1A1A2E">
                        <img id="ia-priya-preview-img"
                            src="<?php echo esc_url($current_url); ?>"
                            alt="Current Priya avatar"
                            style="width:100%;height:100%;object-fit:cover;object-position:center 15%"/>
                    </div>
                </div>

                <!-- Controls -->
                <div style="flex:1;min-width:280px">
                    <div style="margin-bottom:14px">
                        <label for="ia_priya_avatar_url" style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#374151">Avatar image URL</label>
                        <input type="url" id="ia_priya_avatar_url" name="ia_priya_avatar_url"
                            value="<?php echo esc_url($current_url); ?>"
                            class="regular-text"
                            style="font-family:monospace;font-size:12px"
                            oninput="document.getElementById('ia-priya-preview-img').src=this.value"/>
                        <p class="description" style="margin-top:4px">Paste a direct image URL, or use the button below to upload from your media library.</p>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
                        <button type="button" id="ia-priya-upload-btn" class="button button-secondary">
                            📁 Choose from Media Library
                        </button>
                        <button type="button" class="button" onclick="
                            const def='<?php echo esc_js(IA_URL.'assets/priya-photo.jpg'); ?>';
                            document.getElementById('ia_priya_avatar_url').value=def;
                            document.getElementById('ia-priya-preview-img').src=def;">
                            ↩ Reset to default
                        </button>
                    </div>

                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;font-size:13px;color:#166534;margin-bottom:16px">
                        <strong>💡 Tips for best results:</strong>
                        <ul style="margin:6px 0 0 16px;line-height:1.8">
                            <li>Use a professional headshot with a clear face</li>
                            <li>Portrait orientation (4:5 ratio) works best</li>
                            <li>Recommended size: at least 800×1000 pixels</li>
                            <li>JPG or PNG format, under 2MB</li>
                        </ul>
                    </div>

                    <button type="submit" class="button button-primary">Save Avatar</button>
                </div>
            </div>
            </form>
        </div>

        <script>
        (function(){
            var btn = document.getElementById('ia-priya-upload-btn');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var frame = wp.media({
                    title: 'Select Priya Avatar Image',
                    button: { text: 'Use this image' },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    document.getElementById('ia_priya_avatar_url').value = att.url;
                    document.getElementById('ia-priya-preview-img').src = att.url;
                });
                frame.open();
            });
        })();
        </script>
        <?php
    }

    private static function admin_styles() { ?>
        <style>
        .ia-admin{max-width:860px}.ia-admin--wide{max-width:1100px}
        .ia-admin h1{display:flex;align-items:center;gap:10px;margin-bottom:6px}
        .ia-sub{color:#64748b;margin-bottom:22px;font-size:14px}
        .ia-btn-lnk{display:inline-flex;align-items:center;gap:7px;background:#0f172a;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px}
        .ia-btn-lnk:hover{opacity:.88;color:#fff}
        .ia-status-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:11px 15px;margin-bottom:22px}
        .ia-status-pill{padding:3px 11px;border-radius:20px;font-size:12px;font-weight:600}
        .ia-section{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px;margin-bottom:18px}
        .ia-section h2{font-size:15px;font-weight:700;margin:0 0 4px}
        .ia-desc{font-size:13px;color:#64748b;margin:0 0 16px;line-height:1.55}
        .ia-field-row{margin-bottom:14px}
        .ia-field-row label{display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#374151}
        .ia-section--plan{padding:22px}
        .ia-price-badge{font-size:12px;font-weight:600;background:#ede9fe;color:#6d28d9;padding:2px 9px;border-radius:20px;margin-left:6px}
        .ia-quota-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
        .ia-quota-grid--3{grid-template-columns:repeat(3,1fr)}
        .ia-quota-field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
        .ia-quota-input-wrap{display:flex;align-items:center;gap:8px}
        .ia-quota-num{width:88px!important;font-size:16px!important;font-weight:700!important;text-align:center}
        .ia-unit{font-size:13px;color:#64748b;font-weight:500}
        .ia-default-hint{font-size:11px;color:#94a3b8}
        @media(max-width:700px){.ia-quota-grid,.ia-quota-grid--3{grid-template-columns:1fr 1fr}}
        </style>
    <?php }

    public static function ajax_save_quota() {
        if (!current_user_can('manage_options') || !check_ajax_referer('ia_quota_nonce','nonce',false))
            wp_send_json_error(['message'=>'Unauthorized'],403);
        $key   = sanitize_key($_POST['key']??'');
        $value = absint($_POST['value']??0);
        if (!$key||!isset(IA_Plan_Enforcer::DEFAULTS[$key])) wp_send_json_error(['message'=>'Invalid key'],400);
        update_option('ia_quota_'.$key,$value);
        wp_send_json_success(['key'=>$key,'value'=>$value]);
    }
}
