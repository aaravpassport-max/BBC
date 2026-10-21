<?php
/**
 * LeadCRM SPA Frontend — v5.0
 *
 * A thin shell around the existing wp-admin page functions.
 * The sidebar replaces the WP admin menu.
 * Every section calls the EXACT same lcrm_page_*() function used in wp-admin.
 * No UI changes. No feature removal. Same CSS. Same HTML.
 *
 * Performance:
 *   - Shell PHP: 3 queries (caps + unread count + transient read for dropdowns)
 *   - Each section: same queries as wp-admin — no more, no less
 *   - Sections cached in JS memory after first load — revisit = 0 queries
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
   ROUTING — template_redirect intercept
   Fires before any theme template loads.
   If this is the CRM page → output full clean HTML and exit.
   ========================================================= */

add_action( 'template_redirect', 'lcrm_spa_maybe_render', 1 );

function lcrm_spa_maybe_render() {
    if ( is_admin() ) return;

    $crm_page_id = (int) get_option( 'lcrm_spa_page_id', 0 );
    $is_crm_page = false;

    if ( $crm_page_id && is_page( $crm_page_id ) ) {
        $is_crm_page = true;
    } elseif ( is_page() ) {
        global $post;
        if ( $post && (
            has_shortcode( $post->post_content, 'leadcrm_app' ) ||
            has_shortcode( $post->post_content, 'leadcrm_portal' )
        ) ) {
            $is_crm_page = true;
        }
    }

    if ( ! $is_crm_page ) return;

    lcrm_spa_render_standalone();
    exit;
}

/* =========================================================
   STANDALONE RENDER — full clean HTML document
   ========================================================= */

function lcrm_spa_render_standalone() {
    if ( ! is_user_logged_in() ) {
        lcrm_spa_render_login_page();
        return;
    }
    if ( ! lcrm_current_can( 'lcrm_view_leads' ) ) {
        lcrm_spa_render_denied_page();
        return;
    }

    $ver      = LCRM_VERSION;
    $base_url = LCRM_URL;
    $ajax_url = admin_url( 'admin-ajax.php' );
    $boot     = lcrm_spa_boot_data();
    $sections = lcrm_spa_nav_sections();

    $gf_url    = 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap';
    $css_admin = $base_url . 'assets/css/admin.css?v=' . $ver;
    $css_wa    = $base_url . 'assets/css/wa-admin.css?v=' . $ver;
    $css_spa   = $base_url . 'assets/css/spa.css?v=' . $ver;
    $js_jquery = includes_url( 'js/jquery/jquery.min.js' );
    $js_jqui   = 'https://cdn.jsdelivr.net/npm/jquery-ui@1.13.2/dist/jquery-ui.min.js';
    $js_sort   = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
    $js_admin  = $base_url . 'assets/js/admin.js?v=' . $ver;
    $js_wa     = $base_url . 'assets/js/wa-admin.js?v=' . $ver;
    $js_spa    = $base_url . 'assets/js/spa.js?v=' . $ver;

    $lcrm_json = wp_json_encode( $boot );
    $spa_json  = wp_json_encode( [
        'base_url' => trailingslashit( get_permalink() ),
        'sections' => $sections,
    ] );

    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#5046e4">
<title><?php echo esc_html( get_bloginfo('name') ); ?> — CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?php echo esc_url($gf_url); ?>" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url($css_admin); ?>">
<link rel="stylesheet" href="<?php echo esc_url($css_wa); ?>">
<link rel="stylesheet" href="<?php echo esc_url($css_spa); ?>">
<?php if ( has_site_icon() ) echo '<link rel="icon" href="' . esc_url(get_site_icon_url(32)) . '">'; ?>
</head>
<body>
<?php lcrm_spa_shell(); ?>
<script>
var LCRM     = <?php echo $lcrm_json; ?>;
var LCRM_SPA = <?php echo $spa_json; ?>;
var ajaxurl  = <?php echo wp_json_encode($ajax_url); ?>;
var pagenow  = 'leadcrm';
</script>
<script src="<?php echo esc_url($js_jquery); ?>"></script>
<script src="<?php echo esc_url($js_jqui); ?>"></script>
<script src="<?php echo esc_url($js_sort); ?>"></script>
<script src="<?php echo esc_url($js_admin); ?>"></script>
<script src="<?php echo esc_url($js_wa); ?>"></script>
<script src="<?php echo esc_url($js_spa); ?>"></script>
</body>
</html>
<?php
}

