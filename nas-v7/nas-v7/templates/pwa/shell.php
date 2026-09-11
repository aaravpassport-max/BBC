<?php
/**
 * NAS PWA + Desktop Shell v2.0
 * MOBILE  (<768px): App-like bottom-tab shell  (marketplace-os CustomerLayout)
 * DESKTOP (>=768px): Full marketplace site     (marketplace-os DesktopLayout + HomeDesktop)
 * THEMING: Three-layer zero-flicker — same as marketplace-os
 */
if ( ! defined( 'ABSPATH' ) ) exit;
use NAS\Modules\PWA\NASTheme;
use NAS\Core\{Config, Database};

$cfg      = Config::instance();
$db       = Database::instance();
$nonce    = wp_create_nonce('nas_action');
$is_li    = is_user_logged_in();
$wp_user  = $is_li ? wp_get_current_user() : null;
$u_name   = $is_li ? esc_js($wp_user->display_name) : '';
$u_init   = ($is_li && $wp_user->display_name) ? strtoupper($wp_user->display_name[0]) : '';

$book_url   = esc_url( nas_get_page_url('nas_page_booking','/book-newspaper-ad/') );
$dash_url   = esc_url( nas_get_page_url('nas_page_client_dashboard','/client-dashboard/') );
$login_url  = esc_url( nas_get_page_url('nas_page_login','/newspaper-ad-login/') );
$logout_url = wp_logout_url( home_url('/nas-app/') );
$home_url   = home_url('/');
$brand    = esc_js( $cfg->get('brand_name', get_bloginfo('name')) );
$tagline  = esc_js( $cfg->get('brand_tagline','Book newspaper ads in minutes') );
$logo_url = esc_url( $cfg->get('logo_url','') );
$phone    = esc_js( $cfg->get('brand_phone','') );
$currency = esc_js( $cfg->get('currency_symbol','₹') );

// LAYER 1+2: PHP theme build
$pwa_theme  = $cfg->get('pwa_theme','green');
$pwa_bg     = $cfg->get('pwa_portal_bg','#0e1117');
$pwa_accent = $cfg->get('pwa_accent_color','');
$pwa_btn    = $cfg->get('pwa_button_color','');
$theme_css  = NASTheme::build_theme_css($pwa_theme,$pwa_bg,$pwa_accent,$pwa_btn);
$palette    = NASTheme::palette($pwa_theme);
$primary    = esc_attr($pwa_accent ?: $palette['primary']);

$cities_raw = $db->select("SELECT name FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC,name ASC LIMIT 30");
$cities     = array_column($cities_raw ?: [], 'name');
if(empty($cities)) $cities = ['Delhi','Mumbai','Kolkata','Chennai','Bangalore','Hyderabad'];
$tb = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE status NOT IN ('cancelled','rejected')") ?? 0);
$tp = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('newspapers')}` WHERE is_active=1") ?? 0);
$tc = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('cities')}` WHERE is_active=1") ?? 0);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="theme-color" content="<?php echo $primary; ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($brand); ?>">
<meta name="description" content="<?php echo esc_attr($tagline); ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?php echo esc_attr($brand); ?>">
<meta property="og:title" content="<?php echo esc_attr($brand); ?> — Book Newspaper Ads Online">
<meta property="og:description" content="<?php echo esc_attr($tagline); ?>">
<meta property="og:url" content="<?php echo esc_url(home_url('/nas-app/')); ?>">
<?php if($logo_url): ?><meta property="og:image" content="<?php echo esc_url($logo_url); ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo esc_attr($brand); ?>">
<meta name="twitter:description" content="<?php echo esc_attr($tagline); ?>">
<?php if($logo_url): ?><meta name="twitter:image" content="<?php echo esc_url($logo_url); ?>"><?php endif; ?>
<link rel="canonical" href="<?php echo esc_url(home_url('/nas-app/')); ?>">
<title><?php echo esc_html($brand); ?> — Book Newspaper Ads Online</title>
<link rel="manifest" href="/nas-app/manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">

<!-- JSON-LD: Structured data for search engines -->
<script type="application/ld+json">
<?php echo wp_json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'WebApplication',
    'name'        => $brand,
    'description' => $tagline,
    'url'         => home_url('/nas-app/'),
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem'     => 'All',
    'offers'      => ['@type'=>'Offer','price'=>'0','priceCurrency'=>'INR'],
    'author'      => ['@type'=>'Organization','name'=>$brand,'url'=>home_url('/')],
    'screenshot'  => $logo_url ?: '',
]); ?>
</script>

<!-- LAYER 1: Zero-flicker — PHP :root{} injected BEFORE any stylesheet -->
<style id="nas-theme"><?php echo $theme_css; ?></style>

