<?php
if (!defined('ABSPATH')) exit;

/**
 * Context-aware URL builder.
 * In WP Admin → returns admin_url('admin.php?page=...')
 * On Frontend → returns the portal page URL with crm_page=... param
 *
 * $page maps:  leadcrm           → dashboard
 *              leadcrm-leads     → leads
 *              leadcrm-add       → add
 *              leadcrm-alerts    → alerts
 *              leadcrm-vendors   → vendors
 *              leadcrm-settings  → settings
 */
function lcrm_page_url( $admin_page, $extra_args = [] ) {
    static $page_map = [
        'leadcrm'           => 'dashboard',
        'leadcrm-leads'     => 'leads',
        'leadcrm-add'       => 'add',
        'leadcrm-alerts'    => 'alerts',
        'leadcrm-vendors'   => 'vendors',
        'leadcrm-brands'    => 'brands',
        'leadcrm-settings'  => 'settings',
        'leadcrm-import'    => 'settings',
        'leadcrm-tasks'     => 'tasks',
        'leadcrm-analytics' => 'analytics',
        'leadcrm-pipeline'  => 'pipeline',
        'leadcrm-team'      => 'team',
        'leadcrm-fields'    => 'fields',
        'leadcrm-import'    => 'import',
        'leadcrm-assignments' => 'assignments',
    ];

    // SPA AJAX context: return JS-navigable hash URLs
    if ( defined('LCRM_SPA_RENDER') && LCRM_SPA_RENDER ) {
        $section = isset($page_map[$admin_page]) ? $page_map[$admin_page] : 'dashboard';
        // For settings with tab
        if ( $section === 'settings' && isset($extra_args['tab']) ) {
            $tab = esc_js( $extra_args['tab'] );
            return "javascript:lcrmSpaNavigate('settings','{$tab}',true)";
        }
        // For leads with edit param — open modal directly
        if ( $section === 'leads' && isset($extra_args['edit']) ) {
            return 'javascript:lcrmOpenLeadModal(' . intval($extra_args['edit']) . ')';
        }
        $sec = esc_js( $section );
        return "javascript:lcrmSpaNavigate('{$sec}','',true)";
    }

    if ( is_admin() ) {
        // Normal wp-admin context: build admin URL
        $url = admin_url( 'admin.php?page=' . $admin_page );
        if ( ! empty($extra_args) ) $url = add_query_arg( $extra_args, $url );
        return $url;
    }

    // Frontend context: use portal page
    $crm_page = isset($page_map[$admin_page]) ? $page_map[$admin_page] : 'dashboard';
    $args = array_merge( ['crm_page' => $crm_page], $extra_args );

    // Handle tab param for settings
    if ( $admin_page === 'leadcrm-settings' && isset($extra_args['tab']) ) {
        $args['crm_tab'] = $extra_args['tab'];
        unset($args['tab']);
    }

    // Try to get portal page URL
    $portal = lcrm_get_portal_url();
    return $portal ? add_query_arg( $args, $portal ) : '#';
}

/** Return the URL of the page containing [leadcrm_portal], or false. */
function lcrm_get_portal_url() {
    static $url = null;
    if ( $url !== null ) return $url;
    // Check if we're already on the frontend portal page (fastest path)
    if ( ! is_admin() && get_queried_object_id() ) {
        $post = get_queried_object();
        if ( $post && isset($post->post_content) && has_shortcode($post->post_content,'leadcrm_portal') ) {
            $url = get_permalink($post->ID);
            return $url;
        }
    }
    // Search for the portal page
    $pages = get_posts([
        'post_type'   => 'page',
        'post_status' => 'publish',
        'numberposts' => 5,
        's'           => 'leadcrm_portal',
    ]);
    foreach ( $pages as $p ) {
        if ( has_shortcode($p->post_content,'leadcrm_portal') ) {
            $url = get_permalink($p->ID);
            return $url;
        }
    }
    $url = false;
    return $url;
}

add_action('admin_menu', 'lcrm_register_menus');
function lcrm_register_menus() {
    $unread = lcrm_count_unread_alerts();
    $badge  = $unread ? ' <span class="awaiting-mod count-' . intval($unread) . '"><span class="pending-count">' . intval($unread) . '</span></span>' : '';
    add_menu_page('LeadCRM', 'LeadCRM' . $badge, 'lcrm_view_leads', 'leadcrm', 'lcrm_page_leads', 'dashicons-networking', 3); // FIX#9: default to All Leads
    add_submenu_page('leadcrm', 'Dashboard',       'Dashboard',        'lcrm_view_leads',      'leadcrm',              'lcrm_page_dashboard');
    add_submenu_page('leadcrm', 'All Leads',        'All Leads',        'lcrm_view_leads',      'leadcrm-leads',        'lcrm_page_leads');
    add_submenu_page('leadcrm', 'Add Lead',         '+ Add Lead',       'lcrm_add_leads',       'leadcrm-add',          'lcrm_page_add_lead');
    add_submenu_page('leadcrm', "Today's Tasks",    '&#128197; Today\'s Tasks', 'lcrm_view_leads','leadcrm-tasks',      'lcrm_page_tasks');
    add_submenu_page('leadcrm', 'Assignments',      '&#128101; Assignments', 'manage_options',  'leadcrm-assignments',  'lcrm_page_assignments');
    add_submenu_page('leadcrm', 'Alerts',           ' Alerts'.$badge, 'lcrm_view_leads',      'leadcrm-alerts',       'lcrm_page_alerts');
    add_submenu_page('leadcrm', 'Analytics',   '&#128202; Analytics', 'lcrm_view_leads', 'leadcrm-analytics', 'lcrm_page_analytics');
    add_submenu_page('leadcrm', 'Pipeline',    '&#127867; Pipeline',  'lcrm_view_leads', 'leadcrm-pipeline',  'lcrm_page_pipeline');
    add_submenu_page('leadcrm', 'Team Inbox',  '&#128101; Team',      'lcrm_view_leads', 'leadcrm-team',      'lcrm_page_team_inbox');
    add_submenu_page('leadcrm', 'Vendors',          'Vendors',          'lcrm_view_vendor_data','leadcrm-vendors',      'lcrm_page_vendors');
    add_submenu_page('leadcrm', 'Brands',           'Brands',           'manage_options',       'leadcrm-brands',       'lcrm_page_brands');
    add_submenu_page('leadcrm', 'Settings',         'Settings',         'lcrm_manage_settings', 'leadcrm-settings',     'lcrm_page_settings');
    add_submenu_page('leadcrm', 'Custom Fields',    'Custom Fields',    'lcrm_manage_settings', 'leadcrm-fields',       'lcrm_page_custom_fields');
    add_submenu_page('leadcrm', 'Import / Export',  'Import/Export',    'manage_options',       'leadcrm-import',       'lcrm_page_import');

    // CRM App link — opens standalone SPA in new tab
    $crm_page_id = (int) get_option('lcrm_spa_page_id', 0);
    if ( $crm_page_id ) {
        $crm_url = get_permalink( $crm_page_id );
        add_submenu_page( 'leadcrm', 'Open CRM App', '⚡ Open CRM App ↗', 'lcrm_view_leads', 'leadcrm-open-app', '__return_null' );
    }
}

function lcrm_header($title, $actions = '') {
    echo '<div class="lcrm-wrap"><div class="lcrm-topbar"><h1 class="lcrm-page-title">' . esc_html($title) . '</h1><div class="lcrm-topbar-actions">' . $actions . '</div></div>';
}
function lcrm_footer() { echo '</div>'; }

/* =========================================================
   DASHBOARD
   ========================================================= */