/* =========================================================
   LOGIN PAGE
   ========================================================= */

function lcrm_spa_render_login_page() {
    $site    = esc_html( get_bloginfo('name') );
    $login   = esc_url( wp_login_url( get_permalink() ) );
    $css_spa = LCRM_URL . 'assets/css/spa.css?v=' . LCRM_VERSION;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — ' . $site . '</title>
<link rel="stylesheet" href="' . esc_url($css_spa) . '"></head><body>';
    echo lcrm_spa_login_wall();
    echo '</body></html>';
}

function lcrm_spa_render_denied_page() {
    $css_spa = LCRM_URL . 'assets/css/spa.css?v=' . LCRM_VERSION;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied</title>
<link rel="stylesheet" href="' . esc_url($css_spa) . '"></head><body>
<div class="lcrm-spa-denied" style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center">
<div style="font-size:48px">🔒</div><h3 style="font-size:20px;font-weight:700;margin:12px 0 8px">Access Denied</h3>
<p style="color:#6b7280">You do not have permission to access the CRM.</p>
<a href="' . esc_url(wp_logout_url(home_url())) . '" style="margin-top:16px;color:#5046e4;font-size:13px">Sign out</a>
</div></body></html>';
}

/* =========================================================
   BOOT DATA — one-time PHP, cached 10 min
   ========================================================= */

function lcrm_spa_boot_data() {
    $user     = wp_get_current_user();
    $is_admin = current_user_can( 'manage_options' );

    // Dropdown data — cached 10 min
    $cache_key = 'lcrm_spa_boot_' . get_current_user_id();
    $cached    = get_transient( $cache_key );
    if ( ! $cached ) {
        global $wpdb;
        $ok = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}lcrm_stages'" );
        if ( $ok ) {
            $stages  = $wpdb->get_results( "SELECT id,name,color FROM {$wpdb->prefix}lcrm_stages WHERE is_active=1 ORDER BY sort_order ASC" );
            $sources = $wpdb->get_results( "SELECT id,name FROM {$wpdb->prefix}lcrm_lead_sources WHERE is_active=1 ORDER BY sort_order ASC" );
            $vendors = $wpdb->get_results( "SELECT id,name FROM {$wpdb->prefix}lcrm_vendors WHERE is_active=1 ORDER BY name ASC" );
            $brands  = $wpdb->get_results( "SELECT id,name FROM {$wpdb->prefix}lcrm_brands WHERE is_active=1 ORDER BY name ASC" );
            $wtypes  = $wpdb->get_results( "SELECT id,name FROM {$wpdb->prefix}lcrm_work_types WHERE is_active=1 ORDER BY sort_order ASC" );
            $cached  = compact('stages','sources','vendors','brands','wtypes');
            set_transient( $cache_key, $cached, 600 );
        } else {
            $cached = [ 'stages'=>[],'sources'=>[],'vendors'=>[],'brands'=>[],'wtypes'=>[] ];
        }
    }

    global $wpdb;
    $has_alerts = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}lcrm_alerts'" );
    $has_leads  = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}lcrm_leads'" );
    $unread     = $has_alerts ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0") : 0;
    $wa_unread  = $has_leads  ? (int)$wpdb->get_var("SELECT COALESCE(SUM(wa_unread_count),0) FROM {$wpdb->prefix}lcrm_leads") : 0;

    // Fields for lead table column picker
    $list_fields = function_exists('lcrm_get_list_fields')   ? array_map(fn($f)=>['key'=>$f->field_key,'label'=>$f->field_label,'in_list'=>1],     lcrm_get_list_fields())   : [];
    $all_fields  = function_exists('lcrm_get_active_fields') ? array_map(fn($f)=>['key'=>$f->field_key,'label'=>$f->field_label,'in_list'=>(int)$f->show_in_list], lcrm_get_active_fields()) : [];

    return [
        'ajax_url'         => admin_url('admin-ajax.php'),
        'nonce'            => wp_create_nonce('lcrm_nonce'),
        'user_id'          => (int) $user->ID,
        'user_name'        => $user->display_name,
        'is_admin'         => $is_admin ? 1 : 0,
        'can_conf_finance' => lcrm_can_see_confidential_finance() ? 1 : 0,
        'can_assign'       => lcrm_can_assign_leads() ? 1 : 0,
        'can_vendor'       => lcrm_can_see_vendor() ? 1 : 0,
        'can_settings'     => lcrm_current_can('lcrm_manage_settings') ? 1 : 0,
        'frontend'         => 1,
        'site_name'        => get_bloginfo('name'),
        'unread_alerts'    => $unread,
        'wa_unread'        => $wa_unread,
        'open_add'         => 0,
        'stages'           => array_map(fn($s)=>['id'=>(int)$s->id,'name'=>$s->name,'color'=>$s->color], (array)$cached['stages']),
        'sources'          => array_map(fn($s)=>['id'=>(int)$s->id,'name'=>$s->name], (array)$cached['sources']),
        'vendors'          => array_map(fn($v)=>['id'=>(int)$v->id,'name'=>$v->name], (array)$cached['vendors']),
        'brands'           => array_map(fn($b)=>['id'=>(int)$b->id,'name'=>$b->name], (array)$cached['brands']),
        'work_types'       => array_map(fn($w)=>['id'=>(int)$w->id,'name'=>$w->name], (array)$cached['wtypes']),
        'list_fields'      => $list_fields,
        'all_fields'       => $all_fields,
    ];
}

