<?php
$site_name = get_bloginfo('name') ?: 'InterviewAce';
$logo_url  = esc_url(IA_URL . 'assets/logo.svg');
$app_url   = home_url('/');
/*
 * ROOT-CAUSE FIX: every CTA on the public marketing site pointed at
 * $app_url . 'login' (i.e. home_url('/login')) — but the React SPA is
 * mounted with basename="/app" (frontend/src/App.tsx), so any path that
 * doesn't start with /app matches none of its routes and the page loads
 * blank. The correct entry point is /app/login. Same bug existed for
 * every "Start free" / "Get started" / "Sign up" link across home.php,
 * pricing.php, and about.php — all fixed together this pass.
 */
$app_login_url = home_url('/app/login');
$nav_links = [
    'Features' => '#features',
    'How it works' => '#how-it-works',
    'Pricing' => home_url('/ia-pricing'),
    'About' => home_url('/ia-about'),
];
?>
<!DOCTYPE html>
<html lang="en-IN">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="theme-color" content="#0B0B16"/>
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<style>
:root{--p:#7C3AED;--pa:#A78BFA;--g:#34D399;--bg:#0B0B16;--s1:#12121F;--s2:#1A1A2E;--t:#F0EBFF;--m:rgba(255,255,255,.5);--b:rgba(255,255,255,.09)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--t);-webkit-font-smoothing:antialiased;line-height:1.6}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
.pub-header{position:fixed;top:0;left:0;right:0;z-index:100;background:rgba(11,11,22,.88);backdrop-filter:blur(20px);border-bottom:1px solid var(--b)}
.pub-nav{max-width:1180px;margin:0 auto;padding:0 24px;display:flex;align-items:center;height:64px;gap:32px}
.pub-logo{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:800;letter-spacing:-.3px}
.pub-logo__icon{width:36px;height:36px;background:linear-gradient(135deg,#7C3AED,#A78BFA);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px}
.pub-nav__links{display:flex;align-items:center;gap:6px;margin-left:auto}
.pub-nav__link{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:500;color:var(--m);transition:all .14s}
.pub-nav__link:hover{color:var(--t);background:rgba(255,255,255,.05)}
.pub-nav__cta{padding:9px 20px;background:var(--p);color:#fff;border-radius:9px;font-size:14px;font-weight:700;transition:all .15s;margin-left:8px}
.pub-nav__cta:hover{background:#6D28D9;transform:translateY(-1px)}
.pub-burger{display:none;background:none;border:none;cursor:pointer;flex-direction:column;gap:5px;padding:4px}
.pub-burger span{width:22px;height:2px;background:var(--t);border-radius:1px;display:block;transition:all .2s}
.pub-mobile-menu{display:none;position:fixed;inset:0;background:rgba(11,11,22,.97);z-index:99;padding:80px 24px 24px;flex-direction:column;gap:8px}
.pub-mobile-menu.open{display:flex}
.pub-mobile-link{padding:16px;border-radius:10px;font-size:16px;font-weight:600;color:var(--m);border-bottom:1px solid var(--b)}
@media(max-width:768px){.pub-nav__links{display:none}.pub-burger{display:flex}}
</style>
</head>
<body>
<header class="pub-header">
  <nav class="pub-nav">
    <a href="<?php echo home_url('/'); ?>" class="pub-logo">
      <div class="pub-logo__icon">🎤</div>
      InterviewAce
    </a>
    <div class="pub-nav__links">
      <?php foreach ($nav_links as $label => $href): ?>
      <a class="pub-nav__link" href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
      <a class="pub-nav__link pub-nav__cta" href="<?php echo esc_url($app_login_url); ?>">Start free</a>
    </div>
    <button class="pub-burger" onclick="document.querySelector('.pub-mobile-menu').classList.toggle('open')" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </nav>
</header>
<div class="pub-mobile-menu">
  <?php foreach ($nav_links as $label => $href): ?>
  <a class="pub-mobile-link" href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a>
  <?php endforeach; ?>
  <a class="pub-mobile-link" style="color:var(--pa);font-weight:800" href="<?php echo esc_url($app_login_url); ?>">Start free →</a>
</div>
