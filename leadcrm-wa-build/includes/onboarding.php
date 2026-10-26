<?php
/**
 * LeadCRM Onboarding Module
 * First-run setup wizard and persistent inline guidance system.
 * Shows only until user completes onboarding or dismisses it.
 *
 * Steps:
 *   1. Business profile (name, team size, service types)
 *   2. Channel setup (which sources to activate)
 *   3. First lead creation (guided)
 *   4. WhatsApp setup check
 *   5. Completion — "You're live!"
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── STATE ───────────────────────────────────────────────────────────────────

// TRACE: Returns current onboarding state. Step 0 = not started, step 5 = complete.
//        EDGE CASES: Already completed → returns ['complete'=>true] to skip UI.
function lcrm_onboarding_state() {
    return [
        'step'      => (int) get_option( 'lcrm_onboarding_step', 0 ),
        'complete'  => (bool) get_option( 'lcrm_onboarding_complete', 0 ),
        'dismissed' => (bool) get_option( 'lcrm_onboarding_dismissed', 0 ),
        'data'      => json_decode( get_option( 'lcrm_onboarding_data', '{}' ), true ) ?: [],
    ];
}

// ─── ADMIN NOTICE + BANNER ───────────────────────────────────────────────────

add_action( 'admin_notices', 'lcrm_onboarding_maybe_show_banner' );

// TRACE: Shows onboarding banner on LeadCRM admin pages until dismissed or complete.
//        EDGE CASES: Complete/dismissed → no banner; non-CRM pages → no banner.
function lcrm_onboarding_maybe_show_banner() {
    $page = sanitize_text_field( $_GET['page'] ?? '' );
    if ( strpos( $page, 'leadcrm' ) === false ) return;

    $state = lcrm_onboarding_state();
    if ( $state['complete'] || $state['dismissed'] ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $step = $state['step'];
    ?>
    <div class="notice lcrm-onboarding-banner" style="background:#fff;border-left:4px solid #6366f1;padding:0;margin:16px 0;border-radius:0 8px 8px 0;box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <div style="display:flex;align-items:center;gap:16px;padding:16px 20px;">
            <div style="font-size:28px;">🚀</div>
            <div style="flex:1">
                <strong style="display:block;font-size:15px;color:#1e293b;margin-bottom:4px;">
                    Welcome to LeadCRM v4! Let's get you set up.
                </strong>
                <span style="font-size:13px;color:#6b7280;">
                    Step <?php echo $step + 1; ?> of 5 — <?php echo lcrm_onboarding_step_label( $step ); ?>
                </span>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadcrm-setup' ) ); ?>"
                   class="button button-primary" style="background:#6366f1;border-color:#6366f1;">
                    Continue Setup ➜
                </a>
                <button type="button" class="button lcrm-onboarding-dismiss">Skip for now</button>
            </div>
        </div>
        <?php
        // Progress bar
        $pct = round( $step / 5 * 100 );
        ?>
        <div style="height:3px;background:#e2e8f0;border-radius:0 0 8px 8px;overflow:hidden;">
            <div style="height:3px;background:#6366f1;width:<?php echo $pct; ?>%;"></div>
        </div>
    </div>
    <script>
    document.querySelector('.lcrm-onboarding-dismiss')?.addEventListener('click',function(){
        var fd=new FormData(); fd.append('action','lcrm_onboarding_dismiss'); fd.append('nonce','<?php echo wp_create_nonce("lcrm_nonce"); ?>');
        fetch('<?php echo admin_url("admin-ajax.php"); ?>',{method:'POST',body:fd});
        this.closest('.lcrm-onboarding-banner').style.display='none';
    });
    </script>
    <?php
}

// TRACE: Returns human-readable step label for progress display.
function lcrm_onboarding_step_label( $step ) {
    $labels = [
        0 => 'Business Profile',
        1 => 'Lead Sources',
        2 => 'Create Your First Lead',
        3 => 'WhatsApp Setup',
        4 => 'Final Review',
        5 => 'Complete',
    ];
    return $labels[ $step ] ?? 'Setup';
}

// ─── SETUP PAGE ───────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'lcrm_onboarding_register_menu', 99 );

// TRACE: Registers hidden setup page (no menu item visible) accessible via URL.
function lcrm_onboarding_register_menu() {
    add_submenu_page(
        null, // Hidden — no parent menu
        'LeadCRM Setup',
        'Setup',
        'manage_options',
        'leadcrm-setup',
        'lcrm_onboarding_render_page'
    );
}

// TRACE: Renders the multi-step setup wizard page — full-screen WP admin page.
//        POSTCONDITIONS: Setup wizard UI rendered; JS handles step transitions via AJAX.
function lcrm_onboarding_render_page() {
    $state  = lcrm_onboarding_state();
    $step   = $state['step'];
    $stages = lcrm_get_stages();
    $sources= lcrm_get_lead_sources();
    $brands = lcrm_get_brands();

    // Check what's already configured
    global $wpdb;
    $has_wa  = (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_wa_accounts WHERE is_active=1" );
    $has_lead= (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads" );
    $has_ai  = lcrm_ai_enabled();
    ?>
    <div class="wrap" id="lcrm-setup-wrap" style="max-width:700px;margin:30px auto;">
        <div style="text-align:center;margin-bottom:30px;">
            <div style="font-size:40px;margin-bottom:8px;">⚡</div>
            <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin:0 0 8px;">LeadCRM Setup</h1>
            <p style="color:#6b7280;font-size:14px;margin:0;">5 minutes to your first live lead</p>
        </div>

        <!-- Progress steps -->
        <div style="display:flex;justify-content:center;gap:4px;margin-bottom:30px;" id="lcrm-setup-steps">
            <?php for ( $i = 0; $i <= 4; $i++ ): ?>
            <div style="display:flex;align-items:center;gap:4px;">
                <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
                    <?php echo $i < $step ? 'background:#10b981;color:#fff;' : ( $i === $step ? 'background:#6366f1;color:#fff;' : 'background:#e2e8f0;color:#6b7280;' ); ?>">
                    <?php echo $i < $step ? '✓' : ( $i + 1 ); ?>
                </div>
                <span style="font-size:11px;color:<?php echo $i === $step ? '#6366f1' : '#9ca3af'; ?>;display:none;" class="step-label-lg">
                    <?php echo lcrm_onboarding_step_label( $i ); ?>
                </span>
            </div>
            <?php if ( $i < 4 ): ?>
            <div style="height:1px;width:24px;background:#e2e8f0;margin-top:14px;"></div>
            <?php endif; ?>
            <?php endfor; ?>
        </div>

        <div id="lcrm-setup-card" style="background:#fff;border-radius:16px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.08);">

            <!-- STEP 0: Business Profile -->
            <div id="lcrm-step-0" <?php echo $step !== 0 ? 'style="display:none"' : ''; ?>>
                <h2 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;">Tell us about your business</h2>
                <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">This helps LeadCRM pre-configure stages and sources for your industry.</p>

                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <label style="font-size:13px;font-weight:500;display:block;margin-bottom:6px;">Business type</label>
                        <select id="ob-industry" class="lcrm-input lcrm-input-full">
                            <option value="nri_services">NRI & Documentation Services</option>
                            <option value="legal">Legal Services</option>
                            <option value="visa">Visa & Immigration</option>
                            <option value="property">Property & Real Estate</option>
                            <option value="home_services">Home Services</option>
                            <option value="education">Education & Coaching</option>
                            <option value="other">Other Service Business</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:13px;font-weight:500;display:block;margin-bottom:6px;">Team size</label>
                        <select id="ob-team-size" class="lcrm-input lcrm-input-full">
                            <option value="1">Solo (just me)</option>
                            <option value="2-5">2–5 people</option>
                            <option value="6-20">6–20 people</option>
                            <option value="20+">20+ people</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:13px;font-weight:500;display:block;margin-bottom:6px;">Primary lead source</label>
                        <select id="ob-primary-source" class="lcrm-input lcrm-input-full">
                            <option value="whatsapp">WhatsApp</option>
                            <option value="website">Website forms</option>
                            <option value="instagram">Instagram DMs</option>
                            <option value="referral">Referrals</option>
                            <option value="mixed">Mix of everything</option>
                        </select>
                    </div>
                </div>
                <button class="button button-primary lcrm-btn" id="ob-step0-next" style="margin-top:20px;width:100%;justify-content:center;background:#6366f1;border-color:#6366f1;">
                    Continue →
                </button>
            </div>

            <!-- STEP 1: Channel Setup -->
            <div id="lcrm-step-1" style="display:none">
                <h2 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;">Which channels do your leads come from?</h2>
                <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">We'll activate and configure only what you need.</p>
                <div style="display:flex;flex-direction:column;gap:10px;" id="ob-channels">
                    <?php
                    $channels = [
                        'whatsapp'   => [ '💬', 'WhatsApp',           'Inbound WA messages auto-converted to leads' ],
                        'email'      => [ '📧', 'Email (IMAP/Gmail)', 'Poll your inbox every 15 minutes' ],
                        'instagram'  => [ '📸', 'Instagram DMs',      'Webhook captures Instagram messages' ],
                        'facebook'   => [ '👥', 'Facebook Messenger', 'Webhook captures FB messages' ],
                        'website'    => [ '🌐', 'Website Forms',      'Auto-captures CF7, WPForms, Gravity Forms' ],
                        'woocommerce'=> [ '🛒', 'WooCommerce',        'Every order becomes a lead' ],
                        'api'        => [ '🔌', 'REST API',           'External forms, chatbots, landing pages' ],
                    ];
                    foreach ( $channels as $key => [$icon, $label, $desc] ):
                    ?>
                    <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:.15s;"
                           class="ob-channel-row">
                        <input type="checkbox" name="ob_channels[]" value="<?php echo esc_attr($key); ?>"
                               style="margin-top:2px;" checked>
                        <div>
                            <div style="font-size:14px;font-weight:500;color:#1e293b;"><?php echo $icon; ?> <?php echo esc_html($label); ?></div>
                            <div style="font-size:12px;color:#6b7280;"><?php echo esc_html($desc); ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button class="button button-primary lcrm-btn" id="ob-step1-next" style="margin-top:20px;width:100%;justify-content:center;background:#6366f1;border-color:#6366f1;">
                    Continue →
                </button>
            </div>

            <!-- STEP 2: First Lead -->
            <div id="lcrm-step-2" style="display:none">
                <h2 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;">Create your first lead</h2>
                <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">
                    <?php echo $has_lead ? '✓ You already have leads! You can skip this step.' : 'Add a real lead so you can see how the pipeline works.'; ?>
                </p>
                <?php if ( ! $has_lead ): ?>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <input type="text" id="ob-lead-name" class="lcrm-input lcrm-input-full" placeholder="Client Name *">
                    <input type="text" id="ob-lead-phone" class="lcrm-input lcrm-input-full" placeholder="Phone Number">
                    <input type="text" id="ob-lead-work" class="lcrm-input lcrm-input-full" placeholder="Service Required (e.g. OCI Card, Visa)">
                    <select id="ob-lead-source" class="lcrm-input lcrm-input-full">
                        <?php foreach ( $sources as $s ): ?>
                        <option value="<?php echo esc_attr($s->name); ?>"><?php echo esc_html($s->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary lcrm-btn" id="ob-create-lead-btn" style="background:#6366f1;border-color:#6366f1;">
                        Create Lead & Continue →
                    </button>
                </div>
                <?php endif; ?>
                <button class="button lcrm-btn" id="ob-step2-skip" style="margin-top:12px;width:100%;justify-content:center;">
                    <?php echo $has_lead ? 'Continue →' : 'Skip this step'; ?>
                </button>
            </div>

            <!-- STEP 3: WhatsApp / AI -->
            <div id="lcrm-step-3" style="display:none">
                <h2 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;">Power features</h2>
                <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">Configure these now or later in Settings.</p>

                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div style="padding:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;display:flex;align-items:center;gap:12px;">
                        <span style="font-size:24px;"><?php echo $has_wa ? '✅' : '⬜'; ?></span>
                        <div>
                            <div style="font-weight:600;font-size:13px;color:#166534;">WhatsApp (Cloud API)</div>
                            <div style="font-size:12px;color:#374151;">
                                <?php if ( $has_wa ): ?>Connected<?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=leadcrm-wa-settings&tab=accounts'); ?>">Configure →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div style="padding:14px;background:<?php echo $has_ai ? '#f0fdf4' : '#eff6ff'; ?>;border:1px solid <?php echo $has_ai ? '#bbf7d0' : '#bfdbfe'; ?>;border-radius:8px;display:flex;align-items:center;gap:12px;">
                        <span style="font-size:24px;"><?php echo $has_ai ? '✅' : '⬜'; ?></span>
                        <div>
                            <div style="font-weight:600;font-size:13px;color:<?php echo $has_ai ? '#166534' : '#1e40af'; ?>;">AI Engine</div>
                            <div style="font-size:12px;color:#374151;">
                                <?php if ( $has_ai ): ?>Active<?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=leadcrm-settings&tab=ai'); ?>">Set up OpenAI/Claude →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div style="padding:14px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;display:flex;align-items:center;gap:12px;">
                        <span style="font-size:24px;">🔗</span>
                        <div>
                            <div style="font-weight:600;font-size:13px;color:#6b21a8;">Client Portal</div>
                            <div style="font-size:12px;color:#374151;">Magic-link status pages for clients — no login needed</div>
                        </div>
                    </div>
                </div>
                <button class="button button-primary lcrm-btn" id="ob-step3-next" style="margin-top:20px;width:100%;justify-content:center;background:#6366f1;border-color:#6366f1;">
                    Continue →
                </button>
            </div>

            <!-- STEP 4: Complete -->
            <div id="lcrm-step-4" style="display:none;text-align:center;">
                <div style="font-size:56px;margin-bottom:16px;">🎉</div>
                <h2 style="font-size:20px;font-weight:700;color:#1e293b;margin-bottom:8px;">You're all set!</h2>
                <p style="color:#6b7280;font-size:14px;margin-bottom:24px;line-height:1.6;">
                    LeadCRM is configured and ready to capture leads from all your channels.<br>
                    Your first alert will fire within minutes of the first incoming message.
                </p>
                <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <a href="<?php echo admin_url('admin.php?page=leadcrm'); ?>" class="button button-primary lcrm-btn" style="background:#6366f1;border-color:#6366f1;">
                        Go to Dashboard →
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=leadcrm-leads'); ?>" class="button lcrm-btn">
                        View Leads
                    </a>
                </div>
            </div>

            <div id="lcrm-setup-msg" style="margin-top:12px;font-size:13px;text-align:center;display:none;"></div>
        </div>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url("admin-ajax.php"); ?>', NONCE='<?php echo wp_create_nonce("lcrm_nonce"); ?>';

        function showStep(n){
            $('.wrap [id^="lcrm-step-"]').hide();
            $('#lcrm-step-'+n).show();
        }

        function nextStep(step, extra_data, cb){
            var fd=new FormData();
            fd.append('action','lcrm_onboarding_save_step');
            fd.append('nonce',NONCE);
            fd.append('step',step);
            if(extra_data) Object.keys(extra_data).forEach(k=>fd.append(k,extra_data[k]));
            fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(res.success){ if(cb) cb(res.data); }
                else{ $('#lcrm-setup-msg').text(res.data&&res.data.msg?res.data.msg:'Error').css('color','#ef4444').show(); }
            });
        }

        // Step 0
        $('#ob-step0-next').on('click',function(){
            nextStep(0, {
                industry: $('#ob-industry').val(),
                team_size: $('#ob-team-size').val(),
                primary_source: $('#ob-primary-source').val()
            }, function(){ showStep(1); });
        });

        // Step 1
        $('#ob-step1-next').on('click',function(){
            var channels=[]; $('input[name="ob_channels[]"]:checked').each(function(){ channels.push($(this).val()); });
            nextStep(1, {channels: JSON.stringify(channels)}, function(){ showStep(2); });
        });

        // Step 2 — create lead
        $('#ob-create-lead-btn').on('click',function(){
            var name=$('#ob-lead-name').val().trim();
            if(!name){$('#lcrm-setup-msg').text('Please enter a client name.').css('color','#ef4444').show();return;}
            var fd=new FormData();
            fd.append('action','lcrm_onboarding_create_lead'); fd.append('nonce',NONCE);
            fd.append('client_name',name); fd.append('client_phone',$('#ob-lead-phone').val());
            fd.append('work_type',$('#ob-lead-work').val()); fd.append('lead_source',$('#ob-lead-source').val());
            fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(res.success){ nextStep(2,{},function(){ showStep(3); }); }
                else{$('#lcrm-setup-msg').text(res.data&&res.data.msg?res.data.msg:'Error').css('color','#ef4444').show();}
            });
        });

        $('#ob-step2-skip').on('click',function(){ nextStep(2,{},function(){showStep(3);}); });

        // Step 3
        $('#ob-step3-next').on('click',function(){ nextStep(3,{},function(){showStep(4);}); });

        // Highlight channel rows on select
        $(document).on('change','.ob-channel-row input',function(){
            $(this).closest('.ob-channel-row').css('border-color',this.checked?'#6366f1':'#e2e8f0');
        });
    })(jQuery);
    </script>
    <?php
}

// ─── AJAX HANDLERS ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lcrm_onboarding_save_step',   'lcrm_ajax_onboarding_save_step' );
add_action( 'wp_ajax_lcrm_onboarding_create_lead', 'lcrm_ajax_onboarding_create_lead' );
add_action( 'wp_ajax_lcrm_onboarding_dismiss',     'lcrm_ajax_onboarding_dismiss' );

// TRACE: Saves onboarding step data and advances to next step.
//        PRECONDITIONS: Admin; step is 0-4.
//        POSTCONDITIONS: lcrm_onboarding_step incremented; step data saved.
//        EDGE CASES: step 4 → marks complete; unknown step → error.
function lcrm_ajax_onboarding_save_step() {
    lcrm_verify_admin();
    $step = intval( $_POST['step'] ?? -1 );
    if ( $step < 0 || $step > 4 ) wp_send_json_error( [ 'msg' => 'Invalid step.' ] );

    // Save step-specific data
    $existing = json_decode( get_option( 'lcrm_onboarding_data', '{}' ), true ) ?: [];

    if ( $step === 0 ) {
        $existing['industry']       = sanitize_text_field( $_POST['industry']       ?? '' );
        $existing['team_size']      = sanitize_text_field( $_POST['team_size']      ?? '' );
        $existing['primary_source'] = sanitize_text_field( $_POST['primary_source'] ?? '' );
    } elseif ( $step === 1 ) {
        $channels = json_decode( sanitize_text_field( $_POST['channels'] ?? '[]' ), true );
        $existing['channels'] = is_array( $channels ) ? $channels : [];
    }

    update_option( 'lcrm_onboarding_data', wp_json_encode( $existing ) );
    update_option( 'lcrm_onboarding_step', min( 5, $step + 1 ) );

    if ( $step === 4 ) {
        update_option( 'lcrm_onboarding_complete', 1 );
    }

    wp_send_json_success( [ 'step' => $step + 1 ] );
}

// TRACE: Creates the user's first lead from the onboarding wizard.
//        POSTCONDITIONS: Lead in DB; user sees it in leads list.
function lcrm_ajax_onboarding_create_lead() {
    lcrm_verify_admin();
    $name   = sanitize_text_field( $_POST['client_name']  ?? '' );
    $phone  = sanitize_text_field( $_POST['client_phone'] ?? '' );
    $work   = sanitize_text_field( $_POST['work_type']    ?? '' );
    $source = sanitize_text_field( $_POST['lead_source']  ?? 'Website' );

    if ( ! $name ) wp_send_json_error( [ 'msg' => 'Client name required.' ] );

    $lead_id = lcrm_create_lead( [
        'lead_date'    => date( 'Y-m-d' ),
        'client_name'  => $name,
        'client_phone' => $phone,
        'work_type'    => $work,
        'lead_source'  => $source,
        'stage_id'     => 1,
        'priority'     => 'medium',
        'lead_temperature'=> 'warm',
        'remarks'      => 'Created during onboarding setup.',
        'created_by'   => get_current_user_id(),
    ] );

    if ( ! $lead_id || is_wp_error( $lead_id ) ) {
        wp_send_json_error( [ 'msg' => is_wp_error( $lead_id ) ? $lead_id->get_error_message() : 'Failed to create lead.' ] );
    }

    wp_send_json_success( [ 'lead_id' => $lead_id, 'msg' => 'Lead created.' ] );
}

// TRACE: Dismisses the onboarding banner permanently (not completing, just hiding).
function lcrm_ajax_onboarding_dismiss() {
    lcrm_verify_admin();
    update_option( 'lcrm_onboarding_dismissed', 1 );
    wp_send_json_success( [ 'msg' => 'Dismissed.' ] );
}