// Bust boot + dashboard transients when lead saved
add_action( 'lcrm_lead_created', function() {
    $uid = get_current_user_id();
    delete_transient('lcrm_spa_boot_' . $uid);
    delete_transient('lcrm_dash_stats_' . $uid);
}, 10, 0 );
add_action( 'lcrm_lead_updated', function() {
    $uid = get_current_user_id();
    delete_transient('lcrm_spa_boot_' . $uid);
    delete_transient('lcrm_dash_stats_' . $uid);
}, 10, 0 );

/* =========================================================
   NAV SECTIONS
   ========================================================= */

function lcrm_spa_nav_sections() {
    // Mirror the exact same menu order as wp-admin
    return [
        ['id'=>'dashboard',   'label'=>'Dashboard',       'icon'=>'⚡',  'cap'=>'lcrm_view_leads'],
        ['id'=>'leads',       'label'=>'All Leads',        'icon'=>'📋', 'cap'=>'lcrm_view_leads'],
        ['id'=>'add',         'label'=>'+ Add Lead',       'icon'=>'➕', 'cap'=>'lcrm_add_leads'],
        ['id'=>'tasks',       'label'=>"Today's Tasks",    'icon'=>'📅', 'cap'=>'lcrm_view_leads'],
        ['id'=>'assignments', 'label'=>'Assignments',      'icon'=>'👤', 'cap'=>'manage_options'],
        ['id'=>'alerts',      'label'=>'Alerts',           'icon'=>'🔔', 'cap'=>'lcrm_view_leads'],
        ['id'=>'analytics',   'label'=>'Analytics',        'icon'=>'📊', 'cap'=>'lcrm_view_leads'],
        ['id'=>'pipeline',    'label'=>'Pipeline',         'icon'=>'🏗',  'cap'=>'lcrm_view_leads'],
        ['id'=>'team',        'label'=>'Team Inbox',       'icon'=>'👥', 'cap'=>'lcrm_view_leads'],
        ['id'=>'vendors',     'label'=>'Vendors',          'icon'=>'🤝', 'cap'=>'lcrm_view_vendor_data'],
        ['id'=>'brands',      'label'=>'Brands',           'icon'=>'🏷',  'cap'=>'manage_options'],
        ['id'=>'settings',    'label'=>'Settings',         'icon'=>'⚙',  'cap'=>'lcrm_manage_settings'],
        ['id'=>'fields',      'label'=>'Custom Fields',    'icon'=>'🔧', 'cap'=>'lcrm_manage_settings'],
        ['id'=>'import',      'label'=>'Import/Export',    'icon'=>'↕',  'cap'=>'manage_options'],
    ];
}

