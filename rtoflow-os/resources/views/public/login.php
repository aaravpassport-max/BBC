<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<div style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;background:#F8FAFC">
  <div style="width:100%;max-width:420px">
    <div style="text-align:center;margin-bottom:24px">
      <div style="font-size:40px" aria-hidden="true">🔑</div>
      <h1 style="font-size:24px;font-weight:800;color:#1B2A6B;margin:8px 0 4px"><?= esc_html__('Welcome Back', 'rtoflow-os') ?></h1>
      <p style="color:#6b7280;font-size:14px"><?= esc_html__('Login to track and manage your RTO service requests', 'rtoflow-os') ?></p>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.06)">
      <div id="rto-login-err" style="display:none;background:#fee2e2;color:#dc2626;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px" role="alert"></div>
      <div style="margin-bottom:14px">
        <label for="rto-login-user" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Email or Username', 'rtoflow-os') ?></label>
        <input type="text" id="rto-login-user" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:14px;color:#374151" placeholder="your@email.com" autofocus>
      </div>
      <div style="margin-bottom:20px">
        <label for="rto-login-pass" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Password', 'rtoflow-os') ?></label>
        <input type="password" id="rto-login-pass" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:14px;color:#374151" placeholder="••••••••">
      </div>
      <button id="rto-login-btn" style="width:100%;background:#1B2A6B;color:#fff;border:none;padding:12px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer"><?= esc_html__('Login to Account →', 'rtoflow-os') ?></button>
      <div style="text-align:center;margin-top:14px;font-size:13px;color:#6b7280">
        <a href="<?= esc_url(wp_lostpassword_url()) ?>" style="color:#2563EB"><?= esc_html__('Forgot your password?', 'rtoflow-os') ?></a>
      </div>
    </div>
    <div style="margin-top:16px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 16px">
      <p style="font-size:12px;color:#1d4ed8;margin:0"><strong><?= esc_html__('💡 Note:', 'rtoflow-os') ?></strong> <?php echo wp_kses(sprintf(__('If you submitted a service request, your account was auto-created. Use your email + the password sent to you, or <a href="%s" style="color:#1d4ed8">reset it here</a>.', 'rtoflow-os'), esc_url(wp_lostpassword_url())), ['a' => ['href' => [], 'style' => []]]); ?></p>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function rtoLogin(){
  var u=document.getElementById('rto-login-user').value.trim();
  var p=document.getElementById('rto-login-pass').value;
  var err=document.getElementById('rto-login-err');
  var btn=document.getElementById('rto-login-btn');
  if(!u||!p){err.style.display='block';err.textContent='Please enter your email/username and password';return;}
  btn.disabled=true;btn.textContent='Logging in…';err.style.display='none';
  var fd=new FormData();
  fd.append('action','rtoflow_ajax_login');
  fd.append('nonce','<?= wp_create_nonce('rtoflow_login') ?>');
  fd.append('username',u);fd.append('password',p);
  fetch('<?= esc_js(admin_url('admin-ajax.php')) ?>',{method:'POST',body:fd})
  .then(r=>r.json()).then(d=>{
    btn.disabled=false;btn.textContent='Login to Account →';
    if(d.success){window.location.href=d.data.redirect||'<?= esc_js(home_url('/rto-dashboard/')) ?>';}
    else{err.style.display='block';err.textContent=d.data||'Invalid credentials. Please try again.';}
  }).catch(()=>{btn.disabled=false;btn.textContent='Login to Account →';err.style.display='block';err.textContent='Connection error. Please try again.';});
}
document.getElementById('rto-login-btn').addEventListener('click',rtoLogin);
document.addEventListener('keypress',function(e){if(e.key==='Enter')rtoLogin();});
</script>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