function lcrm_page_dashboard() {
    $stats   = lcrm_get_dashboard_stats();
    $alerts  = lcrm_get_dashboard_alerts();
    $stages  = lcrm_get_stages();
    lcrm_header('Dashboard', '<button id="lcrm-run-automation-btn" class="button lcrm-btn">&#9881; Run Automation Now</button>');
    ?>
    <!-- ACTION REQUIRED SECTION -->
    <?php if ($alerts['total_unread'] > 0): ?>
    <div class="lcrm-action-required-banner">
        <div class="lcrm-ar-title">&#128680; ACTION REQUIRED - <?php echo intval($alerts['total_unread']); ?> alerts need attention</div>
        <div class="lcrm-ar-chips">
            <?php if ($alerts['payment_no_work']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#128308; <?php echo intval($alerts['payment_no_work']); ?> Payment &amp; Work Pending</span><?php endif; ?>
            <?php if ($alerts['quote_pending']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#128196; <?php echo intval($alerts['quote_pending']); ?> Quotation Pending</span><?php endif; ?>
            <?php if ($alerts['vendor_issues']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#9888; <?php echo intval($alerts['vendor_issues']); ?> Vendor Follow-up</span><?php endif; ?>
            <?php if (!empty($alerts['hot_no_followup']) && $alerts['hot_no_followup']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#128293; <?php echo intval($alerts['hot_no_followup']); ?> Hot Lead No Follow-up</span><?php endif; ?>
            <?php if (!empty($alerts['competitor_risk']) && $alerts['competitor_risk']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#9889; <?php echo intval($alerts['competitor_risk']); ?> Competitor Risk</span><?php endif; ?>
            <?php if (!empty($alerts['awaiting_decision']) && $alerts['awaiting_decision']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#9201; <?php echo intval($alerts['awaiting_decision']); ?> Decision Stalled</span><?php endif; ?>
            <?php if (!empty($alerts['price_objections']) && $alerts['price_objections']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#128176; <?php echo intval($alerts['price_objections']); ?> Price Objection</span><?php endif; ?>
            <?php if (!empty($alerts['quotation_silent']) && $alerts['quotation_silent']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#128194; <?php echo intval($alerts['quotation_silent']); ?> Quotation Silent</span><?php endif; ?>
            <?php if (!empty($alerts['payment_pending']) && $alerts['payment_pending']): ?><span class="lcrm-ar-chip lcrm-ar-red">&#128180; <?php echo intval($alerts['payment_pending']); ?> Payment Pending</span><?php endif; ?>
            <?php if (!empty($alerts['interested_stalled']) && $alerts['interested_stalled']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#128161; <?php echo intval($alerts['interested_stalled']); ?> Interested Not Actioned</span><?php endif; ?>
            <?php if (!empty($alerts['docs_pending']) && $alerts['docs_pending']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#128204; <?php echo intval($alerts['docs_pending']); ?> Docs Overdue</span><?php endif; ?>
            <?php if (!empty($alerts['highvalue_cold']) && $alerts['highvalue_cold']): ?><span class="lcrm-ar-chip lcrm-ar-amber">&#10052; <?php echo intval($alerts['highvalue_cold']); ?> High-Value Gone Cold</span><?php endif; ?>
        </div>
        <a href="<?php echo lcrm_page_url('leadcrm-alerts'); ?>" class="button button-primary lcrm-btn">View All Alerts</a>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <?php $isAdmin = lcrm_can_see_revenue_dashboard(); ?>
    <div class="lcrm-stats-grid">
        <div class="lcrm-stat-card">
            <div class="lcrm-stat-num"><?php echo intval($stats['total_leads']); ?></div>
            <div class="lcrm-stat-label">Total Leads</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">+<?php echo intval($stats['month_leads']); ?> this month</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-blue">
            <div class="lcrm-stat-num"><?php echo intval($stats['today_leads']); ?></div>
            <div class="lcrm-stat-label">Added Today</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-amber">
            <div class="lcrm-stat-num"><?php echo intval($stats['follow_up_today']); ?></div>
            <div class="lcrm-stat-label">Follow-up Today</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-red">
            <div class="lcrm-stat-num"><?php echo intval($stats['hot_leads']); ?></div>
            <div class="lcrm-stat-label">&#128293; Hot Leads</div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="lcrm-stat-card lcrm-stat-orange">
            <div class="lcrm-stat-num"><?php echo intval($stats['payment_no_work']); ?></div>
            <div class="lcrm-stat-label">PMT Recd / Not Started</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-green">
            <div class="lcrm-stat-num">&#8377;<?php echo number_format($stats['total_revenue'], 0); ?></div>
            <div class="lcrm-stat-label">Total Revenue</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">&#8377;<?php echo number_format($stats['month_revenue'],0); ?> this month</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-purple">
            <div class="lcrm-stat-num">&#8377;<?php echo number_format($stats['total_profit'], 0); ?></div>
            <div class="lcrm-stat-label">Total Profit</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">&#8377;<?php echo number_format($stats['month_profit'],0); ?> this month</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-red">
            <div class="lcrm-stat-num">&#8377;<?php echo number_format($stats['pending_balance'], 0); ?></div>
            <div class="lcrm-stat-label">Pending Balance</div>
        </div>
        <div class="lcrm-stat-card" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;">
            <div class="lcrm-stat-num" style="color:#fff;"><?php echo $stats['conversion_rate']; ?>%</div>
            <div class="lcrm-stat-label" style="color:rgba(255,255,255,.8);">Conversion Rate</div>
        </div>
        <?php else: ?>
        <div class="lcrm-stat-card lcrm-stat-amber">
            <div class="lcrm-stat-num"><?php echo intval($stats['client_fu_today']); ?></div>
            <div class="lcrm-stat-label">Client Follow-up Today</div>
        </div>
        <div class="lcrm-stat-card lcrm-stat-red">
            <div class="lcrm-stat-num"><?php echo intval($stats['high_priority']); ?></div>
            <div class="lcrm-stat-label">High Priority</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin && (!empty($stats['by_staff']) || !empty($stats['by_source']))): ?>
    <div class="lcrm-two-col" style="margin-bottom:18px;">
        <!-- Staff Performance -->
        <?php if (!empty($stats['by_staff'])): ?>
        <div class="lcrm-card">
            <div class="lcrm-card-title">&#128101; Staff Performance</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:7px 10px;text-align:left;">Staff</th>
                    <th style="padding:7px 10px;text-align:right;">Leads</th>
                    <th style="padding:7px 10px;text-align:right;">Converted</th>
                    <th style="padding:7px 10px;text-align:right;">Revenue</th>
                </tr></thead>
                <tbody>
                <?php foreach ($stats['by_staff'] as $s):
                    $conv_pct = $s->total_leads > 0 ? round($s->converted/$s->total_leads*100) : 0; ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:7px 10px;font-weight:600;"><?php echo esc_html($s->name ?: 'Unassigned'); ?></td>
                    <td style="padding:7px 10px;text-align:right;"><?php echo intval($s->total_leads); ?></td>
                    <td style="padding:7px 10px;text-align:right;color:#10b981;"><?php echo intval($s->converted); ?> <span style="color:#94a3b8;font-size:11px;">(<?php echo $conv_pct; ?>%)</span></td>
                    <td style="padding:7px 10px;text-align:right;font-weight:600;color:#6366f1;">&#8377;<?php echo number_format((float)$s->revenue,0); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Revenue by Source -->
        <?php if (!empty($stats['by_source'])): ?>
        <div class="lcrm-card">
            <div class="lcrm-card-title">&#127919; Top Lead Sources</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:7px 10px;text-align:left;">Source</th>
                    <th style="padding:7px 10px;text-align:right;">Leads</th>
                    <th style="padding:7px 10px;text-align:right;">Revenue</th>
                </tr></thead>
                <tbody>
                <?php foreach ($stats['by_source'] as $src): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:7px 10px;font-weight:600;"><?php echo esc_html($src->lead_source); ?></td>
                    <td style="padding:7px 10px;text-align:right;"><?php echo intval($src->cnt); ?></td>
                    <td style="padding:7px 10px;text-align:right;font-weight:600;color:#6366f1;">&#8377;<?php echo number_format((float)$src->revenue,0); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- URGENT ALERTS + STAGE CHART -->
    <div class="lcrm-two-col">
        <div class="lcrm-card">
            <div class="lcrm-card-title">&#128308; Urgent Alerts</div>
            <?php if (empty($alerts['urgent'])): ?>
                <p style="color:#94a3b8;font-size:13px;">All clear! No urgent actions.</p>
            <?php else: foreach ($alerts['urgent'] as $a): ?>
            <div class="lcrm-alert-row lcrm-alert-high">
                <div class="lcrm-alert-msg"><?php echo esc_html($a->message); ?></div>
                <div class="lcrm-alert-meta">
                    <a href="<?php echo lcrm_page_url('leadcrm-leads',['edit'=>$a->lead_id]); ?>" class="lcrm-link-btn">View Lead</a>
                    <button class="lcrm-mark-read-btn lcrm-btn-xs" data-id="<?php echo esc_attr($a->id); ?>">&#10003; Done</button>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="lcrm-card">
            <div class="lcrm-card-title">Leads by Stage</div>
            <?php foreach ($stats['by_stage'] as $s): ?>
            <div class="lcrm-stage-bar-row">
                <span class="lcrm-stage-dot" style="background:<?php echo esc_attr($s->color); ?>"></span>
                <span class="lcrm-stage-bar-label"><?php echo esc_html($s->name); ?></span>
                <span class="lcrm-stage-bar-count"><?php echo intval($s->cnt); ?></span>
                <div class="lcrm-stage-bar-track">
                    <div class="lcrm-stage-bar-fill" style="width:<?php echo $stats['total_leads'] ? min(100, round($s->cnt / $stats['total_leads'] * 100)) : 0; ?>%;background:<?php echo esc_attr($s->color); ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- FOLLOW-UP TODAY -->
    <div class="lcrm-two-col">
        <div class="lcrm-card">
            <div class="lcrm-card-title">Client Follow-up Today (<?php echo intval($stats['client_fu_today']); ?>)</div>
            <?php
            $fu = lcrm_get_leads(['client_followup_today'=>true,'limit'=>10]);
            if (empty($fu)) echo '<p style="color:#94a3b8;font-size:13px;">None today &#127881;</p>';
            foreach ($fu as $l) {
                $wa = lcrm_render_whatsapp_button($l->client_phone, lcrm_generate_message($l,'admin_followup'), 'WhatsApp');
                echo '<div class="lcrm-fu-row"><strong>' . esc_html($l->client_name) . '</strong><span>' . esc_html($l->work_type) . '</span><span class="lcrm-badge" style="background:' . esc_attr($l->stage_color) . '">' . esc_html($l->stage_name) . '</span>' . $wa . '<a href="' . lcrm_page_url('leadcrm-leads',['edit'=>$l->id]) . '" class="lcrm-link-btn">Edit</a></div>';
            }
            ?>
        </div>
        <div class="lcrm-card">
            <div class="lcrm-card-title">Vendor Follow-up Today (<?php echo intval($stats['vendor_fu_today']); ?>)</div>
            <?php
            $vfu = lcrm_get_leads(['vendor_followup_today'=>true,'limit'=>10]);
            if (empty($vfu)) echo '<p style="color:#94a3b8;font-size:13px;">None today &#127881;</p>';
            foreach ($vfu as $l) {
                $vendor_phone = (isset($l->vendor_phone) ? $l->vendor_phone : '');
                $wa = $vendor_phone ? lcrm_render_whatsapp_button($vendor_phone, lcrm_generate_message($l,'vendor_followup'), 'WhatsApp Vendor') : '';
                echo '<div class="lcrm-fu-row"><strong>' . esc_html($l->client_name) . '</strong><span>' . esc_html((isset($l->vendor_name) ? $l->vendor_name : '-')) . '</span>' . $wa . '<a href="' . lcrm_page_url('leadcrm-leads',['edit'=>$l->id]) . '" class="lcrm-link-btn">Edit</a></div>';
            }
            ?>
        </div>
    </div>
    <?php
    lcrm_footer();
}

/* =========================================================
   ALERTS PAGE
   ========================================================= */
function lcrm_page_alerts() {
    lcrm_header('Alerts - Action Required',
        '<button id="lcrm-mark-all-read-btn" class="button lcrm-btn">&#10003; Mark All Read</button>
         <button id="lcrm-run-automation-btn" class="button lcrm-btn">&#9881; Refresh Alerts</button>'
    );
    $settings = lcrm_get_automation_settings();
    ?>
    <div class="lcrm-alert-filters lcrm-card" style="margin-bottom:16px;">
        <label>Priority:</label>
        <select id="lcrm-alert-priority" class="lcrm-input">
            <option value="">All</option>
            <option value="high">&#128308; High</option>
            <option value="medium">&#128993; Medium</option>
            <option value="low">&#9898; Low</option>
        </select>
        <label><input type="checkbox" id="lcrm-alert-unread"> Unread only</label>
        <button class="button lcrm-btn" id="lcrm-load-alerts-btn">Load</button>
    </div>
    <div id="lcrm-alerts-wrap">
        <div class="lcrm-loading">Loading alerts...</div>
    </div>
    <script>
    (function(){
        // In SPA context DOMContentLoaded already fired — run immediately
        function alertsInit(){
            lcrmLoadAlerts();
            document.getElementById('lcrm-load-alerts-btn').addEventListener('click', lcrmLoadAlerts);
        document.getElementById('lcrm-mark-all-read-btn').addEventListener('click', function(){
            jQuery.post(LCRM.ajax_url,{action:'lcrm_mark_alert_read',nonce:LCRM.nonce,id:0},function(r){
                if(r.success) lcrmLoadAlerts();
            });
        });
    }
    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', alertsInit); }
    else { alertsInit(); }
    })();
    function lcrmLoadAlerts(){
        var pri = document.getElementById('lcrm-alert-priority').value;
        var unread = document.getElementById('lcrm-alert-unread').checked ? 1 : 0;
        jQuery.post(LCRM.ajax_url,{action:'lcrm_get_alerts',nonce:LCRM.nonce,priority:pri,unread_only:unread},function(r){
            if(!r.success) return;
            var html='';
            if(!r.data.alerts.length){html='<div class="lcrm-card" style="text-align:center;padding:40px;color:#94a3b8;"><div style="font-size:32px;">&#9989;</div><p>No alerts. All clear!</p></div>';}
            r.data.alerts.forEach(function(a){
                var cls=a.priority==='high'?'lcrm-alert-high':(a.priority==='medium'?'lcrm-alert-medium':'lcrm-alert-low');
                var icon=a.priority==='high'?'&#128308;':(a.priority==='medium'?'&#128993;':'&#9898;');
                html+='<div class="lcrm-alert-card '+cls+(a.is_read?' lcrm-alert-read':'')+'" data-id="'+a.id+'">';
                html+='<div class="lcrm-alert-header"><span class="lcrm-alert-pri-badge">'+icon+' '+a.priority.toUpperCase()+'</span>';
                html+='<span class="lcrm-alert-type">'+a.type.replace(/_/g,' ')+'</span>';
                html+='<span class="lcrm-alert-time">'+a.created_at+'</span></div>';
                html+='<div class="lcrm-alert-msg">'+a.message+'</div>';
                html+='<div class="lcrm-alert-footer"><strong>'+a.client_name+'</strong> &mdash; '+a.work_type+'</div>';
                html+='<div class="lcrm-alert-actions">';
                <?php if ( defined('LCRM_SPA_RENDER') && LCRM_SPA_RENDER ): ?>
                html+='<a href="javascript:void(0)" onclick="lcrmOpenLeadModal('+a.lead_id+')" class="button lcrm-btn lcrm-btn-sm">View Lead</a> ';
                <?php else: ?>
                html+='<a href="<?php echo esc_js(lcrm_page_url('leadcrm-leads')); ?>&edit='+a.lead_id+'" class="button lcrm-btn lcrm-btn-sm">View Lead</a> ';
                <?php endif; ?>
                html+='<button class="button lcrm-btn lcrm-btn-sm lcrm-mark-read-btn" data-id="'+a.id+'">&#10003; Done</button>';
                html+='</div></div>';
            });
            document.getElementById('lcrm-alerts-wrap').innerHTML=html;
        });
    }
    </script>
    <?php
    lcrm_footer();
}

/* =========================================================
   ALL LEADS
   ========================================================= */
function lcrm_page_leads() {
    $stages  = lcrm_get_stages();
    $vendors = lcrm_get_vendors();
    $brands  = lcrm_get_brands();
    $wt      = lcrm_get_work_types();
    $remarks = lcrm_get_remarks_grouped();
    $edit_id = intval((isset($_GET['edit']) ? $_GET['edit'] : 0));

    lcrm_header('All Leads',
        '<button id="lcrm-add-lead-btn" class="button button-primary lcrm-btn">+ Add Lead</button>
         <button id="lcrm-export-btn" class="button lcrm-btn">&#8595; Export CSV</button>
         <span id="lcrm-col-panel-anchor" style="position:relative;display:inline-block;">
           <button id="lcrm-col-picker-btn" class="button lcrm-btn" title="Column options: visibility, order, resize, wrap text">&#9776; Columns</button>
         </span>
         <button id="lcrm-filter-settings-btn" class="button lcrm-btn" title="Manage filters">&#9881; Filters</button>
         <button id="lcrm-bulk-status-btn"  class="button lcrm-btn" style="display:none">Change Stage</button>
         <button id="lcrm-bulk-priority-btn" class="button lcrm-btn" style="display:none">Set Priority</button>
         <button id="lcrm-bulk-vendor-btn"  class="button lcrm-btn" style="display:none">Assign Vendor</button>
         <button id="lcrm-bulk-delete-btn"  class="button lcrm-btn lcrm-btn-danger" style="display:none">Delete</button>'
    );
    ?>
    <!-- Column Options Panel built dynamically by JS (lcrmBuildColPickerPanel) -->

    <!-- Filter Settings Panel (FIX #5) -->
    <div id="lcrm-filter-settings-panel" class="lcrm-prefs-panel" style="display:none;">
        <div class="lcrm-prefs-panel-title">&#9881; Visible Filters <button class="lcrm-prefs-close" data-target="lcrm-filter-settings-panel">&times;</button></div>
        <div class="lcrm-prefs-grid">
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-search-wrap" checked> Search</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-stage" checked> Stage</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-vendor" checked> Vendor</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-brand" checked> Brand</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-wt" checked> Work Type</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-priority" checked> Priority</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-temp" checked> Temperature</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-date" checked> Date Range</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-wstatus"> Status</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-status"> Internal Status</label>
            <label class="lcrm-prefs-check"><input type="checkbox" class="lcrm-filter-toggle" data-filter="lcrm-fwrap-remarks"> Remarks Search</label>
        </div>
        <button class="button lcrm-btn lcrm-btn-sm" id="lcrm-filter-save-btn">Save Preferences</button>
    </div>

    <!-- Filters (FIX #5) -->
    <div class="lcrm-filters" id="lcrm-filters-bar">
        <span id="lcrm-search-wrap"><input type="text" id="lcrm-search" placeholder="Search name, phone, work type..." class="lcrm-input"></span>
        <span id="lcrm-fwrap-stage"><select id="lcrm-filter-stage" class="lcrm-input">
            <option value="">All Stages</option>
            <?php foreach ($stages as $s) echo '<option value="' . esc_attr($s->id) . '">' . esc_html($s->name) . '</option>'; ?>
        </select></span>
        <span id="lcrm-fwrap-wstatus"><select id="lcrm-filter-wstatus" class="lcrm-input">
            <option value="">All Status</option>
            <?php foreach (lcrm_work_statuses() as $ws) echo '<option value="' . esc_attr($ws) . '">' . esc_html($ws) . '</option>'; ?>
        </select></span>
        <span id="lcrm-fwrap-vendor"><select id="lcrm-filter-vendor" class="lcrm-input">
            <option value="">All Vendors</option>
            <?php foreach ($vendors as $v) echo '<option value="' . esc_attr($v->id) . '">' . esc_html($v->name) . '</option>'; ?>
        </select></span>
        <span id="lcrm-fwrap-brand"><select id="lcrm-filter-brand" class="lcrm-input">
            <option value="">All Brands</option>
            <?php foreach ($brands as $b) echo '<option value="' . esc_attr($b->id) . '">' . esc_html($b->name) . '</option>'; ?>
        </select></span>
        <span id="lcrm-fwrap-wt"><select id="lcrm-filter-wt" class="lcrm-input">
            <option value="">All Work Types</option>
            <?php foreach ($wt as $w) echo '<option value="' . esc_attr($w->name) . '">' . esc_html($w->name) . '</option>'; ?>
        </select></span>
        <span id="lcrm-fwrap-priority"><select id="lcrm-filter-priority" class="lcrm-input">
            <option value="">All Priority</option>
            <option value="high">&#128308; High</option>
            <option value="medium">&#128993; Medium</option>
            <option value="low">&#9898; Low</option>
        </select></span>
        <span id="lcrm-fwrap-temp"><select id="lcrm-filter-temp" class="lcrm-input">
            <option value="">All Temperature</option>
            <option value="hot">&#128293; Hot</option>
            <option value="warm">&#9728; Warm</option>
            <option value="cold">&#10052; Cold</option>
        </select></span>
        <span id="lcrm-fwrap-date" style="display:inline-flex;gap:4px;">
            <input type="date" id="lcrm-filter-from" class="lcrm-input" placeholder="From">
            <input type="date" id="lcrm-filter-to"   class="lcrm-input" placeholder="To">
        </span>
        <span id="lcrm-fwrap-status" style="display:none;"><input type="text" id="lcrm-filter-status" class="lcrm-input" placeholder="Internal Status..."></span>
        <span id="lcrm-fwrap-remarks" style="display:none;"><input type="text" id="lcrm-filter-remarks" class="lcrm-input" placeholder="Search remarks..."></span>
        <button id="lcrm-filter-btn" class="button lcrm-btn" title="Re-run the current filters">Refresh</button>
        <button id="lcrm-reset-btn"  class="button lcrm-btn">Reset</button>
    </div>

    <div class="lcrm-table-wrap">
        <table class="lcrm-table" id="lcrm-leads-table">
            <thead id="lcrm-thead">
                <!-- Built dynamically by lcrmBuildThead() in admin.js -->
            </thead>
            <tbody id="lcrm-tbody">
                <tr><td colspan="18" class="lcrm-loading">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="lcrm-pagination" id="lcrm-pagination"></div>

    <?php if ( ! defined('LCRM_SPA_RENDER') || ! LCRM_SPA_RENDER ): ?>
    <!-- Lead Edit Modal — only in wp-admin, not in SPA (shell renders it) -->
    <div id="lcrm-lead-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay"></div>
        <div class="lcrm-modal-box lcrm-modal-lg">
            <div class="lcrm-modal-header">
                <h2 id="lcrm-modal-title">Lead Details</h2>
                <button class="lcrm-modal-close" id="lcrm-modal-close">&times;</button>
            </div>
            <div class="lcrm-modal-body" id="lcrm-modal-body">
                <?php lcrm_render_lead_form($stages, $vendors, $brands, $wt, $remarks); ?>
            </div>
        </div>
    </div>
    <?php lcrm_render_bulk_modals( $stages, $vendors ); ?>
    <?php endif; ?>

    <?php /* LCRM_EDIT_ID passed via data-edit-id on lcrm-wrap — see lcrm_header */ ?>
    <?php
    lcrm_footer();
}

/* =========================================================
   SPA MODAL CONTAINER — called from lcrm_spa_shell()
   Renders all CRM modals into the page so they are
   available before any section is loaded.
   ========================================================= */
function lcrm_render_all_modals() {
    global $wpdb;
    // Guard: tables must exist (fresh install edge case)
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}lcrm_stages'" ) ) return;
    $stages  = lcrm_get_stages();
    $vendors = lcrm_get_vendors();
    $brands  = lcrm_get_brands();
    $wt      = lcrm_get_work_types();
    $remarks = lcrm_get_remarks_grouped();
    ?>
    <!-- Lead Edit Modal — skip in SPA (rendered in shell via lcrm_render_all_modals) -->
    <?php if ( ! defined('LCRM_SPA_RENDER') || ! LCRM_SPA_RENDER ): ?>
    <div id="lcrm-lead-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay"></div>
        <div class="lcrm-modal-box lcrm-modal-lg">
            <div class="lcrm-modal-header">
                <h2 id="lcrm-modal-title">Lead Details</h2>
                <button class="lcrm-modal-close" id="lcrm-modal-close">&times;</button>
            </div>
            <div class="lcrm-modal-body" id="lcrm-modal-body">
                <?php lcrm_render_lead_form($stages, $vendors, $brands, $wt, $remarks); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Bulk modals — skip in SPA (rendered in shell) -->
    <?php if ( ! defined('LCRM_SPA_RENDER') || ! LCRM_SPA_RENDER ) lcrm_render_bulk_modals($stages, $vendors); ?>
    <script>var LCRM_EDIT_ID = 0;</script>
    <?php
}

function lcrm_render_bulk_modals($stages, $vendors) {
    ?>
    <div id="lcrm-bulk-stage-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay"></div>
        <div class="lcrm-modal-box lcrm-modal-sm">
            <div class="lcrm-modal-header"><h2>Change Stage</h2><button class="lcrm-modal-close">&times;</button></div>
            <div class="lcrm-modal-body">
                <select id="lcrm-bulk-stage-val" class="lcrm-input lcrm-input-full">
                    <?php foreach ($stages as $s) echo '<option value="' . esc_attr($s->id) . '">' . esc_html($s->name) . '</option>'; ?>
                </select>
                <button class="button button-primary lcrm-btn lcrm-mt" id="lcrm-bulk-stage-confirm">Apply</button>
            </div>
        </div>
    </div>
    <div id="lcrm-bulk-priority-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay"></div>
        <div class="lcrm-modal-box lcrm-modal-sm">
            <div class="lcrm-modal-header"><h2>Set Priority</h2><button class="lcrm-modal-close">&times;</button></div>
            <div class="lcrm-modal-body">
                <select id="lcrm-bulk-priority-val" class="lcrm-input lcrm-input-full">
                    <option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option>
                </select>
                <button class="button button-primary lcrm-btn lcrm-mt" id="lcrm-bulk-priority-confirm">Apply</button>
            </div>
        </div>
    </div>
    <div id="lcrm-bulk-vendor-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay"></div>
        <div class="lcrm-modal-box lcrm-modal-sm">
            <div class="lcrm-modal-header"><h2>Assign Vendor</h2><button class="lcrm-modal-close">&times;</button></div>
            <div class="lcrm-modal-body">
                <select id="lcrm-bulk-vendor-val" class="lcrm-input lcrm-input-full">
                    <?php foreach ($vendors as $v) echo '<option value="' . esc_attr($v->id) . '">' . esc_html($v->name) . '</option>'; ?>
                </select>
                <button class="button button-primary lcrm-btn lcrm-mt" id="lcrm-bulk-vendor-confirm">Apply</button>
            </div>
        </div>
    </div>
    <?php
}


/* =========================================================
   LEAD FORM (shared: add + edit modal) — DYNAMIC RENDERER
   All fields (built-in + custom) come from lcrm_build_form_fields_by_tab()
   so their tab and order can be changed in the Layout Editor.
   ========================================================= */
function lcrm_render_lead_form($stages, $vendors, $brands, $wt, $remarks) {
    $sources     = lcrm_get_lead_sources();
    $crm_users   = lcrm_get_crm_users();
    $render_data = compact('stages','vendors','brands','wt','remarks','sources','crm_users');
    $fields_by_tab = lcrm_build_form_fields_by_tab();
    $tab_labels  = ['basic'=>'Basic Info','follow'=>'Follow-up','financial'=>'Financial','vendor'=>'Vendor','remarks'=>'Remarks'];
    $qr = [];
    foreach ($remarks as $cat => $items) foreach ($items as $r) $qr[] = ['cat'=>$cat,'text'=>$r->remark];
    echo '<script>window.LCRM_QR='.wp_json_encode($qr).';</script>';
    ?>
    <form id="lcrm-lead-form" class="lcrm-form" action="javascript:void(0)" onsubmit="return false;">
        <input type="hidden" id="lf-id" name="id" value="">
        <div class="lcrm-form-tabs">
            <?php $first=true; foreach ($tab_labels as $tk=>$tl): ?>
            <button type="button" class="lcrm-ftab <?php echo $first?'active':''; ?>" data-tab="<?php echo esc_attr($tk); ?>"><?php echo esc_html($tl); ?></button>
            <?php $first=false; endforeach; ?>
        </div>

        <?php $first=true; foreach ($tab_labels as $tk=>$tl): ?>
        <div class="lcrm-ftab-pane <?php echo $first?'active':''; ?>" data-pane="<?php echo esc_attr($tk); ?>"<?php echo $first?'':' style="display:none;"'; ?>>
            <?php
            /* --- Check if this tab has any permission-blocked fields --- */
            $has_financial_gate = ($tk==='financial');
            $has_vendor_gate    = ($tk==='vendor');
            if ($has_financial_gate && function_exists('lcrm_can_see_financial') && !lcrm_can_see_financial()):
            ?>
            <p style="color:#94a3b8;">You do not have permission to view financial data.</p>
            <?php elseif ($has_vendor_gate && function_exists('lcrm_can_see_vendor') && !lcrm_can_see_vendor()): ?>
            <p style="color:#94a3b8;">You do not have permission to view vendor data.</p>
            <?php else: ?>
            <div class="lcrm-form-grid">
            <?php
            $tab_fields = isset($fields_by_tab[$tk]) ? $fields_by_tab[$tk] : [];
            foreach ($tab_fields as $field) {
                // Skip vendor_sel if no vendor access, skip financial fields if no finance access
                if (!empty($field['gate'])) {
                    if ($field['gate']==='financial' && function_exists('lcrm_can_see_financial') && !lcrm_can_see_financial()) continue;
                    if ($field['gate']==='vendor'    && function_exists('lcrm_can_see_vendor')    && !lcrm_can_see_vendor())    continue;
                }
                echo lcrm_render_form_field_html($field, $render_data);
            }
            ?>
            </div>
            <?php
            /* --- Special anchored sections per tab (not moveable, always at bottom) --- */
            if ($tk==='follow'): ?>
            <div id="lcrm-reminder-section" style="margin-top:16px;">
                <div class="lcrm-card-title" style="margin-bottom:8px;">Set Reminder</div>
                <div class="lcrm-form-grid">
                    <div class="lcrm-form-group"><label>Remind At (date &amp; time)</label><input type="datetime-local" id="lcrm-remind-at" class="lcrm-input lcrm-input-full"></div>
                    <div class="lcrm-form-group"><label>Reminder Note</label><input type="text" id="lcrm-remind-note" class="lcrm-input lcrm-input-full" placeholder="e.g. Call and confirm payment"></div>
                </div>
                <button type="button" class="button lcrm-btn" id="lcrm-set-reminder-btn">Set Reminder</button>
                <span id="lcrm-remind-msg" class="lcrm-form-msg"></span>
            </div>
            <?php elseif ($tk==='vendor' && function_exists('lcrm_can_see_vendor') && lcrm_can_see_vendor()): ?>
            <div class="lcrm-card lcrm-wa-section" id="lcrm-vendor-msg-section" style="display:none;">
                <div class="lcrm-card-title">&#128172; Send Message to Vendor</div>
                <div class="lcrm-form-grid" style="margin-bottom:10px;">
                    <div class="lcrm-form-group"><label>Message Type</label>
                        <select id="lcrm-msg-type" class="lcrm-input lcrm-input-full">
                            <option value="vendor_assignment">Vendor Assignment (Full Details)</option>
                            <option value="vendor_followup">Follow-up Message</option>
                            <option value="vendor_share">Custom Field Share</option>
                        </select>
                    </div>
                </div>
                <div id="lcrm-field-checkboxes" class="lcrm-field-picker" style="display:none;margin-bottom:10px;">
                    <label style="font-size:12px;color:#64748b;display:block;margin-bottom:6px;">Select fields to include:</label>
                    <?php
                    $shareable = ['client_name'=>'Client Name','work_type'=>'Service','client_phone'=>'Phone','client_email'=>'Email','lead_source'=>'Lead Source','remarks'=>'Notes','next_action_date'=>'Timeline','priority'=>'Priority','stage_name'=>'Current Status','total_quote'=>'Quote Amount'];
                    foreach ($shareable as $fk=>$fl): ?>
                    <label class="lcrm-check-pill"><input type="checkbox" name="share_fields[]" value="<?php echo esc_attr($fk); ?>" checked><?php echo esc_html($fl); ?></label>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:8px;margin-bottom:10px;">
                    <button type="button" class="button lcrm-btn" id="lcrm-gen-msg-btn">&#9889; Generate Message</button>
                    <button type="button" class="button lcrm-btn" id="lcrm-log-contact-btn" style="display:none;">&#128203; Log Contact</button>
                </div>
                <textarea id="lcrm-vendor-msg-txt" class="lcrm-input lcrm-input-full lcrm-textarea" rows="6" placeholder="Generated message will appear here..."></textarea>
                <div style="display:flex;gap:8px;margin-top:8px;" id="lcrm-wa-buttons"></div>
                <div id="lcrm-vendor-logs" style="margin-top:16px;"></div>
            </div>
            <?php elseif ($tk==='remarks'): ?>
            <div id="lcrm-conv-wrap" style="display:none;margin-top:16px;">
                <div class="lcrm-conv-header">
                    <span>&#128172;</span>
                    <span class="lcrm-conv-title">Conversation &amp; Notes</span>
                    <span id="lcrm-conv-count" class="lcrm-conv-count"></span>
                    <button type="button" class="lcrm-conv-refresh" id="lcrm-conv-refresh-btn" title="Refresh">&#8635;</button>
                </div>
                <div id="lcrm-conv-timeline" class="lcrm-conv-timeline">
                    <div class="lcrm-conv-empty">No entries yet.</div>
                </div>
                <div class="lcrm-qr-wrap">
                    <div class="lcrm-qr-topbar">
                        <span class="lcrm-qr-label">&#9889; Quick Replies</span>
                        <input type="text" id="lcrm-qr-search" class="lcrm-input lcrm-qr-search" placeholder="Search quick replies&#8230;" autocomplete="off">
                        <button type="button" class="lcrm-qr-add-btn" id="lcrm-qr-add-btn">&#43; Add</button>
                        <button type="button" class="lcrm-qr-toggle" id="lcrm-qr-toggle">&#9660;</button>
                    </div>
                    <div id="lcrm-qr-add-form" class="lcrm-qr-add-form" style="display:none;">
                        <input type="text" id="lcrm-qr-add-cat" class="lcrm-input lcrm-qr-add-cat" placeholder="Category (e.g. Follow-up)&#8230;" autocomplete="off">
                        <input type="text" id="lcrm-qr-add-text" class="lcrm-input lcrm-qr-add-text" placeholder="Quick reply text&#8230;" autocomplete="off">
                        <div class="lcrm-qr-add-footer">
                            <span id="lcrm-qr-add-msg" class="lcrm-form-msg"></span>
                            <button type="button" class="lcrm-qr-save-btn" id="lcrm-qr-save-btn">Save</button>
                            <button type="button" class="lcrm-qr-cancel-btn" id="lcrm-qr-add-cancel">Cancel</button>
                        </div>
                    </div>
                    <div class="lcrm-qr-body" id="lcrm-qr-body">
                        <div id="lcrm-qr-pills" class="lcrm-qr-pills">
                            <span class="lcrm-qr-empty">Loading&#8230;</span>
                        </div>
                    </div>
                </div>
                <div class="lcrm-conv-compose">
                    <textarea id="lcrm-conv-message" class="lcrm-input lcrm-input-full lcrm-textarea lcrm-conv-textarea"
                        rows="3" placeholder="Write what the client said, next action, or any note&#8230;"></textarea>
                    <div class="lcrm-conv-compose-footer">
                        <span id="lcrm-conv-msg" class="lcrm-form-msg" style="flex:1;"></span>
                        <button type="button" class="button lcrm-btn lcrm-conv-send-btn" id="lcrm-conv-send-btn">&#10148; Add</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; /* end permission gate */ ?>
        </div>
        <?php $first=false; endforeach; ?>

        <div class="lcrm-modal-footer">
            <div id="lcrm-form-msg" class="lcrm-form-msg"></div>
            <button type="button" class="button lcrm-btn" id="lcrm-modal-close-btn">Cancel</button>
            <button type="submit" class="button button-primary lcrm-btn" id="lcrm-form-submit">Save Lead</button>
            <button type="button" class="button lcrm-btn lcrm-btn-done" id="lcrm-form-done-btn" style="background:#10b981;color:#fff;border-color:#10b981;">&#10003; Save &amp; Close</button>
        </div>
    </form>
    <?php
}


/* =========================================================
   ADD LEAD (standalone page)
   ========================================================= */
function lcrm_page_tasks() {
    lcrm_header("Today's Tasks");
    $today   = date('Y-m-d');
    $uid     = get_current_user_id();
    $isAdmin = lcrm_can_see_all_leads();
    global $wpdb;

    // Scope: admin sees all, staff sees only their assigned leads
    $scope_where = '';
    $scope_vals  = [];
    if (!$isAdmin && lcrm_col_exists('assigned_to')) {
        $scope_where = ' AND (l.assigned_to=%d AND COALESCE(l.is_access_revoked,0)=0)';
        $scope_vals  = [$uid];
    }

    $sections = [
        'follow_up'     => ['label'=>'&#128222; Follow-up / Next Action Today', 'color'=>'#6366f1', 'field'=>'next_action_date',      'leads'=>[]],
        'client_fu'     => ['label'=>'&#128100; Client Follow-up Today',         'color'=>'#f59e0b', 'field'=>'client_followup_date',  'leads'=>[]],
        'vendor_fu'     => ['label'=>'&#128666; Vendor Follow-up Today',          'color'=>'#10b981', 'field'=>'vendor_followup_date', 'leads'=>[]],
        'reminders'     => ['label'=>'&#9201; Reminders Due Today',               'color'=>'#ef4444', 'field'=>'',                    'leads'=>[]],
    ];

    // Query each section
    foreach (['follow_up','client_fu','vendor_fu'] as $k) {
        $field = $sections[$k]['field'];
        $sql = "SELECT l.id,l.client_name,l.client_phone,l.work_type,l.priority,l.lead_temperature,
                       s.name AS stage_name, s.color AS stage_color,
                       l.next_action_type, l.{$field} AS task_date
                FROM {$wpdb->prefix}lcrm_leads l
                LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id=l.stage_id
                WHERE l.{$field}=%s{$scope_where}
                ORDER BY l.priority DESC, l.client_name ASC";
        $vals = array_merge([$today], $scope_vals);
        $sections[$k]['leads'] = $wpdb->get_results($wpdb->prepare($sql,...$vals));
    }

    // Reminders
    $rem_sql = "SELECT r.*, l.client_name, l.client_phone, l.work_type, l.priority,
                        s.name AS stage_name, s.color AS stage_color
                FROM {$wpdb->prefix}lcrm_reminders r
                LEFT JOIN {$wpdb->prefix}lcrm_leads l ON l.id=r.lead_id
                LEFT JOIN {$wpdb->prefix}lcrm_stages s ON s.id=l.stage_id
                WHERE DATE(r.remind_at) <= %s AND r.sent=0
                ORDER BY r.remind_at ASC";
    $sections['reminders']['leads'] = $wpdb->get_results($wpdb->prepare($rem_sql, $today));

    $total = array_sum(array_map(function($s){ return count($s['leads']); }, $sections));
    ?>
    <div style="margin:0 18px 16px;">
        <?php if ($total === 0): ?>
        <div class="lcrm-card" style="text-align:center;padding:48px 24px;">
            <div style="font-size:48px;margin-bottom:12px;">&#127881;</div>
            <h3 style="color:#10b981;margin:0 0 8px;">All clear for today!</h3>
            <p style="color:#64748b;margin:0;">No follow-ups, reminders, or tasks due today.</p>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <h2 style="margin:0;font-size:18px;color:#1e293b;">Tasks for <?php echo date('l, d F Y'); ?></h2>
            <span style="background:#6366f1;color:#fff;border-radius:20px;padding:2px 12px;font-size:12px;font-weight:700;"><?php echo $total; ?> total</span>
        </div>

        <?php foreach ($sections as $key => $section):
            if (empty($section['leads'])) continue; ?>
        <div class="lcrm-card" style="margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:<?php echo esc_attr($section['color']); ?>15;border-bottom:2px solid <?php echo esc_attr($section['color']); ?>;">
                <h3 style="margin:0;font-size:14px;color:<?php echo esc_attr($section['color']); ?>;"><?php echo $section['label']; ?></h3>
                <span style="background:<?php echo esc_attr($section['color']); ?>;color:#fff;border-radius:20px;padding:1px 10px;font-size:11px;font-weight:700;margin-left:auto;"><?php echo count($section['leads']); ?></span>
            </div>
            <table class="lcrm-table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Client</th><th>Phone</th><th>Work Type</th>
                        <th>Stage</th><th>Priority</th>
                        <?php if ($key === 'reminders'): ?><th>Reminder Note</th><th>Due</th>
                        <?php else: ?><th>Action Type</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($section['leads'] as $l):
                    $pBg = ['high'=>'#fee2e2','medium'=>'#fef9c3','low'=>'#f0fdf4'];
                    $pColor = ['high'=>'#991b1b','medium'=>'#854d0e','low'=>'#166534'];
                    $pri = isset($l->priority) ? $l->priority : 'medium';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($l->client_name); ?></strong></td>
                    <td>
                        <?php if (!empty($l->client_phone)): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$l->client_phone); ?>" target="_blank"
                           style="color:#25D366;font-weight:600;text-decoration:none;">&#128172; <?php echo esc_html($l->client_phone); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?php echo esc_html($l->work_type); ?></td>
                    <td>
                        <?php if (!empty($l->stage_color)): ?>
                        <span class="lcrm-badge" style="background:<?php echo esc_attr($l->stage_color); ?>"><?php echo esc_html($l->stage_name); ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span style="background:<?php echo $pBg[$pri]??'#f1f5f9'; ?>;color:<?php echo $pColor[$pri]??'#334155'; ?>;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:600;text-transform:capitalize;"><?php echo esc_html($pri); ?></span></td>
                    <?php if ($key === 'reminders'): ?>
                    <td style="font-size:12px;color:#334155;"><?php echo esc_html($l->note ?? ''); ?></td>
                    <td style="font-size:11px;color:#ef4444;font-weight:600;"><?php echo esc_html(substr($l->remind_at??'',0,16)); ?></td>
                    <?php else: ?>
                    <td style="font-size:12px;color:#6366f1;"><?php echo esc_html($l->next_action_type??''); ?></td>
                    <?php endif; ?>
                    <td style="white-space:nowrap;">
                        <button class="button button-primary lcrm-btn lcrm-btn-sm lcrm-edit-lead" data-id="<?php echo intval($l->id); ?>">Edit</button>
                        <?php if (!empty($l->client_phone)): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$l->client_phone); ?>" target="_blank"
                           class="button lcrm-btn lcrm-btn-sm" style="color:#25D366;border-color:#25D366;">WA</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php lcrm_footer();
}

function lcrm_page_add_lead() {
    // FIX #6: Add Lead now opens as a modal on the All Leads page
    $url = defined('LCRM_SPA_RENDER') && LCRM_SPA_RENDER
        ? 'javascript:window.lcrmOpenLeadModal(0)'
        : esc_js( admin_url( 'admin.php?page=leadcrm-leads&open_add=1' ) );
    // Location redirect handled by SPA JS — no inline script needed
    if ( defined('LCRM_SPA_RENDER') && LCRM_SPA_RENDER ) {
        // SPA handles Add Lead via modal — no redirect needed
    } else {
        echo '<script>window.location="' . esc_js($url) . '";</script>';
    }
    lcrm_footer();
}

/* =========================================================
   KANBAN BOARD
   ========================================================= */
// Kanban board removed

/* =========================================================
   VENDORS PAGE
   ========================================================= */
function lcrm_page_assignments() {
    if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
    global $wpdb;

    // Ensure columns/tables exist inline — never fails silently
    $cols = (array)$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lcrm_leads", 0);
    if (!in_array('assigned_to',      $cols, true)) $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_leads ADD COLUMN assigned_to BIGINT UNSIGNED DEFAULT NULL");
    if (!in_array('is_access_revoked',$cols, true)) $wpdb->query("ALTER TABLE {$wpdb->prefix}lcrm_leads ADD COLUMN is_access_revoked TINYINT(1) NOT NULL DEFAULT 0");
    $at = $wpdb->prefix . 'lcrm_lead_assignments';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$at)) !== $at) {
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE IF NOT EXISTS {$at} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, lead_id BIGINT UNSIGNED NOT NULL, action ENUM('assigned','reassigned','revoked','taken','transferred') NOT NULL DEFAULT 'assigned', from_user_id BIGINT UNSIGNED DEFAULT NULL, to_user_id BIGINT UNSIGNED DEFAULT NULL, done_by BIGINT UNSIGNED NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY lead_id (lead_id)) $c;");
    }

    $users  = get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name']]);
    $stages = lcrm_get_stages();
    lcrm_header('Lead Assignments');
    ?>
    <!-- Filters -->
    <div class="lcrm-card" style="padding:12px 16px;margin-bottom:14px;">
        <div style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;">
            <div>
                <label class="lcrm-filter-label">SEARCH</label>
                <input type="text" id="las-search" class="lcrm-input" placeholder="Name / phone / work type&#8230;" style="width:200px;">
            </div>
            <div>
                <label class="lcrm-filter-label">ASSIGNED TO</label>
                <select id="las-user" class="lcrm-input" style="width:170px;">
                    <option value="">— All Users —</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="lcrm-filter-label">STAGE</label>
                <select id="las-stage" class="lcrm-input" style="width:140px;">
                    <option value="">— All —</option>
                    <?php foreach ($stages as $s): ?>
                    <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="lcrm-filter-label">STATUS</label>
                <select id="las-status" class="lcrm-input" style="width:140px;">
                    <option value="">— All —</option>
                    <option value="assigned">&#9989; Assigned</option>
                    <option value="unassigned">&#9675; Unassigned</option>
                    <option value="revoked">&#128274; Revoked</option>
                </select>
            </div>
            <button class="button button-primary lcrm-btn" id="las-filter-btn">Filter</button>
            <button class="button lcrm-btn" id="las-reset-btn">Reset</button>
            <span id="las-count" style="margin-left:auto;font-size:12px;color:#64748b;"></span>
        </div>
    </div>

    <!-- Bulk assign bar -->
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
        <span style="font-size:13px;font-weight:700;color:#0369a1;">&#128101; Bulk Assign:</span>
        <select id="las-bulk-user" class="lcrm-input" style="width:220px;">
            <option value="">— Select user —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button button-primary lcrm-btn" id="las-bulk-assign-btn">&#128101; Assign Selected</button>
        <span id="las-bulk-msg" class="lcrm-form-msg"></span>
        <span id="las-sel-count" style="margin-left:auto;font-size:12px;color:#0369a1;font-weight:600;">0 selected</span>
    </div>

    <!-- Table -->
    <div class="lcrm-table-wrap" style="overflow-x:auto;">
        <table class="lcrm-table" id="las-table" style="min-width:900px;">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="las-check-all" title="Select all"></th>
                    <th class="las-sortable" data-col="id" style="width:60px;">ID <span class="las-sort-icon">&#8597;</span></th>
                    <th class="las-sortable" data-col="client_name">Client <span class="las-sort-icon">&#8597;</span></th>
                    <th class="las-sortable" data-col="work_type">Work Type <span class="las-sort-icon">&#8597;</span></th>
                    <th class="las-sortable" data-col="lead_date" style="width:90px;">Date <span class="las-sort-icon">&#8597;</span></th>
                    <th>Stage</th>
                    <th>Created By</th>
                    <th style="min-width:240px;">Assigned To</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:200px;">Actions</th>
                </tr>
            </thead>
            <tbody id="las-tbody">
                <tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Loading&#8230;</td></tr>
            </tbody>
        </table>
    </div>
    <div id="las-pagination" style="margin-top:12px;"></div>

    <!-- Assignment History Modal -->
    <div id="lcrm-history-modal" class="lcrm-modal" style="display:none;">
        <div class="lcrm-modal-overlay" id="lcrm-history-overlay"></div>
        <div class="lcrm-modal-box" style="max-width:620px;">
            <div class="lcrm-modal-header">
                <h2>&#128203; Assignment History</h2>
                <button class="lcrm-modal-close" id="lcrm-history-close">&times;</button>
            </div>
            <div class="lcrm-modal-body" id="lcrm-history-body" style="max-height:420px;overflow-y:auto;padding:16px;">
                <p style="color:#94a3b8;text-align:center;padding:20px;">Loading&#8230;</p>
            </div>
        </div>
    </div>

    <!-- Quick-assign users JSON for JS -->
    <script>
    window.LAS_USERS = <?php echo wp_json_encode(array_map(function($u){ return ['id'=>$u->ID,'name'=>$u->display_name]; }, $users)); ?>;
    </script>
    <?php lcrm_footer();
}

function lcrm_page_vendors() {
    global $wpdb;
    $vendors = $wpdb->get_results(
        "SELECT v.*, vc.name AS category_name,
                (SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads WHERE vendor_id=v.id) AS lead_count
         FROM {$wpdb->prefix}lcrm_vendors v
         LEFT JOIN {$wpdb->prefix}lcrm_vendor_categories vc ON vc.id=v.category_id
         ORDER BY v.name ASC"
    );
    $cats = lcrm_get_vendor_cats();
    lcrm_header('Vendors', '<button class="button button-primary lcrm-btn" id="lcrm-add-vendor-btn">+ Add Vendor</button>');
    ?>
    <div id="lcrm-vendor-form-wrap" class="lcrm-card" style="display:none;margin-bottom:20px;">
        <h3 id="lcrm-vendor-form-title">Add Vendor</h3>
        <input type="hidden" id="lv-id" value="">
        <div class="lcrm-form-grid">
            <div class="lcrm-form-group"><label>Name *</label><input type="text" id="lv-name" class="lcrm-input lcrm-input-full"></div>
            <div class="lcrm-form-group"><label>Category</label>
                <select id="lv-category_id" class="lcrm-input lcrm-input-full">
                    <option value="">- None -</option>
                    <?php foreach ($cats as $c) echo '<option value="' . esc_attr($c->id) . '">' . esc_html($c->name) . '</option>'; ?>
                </select>
            </div>
            <div class="lcrm-form-group"><label>Phone</label><input type="text" id="lv-phone" class="lcrm-input lcrm-input-full"></div>
            <div class="lcrm-form-group"><label>Phone 2</label><input type="text" id="lv-phone2" class="lcrm-input lcrm-input-full"></div>
            <div class="lcrm-form-group"><label>Email</label><input type="email" id="lv-email" class="lcrm-input lcrm-input-full"></div>
            <div class="lcrm-form-group"><label>Company</label><input type="text" id="lv-company" class="lcrm-input lcrm-input-full"></div>
        </div>
        <div class="lcrm-form-group"><label>Address</label><textarea id="lv-address" class="lcrm-input lcrm-input-full lcrm-textarea" rows="2"></textarea></div>
        <div class="lcrm-form-actions">
            <button class="button button-primary lcrm-btn" id="lcrm-save-vendor-btn">Save</button>
            <button class="button lcrm-btn" id="lcrm-cancel-vendor-btn">Cancel</button>
            <span id="lcrm-vendor-msg" class="lcrm-form-msg"></span>
        </div>
    </div>
    <div class="lcrm-table-wrap">
        <table class="lcrm-table">
            <thead><tr><th>Name</th><th>Category</th><th>Company</th><th>Phone</th><th>Phone 2</th><th>Email</th><th>Leads</th><th>WhatsApp</th><th>Actions</th></tr></thead>
            <tbody id="lcrm-vendors-tbody">
                <?php foreach ($vendors as $v): ?>
                <tr id="vrow-<?php echo $v->id; ?>">
                    <td><strong><?php echo esc_html($v->name); ?></strong></td>
                    <td><span class="lcrm-badge lcrm-badge-gray"><?php echo esc_html((isset($v->category_name) ? $v->category_name : '-')); ?></span></td>
                    <td><?php echo esc_html($v->company ?: '-'); ?></td>
                    <td><?php echo esc_html($v->phone ?: '-'); ?></td>
                    <td><?php echo esc_html($v->phone2 ?: '-'); ?></td>
                    <td><?php echo esc_html($v->email ?: '-'); ?></td>
                    <td><span class="lcrm-badge lcrm-badge-gray"><?php echo intval($v->lead_count); ?></span></td>
                    <td><?php if ($v->phone) echo '<a href="https://wa.me/' . preg_replace('/[^0-9]/','',$v->phone) . '" target="_blank" class="lcrm-wa-btn">&#128172; WhatsApp</a>'; ?></td>
                    <td>
                        <button class="button lcrm-btn lcrm-btn-sm lcrm-vendor-edit"
                            data-id="<?php echo $v->id; ?>"
                            data-name="<?php echo esc_attr($v->name); ?>"
                            data-phone="<?php echo esc_attr($v->phone); ?>"
                            data-phone2="<?php echo esc_attr($v->phone2); ?>"
                            data-email="<?php echo esc_attr($v->email); ?>"
                            data-company="<?php echo esc_attr($v->company); ?>"
                            data-category_id="<?php echo esc_attr($v->category_id); ?>"
                            data-address="<?php echo esc_attr($v->address); ?>">Edit</button>
                        <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-vendor-del" data-id="<?php echo $v->id; ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    lcrm_footer();
}

/* =========================================================
   BRANDS PAGE
   ========================================================= */
function lcrm_page_brands() {
    global $wpdb;
    $brands = lcrm_get_brands();
    $cats   = lcrm_get_vendor_cats();
    lcrm_header('Brands', '<button class="button button-primary lcrm-btn" id="lcrm-add-brand-btn">+ Add Brand</button>');
    ?>
    <div class="lcrm-two-col">
        <div class="lcrm-card">
            <div class="lcrm-card-head"><h3>Brands</h3></div>
            <div id="lcrm-brand-form-wrap" class="lcrm-inline-form" style="display:none;">
                <input type="hidden" id="lb-id" value="">
                <input type="text" id="lb-name" class="lcrm-input" placeholder="Brand name" style="width:240px">
                <button class="button button-primary lcrm-btn" id="lb-save-btn">Save</button>
                <button class="button lcrm-btn" id="lb-cancel-btn">Cancel</button>
                <span id="lb-msg" class="lcrm-form-msg"></span>
            </div>
            <table class="lcrm-table" style="margin-top:12px;">
                <thead><tr><th>Brand Name</th><th>Actions</th></tr></thead>
                <tbody id="lcrm-brands-tbody">
                    <?php foreach ($brands as $b): ?>
                    <tr id="brand-row-<?php echo $b->id; ?>">
                        <td><strong><?php echo esc_html($b->name); ?></strong></td>
                        <td>
                            <button class="button lcrm-btn lcrm-btn-sm lcrm-brand-edit" data-id="<?php echo $b->id; ?>" data-name="<?php echo esc_attr($b->name); ?>">Edit</button>
                            <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-brand-del" data-id="<?php echo $b->id; ?>">Del</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="lcrm-card">
            <div class="lcrm-card-head"><h3>Vendor Categories <button class="button button-primary lcrm-btn" id="lcrm-add-vcat-btn" style="margin-left:10px;">+ Add</button></h3></div>
            <div id="lcrm-vcat-form-wrap" class="lcrm-inline-form" style="display:none;">
                <input type="hidden" id="lvc-id" value="">
                <input type="text" id="lvc-name" class="lcrm-input" placeholder="Category name" style="width:200px">
                <button class="button button-primary lcrm-btn" id="lvc-save-btn">Save</button>
                <button class="button lcrm-btn" id="lvc-cancel-btn">Cancel</button>
                <span id="lvc-msg" class="lcrm-form-msg"></span>
            </div>
            <table class="lcrm-table" style="margin-top:12px;">
                <thead><tr><th>Category</th><th>Actions</th></tr></thead>
                <tbody id="lcrm-vcats-tbody">
                    <?php foreach ($cats as $c): ?>
                    <tr id="vcat-row-<?php echo $c->id; ?>">
                        <td><?php echo esc_html($c->name); ?></td>
                        <td>
                            <button class="button lcrm-btn lcrm-btn-sm lcrm-vcat-edit" data-id="<?php echo $c->id; ?>" data-name="<?php echo esc_attr($c->name); ?>">Edit</button>
                            <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-vcat-del" data-id="<?php echo $c->id; ?>">Del</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    lcrm_footer();
}

/* =========================================================
   SETTINGS
   ========================================================= */
function lcrm_page_settings() {
    $active_tab = sanitize_key((isset($_GET['tab']) ? $_GET['tab'] : 'stages'));
    $tabs = [
        'stages'       => 'Pipeline Stages',
        'remarks'      => 'Remark Templates',
        'work_types'   => 'Work Types',
        'lead_sources' => 'Lead Sources',
        'automation'   => 'Automation',
        'whatsapp'     => '&#128242; WhatsApp',
        'email'        => '&#9993; Email',
        'social'       => '&#128293; Social DMs',
        'ai'           => '&#129302; AI Engine',
        'integrations' => '&#128279; Integrations',
        'payments'     => '&#128180; Payments',
        'webhooks'     => '&#128279; Webhooks',
        'sla'          => '&#9203; SLA Rules',
        'templates'    => '&#128203; WA Templates',
        'ai_pro'       => '&#129302; AI Intelligence',
    ];
    lcrm_header('Settings');
    ?>
    <div class="lcrm-settings-tabs" style="flex-wrap:wrap;">
        <?php foreach ($tabs as $key => $label): ?>
        <a href="<?php echo lcrm_page_url('leadcrm-settings',['tab'=>$key]); ?>" class="lcrm-stab <?php echo $active_tab===$key?'active':''; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <?php
    if      ($active_tab === 'stages')       lcrm_settings_stages();
    elseif  ($active_tab === 'remarks')      lcrm_settings_remarks();
    elseif  ($active_tab === 'work_types')   lcrm_settings_dropdown('work_type','Work Types');
    elseif  ($active_tab === 'lead_sources') lcrm_settings_dropdown('lead_source','Lead Sources');
    elseif  ($active_tab === 'automation')   lcrm_settings_automation();
    elseif  ($active_tab === 'whatsapp')     lcrm_wa_render_settings_tab();
    elseif  ($active_tab === 'email')        lcrm_settings_email_tab();
    elseif  ($active_tab === 'social')       lcrm_settings_social_tab();
    elseif  ($active_tab === 'ai')           lcrm_settings_ai_tab();
    elseif  ($active_tab === 'integrations') lcrm_settings_integrations_tab();
    elseif  ($active_tab === 'payments')    lcrm_settings_payments_tab();
    elseif  ($active_tab === 'webhooks')    lcrm_settings_webhooks_tab();
    elseif  ($active_tab === 'sla')         lcrm_settings_sla_tab();
    elseif  ($active_tab === 'templates')   lcrm_settings_templates_tab();
    elseif  ($active_tab === 'ai_pro')      lcrm_settings_ai_pro_tab();
    lcrm_footer();
}

// ─────────────────────────────────────────────────────────────────────────────
// AI SETTINGS TAB
// ─────────────────────────────────────────────────────────────────────────────
function lcrm_settings_ai_tab() {
    $enabled     = get_option('lcrm_ai_enabled', 0);
    $provider    = get_option('lcrm_ai_provider', 'openai');
    $model       = get_option('lcrm_ai_model', '');
    $auto_score  = get_option('lcrm_ai_auto_score', 1);
    $auto_tasks  = get_option('lcrm_ai_auto_tasks', 1);
    $auto_transcribe = get_option('lcrm_ai_auto_transcribe', 1);
    ?>
    <div class="lcrm-card">
        <div class="lcrm-card-head"><h3>&#129302; AI Engine Settings</h3></div>
        <form id="lcrm-ai-settings-form">
            <div class="lcrm-form-grid">
                <div class="lcrm-form-group">
                    <label>Enable AI</label>
                    <div class="lcrm-toggle-wrap">
                        <input type="checkbox" id="ai_enabled" name="ai_enabled" value="1" class="lcrm-toggle" <?php checked($enabled,1); ?>>
                        <label for="ai_enabled" class="lcrm-toggle-lbl">Enable AI features</label>
                    </div>
                </div>
                <div class="lcrm-form-group">
                    <label>Provider</label>
                    <select name="ai_provider" class="lcrm-input lcrm-input-full">
                        <option value="openai" <?php selected($provider,'openai'); ?>>OpenAI (GPT-4o-mini)</option>
                        <option value="anthropic" <?php selected($provider,'anthropic'); ?>>Anthropic (Claude Haiku)</option>
                    </select>
                </div>
                <div class="lcrm-form-group">
                    <label>API Key</label>
                    <input type="password" name="ai_api_key" class="lcrm-input lcrm-input-full" placeholder="sk-... or sk-ant-..." value="<?php echo esc_attr(get_option('lcrm_ai_api_key','')); ?>">
                </div>
                <div class="lcrm-form-group">
                    <label>Model (optional override)</label>
                    <input type="text" name="ai_model" class="lcrm-input lcrm-input-full" placeholder="Leave blank for default" value="<?php echo esc_attr($model); ?>">
                </div>
            </div>
            <div class="lcrm-form-grid" style="margin-top:14px;">
                <div class="lcrm-form-group">
                    <label>Auto-score intent on new messages</label>
                    <div class="lcrm-toggle-wrap">
                        <input type="checkbox" id="ai_auto_score" name="ai_auto_score" value="1" class="lcrm-toggle" <?php checked($auto_score,1); ?>>
                        <label for="ai_auto_score" class="lcrm-toggle-lbl">Update lead temperature automatically</label>
                    </div>
                </div>
                <div class="lcrm-form-group">
                    <label>Auto-extract tasks from messages</label>
                    <div class="lcrm-toggle-wrap">
                        <input type="checkbox" id="ai_auto_tasks" name="ai_auto_tasks" value="1" class="lcrm-toggle" <?php checked($auto_tasks,1); ?>>
                        <label for="ai_auto_tasks" class="lcrm-toggle-lbl">Create alerts from AI-extracted tasks</label>
                    </div>
                </div>
                <div class="lcrm-form-group">
                    <label>Auto-transcribe voice notes</label>
                    <div class="lcrm-toggle-wrap">
                        <input type="checkbox" id="ai_auto_transcribe" name="ai_auto_transcribe" value="1" class="lcrm-toggle" <?php checked($auto_transcribe,1); ?>>
                        <label for="ai_auto_transcribe" class="lcrm-toggle-lbl">Transcribe WA voice messages via Whisper</label>
                    </div>
                </div>
            </div>
            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                <button type="button" class="button button-primary lcrm-btn" id="lcrm-ai-save-btn">Save AI Settings</button>
                <button type="button" class="button lcrm-btn" id="lcrm-ai-test-btn">Test Connection</button>
                <button type="button" class="button lcrm-btn" id="lcrm-ai-brief-btn">Preview Morning Brief</button>
                <span id="lcrm-ai-msg" style="color:#10b981;font-size:13px;"></span>
            </div>
        </form>
        <div id="lcrm-ai-brief-output" style="display:none;margin-top:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;white-space:pre-wrap;font-size:13px;"></div>
    </div>

    <script>
    (function(){ var _spaDclInit=function(){
        var msg = document.getElementById('lcrm-ai-msg');
        document.getElementById('lcrm-ai-save-btn').addEventListener('click',function(){
            var f = document.getElementById('lcrm-ai-settings-form');
            var d = new FormData(f);
            d.append('action','lcrm_ai_save_settings');
            d.append('nonce',LCRM.nonce);
            fetch(LCRM.ajax_url,{method:'POST',body:d}).then(r=>r.json()).then(function(res){
                msg.textContent = res.success ? '&#10003; Saved.' : (res.data?.msg||'Error');
                msg.style.color = res.success ? '#10b981' : '#ef4444';
            });
        });
        document.getElementById('lcrm-ai-test-btn').addEventListener('click',function(){
            msg.textContent = 'Testing...';
            var d = new FormData();
            d.append('action','lcrm_ai_test'); d.append('nonce',LCRM.nonce);
            fetch(LCRM.ajax_url,{method:'POST',body:d}).then(r=>r.json()).then(function(res){
                msg.textContent = res.success ? '&#10003; ' + res.data.msg : '&#10007; ' + (res.data?.msg||'Failed');
                msg.style.color = res.success ? '#10b981' : '#ef4444';
            });
        });
        document.getElementById('lcrm-ai-brief-btn').addEventListener('click',function(){
            var out = document.getElementById('lcrm-ai-brief-output');
            out.textContent = 'Generating...'; out.style.display='block';
            var d = new FormData();
            d.append('action','lcrm_ai_get_brief'); d.append('nonce',LCRM.nonce);
            fetch(LCRM.ajax_url,{method:'POST',body:d}).then(r=>r.json()).then(function(res){
                out.textContent = res.success ? res.data.brief : 'Error generating brief.';
            });
        });
    };
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',_spaDclInit);}else{_spaDclInit();}
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// EMAIL SETTINGS TAB
// ─────────────────────────────────────────────────────────────────────────────
function lcrm_settings_email_tab() {
    global $wpdb;
    $accounts = $wpdb->get_results("SELECT e.*, b.name AS brand_name FROM {$wpdb->prefix}lcrm_email_accounts e LEFT JOIN {$wpdb->prefix}lcrm_brands b ON b.id=e.brand_id ORDER BY e.display_name ASC");
    $brands   = lcrm_get_brands();
    $imap_ok  = function_exists('imap_open');
    ?>
    <div class="lcrm-card" style="margin-bottom:20px;">
        <div class="lcrm-card-head">
            <h3>&#9993; Email Capture Accounts</h3>
            <button class="button button-primary lcrm-btn" id="lcrm-email-add-btn">+ Add Email Account</button>
        </div>
        <?php if (!$imap_ok): ?>
        <div style="background:#fef3c7;border:1px solid #fde68a;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13px;color:#92400e;">
            <strong>Note:</strong> PHP IMAP extension not detected on this server. Contact your host to enable it, or use Gmail OAuth instead.
        </div>
        <?php endif; ?>

        <div id="lcrm-email-account-form" class="lcrm-inline-form" style="display:none;background:#f8fafc;padding:14px;border-radius:8px;margin-bottom:14px;border:1px solid #e2e8f0;">
            <input type="hidden" id="lea-id" value="">
            <div class="lcrm-form-grid" style="grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px;">
                <div class="lcrm-form-group"><label>Display Name</label><input type="text" id="lea-display" class="lcrm-input lcrm-input-full" placeholder="e.g. Sales Inbox"></div>
                <div class="lcrm-form-group"><label>Email Address</label><input type="email" id="lea-email" class="lcrm-input lcrm-input-full" placeholder="sales@yourdomain.com"></div>
                <div class="lcrm-form-group"><label>Brand</label><select id="lea-brand" class="lcrm-input lcrm-input-full"><option value="">- No Brand -</option><?php foreach($brands as $b): ?><option value="<?php echo esc_attr($b->id); ?>"><?php echo esc_html($b->name); ?></option><?php endforeach; ?></select></div>
                <div class="lcrm-form-group"><label>Method</label><select id="lea-method" class="lcrm-input lcrm-input-full"><option value="imap">IMAP (Generic)</option><option value="gmail_oauth">Gmail OAuth</option></select></div>
                <div class="lcrm-form-group"><label>IMAP Host</label><input type="text" id="lea-host" class="lcrm-input lcrm-input-full" placeholder="imap.gmail.com"></div>
                <div class="lcrm-form-group"><label>Port / Encryption</label>
                    <div style="display:flex;gap:6px;">
                        <input type="number" id="lea-port" class="lcrm-input" style="width:80px;" value="993">
                        <select id="lea-enc" class="lcrm-input lcrm-input-full"><option value="ssl">SSL</option><option value="tls">TLS</option><option value="none">None</option></select>
                    </div>
                </div>
                <div class="lcrm-form-group"><label>IMAP Username</label><input type="text" id="lea-user" class="lcrm-input lcrm-input-full" placeholder="Usually same as email"></div>
                <div class="lcrm-form-group"><label>IMAP Password / App Password</label><input type="password" id="lea-pass" class="lcrm-input lcrm-input-full" placeholder="Gmail: use App Password"></div>
                <div class="lcrm-form-group"><label>Active</label><div class="lcrm-toggle-wrap"><input type="checkbox" id="lea-active" class="lcrm-toggle" value="1" checked><label for="lea-active" class="lcrm-toggle-lbl">Active</label></div></div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button class="button button-primary lcrm-btn" id="lcrm-email-save-btn">Save Account</button>
                <button class="button lcrm-btn" id="lcrm-email-test-btn">Test Connection</button>
                <button class="button lcrm-btn" id="lcrm-email-cancel-btn" style="margin-left:auto;">Cancel</button>
                <span id="lcrm-email-form-msg" style="font-size:13px;"></span>
            </div>
        </div>

        <table class="lcrm-table" style="width:100%;">
            <thead><tr><th>Name / Email</th><th>Brand</th><th>Method</th><th>Last Synced</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($accounts)): ?>
            <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:20px;">No email accounts configured. Add one above.</td></tr>
            <?php else: ?>
            <?php foreach($accounts as $a): ?>
            <tr>
                <td><strong><?php echo esc_html($a->display_name); ?></strong><br><small style="color:#6b7280;"><?php echo esc_html($a->email_address); ?></small></td>
                <td><?php echo esc_html($a->brand_name ?: '-'); ?></td>
                <td><?php echo $a->method === 'gmail_oauth' ? 'Gmail OAuth' : 'IMAP'; ?></td>
                <td><?php echo $a->last_synced_at ? esc_html(date('d M H:i', strtotime($a->last_synced_at))) : 'Never'; ?></td>
                <td><span class="lcrm-badge <?php echo $a->is_active ? 'lcrm-badge-green' : 'lcrm-badge-red'; ?>"><?php echo $a->is_active ? 'Active' : 'Inactive'; ?></span></td>
                <td>
                    <button class="button lcrm-btn lcrm-email-edit-btn" data-id="<?php echo $a->id; ?>" data-display="<?php echo esc_attr($a->display_name); ?>" data-email="<?php echo esc_attr($a->email_address); ?>" data-brand="<?php echo esc_attr($a->brand_id); ?>" data-method="<?php echo esc_attr($a->method); ?>" data-host="<?php echo esc_attr($a->imap_host); ?>" data-port="<?php echo esc_attr($a->imap_port); ?>" data-enc="<?php echo esc_attr($a->imap_encryption); ?>" data-user="<?php echo esc_attr($a->imap_username); ?>" data-active="<?php echo esc_attr($a->is_active); ?>">Edit</button>
                    <button class="button lcrm-btn lcrm-email-poll-btn" data-id="<?php echo $a->id; ?>">Poll Now</button>
                    <button class="button lcrm-btn lcrm-btn-danger lcrm-email-delete-btn" data-id="<?php echo $a->id; ?>" data-name="<?php echo esc_attr($a->display_name); ?>">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:14px;font-size:12px;color:#6b7280;">Email accounts are polled every 15 minutes automatically. <strong>Gmail users:</strong> use an App Password (not your main password). Enable 2FA in Google → Google Account → Security → App Passwords.</p>
    </div>

    <script>
    (function(){ var _spaDclInit=function(){
        var form=document.getElementById('lcrm-email-account-form'),
            msg=document.getElementById('lcrm-email-form-msg');

        document.getElementById('lcrm-email-add-btn').addEventListener('click',function(){
            document.getElementById('lea-id').value='';
            ['lea-display','lea-email','lea-host','lea-user','lea-pass'].forEach(id=>document.getElementById(id).value='');
            document.getElementById('lea-port').value='993';
            document.getElementById('lea-enc').value='ssl';
            document.getElementById('lea-method').value='imap';
            document.getElementById('lea-brand').value='';
            document.getElementById('lea-active').checked=true;
            form.style.display='block';
        });
        document.getElementById('lcrm-email-cancel-btn').addEventListener('click',function(){ form.style.display='none'; });

        document.querySelectorAll('.lcrm-email-edit-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                var d=btn.dataset;
                document.getElementById('lea-id').value=d.id;
                document.getElementById('lea-display').value=d.display;
                document.getElementById('lea-email').value=d.email;
                document.getElementById('lea-brand').value=d.brand||'';
                document.getElementById('lea-method').value=d.method||'imap';
                document.getElementById('lea-host').value=d.host||'';
                document.getElementById('lea-port').value=d.port||993;
                document.getElementById('lea-enc').value=d.enc||'ssl';
                document.getElementById('lea-user').value=d.user||'';
                document.getElementById('lea-pass').value='';
                document.getElementById('lea-active').checked=d.active==='1';
                form.style.display='block';
                form.scrollIntoView({behavior:'smooth'});
            });
        });

        document.getElementById('lcrm-email-save-btn').addEventListener('click',function(){
            var fd=new FormData();
            fd.append('action','lcrm_email_save_account');fd.append('nonce',LCRM.nonce);
            fd.append('id',document.getElementById('lea-id').value);
            fd.append('display_name',document.getElementById('lea-display').value);
            fd.append('email_address',document.getElementById('lea-email').value);
            fd.append('brand_id',document.getElementById('lea-brand').value);
            fd.append('method',document.getElementById('lea-method').value);
            fd.append('imap_host',document.getElementById('lea-host').value);
            fd.append('imap_port',document.getElementById('lea-port').value);
            fd.append('imap_encryption',document.getElementById('lea-enc').value);
            fd.append('imap_username',document.getElementById('lea-user').value);
            fd.append('imap_password',document.getElementById('lea-pass').value);
            fd.append('is_active',document.getElementById('lea-active').checked?'1':'0');
            fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(res.success){msg.textContent='✓ Saved.';msg.style.color='#10b981';setTimeout(()=>location.reload(),1000);}
                else{msg.textContent='✗ '+(res.data?.msg||'Error');msg.style.color='#ef4444';}
            });
        });

        document.getElementById('lcrm-email-test-btn').addEventListener('click',function(){
            msg.textContent='Testing...';msg.style.color='#6b7280';
            var fd=new FormData();
            fd.append('action','lcrm_email_test_account');fd.append('nonce',LCRM.nonce);
            fd.append('imap_host',document.getElementById('lea-host').value);
            fd.append('imap_port',document.getElementById('lea-port').value);
            fd.append('imap_encryption',document.getElementById('lea-enc').value);
            fd.append('imap_username',document.getElementById('lea-user').value);
            fd.append('imap_password',document.getElementById('lea-pass').value);
            fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                msg.textContent = res.success ? '✓ '+res.data.msg : '✗ '+(res.data?.msg||'Failed');
                msg.style.color = res.success ? '#10b981' : '#ef4444';
            });
        });

        document.querySelectorAll('.lcrm-email-delete-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                if(!confirm('Delete "'+btn.dataset.name+'"? This will stop polling this inbox.')) return;
                var fd=new FormData();
                fd.append('action','lcrm_email_delete_account');fd.append('nonce',LCRM.nonce);fd.append('id',btn.dataset.id);
                fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                    if(res.success) location.reload(); else alert(res.data?.msg||'Error');
                });
            });
        });

        document.querySelectorAll('.lcrm-email-poll-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                btn.textContent='Polling...';btn.disabled=true;
                var fd=new FormData();
                fd.append('action','lcrm_email_poll_now');fd.append('nonce',LCRM.nonce);
                fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                    btn.textContent='Poll Now';btn.disabled=false;
                    alert(res.data?.msg||'Poll complete.');
                });
            });
        });
    };
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',_spaDclInit);}else{_spaDclInit();}
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// SOCIAL SETTINGS TAB
// ─────────────────────────────────────────────────────────────────────────────
function lcrm_settings_social_tab() {
    global $wpdb;
    $accounts = lcrm_social_get_all_accounts();
    $brands   = lcrm_get_brands();
    ?>
    <div class="lcrm-card">
        <div class="lcrm-card-head">
            <h3>&#128293; Instagram &amp; Facebook Messenger</h3>
            <button class="button button-primary lcrm-btn" id="lcrm-social-add-btn">+ Add Account</button>
        </div>

        <div id="lcrm-social-form" class="lcrm-inline-form" style="display:none;background:#f8fafc;padding:14px;border-radius:8px;margin-bottom:14px;border:1px solid #e2e8f0;">
            <input type="hidden" id="lsa-id" value="">
            <div class="lcrm-form-grid" style="grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px;">
                <div class="lcrm-form-group"><label>Platform</label><select id="lsa-platform" class="lcrm-input lcrm-input-full"><option value="instagram">Instagram</option><option value="facebook">Facebook Messenger</option></select></div>
                <div class="lcrm-form-group"><label>Display Name</label><input type="text" id="lsa-display" class="lcrm-input lcrm-input-full" placeholder="e.g. @yourbrand_ig"></div>
                <div class="lcrm-form-group"><label>Brand</label><select id="lsa-brand" class="lcrm-input lcrm-input-full"><option value="">- No Brand -</option><?php foreach($brands as $b): ?><option value="<?php echo esc_attr($b->id); ?>"><?php echo esc_html($b->name); ?></option><?php endforeach; ?></select></div>
                <div class="lcrm-form-group"><label>Facebook Page ID</label><input type="text" id="lsa-page" class="lcrm-input lcrm-input-full" placeholder="12345678901234"></div>
                <div class="lcrm-form-group"><label>Page Access Token</label><input type="password" id="lsa-token" class="lcrm-input lcrm-input-full" placeholder="EAA..."></div>
                <div class="lcrm-form-group"><label>Instagram Account ID (IG only)</label><input type="text" id="lsa-ig" class="lcrm-input lcrm-input-full" placeholder="17841400000000000"></div>
                <div class="lcrm-form-group"><label>Webhook Verify Token</label><input type="text" id="lsa-verify" class="lcrm-input lcrm-input-full" placeholder="Auto-generated if blank"></div>
            </div>
            <div style="display:flex;gap:10px;">
                <button class="button button-primary lcrm-btn" id="lcrm-social-save-btn">Save</button>
                <button class="button lcrm-btn" id="lcrm-social-cancel-btn" style="margin-left:auto;">Cancel</button>
                <span id="lcrm-social-msg" style="font-size:13px;"></span>
            </div>
            <div id="lcrm-social-webhook-info" style="display:none;margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;padding:10px 14px;border-radius:6px;font-size:13px;">
                <strong>Webhook URL:</strong> <code id="lcrm-social-webhook-url"></code><br>
                <small>Copy this URL into your Meta App → Webhooks → Instagram/Facebook Messenger subscription.</small>
            </div>
        </div>

        <table class="lcrm-table" style="width:100%;">
            <thead><tr><th>Name</th><th>Platform</th><th>Brand</th><th>Page ID</th><th>Webhook URL</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($accounts)): ?>
            <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:20px;">No social accounts configured.</td></tr>
            <?php else: ?>
            <?php foreach($accounts as $a):
                $wh_url = rest_url("lcrm/v1/social-webhook/{$a->id}"); ?>
            <tr>
                <td><strong><?php echo esc_html($a->display_name); ?></strong></td>
                <td><?php echo $a->platform === 'instagram' ? '&#128247; Instagram' : '&#128422; Facebook'; ?></td>
                <td><?php echo esc_html($a->brand_name ?: '-'); ?></td>
                <td><code style="font-size:11px;"><?php echo esc_html($a->page_id); ?></code></td>
                <td><code style="font-size:11px;"><?php echo esc_html($wh_url); ?></code></td>
                <td>
                    <button class="button lcrm-btn lcrm-social-edit-btn" data-id="<?php echo $a->id; ?>" data-platform="<?php echo esc_attr($a->platform); ?>" data-display="<?php echo esc_attr($a->display_name); ?>" data-brand="<?php echo esc_attr($a->brand_id); ?>" data-page="<?php echo esc_attr($a->page_id); ?>" data-ig="<?php echo esc_attr($a->ig_account_id); ?>" data-verify="<?php echo esc_attr($a->webhook_verify_token); ?>">Edit</button>
                    <button class="button lcrm-btn lcrm-btn-danger lcrm-social-delete-btn" data-id="<?php echo $a->id; ?>" data-name="<?php echo esc_attr($a->display_name); ?>">Delete</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:14px;font-size:12px;color:#6b7280;">
            Setup: Create a Meta App → Add Instagram/Messenger product → Webhooks → subscribe to <code>messages</code> field using the webhook URL above.
        </p>
    </div>
    <script>
    (function(){ var _spaDclInit=function(){
        var form=document.getElementById('lcrm-social-form'),msg=document.getElementById('lcrm-social-msg');
        document.getElementById('lcrm-social-add-btn').addEventListener('click',function(){
            document.getElementById('lsa-id').value='';
            ['lsa-display','lsa-page','lsa-token','lsa-ig','lsa-verify'].forEach(id=>document.getElementById(id).value='');
            form.style.display='block';
            document.getElementById('lcrm-social-webhook-info').style.display='none';
        });
        document.getElementById('lcrm-social-cancel-btn').addEventListener('click',function(){ form.style.display='none'; });
        document.querySelectorAll('.lcrm-social-edit-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                var d=btn.dataset;
                document.getElementById('lsa-id').value=d.id;
                document.getElementById('lsa-platform').value=d.platform;
                document.getElementById('lsa-display').value=d.display;
                document.getElementById('lsa-brand').value=d.brand||'';
                document.getElementById('lsa-page').value=d.page;
                document.getElementById('lsa-ig').value=d.ig||'';
                document.getElementById('lsa-verify').value=d.verify||'';
                document.getElementById('lsa-token').value='';
                form.style.display='block';
                document.getElementById('lcrm-social-webhook-info').style.display='none';
            });
        });
        document.getElementById('lcrm-social-save-btn').addEventListener('click',function(){
            var fd=new FormData();
            fd.append('action','lcrm_social_save_account');fd.append('nonce',LCRM.nonce);
            ['lsa-id','lsa-platform','lsa-display','lsa-brand','lsa-page','lsa-token','lsa-ig','lsa-verify'].forEach(function(id){
                fd.append(id.replace('lsa-','').replace('-','_')==='id'?'id':id.replace('lsa-','').replace(/-/g,'_'),document.getElementById(id).value);
            });
            fd.set('id',document.getElementById('lsa-id').value);
            fd.set('platform',document.getElementById('lsa-platform').value);
            fd.set('display_name',document.getElementById('lsa-display').value);
            fd.set('brand_id',document.getElementById('lsa-brand').value);
            fd.set('page_id',document.getElementById('lsa-page').value);
            fd.set('page_access_token',document.getElementById('lsa-token').value);
            fd.set('ig_account_id',document.getElementById('lsa-ig').value);
            fd.set('webhook_verify_token',document.getElementById('lsa-verify').value);
            fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(res.success){
                    var info=document.getElementById('lcrm-social-webhook-info');
                    document.getElementById('lcrm-social-webhook-url').textContent=res.data.webhook_url;
                    info.style.display='block';
                    msg.textContent='✓ Saved.';msg.style.color='#10b981';
                    setTimeout(()=>location.reload(),2000);
                } else {msg.textContent='✗ '+(res.data?.msg||'Error');msg.style.color='#ef4444';}
            });
        });
        document.querySelectorAll('.lcrm-social-delete-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                if(!confirm('Delete "'+btn.dataset.name+'"?')) return;
                var fd=new FormData();
                fd.append('action','lcrm_social_delete_account');fd.append('nonce',LCRM.nonce);fd.append('id',btn.dataset.id);
                fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{ if(res.success) location.reload(); });
            });
        });
    };
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',_spaDclInit);}else{_spaDclInit();}
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// INTEGRATIONS TAB — REST API key + form plugins status
// ─────────────────────────────────────────────────────────────────────────────
function lcrm_settings_integrations_tab() {
    $rest_key        = get_option('lcrm_rest_api_key', '');
    $rest_endpoint   = rest_url('lcrm/v1/lead-capture');
    $auto_followup   = get_option('lcrm_wa_auto_followup', 0);
    $followup_tpl    = get_option('lcrm_wa_followup_template', 'Hello {name}, ji! Just following up regarding your {work} inquiry. Please let us know if you have any questions!');
    ?>
    <div class="lcrm-card" style="margin-bottom:20px;">
        <div class="lcrm-card-head"><h3>&#128279; REST API — Generic Lead Capture</h3></div>
        <p style="font-size:13px;color:#6b7280;margin-bottom:14px;">Use this endpoint to send leads from any external website, chatbot, landing page, or third-party tool. POST JSON with your API key.</p>
        <div style="background:#f1f5f9;border-radius:6px;padding:12px 16px;font-family:monospace;font-size:12px;margin-bottom:14px;">
            <strong>POST</strong> <?php echo esc_html($rest_endpoint); ?><br><br>
            { "api_key": "your_key", "client_name": "Rajesh Kumar", "client_phone": "+919876543210",<br>
              "work_type": "NRI Documentation", "lead_source": "Landing Page", "message": "I need help..." }
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <div>
                <label style="font-size:13px;font-weight:500;">API Key:</label>
                <code style="background:#f1f5f9;padding:4px 10px;border-radius:4px;font-size:12px;"><?php echo $rest_key ? esc_html($rest_key) : '(not generated)'; ?></code>
            </div>
            <button class="button lcrm-btn" id="lcrm-regen-key-btn">Regenerate Key</button>
            <span id="lcrm-key-msg" style="font-size:13px;color:#10b981;"></span>
        </div>
        <p style="font-size:12px;color:#6b7280;margin-top:10px;">Keep this key secret. Pass it in the JSON body as <code>api_key</code> or in the <code>X-LCRM-Key</code> HTTP header.</p>
    </div>

    <div class="lcrm-card" style="margin-bottom:20px;">
        <div class="lcrm-card-head"><h3>&#129302; Automated WA Follow-ups</h3></div>
        <div class="lcrm-form-grid">
            <div class="lcrm-form-group">
                <label>Enable auto follow-up on follow-up date</label>
                <div class="lcrm-toggle-wrap">
                    <input type="checkbox" id="wa_auto_followup" class="lcrm-toggle" value="1" <?php checked($auto_followup,1); ?>>
                    <label for="wa_auto_followup" class="lcrm-toggle-lbl">Send WA message automatically on client_followup_date</label>
                </div>
            </div>
            <div class="lcrm-form-group" style="grid-column:1/-1;">
                <label>Follow-up message template</label>
                <textarea id="wa_followup_template" class="lcrm-input lcrm-input-full lcrm-textarea" rows="2"><?php echo esc_textarea($followup_tpl); ?></textarea>
                <small style="color:#6b7280;">Use <code>{name}</code> for client name, <code>{work}</code> for work type.</small>
            </div>
        </div>
        <div style="margin-top:12px;">
            <button class="button button-primary lcrm-btn" id="lcrm-followup-save-btn">Save</button>
            <span id="lcrm-followup-msg" style="font-size:13px;color:#10b981;margin-left:10px;"></span>
        </div>
    </div>

    <div class="lcrm-card">
        <div class="lcrm-card-head"><h3>&#128203; Form Plugin Auto-Capture Status</h3></div>
        <table class="lcrm-table" style="width:100%;">
            <thead><tr><th>Plugin</th><th>Status</th><th>Notes</th></tr></thead>
            <tbody>
            <tr><td>Contact Form 7</td><td><?php echo class_exists('WPCF7') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Auto-captures all CF7 submissions.</td></tr>
            <tr><td>WPForms</td><td><?php echo function_exists('wpforms') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Auto-captures all WPForms submissions.</td></tr>
            <tr><td>Gravity Forms</td><td><?php echo class_exists('GFForms') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Auto-captures all Gravity Forms entries.</td></tr>
            <tr><td>Ninja Forms</td><td><?php echo class_exists('Ninja_Forms') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Auto-captures all Ninja Forms submissions.</td></tr>
            <tr><td>Fluent Forms</td><td><?php echo defined('FLUENTFORM') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Auto-captures all Fluent Forms submissions.</td></tr>
            <tr><td>WooCommerce</td><td><?php echo class_exists('WooCommerce') ? '<span class="lcrm-badge lcrm-badge-green">Active ✓</span>' : '<span class="lcrm-badge lcrm-badge-gray">Not Installed</span>'; ?></td><td>Creates lead from every new checkout order.</td></tr>
            <tr><td>Generic REST API</td><td><span class="lcrm-badge lcrm-badge-green">Always Active ✓</span></td><td>Works with any platform that can POST JSON.</td></tr>
            </tbody>
        </table>
    </div>

    <script>
    (function(){ var _spaDclInit=function(){
        document.getElementById('lcrm-regen-key-btn').addEventListener('click',function(){
            if(!confirm('Regenerate API key? Existing integrations using the old key will stop working.')) return;
            var fd=new FormData();
            fd.append('action','lcrm_regenerate_rest_key');fd.append('nonce',LCRM.nonce);
            fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(res.success){
                    document.getElementById('lcrm-key-msg').textContent='✓ New key generated.';
                    setTimeout(()=>location.reload(),1000);
                }
            });
        });

        document.getElementById('lcrm-followup-save-btn').addEventListener('click',function(){
            var fd=new FormData();
            fd.append('action','lcrm_save_followup_settings');fd.append('nonce',LCRM.nonce);
            fd.append('wa_auto_followup',document.getElementById('wa_auto_followup').checked?'1':'0');
            fd.append('wa_followup_template',document.getElementById('wa_followup_template').value);
            fetch(LCRM.ajax_url,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                var m=document.getElementById('lcrm-followup-msg');
                m.textContent=res.success?'✓ Saved.':'✗ Error'; m.style.color=res.success?'#10b981':'#ef4444';
            });
        });
    };
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',_spaDclInit);}else{_spaDclInit();}
    })();
    </script>
    <?php
}