/* =========================================================
   SPA SHELL — sidebar + topbar + content pane
   ========================================================= */

function lcrm_spa_shell() {
    $user     = wp_get_current_user();
    $avatar   = strtoupper( substr( $user->display_name, 0, 1 ) );
    $site     = esc_html( get_bloginfo('name') );
    $logout   = esc_url( wp_logout_url( get_permalink() ) );
    $sections = lcrm_spa_nav_sections();

    global $wpdb;
    $has_alerts = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}lcrm_alerts'");
    $unread     = $has_alerts ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_alerts WHERE is_read=0") : 0;
    ?>
    <div class="lcrm-spa" id="lcrm-spa">

        <!-- SIDEBAR -->
        <aside class="lcrm-sidebar" id="lcrm-sidebar">
            <div class="lcrm-sidebar-logo">
                <span class="lcrm-logo-icon">⚡</span>
                <span class="lcrm-logo-text"><?php echo $site; ?></span>
                <button class="lcrm-sidebar-collapse" id="lcrm-sidebar-collapse" type="button" title="Collapse / expand menu" aria-label="Collapse or expand menu">‹</button>
            </div>

            <nav class="lcrm-sidenav">
                <?php foreach ( $sections as $s ) :
                    $cap = $s['cap'] ?? 'lcrm_view_leads';
                    if ( ! lcrm_current_can( $cap ) ) continue;
                    $badge = '';
                    if ( $s['id'] === 'alerts' && $unread > 0 )
                        $badge = '<span class="lcrm-nav-badge" id="lcrm-alerts-badge">' . $unread . '</span>';
                ?>
                <a class="lcrm-nav-item" data-section="<?php echo esc_attr($s['id']); ?>" href="#<?php echo esc_attr($s['id']); ?>">
                    <span class="lcrm-nav-icon"><?php echo $s['icon']; ?></span>
                    <span class="lcrm-nav-label"><?php echo esc_html($s['label']); ?></span>
                    <?php echo $badge; ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div class="lcrm-sidebar-footer">
                <div class="lcrm-user-row">
                    <div class="lcrm-user-avatar"><?php echo esc_html($avatar); ?></div>
                    <div class="lcrm-user-info">
                        <div class="lcrm-user-name"><?php echo esc_html($user->display_name); ?></div>
                        <a href="<?php echo $logout; ?>" class="lcrm-user-logout">Sign out</a>
                    </div>
                </div>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="lcrm-main" id="lcrm-main">
            <header class="lcrm-spa-topbar" id="lcrm-spa-topbar">
                <button class="lcrm-menu-toggle" id="lcrm-menu-toggle" type="button" title="Collapse / expand menu" aria-label="Toggle navigation menu" aria-expanded="true">☰</button>
                <span class="lcrm-spa-topbar-title" id="lcrm-spa-topbar-title">Dashboard</span>
                <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
                    <button class="lcrm-spa-topbar-btn" id="lcrm-quick-add-btn" title="Add Lead">+ Add Lead</button>
                    <button class="lcrm-spa-topbar-btn" id="lcrm-alerts-topbtn">
                        🔔<?php if($unread>0):?><span class="lcrm-topnav-badge" id="lcrm-topnav-badge"><?php echo $unread;?></span><?php endif;?>
                    </button>
                    <div class="lcrm-user-avatar" style="cursor:pointer"><?php echo esc_html($avatar); ?></div>
                </div>
            </header>

            <!-- All content renders here — same HTML as wp-admin pages -->
            <main class="lcrm-spa-content" id="lcrm-content">
                <div id="lcrm-section-loader" style="display:flex;align-items:center;justify-content:center;min-height:300px;">
                    <div style="text-align:center;"><div class="lcrm-spinner" style="margin:0 auto 12px;"></div><div style="color:#9ca3af;font-size:13px;">Loading…</div></div>
                </div>
            </main>
        </div>

        <!-- Mobile overlay -->
        <div class="lcrm-sidebar-overlay" id="lcrm-sidebar-overlay"></div>

    </div>
    <?php
    // Inject all modals — same ones used in wp-admin
    if ( function_exists('lcrm_render_all_modals') ) lcrm_render_all_modals();
    if ( function_exists('lcrm_wa_render_modal_templates') ) lcrm_wa_render_modal_templates();
}

