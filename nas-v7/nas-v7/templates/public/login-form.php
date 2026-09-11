<?php
/**
 * NAS Login Form — split layout with trust panel.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( is_user_logged_in() ) {
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;
    if ( user_can( $user, 'manage_options' ) )   { wp_safe_redirect( home_url( '/admin-dashboard/' ) ); exit; }
    if ( in_array( 'nas_manager', $roles, true ) ) { wp_safe_redirect( home_url( '/moderation-dashboard/' ) ); exit; }
    if ( in_array( 'nas_staff', $roles, true ) )   { wp_safe_redirect( home_url( '/staff-dashboard/' ) ); exit; }
    if ( in_array( 'nas_vendor', $roles, true ) )  { wp_safe_redirect( home_url( '/vendor-dashboard/' ) ); exit; }
    wp_safe_redirect( nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' ) ); exit;
}

$cfg      = \NAS\Core\Config::instance();
$brand    = $cfg->get( 'brand_name', get_bloginfo( 'name' ) );
$logo     = $cfg->get( 'logo_url', '' );
$color    = $cfg->get( 'brand_primary_color', '#1A3A5C' );
$page_url = nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' );
$book_url = nas_portal_booking_url();
$step     = sanitize_key( $_GET['step'] ?? 'login' );
$nas_err  = sanitize_key( $_GET['nas_err'] ?? '' );
$nas_msg  = sanitize_key( $_GET['nas_msg'] ?? '' );
$reset_key   = sanitize_text_field( $_GET['key'] ?? '' );
$reset_login = sanitize_user( $_GET['login'] ?? '' );
if ( $reset_key && $reset_login ) {
    $step = 'reset';
}

$errors = [
    'nonce'       => 'Security check failed. Please refresh the page and try again.',
    'credentials' => 'Incorrect email or password. Please try again.',
    'expired'     => 'This reset link has expired. Please request a new one.',
    'short'       => 'Password must be at least 8 characters long.',
    'mismatch'    => 'Passwords do not match. Please try again.',
];
$messages = [
    'reset_sent'  => [ 'type' => 'success', 'text' => 'If an account with that email exists, a reset link has been sent. Check your inbox.' ],
    'pwd_changed' => [ 'type' => 'success', 'text' => 'Password changed successfully! You can now sign in with your new password.' ],
];

$msg_html = '';
if ( $nas_err && isset( $errors[ $nas_err ] ) ) {
    $msg_html = '<div class="nlp-msg error"><i class="fa-solid fa-circle-xmark"></i> ' . esc_html( $errors[ $nas_err ] ) . '</div>';
}
if ( $nas_msg && isset( $messages[ $nas_msg ] ) ) {
    $m = $messages[ $nas_msg ];
    $msg_html = '<div class="nlp-msg ' . esc_attr( $m['type'] ) . '"><i class="fa-solid fa-circle-check"></i> ' . esc_html( $m['text'] ) . '</div>';
}

$s = nas_portal_live_stats();
?>
<style>
.nlp-box{background:#fff;border:1.5px solid var(--nas-border);border-radius:20px;padding:36px 32px;box-shadow:0 8px 40px rgba(12,18,34,.08);width:100%;max-width:420px}
.nlp-title{font-size:1.25rem;font-weight:800;font-family:var(--nas-font-display);color:var(--nas-text);margin:0 0 4px}
.nlp-sub{font-size:0.875rem;color:var(--nas-text-muted);margin:0 0 18px}
.nlp-form{display:flex;flex-direction:column;gap:14px}
.nlp-label{display:block;font-size:0.75rem;font-weight:700;color:var(--nas-text);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
.nlp-input{width:100%;padding:12px 14px;border:1.5px solid var(--nas-border);border-radius:12px;font-size:0.9375rem;font-family:inherit;color:var(--nas-text);outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}
.nlp-input:focus{border-color:#0D9488;box-shadow:0 0 0 4px rgba(13,148,136,.12)}
.nlp-btn{padding:13px;background:linear-gradient(135deg,#F59E0B,#F97316);color:#fff;border:none;border-radius:999px;font-size:0.9375rem;font-weight:700;cursor:pointer;font-family:inherit;width:100%;transition:filter .15s;box-shadow:0 4px 14px rgba(245,158,11,.35)}
.nlp-btn:hover{filter:brightness(1.05)}
.nlp-remember{display:flex;align-items:center;gap:8px;font-size:0.8125rem;color:var(--nas-text-muted);cursor:pointer}
.nlp-msg{padding:11px 14px;border-radius:12px;font-size:0.8125rem;margin-bottom:12px;display:flex;align-items:flex-start;gap:8px}
.nlp-msg.error{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}
.nlp-msg.success{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
.nlp-hint{background:rgba(13,148,136,.08);border:1px solid rgba(13,148,136,.2);border-radius:12px;padding:10px 14px;font-size:0.8125rem;color:#0f766e;margin-bottom:12px}
.nlp-link-row{text-align:center;margin-top:16px;font-size:0.8125rem;color:var(--nas-text-muted)}
.nlp-link-row a{color:var(--nas-primary);font-weight:600;text-decoration:none}
.nlp-pw-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
.nlp-pw-row .nlp-label{margin:0}
@media(max-width:900px){.nlp-box{padding:28px 20px}}
</style>

<div class="nas-login-split">
  <div class="nas-login-trust-panel">
    <h2>Your Newspaper Ad Dashboard</h2>
    <p>Sign in to track bookings, download invoices, approve proofs, and chat with our support team.</p>
    <ul class="nas-login-trust-list">
      <li><i class="fa-solid fa-shield-check"></i> <span><strong><?php echo $s['bookings'] > 0 ? number_format( $s['bookings'] ) . '+' : '10,000+'; ?></strong> ads placed through our platform</span></li>
      <li><i class="fa-solid fa-newspaper"></i> <span><strong><?php echo $s['papers'] > 0 ? $s['papers'] . '+' : '50+'; ?></strong> verified newspaper publications</span></li>
      <li><i class="fa-solid fa-city"></i> <span><strong><?php echo $s['cities'] > 0 ? $s['cities'] . '+' : '300+'; ?></strong> cities across India</span></li>
      <li><i class="fa-solid fa-lock"></i> <span>Secure login with encrypted credentials</span></li>
      <li><i class="fa-solid fa-location-crosshairs"></i> <span>Real-time order tracking from submission to publication</span></li>
    </ul>
    <a href="<?php echo esc_url( $book_url ); ?>" class="nhp-btn nhp-btn--white" style="margin-top:32px;align-self:flex-start">Book Without Login <i class="fa-solid fa-arrow-right"></i></a>
  </div>

  <div class="nas-login-form-panel">
    <div class="nlp-box">
      <?php if ( $logo ) : ?>
      <div style="text-align:center;margin-bottom:20px"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" style="height:44px;object-fit:contain"></div>
      <?php endif; ?>

      <?php echo $msg_html; ?>

      <?php if ( $step === 'login' ) : ?>
        <h2 class="nlp-title">Welcome back</h2>
        <p class="nlp-sub">Sign in to manage your bookings</p>
        <div class="nlp-hint"><i class="fa-solid fa-circle-info"></i> Just placed a booking? Your login details were sent to your email.</div>
        <form class="nlp-form" method="post" action="<?php echo esc_url( $page_url ); ?>">
          <?php wp_nonce_field( 'nas_login_action', 'nas_login_nonce' ); ?>
          <?php if ( ! empty( $_GET['redirect_to'] ) ) : ?>
          <input type="hidden" name="redirect_to" value="<?php echo esc_attr( sanitize_url( $_GET['redirect_to'] ) ); ?>">
          <?php endif; ?>
          <div>
            <label class="nlp-label" for="nlp-log">Email or Username</label>
            <input class="nlp-input" id="nlp-log" name="log" type="text" required placeholder="you@example.com" autocomplete="username">
          </div>
          <div>
            <div class="nlp-pw-row">
              <label class="nlp-label" for="nlp-pwd">Password</label>
              <a href="<?php echo esc_url( add_query_arg( 'step', 'forgot', $page_url ) ); ?>" style="font-size:0.75rem;color:var(--nas-primary);text-decoration:none;font-weight:600">Forgot?</a>
            </div>
            <input class="nlp-input" id="nlp-pwd" name="pwd" type="password" required placeholder="••••••••" autocomplete="current-password">
          </div>
          <label class="nlp-remember"><input type="checkbox" name="rememberme"> Keep me signed in for 2 weeks</label>
          <button class="nlp-btn" type="submit" name="nas_login_submit">Sign In <i class="fa-solid fa-arrow-right"></i></button>
        </form>
        <div class="nlp-link-row">New to <?php echo esc_html( $brand ); ?>? <a href="<?php echo esc_url( $book_url ); ?>">Book an ad</a></div>

      <?php elseif ( $step === 'forgot' ) : ?>
        <h2 class="nlp-title">Reset Password</h2>
        <p class="nlp-sub">Enter your email and we'll send a reset link</p>
        <form class="nlp-form" method="post" action="<?php echo esc_url( add_query_arg( 'step', 'forgot', $page_url ) ); ?>">
          <?php wp_nonce_field( 'nas_forgot_action', 'nas_forgot_nonce' ); ?>
          <div>
            <label class="nlp-label" for="nlp-forgot">Email Address</label>
            <input class="nlp-input" id="nlp-forgot" name="forgot_email" type="email" required placeholder="you@example.com" autocomplete="email">
          </div>
          <button class="nlp-btn" type="submit" name="nas_forgot_submit">Send Reset Link</button>
        </form>
        <div class="nlp-link-row"><a href="<?php echo esc_url( $page_url ); ?>">← Back to Sign In</a></div>

      <?php elseif ( $step === 'reset' ) : ?>
        <h2 class="nlp-title">Set New Password</h2>
        <p class="nlp-sub">Must be at least 8 characters</p>
        <form class="nlp-form" method="post" action="<?php echo esc_url( add_query_arg( [ 'step' => 'reset', 'key' => $reset_key, 'login' => $reset_login ], $page_url ) ); ?>">
          <?php wp_nonce_field( 'nas_reset_action', 'nas_reset_nonce' ); ?>
          <input type="hidden" name="rp_key" value="<?php echo esc_attr( $reset_key ); ?>">
          <input type="hidden" name="rp_login" value="<?php echo esc_attr( $reset_login ); ?>">
          <div>
            <label class="nlp-label" for="nlp-p1">New Password</label>
            <input class="nlp-input" id="nlp-p1" name="pass1" type="password" required placeholder="Min 8 characters" autocomplete="new-password">
          </div>
          <div>
            <label class="nlp-label" for="nlp-p2">Confirm Password</label>
            <input class="nlp-input" id="nlp-p2" name="pass2" type="password" required placeholder="Repeat password" autocomplete="new-password">
          </div>
          <button class="nlp-btn" type="submit" name="nas_reset_submit">Set New Password</button>
        </form>
        <div class="nlp-link-row"><a href="<?php echo esc_url( $page_url ); ?>">← Back to Sign In</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>