<!-- NAS_CONFIG — Layer 3 JS theme engine + runtime config -->
<script>
window.NAS_CONFIG = {
  ajax:<?php echo wp_json_encode( function_exists('nas_get_ajax_url') ? nas_get_ajax_url() : home_url('/nas-app/') ); ?>,
  nonce:<?php echo wp_json_encode($nonce); ?>,
  isLoggedIn:<?php echo $is_li?'true':'false'; ?>,
  isAdmin:<?php echo (current_user_can('manage_options')?'true':'false'); ?>,
  themeSettingsUrl:<?php echo wp_json_encode(admin_url('admin.php?page=nas-pwa-settings')); ?>,
  userId:<?php echo (int)get_current_user_id(); ?>,
  userName:<?php echo wp_json_encode($u_name); ?>,
  bookingUrl:<?php echo wp_json_encode($book_url); ?>,
  dashUrl:<?php echo wp_json_encode($dash_url); ?>,
  loginUrl:<?php echo wp_json_encode($login_url); ?>,
  logoutUrl:<?php echo wp_json_encode($logout_url); ?>,
  homeUrl:<?php echo wp_json_encode($home_url); ?>,
  currency:<?php echo wp_json_encode($currency); ?>,
  brand:<?php echo wp_json_encode($brand); ?>,
  tagline:<?php echo wp_json_encode($tagline); ?>,
  logo:<?php echo wp_json_encode($logo_url); ?>,
  phone:<?php echo wp_json_encode($phone); ?>,
  cities:<?php echo wp_json_encode($cities); ?>,
  theme:<?php echo wp_json_encode($pwa_theme); ?>,
  themePalette:<?php echo wp_json_encode($palette); ?>,
  accentColor:<?php echo wp_json_encode($pwa_accent ?: $palette['primary']); ?>,
  buttonColor:<?php echo wp_json_encode($pwa_btn ?: $palette['primary']); ?>,
  portalBg:<?php echo wp_json_encode($pwa_bg); ?>,
  themes:<?php echo wp_json_encode(NASTheme::palettes()); ?>,
  bgPresets:<?php echo wp_json_encode(NASTheme::bg_presets()); ?>,
  stats:{bookings:<?php echo $tb;?>,newspapers:<?php echo $tp;?>,cities:<?php echo $tc;?>}
};
</script>
<style>
/* === NAS DESIGN SYSTEM — mirrors marketplace-os CSS vars & tokens === */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{background:var(--surface-950);scroll-behavior:smooth}
body{background:var(--surface-950);color:var(--ink-primary);font-family:'Inter',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;min-height:100vh}
::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px}
input,select,textarea,button{font-family:inherit;outline:none}a{text-decoration:none;color:inherit}
#nas-app{min-height:100vh;background:var(--surface-950)}
/* breakpoint */
@media(min-width:768px){.mob-only{display:none!important}}
@media(max-width:767px){.desk-only{display:none!important}}
/* animations */
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes spin{to{transform:rotate(360deg)}}
.anim-fade-up{animation:fadeUp .22s ease both}
.anim-slide-down{animation:slideDown .2s ease both}
.shimmer{background:linear-gradient(90deg,var(--surface-800) 25%,var(--surface-700) 50%,var(--surface-800) 75%);background-size:200%;animation:shimmer 1.4s infinite}
/* === MOBILE LAYOUT === */
.mob-shell{display:flex;flex-direction:column;min-height:100vh}
.mob-topbar{position:sticky;top:0;z-index:40;height:56px;background:var(--surface-900);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;padding-top:env(safe-area-inset-top)}
.mob-logo{display:flex;align-items:center;gap:8px;cursor:pointer}
.mob-logo-icon{width:30px;height:30px;border-radius:8px;background:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:15px;color:#060c18;font-weight:900;flex-shrink:0}
.mob-logo-name{font-weight:800;font-size:15px;color:var(--ink-primary)}
.mob-actions{display:flex;align-items:center;gap:8px}
.mob-icon-btn{width:34px;height:34px;border-radius:10px;background:none;border:none;color:var(--ink-muted);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;position:relative}
.mob-icon-btn:hover{background:var(--surface-800)}
.mob-badge-dot{position:absolute;top:5px;right:5px;width:7px;height:7px;border-radius:50%;background:var(--color-primary)}
.mob-avatar{width:32px;height:32px;border-radius:50%;background:color-mix(in srgb,var(--color-primary) 20%,transparent);border:1.5px solid color-mix(in srgb,var(--color-primary) 30%,transparent);color:var(--color-primary);font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;cursor:pointer}
.mob-screen{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding-bottom:calc(60px + env(safe-area-inset-bottom) + 8px)}
.mob-tabs{position:fixed;bottom:0;left:0;right:0;z-index:40;height:calc(60px + env(safe-area-inset-bottom));padding-bottom:env(safe-area-inset-bottom);background:var(--surface-900);border-top:1px solid var(--border);display:flex;align-items:stretch}
.mob-tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;border:none;background:none;color:var(--ink-muted);font-size:10px;font-weight:600;font-family:inherit;transition:color .15s;padding:0 4px}
.mob-tab .ti{font-size:19px;display:block;transition:transform .15s}
.mob-tab.active{color:var(--color-primary)}
.mob-tab.active .ti{transform:scale(1.1)}
/* === DESKTOP LAYOUT === */
.desk-topbar{position:fixed;top:0;left:0;right:0;z-index:50;height:64px;background:var(--surface-900);border-bottom:1px solid var(--border);display:flex;align-items:center}
.desk-topbar-inner{max-width:1280px;margin:0 auto;width:100%;padding:0 24px;display:flex;align-items:center;gap:20px}
.desk-logo{display:flex;align-items:center;gap:10px;cursor:pointer;flex-shrink:0;text-decoration:none}
.desk-logo-icon{width:36px;height:36px;border-radius:10px;background:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:18px;color:#060c18;font-weight:900}
.desk-logo-name{font-weight:800;font-size:17px;color:var(--ink-primary)}
.desk-nav{display:flex;align-items:center;gap:2px;flex:1}
.desk-nav-link{padding:8px 14px;border-radius:10px;font-size:14px;font-weight:500;color:var(--ink-muted);cursor:pointer;border:none;background:none;transition:all .15s;font-family:inherit;white-space:nowrap}
.desk-nav-link:hover{color:var(--ink-secondary);background:var(--surface-800)}
.desk-nav-link.active{color:var(--color-primary)}
.desk-search-bar{display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1px solid var(--border);border-radius:12px;padding:9px 14px;transition:all .2s;width:180px}
.desk-search-bar:focus-within{border-color:var(--color-primary);width:260px}
.desk-search-bar input{background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;width:100%}
.desk-search-bar input::placeholder{color:var(--ink-muted)}
.desk-right{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}
.btn-ghost{padding:8px 18px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:none;color:var(--ink-secondary);font-family:inherit;transition:all .15s}
.btn-ghost:hover{background:var(--surface-800)}
.btn-primary{padding:9px 20px;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;border:none;background:var(--color-button,var(--color-primary));color:#060c18;font-family:inherit;transition:opacity .15s}
.btn-primary:hover{opacity:.9}
.desk-av{width:34px;height:34px;border-radius:50%;background:color-mix(in srgb,var(--color-primary) 20%,transparent);border:1.5px solid color-mix(in srgb,var(--color-primary) 30%,transparent);color:var(--color-primary);font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;cursor:pointer}
.desk-user-dd{position:absolute;right:0;top:calc(100% + 8px);width:200px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:6px;box-shadow:0 8px 32px rgba(0,0,0,.4);animation:slideDown .2s ease;z-index:100}
.dd-item{width:100%;text-align:left;padding:10px 14px;font-size:13px;border-radius:10px;border:none;background:none;color:var(--ink-secondary);cursor:pointer;font-family:inherit;display:block;transition:background .1s;text-decoration:none}
.dd-item:hover{background:var(--surface-700);color:var(--ink-primary)}
.dd-item.danger{color:#f87171}.dd-item.danger:hover{background:rgba(239,68,68,.1)}
.mega-panel{position:fixed;top:64px;left:0;right:0;z-index:40;background:var(--surface-900);border-bottom:1px solid var(--border);box-shadow:0 8px 32px rgba(0,0,0,.4);animation:slideDown .2s ease}
.mega-inner{max-width:1280px;margin:0 auto;padding:24px}
.mega-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-muted);margin-bottom:14px}
.mega-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
.mega-card{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 8px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all .15s}
.mega-card:hover{border-color:var(--color-primary);transform:translateY(-2px)}
.mega-card span:first-child{font-size:26px}.mega-card span:last-child{font-size:11px;font-weight:600;color:var(--ink-secondary);text-align:center;line-height:1.3}
.desk-sidebar{position:fixed;left:0;top:64px;bottom:0;width:224px;background:var(--surface-900);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:30;overflow-y:auto}
.sb-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-muted);padding:16px 16px 8px}
.sb-link{display:flex;align-items:center;gap:10px;padding:10px 14px;margin:1px 8px;border-radius:11px;font-size:13px;font-weight:500;color:var(--ink-muted);cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none}
.sb-link:hover{background:var(--surface-800);color:var(--ink-secondary)}
.sb-link.active{background:color-mix(in srgb,var(--color-primary) 12%,transparent);color:var(--color-primary);border-color:color-mix(in srgb,var(--color-primary) 25%,transparent)}
.sb-footer{margin-top:auto;padding:12px;border-top:1px solid var(--border)}
.sb-user{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface-800);border-radius:11px;margin-bottom:6px}
.sb-av{width:30px;height:30px;border-radius:50%;background:color-mix(in srgb,var(--color-primary) 20%,transparent);color:var(--color-primary);font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.desk-main{padding-top:64px;min-height:100vh}
.desk-main.with-sb{margin-left:224px}
.desk-content{max-width:1280px;margin:0 auto;padding:28px 24px}
/* Shared components */
.n-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;font-weight:700;cursor:pointer;border:none;transition:all .15s;white-space:nowrap}
.n-btn-p{background:var(--color-button,var(--color-primary));color:#060c18;border-radius:12px;padding:12px 24px;font-size:14px}
.n-btn-p:hover{opacity:.9;transform:translateY(-1px)}.n-btn-p:active{transform:scale(.98)}
.n-btn-sm{padding:8px 16px;font-size:13px;border-radius:9px}
.n-btn-g{background:var(--surface-800);border:1px solid var(--border);color:var(--ink-secondary);border-radius:12px;padding:10px 20px;font-size:14px}
.n-btn-g:hover{background:var(--surface-700);color:var(--ink-primary)}
.n-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important}
.cat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
@media(min-width:768px){.cat-grid{grid-template-columns:repeat(6,1fr);gap:14px}}
.cat-card{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 8px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;cursor:pointer;transition:all .15s}
.cat-card:hover{border-color:color-mix(in srgb,var(--color-primary) 40%,transparent);transform:translateY(-2px)}
.cat-card:active{transform:scale(.96)}
.cat-emoji{font-size:26px}@media(min-width:768px){.cat-emoji{font-size:30px}}
.cat-name{font-size:10px;font-weight:600;color:var(--ink-secondary);text-align:center;line-height:1.3}
@media(min-width:768px){.cat-name{font-size:11px}}
.np-card{display:flex;align-items:center;gap:14px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:14px;cursor:pointer;transition:all .15s}
.np-card:hover{border-color:color-mix(in srgb,var(--color-primary) 40%,transparent);transform:translateY(-1px)}
.np-logo{width:50px;height:50px;border-radius:10px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border);flex-shrink:0}
.np-logo-ph{width:50px;height:50px;border-radius:10px;background:var(--surface-700);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.np-info{flex:1;min-width:0}
.np-cat{font-size:11px;font-weight:600;color:var(--color-primary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
.np-name{font-size:15px;font-weight:700;color:var(--ink-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.np-meta{font-size:12px;color:var(--ink-muted);margin-top:2px}
.np-right{flex-shrink:0;text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.np-price{font-size:18px;font-weight:800;color:var(--color-primary)}
.np-desk{background:var(--surface-800);border:1px solid var(--border);border-radius:18px;padding:20px;cursor:pointer;transition:all .15s}
.np-desk:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.city-scroll{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;padding-bottom:4px}
.city-scroll::-webkit-scrollbar{display:none}
.city-chip{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:99px;font-size:13px;font-weight:600;background:var(--surface-800);border:1px solid var(--border);color:var(--ink-secondary);cursor:pointer;white-space:nowrap;transition:all .15s;font-family:inherit}
.city-chip:hover,.city-chip.active{background:color-mix(in srgb,var(--color-primary) 12%,transparent);border-color:color-mix(in srgb,var(--color-primary) 30%,transparent);color:var(--color-primary)}
.n-divider{height:1px;background:var(--border)}
.how-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
@media(min-width:768px){.how-grid{grid-template-columns:repeat(4,1fr);gap:24px}}
.how-num{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;margin-bottom:14px;border:1px solid color-mix(in srgb,var(--color-primary) 25%,transparent);background:color-mix(in srgb,var(--color-primary) 10%,transparent);color:var(--color-primary)}
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;text-align:center}
@media(min-width:768px){.stats-grid{grid-template-columns:repeat(4,1fr)}}
.stat-num{font-size:36px;font-weight:900;color:var(--color-primary);line-height:1}
.stat-lbl{font-size:13px;color:var(--ink-muted);margin-top:4px}
.desk-footer{border-top:1px solid var(--border);background:var(--surface-900)}
.footer-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:32px;margin-bottom:32px}
.footer-h{font-size:13px;font-weight:700;color:var(--ink-primary);margin-bottom:14px}
.footer-lnk{display:block;font-size:13px;color:var(--ink-muted);margin-bottom:8px;cursor:pointer;transition:color .15s;text-decoration:none}
.footer-lnk:hover{color:var(--ink-secondary)}
.section{padding:48px 16px}@media(min-width:768px){.section{padding:64px 24px}}
.max-w{max-width:1280px;margin:0 auto}
.flex-b{display:flex;align-items:center;justify-content:space-between}
.sec-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--color-primary);margin-bottom:8px;display:block}
.sec-ttl{font-size:26px;font-weight:800;color:var(--ink-primary);line-height:1.2}
@media(min-width:1024px){.sec-ttl{font-size:32px}}
.n-loading{display:flex;align-items:center;justify-content:center;padding:48px}
.n-spinner{width:22px;height:22px;border:2.5px solid var(--border);border-top-color:var(--color-primary);border-radius:50%;animation:spin .7s linear infinite;display:inline-block}
.n-empty{text-align:center;padding:48px 20px}
.n-empty-icon{font-size:48px;opacity:.3;display:block;margin-bottom:12px}
.n-empty-title{font-size:17px;font-weight:700;color:var(--ink-primary);margin-bottom:6px}
.n-empty-sub{font-size:13px;color:var(--ink-muted);margin-bottom:18px;line-height:1.6}
.nbadge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.nbadge.booking_received,.nbadge.under_review{background:rgba(251,191,36,.15);color:#fbbf24}
.nbadge.payment_received{background:color-mix(in srgb,var(--color-primary) 15%,transparent);color:var(--color-primary)}
.nbadge.ad_processing,.nbadge.proof_ready{background:rgba(129,140,248,.15);color:#818cf8}
.nbadge.published,.nbadge.completed{background:color-mix(in srgb,var(--color-primary) 15%,transparent);color:var(--color-primary)}
.nbadge.rejected,.nbadge.cancelled{background:rgba(248,113,113,.15);color:#f87171}
.n-overlay{position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);display:flex;align-items:flex-end;opacity:0;pointer-events:none;transition:opacity .25s}
.n-overlay.open{opacity:1;pointer-events:all}
.n-sheet{background:var(--surface-900);border-radius:22px 22px 0 0;width:100%;max-height:88vh;overflow-y:auto;transform:translateY(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);padding-bottom:calc(24px + env(safe-area-inset-bottom))}
.n-overlay.open .n-sheet{transform:translateY(0)}
.n-sheet-handle{width:40px;height:4px;background:var(--surface-700);border-radius:99px;margin:12px auto 20px}
.n-toasts{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:9999;pointer-events:none;display:flex;flex-direction:column;gap:8px;align-items:center;width:90%;max-width:400px}
@media(min-width:768px){.n-toasts{bottom:24px}}
.n-toast{background:var(--surface-800);border:1px solid var(--border);color:var(--ink-primary);padding:12px 18px;border-radius:14px;font-size:13px;font-weight:600;pointer-events:all;box-shadow:0 4px 24px rgba(0,0,0,.5);transform:translateY(16px);opacity:0;transition:all .3s;text-align:center;border-left:4px solid var(--border)}
.n-toast.in{transform:translateY(0);opacity:1}
.n-toast.success{border-left-color:var(--color-primary)}.n-toast.error{border-left-color:#f87171}
.n-pages{display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap}
.n-page-btn{background:var(--surface-800);border:1px solid var(--border);color:var(--ink-muted);border-radius:9px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s}
.n-page-btn.active,.n-page-btn:hover{background:var(--color-primary);color:#060c18;border-color:var(--color-primary)}
.n-input{width:100%;padding:12px 14px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:12px;color:var(--ink-primary);font-size:14px;font-family:inherit;transition:border-color .15s}
.n-input:focus{border-color:var(--color-primary)}.n-input::placeholder{color:var(--ink-muted)}
.n-field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.n-lbl{font-size:12px;font-weight:600;color:var(--ink-secondary);text-transform:uppercase;letter-spacing:.04em}
.n-err{font-size:12px;color:#f87171;display:none}.n-err.show{display:block}
.n-input.invalid{border-color:#f87171}
</style>
</head>
<body>
<div id="nas-app">

<!-- MOBILE SHELL (<768px) — Bottom tab app (marketplace-os CustomerLayout) -->
<div class="mob-shell mob-only" id="nas-mobile">
  <header class="mob-topbar">
    <div class="mob-logo" onclick="NAS.goto('home')">
      <div class="mob-logo-icon" id="mob-logo-icon">📰</div>
      <span class="mob-logo-name" id="mob-logo-name">NAS</span>
    </div>
    <div class="mob-actions">
      <button class="mob-icon-btn" onclick="NAS.goto('bookings')" aria-label="Notifications">
        🔔<span class="mob-badge-dot" id="mob-badge" style="display:none"></span>
      </button>
      <?php if($is_li): ?>
        <div class="mob-avatar" onclick="NAS.goto('account')"><?php echo esc_html($u_init ?: '👤'); ?></div>
      <?php else: ?>
        <button class="n-btn n-btn-g n-btn-sm" onclick="NAS.goto('account')" style="padding:6px 12px;font-size:12px">Sign In</button>
      <?php endif; ?>
    </div>
  </header>
  <main class="mob-screen" id="mob-screen">
    <div class="n-loading"><span class="n-spinner"></span></div>
  </main>
  <nav class="mob-tabs" role="navigation" aria-label="Main navigation">
    <button class="mob-tab active" id="tab-home"     onclick="NAS.goto('home')"     aria-label="Home">     <span class="ti">🏠</span>Home</button>
    <button class="mob-tab"        id="tab-browse"   onclick="NAS.goto('browse')"   aria-label="Browse">   <span class="ti">🔍</span>Browse</button>
    <button class="mob-tab"        id="tab-book"     onclick="NAS.goto('book')"     aria-label="Book">     <span class="ti">📋</span>Book</button>
    <button class="mob-tab"        id="tab-bookings" onclick="NAS.goto('bookings')" aria-label="Bookings"> <span class="ti">📦</span>Bookings</button>
    <button class="mob-tab"        id="tab-account"  onclick="NAS.goto('account')"  aria-label="Account">  <span class="ti">👤</span>Account</button>
  </nav>
</div>

<!-- DESKTOP SHELL (>=768px) — Full marketplace site (marketplace-os DesktopLayout + HomeDesktop) -->
<div class="desk-only" id="nas-desktop">
  <!-- Fixed topbar — marketplace-os TopBar component -->
  <header class="desk-topbar">
    <div class="desk-topbar-inner">
      <a class="desk-logo" href="<?php echo esc_url(home_url('/nas-app/')); ?>">
        <div class="desk-logo-icon" id="desk-logo-icon">📰</div>
        <span class="desk-logo-name" id="desk-logo-name">NAS</span>
      </a>
      <nav class="desk-nav">
        <button class="desk-nav-link active" id="dnl-home"   onclick="NAS.deskGoto('home')">Home</button>
        <div style="position:relative" id="mega-trigger"
             onmouseenter="NAS.openMega()" onmouseleave="NAS.closeMega()">
          <button class="desk-nav-link" style="display:flex;align-items:center;gap:4px;font-family:inherit">
            Ad Types <span id="mega-arrow" style="font-size:11px;transition:transform .2s">▾</span>
          </button>
        </div>
        <button class="desk-nav-link" id="dnl-browse"  onclick="NAS.deskGoto('browse')">Browse Newspapers</button>
        <button class="desk-nav-link" id="dnl-cities"  onclick="NAS.deskGoto('cities')">Cities</button>
        <button class="desk-nav-link" id="dnl-rates"   onclick="NAS.deskGoto('rates')">Rate Card</button>
        <button class="desk-nav-link" id="dnl-blog"    onclick="NAS.deskGoto('blog')">Blog</button>
        <button class="desk-nav-link" id="dnl-faq"     onclick="NAS.deskGoto('faq')">FAQ</button>
        <button class="desk-nav-link" id="dnl-contact" onclick="NAS.deskGoto('contact')">Contact</button>
        <?php if($is_li): ?>
          <button class="desk-nav-link" id="dnl-portal" onclick="NAS.deskGoto('portal')">My Account</button>
        <?php endif; ?>
      </nav>
      <div class="desk-search-bar">
        <span style="color:var(--ink-muted);font-size:14px;flex-shrink:0">🔍</span>
        <input id="desk-search-inp" placeholder="Search newspaper, city…" autocomplete="off"
               onkeydown="if(event.key==='Enter')NAS.deskSearch()" aria-label="Search">
      </div>
      <div class="desk-right">
        <?php if($is_li): ?>
          <button class="mob-icon-btn" style="width:38px;height:38px;border-radius:11px;background:var(--surface-800);border:1px solid var(--border)" onclick="NAS.deskGoto('portal')">
            🔔<span class="mob-badge-dot" id="desk-badge" style="display:none"></span>
          </button>
          <div style="position:relative" id="desk-user-wrap">
            <button class="desk-av" onclick="NAS.toggleUserMenu()" aria-label="Account menu">
              <?php echo esc_html($u_init ?: '👤'); ?>
            </button>
            <div class="desk-user-dd" id="desk-user-menu" style="display:none">
              <div style="padding:10px 14px;border-bottom:1px solid var(--border);margin-bottom:4px">
                <div style="font-size:13px;font-weight:700;color:var(--ink-primary)"><?php echo esc_html($wp_user ? $wp_user->display_name : ''); ?></div>
                <div style="font-size:11px;color:var(--ink-muted)"><?php echo esc_html($wp_user ? $wp_user->user_email : ''); ?></div>
              </div>
              <a class="dd-item" href="<?php echo esc_url($dash_url); ?>">📊 Full Dashboard</a>
              <button class="dd-item" onclick="NAS.deskGoto('portal')">📦 My Bookings</button>
              <?php if(current_user_can('manage_options')): ?>
              <div style="height:1px;background:var(--border);margin:4px 0"></div>
              <a class="dd-item" href="<?php echo esc_url(admin_url('admin.php?page=nas-pwa-settings')); ?>">🎨 Theme &amp; App Settings</a>
              <?php endif; ?>
              <div style="height:1px;background:var(--border);margin:4px 0"></div>
              <a class="dd-item danger" href="<?php echo esc_url($logout_url); ?>">🚪 Sign Out</a>
            </div>
          </div>
        <?php else: ?>
          <a class="btn-ghost" href="<?php echo esc_url($login_url); ?>">Login</a>
          <button class="btn-primary" onclick="window.location.href='<?php echo esc_js($book_url); ?>'">Book an Ad</button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Mega menu — categories dropdown (marketplace-os pattern) -->
  <div class="mega-panel" id="mega-menu" style="display:none"
       onmouseenter="NAS.openMega()" onmouseleave="NAS.closeMega()">
    <div class="mega-inner">
      <div class="mega-lbl">Ad Categories</div>
      <div class="mega-grid" id="mega-cats"></div>
    </div>
  </div>

  <!-- Main content area — JS renders pages here -->
  <main class="desk-main" id="desk-main">
    <div class="n-loading" style="padding:120px 0"><span class="n-spinner"></span></div>
  </main>

  <!-- Footer — shown on public pages -->
  <footer class="desk-footer" id="desk-footer" style="display:none">
    <div class="max-w" style="padding:48px 24px 32px">
      <div class="footer-grid">
        <div>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
            <div class="desk-logo-icon" style="width:34px;height:34px;font-size:16px">📰</div>
            <span style="font-weight:800;color:var(--ink-primary)" id="footer-brand">NAS</span>
          </div>
          <p style="font-size:13px;color:var(--ink-muted);line-height:1.6;margin-bottom:12px" id="footer-tagline"></p>
          <p style="font-size:12px;color:var(--ink-muted)" id="footer-phone"></p>
        </div>
        <div>
          <div class="footer-h">Ad Categories</div>
          <?php foreach(['Classified Ads','Display Ads','Obituary & In Memoriam','Matrimonial','Property & Real Estate','Jobs & Recruitment','Legal Notices','Public Notices'] as $fl): ?>
            <a class="footer-lnk" href="<?php echo esc_url($book_url); ?>"><?php echo esc_html($fl); ?></a>
          <?php endforeach; ?>
        </div>
        <div>
          <div class="footer-h">Quick Links</div>
          <a class="footer-lnk" href="<?php echo esc_url($book_url); ?>">Book an Ad</a>
          <a class="footer-lnk" href="<?php echo esc_url(home_url('/track-order/')); ?>">Track Your Ad</a>
          <a class="footer-lnk" href="<?php echo esc_url($is_li ? $dash_url : $login_url); ?>"><?php echo $is_li ? 'My Dashboard' : 'Client Login'; ?></a>
          <a class="footer-lnk" href="<?php echo esc_url(home_url('/faq/')); ?>">FAQs</a>
          <a class="footer-lnk" href="<?php echo esc_url(home_url('/contact-us/')); ?>">Contact Us</a>
          <span class="footer-lnk" onclick="NAS.deskGoto('rates')">Rate Card</span>
          <span class="footer-lnk" onclick="NAS.deskGoto('blog')">Blog</span>
          <span class="footer-lnk" onclick="NAS.deskGoto('faq')">FAQ</span>
          <a class="footer-lnk" href="<?php echo esc_url(home_url('/blog/')); ?>">Blog</a>
        </div>
        <div>
          <div class="footer-h">Top Cities</div>
          <?php foreach(array_slice($cities,0,8) as $ct): ?>
            <span class="footer-lnk" onclick="NAS.deskBrowseCity('<?php echo esc_js($ct); ?>')">📍 <?php echo esc_html($ct); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="padding-top:24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <p style="font-size:12px;color:var(--ink-muted)">© <?php echo date('Y'); ?> <?php echo esc_html($brand); ?>. All rights reserved.</p>
        <div style="display:flex;gap:16px">
          <?php foreach(['Privacy Policy','Terms of Service','Refund Policy'] as $pl): ?>
            <span style="font-size:12px;color:var(--ink-muted);cursor:pointer"><?php echo esc_html($pl); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </footer>
</div><!-- /nas-desktop -->
</div><!-- #nas-app -->

<div class="n-toasts" id="n-toasts" aria-live="polite"></div>
<div class="n-overlay" id="n-overlay" onclick="NAS.closeSheet()">
  <div class="n-sheet" id="n-sheet" onclick="event.stopPropagation()">
    <div class="n-sheet-handle"></div>
    <div id="n-sheet-body"></div>
  </div>
</div>
<script>
(function(){
'use strict';
const C=window.NAS_CONFIG;

/* === LAYER 3 THEME — exact port of marketplace-os lib/theme.js === */
const THEMES=C.themes||{};
function applyTheme(p){
  if(!p)return;const r=document.documentElement;
  r.style.setProperty('--color-primary',p.primary);r.style.setProperty('--color-secondary',p.secondary);
  r.style.setProperty('--color-accent',p.accent);r.style.setProperty('--surface-950',p.surface_950);
  r.style.setProperty('--surface-900',p.surface_900);r.style.setProperty('--surface-800',p.surface_800);
  r.style.setProperty('--surface-700',p.surface_700);r.style.setProperty('--border',p.border);
  r.style.setProperty('--ink-primary',p.ink_primary);r.style.setProperty('--ink-secondary',p.ink_secondary);
  r.style.setProperty('--ink-muted',p.ink_muted);
  r.style.setProperty('--color-button',C.buttonColor||p.primary);
  document.body.style.backgroundColor=p.surface_950;
  const app=document.getElementById('nas-app');if(app)app.style.backgroundColor=p.surface_950;
  if(C.portalBg&&C.portalBg!==p.surface_950)applyPortalBg(C.portalBg);
}
function previewTheme(k){if(THEMES[k])applyTheme(THEMES[k]);}
function restoreTheme(){applyTheme(THEMES[C.theme]||THEMES.green||THEMES[Object.keys(THEMES)[0]]);}
function applyPortalBg(hex){
  if(!hex)return;const r=document.documentElement;
  r.style.setProperty('--surface-950',hex);r.style.setProperty('--portal-bg',hex);
  document.body.style.backgroundColor=hex;
  const app=document.getElementById('nas-app');if(app)app.style.backgroundColor=hex;
  function blend(h,pct){const n=parseInt(h.replace('#',''),16),rr=(n>>16)&0xff,gg=(n>>8)&0xff,bb=n&0xff,m=c=>Math.round(c+(255-c)*pct).toString(16).padStart(2,'0');return'#'+m(rr)+m(gg)+m(bb);}
  function lum(h){const n=parseInt(h.replace('#',''),16);return 0.2126*((n>>16)&0xff)/255+0.7152*((n>>8)&0xff)/255+0.0722*(n&0xff)/255;}
  if(lum(hex)>0.4){r.style.setProperty('--surface-900','#f1f5f9');r.style.setProperty('--surface-800','#e8edf5');r.style.setProperty('--surface-700','#dde3ee');r.style.setProperty('--border','#cbd5e1');r.style.setProperty('--ink-primary','#0f172a');r.style.setProperty('--ink-secondary','#334155');r.style.setProperty('--ink-muted','#64748b');}
  else{r.style.setProperty('--surface-900',blend(hex,.06));r.style.setProperty('--surface-800',blend(hex,.12));r.style.setProperty('--surface-700',blend(hex,.18));r.style.setProperty('--border',blend(hex,.14));r.style.setProperty('--ink-primary','#e8f4fc');r.style.setProperty('--ink-secondary','#8ba8c8');r.style.setProperty('--ink-muted','#4a6080');}
}
function applyButtonColor(hex){document.documentElement.style.setProperty('--color-button',hex);C.buttonColor=hex;}
function applyAccentColor(hex){document.documentElement.style.setProperty('--color-primary',hex);C.accentColor=hex;}
window.NAS_Theme={applyTheme,previewTheme,restoreTheme,applyPortalBg,applyButtonColor,applyAccentColor,THEMES};
if(C.accentColor)document.documentElement.style.setProperty('--color-primary',C.accentColor);
if(C.buttonColor)document.documentElement.style.setProperty('--color-button',C.buttonColor);
if(C.portalBg)applyPortalBg(C.portalBg);

/* === UTILITIES === */
function h(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtP(n){return C.currency+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0});}
function fmtD(d){try{return new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d||'';}}
function fmtN(n){return n>=1000?Math.floor(n/100)*100+'+':n;}
function av(name){return name?name.charAt(0).toUpperCase():'?';}
function getP(){return getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim()||C.accentColor||'#00d084';}
function catEmoji(n){const v=(n||'').toLowerCase();if(v.includes('classif'))return'📄';if(v.includes('display'))return'🖼️';if(v.includes('obituar'))return'🕊️';if(v.includes('tender'))return'📋';if(v.includes('matrimon'))return'💍';if(v.includes('propert'))return'🏠';if(v.includes('jobs')||v.includes('recruit'))return'💼';if(v.includes('legal'))return'⚖️';if(v.includes('public')||v.includes('notice'))return'📢';return'📰';}
function stLbl(s){const m={booking_received:'Received',under_review:'Under Review',quotation_sent:'Quoted',ready_to_process:'Ready',payment_received:'Paid',ad_processing:'Processing',proof_ready:'Proof Ready',submitted_to_pub:'Submitted',published:'Published',completed:'Completed',rejected:'Rejected',cancelled:'Cancelled'};return m[s]||s;}
function toast(msg,type='success'){const w=document.getElementById('n-toasts'),el=document.createElement('div');el.className='n-toast '+type;el.setAttribute('role','alert');el.textContent=msg;w.appendChild(el);requestAnimationFrame(()=>requestAnimationFrame(()=>el.classList.add('in')));setTimeout(()=>{el.classList.remove('in');setTimeout(()=>el.remove(),400);},3500);}
function ajax(action,data={}){const fd=new FormData();fd.append('action',action);fd.append('nonce',C.nonce);fd.append('nas_action','1');for(const[k,v]of Object.entries(data))fd.append(k,v??'');const ctrl=new AbortController(),tid=setTimeout(()=>ctrl.abort(),30000);return fetch(C.ajax,{method:'POST',credentials:'same-origin',body:fd,signal:ctrl.signal}).then(r=>{clearTimeout(tid);return r.text().then(t=>{try{return JSON.parse(t);}catch(e){throw new Error('Invalid server response');}});}).then(r=>{if(!r.success)throw new Error(r.data?.message||r.data||'Error');return r.data;}).catch(e=>{clearTimeout(tid);if(e.name==='AbortError')throw new Error('Request timed out');throw e;});}
function errState(msg,retry){const id='r'+Date.now();setTimeout(()=>{const b=document.getElementById(id);if(b)b.addEventListener('click',retry);},50);return`<div class="n-empty"><span class="n-empty-icon">⚠️</span><div class="n-empty-title">Something went wrong</div><div class="n-empty-sub">${h(msg)}</div><button id="${id}" class="n-btn n-btn-g n-btn-sm">Retry</button></div>`;}
function loginPrompt(ctx){return`<div class="n-empty"><span class="n-empty-icon">🔒</span><div class="n-empty-title">Sign in required</div><div class="n-empty-sub">Please sign in to access ${h(ctx)}.</div><button class="n-btn n-btn-p n-btn-sm" style="margin:0 auto;display:flex" onclick="NAS.goto('account')">Sign In</button></div>`;}

/* === STATE === */
const S={screen:'home',homeData:null,deskPage:'home',browseCat:0,browseCity:0,browsePage:1,browseSearch:'',deferredInstall:null};
const isMob=()=>window.innerWidth<768;

/* === HEADER === */
function initHeader(brand,logo){
  ['mob-logo-name','desk-logo-name','footer-brand'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=brand;});
  if(logo){['mob-logo-icon','desk-logo-icon'].forEach(id=>{const el=document.getElementById(id);if(el)el.innerHTML=`<img src="${h(logo)}" style="width:100%;height:100%;object-fit:contain;border-radius:6px" alt="${h(brand)}">`;});}
  const ft=document.getElementById('footer-tagline');if(ft)ft.textContent=C.tagline||'';
  const fp=document.getElementById('footer-phone');if(fp&&C.phone)fp.textContent='📞 '+C.phone;
}

/* === MOBILE ROUTING === */
function goto(screen){
  if(S.screen===screen&&screen!=='bookings'&&screen!=='track')return;
  // Clean up any active booking detail poll
  if(window._bkdPollCleanup){window._bkdPollCleanup();window._bkdPollCleanup=null;}
  S.screen=screen;
  const url=new URL(location.href);url.searchParams.set('screen',screen);history.pushState({screen},'',url);
  document.querySelectorAll('.mob-tab').forEach(t=>t.classList.remove('active'));
  // Map special screens to their parent tab
  const tabMap={track:'account',vendor:'account','booking-detail':'bookings'};
  const tabId=tabMap[screen]||screen;
  const tab=document.getElementById('tab-'+tabId);if(tab)tab.classList.add('active');
  renderMob(screen);
}
function renderMob(screen){
  const scr=document.getElementById('mob-screen');if(!scr)return;scr.scrollTop=0;
  switch(screen){case'home':renderMobHome(scr);break;case'browse':renderMobBrowse(scr);break;case'book':renderWizard(scr);break;case'bookings':renderMobBookings(scr);break;case'account':renderMobAccount(scr);break;case'track':renderTrackOrder(scr);break;case'vendor':renderVendorPortal(scr);break;case'blog':renderBlog(scr);break;case'faq':renderFAQ(scr);break;case'contact':renderContact(scr);break;case'rates':renderRateCard(scr);break;case'register':renderRegister(scr);break;case'notifications':renderNotifications(scr);break;case'wallet':renderWallet(scr);break;case'saved':renderSaved(scr);break;default:renderMobHome(scr);}
}

/* --- MOBILE HOME --- */
function renderMobHome(scr){
  scr.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  if(S.homeData){buildMobHome(scr,S.homeData);return;}
  ajax('nas_pwa_home').then(d=>{S.homeData=d;buildMobHome(scr,d);initHeader(d.brand?.name||C.brand,d.brand?.logo||C.logo);}).catch(e=>{scr.innerHTML=errState(e.message,()=>renderMobHome(scr));});
}
function buildMobHome(scr,d){
  const cats=d.categories||[],papers=(d.featured?.length?d.featured:(d.newspapers||[])).slice(0,6),cities=d.cities||[],stats=d.stats||{};
  const P=getP();
  scr.innerHTML=`<div>
    <div style="padding:20px 16px 12px;background:linear-gradient(160deg,color-mix(in srgb,${P} 10%,transparent) 0%,transparent 60%)">
      <div style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;background:color-mix(in srgb,${P} 12%,transparent);border:1px solid color-mix(in srgb,${P} 25%,transparent);color:${P};font-size:11px;font-weight:700;margin-bottom:14px">⚡ ${h(C.brand)}</div>
      <h1 style="font-size:26px;font-weight:900;color:var(--ink-primary);line-height:1.15;margin-bottom:8px">Book newspaper ads<br><span style="color:${P}">fast &amp; easy</span></h1>
      <p style="font-size:14px;color:var(--ink-muted);line-height:1.5;margin-bottom:16px">${h(C.tagline)}</p>
    </div>
    <div style="padding:0 16px 12px">
      <div style="display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:14px;padding:10px 14px">
        <span style="color:var(--ink-muted);font-size:15px;flex-shrink:0">🔍</span>
        <input type="text" id="mob-srch" placeholder="Search newspaper, city, category…" autocomplete="off" aria-label="Search" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-family:inherit" onkeydown="if(event.key==='Enter')NAS.doSearch()">
        <button onclick="NAS.doSearch()" style="background:${P};color:#060c18;border:none;border-radius:9px;padding:7px 14px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit">Search</button>
      </div>
    </div>
    <div style="display:flex;align-items:center;justify-content:center;gap:14px;padding:6px 16px 14px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">✅</span>${fmtN(stats.bookings||500)}+ ads placed</span>
      <span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">📰</span>${fmtN(stats.newspapers||300)}+ newspapers</span>
      <span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">📍</span>${fmtN(stats.cities||200)}+ cities</span>
    </div>
    <div style="padding:0 16px">
      <div class="flex-b" style="margin-bottom:10px">
        <span style="font-size:14px;font-weight:800;color:var(--ink-primary)">BROWSE CATEGORIES</span>
        <span style="font-size:13px;font-weight:700;color:${P};cursor:pointer" onclick="NAS.goto('browse')">All →</span>
      </div>
      <div class="cat-grid">${cats.slice(0,8).map(c=>`<div class="cat-card" onclick="NAS.browseByCat(${c.id})" role="button" tabindex="0"><span class="cat-emoji">${catEmoji(c.name)}</span><span class="cat-name">${h(c.name)}</span></div>`).join('')||`<div style="color:var(--ink-muted);font-size:13px;grid-column:1/-1;padding:12px">No categories yet</div>`}</div>
    </div>
    <div style="padding:20px 16px 0">
      <div class="flex-b" style="margin-bottom:10px">
        <span style="font-size:14px;font-weight:800;color:var(--ink-primary)">POPULAR NEWSPAPERS</span>
        <span style="font-size:13px;font-weight:700;color:${P};cursor:pointer" onclick="NAS.goto('browse')">See all →</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">${papers.map(n=>npCardMob(n)).join('')}</div>
    </div>
    ${cities.length?`<div style="padding:18px 0 0"><div style="padding:0 16px;font-size:14px;font-weight:800;color:var(--ink-primary);margin-bottom:10px">TOP CITIES</div><div class="city-scroll" style="padding:0 16px">${cities.slice(0,16).map(c=>`<button class="city-chip" onclick="NAS.browseByCity(0,'${h(c.name||c)}')">${h(c.name||c)}</button>`).join('')}</div></div>`:''}
    <div style="padding:20px 16px 8px"><button class="n-btn n-btn-p" style="width:100%" onclick="window.location.href='${h(C.bookingUrl)}'">📋 Book a Newspaper Ad Now</button></div>
    ${C.phone?`<div style="padding:0 16px 16px;text-align:center" id="mob-wa-cta"></div>`:''}
  </div>`;
}
function npCardMob(n){
  const P=getP(),logo=n.logo_url?`<img class="np-logo" src="${h(n.logo_url)}" alt="${h(n.name)}" loading="lazy" onerror="this.outerHTML='<div class=np-logo-ph>📰</div>'">`:'<div class="np-logo-ph">📰</div>';
  return`<div class="np-card" onclick="NAS.openSheet(${n.id})" role="button" tabindex="0">${logo}<div class="np-info">${n.city_name?`<div class="np-cat">${h(n.city_name)}${n.language?' · '+h(n.language):''}</div>`:''}<div class="np-name">${h(n.name)}</div>${n.circulation?`<div class="np-meta">Circ: ${parseInt(n.circulation).toLocaleString('en-IN')}+</div>`:''}</div><div class="np-right">${n.base_rate?`<div class="np-price">${fmtP(n.base_rate)}<span style="font-size:10px;opacity:.6">/ad</span></div>`:''}<button class="n-btn n-btn-p n-btn-sm" onclick="event.stopPropagation();NAS.bookNp(${n.id})">Book</button></div></div>`;
}

/* --- MOBILE BROWSE --- */
function renderMobBrowse(scr){
  scr.innerHTML=`<div><div style="padding:12px 16px 0"><div style="display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:14px;padding:10px 14px"><span style="color:var(--ink-muted);font-size:15px;flex-shrink:0">🔍</span><input type="text" id="mob-br-inp" placeholder="Search newspapers, cities…" value="${h(S.browseSearch)}" autocomplete="off" aria-label="Search" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-family:inherit"><button onclick="NAS.clearBrowse()" style="background:none;border:none;color:var(--ink-muted);cursor:pointer;font-size:14px">✕</button></div></div><div class="n-loading" id="mob-br-spin" style="padding:32px"><span class="n-spinner"></span></div><div id="mob-br-res" style="display:none"></div></div>`;
  let t;document.getElementById('mob-br-inp')?.addEventListener('input',e=>{S.browseSearch=e.target.value;clearTimeout(t);t=setTimeout(()=>mobLoadBrowse(scr,1),400);});
  mobLoadBrowse(scr,1);
}
function mobLoadBrowse(scr,page){
  S.browsePage=page;const sp=document.getElementById('mob-br-spin'),res=document.getElementById('mob-br-res');
  if(sp)sp.style.display='flex';if(res)res.style.display='none';
  ajax('nas_pwa_browse',{category_id:S.browseCat,city_id:S.browseCity,search:S.browseSearch,page}).then(d=>{
    if(sp)sp.style.display='none';if(res)res.style.display='block';
    const P=getP(),cats=d.categories||[],papers=d.newspapers||[],cities=d.cities||[];
    let html=`<div style="display:flex;gap:8px;padding:10px 16px 4px;overflow-x:auto;scrollbar-width:none"><button class="city-chip${!S.browseCat?' active':''}" onclick="NAS.filterCat(0)">All</button>${cats.map(c=>`<button class="city-chip${S.browseCat===c.id?' active':''}" onclick="NAS.filterCat(${c.id})">${catEmoji(c.name)} ${h(c.name)}</button>`).join('')}</div>`;
    if(cities.length>1)html+=`<div style="display:flex;gap:8px;padding:4px 16px 8px;overflow-x:auto;scrollbar-width:none"><button class="city-chip${!S.browseCity?' active':''}" onclick="NAS.filterCity(0)">All Cities</button>${cities.map(c=>`<button class="city-chip${S.browseCity===c.id?' active':''}" onclick="NAS.filterCity(${c.id})">${h(c.name)}</button>`).join('')}</div>`;
    if(!papers.length){html+=`<div class="n-empty"><span class="n-empty-icon">🔍</span><div class="n-empty-title">No results</div><div class="n-empty-sub">Try clearing filters.</div><button class="n-btn n-btn-g n-btn-sm" onclick="NAS.clearBrowse()">Clear</button></div>`;}
    else{html+=`<div style="padding:4px 16px 6px;font-size:12px;color:var(--ink-muted)">${d.total} result${d.total===1?'':'s'}</div><div style="padding:0 16px;display:flex;flex-direction:column;gap:10px">${papers.map(n=>npCardMob(n)).join('')}</div>`;if(d.pages>1){html+='<div class="n-pages">';for(let i=1;i<=d.pages;i++)html+=`<button class="n-page-btn${i===page?' active':''}" onclick="NAS.browsePg(${i})">${i}</button>`;html+='</div>';}}
    res.innerHTML=html;
  }).catch(e=>{if(sp)sp.style.display='none';if(res){res.style.display='block';res.innerHTML=errState(e.message,()=>mobLoadBrowse(scr,page));}});
}

/* --- MOBILE BOOK --- */
function renderMobBook(scr){
  const P=getP();
  scr.innerHTML=`<div style="padding:24px 16px"><div style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;background:color-mix(in srgb,${P} 12%,transparent);border:1px solid color-mix(in srgb,${P} 25%,transparent);color:${P};font-size:11px;font-weight:700;margin-bottom:20px">📋 Book a Newspaper Ad</div>
  <h2 style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:8px">Ready to place your ad?</h2>
  <p style="font-size:14px;color:var(--ink-muted);margin-bottom:28px;line-height:1.6">Our 5-step wizard guides you through selecting a newspaper, ad type, content, and pricing — in minutes.</p>
  <button class="n-btn n-btn-p" style="width:100%;padding:14px" onclick="window.location.href='${h(C.bookingUrl)}'">🚀 Start Booking Wizard</button>
  <div style="margin-top:24px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;overflow:hidden">${[['📄','Classified Ads','Text-based ads in defined word/line formats'],['🖼️','Display Ads','Branded ads with custom images and design'],['💍','Matrimonial','Wedding and alliance listings'],['🏠','Property Ads','Real estate buy/sell/rent'],['📢','Public Notices','Legal and regulatory announcements'],['🕊️','Obituary & Tribute','In memoriam and remembrance ads']].map(([ico,nm,desc],i)=>`<div style="display:flex;align-items:center;gap:12px;padding:14px 16px;${i<5?'border-bottom:1px solid var(--border)':''};cursor:pointer;transition:background .15s" onclick="window.location.href='${h(C.bookingUrl)}'" onmouseover="this.style.background='var(--surface-700)'" onmouseout="this.style.background='none'"><span style="font-size:22px;flex-shrink:0">${ico}</span><div><div style="font-size:14px;font-weight:700;color:var(--ink-primary)">${nm}</div><div style="font-size:12px;color:var(--ink-muted);margin-top:2px">${desc}</div></div><span style="margin-left:auto;color:var(--ink-muted)">›</span></div>`).join('')}</div></div>`;
}

/* --- MOBILE BOOKINGS --- */
function renderMobBookings(scr){ return renderMobBookingsEnhanced(scr); }
function renderMobBookings_orig(scr){
  if(!C.isLoggedIn){scr.innerHTML=loginPrompt('your bookings');return;}
  scr.innerHTML='<div><div style="padding:16px 16px 8px;font-size:14px;font-weight:800;color:var(--ink-primary)">MY BOOKINGS</div><div class="n-loading"><span class="n-spinner"></span></div><div id="mob-bk-list"></div></div>';
  loadMobBk(1);
}
function loadMobBk(page){
  ajax('nas_pwa_bookings',{page}).then(d=>{
    const el=document.getElementById('mob-bk-list'),items=d.bookings||[],pages=d.pages||1,P=getP();
    document.querySelector('#nas-mobile .n-loading')?.remove();
    if(!items.length){el.innerHTML=`<div class="n-empty"><span class="n-empty-icon">📭</span><div class="n-empty-title">No bookings yet</div><div class="n-empty-sub">Place your first newspaper ad.</div><button class="n-btn n-btn-p n-btn-sm" style="margin:0 auto;display:flex" onclick="NAS.goto('book')">Book an Ad</button></div>`;return;}
    let html=items.map(b=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:14px;margin:0 16px 10px;cursor:pointer" onclick="NAS.openBookingDetail(${parseInt(b.id)},null)"><div style="display:flex;justify-content:space-between;margin-bottom:6px"><span style="font-size:14px;font-weight:800;color:${P}">${h(b.uid||b.id)}</span><span style="font-size:12px;color:var(--ink-muted)">${fmtD(b.submitted_at)}</span></div><div style="font-size:15px;font-weight:700;color:var(--ink-primary);margin-bottom:6px">${h(b.newspaper_name||'Newspaper')} — ${h(b.category_name||'Ad')}</div><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span class="nbadge ${h(b.status)}">${stLbl(b.status)}</span>${b.publish_date?`<span style="font-size:12px;color:var(--ink-muted)">📅 ${fmtD(b.publish_date)}</span>`:''}</div><div style="display:flex;justify-content:space-between;margin-top:10px"><span style="font-size:12px;color:var(--ink-muted)">${b.payment_status==='paid'?'✅ Paid':'💳 '+b.payment_status}</span><span style="font-size:15px;font-weight:800;color:var(--ink-primary)">${fmtP(b.total_amount)}</span></div></div>`).join('');
    if(pages>1){html+='<div class="n-pages">';for(let i=1;i<=pages;i++)html+=`<button class="n-page-btn${i===page?' active':''}" onclick="NAS.loadMobBkPg(${i})">${i}</button>`;html+='</div>';}
    html+=`<div style="padding:4px 16px 16px;text-align:center"><a href="${h(C.dashUrl)}" style="font-size:13px;font-weight:700;color:${P}">View full dashboard →</a></div>`;
    el.innerHTML=html;
  }).catch(e=>{document.querySelector('#nas-mobile .n-loading')?.remove();document.getElementById('mob-bk-list').innerHTML=errState(e.message,()=>loadMobBk(page));});
}

/* --- MOBILE ACCOUNT --- */
function renderMobAccount(scr){
  if(!C.isLoggedIn){renderMobLogin(scr);return;}
  scr.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ajax('nas_pwa_account').then(d=>{
    const P=getP(),u=d.user||{},bal=d.wallet_balance||0;
    scr.innerHTML=`<div><div style="padding:28px 16px;text-align:center"><div style="width:72px;height:72px;border-radius:50%;background:color-mix(in srgb,${P} 20%,transparent);color:${P};font-size:28px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">${av(u.name)}</div><div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">${h(u.name||'')}</div><div style="font-size:13px;color:var(--ink-muted)">${h(u.email||'')}</div></div>
    <div style="margin:0 16px 16px;background:linear-gradient(135deg,color-mix(in srgb,${P} 20%,#000),color-mix(in srgb,${P} 40%,#000));border:1px solid color-mix(in srgb,${P} 30%,transparent);border-radius:18px;padding:18px"><div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Wallet Balance</div><div style="font-size:28px;font-weight:900;color:#fff">${fmtP(bal)}</div></div>
    <div style="margin:0 16px 16px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;overflow:hidden">
      ${[['📊','Full Dashboard',C.dashUrl],['📦','My Bookings','#bookings'],['🔔','Notifications','#notifications'],['💰','Wallet','#wallet'],['❤️','Saved Newspapers','#saved'],['📍','Track Order','#track'],['🏪','Vendor Portal','#vendor'],['❓','FAQs','#faq'],['💬','Contact Us','#contact']].map(([ico,lbl,url],i)=>`<a href="${h(url)}" style="display:flex;align-items:center;gap:12px;padding:14px 16px;${i<8?'border-bottom:1px solid var(--border)':''};text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--surface-700)'" onmouseout="this.style.background='none'"><div style="width:36px;height:36px;border-radius:10px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">${ico}</div><span style="flex:1;font-size:14px;font-weight:600;color:var(--ink-primary)">${lbl}</span><span style="color:var(--ink-muted)">›</span></a>`).join('')}
      ${C.isAdmin?`<a href="${h(C.themeSettingsUrl)}" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--surface-700)'" onmouseout="this.style.background='none'"><div style="width:36px;height:36px;border-radius:10px;background:color-mix(in srgb,${getP()} 15%,transparent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">🎨</div><span style="flex:1;font-size:14px;font-weight:600;color:var(--ink-primary)">Theme &amp; App Settings</span></a>`:''}
    <a href="${h(C.logoutUrl)}" style="display:flex;align-items:center;gap:12px;padding:14px 16px;text-decoration:none;transition:background .15s" onmouseover="this.style.background='rgba(239,68,68,.08)'" onmouseout="this.style.background='none'"><div style="width:36px;height:36px;border-radius:10px;background:rgba(239,68,68,.1);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">🚪</div><span style="flex:1;font-size:14px;font-weight:600;color:#f87171">Sign Out</span></a>
    </div></div>`;
  }).catch(e=>{scr.innerHTML=errState(e.message,()=>renderMobAccount(scr));});
}
function renderMobLogin(scr){
  const P=getP();
  scr.innerHTML=`<div style="padding:32px 20px"><div style="font-size:48px;text-align:center;margin-bottom:16px">🔑</div><h2 style="font-size:24px;font-weight:900;color:var(--ink-primary);margin-bottom:6px">Welcome back</h2><p style="font-size:14px;color:var(--ink-muted);margin-bottom:28px;line-height:1.5">Sign in to manage bookings, track orders, and view invoices.</p>
  <div class="n-field"><label class="n-lbl" for="li-em">Email Address</label><input class="n-input" id="li-em" type="email" placeholder="your@email.com" autocomplete="email"><span class="n-err" id="li-em-err"></span></div>
  <div class="n-field"><label class="n-lbl" for="li-pw">Password</label><input class="n-input" id="li-pw" type="password" placeholder="••••••••" autocomplete="current-password" onkeydown="if(event.key==='Enter')NAS.doLogin()"><span class="n-err" id="li-pw-err"></span></div>
  <span class="n-err" id="li-err" style="display:block;margin-bottom:10px"></span>
  <button class="n-btn n-btn-p" id="li-btn" style="width:100%;padding:14px" onclick="NAS.doLogin()">Sign In →</button>
  <div style="margin-top:14px;text-align:center"><a href="${h(C.loginUrl)}?step=forgot" style="color:${P};font-size:13px">Forgot password?</a></div>
  <div style="margin-top:12px;text-align:center;font-size:13px;color:var(--ink-muted)">New here? <span onclick="NAS.goto('register')" style="color:${P};font-weight:700;cursor:pointer">Create an account →</span></div></div>`;
}

/* --- NP SHEET (both mobile + desktop) --- */
function openSheet(id){
  const ov=document.getElementById('n-overlay'),body=document.getElementById('n-sheet-body');
  body.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ov.classList.add('open');document.body.style.overflow='hidden';
  ajax('nas_pwa_newspaper',{id}).then(d=>{
    const np=d.newspaper||{},rates=d.rates||[],P=getP();
    const logo=np.logo_url?`<img src="${h(np.logo_url)}" style="width:54px;height:54px;border-radius:12px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border)" alt="${h(np.name)}">`:'<div style="width:54px;height:54px;border-radius:12px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:26px">📰</div>';
    body.innerHTML=`<div style="padding:0 20px 8px;display:flex;align-items:center;gap:14px;position:relative">${logo}<div><div style="font-size:18px;font-weight:800;color:var(--ink-primary)">${h(np.name)}</div>${np.city_name?`<div style="font-size:13px;color:var(--ink-muted)">${h(np.city_name)}${np.state?', '+h(np.state):''}</div>`:''}</div><button onclick="NAS.closeSheet()" style="position:absolute;top:0;right:0;background:var(--surface-700);border:none;color:var(--ink-muted);width:32px;height:32px;border-radius:50%;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button></div>
    ${np.base_rate?`<div style="display:flex;gap:20px;padding:0 20px 16px"><div><div style="font-size:18px;font-weight:800;color:${P}">${fmtP(np.base_rate)}</div><div style="font-size:11px;color:var(--ink-muted)">Starting from</div></div>${np.circulation?`<div><div style="font-size:16px;font-weight:800;color:var(--ink-primary)">${parseInt(np.circulation).toLocaleString('en-IN')}+</div><div style="font-size:11px;color:var(--ink-muted)">Circulation</div></div>`:''}</div>`:''}
    ${rates.length?`<div style="padding:0 20px 10px;font-size:14px;font-weight:700;color:var(--ink-primary)">Rate Card</div><table style="width:calc(100% - 40px);margin:0 20px;border-collapse:collapse;font-size:13px"><thead><tr style="background:var(--surface-700)"><th style="text-align:left;padding:8px 10px;font-weight:600;color:var(--ink-muted)">Ad Type</th><th style="text-align:right;padding:8px 10px;font-weight:600;color:var(--ink-muted)">Rate</th></tr></thead><tbody>${rates.map(r=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:10px;color:var(--ink-secondary)">${h(r.ad_type||'Classified')}</td><td style="text-align:right;padding:10px;font-weight:800;color:${P}">${fmtP(r.rate_per_unit||r.base_rate)}</td></tr>`).join('')}</tbody></table>`:''}
    <div style="padding:20px 16px 0;display:flex;gap:10px">
      <button class="n-btn n-btn-p" style="flex:1;padding:14px" onclick="NAS.bookNp(${np.id})">📋 Book Ad</button>
      <button class="n-btn n-btn-g" style="padding:14px" onclick="NAS.toggleSaved(${np.id},'${h(np.name).replace(/'/g,'')}')" id="save-btn-${np.id}" aria-label="Save newspaper">${isSaved(np.id)?'❤️':'🤍'}</button>
      <button class="n-btn n-btn-g" style="padding:14px" onclick="NAS.shareNewspaper('${h(np.name)}','${h(C.homeUrl)}nas-app/?screen=browse')" aria-label="Share">⬆️</button>
    </div>\`;
  }).catch(e=>{body.innerHTML=`<div class="n-empty"><span class="n-empty-icon">⚠️</span><div class="n-empty-title">Could not load</div><div class="n-empty-sub">${h(e.message)}</div></div>`;});
}
function closeSheet(){document.getElementById('n-overlay').classList.remove('open');document.body.style.overflow='';}

/* === DESKTOP ROUTING === */
function deskGoto(page){
  S.deskPage=page;
  document.querySelectorAll('.desk-nav-link').forEach(l=>l.classList.remove('active'));
  const map={home:'dnl-home',browse:'dnl-browse',cities:'dnl-cities',portal:'dnl-portal'};
  const el=document.getElementById(map[page]);if(el)el.classList.add('active');
  const url=new URL(location.href);url.searchParams.set('screen',page);history.pushState({screen:page},'',url);
  renderDesk(page);
}
function renderDesk(page){
  const main=document.getElementById('desk-main'),footer=document.getElementById('desk-footer');
  const old=document.getElementById('desk-sidebar');if(old)old.remove();
  main.className='desk-main';
  switch(page){case'browse':if(footer)footer.style.display='block';renderDeskBrowse(main);break;case'cities':if(footer)footer.style.display='block';renderDeskCities(main);break;case'portal':if(footer)footer.style.display='none';renderDeskPortal(main);break;case'blog':if(footer)footer.style.display='block';renderDeskBlog(main);break;case'faq':if(footer)footer.style.display='block';renderDeskFAQ(main);break;case'contact':if(footer)footer.style.display='block';renderDeskContact(main);break;case'rates':if(footer)footer.style.display='block';renderDeskRates(main);break;case'register':if(footer)footer.style.display='block';renderDeskRegister(main);break;default:if(footer)footer.style.display='block';renderDeskHome(main);}
}

/* --- DESKTOP HOME --- */
function renderDeskHome(main){
  main.innerHTML='<div class="n-loading" style="padding:120px 0"><span class="n-spinner"></span></div>';
  if(S.homeData){buildDeskHome(main,S.homeData);return;}
  ajax('nas_pwa_home').then(d=>{S.homeData=d;buildDeskHome(main,d);initHeader(d.brand?.name||C.brand,d.brand?.logo||C.logo);}).catch(e=>{main.innerHTML=errState(e.message,()=>renderDeskHome(main));});
}
function buildDeskHome(main,d){
  const cats=d.categories||[],papers=(d.newspapers||[]).slice(0,9),cities=d.cities||[],stats=d.stats||{};
  const P=getP(),totalB=fmtN(stats.bookings||500),totalP=fmtN(stats.newspapers||300),totalC=fmtN(stats.cities||200);
  // populate mega menu
  const mc=document.getElementById('mega-cats');
  if(mc)mc.innerHTML=cats.map(c=>`<button class="mega-card" onclick="NAS.deskBrowseCat(${c.id})"><span>${catEmoji(c.name)}</span><span>${h(c.name)}</span></button>`).join('');

  main.innerHTML=`
  <!-- HERO — HomeDesktop.jsx pattern -->
  <section style="position:relative;overflow:hidden;padding:80px 24px 64px;background:linear-gradient(135deg,color-mix(in srgb,${P} 8%,var(--surface-950)) 0%,var(--surface-950) 60%)">
    <div style="position:absolute;top:0;right:0;width:500px;height:500px;border-radius:50%;background:${P};opacity:.04;filter:blur(80px);transform:translate(30%,-30%)"></div>
    <div class="max-w" style="position:relative">
      <div style="max-width:680px">
        <div style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:99px;background:color-mix(in srgb,${P} 12%,transparent);border:1px solid color-mix(in srgb,${P} 30%,transparent);color:${P};font-size:12px;font-weight:700;margin-bottom:20px">⚡ India's trusted newspaper ad platform</div>
        <h1 style="font-size:52px;font-weight:900;color:var(--ink-primary);line-height:1.1;margin-bottom:16px">Book Newspaper Ads<br><span style="color:${P}">at Your Door</span></h1>
        <p style="font-size:18px;color:var(--ink-secondary);line-height:1.6;margin-bottom:32px;max-width:560px">Place classified, display and public notice ads in leading newspapers across India. Transparent pricing, instant confirmation.</p>
        <!-- City + search + button (HomeDesktop.jsx search bar pattern) -->
        <form onsubmit="NAS.deskSearch();return false" style="display:flex;align-items:stretch;border-radius:18px;overflow:hidden;border:2px solid ${P};background:var(--surface-900);max-width:660px;box-shadow:0 8px 40px rgba(0,0,0,.3)">
          <div style="display:flex;align-items:center;gap:8px;padding:0 16px;border-right:1px solid var(--border);flex-shrink:0">
            <span style="color:${P};font-size:15px">📍</span>
            <select id="desk-city-sel" style="background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;min-width:100px;padding:18px 0">
              ${(C.cities||[]).map(c=>`<option style="background:var(--surface-800)">${h(c)}</option>`).join('')}
            </select>
          </div>
          <input id="desk-hero-inp" placeholder="Search newspaper, ad type, city…" value="${h(S.browseSearch)}" style="flex:1;padding:18px 20px;background:none;border:none;outline:none;color:var(--ink-primary);font-size:16px;font-family:inherit">
          <button type="submit" style="padding:0 28px;background:${P};color:#060c18;border:none;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap">Search</button>
        </form>
        <div style="display:flex;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap">
          <span style="font-size:13px;color:var(--ink-muted)">Popular:</span>
          ${['Classified','Display Ads','Matrimonial','Property','Legal Notice','Obituary','Recruitment'].map(tag=>`<button onclick="NAS.deskTag('${tag}')" style="padding:6px 14px;border-radius:99px;background:var(--surface-800);border:1px solid var(--border);color:var(--ink-secondary);font-size:13px;cursor:pointer;font-family:inherit;transition:all .15s" onmouseover="this.style.borderColor='${P}'" onmouseout="this.style.borderColor='var(--border)'">${tag}</button>`).join('')}
        </div>
      </div>
    </div>
  </section>

  <!-- TRUST BADGES -->
  <div style="border-bottom:1px solid var(--border)">
    <div class="max-w" style="padding:20px 24px">
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">
        ${[['✅','Verified Newspapers','All officially empanelled partners'],['⭐','Printed Rates','DAVP/RNI rates, zero markup'],['⚡','Instant Confirmation','Booking in minutes, not days'],['🔒','GST Invoice','Proper tax invoice for all bookings']].map(([ico,title,desc])=>`<div style="display:flex;align-items:center;gap:12px"><div style="width:40px;height:40px;border-radius:12px;background:color-mix(in srgb,${P} 12%,transparent);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">${ico}</div><div><div style="font-size:14px;font-weight:600;color:var(--ink-primary)">${title}</div><div style="font-size:12px;color:var(--ink-muted);margin-top:2px;line-height:1.4">${desc}</div></div></div>`).join('')}
      </div>
    </div>
  </div>

  <!-- CATEGORIES -->
  <section class="section">
    <div class="max-w">
      <div class="flex-b" style="margin-bottom:28px;align-items:flex-end">
        <div><span class="sec-lbl">What do you need?</span><div class="sec-ttl">All Ad Categories</div><p style="font-size:14px;color:var(--ink-secondary);margin-top:6px">${totalP}+ newspapers · ${totalC}+ cities</p></div>
        <button onclick="NAS.deskGoto('browse')" style="display:flex;align-items:center;gap:6px;font-size:14px;font-weight:700;color:${P};background:none;border:none;cursor:pointer;font-family:inherit">Browse all →</button>
      </div>
      <div class="cat-grid">${cats.map(c=>`<button class="cat-card" onclick="NAS.deskBrowseCat(${c.id})" style="border-bottom:3px solid color-mix(in srgb,${P} 40%,transparent)" onmouseover="this.style.borderBottomColor='${P}'" onmouseout="this.style.borderBottomColor='color-mix(in srgb,${P} 40%,transparent)'"><span class="cat-emoji">${catEmoji(c.name)}</span><span class="cat-name">${h(c.name)}</span></button>`).join('')||`<div style="color:var(--ink-muted);font-size:13px;grid-column:1/-1;padding:16px">No categories yet. Add in admin panel.</div>`}</div>
    </div>
  </section>

  <div class="n-divider"></div>

  <!-- HOW IT WORKS -->
  <section class="section" style="background:var(--surface-900)">
    <div class="max-w">
      <div style="text-align:center;margin-bottom:48px"><span class="sec-lbl">Simple Process</span><div class="sec-ttl">How It Works</div><p style="font-size:15px;color:var(--ink-secondary);margin-top:8px;max-width:500px;margin-left:auto;margin-right:auto">Book a newspaper ad in 5 easy steps. Confirmation in minutes.</p></div>
      <div class="how-grid">${[['01','Select Newspaper','Choose from '+totalP+'+ empanelled newspapers across India.'],['02','Choose Ad Type','Pick classified, display, matrimonial, notice, or any category.'],['03','Write Your Ad','Use our templates or write from scratch. Preview before booking.'],['04','Pay & Confirm','Pay securely online. Ad published on your chosen date.']].map(([step,title,desc])=>`<div><div class="how-num">${step}</div><div style="font-size:15px;font-weight:700;color:var(--ink-primary);margin-bottom:6px">${title}</div><div style="font-size:13px;color:var(--ink-muted);line-height:1.6">${desc}</div></div>`).join('')}</div>
    </div>
  </section>

  <div class="n-divider"></div>

  <!-- POPULAR NEWSPAPERS -->
  <section class="section">
    <div class="max-w">
      <div class="flex-b" style="margin-bottom:28px;align-items:flex-end">
        <div><span class="sec-lbl">Most Booked</span><div class="sec-ttl">Popular Newspapers</div></div>
        <button onclick="NAS.deskGoto('browse')" style="display:flex;align-items:center;gap:6px;font-size:14px;font-weight:700;color:${P};background:none;border:none;cursor:pointer;font-family:inherit">See all →</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
        ${papers.map(n=>`<div class="np-desk" onclick="NAS.openSheet(${n.id})" onmouseover="this.style.borderColor='${P}'" onmouseout="this.style.borderColor='var(--border)'">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            ${n.logo_url?`<img src="${h(n.logo_url)}" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border)" alt="${h(n.name)}" loading="lazy">`:'<div style="width:44px;height:44px;border-radius:10px;background:var(--surface-700);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:20px">📰</div>'}
            <div style="flex:1;min-width:0">${n.city_name?`<div style="font-size:11px;font-weight:600;color:${P};margin-bottom:2px">${h(n.city_name)}</div>`:''}<div style="font-size:15px;font-weight:700;color:var(--ink-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(n.name)}</div></div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border);padding-top:12px">
            <div>${n.circulation?`<div style="font-size:12px;color:var(--ink-muted)">Circ: ${parseInt(n.circulation).toLocaleString('en-IN')}+</div>`:''}</div>
            <div style="text-align:right">${n.base_rate?`<div style="font-size:18px;font-weight:800;color:${P}">${fmtP(n.base_rate)}</div><div style="font-size:11px;color:var(--ink-muted)">/ad onwards</div>`:''}<button class="n-btn n-btn-p n-btn-sm" style="margin-top:8px" onclick="event.stopPropagation();NAS.bookNp(${n.id})">Book Now</button></div>
          </div>
        </div>`).join('')||`<div style="color:var(--ink-muted);font-size:13px;grid-column:1/-1;padding:20px">No newspapers yet.</div>`}
      </div>
    </div>
  </section>

  <!-- STATS -->
  <section style="padding:48px 24px;border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--surface-900)">
    <div class="max-w"><div class="stats-grid">${[[totalB,'Ads Placed'],[totalP+' Newspapers','Across India'],['4.8★','Client Rating'],['Same Day','Confirmation']].map(([v,l])=>`<div><div class="stat-num">${v}</div><div class="stat-lbl">${l}</div></div>`).join('')}</div></div>
  </section>

  <!-- CITIES -->
  ${cities.length>1?`<section style="padding:40px 24px"><div class="max-w"><h2 style="font-size:24px;font-weight:800;text-align:center;margin-bottom:20px;color:var(--ink-primary)">We serve across <span style="color:${P}">${totalC} cities</span></h2><div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center">${cities.map(c=>`<button class="city-chip" onclick="NAS.deskBrowseCity('${h(c.name||c)}')">📍 ${h(c.name||c)}</button>`).join('')}</div></div></section>`:''}

  <!-- WHY CHOOSE US -->
  <section class="section" style="border-top:1px solid var(--border);background:var(--surface-900)">
    <div class="max-w" style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center">
      <div>
        <span class="sec-lbl">Why Choose Us</span>
        <h2 style="font-size:28px;font-weight:800;color:var(--ink-primary);margin-bottom:14px;line-height:1.2">Newspaper advertising made simple and transparent</h2>
        <p style="font-size:15px;color:var(--ink-secondary);line-height:1.7;margin-bottom:24px">Every newspaper on our platform is officially empanelled. We provide actual DAVP/RNI-published rates with no markup.</p>
        <div style="display:flex;flex-direction:column;gap:12px">${['All newspapers officially empanelled','Printed tariff rates — no hidden markup','Instant booking confirmation via email','Published clipping shared after publication','GST invoice provided for all bookings'].map(p=>`<div style="display:flex;align-items:center;gap:10px"><span style="width:20px;height:20px;border-radius:50%;background:color-mix(in srgb,${P} 15%,transparent);color:${P};font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0">✓</span><span style="font-size:14px;color:var(--ink-secondary)">${p}</span></div>`).join('')}</div>
        <button class="n-btn n-btn-p" style="margin-top:28px;padding:14px 32px" onclick="window.location.href='${h(C.bookingUrl)}'">Book Your First Ad →</button>
        ${C.phone?`<a href="https://wa.me/${C.phone.replace(/\D/g,'')}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:#25d366;color:#fff;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;margin-top:12px;margin-left:12px;transition:opacity .15s" onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'">💬 Chat on WhatsApp</a>`:''}
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">${[[totalB,'Ads placed',P],['4.8★','Rating','#fbbf24'],[totalP+' Papers','Available','#60a5fa'],['Same Day','Confirmation','#a78bfa']].map(([v,l,c])=>`<div style="padding:24px;border-radius:18px;background:var(--surface-800);border:1px solid var(--border);text-align:center"><div style="font-size:26px;font-weight:900;margin-bottom:4px;color:${c}">${v}</div><div style="font-size:12px;color:var(--ink-muted)">${l}</div></div>`).join('')}</div>
    </div>
  </section>`;
}

/* --- DESKTOP BROWSE --- */
function renderDeskBrowse(main){
  main.innerHTML=`<div class="desk-content"><div style="margin-bottom:20px"><span class="sec-lbl">All Newspapers</span><div style="font-size:24px;font-weight:800;color:var(--ink-primary)">Browse &amp; Compare</div></div><div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap"><div style="flex:1;min-width:260px;display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:12px;padding:10px 14px"><span style="color:var(--ink-muted)">🔍</span><input type="text" id="dbr-inp" placeholder="Search…" value="${h(S.browseSearch)}" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-family:inherit" autocomplete="off"></div></div><div class="n-loading" id="dbr-spin"><span class="n-spinner"></span></div><div id="dbr-res"></div></div>`;
  let t;document.getElementById('dbr-inp')?.addEventListener('input',e=>{S.browseSearch=e.target.value;clearTimeout(t);t=setTimeout(()=>deskLoadBrowse(1),400);});
  deskLoadBrowse(1);
}
function deskLoadBrowse(page){
  ajax('nas_pwa_browse',{category_id:S.browseCat,city_id:S.browseCity,search:S.browseSearch,page}).then(d=>{
    const sp=document.getElementById('dbr-spin'),res=document.getElementById('dbr-res');if(sp)sp.style.display='none';
    const cats=d.categories||[],papers=d.newspapers||[],P=getP();
    let html=`<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap"><button class="city-chip${!S.browseCat?' active':''}" onclick="NAS.deskFCat(0)">All Categories</button>${cats.map(c=>`<button class="city-chip${S.browseCat===c.id?' active':''}" onclick="NAS.deskFCat(${c.id})">${catEmoji(c.name)} ${h(c.name)}</button>`).join('')}</div>`;
    if(!papers.length){html+=`<div class="n-empty"><span class="n-empty-icon">🔍</span><div class="n-empty-title">No results</div></div>`;}
    else{html+=`<div style="font-size:13px;color:var(--ink-muted);margin-bottom:14px">${d.total} result${d.total===1?'':'s'}</div><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">${papers.map(n=>`<div class="np-desk" onclick="NAS.openSheet(${n.id})" onmouseover="this.style.borderColor='${P}'" onmouseout="this.style.borderColor='var(--border)'"><div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">${n.logo_url?`<img src="${h(n.logo_url)}" style="width:40px;height:40px;border-radius:9px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border)" alt="${h(n.name)}" loading="lazy">`:'<div style="width:40px;height:40px;border-radius:9px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:18px">📰</div>'}<div style="flex:1;min-width:0">${n.city_name?`<div style="font-size:11px;font-weight:600;color:${P};margin-bottom:2px">${h(n.city_name)}</div>`:''}<div style="font-size:14px;font-weight:700;color:var(--ink-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(n.name)}</div></div></div><div style="display:flex;align-items:center;justify-content:space-between"><span style="font-size:12px;color:var(--ink-muted)">${n.language?h(n.language):''}</span><div style="text-align:right">${n.base_rate?`<div style="font-size:16px;font-weight:800;color:${P}">${fmtP(n.base_rate)}</div>`:''}<button class="n-btn n-btn-p n-btn-sm" style="margin-top:6px" onclick="event.stopPropagation();NAS.bookNp(${n.id})">Book</button></div></div></div>`).join('')}</div>`;
    if(d.pages>1){html+='<div class="n-pages">';for(let i=1;i<=d.pages;i++)html+=`<button class="n-page-btn${i===page?' active':''}" onclick="NAS.deskBrPg(${i})">${i}</button>`;html+='</div>';}}
    res.innerHTML=html;
  });
}

/* --- DESKTOP CITIES --- */
function renderDeskCities(main){
  const P=getP();
  main.innerHTML=`<div class="desk-content"><div style="margin-bottom:28px"><span class="sec-lbl">Locations</span><div class="sec-ttl">Cities We Serve</div></div><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">${(C.cities||[]).map(c=>`<button onclick="NAS.deskBrowseCity('${h(c)}')" style="display:flex;align-items:center;gap:10px;padding:16px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all .15s;font-family:inherit" onmouseover="this.style.borderColor='${P}';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--border)';this.style.transform='none'"><span style="font-size:20px">📍</span><span style="font-size:14px;font-weight:600;color:var(--ink-primary)">${h(c)}</span></button>`).join('')}</div></div>`;
}

/* --- DESKTOP PORTAL --- */
function renderDeskPortal(main){
  if(!C.isLoggedIn){renderDeskLoginPrompt(main);return;}
  main.className='desk-main with-sb';
  const P=getP();
  const sb=document.createElement('aside');sb.className='desk-sidebar';sb.id='desk-sidebar';
  sb.innerHTML=`<div class="sb-lbl">My Account</div>${[['📊','Dashboard',C.dashUrl],['📦','My Bookings','#portal'],['📍','Track Order',C.homeUrl+'track-order/'],['❓','FAQs',C.homeUrl+'faq/'],['💬','Support',C.homeUrl+'contact-us/']].map(([ico,lbl,url])=>`<a class="sb-link" href="${h(url)}">${ico} ${lbl}</a>`).join('')}<div class="sb-footer"><div class="sb-user"><div class="sb-av">${av(C.userName)}</div><div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:700;color:var(--ink-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(C.userName)}</div></div></div><a href="${h(C.logoutUrl)}" style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;color:#f87171;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s" onmouseover="this.style.background='rgba(239,68,68,.1)'" onmouseout="this.style.background='none'">🚪 Sign Out</a></div>`;
  document.getElementById('nas-desktop').insertBefore(sb,main);
  main.innerHTML='<div class="desk-content"><div class="n-loading"><span class="n-spinner"></span></div></div>';
  Promise.all([ajax('nas_pwa_account').catch(()=>({})),ajax('nas_pwa_bookings',{page:1}).catch(()=>({bookings:[],pages:0}))]).then(([acc,bkd])=>{
    const u=acc.user||{},bal=acc.wallet_balance||0,items=bkd.bookings||[],pages=bkd.pages||1;
    let html=`<div class="desk-content"><div style="display:flex;align-items:center;gap:16px;background:var(--surface-800);border:1px solid var(--border);border-radius:20px;padding:24px;margin-bottom:24px"><div style="width:64px;height:64px;border-radius:50%;background:color-mix(in srgb,${P} 20%,transparent);color:${P};font-size:24px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0">${av(u.name)}</div><div style="flex:1"><div style="font-size:20px;font-weight:800;color:var(--ink-primary)">${h(u.name||'')}</div><div style="font-size:13px;color:var(--ink-muted)">${h(u.email||'')}</div></div><div style="background:linear-gradient(135deg,color-mix(in srgb,${P} 20%,#000),color-mix(in srgb,${P} 40%,#000));border-radius:14px;padding:14px 20px;text-align:center;min-width:140px"><div style="font-size:11px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.05em">Wallet</div><div style="font-size:22px;font-weight:900;color:#fff">${fmtP(bal)}</div></div><a href="${h(C.dashUrl)}" class="n-btn n-btn-g" style="padding:10px 20px;font-size:14px;text-decoration:none">Full Dashboard →</a></div>
    <div style="font-size:18px;font-weight:800;color:var(--ink-primary);margin-bottom:16px">My Bookings</div>`;
    if(!items.length){html+=`<div class="n-empty"><span class="n-empty-icon">📭</span><div class="n-empty-title">No bookings yet</div><div class="n-empty-sub">Place your first newspaper ad.</div><button class="n-btn n-btn-p" onclick="window.location.href='${h(C.bookingUrl)}'">Book an Ad</button></div>`;}
    else{html+=`<div style="display:flex;flex-direction:column;gap:10px">${items.map(b=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:18px;display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center"><div><div style="font-size:15px;font-weight:800;color:${P};margin-bottom:4px">${h(b.uid||b.id)}</div><div style="font-size:15px;font-weight:700;color:var(--ink-primary);margin-bottom:6px">${h(b.newspaper_name||'Newspaper')} — ${h(b.category_name||'Ad')}</div><div style="display:flex;align-items:center;gap:10px"><span class="nbadge ${h(b.status)}">${stLbl(b.status)}</span><span style="font-size:12px;color:var(--ink-muted)">${fmtD(b.submitted_at)}</span></div></div><div style="text-align:right"><div style="font-size:18px;font-weight:900;color:var(--ink-primary)">${fmtP(b.total_amount)}</div><div style="font-size:12px;color:var(--ink-muted)">${b.payment_status==='paid'?'✅ Paid':'💳 '+b.payment_status}</div></div><a href="${h(C.dashUrl)}" class="n-btn n-btn-g" style="padding:9px 16px;font-size:13px;white-space:nowrap;text-decoration:none">View →</a></div>`).join('')}</div>`;}
    html+='</div>';main.innerHTML=html;
  });
}
function renderDeskLoginPrompt(main){
  const P=getP();
  main.innerHTML=`<div class="desk-content" style="max-width:480px;margin:80px auto"><div style="text-align:center;font-size:64px;margin-bottom:20px">🔑</div><h2 style="font-size:24px;font-weight:900;color:var(--ink-primary);margin-bottom:8px;text-align:center">Sign in to your account</h2><p style="font-size:14px;color:var(--ink-muted);margin-bottom:32px;text-align:center">Manage bookings, view invoices and track your ads.</p><div class="n-field"><label class="n-lbl" for="dli-em">Email</label><input class="n-input" id="dli-em" type="email" placeholder="your@email.com" autocomplete="email"></div><div class="n-field"><label class="n-lbl" for="dli-pw">Password</label><input class="n-input" id="dli-pw" type="password" placeholder="••••••••" autocomplete="current-password" onkeydown="if(event.key==='Enter')NAS.deskLogin()"></div><span class="n-err" id="dli-err" style="display:block;margin-bottom:10px"></span><button class="n-btn n-btn-p" id="dli-btn" style="width:100%;padding:14px" onclick="NAS.deskLogin()">Sign In →</button><div style="text-align:center;margin-top:14px"><a href="${h(C.loginUrl)}?step=forgot" style="color:${P};font-size:13px">Forgot password?</a></div></div>`;
}

/* === SHARED ACTIONS === */
function doSearch(){const inp=document.getElementById('mob-srch')||document.getElementById('mob-br-inp');if(inp)S.browseSearch=inp.value;S.browseCat=0;S.browseCity=0;S.browsePage=1;if(isMob())goto('browse');else{S.deskPage='browse';renderDesk('browse');}}
function deskSearch(){const inp=document.getElementById('desk-hero-inp')||document.getElementById('desk-search-inp');if(inp)S.browseSearch=inp.value;S.browseCat=0;S.browseCity=0;deskGoto('browse');}
function deskTag(tag){S.browseSearch=tag;S.browseCat=0;S.browseCity=0;deskGoto('browse');}
function browseByCat(id){S.browseCat=id;S.browseCity=0;S.browseSearch='';S.browsePage=1;goto('browse');}
function browseByCity(id){S.browseCity=id;S.browseCat=0;S.browseSearch='';S.browsePage=1;goto('browse');}
function deskBrowseCat(id){S.browseCat=id;S.browseCity=0;S.browseSearch='';deskGoto('browse');}
function deskBrowseCity(name){S.browseSearch=name;S.browseCat=0;S.browseCity=0;deskGoto('browse');}
function filterCat(id){S.browseCat=id;S.browsePage=1;const scr=document.getElementById('mob-screen');mobLoadBrowse(scr,1);}
function filterCity(id){S.browseCity=id;S.browsePage=1;const scr=document.getElementById('mob-screen');mobLoadBrowse(scr,1);}
function deskFCat(id){S.browseCat=id;deskLoadBrowse(1);}
function clearBrowse(){S.browseSearch='';S.browseCat=0;S.browseCity=0;S.browsePage=1;const scr=document.getElementById('mob-screen');if(scr)mobLoadBrowse(scr,1);}
function browsePg(p){S.browsePage=p;const scr=document.getElementById('mob-screen');mobLoadBrowse(scr,p);scr.scrollTop=0;}
function deskBrPg(p){deskLoadBrowse(p);document.getElementById('desk-main').scrollTop=0;}
function loadMobBkPg(p){loadMobBk(p);}
function bookNp(id){closeSheet();window.location.href=C.bookingUrl+'?np='+id;}

// Mega menu
let megaT;
function openMega(){clearTimeout(megaT);megaT=setTimeout(()=>{const m=document.getElementById('mega-menu'),a=document.getElementById('mega-arrow');if(m)m.style.display='block';if(a)a.style.transform='rotate(180deg)';},220);}
function closeMega(){clearTimeout(megaT);megaT=setTimeout(()=>{const m=document.getElementById('mega-menu'),a=document.getElementById('mega-arrow');if(m)m.style.display='none';if(a)a.style.transform='none';},200);}
function toggleUserMenu(){const m=document.getElementById('desk-user-menu');if(m)m.style.display=m.style.display==='none'?'block':'none';}
document.addEventListener('click',e=>{const w=document.getElementById('desk-user-wrap');if(w&&!w.contains(e.target)){const m=document.getElementById('desk-user-menu');if(m)m.style.display='none';}});
document.getElementById('desk-search-inp')?.addEventListener('keydown',e=>{if(e.key==='Enter')deskSearch();});

function doLogin(){
  const em=document.getElementById('li-em')?.value?.trim(),pw=document.getElementById('li-pw')?.value;
  const err=document.getElementById('li-err'),btn=document.getElementById('li-btn');
  let ok=true;
  if(!em||!/^[^@]+@[^@]+\.[^@]+$/.test(em)){const e=document.getElementById('li-em-err');if(e){e.textContent='Valid email required';e.classList.add('show');}ok=false;}else{const e=document.getElementById('li-em-err');if(e)e.classList.remove('show');}
  if(!pw){const e=document.getElementById('li-pw-err');if(e){e.textContent='Password required';e.classList.add('show');}ok=false;}else{const e=document.getElementById('li-pw-err');if(e)e.classList.remove('show');}
  if(!ok)return;
  if(btn){btn.disabled=true;btn.textContent='Signing in…';}
  ajax('nas_pwa_login',{email:em,password:pw}).then(d=>{C.isLoggedIn=true;C.userId=d.user_id;C.userName=d.name;toast('Welcome back, '+d.name+' ✓');S.homeData=null;goto('bookings');}).catch(e=>{if(btn){btn.disabled=false;btn.textContent='Sign In →';}if(err){err.textContent=e.message;err.style.display='block';}});
}
function deskLogin(){
  const em=document.getElementById('dli-em')?.value?.trim(),pw=document.getElementById('dli-pw')?.value;
  const err=document.getElementById('dli-err'),btn=document.getElementById('dli-btn');
  if(!em||!pw){if(err){err.textContent='Email and password required';err.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Signing in…';}
  ajax('nas_pwa_login',{email:em,password:pw}).then(d=>{C.isLoggedIn=true;C.userId=d.user_id;C.userName=d.name;toast('Welcome back, '+d.name+' ✓');renderDeskPortal(document.getElementById('desk-main'));}).catch(e=>{if(btn){btn.disabled=false;btn.textContent='Sign In →';}if(err){err.textContent=e.message;err.style.display='block';}});
}
function promptInstall(){if(S.deferredInstall){S.deferredInstall.prompt();S.deferredInstall.userChoice.then(r=>{if(r.outcome==='accepted'){toast('App installed! 🎉');S.deferredInstall=null;}});}}

/* === NOTIF BADGE === */
function loadNotifCount(){
  if(!C.isLoggedIn)return;
  ajax('nas_pwa_notif_count').then(d=>{
    const show=d.count>0,label=d.count>9?'9+':String(d.count);
    ['mob-badge','desk-badge'].forEach(id=>{const b=document.getElementById(id);if(b){b.style.display=show?'block':'none';if(show)b.textContent=label;}});
  }).catch(()=>{});
}

/* === PWA INSTALL === */
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();S.deferredInstall=e;});
window.addEventListener('appinstalled',()=>{S.deferredInstall=null;toast('App installed! 🎉');});


/* ══════════════════════════════════════════════════════════════════
   PHASE 2: BOOKING DETAIL SCREEN
   Full in-PWA booking view: status timeline, chat, materials, actions
══════════════════════════════════════════════════════════════════ */
function renderMobBookingDetail(scr, bookingId, bookingUid) {
  scr.innerHTML='<div style="padding:0"><div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)"><button onclick="NAS.goto(\'bookings\')" style="background:var(--surface-800);border:1px solid var(--border);border-radius:10px;padding:6px 12px;font-size:13px;color:var(--ink-secondary);cursor:pointer;font-family:inherit">← Back</button><span style="font-size:14px;font-weight:700;color:var(--ink-primary)">Booking Detail</span></div><div class="n-loading"><span class="n-spinner"></span></div><div id="bkd-content"></div></div>';
  const params = bookingId ? {booking_id:bookingId} : {uid:bookingUid};
  ajax('nas_pwa_booking_detail', params).then(d => {
    buildBookingDetail(scr, d);
  }).catch(e => {
    document.getElementById('bkd-content').innerHTML = errState(e.message, () => renderMobBookingDetail(scr, bookingId, bookingUid));
    document.querySelector('.n-loading')?.remove();
  });
}

function buildBookingDetail(scr, d) {
  const bk=d.booking||{}, msgs=d.messages||[], hist=d.history||[], mats=d.materials||[];
  const P=getP(), bid=bk.id, uid_str=bk.uid||bk.id;

  // Remove spinner
  document.querySelector('#nas-mobile .n-loading')?.remove();

  const el = document.getElementById('bkd-content');
  if (!el) return;

  // Status colours
  const stColor = {booking_received:'#fbbf24',under_review:'#fbbf24',quotation_sent:'#60a5fa',ready_to_process:'#60a5fa',payment_received:P,ad_processing:'#818cf8',proof_ready:'#818cf8',submitted_to_pub:'#818cf8',published:P,completed:P,rejected:'#f87171',cancelled:'#f87171'};
  const sc = stColor[bk.status]||P;

  el.innerHTML = `
  <!-- Booking header -->
  <div style="padding:14px 16px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <span style="font-size:18px;font-weight:900;color:${P}">${h(uid_str)}</span>
      <span class="nbadge ${h(bk.status)}" style="background:${sc}20;color:${sc}">${stLbl(bk.status)}</span>
    </div>
    <div style="font-size:15px;font-weight:700;color:var(--ink-primary);margin-bottom:4px">${h(bk.np_name||'Newspaper')} — ${h(bk.cat_name||'Ad')}</div>
    <div style="font-size:13px;color:var(--ink-muted)">${h(bk.ad_type||'')} ${bk.city_name?'· '+h(bk.city_name):''}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px">
      <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:11px;padding:10px">
        <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:4px">Total Amount</div>
        <div style="font-size:18px;font-weight:900;color:${P}">${fmtP(bk.total_amount)}</div>
      </div>
      <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:11px;padding:10px">
        <div style="font-size:10px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:4px">Payment</div>
        <div style="font-size:14px;font-weight:700;color:${bk.payment_status==='paid'?P:'#fbbf24'}">${bk.payment_status==='paid'?'✅ Paid':'💳 '+h(bk.payment_status||'Pending')}</div>
      </div>
    </div>
    ${bk.publish_date?`<div style="font-size:12px;color:var(--ink-muted);margin-top:8px">📅 Publish date: ${fmtD(bk.publish_date)}</div>`:''}
  </div>

  <!-- Status Timeline -->
  <div style="padding:0 16px 14px;border-bottom:1px solid var(--border)">
    <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:10px">Status Timeline</div>
    ${hist.map((h_,i)=>`<div style="display:flex;gap:10px;position:relative">
      <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0">
        <div style="width:20px;height:20px;border-radius:50%;background:${stColor[h_.to_status]||P};display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:#060c18;flex-shrink:0">✓</div>
        ${i<hist.length-1?`<div style="width:2px;flex:1;background:var(--border);margin:3px 0;min-height:20px"></div>`:''}
      </div>
      <div style="padding-bottom:${i<hist.length-1?'12px':'0'}">
        <div style="font-size:13px;font-weight:700;color:var(--ink-primary)">${stLbl(h_.to_status)}</div>
        <div style="font-size:11px;color:var(--ink-muted)">${fmtD(h_.created_at)} ${h_.note?'· '+h(h_.note):''}</div>
      </div>
    </div>`).join('')||`<div style="font-size:13px;color:var(--ink-muted)">No history yet.</div>`}
  </div>

  <!-- Actions -->
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap">
    ${bk.payment_status==='pending'&&!['cancelled','rejected'].includes(bk.status)?`<button onclick="window.location.href='${h(C.homeUrl)}payment/?uid=${h(uid_str)}'" class="n-btn n-btn-p n-btn-sm">💳 Pay Now</button>`:''}
    ${d.invoice_url?`<a href="${h(d.invoice_url)}" class="n-btn n-btn-g n-btn-sm" download>📄 Invoice</a>`:''}
    ${['booking_received','under_review','quotation_sent'].includes(bk.status)?`<button onclick="NAS.cancelBooking(${bid})" class="n-btn n-btn-sm" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3)">✕ Cancel</button>`:''}
  </div>

  <!-- Material Upload -->
  ${['payment_received','ad_processing'].includes(bk.status)?`<div style="padding:12px 16px;border-bottom:1px solid var(--border)">
    <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:8px">Upload Ad Material</div>
    ${mats.length?mats.map(m=>`<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--surface-800);border-radius:9px;margin-bottom:6px"><span style="font-size:16px">📎</span><span style="flex:1;font-size:13px;color:var(--ink-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(m.file_name||m.file_url)}</span><span style="font-size:11px;color:var(--ink-muted)">${h(m.status)}</span></div>`).join(''):''}
    <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--surface-800);border:1.5px dashed var(--border);border-radius:11px;cursor:pointer;transition:border-color .15s" onmouseover="this.style.borderColor='${P}'" onmouseout="this.style.borderColor='var(--border)'">
      <span style="font-size:18px">📤</span>
      <span style="font-size:13px;color:var(--ink-secondary)">Tap to upload file (PDF, Image, DOCX)</span>
      <input type="file" id="mat-upload-${bid}" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.webp" onchange="NAS.uploadMaterial(${bid},this)">
    </label>
    <div id="mat-status-${bid}" style="font-size:12px;color:var(--ink-muted);margin-top:6px;display:none"></div>
  </div>`:''}

  <!-- Chat -->
  <div style="padding:12px 16px 0">
    <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:10px">Messages with Team</div>
    <div id="bkd-chat-msgs" style="display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto;margin-bottom:12px">
      ${msgs.map(m=>`<div style="display:flex;gap:8px;flex-direction:${m.sender_role==='client'?'row-reverse':'row'}">
        <div style="width:28px;height:28px;border-radius:50%;background:${m.sender_role==='client'?P+'20':'var(--surface-700)'};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:${m.sender_role==='client'?P:'var(--ink-muted)'}">${m.sender_name?m.sender_name[0].toUpperCase():'?'}</div>
        <div style="max-width:75%">
          <div style="padding:8px 12px;background:${m.sender_role==='client'?P:'var(--surface-800)'};color:${m.sender_role==='client'?'#060c18':'var(--ink-primary)'};border-radius:${m.sender_role==='client'?'14px 14px 4px 14px':'14px 14px 14px 4px'};font-size:13px;line-height:1.5">${h(m.message)}</div>
          <div style="font-size:10px;color:var(--ink-muted);margin-top:3px;text-align:${m.sender_role==='client'?'right':'left'}">${fmtD(m.created_at)}</div>
        </div>
      </div>`).join('')||`<div style="text-align:center;padding:20px;font-size:13px;color:var(--ink-muted)">No messages yet. Send one below.</div>`}
    </div>
    <div style="display:flex;gap:8px;padding-bottom:16px">
      <input type="text" id="bkd-chat-inp" class="n-input" placeholder="Type a message…" style="flex:1;font-size:14px" onkeydown="if(event.key==='Enter')NAS.sendMsg(${bid})">
      <button onclick="NAS.sendMsg(${bid})" class="n-btn n-btn-p n-btn-sm" id="bkd-send-btn">Send</button>
    </div>
  </div>`;

  // Poll for new messages
  let chatPollTimer = setInterval(() => {
    const lastMsg = document.querySelectorAll('#bkd-chat-msgs > div');
    const lastId = msgs.length ? msgs[msgs.length-1]?.id || 0 : 0;
    ajax('nas_pwa_get_messages', {booking_id: bid, last_id: lastId}).then(md => {
      if (md.messages?.length) {
        const chatEl = document.getElementById('bkd-chat-msgs');
        if (chatEl) {
          md.messages.forEach(m => {
            const div = document.createElement('div');
            div.style.cssText = `display:flex;gap:8px;flex-direction:${m.sender_role==='client'?'row-reverse':'row'}`;
            div.innerHTML = `<div style="width:28px;height:28px;border-radius:50%;background:${m.sender_role==='client'?P+'20':'var(--surface-700)'};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:${m.sender_role==='client'?P:'var(--ink-muted)'}">${m.sender_name?m.sender_name[0].toUpperCase():'?'}</div><div style="max-width:75%"><div style="padding:8px 12px;background:${m.sender_role==='client'?P:'var(--surface-800)'};color:${m.sender_role==='client'?'#060c18':'var(--ink-primary)'};border-radius:14px;font-size:13px;line-height:1.5">${h(m.message)}</div></div>`;
            chatEl.appendChild(div);
            msgs.push(m);
          });
          chatEl.scrollTop = chatEl.scrollHeight;
        }
      }
    }).catch(() => {});
  }, 5000);

  // Clean up on screen change
  const origGoto = goto;
  window._bkdPollCleanup = () => clearInterval(chatPollTimer);
}

/* ══════════════════════════════════════════════════════════════════
   PHASE 2: TRACK ORDER SCREEN (public)
══════════════════════════════════════════════════════════════════ */
function renderTrackOrder(scr) {
  const P=getP();
  scr.innerHTML=`<div style="padding:24px 16px">
    <div style="font-size:24px;font-weight:900;color:var(--ink-primary);margin-bottom:6px">Track Your Ad</div>
    <p style="font-size:14px;color:var(--ink-muted);margin-bottom:24px;line-height:1.5">Enter your booking ID and email to see the current status of your newspaper ad.</p>
    <div class="n-field"><label class="n-lbl" for="trk-uid">Booking ID</label><input class="n-input" id="trk-uid" placeholder="e.g. NAS241201XXXX" autocomplete="off" style="text-transform:uppercase"></div>
    <div class="n-field"><label class="n-lbl" for="trk-email">Email Address</label><input class="n-input" id="trk-email" type="email" placeholder="your@email.com" autocomplete="email"></div>
    <span class="n-err" id="trk-err" style="display:block;margin-bottom:10px"></span>
    <button class="n-btn n-btn-p" id="trk-btn" style="width:100%;padding:13px" onclick="NAS.doTrack()">Track Order →</button>
    <div id="trk-result" style="margin-top:20px"></div>
  </div>`;
}

/* ══════════════════════════════════════════════════════════════════
   PHASE 3: IN-PWA BOOKING WIZARD (5 steps, no redirect)
══════════════════════════════════════════════════════════════════ */
const WIZ = { step:1, cat:null, city:null, newspaper:null, rate:null, adType:'', title:'', content:'', wordCount:0, publishDate:'', name:'', email:'', phone:'', company:'', notes:'', total:0, base:0, gst:0, priceCalcTimer:null };

function renderWizard(scr) {
  scr.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ajax('nas_pwa_wizard_data').then(d => buildWizardStep1(scr, d)).catch(e => {scr.innerHTML=errState(e.message,()=>renderWizard(scr));});
}

function wizHeader(step, total=5) {
  const P=getP();
  return `<div style="padding:14px 16px 0">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      ${step>1?`<button onclick="NAS.wizBack()" style="background:var(--surface-800);border:1px solid var(--border);border-radius:9px;padding:6px 12px;font-size:13px;color:var(--ink-secondary);cursor:pointer;font-family:inherit">←</button>`:''}
      <div style="flex:1">
        <div style="display:flex;gap:4px;margin-bottom:4px">${Array.from({length:total},(_,i)=>`<div style="flex:1;height:3px;border-radius:99px;background:${i<step?P:'var(--border)'}"></div>`).join('')}</div>
        <div style="font-size:11px;color:var(--ink-muted)">Step ${step} of ${total}</div>
      </div>
    </div>
  </div>`;
}

function buildWizardStep1(scr, d) {
  const P=getP(), cats=d.categories||[], cities=d.cities||[];
  scr.innerHTML=`${wizHeader(1)}<div style="padding:0 16px 16px">
    <div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">Choose Ad Category</div>
    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:16px">What type of ad do you want to place?</p>
    <div class="cat-grid">${cats.map(c=>`<div class="cat-card${WIZ.cat?.id===c.id?' active':''}" onclick="NAS.wizSelCat(${c.id},'${h(c.name)}')" data-catid="${c.id}" style="${WIZ.cat?.id===c.id?`border-color:${P};background:${P}20`:''}" role="button" tabindex="0">
      <span class="cat-emoji">${catEmoji(c.name)}</span>
      <span class="cat-name">${h(c.name)}</span>
    </div>`).join('')}</div>
    <div style="margin-top:20px;font-size:14px;font-weight:700;color:var(--ink-primary);margin-bottom:8px">Select City</div>
    <div class="city-scroll" style="margin-bottom:16px">${cities.map(c=>`<button class="city-chip${WIZ.city?.id===c.id?' active':''}" onclick="NAS.wizSelCity(${c.id},'${h(c.name)}')">${h(c.name)}</button>`).join('')}</div>
    <button class="n-btn n-btn-p" id="wiz1-next" style="width:100%;padding:13px;${WIZ.cat&&WIZ.city?'':'opacity:.5;cursor:not-allowed'}" onclick="NAS.wizStep2()" ${WIZ.cat&&WIZ.city?'':'disabled'}>Next: Choose Newspaper →</button>
  </div>`;
  scr._wizData = d;
}

function buildWizardStep2(scr) {
  const P=getP();
  scr.innerHTML=`${wizHeader(2)}<div style="padding:0 16px 16px">
    <div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">Choose Newspaper</div>
    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:12px">Category: <strong>${h(WIZ.cat?.name)}</strong> · City: <strong>${h(WIZ.city?.name)}</strong></p>
    <div style="display:flex;gap:8px;margin-bottom:12px">
      <input type="text" id="wiz-np-search" class="n-input" placeholder="Search newspapers…" style="font-size:14px" oninput="NAS.wizSearchNp(this.value)">
    </div>
    <div id="wiz-np-list" style="display:flex;flex-direction:column;gap:8px"><div class="n-loading"><span class="n-spinner"></span></div></div>
  </div>`;
  ajax('nas_pwa_wizard_newspapers',{category_id:WIZ.cat.id,city_id:WIZ.city.id}).then(d=>{
    const el=document.getElementById('wiz-np-list');if(!el)return;
    const papers=d.newspapers||[];
    if(!papers.length){el.innerHTML=`<div class="n-empty"><span class="n-empty-icon">📰</span><div class="n-empty-title">No newspapers found</div><div class="n-empty-sub">Try a different city or category.</div></div>`;return;}
    el.innerHTML=papers.map(n=>`<div class="np-card${WIZ.newspaper?.id===n.id?' active':''}" onclick="NAS.wizSelNp(${n.id},'${h(n.name)}',${n.base_rate||0})" style="${WIZ.newspaper?.id===n.id?`border-color:${P};background:${P}12`:''}" data-npid="${n.id}" role="button" tabindex="0">
      ${n.logo_url?`<img class="np-logo" src="${h(n.logo_url)}" alt="${h(n.name)}" loading="lazy">`:'<div class="np-logo-ph">📰</div>'}
      <div class="np-info"><div class="np-name">${h(n.name)}</div><div class="np-meta">${n.city_name?h(n.city_name)+' · ':''}${n.language?h(n.language):''}</div></div>
      <div class="np-right">${n.base_rate?`<div class="np-price">${fmtP(n.base_rate)}<span style="font-size:10px;opacity:.6">/ad</span></div>`:''}</div>
    </div>`).join('');
    if(WIZ.newspaper){const active=el.querySelector(`[data-npid="${WIZ.newspaper.id}"]`);if(active)active.style.borderColor=P;}
  }).catch(e=>{const el=document.getElementById('wiz-np-list');if(el)el.innerHTML=errState(e.message,()=>buildWizardStep2(scr));});
}

function buildWizardStep3(scr) {
  const P=getP();
  scr.innerHTML=`${wizHeader(3)}<div style="padding:0 16px 16px">
    <div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">Write Your Ad</div>
    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:14px">Newspaper: <strong>${h(WIZ.newspaper?.name)}</strong></p>
    <div id="wiz-rates-area"><div class="n-loading" style="padding:20px"><span class="n-spinner"></span></div></div>
    <div class="n-field" style="margin-top:12px"><label class="n-lbl" for="wiz-title">Ad Title / Headline</label><input class="n-input" id="wiz-title" placeholder="Short headline" value="${h(WIZ.title)}" oninput="WIZ.title=this.value"></div>
    <div class="n-field"><label class="n-lbl" for="wiz-content">Ad Content <span id="wiz-wc" style="color:var(--ink-muted)">(0 words)</span></label><textarea class="n-input" id="wiz-content" rows="5" placeholder="Type your ad text here…" style="resize:vertical" oninput="NAS.wizUpdateContent(this.value)">${h(WIZ.content)}</textarea></div>
    <div id="wiz-price-preview" style="background:var(--surface-800);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:14px;display:${WIZ.total?'block':'none'}">
      <div style="font-size:11px;color:var(--ink-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Estimated Price</div>
      <div style="font-size:22px;font-weight:900;color:${P}">${fmtP(WIZ.total)}</div>
      <div style="font-size:11px;color:var(--ink-muted)">Base: ${fmtP(WIZ.base)} + GST: ${fmtP(WIZ.gst)}</div>
    </div>
    <button class="n-btn n-btn-p" id="wiz3-next" style="width:100%;padding:13px;${WIZ.adType&&WIZ.content?'':'opacity:.5;cursor:not-allowed'}" onclick="NAS.wizStep4()" ${WIZ.adType&&WIZ.content?'':'disabled'}>Next: Schedule →</button>
  </div>`;

  // Load rate card
  ajax('nas_pwa_wizard_rates',{newspaper_id:WIZ.newspaper.id}).then(d=>{
    const rates=d.rates||[], el=document.getElementById('wiz-rates-area');if(!el)return;
    if(!rates.length){el.innerHTML='<div style="font-size:13px;color:var(--ink-muted);margin-bottom:12px">Standard rate applies.</div>';WIZ.adType='standard';return;}
    const adTypes=[...new Set(rates.map(r=>r.ad_type))];
    el.innerHTML=`<div style="margin-bottom:12px"><div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:8px">Ad Type</div><div style="display:flex;gap:8px;flex-wrap:wrap">${adTypes.map(t=>`<button class="city-chip${WIZ.adType===t?' active':''}" onclick="NAS.wizSelAdType('${t}')" style="font-size:12px">${t.replace(/_/g,' ')}</button>`).join('')}</div></div>`;
  }).catch(()=>{});
}

function buildWizardStep4(scr) {
  const P=getP(), today=new Date().toISOString().slice(0,10);
  scr.innerHTML=`${wizHeader(4)}<div style="padding:0 16px 16px">
    <div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">Schedule Your Ad</div>
    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:16px">Choose when your ad should be published.</p>
    <div class="n-field"><label class="n-lbl" for="wiz-date">Publish Date</label><input class="n-input" id="wiz-date" type="date" min="${today}" value="${WIZ.publishDate}" oninput="WIZ.publishDate=this.value;NAS.wizValidate4()"></div>
    <div id="wiz-price-box" style="background:var(--surface-800);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:8px">
      <div style="font-size:11px;color:var(--ink-muted);text-transform:uppercase;margin-bottom:8px">Price Breakdown</div>
      <div style="display:grid;grid-template-columns:1fr auto;gap:6px;font-size:13px">
        <span style="color:var(--ink-secondary)">Base amount</span><span style="font-weight:700;color:var(--ink-primary)">${fmtP(WIZ.base)}</span>
        <span style="color:var(--ink-secondary)">GST (5%)</span><span style="font-weight:700;color:var(--ink-primary)">${fmtP(WIZ.gst)}</span>
        <div style="height:1px;background:var(--border);grid-column:1/-1;margin:4px 0"></div>
        <span style="color:var(--ink-primary);font-weight:700">Total</span><span style="font-size:20px;font-weight:900;color:${P}">${fmtP(WIZ.total)}</span>
      </div>
    </div>
    <div id="wiz-coupon-wrap" style="margin-top:14px"></div>
    <button class="n-btn n-btn-p" id="wiz4-next" style="width:100%;padding:13px;margin-top:14px;${WIZ.publishDate?'':'opacity:.5;cursor:not-allowed'}" onclick="NAS.wizStep5()" ${WIZ.publishDate?'':'disabled'}>Next: Your Details →</button>
  </div>`;
}

function buildWizardStep5(scr) {
  const P=getP();
  const user = C.isLoggedIn ? null : null; // Pre-fill if logged in
  scr.innerHTML=`${wizHeader(5)}<div style="padding:0 16px 16px">
    <div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:4px">Your Details</div>
    <p style="font-size:13px;color:var(--ink-muted);margin-bottom:16px">We'll send your booking confirmation here.</p>
    <div class="n-field"><label class="n-lbl" for="wiz-name">Full Name</label><input class="n-input" id="wiz-name" placeholder="Your full name" value="${h(WIZ.name)}" oninput="WIZ.name=this.value"></div>
    <div class="n-field"><label class="n-lbl" for="wiz-email">Email Address</label><input class="n-input" id="wiz-email" type="email" placeholder="your@email.com" value="${h(WIZ.email)}" oninput="WIZ.email=this.value"></div>
    <div class="n-field"><label class="n-lbl" for="wiz-phone">Phone Number</label><input class="n-input" id="wiz-phone" type="tel" placeholder="10-digit mobile" value="${h(WIZ.phone)}" oninput="WIZ.phone=this.value"></div>
    <div class="n-field"><label class="n-lbl" for="wiz-company">Company / Organisation <span style="color:var(--ink-muted);font-weight:400">(optional)</span></label><input class="n-input" id="wiz-company" placeholder="If booking for business" value="${h(WIZ.company)}" oninput="WIZ.company=this.value"></div>
    <div class="n-field"><label class="n-lbl" for="wiz-notes">Additional Notes <span style="color:var(--ink-muted);font-weight:400">(optional)</span></label><textarea class="n-input" id="wiz-notes" rows="3" placeholder="Any special instructions…" oninput="WIZ.notes=this.value">${h(WIZ.notes)}</textarea></div>
    <!-- Summary -->
    <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:14px">
      <div style="font-size:12px;font-weight:700;color:var(--ink-muted);margin-bottom:8px">ORDER SUMMARY</div>
      <div style="font-size:13px;color:var(--ink-secondary);margin-bottom:4px">${h(WIZ.newspaper?.name)} — ${h(WIZ.cat?.name)}</div>
      <div style="font-size:12px;color:var(--ink-muted);margin-bottom:6px">${WIZ.adType.replace(/_/g,' ')} · Publish: ${fmtD(WIZ.publishDate)}</div>
      <div style="font-size:18px;font-weight:900;color:${P}">${fmtP(WIZ.total)}</div>
    </div>
    <span class="n-err" id="wiz-submit-err" style="display:block;margin-bottom:10px"></span>
    <button class="n-btn n-btn-p" id="wiz-submit-btn" style="width:100%;padding:14px;font-size:15px" onclick="NAS.wizSubmit()">
      📋 Confirm Booking
    </button>
    <p style="font-size:11px;color:var(--ink-muted);text-align:center;margin-top:10px">Payment can be made after booking is confirmed.</p>
  </div>`;
}

/* ══════════════════════════════════════════════════════════════════
   PHASE 4: VENDOR MOBILE PORTAL
══════════════════════════════════════════════════════════════════ */
function renderVendorPortal(scr) {
  if (!C.isLoggedIn) { scr.innerHTML = loginPrompt('the vendor portal'); return; }
  scr.innerHTML = `<div>
    <div style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none" id="vendor-tabs">
      <button class="city-chip active" id="vt-bookings" onclick="NAS.vendorTab('bookings')">📦 Bookings</button>
      <button class="city-chip" id="vt-earnings" onclick="NAS.vendorTab('earnings')">💰 Earnings</button>
      <button class="city-chip" id="vt-profile"  onclick="NAS.vendorTab('profile')">👤 Profile</button>
    </div>
    <div id="vendor-content"><div class="n-loading"><span class="n-spinner"></span></div></div>
  </div>`;
  loadVendorBookings(1);
}

function loadVendorBookings(page) {
  const el=document.getElementById('vendor-content');
  if(el)el.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ajax('nas_pwa_vendor_bookings',{page}).then(d=>{
    const P=getP(), items=d.bookings||[], pages=d.pages||1;
    let html='';
    // Stats
    ajax('nas_pwa_vendor_stats').then(s=>{
      const statsEl=document.getElementById('vendor-stats-row');
      if(statsEl) statsEl.innerHTML=`<span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">📦</span>${s.active} active</span><span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">✅</span>${s.completed} done</span><span style="font-size:12px;color:var(--ink-muted);display:flex;align-items:center;gap:5px"><span style="color:${P}">💰</span>${fmtP(s.earnings)}</span>`;
    }).catch(()=>{});

    if(!items.length){
      html=`<div class="n-empty"><span class="n-empty-icon">📭</span><div class="n-empty-title">No bookings assigned</div><div class="n-empty-sub">Bookings assigned to you will appear here.</div></div>`;
    } else {
      html=`<div style="display:flex;align-items:center;justify-content:center;gap:14px;padding:10px 16px;flex-wrap:wrap" id="vendor-stats-row"><span style="font-size:12px;color:var(--ink-muted)">Loading stats…</span></div>`;
      html+=items.map(b=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:14px;padding:14px;margin:0 16px 10px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <span style="font-size:14px;font-weight:800;color:${P}">${h(b.uid||b.id)}</span>
          <span class="nbadge ${h(b.status)}">${stLbl(b.status)}</span>
        </div>
        <div style="font-size:14px;font-weight:700;color:var(--ink-primary);margin-bottom:4px">${h(b.np_name||'Newspaper')} — ${h(b.cat_name||'Ad')}</div>
        <div style="font-size:12px;color:var(--ink-muted);margin-bottom:10px">${h(b.ad_type||'')} ${b.publish_date?'· Pub: '+fmtD(b.publish_date):''}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          ${['ad_processing'].includes(b.status)?`<label class="n-btn n-btn-p n-btn-sm" style="cursor:pointer">📤 Upload Proof<input type="file" style="display:none" accept=".pdf,.jpg,.jpeg,.png" onchange="NAS.vendorUploadProof(${b.id},this)"></label>`:''}
          ${['proof_ready','submitted_to_pub'].includes(b.status)?`<button onclick="NAS.vendorMarkPublished(${b.id})" class="n-btn n-btn-p n-btn-sm">✅ Mark Published</button>`:''}
          <button onclick="NAS.goto('bookings');NAS.openVendorBkDetail(${b.id})" class="n-btn n-btn-g n-btn-sm">View</button>
        </div>
      </div>`).join('');
      if(pages>1){html+='<div class="n-pages">';for(let i=1;i<=pages;i++)html+=`<button class="n-page-btn${i===page?' active':''}" onclick="NAS.loadVendorBkPg(${i})">${i}</button>`;html+='</div>';}
    }
    if(el)el.innerHTML=`<div>${html}</div>`;
  }).catch(e=>{if(el)el.innerHTML=errState(e.message,()=>loadVendorBookings(page));});
}

function loadVendorEarnings() {
  const el=document.getElementById('vendor-content');
  if(el)el.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ajax('nas_pwa_vendor_earnings').then(d=>{
    const P=getP(), monthly=d.monthly||[];
    let html=`<div style="padding:14px 16px">
      <div style="font-size:22px;font-weight:900;color:${P};margin-bottom:4px">${fmtP(d.total)}</div>
      <div style="font-size:13px;color:var(--ink-muted);margin-bottom:20px">Total earnings from completed bookings</div>`;
    if(!monthly.length){html+=`<div class="n-empty"><span class="n-empty-icon">💰</span><div class="n-empty-title">No earnings yet</div><div class="n-empty-sub">Earnings appear after bookings are completed.</div></div>`;}
    else{html+=monthly.map(m=>`<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--surface-800);border:1px solid var(--border);border-radius:12px;margin-bottom:8px"><div><div style="font-size:14px;font-weight:700;color:var(--ink-primary)">${m.month}</div><div style="font-size:12px;color:var(--ink-muted)">${m.count} booking${m.count===1?'':'s'}</div></div><div style="font-size:16px;font-weight:800;color:${P}">${fmtP(m.revenue)}</div></div>`).join('');}
    html+='</div>';
    if(el)el.innerHTML=html;
  }).catch(e=>{if(el)el.innerHTML=errState(e.message,loadVendorEarnings);});
}

function loadVendorProfile() {
  const el=document.getElementById('vendor-content');
  if(el)el.innerHTML='<div class="n-loading"><span class="n-spinner"></span></div>';
  ajax('nas_pwa_vendor_profile').then(d=>{
    const P=getP(), v=d.vendor||{}, u=d.user||{};
    if(el)el.innerHTML=`<div style="padding:16px">
      <div style="text-align:center;padding:16px 0 20px">
        <div style="width:64px;height:64px;border-radius:50%;background:color-mix(in srgb,${P} 20%,transparent);color:${P};font-size:24px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 10px">${av(u.name)}</div>
        <div style="font-size:18px;font-weight:800;color:var(--ink-primary)">${h(u.name)}</div>
        <div style="font-size:13px;color:var(--ink-muted)">${h(u.email)}</div>
        <div style="font-size:12px;color:${P};font-weight:600;margin-top:4px">${h(v.contact_person||'')} ${v.phone?'· '+h(v.phone):''}</div>
      </div>
      <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:14px;overflow:hidden">
        ${[['🏢','Company',v.company||'—'],['📍','Cities',v.cities||'—'],['📞','Phone',v.phone||'—'],['🏦','Bank',v.bank_name?v.bank_name+' ···'+String(v.account_number||'').slice(-4):'Not added']].map(([ico,lbl,val],i)=>`<div style="display:flex;align-items:center;gap:12px;padding:12px 16px;${i<3?'border-bottom:1px solid var(--border)':''}"><span style="font-size:18px;flex-shrink:0">${ico}</span><div><div style="font-size:12px;color:var(--ink-muted)">${lbl}</div><div style="font-size:14px;font-weight:600;color:var(--ink-primary)">${h(val)}</div></div></div>`).join('')}
      </div>
      <a href="${h(C.homeUrl)}vendor-dashboard/" class="n-btn n-btn-g" style="width:100%;margin-top:16px;padding:12px;font-size:14px;text-decoration:none">Open Full Vendor Dashboard →</a>
    </div>`;
  }).catch(e=>{if(el)el.innerHTML=errState(e.message,loadVendorProfile);});
}

/* === EXTENDED SHARED ACTIONS (Phase 2-4) === */
function openBookingDetail(bookingId, bookingUid) {
  if (window._bkdPollCleanup) { window._bkdPollCleanup(); window._bkdPollCleanup = null; }
  const scr = document.getElementById('mob-screen');
  if (scr) renderMobBookingDetail(scr, bookingId, bookingUid);
}

function cancelBooking(bid) {
  if (!confirm('Cancel this booking? This cannot be undone.')) return;
  ajax('nas_pwa_cancel_booking',{booking_id:bid}).then(d=>{toast(d.message||'Booking cancelled');goto('bookings');}).catch(e=>toast(e.message,'error'));
}

function uploadMaterial(bid, input) {
  if (!input.files?.[0]) return;
  const statusEl = document.getElementById('mat-status-'+bid);
  if (statusEl) { statusEl.style.display='block'; statusEl.textContent='Uploading…'; }
  const fd = new FormData(); fd.append('action','nas_pwa_upload_material'); fd.append('nonce',C.nonce); fd.append('booking_id',bid); fd.append('material',input.files[0]);
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(r=>{
    if(r.success){toast('File uploaded ✓');if(statusEl){statusEl.textContent='Uploaded: '+r.data.name;}}
    else{toast(r.data?.message||'Upload failed','error');if(statusEl)statusEl.textContent='Upload failed.';}
  }).catch(e=>{toast(e.message,'error');if(statusEl)statusEl.textContent='Upload failed.';});
}

function sendMsg(bid) {
  const inp=document.getElementById('bkd-chat-inp'),btn=document.getElementById('bkd-send-btn');
  const msg=inp?.value?.trim();
  if(!msg)return;
  if(btn){btn.disabled=true;btn.textContent='…';}
  ajax('nas_pwa_send_message',{booking_id:bid,message:msg}).then(d=>{
    if(inp)inp.value='';
    const P=getP(), chatEl=document.getElementById('bkd-chat-msgs');
    if(chatEl){
      const div=document.createElement('div');div.style.cssText='display:flex;gap:8px;flex-direction:row-reverse';
      div.innerHTML=`<div style="width:28px;height:28px;border-radius:50%;background:${P}20;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:${P}">${av(C.userName)}</div><div style="max-width:75%"><div style="padding:8px 12px;background:${P};color:#060c18;border-radius:14px 14px 4px 14px;font-size:13px;line-height:1.5">${h(msg)}</div></div>`;
      chatEl.appendChild(div);chatEl.scrollTop=chatEl.scrollHeight;
    }
    if(btn){btn.disabled=false;btn.textContent='Send';}
  }).catch(e=>{toast(e.message,'error');if(btn){btn.disabled=false;btn.textContent='Send';}});
}

function doTrack() {
  const uid=document.getElementById('trk-uid')?.value?.trim().toUpperCase();
  const email=document.getElementById('trk-email')?.value?.trim();
  const err=document.getElementById('trk-err'), btn=document.getElementById('trk-btn'), res=document.getElementById('trk-result');
  if(!uid||!email){if(err){err.textContent='Booking ID and email are required';err.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Tracking…';}
  ajax('nas_pwa_track_order',{uid,email}).then(d=>{
    const P=getP(), bk=d.booking||{}, hist=d.history||[];
    const stC={booking_received:'#fbbf24',payment_received:P,published:P,completed:P,rejected:'#f87171',cancelled:'#f87171'};
    if(err)err.style.display='none';
    if(res)res.innerHTML=`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:16px">
      <div style="font-size:18px;font-weight:900;color:${P};margin-bottom:4px">${h(bk.uid)}</div>
      <div style="font-size:15px;font-weight:700;color:var(--ink-primary);margin-bottom:8px">${h(bk.np_name||'Newspaper')} — ${h(bk.cat_name||'Ad')}</div>
      <span class="nbadge ${h(bk.status)}" style="margin-bottom:16px;display:inline-flex">${stLbl(bk.status)}</span>
      <div style="font-size:12px;font-weight:700;color:var(--ink-muted);text-transform:uppercase;margin-bottom:10px">Timeline</div>
      ${hist.map((hh,i)=>`<div style="display:flex;gap:10px"><div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0"><div style="width:20px;height:20px;border-radius:50%;background:${stC[hh.to_status]||P};display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:#060c18">✓</div>${i<hist.length-1?`<div style="width:2px;flex:1;background:var(--border);margin:3px 0;min-height:18px"></div>`:''}</div><div style="padding-bottom:${i<hist.length-1?'10px':'0'}"><div style="font-size:13px;font-weight:700;color:var(--ink-primary)">${stLbl(hh.to_status)}</div><div style="font-size:11px;color:var(--ink-muted)">${fmtD(hh.created_at)}</div></div></div>`).join('')}
    </div>`;
  }).catch(e=>{if(err){err.textContent=e.message;err.style.display='block';}})
  .finally(()=>{if(btn){btn.disabled=false;btn.textContent='Track Order →';}});
}

// Wizard action helpers
function wizSelCat(id,name){WIZ.cat={id,name};document.querySelectorAll('.cat-card').forEach(c=>{c.classList.toggle('active',+c.dataset.catid===id);c.style.borderColor=+c.dataset.catid===id?getP():'';c.style.background=+c.dataset.catid===id?getP()+'20':'';});const btn=document.getElementById('wiz1-next');if(btn&&WIZ.city){btn.disabled=false;btn.style.opacity='1';btn.style.cursor='pointer';}}
function wizSelCity(id,name){WIZ.city={id,name};document.querySelectorAll('.city-chip').forEach(c=>{c.classList.toggle('active',c.textContent.trim()===name);});const btn=document.getElementById('wiz1-next');if(btn&&WIZ.cat){btn.disabled=false;btn.style.opacity='1';btn.style.cursor='pointer';}}
function wizSelNp(id,name,rate){WIZ.newspaper={id,name,rate};document.querySelectorAll('[data-npid]').forEach(c=>{c.style.borderColor=+c.dataset.npid===id?getP():'var(--border)';c.style.background=+c.dataset.npid===id?getP()+'12':'var(--surface-800)';});}
function wizSelAdType(t){WIZ.adType=t;document.querySelectorAll('#wiz-rates-area .city-chip').forEach(c=>{c.classList.toggle('active',c.textContent.trim().replace(/ /g,'_')===t);});wizRecalc();}
function wizUpdateContent(v){WIZ.content=v;WIZ.wordCount=v.trim().split(/\s+/).filter(Boolean).length;const wc=document.getElementById('wiz-wc');if(wc)wc.textContent=`(${WIZ.wordCount} words)`;const btn=document.getElementById('wiz3-next');if(btn){btn.disabled=!WIZ.adType||!v.trim();btn.style.opacity=!WIZ.adType||!v.trim()?'.5':'1';}clearTimeout(WIZ.priceCalcTimer);WIZ.priceCalcTimer=setTimeout(wizRecalc,800);}
function wizRecalc(){if(!WIZ.newspaper||!WIZ.adType)return;ajax('nas_pwa_wizard_calculate',{newspaper_id:WIZ.newspaper.id,category_id:WIZ.cat?.id||0,ad_type:WIZ.adType,word_count:WIZ.wordCount,width_cm:0,height_cm:0}).then(d=>{WIZ.base=d.base;WIZ.gst=d.gst;WIZ.total=d.total;const box=document.getElementById('wiz-price-preview');if(box){box.style.display='block';box.querySelector('div:nth-child(2)').textContent=fmtP(d.total);box.querySelector('div:nth-child(3)').textContent=`Base: ${fmtP(d.base)} + GST: ${fmtP(d.gst)}`;}}).catch(()=>{});}
function wizValidate4(){const btn=document.getElementById('wiz4-next');if(btn){btn.disabled=!WIZ.publishDate;btn.style.opacity=!WIZ.publishDate?'.5':'1';}}
function wizSearchNp(q){clearTimeout(WIZ._npTimer);WIZ._npTimer=setTimeout(()=>{const el=document.getElementById('wiz-np-list');if(el)el.innerHTML='<div class="n-loading" style="padding:20px"><span class="n-spinner"></span></div>';ajax('nas_pwa_wizard_newspapers',{category_id:WIZ.cat?.id||0,city_id:WIZ.city?.id||0,search:q}).then(d=>{if(el)el.innerHTML=d.newspapers?.map(n=>`<div class="np-card" onclick="NAS.wizSelNp(${n.id},'${h(n.name)}',${n.base_rate||0})" data-npid="${n.id}" role="button" tabindex="0">${n.logo_url?`<img class="np-logo" src="${h(n.logo_url)}" alt="${h(n.name)}" loading="lazy">`:'<div class="np-logo-ph">📰</div>'}<div class="np-info"><div class="np-name">${h(n.name)}</div></div><div class="np-right">${n.base_rate?`<div class="np-price">${fmtP(n.base_rate)}</div>`:''}</div></div>`).join('')||`<div class="n-empty"><span class="n-empty-icon">🔍</span><div class="n-empty-title">No results</div></div>`;}).catch(()=>{});},400);}

function wizStep2(){if(!WIZ.cat||!WIZ.city){toast('Please select category and city','error');return;}WIZ.step=2;const scr=document.getElementById('mob-screen');buildWizardStep2(scr);}
function wizStep3(){if(!WIZ.newspaper){toast('Please select a newspaper','error');return;}WIZ.step=3;const scr=document.getElementById('mob-screen');buildWizardStep3(scr);}
function wizStep4(){if(!WIZ.adType||!WIZ.content){toast('Please fill in your ad content','error');return;}WIZ.step=4;const scr=document.getElementById('mob-screen');buildWizardStep4(scr);}
// Auto-add coupon field to wizard step 4 when it builds
(function(){
  const _origStep4 = buildWizardStep4;
  buildWizardStep4 = function(scr){
    _origStep4(scr);
    const wrap = document.getElementById('wiz-coupon-wrap');
    if(wrap) renderCouponField(wrap, WIZ.total, function(disc){WIZ.discountAmount=disc;WIZ.total=Math.max(0,WIZ.total-disc);});
  };
})();

function wizStep5(){if(!WIZ.publishDate){toast('Please choose a publish date','error');return;}WIZ.step=5;const scr=document.getElementById('mob-screen');buildWizardStep5(scr);}
function wizBack(){WIZ.step=Math.max(1,WIZ.step-1);const scr=document.getElementById('mob-screen');switch(WIZ.step){case 1:renderWizard(scr);break;case 2:buildWizardStep2(scr);break;case 3:buildWizardStep3(scr);break;case 4:buildWizardStep4(scr);break;}}

function wizSubmit(){
  const name=document.getElementById('wiz-name')?.value?.trim();
  const email=document.getElementById('wiz-email')?.value?.trim();
  const phone=document.getElementById('wiz-phone')?.value?.trim();
  if(!name||!email||!phone){const err=document.getElementById('wiz-submit-err');if(err){err.textContent='Name, email, and phone are required';err.style.display='block';}return;}
  WIZ.name=name;WIZ.email=email;WIZ.phone=phone;
  WIZ.company=document.getElementById('wiz-company')?.value?.trim()||'';
  WIZ.notes=document.getElementById('wiz-notes')?.value?.trim()||'';
  const btn=document.getElementById('wiz-submit-btn');if(btn){btn.disabled=true;btn.textContent='Submitting…';}
  ajax('nas_pwa_wizard_submit',{newspaper_id:WIZ.newspaper.id,category_id:WIZ.cat.id,city_id:WIZ.city.id,ad_type:WIZ.adType,ad_title:WIZ.title||WIZ.content.slice(0,60),ad_content:WIZ.content,publish_date:WIZ.publishDate,client_name:name,client_email:email,client_phone:phone,client_company:WIZ.company,client_notes:WIZ.notes,base_amount:WIZ.base,gst_amount:WIZ.gst,total_amount:WIZ.total}).then(d=>{
    // Show inline confirmation on the same step-5 screen, then redirect to dashboard
    const submitBtn=document.getElementById('wiz-submit-btn');
    if(submitBtn){
      submitBtn.disabled=true;
      submitBtn.style.background='#22c55e';
      submitBtn.textContent='✓ Booking Submitted — Ref: '+d.uid;
    }
    // Show a compact confirmation line below the button
    const confirmLine=document.createElement('p');
    confirmLine.style.cssText='font-size:13px;color:#22c55e;text-align:center;margin-top:10px;font-weight:600';
    confirmLine.textContent='Booking ID: '+d.uid+' — A confirmation has been sent to your email.';
    submitBtn?.parentNode?.insertBefore(confirmLine, submitBtn.nextSibling);
    // Reset wizard state silently
    Object.assign(WIZ,{step:1,cat:null,city:null,newspaper:null,adType:'',title:'',content:'',wordCount:0,publishDate:'',total:0,base:0,gst:0});
    // Redirect to client dashboard after 2 seconds
    setTimeout(()=>{ window.location.href = C.dashUrl; }, 2000);
  }).catch(e=>{const err=document.getElementById('wiz-submit-err');if(err){err.textContent=e.message;err.style.display='block';}if(btn){btn.disabled=false;btn.textContent='📋 Confirm Booking';}});
}

function vendorTab(tab){
  document.querySelectorAll('#vendor-tabs .city-chip').forEach(c=>c.classList.remove('active'));
  const active=document.getElementById('vt-'+tab);if(active)active.classList.add('active');
  switch(tab){case'bookings':loadVendorBookings(1);break;case'earnings':loadVendorEarnings();break;case'profile':loadVendorProfile();break;}
}
function vendorMarkPublished(bid){if(!confirm('Mark this booking as published?'))return;ajax('nas_pwa_vendor_mark_published',{booking_id:bid}).then(d=>{toast(d.message||'Marked as published');loadVendorBookings(1);}).catch(e=>toast(e.message,'error'));}
function vendorUploadProof(bid,input){
  if(!input.files?.[0])return;
  toast('Uploading proof…','info');
  const fd=new FormData();fd.append('action','nas_pwa_vendor_upload_proof');fd.append('nonce',C.nonce);fd.append('booking_id',bid);fd.append('proof',input.files[0]);
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(r=>{if(r.success){toast('Proof uploaded ✓');loadVendorBookings(1);}else toast(r.data?.message||'Upload failed','error');}).catch(e=>toast(e.message,'error'));
}
function loadVendorBkPg(p){loadVendorBookings(p);}
function openVendorBkDetail(id){/* opens detail within vendor context */}



/* ══════════════════════════════════════════════════════════════════
   PHASE 8: ADVANCED UX
   - Analytics tracking (screen views, events, install)
   - Push notification subscription prompt
   - Pull-to-refresh on mobile
   - Search autocomplete with debounce
   - Skeleton loading screens
   - Offline detection + banner
══════════════════════════════════════════════════════════════════ */

/* === Phase 8A: Analytics Tracking === */
const ANA = {
  sessionId: Math.random().toString(36).slice(2)+Date.now().toString(36),
  track(eventType, screen='', meta=null) {
    const fd = new FormData();
    fd.append('action','nas_pwa_track');
    fd.append('nonce', C.nonce);
    fd.append('nas_action', '1');
    fd.append('event_type', eventType);
    fd.append('screen', screen);
    fd.append('session_id', this.sessionId);
    if (meta) fd.append('meta', JSON.stringify(meta));
    // Fire-and-forget — never block UI
    fetch(C.ajax, {method:'POST', body:fd, credentials:'same-origin'}).catch(()=>{});
  },
  screenView(screen) { this.track('screen_view', screen); },
  event(type, meta)  { this.track(type, '', meta); },
};

/* === Phase 8B: Push Notification Permission === */
async function requestPushPermission() {
  if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
  if (Notification.permission === 'denied') return;
  if (!C.isLoggedIn) return;
  if (localStorage.getItem('nas_push_dismissed')) return;

  // Ask for permission after a short delay (don't prompt immediately)
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') { localStorage.setItem('nas_push_dismissed','1'); return; }

  try {
    const reg = await navigator.serviceWorker.ready;
    // Use applicationServerKey if VAPID public key provided
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      // applicationServerKey: urlBase64ToUint8Array(C.vapidPublicKey), // uncomment when VAPID set up
    });
    const fd = new FormData();
    fd.append('action','nas_pwa_push_subscribe_v2');
    fd.append('nonce', C.nonce);
    fd.append('subscription', JSON.stringify(sub));
    await fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'});
    ANA.event('push_subscribed');
  } catch(e) {
    console.warn('[NAS Push]', e.message);
  }
}

/* === Phase 8C: Offline Banner === */
function initOfflineBanner() {
  const banner = document.createElement('div');
  banner.id = 'nas-offline-banner';
  banner.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;z-index:9999;background:#1e293b;color:#f1f5f9;font-size:13px;font-weight:600;text-align:center;padding:10px;border-bottom:1px solid #334155';
  banner.textContent = '📡 You are offline — some features may be unavailable';
  document.body.appendChild(banner);

  function updateStatus() {
    banner.style.display = navigator.onLine ? 'none' : 'block';
    document.getElementById('nas-app').style.marginTop = navigator.onLine ? '0' : '42px';
  }

  window.addEventListener('online',  updateStatus);
  window.addEventListener('offline', updateStatus);
  updateStatus();
}

/* === Phase 8D: Pull-to-Refresh (mobile) === */
function initPullToRefresh() {
  if (window.innerWidth >= 768) return;
  const scr = document.getElementById('mob-screen');
  if (!scr) return;

  let startY=0, pulling=false;
  const indicator = document.createElement('div');
  indicator.style.cssText = 'position:absolute;top:-48px;left:50%;transform:translateX(-50%);width:36px;height:36px;border-radius:50%;background:var(--surface-800);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px;opacity:0;transition:opacity .2s;z-index:10';
  indicator.textContent = '↓';
  scr.style.position = 'relative';
  scr.prepend(indicator);

  scr.addEventListener('touchstart', e => {
    if (scr.scrollTop === 0) { startY = e.touches[0].pageY; pulling = true; }
  }, {passive:true});

  scr.addEventListener('touchmove', e => {
    if (!pulling) return;
    const delta = e.touches[0].pageY - startY;
    if (delta > 10 && delta < 80) {
      indicator.style.opacity = String(delta/80);
      indicator.style.top = (delta/2 - 48)+'px';
      indicator.textContent = delta > 50 ? '↺' : '↓';
    }
  }, {passive:true});

  scr.addEventListener('touchend', e => {
    if (!pulling) return;
    const delta = e.changedTouches[0].pageY - startY;
    pulling = false;
    indicator.style.opacity = '0';
    indicator.style.top = '-48px';
    if (delta > 60) {
      // Refresh current screen
      S.homeData = null;
      renderMob(S.screen);
      ANA.event('pull_to_refresh', {screen: S.screen});
    }
  }, {passive:true});
}

/* === Phase 8E: Search Autocomplete === */
let autoCompleteTimer;
function initDeskSearchAutocomplete() {
  const inp = document.getElementById('desk-search-inp');
  if (!inp) return;

  const dropdown = document.createElement('div');
  dropdown.id = 'desk-search-ac';
  dropdown.style.cssText = 'display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.4);overflow:hidden;z-index:200;max-height:320px;overflow-y:auto';

  const wrap = inp.closest('.desk-search-bar');
  if (wrap) { wrap.style.position='relative'; wrap.appendChild(dropdown); }

  inp.addEventListener('input', function() {
    const q = this.value.trim();
    clearTimeout(autoCompleteTimer);
    if (q.length < 2) { dropdown.style.display='none'; return; }
    autoCompleteTimer = setTimeout(() => {
      ajax('nas_pwa_search', {q}).then(d => {
        const results = d.results || [];
        if (!results.length) { dropdown.style.display='none'; return; }
        const P=getP();
        dropdown.innerHTML = results.map(r => {
          const icon = r.type==='newspaper'?'📰':r.type==='category'?catEmoji(r.name):'📍';
          return `<button style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 14px;background:none;border:none;border-bottom:1px solid var(--border);cursor:pointer;font-family:inherit;transition:background .1s;text-align:left" onclick="NAS.deskTag('${h(r.name)}');document.getElementById('desk-search-ac').style.display='none'" onmouseover="this.style.background='var(--surface-700)'" onmouseout="this.style.background='none'">
            <span style="font-size:18px;flex-shrink:0">${icon}</span>
            <div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:var(--ink-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(r.name)}</div><div style="font-size:11px;color:var(--ink-muted)">${r.city_name||r.state||r.type}</div></div>
          </button>`;
        }).join('');
        dropdown.style.display = 'block';
      }).catch(() => {});
    }, 250);
  });

  document.addEventListener('click', e => {
    if (!wrap?.contains(e.target)) dropdown.style.display = 'none';
  });
}

/* === Phase 8F: Skeleton Screens === */
function skeletonNpCard() {
  return `<div style="display:flex;align-items:center;gap:14px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:14px">
    <div class="shimmer" style="width:50px;height:50px;border-radius:10px;flex-shrink:0"></div>
    <div style="flex:1;display:flex;flex-direction:column;gap:6px">
      <div class="shimmer" style="height:14px;width:60%;border-radius:6px"></div>
      <div class="shimmer" style="height:12px;width:40%;border-radius:6px"></div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
      <div class="shimmer" style="height:16px;width:60px;border-radius:6px"></div>
      <div class="shimmer" style="height:30px;width:50px;border-radius:9px"></div>
    </div>
  </div>`;
}

function skeletonCatGrid() {
  return `<div class="cat-grid">${Array(8).fill(0).map(()=>`<div class="shimmer" style="height:80px;border-radius:16px"></div>`).join('')}</div>`;
}

function skeletonHome() {
  return `<div style="padding:16px">
    <div class="shimmer" style="height:140px;border-radius:16px;margin-bottom:16px"></div>
    ${skeletonCatGrid()}
    <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px">${Array(3).fill(0).map(()=>skeletonNpCard()).join('')}</div>
  </div>`;
}

/* === Phase 8G: Enhanced push subscription prompt (in-app banner) === */
function showPushPrompt() {
  if (!C.isLoggedIn) return;
  if (localStorage.getItem('nas_push_dismissed')) return;
  if (Notification.permission === 'granted') return;
  if (Notification.permission === 'denied') return;

  const P=getP();
  const banner = document.createElement('div');
  banner.style.cssText = `position:fixed;bottom:calc(var(--tab-bottom,64px) + 12px);left:12px;right:12px;background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:14px;box-shadow:0 8px 32px rgba(0,0,0,.5);z-index:400;display:flex;align-items:center;gap:12px;animation:fadeUp .3s ease`;
  banner.innerHTML = `<span style="font-size:28px;flex-shrink:0">🔔</span>
    <div style="flex:1;min-width:0">
      <div style="font-size:14px;font-weight:700;color:var(--ink-primary);margin-bottom:2px">Enable Notifications</div>
      <div style="font-size:12px;color:var(--ink-muted)">Get updates when your ad status changes</div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0">
      <button onclick="this.closest('div[style]').remove();localStorage.setItem('nas_push_dismissed','1')" style="background:var(--surface-700);border:none;border-radius:9px;padding:7px 12px;font-size:12px;font-weight:600;color:var(--ink-muted);cursor:pointer;font-family:inherit">Not now</button>
      <button onclick="requestPushPermission();this.closest('div[style]').remove()" style="background:${P};border:none;border-radius:9px;padding:7px 12px;font-size:12px;font-weight:800;color:#060c18;cursor:pointer;font-family:inherit">Enable</button>
    </div>`;
  document.body.appendChild(banner);

  // Auto-dismiss after 8 seconds
  setTimeout(() => { if (banner.parentNode) banner.remove(); }, 8000);
}

/* === Phase 8H: Analytics hooks integrated into existing navigation === */
// Override goto() to add analytics tracking
const _origGoto = goto;
function gotoWithAnalytics(screen) {
  ANA.screenView(screen);
  // Update meta per screen
  const screenTitles = {home:C.brand, browse:'Browse Newspapers', book:'Book an Ad', bookings:'My Bookings', account:'My Account', blog:'Blog', faq:'FAQs', contact:'Contact Us', rates:'Rate Card', register:'Create Account', notifications:'Notifications', wallet:'My Wallet', saved:'Saved Newspapers', track:'Track Order', vendor:'Vendor Portal'};
  updateMeta(screenTitles[screen]||C.brand, C.tagline, C.logo);
  _origGoto(screen);
}

const _origDeskGoto = deskGoto;
function deskGotoWithAnalytics(page) {
  ANA.screenView('desk_'+page);
  _origDeskGoto(page);
}

/* === Phase 8I: VAPID key config in settings (new Config keys) === */
// Adds pwa_vapid_public_key and pwa_vapid_private_key to admin settings
// (UI added in pwa-settings.php, keys stored in Config)



/* ══════════════════════════════════════════════════════════════════
   FINAL PHASES: Blog, FAQ, Contact, Rate Card, Register, Payment
══════════════════════════════════════════════════════════════════ */

/* === BLOG SCREEN === */
function renderBlog(scr) {
  scr.innerHTML='<div><div style="padding:16px 16px 12px"><div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:2px">Blog</div><div style="font-size:13px;color:var(--ink-muted)">News, tips and updates</div></div><div class="n-loading" id="blog-spin"><span class="n-spinner"></span></div><div id="blog-list"></div></div>';
  ajax('nas_get_blog_posts',{per_page:12}).then(d=>{
    document.getElementById('blog-spin')?.remove();
    const posts=d.posts||d||[], el=document.getElementById('blog-list');
    if(!el)return;
    if(!posts.length){el.innerHTML='<div class="n-empty"><span class="n-empty-icon">📝</span><div class="n-empty-title">No posts yet</div></div>';return;}
    const P=getP();
    el.innerHTML=posts.map(p=>`<div style="margin:0 16px 12px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;overflow:hidden;cursor:pointer" onclick="NAS.openBlogPost(${p.id})">
      ${p.image_url?`<div style="height:160px;background:url('${h(p.image_url)}') center/cover no-repeat"></div>`:''}
      <div style="padding:14px">
        ${p.category?`<div style="font-size:11px;font-weight:700;color:${P};text-transform:uppercase;margin-bottom:4px">${h(p.category)}</div>`:''}
        <div style="font-size:16px;font-weight:800;color:var(--ink-primary);margin-bottom:6px;line-height:1.3">${h(p.title)}</div>
        <div style="font-size:13px;color:var(--ink-muted);line-height:1.5;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${h(p.excerpt||p.content||'').replace(/<[^>]+>/g,'').slice(0,120)}…</div>
        <div style="font-size:11px;color:var(--ink-muted)">${fmtD(p.published_at||p.created_at)} ${p.read_time?`· ${p.read_time} min read`:''}</div>
      </div>
    </div>`).join('');
  }).catch(e=>{document.getElementById('blog-spin')?.remove();document.getElementById('blog-list').innerHTML=errState(e.message,()=>renderBlog(scr));});
}

function openBlogPost(id) {
  const scr=document.getElementById('mob-screen');
  scr.innerHTML=`<div><div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)"><button onclick="NAS.goto('blog')" style="background:var(--surface-800);border:1px solid var(--border);border-radius:10px;padding:6px 12px;font-size:13px;color:var(--ink-secondary);cursor:pointer;font-family:inherit">← Blog</button></div><div class="n-loading"><span class="n-spinner"></span></div><div id="blog-post-content"></div></div>`;
  ajax('nas_get_blog_post',{id}).then(d=>{
    document.querySelector('.n-loading')?.remove();
    const p=d.post||d, el=document.getElementById('blog-post-content');if(!el)return;
    const P=getP();
    el.innerHTML=`<div style="padding:20px 16px">
      ${p.category?`<div style="font-size:11px;font-weight:700;color:${P};text-transform:uppercase;margin-bottom:8px">${h(p.category)}</div>`:''}
      <h1 style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:8px;line-height:1.3">${h(p.title)}</h1>
      <div style="font-size:12px;color:var(--ink-muted);margin-bottom:20px;display:flex;align-items:center;gap:8px">${fmtD(p.published_at||p.created_at)} ${p.read_time?`<span>·</span><span>${p.read_time} min read</span>`:''}</div>
      ${p.image_url?`<div style="height:200px;background:url('${h(p.image_url)}') center/cover no-repeat;border-radius:14px;margin-bottom:20px"></div>`:''}
      <div style="font-size:15px;color:var(--ink-secondary);line-height:1.8;white-space:pre-wrap">${h((p.content||'').replace(/<[^>]+>/g,''))}</div>
    </div>`;
  }).catch(e=>{document.querySelector('.n-loading')?.remove();document.getElementById('blog-post-content').innerHTML=errState(e.message,()=>openBlogPost(id));});
}

/* === FAQ SCREEN === */
function renderFAQ(scr) {
  scr.innerHTML='<div><div style="padding:16px 16px 12px"><div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:2px">FAQs</div><div style="font-size:13px;color:var(--ink-muted)">Frequently asked questions</div></div><div class="n-loading" id="faq-spin"><span class="n-spinner"></span></div><div id="faq-list" style="padding:0 16px 16px"></div></div>';
  ajax('nas_get_faqs').then(d=>{
    document.getElementById('faq-spin')?.remove();
    const faqs=d.faqs||d||[], el=document.getElementById('faq-list');if(!el)return;
    if(!faqs.length){el.innerHTML='<div class="n-empty"><span class="n-empty-icon">❓</span><div class="n-empty-title">No FAQs yet</div></div>';return;}
    const P=getP();
    // Group by category
    const grouped={};
    faqs.forEach(f=>{const cat=f.category||'General';if(!grouped[cat])grouped[cat]=[];grouped[cat].push(f);});
    el.innerHTML=Object.entries(grouped).map(([cat,items])=>`
      <div style="margin-bottom:20px">
        <div style="font-size:12px;font-weight:700;color:${P};text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">${h(cat)}</div>
        ${items.map((f,i)=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:8px">
          <button onclick="NAS.toggleFAQ(this)" style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:14px;background:none;border:none;cursor:pointer;font-family:inherit;text-align:left">
            <span style="font-size:14px;font-weight:700;color:var(--ink-primary);flex:1;padding-right:12px">${h(f.question)}</span>
            <span style="font-size:18px;color:var(--ink-muted);flex-shrink:0;transition:transform .2s">▾</span>
          </button>
          <div style="display:none;padding:0 14px 14px;font-size:14px;color:var(--ink-secondary);line-height:1.7">${h(f.answer||'')}</div>
        </div>`).join('')}
      </div>`).join('');
  }).catch(e=>{document.getElementById('faq-spin')?.remove();document.getElementById('faq-list').innerHTML=errState(e.message,()=>renderFAQ(scr));});
}

function toggleFAQ(btn) {
  const answer=btn.nextElementSibling, arrow=btn.querySelector('span:last-child');
  const isOpen=answer.style.display==='block';
  answer.style.display=isOpen?'none':'block';
  if(arrow)arrow.style.transform=isOpen?'none':'rotate(180deg)';
}

/* === CONTACT SCREEN === */
function renderContact(scr) {
  const P=getP();
  scr.innerHTML=`<div style="padding:20px 16px">
    <div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:6px">Contact Us</div>
    <p style="font-size:14px;color:var(--ink-muted);margin-bottom:24px;line-height:1.5">Send us a message and we'll respond within 24 hours.</p>
    <div class="n-field"><label class="n-lbl" for="ct-name">Full Name</label><input class="n-input" id="ct-name" placeholder="Your name" value="${h(C.userName)}"></div>
    <div class="n-field"><label class="n-lbl" for="ct-email">Email Address</label><input class="n-input" id="ct-email" type="email" placeholder="your@email.com"></div>
    <div class="n-field"><label class="n-lbl" for="ct-phone">Phone <span style="color:var(--ink-muted);font-weight:400">(optional)</span></label><input class="n-input" id="ct-phone" type="tel" placeholder="Mobile number"></div>
    <div class="n-field"><label class="n-lbl" for="ct-subject">Subject</label>
      <select class="n-input" id="ct-subject" style="cursor:pointer">
        <option value="General Enquiry">General Enquiry</option>
        <option value="Booking Support">Booking Support</option>
        <option value="Payment Issue">Payment Issue</option>
        <option value="Ad Content Help">Ad Content Help</option>
        <option value="Rate Card Query">Rate Card Query</option>
        <option value="Feedback">Feedback</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="n-field"><label class="n-lbl" for="ct-msg">Message</label><textarea class="n-input" id="ct-msg" rows="5" placeholder="Describe your query…" style="resize:vertical"></textarea></div>
    ${C.phone?`<div style="display:flex;align-items:center;gap:10px;padding:12px;background:var(--surface-800);border:1px solid var(--border);border-radius:12px;margin-bottom:16px">
      <span style="font-size:20px;flex-shrink:0">📞</span>
      <div><div style="font-size:12px;color:var(--ink-muted)">Or call us directly</div><a href="tel:${h(C.phone)}" style="font-size:15px;font-weight:700;color:${P};text-decoration:none">${h(C.phone)}</a></div>
    </div>`:''}
    <span class="n-err" id="ct-err" style="display:block;margin-bottom:10px"></span>
    <button class="n-btn n-btn-p" id="ct-btn" style="width:100%;padding:13px" onclick="NAS.submitContact()">Send Message →</button>
  </div>`;
}

function submitContact() {
  const name=document.getElementById('ct-name')?.value?.trim();
  const email=document.getElementById('ct-email')?.value?.trim();
  const msg=document.getElementById('ct-msg')?.value?.trim();
  const subject=document.getElementById('ct-subject')?.value;
  const phone=document.getElementById('ct-phone')?.value?.trim();
  const err=document.getElementById('ct-err'), btn=document.getElementById('ct-btn');
  if(!name||!email||!msg){if(err){err.textContent='Name, email, and message are required';err.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Sending…';}
  ajax('nas_submit_contact',{name,email,phone,subject,message:msg}).then(()=>{
    const scr=document.getElementById('mob-screen');
    scr.innerHTML=`<div style="padding:48px 20px;text-align:center"><div style="font-size:56px;margin-bottom:16px">✅</div><div style="font-size:20px;font-weight:800;color:var(--ink-primary);margin-bottom:8px">Message Sent!</div><div style="font-size:14px;color:var(--ink-muted);margin-bottom:24px">We'll get back to you within 24 hours.</div><button class="n-btn n-btn-g" style="margin:0 auto;display:flex" onclick="NAS.goto('home')">← Back to Home</button></div>`;
  }).catch(e=>{if(err){err.textContent=e.message;err.style.display='block';}if(btn){btn.disabled=false;btn.textContent='Send Message →';}});
}

/* === RATE CARD SCREEN === */
function renderRateCard(scr) {
  const P=getP();
  scr.innerHTML=`<div><div style="padding:16px 16px 12px"><div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:2px">Rate Card</div><div style="font-size:13px;color:var(--ink-muted)">Compare newspaper ad rates</div></div>
    <div style="padding:0 16px 8px"><div style="display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:12px;padding:9px 12px"><span style="color:var(--ink-muted)">🔍</span><input type="text" id="rc-search" placeholder="Search newspaper…" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-family:inherit" oninput="NAS.rcSearch(this.value)"></div></div>
    <div id="rc-cities" style="display:flex;gap:8px;padding:6px 16px 8px;overflow-x:auto;scrollbar-width:none"></div>
    <div class="n-loading" id="rc-spin"><span class="n-spinner"></span></div>
    <div id="rc-list" style="padding:0 16px 16px"></div>
  </div>`;
  loadRateCard();
}

let rcCityFilter=0, rcSearchTimer;
function loadRateCard(search='') {
  const spin=document.getElementById('rc-spin'), list=document.getElementById('rc-list');
  if(spin)spin.style.display='flex';if(list)list.style.display='none';
  ajax('nas_pwa_rate_card',{city_id:rcCityFilter,search}).then(d=>{
    if(spin)spin.style.display='none';if(list)list.style.display='block';
    const P=getP(), nps=d.newspapers||[], cities=d.cities||[];
    // Render city chips
    const citiesEl=document.getElementById('rc-cities');
    if(citiesEl)citiesEl.innerHTML=`<button class="city-chip${!rcCityFilter?' active':''}" onclick="NAS.rcCity(0)">All Cities</button>`+cities.map(c=>`<button class="city-chip${rcCityFilter===c.id?' active':''}" onclick="NAS.rcCity(${c.id})">${h(c.name)}</button>`).join('');
    if(!nps.length){list.innerHTML='<div class="n-empty"><span class="n-empty-icon">📰</span><div class="n-empty-title">No newspapers found</div></div>';return;}
    list.innerHTML=nps.map(np=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:14px;margin-bottom:12px;overflow:hidden">
      <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid var(--border)">
        ${np.logo_url?`<img src="${h(np.logo_url)}" style="width:40px;height:40px;border-radius:9px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border);flex-shrink:0" alt="${h(np.name)}" loading="lazy">`:'<div style="width:40px;height:40px;border-radius:9px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">📰</div>'}
        <div style="flex:1;min-width:0"><div style="font-size:15px;font-weight:800;color:var(--ink-primary)">${h(np.name)}</div><div style="font-size:12px;color:var(--ink-muted)">${np.city_name?h(np.city_name)+' · ':''}${np.language?h(np.language):''}</div></div>
        <button class="n-btn n-btn-p n-btn-sm" onclick="NAS.bookNp(${np.id})" style="font-size:12px">Book</button>
      </div>
      ${np.rates?.length?`<table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="background:var(--surface-900)"><th style="text-align:left;padding:7px 10px;color:var(--ink-muted);font-weight:600">Ad Type</th><th style="text-align:right;padding:7px 10px;color:var(--ink-muted);font-weight:600">Rate</th><th style="text-align:right;padding:7px 10px;color:var(--ink-muted);font-weight:600">Unit</th></tr></thead>
        <tbody>${np.rates.slice(0,6).map(r=>`<tr style="border-top:1px solid var(--border)"><td style="padding:7px 10px;color:var(--ink-secondary)">${h(r.ad_type?.replace(/_/g,' ')||'Standard')}</td><td style="text-align:right;padding:7px 10px;font-weight:700;color:${P}">${fmtP(r.rate_per_unit||0)}</td><td style="text-align:right;padding:7px 10px;color:var(--ink-muted)">${h(r.unit||'/ad')}</td></tr>`).join('')}</tbody>
      </table>`:np.rates?.length===0?`<div style="padding:10px 14px;font-size:12px;color:var(--ink-muted)">Contact us for rate card</div>`:''}
    </div>`).join('');
  }).catch(e=>{if(spin)spin.style.display='none';if(list){list.style.display='block';list.innerHTML=errState(e.message,()=>loadRateCard());}});
}
function rcSearch(q){clearTimeout(rcSearchTimer);rcSearchTimer=setTimeout(()=>loadRateCard(q),400);}
function rcCity(id){rcCityFilter=id;loadRateCard(document.getElementById('rc-search')?.value||'');}

/* === CLIENT REGISTRATION SCREEN === */
function renderRegister(scr) {
  const P=getP();
  scr.innerHTML=`<div style="padding:24px 16px">
    <div style="text-align:center;margin-bottom:24px">
      <div style="font-size:48px;margin-bottom:12px">👤</div>
      <div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:4px">Create Account</div>
      <div style="font-size:14px;color:var(--ink-muted)">Manage bookings, track ads, view invoices</div>
    </div>
    <div class="n-field"><label class="n-lbl" for="reg-name">Full Name</label><input class="n-input" id="reg-name" placeholder="Your full name" autocomplete="name"><span class="n-err" id="reg-name-err"></span></div>
    <div class="n-field"><label class="n-lbl" for="reg-email">Email Address</label><input class="n-input" id="reg-email" type="email" placeholder="your@email.com" autocomplete="email"><span class="n-err" id="reg-email-err"></span></div>
    <div class="n-field"><label class="n-lbl" for="reg-phone">Phone Number</label><input class="n-input" id="reg-phone" type="tel" placeholder="10-digit mobile number" autocomplete="tel"><span class="n-err" id="reg-phone-err"></span></div>
    <div class="n-field"><label class="n-lbl" for="reg-pass">Password <span style="color:var(--ink-muted);font-weight:400">(min. 8 characters)</span></label><input class="n-input" id="reg-pass" type="password" placeholder="••••••••" autocomplete="new-password"><span class="n-err" id="reg-pass-err"></span></div>
    <div id="reg-strength" style="height:3px;border-radius:99px;background:var(--border);margin:-8px 0 12px;transition:all .3s"></div>
    <span class="n-err" id="reg-err" style="display:block;margin-bottom:10px"></span>
    <button class="n-btn n-btn-p" id="reg-btn" style="width:100%;padding:14px" onclick="NAS.doRegister()">Create Account →</button>
    <div style="margin-top:16px;text-align:center;font-size:13px;color:var(--ink-muted)">
      Already have an account? <span onclick="NAS.goto('account')" style="color:${P};font-weight:700;cursor:pointer">Sign In →</span>
    </div>
    <div style="margin-top:12px;text-align:center;font-size:11px;color:var(--ink-muted);line-height:1.6">
      By registering you agree to our <span style="color:${P};cursor:pointer">Terms of Service</span> and <span style="color:${P};cursor:pointer">Privacy Policy</span>.
    </div>
  </div>`;
  // Password strength indicator
  document.getElementById('reg-pass')?.addEventListener('input', function() {
    const v=this.value, bar=document.getElementById('reg-strength');
    if(!bar)return;
    let strength=0;
    if(v.length>=8)strength++;if(/[A-Z]/.test(v))strength++;if(/[0-9]/.test(v))strength++;if(/[^a-zA-Z0-9]/.test(v))strength++;
    const colors=['#ef4444','#f59e0b','#22c55e','#00d084'];
    const widths=['25%','50%','75%','100%'];
    bar.style.background=colors[strength-1]||'var(--border)';bar.style.width=widths[strength-1]||'0%';
  });
}

function doRegister() {
  const name=document.getElementById('reg-name')?.value?.trim();
  const email=document.getElementById('reg-email')?.value?.trim();
  const phone=document.getElementById('reg-phone')?.value?.trim();
  const pass=document.getElementById('reg-pass')?.value;
  const err=document.getElementById('reg-err'), btn=document.getElementById('reg-btn');
  let valid=true;
  const fields=[['reg-name-err',!name,'Name required'],['reg-email-err',!email||!/^[^@]+@[^@]+\.[^@]+$/.test(email),'Valid email required'],['reg-phone-err',!phone,'Phone required'],['reg-pass-err',!pass||pass.length<8,'Password must be at least 8 characters']];
  fields.forEach(([id,invalid,msg])=>{const e=document.getElementById(id);if(e){e.textContent=invalid?msg:'';e.style.display=invalid?'block':'none';}if(invalid)valid=false;});
  if(!valid)return;
  if(btn){btn.disabled=true;btn.textContent='Creating account…';}
  ajax('nas_pwa_register',{name,email,phone,password:pass}).then(d=>{
    C.isLoggedIn=true;C.userId=d.user_id;C.userName=d.name;
    toast('Welcome, '+d.name+'! Account created ✓');
    goto('bookings');
  }).catch(e=>{if(err){err.textContent=e.message;err.style.display='block';}if(btn){btn.disabled=false;btn.textContent='Create Account →';}});
}

/* === IN-PWA PAYMENT SCREEN === */
function renderPayment(scr, bookingUid) {
  const P=getP();
  scr.innerHTML=`<div style="padding:20px 16px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <button onclick="NAS.goto('bookings')" style="background:var(--surface-800);border:1px solid var(--border);border-radius:10px;padding:6px 12px;font-size:13px;color:var(--ink-secondary);cursor:pointer;font-family:inherit">← Back</button>
      <div style="font-size:20px;font-weight:900;color:var(--ink-primary)">Complete Payment</div>
    </div>
    <div class="n-loading" id="pay-spin"><span class="n-spinner"></span></div>
    <div id="pay-content" style="display:none"></div>
  </div>`;
  ajax('nas_pwa_init_payment',{uid:bookingUid}).then(d=>{
    document.getElementById('pay-spin')?.remove();
    const el=document.getElementById('pay-content');if(!el)return;
    el.style.display='block';
    if(d.gateway==='razorpay'){renderRazorpayPayment(el, d);}
    else if(d.gateway==='payu'){renderPayUPayment(el, d);}
    else{el.innerHTML=errState('Unsupported payment gateway',()=>renderPayment(scr,bookingUid));}
  }).catch(e=>{document.getElementById('pay-spin')?.remove();document.getElementById('pay-content').style.display='block';document.getElementById('pay-content').innerHTML=errState(e.message,()=>renderPayment(scr,bookingUid));});
}

function renderRazorpayPayment(el, d) {
  const P=getP(), amt=(d.amount/100).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  el.innerHTML=`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:20px">
    <div style="font-size:12px;color:var(--ink-muted);margin-bottom:4px">Booking</div>
    <div style="font-size:16px;font-weight:800;color:${P};margin-bottom:2px">${h(d.booking_uid)}</div>
    <div style="font-size:12px;color:var(--ink-muted)">${h(d.description||'')}</div>
    <div style="font-size:26px;font-weight:900;color:var(--ink-primary);margin-top:12px">${C.currency}${amt}</div>
  </div>
  <div id="rzp-status" style="font-size:13px;color:var(--ink-muted);margin-bottom:14px;text-align:center">Secure payment via Razorpay</div>
  <button class="n-btn n-btn-p" id="rzp-pay-btn" style="width:100%;padding:14px;font-size:16px" onclick="NAS.launchRazorpay(${JSON.stringify(d).replace(/"/g,'&quot;')})">
    💳 Pay ${C.currency}${amt} Now
  </button>`;
}

function launchRazorpay(d) {
  if (typeof Razorpay === 'undefined') {
    // Load Razorpay SDK dynamically
    const script=document.createElement('script');script.src='https://checkout.razorpay.com/v1/checkout.js';
    script.onload=()=>_doRazorpay(d);
    script.onerror=()=>toast('Payment gateway failed to load. Please try again.','error');
    document.head.appendChild(script);
  } else {
    _doRazorpay(d);
  }
}

function _doRazorpay(d) {
  const btn=document.getElementById('rzp-pay-btn');
  if(btn){btn.disabled=true;btn.textContent='Opening payment…';}
  const options = {
    key:         d.key_id,
    amount:      d.amount,
    currency:    d.currency||'INR',
    order_id:    d.order_id,
    name:        d.brand_name||C.brand,
    description: d.description||'Newspaper Ad Booking',
    image:       d.logo||'',
    prefill:     d.prefill||{},
    theme:       {color: getP()},
    handler: function(response) {
      // Verify payment via existing nas_verify_payment endpoint
      const fd=new FormData();
      fd.append('action','nas_verify_payment');
      fd.append('nonce', d.verify_nonce);
      fd.append('razorpay_payment_id', response.razorpay_payment_id);
      fd.append('razorpay_order_id', response.razorpay_order_id);
      fd.append('razorpay_signature', response.razorpay_signature);
      fd.append('booking_uid', d.booking_uid);
      const statusEl=document.getElementById('rzp-status');
      if(statusEl)statusEl.textContent='Verifying payment…';
      fetch(d.verify_ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(r=>{
        if(r.success){
          toast('Payment successful! ✅');
          const scr=document.getElementById('mob-screen');
          scr.innerHTML=`<div style="padding:48px 20px;text-align:center"><div style="font-size:64px;margin-bottom:16px">🎉</div><div style="font-size:22px;font-weight:900;color:var(--ink-primary);margin-bottom:8px">Payment Successful!</div><div style="font-size:14px;color:var(--ink-muted);margin-bottom:8px">Booking ID: <strong style="color:${getP()}">${h(d.booking_uid)}</strong></div><div style="font-size:13px;color:var(--ink-muted);margin-bottom:24px">Your invoice has been sent to your email.</div><button class="n-btn n-btn-p" style="margin:0 auto;display:flex;padding:12px 28px" onclick="NAS.goto('bookings')">View My Bookings</button></div>`;
        } else {
          toast('Payment verification failed. Contact support.','error');
          if(btn){btn.disabled=false;btn.textContent='Retry Payment';}
        }
      }).catch(()=>{toast('Verification error. Contact support.','error');if(btn){btn.disabled=false;btn.textContent='Retry Payment';}});
    },
    modal: { ondismiss: function() { if(btn){btn.disabled=false;btn.textContent=btn.textContent.replace('Opening payment…','Pay Now');} } }
  };
  const rzp = new Razorpay(options); rzp.open();
}

function renderPayUPayment(el, d) {
  // PayU uses a POST form — create and submit it
  el.innerHTML=`<div style="text-align:center;padding:20px"><div style="font-size:14px;color:var(--ink-muted);margin-bottom:16px">Redirecting to PayU payment gateway…</div><div class="n-spinner"></div></div>`;
  const form=document.createElement('form');
  form.method='POST';form.action=d.payu_url;form.style.display='none';
  const fields={key:d.merchant_key,txnid:d.txnid,amount:d.amount,productinfo:d.product_info,firstname:d.firstname,email:d.email,phone:d.phone,surl:d.surl,furl:d.furl,hash:d.hash,service_provider:'payu_paisa'};
  Object.entries(fields).forEach(([k,v])=>{const inp=document.createElement('input');inp.type='hidden';inp.name=k;inp.value=v||'';form.appendChild(inp);});
  document.body.appendChild(form);
  setTimeout(()=>form.submit(), 500);
}

/* === WHATSAPP CTA === */
function renderWhatsAppCTA(container, msg) {
  if (!C.phone) return;
  const P=getP(), waNum=C.phone.replace(/\D/g,'');
  const waMsg = encodeURIComponent(msg || 'Hi! I want to book a newspaper ad.');
  const link = `https://wa.me/${waNum}?text=${waMsg}`;
  const btn=document.createElement('a');
  btn.href=link;btn.target='_blank';btn.rel='noopener';
  btn.style.cssText=`display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#25d366;color:#fff;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;transition:all .15s`;
  btn.innerHTML='<span style="font-size:18px">💬</span> Chat on WhatsApp';
  btn.onmouseover=()=>btn.style.opacity='.9';btn.onmouseout=()=>btn.style.opacity='1';
  if(container)container.appendChild(btn);
}

/* === COUPON VALIDATION UI (upgrades wizard step 4) === */
function renderCouponField(container, amount, onApply) {
  if(!container)return;
  const P=getP();
  const wrap=document.createElement('div');
  wrap.style.cssText='margin-bottom:14px';
  wrap.innerHTML=`<div style="font-size:12px;font-weight:600;color:var(--ink-secondary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">Promo Code (optional)</div>
    <div style="display:flex;gap:8px">
      <input type="text" id="coupon-inp" class="n-input" placeholder="Enter code" style="flex:1;text-transform:uppercase;letter-spacing:.05em;font-size:14px">
      <button onclick="NAS.applyCoupon(${amount})" class="n-btn n-btn-g n-btn-sm" id="coupon-btn" style="white-space:nowrap">Apply</button>
    </div>
    <div id="coupon-msg" style="font-size:12px;margin-top:6px;display:none"></div>`;
  container.appendChild(wrap);
}

function applyCoupon(amount) {
  const code=document.getElementById('coupon-inp')?.value?.trim().toUpperCase();
  const msg=document.getElementById('coupon-msg'), btn=document.getElementById('coupon-btn');
  if(!code){if(msg){msg.textContent='Enter a coupon code';msg.style.color='#f87171';msg.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Checking…';}
  ajax('nas_pwa_validate_coupon',{code,amount}).then(d=>{
    WIZ.couponCode=code;WIZ.discountAmount=d.discount_amount||0;
    WIZ.total=d.final_amount||WIZ.total;
    if(msg){msg.textContent=d.message||'Coupon applied!';msg.style.color='#22c55e';msg.style.display='block';}
    // Update price boxes
    const priceBox=document.getElementById('wiz-price-box');
    if(priceBox){const rows=priceBox.querySelectorAll('span[style*="font-weight:900"]');if(rows.length)rows[rows.length-1].textContent=fmtP(WIZ.total);}
    toast(d.message||'Coupon applied! ✓');
  }).catch(e=>{if(msg){msg.textContent=e.message||'Invalid coupon';msg.style.color='#f87171';msg.style.display='block';}})
  .finally(()=>{if(btn){btn.disabled=false;btn.textContent='Apply';}});
}

/* === DESKTOP CONTENT PAGES (Blog, FAQ, Contact, Rate Card) === */
function renderDeskContent(main, type) {
  main.innerHTML='<div class="desk-content">';
  switch(type){
    case 'blog':     renderDeskBlog(main);     break;
    case 'faq':      renderDeskFAQ(main);      break;
    case 'contact':  renderDeskContact(main);  break;
    case 'rates':    renderDeskRates(main);    break;
    case 'register': renderDeskRegister(main); break;
    default: renderDeskHome(main);
  }
}

function renderDeskBlog(main) {
  main.innerHTML=`<div class="desk-content"><span class="sec-lbl">Latest Posts</span><div class="sec-ttl" style="margin-bottom:24px">Blog</div><div class="n-loading" id="desk-blog-spin"><span class="n-spinner"></span></div><div id="desk-blog-list" style="display:none"></div></div>`;
  ajax('nas_get_blog_posts',{per_page:12}).then(d=>{
    document.getElementById('desk-blog-spin')?.remove();
    const posts=d.posts||d||[], el=document.getElementById('desk-blog-list');
    if(!el)return;el.style.display='block';
    const P=getP();
    if(!posts.length){el.innerHTML='<div class="n-empty"><span class="n-empty-icon">📝</span><div class="n-empty-title">No posts yet</div></div>';return;}
    el.innerHTML=`<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">${posts.map(p=>`<div class="np-desk" onclick="NAS.deskOpenBlog(${p.id})" style="padding:0;overflow:hidden">
      ${p.image_url?`<div style="height:180px;background:url('${h(p.image_url)}') center/cover no-repeat"></div>`:''}
      <div style="padding:16px">
        ${p.category?`<div style="font-size:11px;font-weight:700;color:${P};text-transform:uppercase;margin-bottom:6px">${h(p.category)}</div>`:''}
        <div style="font-size:16px;font-weight:800;color:var(--ink-primary);margin-bottom:8px;line-height:1.3">${h(p.title)}</div>
        <div style="font-size:13px;color:var(--ink-muted);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${h((p.excerpt||p.content||'').replace(/<[^>]+>/g,'').slice(0,120))}</div>
        <div style="font-size:11px;color:var(--ink-muted);margin-top:10px">${fmtD(p.published_at)}</div>
      </div>
    </div>`).join('')}</div>`;
  }).catch(e=>{document.getElementById('desk-blog-spin')?.remove();document.getElementById('desk-blog-list').style.display='block';document.getElementById('desk-blog-list').innerHTML=errState(e.message,()=>renderDeskBlog(main));});
}

function deskOpenBlog(id) {
  const main=document.getElementById('desk-main');
  main.innerHTML=`<div class="desk-content" style="max-width:760px"><button onclick="NAS.deskGoto('blog')" style="background:var(--surface-800);border:1px solid var(--border);border-radius:10px;padding:7px 14px;font-size:13px;color:var(--ink-secondary);cursor:pointer;font-family:inherit;margin-bottom:24px">← Blog</button><div class="n-loading"><span class="n-spinner"></span></div><div id="desk-post"></div></div>`;
  ajax('nas_get_blog_post',{id}).then(d=>{
    document.querySelector('.n-loading')?.remove();
    const p=d.post||d, el=document.getElementById('desk-post');if(!el)return;
    const P=getP();
    el.innerHTML=`${p.category?`<div style="font-size:11px;font-weight:700;color:${P};text-transform:uppercase;margin-bottom:10px">${h(p.category)}</div>`:''}
    <h1 style="font-size:30px;font-weight:900;color:var(--ink-primary);margin-bottom:10px;line-height:1.2">${h(p.title)}</h1>
    <div style="font-size:13px;color:var(--ink-muted);margin-bottom:24px">${fmtD(p.published_at)} ${p.read_time?`· ${p.read_time} min read`:''}</div>
    ${p.image_url?`<div style="height:320px;background:url('${h(p.image_url)}') center/cover no-repeat;border-radius:16px;margin-bottom:24px"></div>`:''}
    <div style="font-size:16px;color:var(--ink-secondary);line-height:1.9;white-space:pre-wrap">${h((p.content||'').replace(/<[^>]+>/g,''))}</div>`;
  }).catch(e=>{document.querySelector('.n-loading')?.remove();document.getElementById('desk-post').innerHTML=errState(e.message,()=>deskOpenBlog(id));});
}

function renderDeskFAQ(main) {
  main.innerHTML=`<div class="desk-content"><span class="sec-lbl">Help Center</span><div class="sec-ttl" style="margin-bottom:24px">Frequently Asked Questions</div><div class="n-loading"><span class="n-spinner"></span></div><div id="desk-faq-list" style="display:none"></div></div>`;
  ajax('nas_get_faqs').then(d=>{
    document.querySelector('.n-loading')?.remove();
    const faqs=d.faqs||d||[], el=document.getElementById('desk-faq-list');if(!el)return;
    el.style.display='block';
    const grouped={};faqs.forEach(f=>{const cat=f.category||'General';if(!grouped[cat])grouped[cat]=[];grouped[cat].push(f);});
    const P=getP();
    el.innerHTML=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">${Object.entries(grouped).map(([cat,items])=>`<div><div style="font-size:13px;font-weight:700;color:${P};text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">${h(cat)}</div>${items.map(f=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:13px;margin-bottom:8px;overflow:hidden"><button onclick="NAS.toggleFAQ(this)" style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:none;border:none;cursor:pointer;font-family:inherit;text-align:left"><span style="font-size:14px;font-weight:700;color:var(--ink-primary);flex:1;padding-right:12px">${h(f.question)}</span><span style="font-size:16px;color:var(--ink-muted);flex-shrink:0;transition:transform .2s">▾</span></button><div style="display:none;padding:0 16px 14px;font-size:14px;color:var(--ink-secondary);line-height:1.7">${h(f.answer||'')}</div></div>`).join('')}</div>`).join('')}</div>`;
  }).catch(e=>{document.querySelector('.n-loading')?.remove();document.getElementById('desk-faq-list').style.display='block';document.getElementById('desk-faq-list').innerHTML=errState(e.message,()=>renderDeskFAQ(main));});
}

function renderDeskContact(main) {
  const P=getP();
  main.innerHTML=`<div class="desk-content" style="max-width:720px;display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:start">
    <div>
      <span class="sec-lbl">Get In Touch</span>
      <div class="sec-ttl" style="margin-bottom:14px">Contact Us</div>
      <p style="font-size:15px;color:var(--ink-secondary);line-height:1.7;margin-bottom:24px">Have a question about booking, pricing, or your ad? Our team responds within 24 hours.</p>
      ${C.phone?`<div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;margin-bottom:12px"><span style="font-size:24px">📞</span><div><div style="font-size:12px;color:var(--ink-muted)">Phone / WhatsApp</div><a href="tel:${h(C.phone)}" style="font-size:16px;font-weight:800;color:${P};text-decoration:none">${h(C.phone)}</a></div></div>`:''}
      <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;margin-bottom:12px"><span style="font-size:24px">⏰</span><div><div style="font-size:12px;color:var(--ink-muted)">Response Time</div><div style="font-size:15px;font-weight:700;color:var(--ink-primary)">Within 24 hours</div></div></div>
      ${C.phone?`<div style="margin-top:4px" id="desk-wa-cta"></div>`:''}
    </div>
    <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:18px;padding:24px">
      <div class="n-field"><label class="n-lbl" for="dct-name">Name</label><input class="n-input" id="dct-name" placeholder="Your name" value="${h(C.userName)}"></div>
      <div class="n-field"><label class="n-lbl" for="dct-email">Email</label><input class="n-input" id="dct-email" type="email" placeholder="your@email.com"></div>
      <div class="n-field"><label class="n-lbl" for="dct-subject">Subject</label><select class="n-input" id="dct-subject" style="cursor:pointer"><option>General Enquiry</option><option>Booking Support</option><option>Payment Issue</option><option>Rate Card Query</option><option>Feedback</option></select></div>
      <div class="n-field"><label class="n-lbl" for="dct-msg">Message</label><textarea class="n-input" id="dct-msg" rows="4" placeholder="Your message…" style="resize:vertical"></textarea></div>
      <span class="n-err" id="dct-err" style="display:block;margin-bottom:10px"></span>
      <button class="n-btn n-btn-p" id="dct-btn" style="width:100%;padding:12px" onclick="NAS.deskSubmitContact()">Send Message →</button>
    </div>
  </div>`;
  setTimeout(()=>{const waCta=document.getElementById('desk-wa-cta');if(waCta)renderWhatsAppCTA(waCta,'Hi, I need help with a newspaper ad booking.');},100);
}

function deskSubmitContact() {
  const name=document.getElementById('dct-name')?.value?.trim();
  const email=document.getElementById('dct-email')?.value?.trim();
  const msg=document.getElementById('dct-msg')?.value?.trim();
  const subject=document.getElementById('dct-subject')?.value;
  const err=document.getElementById('dct-err'), btn=document.getElementById('dct-btn');
  if(!name||!email||!msg){if(err){err.textContent='Name, email, and message are required';err.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Sending…';}
  ajax('nas_submit_contact',{name,email,subject,message:msg}).then(()=>{
    if(btn)btn.closest('div').innerHTML='<div style="text-align:center;padding:20px"><div style="font-size:36px;margin-bottom:10px">✅</div><div style="font-size:16px;font-weight:700;color:var(--ink-primary)">Message Sent!</div><div style="font-size:13px;color:var(--ink-muted);margin-top:6px">We\'ll respond within 24 hours.</div></div>';
    toast('Message sent! We\'ll respond soon.');
  }).catch(e=>{if(err){err.textContent=e.message;err.style.display='block';}if(btn){btn.disabled=false;btn.textContent='Send Message →';}});
}

function renderDeskRates(main) {
  main.innerHTML=`<div class="desk-content"><span class="sec-lbl">Transparent Pricing</span><div class="sec-ttl" style="margin-bottom:8px">Rate Card</div><p style="font-size:14px;color:var(--ink-secondary);margin-bottom:20px">Official DAVP/RNI empanelled rates. No markup.</p><div style="display:flex;gap:10px;margin-bottom:16px"><div style="flex:1;display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:12px;padding:10px 14px"><span style="color:var(--ink-muted)">🔍</span><input type="text" id="desk-rc-search" placeholder="Search newspaper…" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:14px;font-family:inherit" oninput="NAS.deskRcSearch(this.value)"></div></div><div id="desk-rc-cities" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap"></div><div class="n-loading" id="desk-rc-spin"><span class="n-spinner"></span></div><div id="desk-rc-list"></div></div>`;
  deskLoadRates();
}

let deskRcCity=0, deskRcTimer;
function deskLoadRates(search='') {
  const spin=document.getElementById('desk-rc-spin'),list=document.getElementById('desk-rc-list');
  if(spin)spin.style.display='flex';
  ajax('nas_pwa_rate_card',{city_id:deskRcCity,search}).then(d=>{
    if(spin)spin.style.display='none';
    const P=getP(), nps=d.newspapers||[], cities=d.cities||[];
    const cc=document.getElementById('desk-rc-cities');
    if(cc)cc.innerHTML=`<button class="city-chip${!deskRcCity?' active':''}" onclick="NAS.deskRcCity(0)">All Cities</button>`+cities.map(c=>`<button class="city-chip${deskRcCity===c.id?' active':''}" onclick="NAS.deskRcCity(${c.id})">${h(c.name)}</button>`).join('');
    if(!list)return;
    if(!nps.length){list.innerHTML='<div class="n-empty"><span class="n-empty-icon">📰</span><div class="n-empty-title">No newspapers found</div></div>';return;}
    list.innerHTML=nps.map(np=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;margin-bottom:14px;overflow:hidden">
      <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid var(--border)">
        ${np.logo_url?`<img src="${h(np.logo_url)}" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:var(--surface-700);border:1px solid var(--border);flex-shrink:0" alt="${h(np.name)}" loading="lazy">`:'<div style="width:44px;height:44px;border-radius:10px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">📰</div>'}
        <div style="flex:1"><div style="font-size:16px;font-weight:800;color:var(--ink-primary)">${h(np.name)}</div><div style="font-size:13px;color:var(--ink-muted)">${np.city_name?h(np.city_name):''}${np.language?' · '+h(np.language):''} ${np.circulation?'· Circ: '+parseInt(np.circulation).toLocaleString('en-IN')+'+':''}</div></div>
        <button class="n-btn n-btn-p n-btn-sm" onclick="NAS.bookNp(${np.id})">Book Ad</button>
      </div>
      ${np.rates?.length?`<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="background:var(--surface-900)"><th style="text-align:left;padding:10px 18px;color:var(--ink-muted);font-weight:600">Ad Type</th><th style="text-align:left;padding:10px 10px;color:var(--ink-muted);font-weight:600">Category</th><th style="text-align:right;padding:10px 18px;color:var(--ink-muted);font-weight:600">Rate</th><th style="text-align:right;padding:10px 12px;color:var(--ink-muted);font-weight:600">Per</th></tr></thead><tbody>${np.rates.map(r=>`<tr style="border-top:1px solid var(--border)"><td style="padding:10px 18px;color:var(--ink-secondary)">${h(r.ad_type?.replace(/_/g,' ')||'Standard')}</td><td style="padding:10px;color:var(--ink-muted);font-size:12px">${h(r.cat_name||'-')}</td><td style="text-align:right;padding:10px 18px;font-weight:800;color:${P}">${fmtP(r.rate_per_unit||0)}</td><td style="text-align:right;padding:10px 12px;color:var(--ink-muted);font-size:12px">${h(r.unit||'/ad')}</td></tr>`).join('')}</tbody></table>`:np.rates?.length===0?`<div style="padding:12px 18px;font-size:13px;color:var(--ink-muted)">Contact us for detailed rate card</div>`:''}
    </div>`).join('');
  }).catch(e=>{if(spin)spin.style.display='none';if(list)list.innerHTML=errState(e.message,()=>deskLoadRates(search));});
}
function deskRcSearch(q){clearTimeout(deskRcTimer);deskRcTimer=setTimeout(()=>deskLoadRates(q),400);}
function deskRcCity(id){deskRcCity=id;deskLoadRates(document.getElementById('desk-rc-search')?.value||'');}

function renderDeskRegister(main) {
  const P=getP();
  main.innerHTML=`<div class="desk-content" style="max-width:500px;margin:40px auto">
    <div style="text-align:center;margin-bottom:28px"><div style="font-size:48px;margin-bottom:12px">👤</div><div class="sec-ttl">Create Account</div><p style="font-size:14px;color:var(--ink-secondary);margin-top:8px">Manage bookings, invoices and ad history</p></div>
    <div style="background:var(--surface-800);border:1px solid var(--border);border-radius:18px;padding:24px">
      <div class="n-field"><label class="n-lbl" for="dreg-name">Full Name</label><input class="n-input" id="dreg-name" placeholder="Your full name" autocomplete="name"></div>
      <div class="n-field"><label class="n-lbl" for="dreg-email">Email</label><input class="n-input" id="dreg-email" type="email" placeholder="your@email.com" autocomplete="email"></div>
      <div class="n-field"><label class="n-lbl" for="dreg-phone">Phone</label><input class="n-input" id="dreg-phone" type="tel" placeholder="10-digit mobile" autocomplete="tel"></div>
      <div class="n-field"><label class="n-lbl" for="dreg-pass">Password</label><input class="n-input" id="dreg-pass" type="password" placeholder="Min. 8 characters" autocomplete="new-password"></div>
      <span class="n-err" id="dreg-err" style="display:block;margin-bottom:10px"></span>
      <button class="n-btn n-btn-p" id="dreg-btn" style="width:100%;padding:13px" onclick="NAS.deskDoRegister()">Create Account →</button>
      <div style="margin-top:14px;text-align:center;font-size:13px;color:var(--ink-muted)">Already have an account? <button onclick="NAS.deskGoto('portal')" style="background:none;border:none;color:${P};font-weight:700;cursor:pointer;font-family:inherit">Sign In →</button></div>
    </div>
  </div>`;
}

function deskDoRegister(){
  const name=document.getElementById('dreg-name')?.value?.trim();const email=document.getElementById('dreg-email')?.value?.trim();
  const phone=document.getElementById('dreg-phone')?.value?.trim();const pass=document.getElementById('dreg-pass')?.value;
  const err=document.getElementById('dreg-err'),btn=document.getElementById('dreg-btn');
  if(!name||!email||!pass||pass.length<8){if(err){err.textContent='All fields required. Password min 8 chars.';err.style.display='block';}return;}
  if(btn){btn.disabled=true;btn.textContent='Creating…';}
  ajax('nas_pwa_register',{name,email,phone,password:pass}).then(d=>{C.isLoggedIn=true;C.userId=d.user_id;C.userName=d.name;toast('Account created! Welcome, '+d.name+' ✓');renderDeskPortal(document.getElementById('desk-main'));}).catch(e=>{if(err){err.textContent=e.message;err.style.display='block';}if(btn){btn.disabled=false;btn.textContent='Create Account →';}});
}



/* ══════════════════════════════════════════════════════════════════
   COMPLETION PHASE: Notifications, Wallet, Bookings Filter,
   Web Share API, Saved Newspapers, Mega Menu Fix
══════════════════════════════════════════════════════════════════ */

/* === NOTIFICATIONS SCREEN === */
function renderNotifications(scr) {
  if (!C.isLoggedIn) { scr.innerHTML = loginPrompt('notifications'); return; }
  const P=getP();
  scr.innerHTML=`<div>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px">
      <div style="font-size:20px;font-weight:900;color:var(--ink-primary)">Notifications</div>
      <button onclick="NAS.markAllRead()" style="background:none;border:none;font-size:13px;font-weight:700;color:${P};cursor:pointer;font-family:inherit">Mark all read</button>
    </div>
    <div class="n-loading" id="notif-spin"><span class="n-spinner"></span></div>
    <div id="notif-list" style="padding:0 16px 16px"></div>
  </div>`;
  ajax('nas_get_notifications', {per_page:30}).then(d => {
    document.getElementById('notif-spin')?.remove();
    const notifs = d.notifications || d || [];
    const el = document.getElementById('notif-list');
    if (!el) return;
    if (!notifs.length) {
      el.innerHTML = `<div class="n-empty"><span class="n-empty-icon">🔔</span><div class="n-empty-title">No notifications</div><div class="n-empty-sub">Booking updates and messages will appear here.</div></div>`;
      return;
    }
    el.innerHTML = notifs.map(n => {
      const unread = !n.is_read;
      const icons = { booking_received:'📋', payment_received:'✅', status_changed:'📊', message:'💬', material_approved:'✅', material_rejected:'❌', quotation_sent:'💰', published:'🎉' };
      const icon = icons[n.type] || '🔔';
      return `<div style="display:flex;gap:12px;padding:12px;background:${unread?'color-mix(in srgb,'+P+' 8%,var(--surface-800))':'var(--surface-800)'};border:1px solid ${unread?'color-mix(in srgb,'+P+' 25%,var(--border))':'var(--border)'};border-radius:13px;margin-bottom:8px;cursor:pointer;transition:background .15s" onclick="NAS.handleNotif(${n.id},'${h(n.reference_id||'')}','${h(n.type||'')}')">
        <div style="width:40px;height:40px;border-radius:12px;background:color-mix(in srgb,${P} 15%,transparent);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">${icon}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:${unread?'700':'600'};color:var(--ink-primary);margin-bottom:3px;line-height:1.4">${h(n.title||n.message||'Notification')}</div>
          <div style="font-size:12px;color:var(--ink-muted);line-height:1.4">${h(n.body||n.excerpt||'')}</div>
          <div style="font-size:11px;color:var(--ink-muted);margin-top:4px">${fmtD(n.created_at)}</div>
        </div>
        ${unread?`<div style="width:8px;height:8px;border-radius:50%;background:${P};flex-shrink:0;margin-top:4px"></div>`:''}
      </div>`;
    }).join('');
  }).catch(e => {
    document.getElementById('notif-spin')?.remove();
    document.getElementById('notif-list').innerHTML = errState(e.message, () => renderNotifications(scr));
  });
}

function handleNotif(id, refId, type) {
  // Mark read
  ajax('nas_mark_notification_read', {id}).catch(() => {});
  // Navigate to relevant screen
  if (type === 'message' || type === 'booking_received' || type === 'status_changed') {
    if (refId) { openBookingDetail(parseInt(refId)||0, refId); }
    else { goto('bookings'); }
  } else {
    goto('bookings');
  }
}

function markAllRead() {
  ajax('nas_mark_notification_read', {mark_all: 1}).then(() => {
    toast('All notifications marked as read ✓');
    // Refresh badge
    const badges = ['mob-badge','desk-badge'];
    badges.forEach(id => { const b = document.getElementById(id); if(b) { b.style.display='none'; } });
  }).catch(() => {});
}

/* === WALLET SCREEN === */
function renderWallet(scr) {
  if (!C.isLoggedIn) { scr.innerHTML = loginPrompt('your wallet'); return; }
  const P=getP();
  scr.innerHTML=`<div>
    <div style="padding:16px 16px 12px;font-size:20px;font-weight:900;color:var(--ink-primary)">My Wallet</div>
    <div class="n-loading" id="wallet-spin"><span class="n-spinner"></span></div>
    <div id="wallet-content"></div>
  </div>`;
  ajax('nas_get_wallet').then(d => {
    document.getElementById('wallet-spin')?.remove();
    const el = document.getElementById('wallet-content'); if(!el) return;
    const bal = parseFloat(d.balance || d.wallet_balance || 0);
    const txns = d.transactions || [];
    el.innerHTML = `
      <!-- Balance Card -->
      <div style="margin:0 16px 20px;background:linear-gradient(135deg,color-mix(in srgb,${P} 25%,#000),color-mix(in srgb,${P} 45%,#000));border-radius:20px;padding:24px;position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06)"></div>
        <div style="position:absolute;bottom:-30px;left:-10px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.04)"></div>
        <div style="position:relative">
          <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Wallet Balance</div>
          <div style="font-size:36px;font-weight:900;color:#fff;margin-bottom:4px">${fmtP(bal)}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.6)">${txns.length} transaction${txns.length===1?'':'s'}</div>
        </div>
      </div>
      <!-- Info -->
      <div style="margin:0 16px 16px;background:var(--surface-800);border:1px solid var(--border);border-radius:12px;padding:12px 14px;font-size:12px;color:var(--ink-muted);line-height:1.6">
        💡 Wallet balance is credited by admin for refunds, promotions, or loyalty rewards. It's applied automatically during checkout.
      </div>
      <!-- Transaction History -->
      <div style="padding:0 16px">
        <div style="font-size:14px;font-weight:800;color:var(--ink-primary);margin-bottom:12px">Transaction History</div>
        ${txns.length ? txns.map(t => {
          const credit = t.txn_type === 'credit';
          return `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:36px;height:36px;border-radius:10px;background:${credit?'color-mix(in srgb,'+P+' 15%,transparent)':'rgba(239,68,68,.1)'};display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">${credit?'⬆️':'⬇️'}</div>
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--ink-primary)">${h(t.note||t.description||(credit?'Credit':'Debit'))}</div>
                <div style="font-size:11px;color:var(--ink-muted)">${fmtD(t.created_at)}</div>
              </div>
            </div>
            <div style="font-size:15px;font-weight:800;color:${credit?P:'#f87171'}">${credit?'+':'-'}${fmtP(t.amount)}</div>
          </div>`;
        }).join('') : '<div class="n-empty" style="padding:24px 0"><span class="n-empty-icon">💳</span><div class="n-empty-title">No transactions yet</div></div>'}
      </div>`;
  }).catch(e => {
    document.getElementById('wallet-spin')?.remove();
    document.getElementById('wallet-content').innerHTML = errState(e.message, () => renderWallet(scr));
  });
}

/* === BOOKINGS SEARCH + FILTER (replaces basic bookings screen) === */
function renderMobBookingsEnhanced(scr) {
  if (!C.isLoggedIn) { scr.innerHTML = loginPrompt('your bookings'); return; }
  const P=getP();
  scr.innerHTML=`<div>
    <div style="padding:12px 16px 8px;display:flex;align-items:center;gap:8px">
      <div style="flex:1;display:flex;align-items:center;gap:8px;background:var(--surface-800);border:1.5px solid var(--border);border-radius:12px;padding:9px 12px">
        <span style="color:var(--ink-muted);font-size:14px">🔍</span>
        <input type="text" id="bk-search" placeholder="Search booking ID, newspaper…" style="flex:1;background:none;border:none;outline:none;color:var(--ink-primary);font-size:13px;font-family:inherit" oninput="NAS.bkSearch(this.value)">
      </div>
    </div>
    <div style="display:flex;gap:6px;padding:0 16px 8px;overflow-x:auto;scrollbar-width:none" id="bk-status-chips">
      ${['All','Received','Paid','Processing','Published','Completed','Cancelled'].map((s,i)=>`<button class="city-chip${i===0?' active':''}" onclick="NAS.bkFilterStatus('${['','booking_received','payment_received','ad_processing','published','completed','cancelled'][i]}',this)" style="font-size:12px;white-space:nowrap">${s}</button>`).join('')}
    </div>
    <div class="n-loading" id="bk-spin"><span class="n-spinner"></span></div>
    <div id="bk-list"></div>
  </div>`;
  S._bkStatus=''; S._bkSearch=''; S._bkPage=1;
  loadBkEnhanced(1);
}

let bkSearchTimer;
function bkSearch(q) { S._bkSearch=q; clearTimeout(bkSearchTimer); bkSearchTimer=setTimeout(()=>loadBkEnhanced(1),400); }
function bkFilterStatus(status, btn) {
  S._bkStatus=status; S._bkPage=1;
  document.querySelectorAll('#bk-status-chips .city-chip').forEach(c=>c.classList.remove('active'));
  if(btn) btn.classList.add('active');
  loadBkEnhanced(1);
}

function loadBkEnhanced(page) {
  S._bkPage=page;
  const spin=document.getElementById('bk-spin'), list=document.getElementById('bk-list');
  if(spin)spin.style.display='flex'; if(list)list.style.display='none';
  ajax('nas_pwa_bookings',{page,status:S._bkStatus||'',search:S._bkSearch||''}).then(d=>{
    if(spin)spin.style.display='none'; if(list)list.style.display='block';
    const P=getP(), items=d.bookings||[], pages=d.pages||1;
    if(!items.length){
      list.innerHTML=`<div class="n-empty"><span class="n-empty-icon">📭</span><div class="n-empty-title">No bookings found</div><div class="n-empty-sub">${S._bkSearch||S._bkStatus?'Try clearing your filters.':'Place your first newspaper ad.'}</div>${!S._bkSearch&&!S._bkStatus?`<button class="n-btn n-btn-p n-btn-sm" style="margin:0 auto;display:flex" onclick="NAS.goto('book')">Book an Ad</button>`:''}</div>`;
      return;
    }
    let html=items.map(b=>`<div style="background:var(--surface-800);border:1px solid var(--border);border-radius:16px;padding:14px;margin:0 16px 10px;cursor:pointer;transition:background .15s" onclick="NAS.openBookingDetail(${parseInt(b.id)||0},null)" onmouseover="this.style.background='var(--surface-700)'" onmouseout="this.style.background='var(--surface-800)'">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <span style="font-size:14px;font-weight:800;color:${P}">${h(b.uid||b.id)}</span>
        <div style="display:flex;align-items:center;gap:6px">
          <span class="nbadge ${h(b.status)}">${stLbl(b.status)}</span>
          <button onclick="event.stopPropagation();NAS.shareBooking('${h(b.uid||b.id)}')" style="background:none;border:none;color:var(--ink-muted);font-size:16px;cursor:pointer;padding:0;line-height:1" title="Share" aria-label="Share booking">⬆️</button>
        </div>
      </div>
      <div style="font-size:14px;font-weight:700;color:var(--ink-primary);margin-bottom:4px">${h(b.newspaper_name||'Newspaper')} — ${h(b.category_name||'Ad')}</div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        ${b.publish_date?`<span style="font-size:12px;color:var(--ink-muted)">📅 ${fmtD(b.publish_date)}</span>`:''}
        <span style="font-size:12px;color:var(--ink-muted)">${fmtD(b.submitted_at)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:12px;color:var(--ink-muted)">${b.payment_status==='paid'?'✅ Paid':'💳 '+h(b.payment_status||'pending')}</span>
        <span style="font-size:16px;font-weight:900;color:var(--ink-primary)">${fmtP(b.total_amount)}</span>
      </div>
    </div>`).join('');
    if(pages>1){html+='<div class="n-pages">';for(let i=1;i<=pages;i++)html+=`<button class="n-page-btn${i===page?' active':''}" onclick="NAS.bkPage(${i})">${i}</button>`;html+='</div>';}
    html+=`<div style="padding:4px 16px 16px;text-align:center"><a href="${h(C.dashUrl)}" style="font-size:13px;font-weight:700;color:${P}">Full dashboard →</a></div>`;
    list.innerHTML=html;
  }).catch(e=>{if(spin)spin.style.display='none';if(list){list.style.display='block';list.innerHTML=errState(e.message,()=>loadBkEnhanced(page));}});
}
function bkPage(p){S._bkPage=p;loadBkEnhanced(p);document.getElementById('mob-screen').scrollTop=0;}

/* === WEB SHARE API === */
function shareBooking(uid) {
  const url = `${C.homeUrl}nas-app/?screen=track`;
  const text = `My newspaper ad booking: ${uid}`;
  if (navigator.share) {
    navigator.share({ title: 'NAS Booking', text, url }).catch(() => {});
  } else {
    navigator.clipboard?.writeText(uid).then(() => toast('Booking ID copied: '+uid)).catch(() => toast('Booking ID: '+uid,'info'));
  }
}

function shareNewspaper(name, url) {
  if (navigator.share) {
    navigator.share({ title: name + ' - Book Newspaper Ad', url: url || C.homeUrl+'nas-app/' }).catch(() => {});
  } else {
    navigator.clipboard?.writeText(url||C.homeUrl+'nas-app/').then(() => toast('Link copied!')).catch(() => {});
  }
}

/* === SAVED NEWSPAPERS (LocalStorage-based wishlist) === */
const SAVED_KEY = 'nas_saved_papers';
function getSaved() {
  try { return JSON.parse(localStorage.getItem(SAVED_KEY)||'[]'); } catch(e) { return []; }
}
function toggleSaved(id, name) {
  const saved = getSaved();
  const idx = saved.findIndex(s => s.id === id);
  if (idx>=0) { saved.splice(idx,1); toast('Removed from saved'); }
  else { saved.push({id,name,saved_at:Date.now()}); toast('Saved ✓'); }
  localStorage.setItem(SAVED_KEY, JSON.stringify(saved));
  return idx<0; // returns true if now saved
}
function isSaved(id) { return getSaved().some(s=>s.id===id); }

function renderSaved(scr) {
  const saved=getSaved(), P=getP();
  scr.innerHTML=`<div>
    <div style="padding:16px 16px 12px">
      <div style="font-size:20px;font-weight:900;color:var(--ink-primary);margin-bottom:2px">Saved Newspapers</div>
      <div style="font-size:13px;color:var(--ink-muted)">${saved.length} saved</div>
    </div>
    ${!saved.length?`<div class="n-empty"><span class="n-empty-icon">❤️</span><div class="n-empty-title">No saved newspapers</div><div class="n-empty-sub">Tap the bookmark on any newspaper to save it for quick access.</div><button class="n-btn n-btn-p n-btn-sm" style="margin:0 auto;display:flex" onclick="NAS.goto('browse')">Browse Newspapers</button></div>`:`<div style="padding:0 16px;display:flex;flex-direction:column;gap:10px">${saved.map(s=>`<div style="display:flex;align-items:center;gap:12px;background:var(--surface-800);border:1px solid var(--border);border-radius:14px;padding:12px;cursor:pointer" onclick="NAS.openSheet(${s.id})">
      <div style="width:44px;height:44px;border-radius:10px;background:var(--surface-700);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">📰</div>
      <div style="flex:1"><div style="font-size:14px;font-weight:700;color:var(--ink-primary)">${h(s.name)}</div><div style="font-size:12px;color:var(--ink-muted)">Saved ${fmtD(new Date(s.saved_at).toISOString())}</div></div>
      <div style="display:flex;gap:8px">
        <button onclick="event.stopPropagation();NAS.bookNp(${s.id})" class="n-btn n-btn-p n-btn-sm" style="font-size:12px">Book</button>
        <button onclick="event.stopPropagation();NAS.toggleSaved(${s.id},'${h(s.name)}');NAS.renderSaved(document.getElementById('mob-screen'))" style="background:var(--surface-700);border:none;border-radius:8px;padding:6px 10px;font-size:12px;color:var(--ink-muted);cursor:pointer">✕</button>
      </div>
    </div>`).join('')}</div>`}
  </div>`;
}

/* === DESKTOP MEGA MENU: load categories independently === */
function initDeskMegaMenu() {
  // Load categories for mega menu without requiring home to load first
  ajax('nas_pwa_browse', {page:1, per_page:0}).then(d => {
    const cats=d.categories||[], mc=document.getElementById('mega-cats');
    if(mc&&cats.length) mc.innerHTML=cats.map(c=>`<button class="mega-card" onclick="NAS.deskBrowseCat(${c.id},'${h(c.name)}')"><span>${catEmoji(c.name)}</span><span>${h(c.name)}</span></button>`).join('');
  }).catch(()=>{});
}

/* === SEO META TAGS (dynamic, per screen) === */
function updateMeta(title, desc, image) {
  document.title = (title||C.brand) + ' — Book Newspaper Ads';
  const setMeta = (n, v) => { let el=document.querySelector(`meta[property="${n}"],meta[name="${n}"]`); if(!el){el=document.createElement('meta');if(n.startsWith('og:')||n.startsWith('twitter:'))el.setAttribute('property',n);else el.setAttribute('name',n);document.head.appendChild(el);}el.setAttribute('content',v||''); };
  setMeta('og:title', title||C.brand);
  setMeta('og:description', desc||C.tagline);
  setMeta('og:url', location.href);
  if(image) setMeta('og:image', image);
  setMeta('twitter:card','summary_large_image');
  setMeta('twitter:title', title||C.brand);
  setMeta('twitter:description', desc||C.tagline);
}

/* === IMPROVED EMPTY STATES WITH ILLUSTRATIONS === */
function emptyStateWithIllustration(icon, title, sub, actionLabel, actionFn) {
  const id = 'es-action-'+Date.now();
  setTimeout(()=>{ const b=document.getElementById(id); if(b&&actionFn)b.addEventListener('click',actionFn); },50);
  return `<div style="text-align:center;padding:56px 24px">
    <div style="width:80px;height:80px;border-radius:24px;background:color-mix(in srgb,${getP()} 10%,transparent);border:2px solid color-mix(in srgb,${getP()} 20%,transparent);display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px">${icon}</div>
    <div style="font-size:18px;font-weight:800;color:var(--ink-primary);margin-bottom:8px">${title}</div>
    <div style="font-size:14px;color:var(--ink-muted);margin-bottom:${actionLabel?'20px':'0'};line-height:1.6;max-width:260px;margin-left:auto;margin-right:auto">${sub}</div>
    ${actionLabel?`<button id="${id}" class="n-btn n-btn-p n-btn-sm" style="margin:0 auto">${actionLabel}</button>`:''}
  </div>`;
}

/* === VAPID KEY GENERATOR (admin-side, shown in pwa-settings) === */
// This runs in the browser — generates a VAPID key pair using WebCrypto
async function generateVAPIDKeys() {
  try {
    const kp = await crypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'},true,['sign','verify']);
    const pub = await crypto.subtle.exportKey('raw', kp.publicKey);
    const priv = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
    const b64u = buf => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
    return { public: b64u(pub), private: b64u(priv) };
  } catch(e) {
    return null;
  }
}


/* === PUBLIC API === */
// Expose analytics + advanced UX
window.ANA = ANA;
window.NAS={
  goto:gotoWithAnalytics,deskGoto:deskGotoWithAnalytics,doSearch,deskSearch,deskTag,
  browseByCat,browseByCity,deskBrowseCat,deskBrowseCity,
  filterCat,filterCity,deskFCat,clearBrowse,
  browsePg,deskBrPg,loadMobBkPg,
  bookNp,openSheet,closeSheet,
  openMega,closeMega,toggleUserMenu,
  doLogin,deskLogin,promptInstall,
  // Phase 2
  openBookingDetail,cancelBooking,uploadMaterial,sendMsg,doTrack,
  // Phase 3: Wizard
  wizSelCat,wizSelCity,wizSelNp,wizSelAdType,wizUpdateContent,wizSearchNp,
  wizStep2,wizStep3,wizStep4,wizStep5,wizBack,wizSubmit,
  // Phase 4: Vendor
  vendorTab,vendorMarkPublished,vendorUploadProof,loadVendorBkPg,openVendorBkDetail,
  // Final: Blog, FAQ, Contact, Rates, Register, Payment, Coupon
  openBlogPost,deskOpenBlog,toggleFAQ,submitContact,deskSubmitContact,
  renderPayment,launchRazorpay,applyCoupon,
  rcSearch,rcCity,deskRcSearch,deskRcCity,
  doRegister,deskDoRegister,
  // Completion: Notifications, Wallet, Bookings Filter, Share, Saved, Meta
  handleNotif,markAllRead,
  renderWallet,renderSaved,
  bkSearch,bkFilterStatus,bkPage,bkEnhanced:loadBkEnhanced,
  shareBooking,shareNewspaper,
  toggleSaved,isSaved,renderSaved,
  updateMeta,
};

/* === BOOT === */
document.addEventListener('DOMContentLoaded',()=>{
  const initScreen=new URLSearchParams(location.search).get('screen')||'home';
  initHeader(C.brand,C.logo);
  if(isMob()){
    S.screen=initScreen;
    document.querySelectorAll('.mob-tab').forEach(t=>t.classList.remove('active'));
    const tab=document.getElementById('tab-'+initScreen);if(tab)tab.classList.add('active');
    renderMob(initScreen);
  } else {
    S.deskPage=initScreen;
    renderDesk(initScreen);
  }
  loadNotifCount();
  if(C.isLoggedIn)setInterval(loadNotifCount,30000);

  // Pre-load mega menu categories independently
  if(!isMob()) setTimeout(initDeskMegaMenu, 300);

  // Initial meta tags
  updateMeta(C.brand, C.tagline, C.logo);

  // Phase 8: Advanced UX
  initOfflineBanner();
  if(isMob()) initPullToRefresh();
  else setTimeout(initDeskSearchAutocomplete, 500);

  // Track initial screen view
  ANA.screenView(initScreen);

  // Push permission prompt (show after 10s if logged in and not yet prompted)
  if(C.isLoggedIn && 'Notification' in window && Notification.permission === 'default') {
    setTimeout(showPushPrompt, 10000);
  }

  // Track app install
  window.addEventListener('appinstalled', () => ANA.event('install'));
  if('serviceWorker' in navigator)navigator.serviceWorker.register('/nas-sw.js',{scope:'/'}).catch(()=>{});
});
window.addEventListener('popstate',e=>{
  const scr=e.state?.screen||new URLSearchParams(location.search).get('screen')||'home';
  if(isMob()){S.screen=scr;document.querySelectorAll('.mob-tab').forEach(t=>t.classList.remove('active'));const tab=document.getElementById('tab-'+scr);if(tab)tab.classList.add('active');renderMob(scr);}
  else{S.deskPage=scr;renderDesk(scr);}
});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSheet();});
window.addEventListener('unhandledrejection',e=>{const m=e.reason?.message||String(e.reason||'');if(!m.includes('AbortError')&&!m.includes('timed out'))console.error('[NAS]',m);});
window.addEventListener('error',e=>console.error('[NAS]',e.message));

})();
</script>
<?php wp_footer(); ?>
</body>
</html>