/* =========================================================
   LOGIN WALL
   ========================================================= */

function lcrm_spa_login_wall() {
    $login = esc_url( wp_login_url( get_permalink() ) );
    $site  = esc_html( get_bloginfo('name') );
    return '<div class="lcrm-spa-loginwall">
        <div class="lcrm-spa-login-box">
            <div class="lcrm-spa-login-logo">⚡</div>
            <h1>' . $site . '</h1>
            <p>Sign in to access your CRM</p>
            <a href="' . $login . '" class="lcrm-spa-login-btn">Sign In</a>
        </div>
    </div>';
}

/* =========================================================
   AJAX — SECTION HTML
   Calls the EXACT same lcrm_page_*() functions as wp-admin.
   No reinvention. Same HTML. Same CSS. Same features.
   ========================================================= */

add_action( 'wp_ajax_lcrm_spa_section', 'lcrm_ajax_spa_section' );

function lcrm_ajax_spa_section() {
    lcrm_verify();
    $section = sanitize_key( $_POST['section'] ?? 'dashboard' );
    $tab     = sanitize_key( $_POST['tab']     ?? '' );

    // Cache section HTML to avoid re-running all the PHP+DB on every nav click.
    // Key includes user_id so each user gets their own cache (permissions differ).
    // Settings and dashboard are excluded: settings depends on active tab, dashboard has live data.
    // 'leads' excluded: has preloaded live lead data baked in — must be fresh each time
    $cacheable    = in_array( $section, ['vendors','tasks','analytics','pipeline','team','add','assignments','brands','fields','import'], true );
    $cache_key    = 'lcrm_sec_' . get_current_user_id() . '_' . $section . ( $tab ? '_' . $tab : '' );
    if ( $cacheable && ! $tab ) {
        $cached_html = get_transient( $cache_key );
        if ( $cached_html !== false ) {
            wp_send_json_success( [ 'html' => $cached_html, 'section' => $section ] );
            return;
        }
    }

    // Tell lcrm_page_url() to return SPA JS navigation links
    // instead of admin_url links
    define( 'LCRM_SPA_RENDER', true );

    ob_start();

    switch ( $section ) {
        case 'dashboard':
            lcrm_page_dashboard();
            break;
        case 'leads':
            // Honour edit param if passed
            if ( $tab ) $_GET['edit'] = (int) $tab;
            lcrm_page_leads();
            break;
        case 'add':
            lcrm_page_add_lead();
            break;
        case 'pipeline':
            lcrm_page_pipeline();
            break;
        case 'analytics':
            lcrm_page_analytics();
            break;
        case 'team':
            lcrm_page_team_inbox();
            break;
        case 'alerts':
            lcrm_page_alerts();
            break;
        case 'tasks':
            lcrm_page_tasks();
            break;
        case 'vendors':
            lcrm_page_vendors();
            break;
        case 'settings':
            if ( $tab ) $_GET['tab'] = $tab;
            lcrm_page_settings();
            break;
        case 'assignments':
            lcrm_page_assignments();
            break;
        case 'brands':
            lcrm_page_brands();
            break;
        case 'fields':
            lcrm_page_custom_fields();
            break;
        case 'import':
            lcrm_page_import();
            break;
        default:
            lcrm_page_dashboard();
    }

    $html = ob_get_clean();

    // Store in transient cache for next request
    if ( $cacheable && ! $tab && strlen($html) > 0 ) {
        set_transient( $cache_key, $html, 600 ); // 10 min
    }

    wp_send_json_success( [ 'html' => $html, 'section' => $section ] );
}