function lcrm_settings_stages() {
    $stages = lcrm_get_stages();
    ?>
    <div class="lcrm-card">
        <div class="lcrm-card-head">
            <h3>Pipeline Stages <small style="color:#94a3b8;font-size:13px;">Drag to reorder</small></h3>
            <button class="button button-primary lcrm-btn" id="lcrm-add-stage-btn">+ Add Stage</button>
        </div>
        <div id="lcrm-stage-form-wrap" class="lcrm-inline-form" style="display:none;">
            <input type="hidden" id="ls-id" value="">
            <input type="text" id="ls-name" class="lcrm-input" placeholder="Stage name" style="width:240px">
            <input type="color" id="ls-color" value="#6366f1" style="width:48px;height:36px;padding:2px;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer">
            <button class="button button-primary lcrm-btn" id="ls-save-btn">Save</button>
            <button class="button lcrm-btn" id="ls-cancel-btn">Cancel</button>
            <span id="ls-msg" class="lcrm-form-msg"></span>
        </div>
        <ul class="lcrm-sortable-list" id="lcrm-stages-list">
            <?php foreach ($stages as $s): ?>
            <li class="lcrm-sortable-item" data-id="<?php echo $s->id; ?>">
                <span class="lcrm-drag-handle">&#8942;</span>
                <span class="lcrm-stage-swatch" style="background:<?php echo esc_attr($s->color); ?>"></span>
                <span class="lcrm-sortable-label"><?php echo esc_html($s->name); ?></span>
                <div class="lcrm-sortable-actions">
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-stage-edit" data-id="<?php echo $s->id; ?>" data-name="<?php echo esc_attr($s->name); ?>" data-color="<?php echo esc_attr($s->color); ?>">Edit</button>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-stage-del" data-id="<?php echo $s->id; ?>">Delete</button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function lcrm_settings_remarks() {
    $remarks = lcrm_get_remarks_grouped();
    ?>
    <div class="lcrm-card">
        <div class="lcrm-card-head">
            <h3>Remark Templates &amp; Quick Replies</h3>
            <div style="display:flex;gap:8px;">
                <button class="button lcrm-btn" id="lcrm-bulk-remark-open-btn">&#128203; Bulk Add</button>
                <button class="button button-primary lcrm-btn" id="lcrm-add-remark-btn">+ Add Remark</button>
            </div>
        </div>
        <div id="lcrm-bulk-remark-panel" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px;margin-bottom:14px;">
            <p style="font-size:13px;font-weight:600;margin:0 0 4px;">&#9889; Bulk Add Quick Replies</p>
            <p style="font-size:12px;color:#64748b;margin:0 0 8px;">One per line. Use <code>Category: text</code> or <code>Category | text</code>. Lines without prefix go to General.</p>
            <textarea id="lcrm-bulk-remark-text" class="lcrm-input lcrm-input-full" rows="7" style="font-family:monospace;font-size:12px;" placeholder="Follow-up: Called client, no answer&#10;Payment: Payment link sent&#10;Work Processing: Work in progress"></textarea>
            <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
                <button class="button button-primary lcrm-btn" id="lcrm-bulk-remark-btn">+ Bulk Add</button>
                <button class="button lcrm-btn" id="lcrm-bulk-remark-cancel-btn">Cancel</button>
                <span id="lcrm-bulk-remark-msg" class="lcrm-form-msg"></span>
            </div>
        </div>
        <div id="lcrm-remark-form-wrap" class="lcrm-inline-form" style="display:none;">
            <input type="hidden" id="lr-id" value="">
            <input type="text" id="lr-category" class="lcrm-input" placeholder="Category" style="width:180px">
            <input type="text" id="lr-remark" class="lcrm-input" placeholder="Remark text" style="width:340px">
            <button class="button button-primary lcrm-btn" id="lr-save-btn">Save</button>
            <button class="button lcrm-btn" id="lr-cancel-btn">Cancel</button>
            <span id="lr-msg" class="lcrm-form-msg"></span>
        </div>
        <?php foreach ($remarks as $cat => $items): ?>
        <div class="lcrm-remark-section">
            <div class="lcrm-remark-section-title"><?php echo esc_html($cat); ?> <span style="color:#94a3b8;font-size:11px;font-weight:400;">(<?php echo count($items); ?>)</span></div>
            <div class="lcrm-remark-section-body">
                <?php foreach ($items as $r): ?>
                <div class="lcrm-remark-row" id="rrow-<?php echo $r->id; ?>">
                    <span class="lcrm-remark-text"><?php echo esc_html($r->remark); ?></span>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-remark-edit" data-id="<?php echo $r->id; ?>" data-category="<?php echo esc_attr($cat); ?>" data-remark="<?php echo esc_attr($r->remark); ?>">Edit</button>
                    <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-remark-del" data-id="<?php echo $r->id; ?>">Delete</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    jQuery(function($){
        $('#lcrm-bulk-remark-open-btn').on('click',function(){ $('#lcrm-bulk-remark-panel').slideToggle(150); });
        $('#lcrm-bulk-remark-cancel-btn').on('click',function(){ $('#lcrm-bulk-remark-panel').slideUp(150); });
        $('#lcrm-bulk-remark-btn').on('click',function(){
            var raw=$('#lcrm-bulk-remark-text').val().trim();
            if(!raw){ lcrmMsg($('#lcrm-bulk-remark-msg'),'Paste some remarks first.','err'); return; }
            var $btn=$(this).prop('disabled',true).text('Adding\u2026');
            lcrmPost('lcrm_bulk_add_remarks',{text:raw},function(d){
                $btn.prop('disabled',false).text('+ Bulk Add');
                lcrmMsg($('#lcrm-bulk-remark-msg'),d.msg||'Done!','ok');
                $('#lcrm-bulk-remark-text').val('');
                setTimeout(function(){ location.reload(); },1200);
            },function(e){ $btn.prop('disabled',false).text('+ Bulk Add'); lcrmMsg($('#lcrm-bulk-remark-msg'),lcrmErr(e),'err'); });
        });
    });
    </script>
    <?php
}

