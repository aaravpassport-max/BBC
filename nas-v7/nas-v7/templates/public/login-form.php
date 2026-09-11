<?php
/**
 * NAS Login Form — display only. All POST handling in newspaper-ads-saas.php @init.
 * Included by [nas_login] shortcode and login.php standalone.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Redirect if already logged in
if ( is_user_logged_in() ) {
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;
    if ( user_can($user,'manage_options') )   { wp_safe_redirect(home_url('/admin-dashboard/')); exit; }
    if ( in_array('nas_manager',$roles) )     { wp_safe_redirect(home_url('/moderation-dashboard/')); exit; }
    if ( in_array('nas_staff',$roles) )       { wp_safe_redirect(home_url('/staff-dashboard/')); exit; }
    if ( in_array('nas_vendor',$roles) )      { wp_safe_redirect(home_url('/vendor-dashboard/')); exit; }
    wp_safe_redirect(nas_get_page_url('nas_page_client_dashboard','/client-dashboard/')); exit;
}

$cfg      = \NAS\Core\Config::instance();
$brand    = $cfg->get('brand_name', get_bloginfo('name'));
$logo     = $cfg->get('logo_url', '');
$color    = $cfg->get('brand_primary_color', '#6c47ff');
$page_url = nas_get_page_url('nas_page_login', '/newspaper-ad-login/');
$step     = sanitize_key($_GET['step'] ?? 'login');
$nas_err  = sanitize_key($_GET['nas_err'] ?? '');
$nas_msg  = sanitize_key($_GET['nas_msg'] ?? '');
$reset_key   = sanitize_text_field($_GET['key']   ?? '');
$reset_login = sanitize_user($_GET['login'] ?? '');
if ($reset_key && $reset_login) $step = 'reset';

// Map error/message codes → human text
$errors = [
    'nonce'       => 'Security check failed. Please refresh the page and try again.',
    'credentials' => 'Incorrect email or password. Please try again.',
    'expired'     => 'This reset link has expired. Please request a new one.',
    'short'       => 'Password must be at least 8 characters long.',
    'mismatch'    => 'Passwords do not match. Please try again.',
];
$messages = [
    'reset_sent'  => ['type' => 'success', 'text' => 'If an account with that email exists, a reset link has been sent. Check your inbox.'],
    'pwd_changed' => ['type' => 'success', 'text' => 'Password changed successfully! You can now sign in with your new password.'],
];

$msg_html = '';
if ($nas_err && isset($errors[$nas_err])) {
    $msg_html = '<div class="nlp-msg error"><i class="fa-solid fa-circle-xmark"></i> ' . esc_html($errors[$nas_err]) . '</div>';
}
if ($nas_msg && isset($messages[$nas_msg])) {
    $m = $messages[$nas_msg];
    $msg_html = '<div class="nlp-msg ' . $m['type'] . '"><i class="fa-solid fa-circle-check"></i> ' . esc_html($m['text']) . '</div>';
}
?>
<style>
.nlp-wrap{min-height:80vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;background:#f1f5f9}
.nlp-card{width:100%;max-width:420px}
.nlp-brand{text-align:center;margin-bottom:28px}
.nlp-brand img{height:52px;object-fit:contain;display:block;margin:0 auto}
.nlp-brand-name{font-size:24px;font-weight:800;color:#0f172a;margin-top:8px}
.nlp-brand-sub{font-size:13px;color:#64748b;margin-top:2px}
.nlp-box{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:36px 32px;box-shadow:0 8px 40px rgba(0,0,0,.07)}
.nlp-title{font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px;text-align:center}
.nlp-sub{font-size:13px;color:#64748b;text-align:center;margin:0 0 18px}
.nlp-form{display:flex;flex-direction:column;gap:14px}
.nlp-label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.nlp-input{width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.nlp-input:focus{border-color:<?php echo esc_attr($color);?>;box-shadow:0 0 0 3px <?php echo esc_attr($color);?>22}
.nlp-btn{padding:13px;background:<?php echo esc_attr($color);?>;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;width:100%;transition:opacity .15s}
.nlp-btn:hover{opacity:.88}
.nlp-remember{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer}
.nlp-remember input{width:16px;height:16px;accent-color:<?php echo esc_attr($color);?>}
.nlp-msg{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:8px;display:flex;align-items:flex-start;gap:8px}
.nlp-msg.error{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}
.nlp-msg.success{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
.nlp-hint{background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 14px;font-size:12px;color:#1d4ed8;margin-bottom:12px;text-align:center}
.nlp-link-row{text-align:center;margin-top:16px;font-size:13px;color:#94a3b8}
.nlp-link-row a{color:<?php echo esc_attr($color);?>;font-weight:600;text-decoration:none}
.nlp-link-row a:hover{text-decoration:underline}
.nlp-pw-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
.nlp-pw-row .nlp-label{margin:0}
@media(max-width:480px){.nlp-box{padding:24px 18px}.nlp-wrap{align-items:flex-start;padding-top:20px}}
</style>

<div class="nlp-wrap">
  <div class="nlp-card">

    <div class="nlp-brand">
      <?php if($logo): ?>
        <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($brand); ?>">
      <?php else: ?>
        <div style="font-size:42px;margin-bottom:4px">📰</div>
        <div class="nlp-brand-name"><?php echo esc_html($brand); ?></div>
      <?php endif; ?>
      <div class="nlp-brand-sub">Advertising Portal</div>
    </div>

    <div class="nlp-box">
      <?php echo $msg_html; ?>

      <?php if($step === 'login'): ?>
        <h2 class="nlp-title">Welcome back</h2>
        <p class="nlp-sub">Sign in to manage your bookings</p>
        <div class="nlp-hint">
          <i class="fa-solid fa-circle-info"></i>
          Just placed a booking? Your login details were sent to your email.
        </div>
        <form class="nlp-form" method="post" action="<?php echo esc_url($page_url); ?>">
          <?php wp_nonce_field('nas_login_action','nas_login_nonce'); ?>
          <?php if(!empty($_GET['redirect_to'])): ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr(sanitize_url($_GET['redirect_to'])); ?>">
          <?php endif; ?>
          <div>
            <label class="nlp-label" for="nlp-log">Email or Username</label>
            <input class="nlp-input" id="nlp-log" name="log" type="text" required
              placeholder="you@example.com" autocomplete="username">
          </div>
          <div>
            <div class="nlp-pw-row">
              <label class="nlp-label" for="nlp-pwd">Password</label>
              <a href="<?php echo esc_url(add_query_arg('step','forgot',$page_url)); ?>"
                style="font-size:12px;color:<?php echo esc_attr($color);?>;text-decoration:none;font-weight:600">
                Forgot password?
              </a>
            </div>
            <input class="nlp-input" id="nlp-pwd" name="pwd" type="password" required
              placeholder="••••••••" autocomplete="current-password">
          </div>
          <label class="nlp-remember">
            <input type="checkbox" name="rememberme"> Keep me signed in for 2 weeks
          </label>
          <button class="nlp-btn" type="submit" name="nas_login_submit">Sign In →</button>
        </form>
        <div class="nlp-link-row">
          New to <?php echo esc_html($brand); ?>?
          <a href="<?php echo esc_url(nas_get_page_url('nas_page_booking','/book-newspaper-ad/')); ?>">Book an ad →</a>
        </div>

      <?php elseif($step === 'forgot'): ?>
        <h2 class="nlp-title">Reset Password</h2>
        <p class="nlp-sub">Enter your email and we'll send a reset link</p>
        <form class="nlp-form" method="post" action="<?php echo esc_url(add_query_arg('step','forgot',$page_url)); ?>">
          <?php wp_nonce_field('nas_forgot_action','nas_forgot_nonce'); ?>
          <div>
            <label class="nlp-label" for="nlp-forgot">Email Address</label>
            <input class="nlp-input" id="nlp-forgot" name="forgot_email" type="email" required
              placeholder="you@example.com" autocomplete="email">
          </div>
          <button class="nlp-btn" type="submit" name="nas_forgot_submit">Send Reset Link</button>
        </form>
        <div class="nlp-link-row"><a href="<?php echo esc_url($page_url); ?>">← Back to Sign In</a></div>

      <?php elseif($step === 'reset'): ?>
        <h2 class="nlp-title">Set New Password</h2>
        <p class="nlp-sub">Must be at least 8 characters</p>
        <form class="nlp-form" method="post" action="<?php echo esc_url(add_query_arg(['step'=>'reset','key'=>$reset_key,'login'=>$reset_login],$page_url)); ?>">
          <?php wp_nonce_field('nas_reset_action','nas_reset_nonce'); ?>
          <input type="hidden" name="rp_key"   value="<?php echo esc_attr($reset_key); ?>">
          <input type="hidden" name="rp_login" value="<?php echo esc_attr($reset_login); ?>">
          <div>
            <label class="nlp-label" for="nlp-p1">New Password</label>
            <input class="nlp-input" id="nlp-p1" name="pass1" type="password" required
              placeholder="Min 8 characters" autocomplete="new-password">
          </div>
          <div>
            <label class="nlp-label" for="nlp-p2">Confirm Password</label>
            <input class="nlp-input" id="nlp-p2" name="pass2" type="password" required
              placeholder="Repeat password" autocomplete="new-password">
          </div>
          <button class="nlp-btn" type="submit" name="nas_reset_submit">Set New Password</button>
        </form>
        <div class="nlp-link-row"><a href="<?php echo esc_url($page_url); ?>">← Back to Sign In</a></div>

      <?php endif; ?>
    </div>
  </div>
</div>