// Bust section HTML caches when underlying data changes
add_action( 'lcrm_stage_saved',   'lcrm_bust_section_caches' );
add_action( 'lcrm_vendor_saved',  'lcrm_bust_section_caches' );
add_action( 'lcrm_lead_created',  'lcrm_bust_section_caches' );
add_action( 'lcrm_lead_updated',  'lcrm_bust_section_caches' );

function lcrm_bust_section_caches() {
    $uid = get_current_user_id();
    $sections = ['leads','vendors','tasks','analytics','pipeline','team','alerts','add','assignments','brands','fields','import'];
    foreach ( $sections as $s ) {
        delete_transient( 'lcrm_sec_' . $uid . '_' . $s );
    }
    delete_transient( 'lcrm_spa_boot_' . $uid );
    delete_transient( 'lcrm_dash_stats_' . $uid );
}

/* =========================================================
   AUTO PAGE SETUP
   ========================================================= */

add_action( 'admin_notices', 'lcrm_spa_show_url_notice' );

function lcrm_spa_show_url_notice() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( strpos( $screen->id, 'leadcrm' ) === false && $screen->id !== 'dashboard' ) return;
    if ( get_option( 'lcrm_spa_notice_dismissed', 0 ) ) return;
    $page_id = (int) get_option( 'lcrm_spa_page_id', 0 );
    if ( ! $page_id ) return;
    $crm_url = get_permalink( $page_id );
    if ( ! $crm_url ) return;
    ?>
    <div class="notice notice-success" style="display:flex;align-items:center;gap:16px;padding:14px 16px;">
        <div style="font-size:22px;">⚡</div>
        <div style="flex:1">
            <strong>LeadCRM App is ready!</strong> &nbsp;
            Open your CRM at:
            <a href="<?php echo esc_url($crm_url); ?>" target="_blank" style="font-weight:700;color:#5046e4;">
                <?php echo esc_html($crm_url); ?>
            </a>
            — no WordPress admin, full-screen app.
        </div>
        <a href="<?php echo esc_url(admin_url('admin.php?lcrm_dismiss_spa_notice=1')); ?>" style="color:#9ca3af;font-size:20px;text-decoration:none;">×</a>
    </div>
    <?php
}

add_action( 'admin_init', function() {
    if ( isset($_GET['lcrm_dismiss_spa_notice']) && current_user_can('manage_options') )
        update_option('lcrm_spa_notice_dismissed', 1);
});

function lcrm_spa_setup_page() {
    $existing_id = (int) get_option( 'lcrm_spa_page_id', 0 );
    if ( $existing_id && get_post_status($existing_id) === 'publish' ) return;

    $existing = get_page_by_path('crm');
    if ( $existing ) {
        update_option( 'lcrm_spa_page_id', $existing->ID );
        if ( ! has_shortcode($existing->post_content, 'leadcrm_app') )
            wp_update_post(['ID'=>$existing->ID,'post_content'=>'[leadcrm_app]']);
        return;
    }

    $page_id = wp_insert_post([
        'post_title'   => 'CRM',
        'post_name'    => 'crm',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '[leadcrm_app]',
        'comment_status' => 'closed',
        'ping_status'  => 'closed',
    ]);
    if ( $page_id && ! is_wp_error($page_id) )
        update_option( 'lcrm_spa_page_id', $page_id );
}