function lcrm_settings_dropdown($dtype, $label) {
    global $wpdb;
    $map  = ['work_type'=>'lcrm_work_types','lead_source'=>'lcrm_lead_sources'];
    $tbl  = $wpdb->prefix . ( isset($map[$dtype]) ? $map[$dtype] : 'lcrm_work_types' );
    $items = $wpdb->get_results("SELECT * FROM $tbl ORDER BY sort_order ASC");
    ?>
    <div class="lcrm-card">
        <div class="lcrm-card-head">
            <h3><?php echo esc_html($label); ?></h3>
            <button class="button button-primary lcrm-btn" id="lcrm-add-dd-btn">+ Add</button>
        </div>
        <div id="lcrm-dd-form-wrap" class="lcrm-inline-form" style="display:none;">
            <input type="hidden" id="ldd-id" value="">
            <input type="hidden" id="ldd-dtype" value="<?php echo esc_attr($dtype); ?>">
            <input type="text" id="ldd-name" class="lcrm-input" placeholder="Value" style="width:280px">
            <button class="button button-primary lcrm-btn" id="ldd-save-btn">Save</button>
            <button class="button lcrm-btn" id="ldd-cancel-btn">Cancel</button>
            <span id="ldd-msg" class="lcrm-form-msg"></span>
        </div>
        <ul class="lcrm-simple-list" id="lcrm-dd-list">
            <?php foreach ($items as $it): ?>
            <li id="ddrow-<?php echo $it->id; ?>" style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <span style="flex:1;"><?php echo esc_html($it->name); ?></span>
                <button class="button lcrm-btn lcrm-btn-sm lcrm-dd-edit" data-id="<?php echo $it->id; ?>" data-name="<?php echo esc_attr($it->name); ?>" data-dtype="<?php echo esc_attr($dtype); ?>">Edit</button>
                <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-dd-del" data-id="<?php echo $it->id; ?>" data-dtype="<?php echo esc_attr($dtype); ?>">Delete</button>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function lcrm_settings_automation() {
    $s = lcrm_get_automation_settings();
    ?>
    <div class="lcrm-card">
        <h3>Automation Rules Configuration</h3>
        <p style="color:#64748b;font-size:13px;margin-bottom:16px;">The automation engine runs every hour and generates smart alerts based on these rules.</p>
        <div class="lcrm-form-grid">
            <div class="lcrm-form-group">
                <label>Vendor No-Response Days <small style="color:#94a3b8;">(Rule 5)</small></label>
                <input type="number" id="lcrm-no-vendor-days" class="lcrm-input lcrm-input-full" value="<?php echo intval($s['no_vendor_response_days']); ?>" min="1" max="30">
                <p style="font-size:12px;color:#94a3b8;">Alert when vendor hasn't responded in this many days.</p>
            </div>
            <div class="lcrm-form-group">
                <label>No Action Days <small style="color:#94a3b8;">(Rule 6)</small></label>
                <input type="number" id="lcrm-no-action-days" class="lcrm-input lcrm-input-full" value="<?php echo intval($s['no_action_days']); ?>" min="1" max="30">
                <p style="font-size:12px;color:#94a3b8;">Alert when lead has no activity for this many days.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Email Alerts</label>
                <select id="lcrm-email-alerts" class="lcrm-input lcrm-input-full">
                    <option value="1" <?php selected($s['email_alerts'],1); ?>>Enabled</option>
                    <option value="0" <?php selected($s['email_alerts'],0); ?>>Disabled</option>
                </select>
                <p style="font-size:12px;color:#94a3b8;">Send high priority alerts via email.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Alert Email</label>
                <input type="email" id="lcrm-alert-email" class="lcrm-input lcrm-input-full" value="<?php echo esc_attr($s['admin_email']); ?>">
            </div>
        </div>
        <div class="lcrm-form-actions">
            <button class="button button-primary lcrm-btn" id="lcrm-save-automation-btn">Save Settings</button>
            <button class="button lcrm-btn" id="lcrm-run-automation-btn" style="margin-left:8px;">&#9881; Run Automation Now</button>
            <span id="lcrm-auto-msg" class="lcrm-form-msg"></span>
        </div>
        <div style="margin-top:24px;padding:16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
            <h4 style="margin:0 0 12px;font-size:14px;">Active Automation Rules</h4>
            <div style="display:grid;gap:8px;">
                <div class="lcrm-rule-row lcrm-rule-red"><span class="lcrm-rule-icon">&#128308;</span><strong>Rule 1:</strong> Payment received but work not started  HIGH alert</div>
                <div class="lcrm-rule-row lcrm-rule-red"><span class="lcrm-rule-icon">&#128308;</span><strong>Rule 2:</strong> Quotation stage but no quote amount  HIGH alert</div>
                <div class="lcrm-rule-row lcrm-rule-amber"><span class="lcrm-rule-icon">&#128993;</span><strong>Rule 3:</strong> Client follow-up date reached  MEDIUM alert</div>
                <div class="lcrm-rule-row lcrm-rule-amber"><span class="lcrm-rule-icon">&#128993;</span><strong>Rule 4:</strong> Vendor follow-up date reached  MEDIUM alert + CallMe Bot</div>
                <div class="lcrm-rule-row lcrm-rule-red"><span class="lcrm-rule-icon">&#128308;</span><strong>Rule 5:</strong> Vendor not responded in <?php echo intval($s['no_vendor_response_days']); ?> days  HIGH alert + CallMe Bot escalation</div>
                <div class="lcrm-rule-row lcrm-rule-amber"><span class="lcrm-rule-icon">&#128993;</span><strong>Rule 6:</strong> No lead activity in <?php echo intval($s['no_action_days']); ?> days  MEDIUM alert</div>
            </div>
        </div>
    </div>

    <!-- CallMe Bot Configuration (v2.1.2) -->
    <div class="lcrm-card" style="margin-top:24px;">
        <h3>&#128172; CallMe Bot — WhatsApp Automation</h3>
        <p style="color:#64748b;font-size:13px;margin-bottom:16px;">
            Connect CallMe Bot to automatically send WhatsApp alerts to vendors, admin, and staff.
            Get your free API key at <a href="https://www.callmebot.com/" target="_blank" rel="noopener">callmebot.com</a>.
        </p>
        <div class="lcrm-form-grid">
            <div class="lcrm-form-group">
                <label>Enable CallMe Bot</label>
                <select id="lcrm-callme-enabled" class="lcrm-input lcrm-input-full">
                    <option value="1" <?php selected($s['callme_enabled'],1); ?>>Enabled</option>
                    <option value="0" <?php selected($s['callme_enabled'],0); ?>>Disabled</option>
                </select>
            </div>
            <div class="lcrm-form-group">
                <label>CallMe Bot API Key</label>
                <input type="text" id="lcrm-callme-api-key" class="lcrm-input lcrm-input-full"
                       value="<?php echo esc_attr($s['callme_api_key']); ?>"
                       placeholder="Your CallMe Bot API Key">
            </div>
            <div class="lcrm-form-group">
                <label>Admin WhatsApp Number</label>
                <input type="text" id="lcrm-callme-admin-number" class="lcrm-input lcrm-input-full"
                       value="<?php echo esc_attr($s['callme_admin_number']); ?>"
                       placeholder="e.g. 919876543210 (with country code)">
                <p style="font-size:12px;color:#94a3b8;">Receives escalation &amp; work-complete alerts.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Staff WhatsApp Numbers</label>
                <input type="text" id="lcrm-callme-staff-numbers" class="lcrm-input lcrm-input-full"
                       value="<?php echo esc_attr($s['callme_staff_numbers']); ?>"
                       placeholder="e.g. 919876543210,919876500001 (comma-separated)">
                <p style="font-size:12px;color:#94a3b8;">Receive vendor follow-up reminders.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Vendor Escalation Threshold (hours)</label>
                <input type="number" id="lcrm-vendor-escalation-hours" class="lcrm-input lcrm-input-full"
                       value="<?php echo intval($s['vendor_escalation_hours']); ?>" min="1" max="168">
                <p style="font-size:12px;color:#94a3b8;">Auto-escalate to admin after this many hours of vendor no-response.</p>
            </div>
        </div>
        <div style="margin-top:16px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#166534;">
            <strong>&#128161; What CallMe Bot Does:</strong>
            <ul style="margin:8px 0 0 16px;padding:0;">
                <li>Vendor assigned → WhatsApp sent to vendor + team alert</li>
                <li>Vendor follow-up due → WhatsApp reminder to vendor + staff alert</li>
                <li>Vendor no response → Escalation to admin, vendor marked <em>Delayed</em></li>
                <li>Work completed → Admin notified via WhatsApp</li>
                <li>Payment released → Vendor notified via WhatsApp</li>
            </ul>
        </div>
    </div>
    <?php
}

/* =========================================================
   IMPORT / EXPORT
   ========================================================= */
function lcrm_page_import() {
    lcrm_header('Import / Export');
    ?>
    <div class="lcrm-two-col">
        <div class="lcrm-card">
            <h3>Import Leads from CSV</h3>
            <p style="font-size:13px;color:#64748b;margin:10px 0 16px;">CSV must have columns: <code>date, client name, phone, email, work type, lead source, remarks, priority</code></p>
            <form id="lcrm-import-form" enctype="multipart/form-data">
                <input type="file" id="lcrm-csv-file" name="csv_file" accept=".csv" class="lcrm-input" style="margin-bottom:12px;">
                <button type="submit" class="button button-primary lcrm-btn">Import CSV</button>
                <span id="lcrm-import-msg" class="lcrm-form-msg"></span>
            </form>
            <div id="lcrm-import-errors" style="margin-top:12px;font-size:12px;color:#ef4444;"></div>
        </div>
        <div class="lcrm-card">
            <h3>Export Leads to CSV</h3>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <div><label style="font-size:13px;display:block;margin-bottom:4px;">Stage filter</label>
                    <select id="lcrm-exp-stage" class="lcrm-input" style="width:100%;">
                        <option value="">All Stages</option>
                        <?php foreach (lcrm_get_stages() as $s) echo '<option value="' . esc_attr($s->id) . '">' . esc_html($s->name) . '</option>'; ?>
                    </select>
                </div>
                <div><label style="font-size:13px;display:block;margin-bottom:4px;">Date range</label>
                    <div style="display:flex;gap:8px;">
                        <input type="date" id="lcrm-exp-from" class="lcrm-input" placeholder="From">
                        <input type="date" id="lcrm-exp-to" class="lcrm-input" placeholder="To">
                    </div>
                </div>
                <a id="lcrm-export-link" href="#" class="button button-primary lcrm-btn" style="text-align:center;text-decoration:none;">&#8595; Download CSV</a>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('lcrm-export-link').addEventListener('click',function(e){
        e.preventDefault();
        window.location=LCRM.ajax_url+'?action=lcrm_export_csv&nonce='+LCRM.nonce
            +'&stage_id='+document.getElementById('lcrm-exp-stage').value
            +'&date_from='+document.getElementById('lcrm-exp-from').value
            +'&date_to='+document.getElementById('lcrm-exp-to').value;
    });
    </script>
    <?php
    lcrm_footer();
}

/* =========================================================
   CUSTOM FIELDS PAGE
   ========================================================= */