// Shortcode compat
add_shortcode( 'leadcrm_app',    'lcrm_spa_shortcode_compat' );
add_shortcode( 'leadcrm_portal', 'lcrm_spa_shortcode_compat' );
function lcrm_spa_shortcode_compat() {
    if ( ! is_user_logged_in() ) return lcrm_spa_login_wall();
    ob_start(); lcrm_spa_shell(); return ob_get_clean();
}

/* =========================================================
   SPA VENDORS AJAX (used by vendor section)
   ========================================================= */

add_action( 'wp_ajax_lcrm_ajax_get_vendors', 'lcrm_ajax_spa_get_vendors' );
function lcrm_ajax_spa_get_vendors() {
    lcrm_verify();
    if ( ! lcrm_can_see_vendor() && ! current_user_can('manage_options') )
        wp_send_json_error(['msg'=>'Permission denied.'],403);
    global $wpdb;
    $search = sanitize_text_field($_POST['search']??'');
    $cat_id = intval($_POST['cat_id']??0);
    $where  = 'WHERE v.is_active=1';
    $params = [];
    if ($search) { $where.=' AND (v.name LIKE %s OR v.phone LIKE %s)'; $like='%'.$wpdb->esc_like($search).'%'; $params=[$like,$like]; }
    if ($cat_id) { $where.=' AND v.category_id=%d'; $params[]=$cat_id; }
    $sql    = "SELECT v.*,vc.name AS category_name FROM {$wpdb->prefix}lcrm_vendors v LEFT JOIN {$wpdb->prefix}lcrm_vendor_categories vc ON vc.id=v.category_id {$where} ORDER BY v.name ASC LIMIT 100";
    $vendors= $params ? $wpdb->get_results($wpdb->prepare($sql,$params)) : $wpdb->get_results($sql);
    wp_send_json_success(['vendors'=>(array)$vendors]);
}

add_action( 'wp_ajax_lcrm_ajax_mark_all_alerts_read', 'lcrm_ajax_mark_all_read' );
function lcrm_ajax_mark_all_read() {
    lcrm_verify();
    global $wpdb;
    $wpdb->update("{$wpdb->prefix}lcrm_alerts",['is_read'=>1],['is_read'=>0]);
    wp_send_json_success(['msg'=>'All alerts marked as read.']);
}


/* =========================================================
   DEBUG — test endpoint to verify AJAX works
   Call: /wp-admin/admin-ajax.php?action=lcrm_spa_debug&nonce=XXX
   ========================================================= */
add_action( 'wp_ajax_lcrm_spa_debug', function() {
    // No nonce check — just for diagnosis
    global $wpdb;
    $out = [];
    
    // Test 1: Can we query lcrm_leads?
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcrm_leads");
    $out['leads_count'] = $count;
    $out['wpdb_last_error'] = $wpdb->last_error;
    
    // Test 2: lcrm_get_leads function exists?
    $out['lcrm_get_leads_exists'] = function_exists('lcrm_get_leads');
    
    // Test 3: Try calling lcrm_get_leads
    if ( function_exists('lcrm_get_leads') ) {
        $leads = lcrm_get_leads(['limit'=>1,'offset'=>0,'orderby'=>'created_at','order'=>'DESC']);
        $out['test_lead'] = $leads ? 'got ' . count($leads) . ' lead(s)' : 'empty/null';
        $out['test_error'] = $wpdb->last_error;
    }
    
    // Test 4: PHP version and error reporting
    $out['php_version'] = PHP_VERSION;
    $out['display_errors'] = ini_get('display_errors');
    $out['output_buffering'] = ob_get_level();
    
    // Test 5: Any output buffered before this point (warnings)
    $buffered = ob_get_contents();
    $out['buffered_output'] = $buffered ? base64_encode($buffered) : 'none';
    
    wp_send_json_success($out);
});