function lcrm_page_custom_fields() {
    global $wpdb;
    $tabs_map = ['basic'=>'Basic Info','follow'=>'Follow-up','financial'=>'Financial','vendor'=>'Vendor','remarks'=>'Remarks'];
    $field_types = ['text'=>'Text','textarea'=>'Textarea','number'=>'Number','date'=>'Date','select'=>'Dropdown','checkbox'=>'Checkbox'];
    $fields = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lcrm_fields ORDER BY sort_order ASC, id ASC");
    lcrm_header('Custom Fields');
    ?>
    <div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;">

      <!-- Add / Edit Form -->
      <div class="lcrm-card">
        <h3 id="lcrm-field-form-title" style="margin:0 0 16px;font-size:15px;font-weight:700;">Add New Field</h3>
        <input type="hidden" id="lf-field-id" value="">

        <div class="lcrm-form-group">
          <label class="lcrm-label">Field Label *</label>
          <input type="text" id="lf-field-label" class="lcrm-input lcrm-input-full" placeholder="e.g. Passport Number">
        </div>
        <div class="lcrm-form-group">
          <label class="lcrm-label">Field Key (auto-generated)</label>
          <input type="text" id="lf-field-key" class="lcrm-input lcrm-input-full" placeholder="e.g. cf_passport_number" style="font-family:monospace;font-size:12px;">
          <span style="font-size:11px;color:#94a3b8;">Leave blank to auto-generate from label</span>
        </div>
        <div class="lcrm-form-group">
          <label class="lcrm-label">Field Type</label>
          <select id="lf-field-type" class="lcrm-input lcrm-input-full">
            <?php foreach($field_types as $k=>$v): ?>
            <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="lcrm-form-group" id="lf-opts-wrap" style="display:none;">
          <label class="lcrm-label">Options (comma-separated)</label>
          <input type="text" id="lf-field-opts" class="lcrm-input lcrm-input-full" placeholder="Option A, Option B, Option C">
        </div>
        <div class="lcrm-form-group">
          <label class="lcrm-label">Show in Tab / Section</label>
          <select id="lf-field-tab" class="lcrm-input lcrm-input-full">
            <?php foreach($tabs_map as $k=>$v): ?>
            <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="lcrm-form-group">
          <label class="lcrm-label">Position / Order within Section</label>
          <input type="number" id="lf-field-sort-order" class="lcrm-input lcrm-input-full" placeholder="e.g. 10, 20, 30 — lower = earlier" min="0" step="10">
          <span style="font-size:11px;color:#546e7a;margin-top:3px;display:block;">Leave blank to auto-place at end. Use multiples of 10 for easy reordering later.</span>
        </div>
        <div class="lcrm-form-group" style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600;">
            <input type="checkbox" id="lf-field-required"> Required Field
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600;" title="Show this field as a column in the All Leads table view">
            <input type="checkbox" id="lf-field-list"> &#9776; Show as Table Column
          </label>
        </div>
        <div style="display:flex;gap:10px;margin-top:4px;">
          <button id="lcrm-save-field-btn" class="button button-primary lcrm-btn">Save Field</button>
          <button id="lcrm-cancel-field-btn" class="button lcrm-btn" style="display:none;">Cancel</button>
        </div>
        <div id="lcrm-field-msg" class="lcrm-form-msg" style="margin-top:10px;"></div>
      </div>

      <!-- Fields List with Drag-and-Drop -->
      <div class="lcrm-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
          <h3 style="margin:0;font-size:15px;font-weight:700;">Form Fields (<?php echo count($fields); ?>)</h3>
          <span style="font-size:12px;color:#94a3b8;">&#9776; Drag rows to reorder</span>
        </div>
        <?php if(empty($fields)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;">No custom fields yet. Add your first field on the left.</div>
        <?php else: ?>
        <div class="lcrm-table-wrap">
        <table class="lcrm-table">
          <thead><tr>
            <th style="width:32px;"></th>
            <th>Label</th>
            <th>Key</th>
            <th>Type</th>
            <th>Tab</th>
            <th>Req.</th>
            <th>Active</th>
            <th>Actions</th>
          </tr></thead>
          <tbody id="lcrm-fields-tbody">
          <?php foreach($fields as $f): ?>
          <tr data-field-id="<?php echo intval($f->id); ?>" style="<?php echo $f->is_active?'':'opacity:.5;'; ?>">
            <td><span class="lcrm-drag-handle" title="Drag to reorder">&#9776;</span></td>
            <td><strong><?php echo esc_html($f->field_label); ?></strong><?php echo $f->is_required?' <span style="color:#ef4444;">*</span>':''; ?></td>
            <td><code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;"><?php echo esc_html($f->field_key); ?></code></td>
            <td><?php echo esc_html($field_types[$f->field_type]??$f->field_type); ?></td>
            <td><span class="lcrm-badge"><?php echo esc_html($tabs_map[$f->tab_name??'basic']??$f->tab_name); ?></span></td>
            <td><?php echo $f->is_required?'<span style="color:#10b981;font-weight:600;">Yes</span>':'No'; ?></td>
            <td>
              <label class="lcrm-toggle-wrap">
                <input type="checkbox" class="lcrm-field-toggle" data-id="<?php echo $f->id; ?>" <?php checked($f->is_active,1); ?>>
                <span class="lcrm-toggle-slider"></span>
              </label>
            </td>
            <td style="white-space:nowrap;">
              <button class="button lcrm-btn lcrm-btn-sm lcrm-edit-field-btn"
                data-id="<?php echo intval($f->id); ?>"
                data-label="<?php echo esc_attr($f->field_label); ?>"
                data-key="<?php echo esc_attr($f->field_key); ?>"
                data-type="<?php echo esc_attr($f->field_type); ?>"
                data-opts="<?php echo esc_attr($f->field_opts); ?>"
                data-tab="<?php echo esc_attr($f->tab_name??'basic'); ?>"
                data-sortorder="<?php echo intval($f->sort_order); ?>"
                data-required="<?php echo intval($f->is_required); ?>"
                data-list="<?php echo intval($f->show_in_list); ?>">Edit</button>
              <button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-del-field-btn" data-id="<?php echo intval($f->id); ?>">Del</button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- FULL FORM LAYOUT EDITOR — built-in + custom fields -->
    <div class="lcrm-card" style="margin-top:24px;" id="lcrm-layout-editor-wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div>
          <h3 style="margin:0 0 4px;font-size:15px;font-weight:700;">&#9635; Form Layout Editor</h3>
          <p style="margin:0;font-size:12px;color:#64748b;">Drag any field — built-in or custom — between tabs or reorder within a tab. Click <strong>Save Layout</strong> when done.</p>
        </div>
        <button class="button lcrm-btn lcrm-btn-sm" id="lcrm-layout-editor-toggle">Show Layout Editor</button>
      </div>
      <div id="lcrm-layout-editor" style="display:none;margin-top:16px;">
        <?php
        $tab_labels = ['basic'=>'Basic Info','follow'=>'Follow-up','financial'=>'Financial','vendor'=>'Vendor','remarks'=>'Remarks'];
        // Get all fields merged with layout overrides
        $all_layout_fields = lcrm_build_form_fields_by_tab();
        // Special-section labels (not moveable — shown as anchored badges)
        $anchored = [
            'follow'   => ['&#9200; Reminder Section (anchored)'],
            'vendor'   => ['&#128172; WA Messaging Section (anchored)'],
            'remarks'  => ['&#128203; Activity Log &amp; Notes (anchored)'],
        ];
        ?>
        <div class="lcrm-layout-grid">
          <?php foreach ($tab_labels as $tab_key => $tab_label): ?>
          <div class="lcrm-layout-col">
            <div class="lcrm-layout-col-header"><?php echo esc_html($tab_label); ?></div>
            <ul class="lcrm-layout-list" data-tab="<?php echo esc_attr($tab_key); ?>">
              <?php
              $col_fields = isset($all_layout_fields[$tab_key]) ? $all_layout_fields[$tab_key] : [];
              if (!empty($col_fields)):
                  foreach ($col_fields as $lf):
                      $source_badge = $lf['source']==='builtin'
                          ? '<span style="font-size:9px;background:#e0e7ff;color:#4338ca;border-radius:3px;padding:1px 4px;margin-left:4px;">built-in</span>'
                          : '<span style="font-size:9px;background:#dcfce7;color:#15803d;border-radius:3px;padding:1px 4px;margin-left:4px;">custom</span>';
                      $type_short = str_replace(['_select','_sel','_ta','cf_','priority','temp','action','vendor','brand','stage','src','wt','money','calc','remarks'],['','','','','pri','temp','act','vnd','brd','stg','src','wt','₹','=','rmk'], $lf['type']);
              ?>
              <li class="lcrm-layout-item" data-field-key="<?php echo esc_attr($lf['key']); ?>">
                <span class="lcrm-drag-handle" title="Drag to move">&#9776;</span>
                <span class="lcrm-layout-label"><?php echo esc_html($lf['label']); ?></span>
                <?php echo $source_badge; ?>
              </li>
              <?php endforeach; ?>
              <?php endif; ?>
              <?php if (isset($anchored[$tab_key])): foreach ($anchored[$tab_key] as $a): ?>
              <li style="padding:6px 8px;color:#94a3b8;font-size:11px;background:#f8fafc;border:1px dashed #e2e8f0;border-radius:4px;list-style:none;margin-top:4px;cursor:default;"><?php echo $a; ?></li>
              <?php endforeach; endif; ?>
              <?php if (empty($col_fields) && !isset($anchored[$tab_key])): ?>
              <li class="lcrm-layout-drop-hint" style="padding:10px;color:#94a3b8;font-size:12px;text-align:center;list-style:none;border:2px dashed #e2e8f0;border-radius:6px;">Drop fields here</li>
              <?php endif; ?>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
          <button class="button button-primary lcrm-btn" id="lcrm-layout-save-btn">&#128190; Save Layout</button>
          <button class="button lcrm-btn" id="lcrm-layout-reset-btn">&#8635; Reset to Defaults</button>
          <span id="lcrm-layout-save-msg" style="font-size:13px;color:#10b981;display:none;">&#10003; Layout saved!</span>
          <span id="lcrm-layout-err-msg"  style="font-size:13px;color:#ef4444;display:none;"></span>
        </div>
      </div>
    </div>

    <?php
    lcrm_footer();
}

/* =========================================================
   RENDER CUSTOM FIELDS PER TAB (called inside lead form)
   ========================================================= */
function lcrm_render_custom_fields_for_tab($tab) {
    $fields = lcrm_get_active_fields($tab);
    if (empty($fields)) return;
    echo '<div class="lcrm-form-grid" style="margin-top:10px;border-top:1px dashed #e2e8f0;padding-top:12px;">';
    foreach ($fields as $f) {
        $key   = esc_attr($f->field_key);
        $label = esc_html($f->field_label) . ($f->is_required ? ' <span style="color:#ef4444;">*</span>' : '');
        $req   = $f->is_required ? 'required' : '';
        echo '<div class="lcrm-form-group">';
        echo '<label>' . $label . '</label>';
        switch ($f->field_type) {
            case 'textarea':
                echo '<textarea name="' . $key . '" id="lf-' . $key . '" class="lcrm-input lcrm-input-full lcrm-textarea" rows="2" ' . $req . '></textarea>';
                break;
            case 'select':
                $opts = array_filter(array_map('trim', explode(',', $f->field_opts)));
                echo '<select name="' . $key . '" id="lf-' . $key . '" class="lcrm-input lcrm-input-full" ' . $req . '>';
                echo '<option value="">— Select —</option>';
                foreach ($opts as $opt) echo '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
                echo '</select>';
                break;
            case 'checkbox':
                echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">';
                echo '<input type="checkbox" name="' . $key . '" id="lf-' . $key . '" value="1"> Yes</label>';
                break;
            case 'number':
                echo '<input type="number" name="' . $key . '" id="lf-' . $key . '" class="lcrm-input lcrm-input-full" ' . $req . '>';
                break;
            case 'date':
                echo '<input type="date" name="' . $key . '" id="lf-' . $key . '" class="lcrm-input lcrm-input-full" ' . $req . '>';
                break;
            default: // text
                echo '<input type="text" name="' . $key . '" id="lf-' . $key . '" class="lcrm-input lcrm-input-full" ' . $req . '>';
                break;
        }
        echo '</div>';
    }
    echo '</div>';
}

/* =========================================================
   V5 SETTINGS TABS — added to existing settings router
   ========================================================= */

/* V5 settings tabs and dispatch wired directly into lcrm_page_settings above */

/* =========================================================
   ANALYTICS PAGE
   ========================================================= */
function lcrm_page_analytics() {
    lcrm_header( 'Analytics & Pipeline Intelligence' );
    $stats = lcrm_analytics_today_stats();
    ?>
    <style>
    .lcrm-an-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px}
    .lcrm-an-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
    .lcrm-an-val{font-size:24px;font-weight:800;color:#1e293b;margin-bottom:2px}
    .lcrm-an-lbl{font-size:12px;color:#6b7280;font-weight:500}
    .lcrm-an-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
    .lcrm-an-tab{padding:7px 14px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:13px;font-weight:600;cursor:pointer;color:#374151;transition:.15s}
    .lcrm-an-tab.active,.lcrm-an-tab:hover{background:#6366f1;color:#fff;border-color:#6366f1}
    .lcrm-an-panel{display:none}.lcrm-an-panel.active{display:block}
    .lcrm-an-chart-wrap{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.07);min-height:300px}
    .lcrm-an-table{width:100%;border-collapse:collapse;font-size:13px}
    .lcrm-an-table th{background:#f8fafc;padding:10px 12px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0}
    .lcrm-an-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#374151}
    .lcrm-an-table tr:hover td{background:#f8fafc}
    .lcrm-win-bar{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-top:4px;width:100px;display:inline-block}
    .lcrm-win-fill{height:6px;background:#6366f1;border-radius:3px}
    .lcrm-forecast-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}
    .lcrm-fc-card{border-radius:12px;padding:20px;color:#fff}
    .lcrm-fc-val{font-size:28px;font-weight:800;margin-bottom:4px}
    .lcrm-fc-lbl{font-size:12px;opacity:.85}
    </style>

    <!-- KPI Row -->
    <div class="lcrm-an-kpi">
        <div class="lcrm-an-card"><div class="lcrm-an-val"><?php echo intval($stats['new_today']); ?></div><div class="lcrm-an-lbl">New Today</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val"><?php echo intval($stats['new_week']); ?></div><div class="lcrm-an-lbl">New This Week</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val" style="color:#ef4444;"><?php echo intval($stats['hot_leads']); ?></div><div class="lcrm-an-lbl">🔥 Hot Leads</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val" style="color:#f59e0b;"><?php echo intval($stats['followup_due']); ?></div><div class="lcrm-an-lbl">Follow-up Due</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val" style="color:#6366f1;">₹<?php echo number_format($stats['revenue_mtd'], 0); ?></div><div class="lcrm-an-lbl">Revenue MTD</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val" style="color:#10b981;">₹<?php echo number_format($stats['pending_revenue'], 0); ?></div><div class="lcrm-an-lbl">Pending Balance</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val"><?php echo intval($stats['unassigned']); ?></div><div class="lcrm-an-lbl">Unassigned Leads</div></div>
        <div class="lcrm-an-card"><div class="lcrm-an-val" style="color:#ef4444;"><?php echo intval($stats['unread_alerts']); ?></div><div class="lcrm-an-lbl">Unread Alerts</div></div>
    </div>

    <!-- Tabs -->
    <div class="lcrm-an-tabs">
        <button class="lcrm-an-tab active" data-panel="funnel">Funnel</button>
        <button class="lcrm-an-tab" data-panel="forecast">Revenue Forecast</button>
        <button class="lcrm-an-tab" data-panel="agents">Agent Performance</button>
        <button class="lcrm-an-tab" data-panel="sources">Source ROI</button>
        <button class="lcrm-an-tab" data-panel="velocity">Stage Velocity</button>
        <button class="lcrm-an-tab" data-panel="winloss">AI Win/Loss</button>
    </div>

    <!-- Funnel Panel -->
    <div class="lcrm-an-panel active" id="an-funnel">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">Pipeline Funnel</h3>
            <div id="an-funnel-content"><p style="color:#9ca3af;text-align:center;padding:40px;">Loading…</p></div>
        </div>
    </div>

    <!-- Forecast Panel -->
    <div class="lcrm-an-panel" id="an-forecast">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">Revenue Forecast</h3>
            <div id="an-forecast-content"><p style="color:#9ca3af;text-align:center;padding:40px;">Loading…</p></div>
        </div>
    </div>

    <!-- Agents Panel -->
    <div class="lcrm-an-panel" id="an-agents">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">Agent Performance
                <select id="an-agents-days" style="font-size:12px;margin-left:10px;padding:3px 6px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option><option value="90">Last 90 days</option>
                </select>
            </h3>
            <div id="an-agents-content"><p style="color:#9ca3af;text-align:center;padding:40px;">Loading…</p></div>
        </div>
    </div>

    <!-- Sources Panel -->
    <div class="lcrm-an-panel" id="an-sources">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">Source ROI
                <select id="an-sources-days" style="font-size:12px;margin-left:10px;padding:3px 6px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="30">Last 30 days</option><option value="90" selected>Last 90 days</option><option value="365">Last year</option>
                </select>
            </h3>
            <div id="an-sources-content"><p style="color:#9ca3af;text-align:center;padding:40px;">Loading…</p></div>
        </div>
    </div>

    <!-- Velocity Panel -->
    <div class="lcrm-an-panel" id="an-velocity">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">Stage Velocity — Average Days in Each Stage</h3>
            <div id="an-velocity-content"><p style="color:#9ca3af;text-align:center;padding:40px;">Loading…</p></div>
        </div>
    </div>

    <!-- Win/Loss AI Panel -->
    <div class="lcrm-an-panel" id="an-winloss">
        <div class="lcrm-an-chart-wrap">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;">AI Win/Loss Pattern Analysis</h3>
            <div id="an-winloss-content" style="padding:20px 0;">
                <button class="button button-primary lcrm-btn" id="an-winloss-run" style="background:#6366f1;border-color:#6366f1;">&#129302; Run AI Analysis</button>
                <div id="an-winloss-result" style="margin-top:16px;font-size:14px;line-height:1.7;color:#374151;display:none;white-space:pre-line;background:#f8fafc;border-radius:8px;padding:16px;"></div>
            </div>
        </div>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        var loadedPanels = {};

        function req(action, data, cb) {
            var fd = new FormData();
            fd.append('action', action); fd.append('nonce', NONCE);
            if (data) Object.keys(data).forEach(k => fd.append(k, data[k]));
            fetch(AJAX, {method:'POST', body:fd}).then(r=>r.json()).then(cb);
        }

        // Tab switching
        $('.lcrm-an-tab').on('click', function() {
            $('.lcrm-an-tab').removeClass('active');
            $('.lcrm-an-panel').removeClass('active');
            $(this).addClass('active');
            var panel = $(this).data('panel');
            $('#an-' + panel).addClass('active');
            if (!loadedPanels[panel]) { loadPanel(panel); loadedPanels[panel]=true; }
        });

        function loadPanel(panel) {
            switch(panel) {
                case 'funnel':   loadFunnel(); break;
                case 'forecast': loadForecast(); break;
                case 'agents':   loadAgents(); break;
                case 'sources':  loadSources(); break;
                case 'velocity': loadVelocity(); break;
            }
        }

        // Load funnel on page load
        loadFunnel(); loadedPanels['funnel'] = true;

        function loadFunnel() {
            req('lcrm_analytics_get_funnel', {}, function(res) {
                if (!res.success) return;
                var funnel = res.data.funnel;
                if (!funnel.length) { $('#an-funnel-content').html('<p style="color:#9ca3af;text-align:center;padding:40px;">No leads yet. Start adding leads to see your pipeline.</p>'); return; }
                var maxCount = Math.max.apply(null, funnel.map(f=>f.count)) || 1;
                var html = '<div style="overflow-x:auto;"><table class="lcrm-an-table"><thead><tr><th>Stage</th><th>Leads</th><th>% of Total</th><th>Pipeline Value</th><th>Bar</th></tr></thead><tbody>';
                funnel.forEach(function(s) {
                    var pct = Math.round(s.count / maxCount * 100);
                    html += '<tr>'
                        + '<td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + s.color + ';margin-right:6px;"></span>' + s.name + '</td>'
                        + '<td><strong>' + s.count + '</strong></td>'
                        + '<td>' + s.pct_of_total + '%</td>'
                        + '<td>₹' + Number(s.value).toLocaleString('en-IN') + '</td>'
                        + '<td><div class="lcrm-win-bar"><div class="lcrm-win-fill" style="width:' + pct + '%;background:' + s.color + ';"></div></div></td>'
                        + '</tr>';
                });
                html += '</tbody></table></div>';
                $('#an-funnel-content').html(html);
            });
        }

        function loadForecast() {
            req('lcrm_analytics_get_forecast', {}, function(res) {
                if (!res.success) return;
                var d = res.data;
                var html = '<div class="lcrm-forecast-grid">'
                    + '<div class="lcrm-fc-card" style="background:linear-gradient(135deg,#10b981,#059669);"><div class="lcrm-fc-val">₹' + Number(d.confirmed).toLocaleString('en-IN') + '</div><div class="lcrm-fc-lbl">Confirmed Revenue</div></div>'
                    + '<div class="lcrm-fc-card" style="background:linear-gradient(135deg,#6366f1,#4f46e5);"><div class="lcrm-fc-val">₹' + Number(d.probable).toLocaleString('en-IN') + '</div><div class="lcrm-fc-lbl">Probable (Late Stage)</div></div>'
                    + '<div class="lcrm-fc-card" style="background:linear-gradient(135deg,#f59e0b,#d97706);"><div class="lcrm-fc-val">₹' + Number(d.possible).toLocaleString('en-IN') + '</div><div class="lcrm-fc-lbl">Possible (Quoted)</div></div>'
                    + '<div class="lcrm-fc-card" style="background:linear-gradient(135deg,#ef4444,#dc2626);"><div class="lcrm-fc-val">₹' + Number(d.pending).toLocaleString('en-IN') + '</div><div class="lcrm-fc-lbl">Pending Balance Dues</div></div>'
                    + '</div>';
                // Monthly trend table
                if (d.monthly_trend && d.monthly_trend.length) {
                    html += '<h4 style="font-size:13px;font-weight:700;margin:16px 0 8px;">Monthly Revenue Trend</h4>'
                        + '<table class="lcrm-an-table"><thead><tr><th>Month</th><th>Revenue</th><th>Deals</th></tr></thead><tbody>';
                    d.monthly_trend.forEach(function(m) {
                        html += '<tr><td>' + m.month + '</td><td>₹' + Number(m.revenue).toLocaleString('en-IN') + '</td><td>' + m.deals + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }
                $('#an-forecast-content').html(html);
            });
        }

        function loadAgents(days) {
            days = days || 30;
            req('lcrm_analytics_get_agents', {days:days}, function(res) {
                if (!res.success) return;
                var agents = res.data.agents;
                if (!agents.length) { $('#an-agents-content').html('<p style="color:#9ca3af;padding:20px;">No assigned leads found.</p>'); return; }
                var html = '<table class="lcrm-an-table"><thead><tr><th>Agent</th><th>Total</th><th>🔥 Hot</th><th>Won</th><th>Revenue</th><th>Win Rate</th></tr></thead><tbody>';
                agents.forEach(function(a) {
                    html += '<tr><td><strong>' + a.agent_name + '</strong></td>'
                        + '<td>' + a.total_leads + '</td>'
                        + '<td>' + a.hot_leads + '</td>'
                        + '<td>' + a.won_leads + '</td>'
                        + '<td>₹' + Number(a.revenue).toLocaleString('en-IN') + '</td>'
                        + '<td><div class="lcrm-win-bar"><div class="lcrm-win-fill" style="width:' + a.win_rate + '%;"></div></div> ' + a.win_rate + '%</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#an-agents-content').html(html);
            });
        }

        function loadSources(days) {
            days = days || 90;
            req('lcrm_analytics_get_sources', {days:days}, function(res) {
                if (!res.success) return;
                var sources = res.data.sources;
                if (!sources.length) { $('#an-sources-content').html('<p style="color:#9ca3af;padding:20px;">No source data found.</p>'); return; }
                var html = '<table class="lcrm-an-table"><thead><tr><th>Source</th><th>Leads</th><th>Conversions</th><th>Conv. Rate</th><th>Revenue</th><th>Avg Deal</th></tr></thead><tbody>';
                sources.forEach(function(s) {
                    html += '<tr><td><strong>' + s.source + '</strong></td>'
                        + '<td>' + s.total_leads + '</td>'
                        + '<td>' + s.conversions + '</td>'
                        + '<td>' + s.conversion_rate + '%</td>'
                        + '<td>₹' + Number(s.revenue).toLocaleString('en-IN') + '</td>'
                        + '<td>₹' + Number(s.avg_deal_size).toLocaleString('en-IN') + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                $('#an-sources-content').html(html);
            });
        }

        function loadVelocity() {
            req('lcrm_analytics_get_velocity', {}, function(res) {
                if (!res.success) return;
                var stages = res.data.velocity;
                if (!stages.length) { $('#an-velocity-content').html('<p style="color:#9ca3af;padding:20px;">Not enough stage transition data yet.</p>'); return; }
                var html = '<table class="lcrm-an-table"><thead><tr><th>Stage</th><th>Avg Days</th><th>Median Days</th><th>Leads Through</th></tr></thead><tbody>';
                stages.forEach(function(s) {
                    html += '<tr><td>' + s.stage + '</td><td>' + s.avg_days + '</td><td>' + s.median_days + '</td><td>' + s.leads_through + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#an-velocity-content').html(html);
            });
        }

        // Days selectors
        $('#an-agents-days').on('change', function() { $('#an-agents-content').html('<p style="color:#9ca3af;padding:20px;">Loading…</p>'); loadAgents($(this).val()); });
        $('#an-sources-days').on('change', function() { $('#an-sources-content').html('<p style="color:#9ca3af;padding:20px;">Loading…</p>'); loadSources($(this).val()); });

        // Win/Loss AI
        $('#an-winloss-run').on('click', function() {
            var $btn = $(this);
            $btn.text('Analysing…').prop('disabled', true);
            req('lcrm_ai_win_loss', {}, function(res) {
                $btn.text('🤖 Run AI Analysis').prop('disabled', false);
                if (res.success) {
                    $('#an-winloss-result').text(res.data.analysis).show();
                }
            });
        });

    })(jQuery);
    </script>
    <?php
    lcrm_footer();
}

/* =========================================================
   PIPELINE KANBAN PAGE
   ========================================================= */
function lcrm_page_pipeline() {
    $stages = lcrm_get_stages();
    lcrm_header( 'Pipeline — Visual Kanban Board',
        '<button class="button lcrm-btn" id="kanban-refresh-btn">&#8635; Refresh</button>'
    );
    ?>
    <style>
    .kanban-wrap{display:flex;gap:12px;overflow-x:auto;padding-bottom:20px;align-items:flex-start}
    .kanban-col{flex-shrink:0;width:220px;background:#f8fafc;border-radius:12px;padding:10px}
    .kanban-col-hdr{padding:8px 10px;border-radius:8px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
    .kanban-col-name{font-size:12px;font-weight:700;color:#fff}
    .kanban-col-count{font-size:11px;background:rgba(255,255,255,.3);border-radius:20px;padding:2px 7px;color:#fff;font-weight:700}
    .kanban-card{background:#fff;border-radius:8px;padding:10px;margin-bottom:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;border-left:3px solid transparent;transition:.15s}
    .kanban-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.12);transform:translateY(-1px)}
    .kanban-name{font-size:13px;font-weight:600;color:#1e293b;margin-bottom:3px}
    .kanban-work{font-size:11px;color:#6b7280;margin-bottom:5px}
    .kanban-meta{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
    .kanban-badge{font-size:10px;padding:2px 6px;border-radius:20px;font-weight:600}
    .kanban-empty{text-align:center;color:#9ca3af;font-size:12px;padding:16px 8px}
    .kanban-loading{text-align:center;color:#9ca3af;font-size:12px;padding:30px}
    </style>

    <div class="kanban-wrap" id="kanban-board">
        <?php foreach ($stages as $s): ?>
        <div class="kanban-col" data-stage-id="<?php echo intval($s->id); ?>">
            <div class="kanban-col-hdr" style="background:<?php echo esc_attr($s->color); ?>">
                <span class="kanban-col-name"><?php echo esc_html($s->name); ?></span>
                <span class="kanban-col-count" id="kanban-count-<?php echo intval($s->id); ?>">…</span>
            </div>
            <div class="kanban-cards" id="kanban-cards-<?php echo intval($s->id); ?>">
                <div class="kanban-loading">Loading…</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        var STAGES=<?php echo wp_json_encode(array_map(fn($s)=>['id'=>intval($s->id),'name'=>$s->name,'color'=>$s->color], $stages)); ?>;

        function tempBadge(t){ var c={'hot':'#ef4444','warm':'#f59e0b','cold':'#3b82f6'}; return '<span class="kanban-badge" style="background:'+c[t]+';color:#fff;">'+t+'</span>'; }
        function priBadge(p){ var c={'high':'#ef4444','medium':'#f59e0b','low':'#10b981'}; return '<span class="kanban-badge" style="background:'+c[p]+'33;color:'+c[p]+';">'+p+'</span>'; }

        function loadStage(sid) {
            var fd=new FormData();
            fd.append('action','lcrm_ajax_get_leads');
            fd.append('nonce',NONCE);
            fd.append('stage_filter',sid);
            fd.append('per_page',20);
            fd.append('page',1);
            fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                if(!res.success) return;
                var leads=res.data.leads||[];
                var $cont=$('#kanban-cards-'+sid);
                $cont.empty();
                $('#kanban-count-'+sid).text(res.data.total||leads.length);
                if(!leads.length){$cont.append('<div class="kanban-empty">No leads</div>');return;}
                leads.forEach(function(l){
                    var val = l.total_quote>0 ? '₹'+Number(l.total_quote).toLocaleString('en-IN') : '';
                    var wa  = l.wa_unread_count>0 ? '<span class="kanban-badge" style="background:#25d366;color:#fff;">💬 '+l.wa_unread_count+'</span>' : '';
                    var card='<div class="kanban-card" data-id="'+l.id+'" style="border-left-color:'+l.stage_color+'" onclick="window.lcrmOpenLeadModal('+l.id+')">'
                        +'<div class="kanban-name">'+l.client_name+'</div>'
                        +'<div class="kanban-work">'+l.work_type+'</div>'
                        +'<div class="kanban-meta">'+tempBadge(l.lead_temperature)+' '+priBadge(l.priority)+(val?'<span class="kanban-badge" style="background:#f0fdf4;color:#166534;">'+val+'</span>':'')+wa+'</div>'
                        +'</div>';
                    $cont.append(card);
                });
            });
        }

        function loadAll(){ STAGES.forEach(function(s){ loadStage(s.id); }); }
        loadAll();
        $('#kanban-refresh-btn').on('click', loadAll);

    })(jQuery);
    </script>
    <?php
    lcrm_footer();
}

/* =========================================================
   TEAM INBOX PAGE
   ========================================================= */
function lcrm_page_team_inbox() {
    $users  = lcrm_get_crm_users();
    $stages = lcrm_get_stages();
    lcrm_header( 'Team Inbox' );
    ?>
    <style>
    .ti-wrap{display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:start}
    .ti-sidebar{background:#fff;border-radius:12px;padding:0;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden}
    .ti-agent-row{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:.15s}
    .ti-agent-row:hover,.ti-agent-row.active{background:#f5f3ff}
    .ti-agent-row.active{border-left:3px solid #6366f1}
    .ti-agent-name{font-size:13px;font-weight:600;color:#374151}
    .ti-agent-count{font-size:11px;color:#9ca3af}
    .ti-avatar{width:32px;height:32px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0}
    .ti-queue{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden}
    .ti-queue-hdr{padding:14px 16px;border-bottom:1px solid #f1f5f9;font-size:14px;font-weight:700;color:#1e293b;display:flex;align-items:center;justify-content:space-between}
    .ti-lead-row{display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:.15s}
    .ti-lead-row:hover{background:#f8fafc}
    .ti-lead-name{font-size:13px;font-weight:600;color:#1e293b}
    .ti-lead-work{font-size:12px;color:#6b7280;margin-top:2px}
    .ti-lead-stage{font-size:11px;color:#fff;border-radius:20px;padding:2px 8px;font-weight:600;margin-top:4px;display:inline-block}
    .ti-lead-meta{font-size:11px;color:#9ca3af;margin-top:3px}
    .ti-temp-hot{color:#ef4444;font-weight:700}
    .ti-temp-warm{color:#f59e0b;font-weight:700}
    .ti-temp-cold{color:#3b82f6;font-weight:700}
    .ti-empty{text-align:center;padding:40px;color:#9ca3af;font-size:13px}
    @media(max-width:768px){.ti-wrap{grid-template-columns:1fr}}
    </style>

    <div class="ti-wrap">
        <!-- Agent Sidebar -->
        <div class="ti-sidebar">
            <div style="padding:12px 14px;background:#6366f1;color:#fff;font-size:13px;font-weight:700;">👥 Team Queue</div>
            <div class="ti-agent-row active" data-agent="0">
                <div class="ti-avatar">?</div>
                <div><div class="ti-agent-name">Unassigned</div><div class="ti-agent-count">All unassigned leads</div></div>
            </div>
            <?php foreach ($users as $u): ?>
            <div class="ti-agent-row" data-agent="<?php echo intval($u->ID); ?>">
                <div class="ti-avatar"><?php echo strtoupper(substr($u->display_name, 0, 1)); ?></div>
                <div><div class="ti-agent-name"><?php echo esc_html($u->display_name); ?></div><div class="ti-agent-count" id="ti-count-<?php echo intval($u->ID); ?>">…</div></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Queue Content -->
        <div class="ti-queue">
            <div class="ti-queue-hdr">
                <span id="ti-queue-title">Unassigned Leads</span>
                <div style="display:flex;gap:8px;">
                    <button class="button lcrm-btn" id="ti-run-triage-btn">🤖 AI Triage</button>
                    <button class="button lcrm-btn" id="ti-refresh-btn">↻ Refresh</button>
                </div>
            </div>
            <div id="ti-queue-content"><div class="ti-empty">Loading…</div></div>
            <div id="ti-pagination" style="padding:10px 16px;"></div>
        </div>
    </div>

    <!-- Mentions badge -->
    <div id="ti-mentions-bar" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;color:#92400e;">
        <strong>🔔 You have <span id="ti-mentions-count">0</span> unread @mentions</strong>
        — <a href="#" id="ti-view-mentions">View all</a>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        var currentAgent = 0, currentPage = 1;

        function req(action, data, cb) {
            var fd = new FormData();
            fd.append('action', action); fd.append('nonce', NONCE);
            if (data) Object.keys(data).forEach(k => fd.append(k, data[k]));
            fetch(AJAX, {method:'POST', body:fd}).then(r=>r.json()).then(cb);
        }

        function tempClass(t){ return 'ti-temp-'+t; }

        function loadQueue(agentId, page) {
            agentId = agentId || 0; page = page || 1;
            currentAgent = agentId; currentPage = page;
            $('#ti-queue-content').html('<div class="ti-empty">Loading…</div>');
            req('lcrm_team_get_queue', {agent_id: agentId, page: page}, function(res) {
                if (!res.success) return;
                var leads = res.data.leads || [];
                var total = res.data.total || 0;
                if (!leads.length) { $('#ti-queue-content').html('<div class="ti-empty">No leads in this queue.</div>'); return; }
                var html = '';
                leads.forEach(function(l) {
                    var due = l.client_followup_date ? '📅 ' + l.client_followup_date : '';
                    html += '<div class="ti-lead-row" onclick="window.lcrmOpenLeadModal('+l.id+')">'
                        + '<div style="flex:1">'
                        + '<div class="ti-lead-name">'+l.client_name+'</div>'
                        + '<div class="ti-lead-work">'+l.work_type+'</div>'
                        + '<span class="ti-lead-stage" style="background:'+l.stage_color+'">'+l.stage_name+'</span>'
                        + '<div class="ti-lead-meta"><span class="'+tempClass(l.lead_temperature)+'">'+l.lead_temperature.toUpperCase()+'</span>'+(due?' · '+due:'')+(l.wa_unread_count>0?' · 💬 '+l.wa_unread_count+' WA':'')+'</div>'
                        + '</div>'
                        + '<div style="font-size:11px;color:#94a3b8;">'+l.lead_date+'</div>'
                        + '</div>';
                });
                $('#ti-queue-content').html(html);
                // Pagination
                var pages = Math.ceil(total / 25);
                if (pages > 1) {
                    var phtml = '';
                    for (var i = 1; i <= Math.min(pages, 10); i++) {
                        phtml += '<button class="button lcrm-btn ti-page-btn'+(i===page?' button-primary':'')+'" data-page="'+i+'" style="margin-right:4px;min-width:32px;">'+i+'</button>';
                    }
                    $('#ti-pagination').html(phtml);
                } else { $('#ti-pagination').empty(); }
            });
        }

        $('.ti-agent-row').on('click', function() {
            $('.ti-agent-row').removeClass('active');
            $(this).addClass('active');
            var agentId = parseInt($(this).data('agent'));
            var name = $(this).find('.ti-agent-name').text();
            $('#ti-queue-title').text(name + '\'s Queue');
            loadQueue(agentId, 1);
        });

        $(document).on('click', '.ti-page-btn', function() { loadQueue(currentAgent, parseInt($(this).data('page'))); });
        $('#ti-refresh-btn').on('click', function() { loadQueue(currentAgent, currentPage); });

        // AI Triage
        $('#ti-run-triage-btn').on('click', function() {
            $(this).text('Running…').prop('disabled', true);
            req('lcrm_ai_run_triage', {}, function(res) {
                $('#ti-run-triage-btn').text('🤖 AI Triage').prop('disabled', false);
                if (res.success) { alert('AI triage complete. ' + res.data.alerts + ' alerts waiting.'); }
            });
        });

        // Check mentions
        req('lcrm_team_get_mentions', {}, function(res) {
            if (res.success && res.data.count > 0) {
                $('#ti-mentions-count').text(res.data.count);
                $('#ti-mentions-bar').show();
            }
        });

        loadQueue(0, 1);

    })(jQuery);
    </script>
    <?php
    lcrm_footer();
}

/* =========================================================
   SETTINGS: PAYMENTS TAB
   ========================================================= */
function lcrm_settings_payments_tab() {
    $gateway   = get_option('lcrm_payment_gateway', 'upi');
    $rzp_id    = get_option('lcrm_razorpay_key_id', '');
    $rzp_wh    = get_option('lcrm_razorpay_webhook_secret', '');
    $cf_id     = get_option('lcrm_cashfree_client_id', '');
    $upi_id    = get_option('lcrm_upi_id', '');
    $upi_name  = get_option('lcrm_upi_name', get_bloginfo('name'));
    $wh_url    = rest_url('lcrm/v1/payment-webhook/razorpay');
    ?>
    <div class="lcrm-card" style="max-width:700px;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;">Payment Gateway</h3>
        <div class="lcrm-form-group">
            <label>Active Gateway</label>
            <select id="lcrm-pay-gateway" class="lcrm-input" style="max-width:240px;">
                <option value="upi"       <?php selected($gateway,'upi'); ?>>UPI (No API key needed)</option>
                <option value="razorpay"  <?php selected($gateway,'razorpay'); ?>>Razorpay Payment Links</option>
                <option value="cashfree"  <?php selected($gateway,'cashfree'); ?>>Cashfree Payment Links</option>
            </select>
        </div>

        <!-- UPI -->
        <div id="lcrm-pay-upi" class="lcrm-pay-section" <?php echo $gateway!=='upi'?'style="display:none"':''; ?>>
            <h4 style="font-size:13px;font-weight:600;margin:16px 0 10px;">UPI Settings</h4>
            <div class="lcrm-form-grid">
                <div class="lcrm-form-group"><label>UPI ID</label><input type="text" id="lcrm-upi-id" class="lcrm-input lcrm-input-full" value="<?php echo esc_attr($upi_id); ?>" placeholder="yourname@upi"></div>
                <div class="lcrm-form-group"><label>Display Name</label><input type="text" id="lcrm-upi-name" class="lcrm-input lcrm-input-full" value="<?php echo esc_attr($upi_name); ?>"></div>
            </div>
            <p style="font-size:12px;color:#6b7280;">UPI deep-links open the client's UPI app (GPay, PhonePe, Paytm). Manual payment confirmation required.</p>
        </div>

        <!-- Razorpay -->
        <div id="lcrm-pay-razorpay" class="lcrm-pay-section" <?php echo $gateway!=='razorpay'?'style="display:none"':''; ?>>
            <h4 style="font-size:13px;font-weight:600;margin:16px 0 10px;">Razorpay Settings</h4>
            <div class="lcrm-form-grid">
                <div class="lcrm-form-group"><label>Key ID</label><input type="text" id="lcrm-rzp-key-id" class="lcrm-input lcrm-input-full" value="<?php echo esc_attr($rzp_id); ?>" placeholder="rzp_live_..."></div>
                <div class="lcrm-form-group"><label>Key Secret</label><input type="password" id="lcrm-rzp-key-secret" class="lcrm-input lcrm-input-full" value="" placeholder="Leave blank to keep current"></div>
                <div class="lcrm-form-group"><label>Webhook Secret</label><input type="password" id="lcrm-rzp-webhook-secret" class="lcrm-input lcrm-input-full" value="" placeholder="Leave blank to keep current"></div>
            </div>
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;font-size:12px;color:#0369a1;">
                <strong>Webhook URL:</strong> <code><?php echo esc_html($wh_url); ?></code><br>
                Configure this URL in your Razorpay dashboard under Settings → Webhooks. Select event: <strong>payment_link.paid</strong>
            </div>
        </div>

        <!-- Cashfree -->
        <div id="lcrm-pay-cashfree" class="lcrm-pay-section" <?php echo $gateway!=='cashfree'?'style="display:none"':''; ?>>
            <h4 style="font-size:13px;font-weight:600;margin:16px 0 10px;">Cashfree Settings</h4>
            <div class="lcrm-form-grid">
                <div class="lcrm-form-group"><label>Client ID</label><input type="text" id="lcrm-cf-client-id" class="lcrm-input lcrm-input-full" value="<?php echo esc_attr($cf_id); ?>"></div>
                <div class="lcrm-form-group"><label>Client Secret</label><input type="password" id="lcrm-cf-client-secret" class="lcrm-input lcrm-input-full" value="" placeholder="Leave blank to keep current"></div>
            </div>
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;font-size:12px;color:#0369a1;">
                <strong>Webhook URL:</strong> <code><?php echo esc_html(rest_url('lcrm/v1/payment-webhook/cashfree')); ?></code>
            </div>
        </div>

        <button class="button button-primary lcrm-btn" id="lcrm-pay-save-btn" style="margin-top:16px;">Save Payment Settings</button>
        <span id="lcrm-pay-msg" class="lcrm-form-msg" style="margin-left:10px;"></span>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        $('#lcrm-pay-gateway').on('change', function(){
            $('.lcrm-pay-section').hide();
            $('#lcrm-pay-'+$(this).val()).show();
        });
        $('#lcrm-pay-save-btn').on('click', function(){
            var fd = new FormData();
            fd.append('action','lcrm_payment_save_settings'); fd.append('nonce', NONCE);
            fd.append('payment_gateway', $('#lcrm-pay-gateway').val());
            fd.append('upi_id',          $('#lcrm-upi-id').val());
            fd.append('upi_name',        $('#lcrm-upi-name').val());
            fd.append('razorpay_key_id', $('#lcrm-rzp-key-id').val());
            fd.append('razorpay_key_secret', $('#lcrm-rzp-key-secret').val());
            fd.append('razorpay_webhook_secret', $('#lcrm-rzp-webhook-secret').val());
            fd.append('cashfree_client_id', $('#lcrm-cf-client-id').val());
            fd.append('cashfree_client_secret', $('#lcrm-cf-client-secret').val());
            fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(function(res){
                $('#lcrm-pay-msg').text(res.success ? '✓ Saved' : (res.data?.msg||'Error')).css('color', res.success?'#10b981':'#ef4444');
            });
        });
    })(jQuery);
    </script>
    <?php
}

/* =========================================================
   SETTINGS: WEBHOOKS TAB
   ========================================================= */
function lcrm_settings_webhooks_tab() {
    $events = lcrm_webhooks_get_events();
    ?>
    <div class="lcrm-two-col" style="align-items:start;">
        <!-- Webhook list -->
        <div class="lcrm-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <h3 style="font-size:14px;font-weight:700;">Outbound Webhooks</h3>
                <button class="button button-primary lcrm-btn" id="lcrm-wh-add-btn">+ Add Webhook</button>
            </div>
            <div id="lcrm-wh-list"><p style="color:#9ca3af;font-size:13px;">Loading…</p></div>
        </div>
        <!-- Add/Edit form -->
        <div class="lcrm-card" id="lcrm-wh-form-card" style="display:none;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;" id="lcrm-wh-form-title">Add Webhook</h3>
            <input type="hidden" id="lcrm-wh-id" value="0">
            <div class="lcrm-form-group"><label>Name</label><input type="text" id="lcrm-wh-name" class="lcrm-input lcrm-input-full" placeholder="e.g. Zapier Pipeline"></div>
            <div class="lcrm-form-group"><label>URL</label><input type="url" id="lcrm-wh-url" class="lcrm-input lcrm-input-full" placeholder="https://hooks.zapier.com/…"></div>
            <div class="lcrm-form-group">
                <label>Events</label>
                <?php foreach ($events as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:normal;cursor:pointer;">
                    <input type="checkbox" name="wh_events[]" value="<?php echo esc_attr($key); ?>" class="lcrm-wh-event-cb">
                    <?php echo esc_html($label); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="lcrm-form-group"><label>Secret (auto-generated if blank)</label><input type="text" id="lcrm-wh-secret" class="lcrm-input lcrm-input-full" placeholder="Optional HMAC secret"></div>
            <div class="lcrm-form-group">
                <label>Active</label>
                <select id="lcrm-wh-active" class="lcrm-input" style="width:120px;"><option value="1">Yes</option><option value="0">No</option></select>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="button button-primary lcrm-btn" id="lcrm-wh-save-btn">Save Webhook</button>
                <button class="button lcrm-btn" id="lcrm-wh-cancel-btn">Cancel</button>
            </div>
            <span id="lcrm-wh-msg" class="lcrm-form-msg" style="margin-top:8px;display:block;"></span>
        </div>
    </div>

    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        function req(action, data, cb){ var fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE); if(data) Object.keys(data).forEach(k=>fd.append(k,data[k])); fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(cb); }

        function loadWebhooks(){
            req('lcrm_webhooks_list',{},function(res){
                if(!res.success) return;
                var whs=res.data.webhooks||[];
                if(!whs.length){$('#lcrm-wh-list').html('<p style="color:#9ca3af;font-size:13px;">No webhooks yet. Add one to connect LeadCRM to Zapier, Make, or your backend.</p>');return;}
                var html='<table class="lcrm-table"><thead><tr><th>Name</th><th>Events</th><th>Status</th><th>Last Fired</th><th>Actions</th></tr></thead><tbody>';
                whs.forEach(function(w){
                    var status = w.is_active ? '<span style="color:#10b981;font-weight:600;">Active</span>' : '<span style="color:#9ca3af;">Paused</span>';
                    html+='<tr>'
                        +'<td><strong>'+w.name+'</strong><br><small style="color:#6b7280;">'+w.url.substring(0,50)+'…</small></td>'
                        +'<td style="font-size:11px;">'+w.events.join(', ')+'</td>'
                        +'<td>'+status+(w.fail_count>0?' <span style="color:#ef4444;font-size:11px;">('+w.fail_count+' fails)</span>':'')+'</td>'
                        +'<td style="font-size:11px;color:#6b7280;">'+(w.last_fired||'Never')+'</td>'
                        +'<td style="white-space:nowrap;">'
                        +'<button class="button lcrm-btn" onclick="lcrmWhTest('+w.id+')">Test</button> '
                        +'<button class="button lcrm-btn" onclick="lcrmWhEdit('+w.id+')">Edit</button> '
                        +'<button class="button lcrm-btn" style="color:#ef4444;" onclick="lcrmWhDelete('+w.id+')">Delete</button>'
                        +'</td></tr>';
                });
                html+='</tbody></table>';
                $('#lcrm-wh-list').html(html);
            });
        }
        loadWebhooks();

        window.lcrmWhEdit=function(id){
            req('lcrm_webhooks_list',{},function(res){
                var wh=res.data.webhooks.find(w=>w.id===id);
                if(!wh) return;
                $('#lcrm-wh-id').val(wh.id);
                $('#lcrm-wh-name').val(wh.name);
                $('#lcrm-wh-url').val(wh.url);
                $('#lcrm-wh-secret').val('');
                $('#lcrm-wh-active').val(wh.is_active?1:0);
                $('.lcrm-wh-event-cb').prop('checked',false);
                wh.events.forEach(function(e){ $('.lcrm-wh-event-cb[value="'+e+'"]').prop('checked',true); });
                $('#lcrm-wh-form-title').text('Edit Webhook');
                $('#lcrm-wh-form-card').show();
            });
        };
        window.lcrmWhTest=function(id){
            req('lcrm_webhooks_test',{id:id},function(res){
                alert(res.success ? '✓ ' + res.data.msg : '✗ ' + res.data.msg);
            });
        };
        window.lcrmWhDelete=function(id){
            if(!confirm('Delete this webhook?')) return;
            req('lcrm_webhooks_delete',{id:id},function(res){ if(res.success) loadWebhooks(); });
        };

        $('#lcrm-wh-add-btn').on('click',function(){
            $('#lcrm-wh-id').val(0); $('#lcrm-wh-name,#lcrm-wh-url,#lcrm-wh-secret').val('');
            $('.lcrm-wh-event-cb').prop('checked',false);
            $('#lcrm-wh-active').val(1);
            $('#lcrm-wh-form-title').text('Add Webhook');
            $('#lcrm-wh-form-card').show();
        });
        $('#lcrm-wh-cancel-btn').on('click',function(){ $('#lcrm-wh-form-card').hide(); });

        $('#lcrm-wh-save-btn').on('click',function(){
            var events=[]; $('.lcrm-wh-event-cb:checked').each(function(){ events.push($(this).val()); });
            if(!events.length){ $('#lcrm-wh-msg').text('Select at least one event.').css('color','#ef4444'); return; }
            req('lcrm_webhooks_save',{
                id:$('#lcrm-wh-id').val(), name:$('#lcrm-wh-name').val(),
                url:$('#lcrm-wh-url').val(), events:JSON.stringify(events),
                secret:$('#lcrm-wh-secret').val(), is_active:$('#lcrm-wh-active').val()
            },function(res){
                $('#lcrm-wh-msg').text(res.success?'✓ Saved':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
                if(res.success){ loadWebhooks(); setTimeout(()=>$('#lcrm-wh-form-card').hide(), 1000); }
            });
        });
    })(jQuery);
    </script>
    <?php
}

/* =========================================================
   SETTINGS: SLA RULES TAB
   ========================================================= */
function lcrm_settings_sla_tab() {
    $stages = lcrm_get_stages();
    ?>
    <div class="lcrm-two-col" style="align-items:start;">
        <div class="lcrm-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <h3 style="font-size:14px;font-weight:700;">SLA Rules</h3>
                <button class="button button-primary lcrm-btn" id="lcrm-sla-add-btn">+ Add Rule</button>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-bottom:12px;">SLA rules automatically create high-priority alerts when leads breach time thresholds.</p>
            <div id="lcrm-sla-list"><p style="color:#9ca3af;font-size:13px;">Loading…</p></div>
        </div>
        <div class="lcrm-card" id="lcrm-sla-form-card" style="display:none;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;" id="lcrm-sla-form-title">Add SLA Rule</h3>
            <input type="hidden" id="lcrm-sla-id" value="0">
            <div class="lcrm-form-group"><label>Rule Name</label><input type="text" id="lcrm-sla-name" class="lcrm-input lcrm-input-full" placeholder="e.g. First Response SLA"></div>
            <div class="lcrm-form-group">
                <label>Trigger</label>
                <select id="lcrm-sla-trigger" class="lcrm-input lcrm-input-full">
                    <option value="new_lead">New lead created (no contact)</option>
                    <option value="no_activity">No activity on lead</option>
                    <option value="no_reply">No client reply</option>
                    <option value="stage_entry">Lead in stage too long</option>
                </select>
            </div>
            <div class="lcrm-form-group" id="lcrm-sla-stage-wrap" style="display:none;">
                <label>Stage</label>
                <select id="lcrm-sla-stage" class="lcrm-input lcrm-input-full">
                    <option value="">— Any stage —</option>
                    <?php foreach ($stages as $s): ?><option value="<?php echo intval($s->id); ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="lcrm-form-group"><label>Hours Threshold</label><input type="number" id="lcrm-sla-hours" class="lcrm-input" style="width:120px;" value="24" min="1" max="720"></div>
            <div class="lcrm-form-group">
                <label>Priority</label>
                <select id="lcrm-sla-priority" class="lcrm-input" style="width:140px;">
                    <option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option>
                </select>
            </div>
            <div class="lcrm-form-group"><label>Alert Message</label><input type="text" id="lcrm-sla-message" class="lcrm-input lcrm-input-full" placeholder="Message to show in the alert"></div>
            <div style="display:flex;gap:8px;">
                <button class="button button-primary lcrm-btn" id="lcrm-sla-save-btn">Save Rule</button>
                <button class="button lcrm-btn" id="lcrm-sla-cancel-btn">Cancel</button>
            </div>
            <span id="lcrm-sla-msg" class="lcrm-form-msg" style="margin-top:8px;display:block;"></span>
        </div>
    </div>
    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        function req(action, data, cb){ var fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE); if(data) Object.keys(data).forEach(k=>fd.append(k,data[k])); fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(cb); }

        function loadRules(){
            req('lcrm_team_get_sla_rules',{},function(res){
                if(!res.success) return;
                var rules=res.data.rules||[];
                if(!rules.length){$('#lcrm-sla-list').html('<p style="color:#9ca3af;font-size:13px;">No SLA rules yet.</p>');return;}
                var html='<table class="lcrm-table"><thead><tr><th>Rule</th><th>Trigger</th><th>Threshold</th><th>Priority</th><th></th></tr></thead><tbody>';
                rules.forEach(function(r){
                    html+='<tr><td><strong>'+r.rule_name+'</strong></td><td>'+r.trigger_on+(r.stage_name?' ('+r.stage_name+')':'')+'</td><td>'+r.hours_threshold+'h</td><td>'+r.priority+'</td>'
                        +'<td><button class="button lcrm-btn" onclick="lcrmSlaEdit('+r.id+')">Edit</button> <button class="button lcrm-btn" style="color:#ef4444;" onclick="lcrmSlaDelete('+r.id+')">Delete</button></td></tr>';
                });
                html+='</tbody></table>';
                $('#lcrm-sla-list').html(html);
            });
        }
        loadRules();

        $('#lcrm-sla-trigger').on('change',function(){ $('#lcrm-sla-stage-wrap').toggle($(this).val()==='stage_entry'); });
        $('#lcrm-sla-add-btn').on('click',function(){
            $('#lcrm-sla-id').val(0); $('#lcrm-sla-name,#lcrm-sla-message').val(''); $('#lcrm-sla-hours').val(24);
            $('#lcrm-sla-form-title').text('Add SLA Rule'); $('#lcrm-sla-form-card').show();
        });
        $('#lcrm-sla-cancel-btn').on('click',function(){ $('#lcrm-sla-form-card').hide(); });
        window.lcrmSlaDelete=function(id){ if(!confirm('Delete this SLA rule?')) return; req('lcrm_team_delete_sla_rule',{id:id},function(res){ if(res.success) loadRules(); }); };
        window.lcrmSlaEdit=function(id){
            req('lcrm_team_get_sla_rules',{},function(res){
                var r=res.data.rules.find(x=>x.id==id); if(!r) return;
                $('#lcrm-sla-id').val(r.id); $('#lcrm-sla-name').val(r.rule_name);
                $('#lcrm-sla-trigger').val(r.trigger_on).trigger('change');
                $('#lcrm-sla-stage').val(r.stage_id||''); $('#lcrm-sla-hours').val(r.hours_threshold);
                $('#lcrm-sla-priority').val(r.priority); $('#lcrm-sla-message').val(r.alert_message);
                $('#lcrm-sla-form-title').text('Edit SLA Rule'); $('#lcrm-sla-form-card').show();
            });
        };
        $('#lcrm-sla-save-btn').on('click',function(){
            req('lcrm_team_save_sla_rule',{
                id:$('#lcrm-sla-id').val(), rule_name:$('#lcrm-sla-name').val(),
                trigger_on:$('#lcrm-sla-trigger').val(), stage_id:$('#lcrm-sla-stage').val(),
                hours_threshold:$('#lcrm-sla-hours').val(), priority:$('#lcrm-sla-priority').val(),
                alert_message:$('#lcrm-sla-message').val(), is_active:1
            },function(res){
                $('#lcrm-sla-msg').text(res.success?'✓ Saved':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
                if(res.success){ loadRules(); setTimeout(()=>$('#lcrm-sla-form-card').hide(),1000); }
            });
        });
    })(jQuery);
    </script>
    <?php
}

/* =========================================================
   SETTINGS: WA TEMPLATES TAB
   ========================================================= */
function lcrm_settings_templates_tab() {
    ?>
    <div class="lcrm-two-col" style="align-items:start;">
        <div class="lcrm-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <h3 style="font-size:14px;font-weight:700;">WhatsApp Templates</h3>
                <button class="button button-primary lcrm-btn" id="lcrm-wat-add-btn">+ Add Template</button>
            </div>
            <p style="font-size:12px;color:#6b7280;margin-bottom:12px;">Available placeholders: <code>{{client_name}}</code> <code>{{work_type}}</code> <code>{{amount}}</code> <code>{{stage}}</code> <code>{{site_name}}</code></p>
            <div id="lcrm-wat-list"><p style="color:#9ca3af;font-size:13px;">Loading…</p></div>
        </div>
        <div class="lcrm-card" id="lcrm-wat-form-card" style="display:none;min-width:0;">
            <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;" id="lcrm-wat-form-title">Add Template</h3>
            <input type="hidden" id="lcrm-wat-id" value="0">
            <div class="lcrm-form-group"><label>Category</label><input type="text" id="lcrm-wat-category" class="lcrm-input lcrm-input-full" placeholder="e.g. Follow-up"></div>
            <div class="lcrm-form-group"><label>Title</label><input type="text" id="lcrm-wat-title" class="lcrm-input lcrm-input-full" placeholder="e.g. Gentle Follow-up"></div>
            <div class="lcrm-form-group"><label>Message Body</label><textarea id="lcrm-wat-body" class="lcrm-input lcrm-input-full lcrm-textarea" rows="7" placeholder="Hello {{client_name}} ji!…"></textarea></div>
            <div style="display:flex;gap:8px;">
                <button class="button button-primary lcrm-btn" id="lcrm-wat-save-btn">Save Template</button>
                <button class="button lcrm-btn" id="lcrm-wat-cancel-btn">Cancel</button>
            </div>
            <span id="lcrm-wat-msg" class="lcrm-form-msg" style="margin-top:8px;display:block;"></span>
        </div>
    </div>
    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        function req(action, data, cb){ var fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE); if(data) Object.keys(data).forEach(k=>fd.append(k,data[k])); fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(cb); }

        function loadTemplates(){
            req('lcrm_wat_get_grouped',{},function(res){
                if(!res.success) return;
                var groups=res.data.templates;
                var cats=Object.keys(groups);
                if(!cats.length){$('#lcrm-wat-list').html('<p style="color:#9ca3af;font-size:13px;">No templates yet.</p>');return;}
                var html='';
                cats.forEach(function(cat){
                    html+='<div style="margin-bottom:16px;"><strong style="font-size:12px;color:#374151;text-transform:uppercase;letter-spacing:.5px;">'+cat+'</strong><div style="margin-top:8px;">';
                    groups[cat].forEach(function(t){
                        html+='<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:#f8fafc;border-radius:8px;margin-bottom:6px;">'
                            +'<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:2px;">'+t.title+'</div>'
                            +'<div style="font-size:11px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+t.preview+'</div></div>'
                            +'<button class="button lcrm-btn" onclick="lcrmWatEdit('+t.id+')">Edit</button>'
                            +' <button class="button lcrm-btn" style="color:#ef4444;" onclick="lcrmWatDelete('+t.id+')">Del</button>'
                            +'</div>';
                    });
                    html+='</div></div>';
                });
                $('#lcrm-wat-list').html(html);
            });
        }
        loadTemplates();

        window.lcrmWatDelete=function(id){ if(!confirm('Delete template?')) return; req('lcrm_wat_delete',{id:id},function(res){if(res.success)loadTemplates();}); };
        window.lcrmWatEdit=function(id){
            // fetch all and find by id
            req('lcrm_wat_get_grouped',{},function(res){
                var all=[]; Object.values(res.data.templates).forEach(g=>all=all.concat(g));
                var t=all.find(x=>x.id===id); if(!t) return;
                $('#lcrm-wat-id').val(t.id); $('#lcrm-wat-title').val(t.title); $('#lcrm-wat-body').val(t.body);
                // category comes from the group key — we need to store it differently
                // For edit, we'll show a full API call to wat_save which has category
                $('#lcrm-wat-form-title').text('Edit Template'); $('#lcrm-wat-form-card').show();
            });
        };
        $('#lcrm-wat-add-btn').on('click',function(){ $('#lcrm-wat-id').val(0); $('#lcrm-wat-category,#lcrm-wat-title,#lcrm-wat-body').val(''); $('#lcrm-wat-form-title').text('Add Template'); $('#lcrm-wat-form-card').show(); });
        $('#lcrm-wat-cancel-btn').on('click',function(){ $('#lcrm-wat-form-card').hide(); });
        $('#lcrm-wat-save-btn').on('click',function(){
            req('lcrm_wat_save',{id:$('#lcrm-wat-id').val(),category:$('#lcrm-wat-category').val(),title:$('#lcrm-wat-title').val(),body:$('#lcrm-wat-body').val()},function(res){
                $('#lcrm-wat-msg').text(res.success?'✓ Saved':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
                if(res.success){ loadTemplates(); setTimeout(()=>$('#lcrm-wat-form-card').hide(),1000); }
            });
        });
    })(jQuery);
    </script>
    <?php
}

/* =========================================================
   SETTINGS: AI INTELLIGENCE TAB
   ========================================================= */
function lcrm_settings_ai_pro_tab() {
    $hot_warm  = get_option('lcrm_ai_hot_warm_hours',   48);
    $warm_cold = get_option('lcrm_ai_warm_cold_hours',  72);
    $predraft  = get_option('lcrm_ai_predraft_replies',  0);
    $triage    = get_option('lcrm_ai_daily_triage',      1);
    ?>
    <div class="lcrm-card" style="max-width:640px;">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:16px;">AI Proactive Intelligence</h3>
        <p style="font-size:12px;color:#6b7280;margin-bottom:16px;">These settings control how aggressively the AI monitors and acts on your leads.</p>

        <div class="lcrm-form-grid">
            <div class="lcrm-form-group">
                <label>Daily AI Triage</label>
                <select id="lcrm-aipr-triage" class="lcrm-input" style="width:140px;">
                    <option value="1" <?php selected($triage,1); ?>>Enabled</option>
                    <option value="0" <?php selected($triage,0); ?>>Disabled</option>
                </select>
                <p style="font-size:11px;color:#6b7280;">AI ranks your 20 most urgent leads each morning and creates action alerts.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Hot → Warm downgrade (hours of silence)</label>
                <input type="number" id="lcrm-aipr-hot-warm" class="lcrm-input" style="width:100px;" value="<?php echo intval($hot_warm); ?>" min="1" max="720">
                <p style="font-size:11px;color:#6b7280;">If a hot lead hasn't replied in this many hours, AI downgrades to warm.</p>
            </div>
            <div class="lcrm-form-group">
                <label>Warm → Cold downgrade (hours of silence)</label>
                <input type="number" id="lcrm-aipr-warm-cold" class="lcrm-input" style="width:100px;" value="<?php echo intval($warm_cold); ?>" min="1" max="720">
            </div>
            <div class="lcrm-form-group">
                <label>Pre-draft WA Replies for Hot Leads</label>
                <select id="lcrm-aipr-predraft" class="lcrm-input" style="width:140px;">
                    <option value="1" <?php selected($predraft,1); ?>>Enabled</option>
                    <option value="0" <?php selected($predraft,0); ?>>Disabled</option>
                </select>
                <p style="font-size:11px;color:#6b7280;">Each morning AI pre-drafts a reply for each hot lead with unread WA messages, saved as an internal note.</p>
            </div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
            <button class="button button-primary lcrm-btn" id="lcrm-aipr-save-btn">Save AI Settings</button>
            <button class="button lcrm-btn" id="lcrm-aipr-triage-now-btn">&#129302; Run Triage Now</button>
            <button class="button lcrm-btn" id="lcrm-aipr-winloss-btn">&#128200; Win/Loss Analysis</button>
        </div>
        <span id="lcrm-aipr-msg" class="lcrm-form-msg" style="margin-top:8px;display:block;"></span>
        <div id="lcrm-aipr-winloss-result" style="display:none;margin-top:16px;background:#f8fafc;border-radius:8px;padding:16px;font-size:13px;line-height:1.7;white-space:pre-line;"></div>
    </div>
    <script>
    (function($){
        var AJAX='<?php echo admin_url('admin-ajax.php'); ?>', NONCE='<?php echo wp_create_nonce('lcrm_nonce'); ?>';
        function req(action, data, cb){ var fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE); if(data) Object.keys(data).forEach(k=>fd.append(k,data[k])); fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(cb); }
        $('#lcrm-aipr-save-btn').on('click',function(){
            req('lcrm_ai_save_pro_settings',{hot_warm_hours:$('#lcrm-aipr-hot-warm').val(),warm_cold_hours:$('#lcrm-aipr-warm-cold').val(),predraft_replies:$('#lcrm-aipr-predraft').val(),daily_triage:$('#lcrm-aipr-triage').val()},function(res){
                $('#lcrm-aipr-msg').text(res.success?'✓ Saved':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
            });
        });
        $('#lcrm-aipr-triage-now-btn').on('click',function(){
            $(this).text('Running…').prop('disabled',true);
            req('lcrm_ai_run_triage',{},function(res){
                $('#lcrm-aipr-triage-now-btn').text('🤖 Run Triage Now').prop('disabled',false);
                $('#lcrm-aipr-msg').text(res.success?'Triage complete. '+res.data.alerts+' alerts waiting.':'Failed').css('color',res.success?'#10b981':'#ef4444');
            });
        });
        $('#lcrm-aipr-winloss-btn').on('click',function(){
            $(this).text('Analysing…').prop('disabled',true);
            req('lcrm_ai_win_loss',{},function(res){
                $('#lcrm-aipr-winloss-btn').text('📈 Win/Loss Analysis').prop('disabled',false);
                if(res.success){ $('#lcrm-aipr-winloss-result').text(res.data.analysis).show(); }
            });
        });
    })(jQuery);
    </script>
    <?php
}


/* =========================================================
   CRM APP MENU LINK — redirect to frontend SPA
   ========================================================= */

add_action( 'admin_menu', 'lcrm_spa_fix_app_menu_link', 100 );
function lcrm_spa_fix_app_menu_link() {
    global $submenu;
    $crm_page_id = (int) get_option( 'lcrm_spa_page_id', 0 );
    if ( ! $crm_page_id || ! isset( $submenu['leadcrm'] ) ) return;
    $crm_url = get_permalink( $crm_page_id );
    if ( ! $crm_url ) return;
    foreach ( $submenu['leadcrm'] as &$item ) {
        if ( isset($item[2]) && $item[2] === 'leadcrm-open-app' ) {
            $item[2] = esc_url( $crm_url );
            break;
        }
    }
}

// Also add admin bar link
add_action( 'admin_bar_menu', 'lcrm_spa_admin_bar_link', 999 );
function lcrm_spa_admin_bar_link( $wp_admin_bar ) {
    if ( ! is_admin() ) return;
    if ( ! lcrm_current_can( 'lcrm_view_leads' ) ) return;
    $crm_page_id = (int) get_option( 'lcrm_spa_page_id', 0 );
    if ( ! $crm_page_id ) return;
    $crm_url = get_permalink( $crm_page_id );
    if ( ! $crm_url ) return;
    $wp_admin_bar->add_node( [
        'id'     => 'lcrm-open-spa',
        'title'  => '⚡ Open CRM',
        'href'   => $crm_url,
        'meta'   => [ 'target' => '_blank', 'title' => 'Open LeadCRM App' ],
    ] );
}
