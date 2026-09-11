<?php
/**
 * NAS Vendor Registration Page
 * Standalone — no WordPress theme dependency
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( is_user_logged_in() ) {
    $user = wp_get_current_user();
    if ( in_array( 'nas_vendor', (array) $user->roles ) ) {
        wp_safe_redirect( home_url('/vendor-dashboard/') ); exit;
    }
}

$cfg       = \NAS\Core\Config::instance();
$brand     = $cfg->get('brand_name', get_bloginfo('name'));
$logo_url  = $cfg->get('logo_url','');
$nonce     = wp_create_nonce('nas_action');
$login_url = home_url('/newspaper-ad-login/');

$nav_links = [
    ['label'=>'Home',      'url'=>home_url('/')],
    ['label'=>'Book an Ad','url'=>home_url('/book-newspaper-ad/')],
    ['label'=>'Login',     'url'=>$login_url],
];
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<style>
.nas-vr-wrap{max-width:760px;margin:48px auto;padding:0 20px 60px}
.nas-vr-header{text-align:center;margin-bottom:36px}
.nas-vr-header h1{font-size:32px;font-weight:800;color:#0f172a;margin:0 0 8px}
.nas-vr-header p{font-size:15px;color:#64748b;margin:0}
.nas-vr-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:36px;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.nas-vr-steps{display:flex;gap:0;margin-bottom:32px;border-bottom:2px solid #f1f5f9}
.nas-vr-step-btn{flex:1;padding:12px 8px;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;font-size:13px;font-weight:600;color:#94a3b8;cursor:pointer;transition:all .15s;display:flex;flex-direction:column;align-items:center;gap:4px}
.nas-vr-step-btn.active{color:#6c47ff;border-bottom-color:#6c47ff}
.nas-vr-step-btn.done{color:#059669}
.nas-vr-step-num{width:24px;height:24px;border-radius:50%;border:2px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.nas-vr-panel{display:none}.nas-vr-panel.active{display:block}
.nas-vr-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.nas-vr-grid{grid-template-columns:1fr}}
.nas-vr-field{display:flex;flex-direction:column;gap:6px}
.nas-vr-field.full{grid-column:1/-1}
.nas-vr-label{font-size:13px;font-weight:600;color:#374151}
.nas-vr-label span{color:#ef4444}
.nas-vr-input,.nas-vr-select,.nas-vr-textarea{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;color:#0f172a;background:#fff;transition:border-color .15s;outline:none}
.nas-vr-input:focus,.nas-vr-select:focus,.nas-vr-textarea:focus{border-color:#6c47ff}
.nas-vr-textarea{resize:vertical;min-height:80px}
.nas-vr-actions{display:flex;gap:12px;margin-top:24px;justify-content:space-between}
.nas-vr-btn{padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;border:2px solid transparent;display:inline-flex;align-items:center;gap:8px}
.nas-vr-btn-primary{background:#6c47ff;color:#fff;border-color:#6c47ff}
.nas-vr-btn-primary:hover{background:#5b3dd4}
.nas-vr-btn-outline{background:transparent;color:#6c47ff;border-color:#6c47ff}
.nas-vr-btn-outline:hover{background:#f5f3ff}
.nas-vr-btn:disabled{opacity:.5;cursor:not-allowed}
.nas-vr-progress{height:4px;background:#f1f5f9;border-radius:2px;margin-bottom:28px;overflow:hidden}
.nas-vr-progress-bar{height:100%;background:#6c47ff;border-radius:2px;transition:width .3s ease}
.nas-vr-success{text-align:center;padding:40px 20px}
.nas-vr-success-icon{font-size:64px;margin-bottom:16px}
.nas-vr-success h2{font-size:24px;font-weight:800;color:#0f172a;margin:0 0 8px}
.nas-vr-success p{font-size:15px;color:#64748b;margin:0 0 24px}
.nas-vr-error{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;display:none}
.nas-vr-agreement{font-size:12px;color:#64748b;margin-top:16px;text-align:center}
.nas-vr-agreement a{color:#6c47ff}
.nas-checkbox-row{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#374151}
.nas-checkbox-row input{margin-top:2px;accent-color:#6c47ff}
.nas-pw-toggle{position:relative}
.nas-pw-toggle input{padding-right:40px}
.nas-pw-eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:0}
</style>

<div class="nas-vr-wrap">
  <div class="nas-vr-header">
    <?php if($logo_url): ?><img src="<?= esc_url($logo_url) ?>" height="40" style="margin-bottom:16px" alt="<?= esc_attr($brand) ?>"><br><?php endif; ?>
    <h1>Become a Vendor Partner</h1>
    <p>Join <?= esc_html($brand) ?> and start receiving newspaper ad bookings in your area</p>
  </div>

  <div class="nas-vr-card">
    <!-- Progress bar -->
    <div class="nas-vr-progress"><div class="nas-vr-progress-bar" id="vr-progress" style="width:33%"></div></div>

    <!-- Step indicators -->
    <div class="nas-vr-steps">
      <button class="nas-vr-step-btn active" id="vr-step-btn-1" onclick="vrGoStep(1)">
        <span class="nas-vr-step-num">1</span>Business Info
      </button>
      <button class="nas-vr-step-btn" id="vr-step-btn-2" onclick="vrGoStep(2)" disabled>
        <span class="nas-vr-step-num">2</span>Coverage Area
      </button>
      <button class="nas-vr-step-btn" id="vr-step-btn-3" onclick="vrGoStep(3)" disabled>
        <span class="nas-vr-step-num">3</span>Account Setup
      </button>
    </div>

    <div id="vr-error" class="nas-vr-error"></div>

    <!-- Step 1: Business Info -->
    <div class="nas-vr-panel active" id="vr-panel-1">
      <div class="nas-vr-grid">
        <div class="nas-vr-field">
          <label class="nas-vr-label">Business / Vendor Name <span>*</span></label>
          <input type="text" id="vr-name" class="nas-vr-input" placeholder="e.g. ABC Ad Agency">
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">Contact Person Name <span>*</span></label>
          <input type="text" id="vr-contact" class="nas-vr-input" placeholder="Your full name">
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">Phone Number <span>*</span></label>
          <input type="tel" id="vr-phone" class="nas-vr-input" placeholder="10-digit mobile">
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">WhatsApp Number</label>
          <input type="tel" id="vr-wa" class="nas-vr-input" placeholder="If different from phone">
        </div>
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Email Address <span>*</span></label>
          <input type="email" id="vr-email" class="nas-vr-input" placeholder="vendor@email.com">
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">GST Number</label>
          <input type="text" id="vr-gst" class="nas-vr-input" placeholder="22AAAAA0000A1Z5" maxlength="15">
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">PAN Number</label>
          <input type="text" id="vr-pan" class="nas-vr-input" placeholder="ABCDE1234F" maxlength="10">
        </div>
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Bank Account Details</label>
          <textarea id="vr-bank" class="nas-vr-textarea" placeholder="Bank name · Account number · IFSC code"></textarea>
        </div>
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Brief Introduction</label>
          <textarea id="vr-notes" class="nas-vr-textarea" placeholder="Tell us about your experience with newspaper ads, years in business, etc."></textarea>
        </div>
      </div>
      <div class="nas-vr-actions">
        <span></span>
        <button class="nas-vr-btn nas-vr-btn-primary" onclick="vrStep1Next()">Next: Coverage Area <i class="fa-solid fa-arrow-right"></i></button>
      </div>
    </div>

    <!-- Step 2: Coverage Area -->
    <div class="nas-vr-panel" id="vr-panel-2">
      <div class="nas-vr-grid">
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Cities You Cover <span>*</span></label>
          <select id="vr-cities" class="nas-vr-select" multiple size="8" style="height:180px">
            <option value="loading" disabled>Loading cities…</option>
          </select>
          <small style="color:#64748b;font-size:12px">Hold Ctrl / Cmd to select multiple cities</small>
        </div>
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Newspapers You Work With</label>
          <select id="vr-newspapers" class="nas-vr-select" multiple size="8" style="height:180px">
            <option value="loading" disabled>Loading newspapers…</option>
          </select>
          <small style="color:#64748b;font-size:12px">Hold Ctrl / Cmd to select multiple newspapers</small>
        </div>
      </div>
      <div class="nas-vr-actions">
        <button class="nas-vr-btn nas-vr-btn-outline" onclick="vrGoStep(1)"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button class="nas-vr-btn nas-vr-btn-primary" onclick="vrStep2Next()">Next: Account Setup <i class="fa-solid fa-arrow-right"></i></button>
      </div>
    </div>

    <!-- Step 3: Account Credentials -->
    <div class="nas-vr-panel" id="vr-panel-3">
      <div class="nas-vr-grid">
        <div class="nas-vr-field full">
          <label class="nas-vr-label">Choose a Username <span>*</span></label>
          <input type="text" id="vr-username" class="nas-vr-input" placeholder="lowercase, no spaces" autocomplete="new-password">
          <small style="color:#64748b;font-size:12px">Used to log in. Cannot be changed later.</small>
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">Password <span>*</span></label>
          <div class="nas-pw-toggle">
            <input type="password" id="vr-pass" class="nas-vr-input" placeholder="Min 8 characters" autocomplete="new-password">
            <button type="button" class="nas-pw-eye" onclick="vrTogglePw('vr-pass',this)"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
        <div class="nas-vr-field">
          <label class="nas-vr-label">Confirm Password <span>*</span></label>
          <div class="nas-pw-toggle">
            <input type="password" id="vr-pass2" class="nas-vr-input" placeholder="Repeat password" autocomplete="new-password">
            <button type="button" class="nas-pw-eye" onclick="vrTogglePw('vr-pass2',this)"><i class="fa-regular fa-eye"></i></button>
          </div>
        </div>
      </div>
      <div class="nas-checkbox-row" style="margin-top:20px">
        <input type="checkbox" id="vr-agree">
        <label for="vr-agree">I agree to the <a href="<?= esc_url(home_url('/terms-conditions/')) ?>" target="_blank">Terms & Conditions</a> and <a href="<?= esc_url(home_url('/privacy-policy/')) ?>" target="_blank">Privacy Policy</a></label>
      </div>
      <div class="nas-vr-actions">
        <button class="nas-vr-btn nas-vr-btn-outline" onclick="vrGoStep(2)"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button class="nas-vr-btn nas-vr-btn-primary" id="vr-submit-btn" onclick="vrSubmit()">
          <i class="fa-solid fa-user-plus"></i> Create Vendor Account
        </button>
      </div>
      <div class="nas-vr-agreement">Already have an account? <a href="<?= esc_url($login_url) ?>">Login here</a></div>
    </div>

    <!-- Success state -->
    <div class="nas-vr-panel" id="vr-panel-success">
      <div class="nas-vr-success">
        <div class="nas-vr-success-icon">🎉</div>
        <h2>Registration Successful!</h2>
        <p>Your vendor account has been created. Our team will review your application and activate your account within 24 hours. You will receive a confirmation email shortly.</p>
        <a href="<?= esc_url($login_url) ?>" class="nas-vr-btn nas-vr-btn-primary" style="display:inline-flex">
          <i class="fa-solid fa-right-to-bracket"></i> Login to Vendor Portal
        </a>
      </div>
    </div>

  </div><!-- /card -->
</div><!-- /wrap -->

<script>
(function(){
var AJAX='<?= esc_js(get_permalink() ?: home_url('/')) ?>';
var NONCE='<?= esc_js($nonce) ?>';
var vrData={};
var vrCurrentStep=1;

// Load cities and newspapers for step 2
function vrLoadSelects(){
  fetch(AJAX,{method:'POST',body:qs({action:'nas_get_cities',nonce:NONCE})}).then(r=>r.json()).then(d=>{
    var cities=d.data?.cities||[];
    document.getElementById('vr-cities').innerHTML=cities.map(c=>'<option value="'+esc(c.name)+'">'+esc(c.name)+' ('+esc(c.state)+')</option>').join('');
  });
  fetch(AJAX,{method:'POST',body:qs({action:'nas_get_newspapers',nonce:NONCE})}).then(r=>r.json()).then(d=>{
    var papers=d.data?.newspapers||[];
    document.getElementById('vr-newspapers').innerHTML=papers.map(n=>'<option value="'+esc(n.name)+'">'+esc(n.name)+'</option>').join('');
  });
}

function qs(o){var fd=new FormData();Object.entries(o).forEach(([k,v])=>fd.append(k,v));return fd;}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showError(msg){var el=document.getElementById('vr-error');el.textContent=msg;el.style.display='block';el.scrollIntoView({behavior:'smooth',block:'nearest'});}
function hideError(){document.getElementById('vr-error').style.display='none';}

window.vrGoStep=function(n){
  document.querySelectorAll('.nas-vr-panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('vr-panel-'+n)?.classList.add('active');
  document.querySelectorAll('.nas-vr-step-btn').forEach((b,i)=>{
    b.classList.remove('active','done');
    if(i+1<n)b.classList.add('done');
    if(i+1===n)b.classList.add('active');
    b.disabled=(i+1>n);
  });
  document.getElementById('vr-progress').style.width=(n/3*100)+'%';
  vrCurrentStep=n;
  hideError();
  if(n===2)vrLoadSelects();
};

window.vrStep1Next=function(){
  var name=document.getElementById('vr-name').value.trim();
  var contact=document.getElementById('vr-contact').value.trim();
  var phone=document.getElementById('vr-phone').value.trim();
  var email=document.getElementById('vr-email').value.trim();
  if(!name||!contact){showError('Please enter your business name and contact person name.');return;}
  if(!phone||phone.replace(/\D/g,'').length<10){showError('Please enter a valid 10-digit phone number.');return;}
  if(!email||!email.includes('@')){showError('Please enter a valid email address.');return;}
  vrData.name=name;vrData.contact=contact;vrData.phone=phone;vrData.email=email;
  vrData.whatsapp=document.getElementById('vr-wa').value.trim();
  vrData.gst=document.getElementById('vr-gst').value.trim();
  vrData.pan=document.getElementById('vr-pan').value.trim();
  vrData.bank=document.getElementById('vr-bank').value.trim();
  vrData.notes=document.getElementById('vr-notes').value.trim();
  vrGoStep(2);
};

window.vrStep2Next=function(){
  var citySel=document.getElementById('vr-cities');
  var selected=Array.from(citySel.selectedOptions).map(o=>o.value);
  if(!selected.length){showError('Please select at least one city you cover.');return;}
  vrData.cities=JSON.stringify(selected);
  var npSel=document.getElementById('vr-newspapers');
  vrData.newspapers=JSON.stringify(Array.from(npSel.selectedOptions).map(o=>o.value));
  vrGoStep(3);
};

window.vrSubmit=function(){
  var user=document.getElementById('vr-username').value.trim();
  var pass=document.getElementById('vr-pass').value;
  var pass2=document.getElementById('vr-pass2').value;
  var agree=document.getElementById('vr-agree').checked;
  if(!user){showError('Please choose a username.');return;}
  if(!/^[a-z0-9_\.]+$/i.test(user)){showError('Username can only contain letters, numbers, dots and underscores.');return;}
  if(pass.length<8){showError('Password must be at least 8 characters.');return;}
  if(pass!==pass2){showError('Passwords do not match.');return;}
  if(!agree){showError('Please agree to the Terms & Conditions to continue.');return;}
  vrData.username=user;vrData.password=pass;
  var btn=document.getElementById('vr-submit-btn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Creating account…';
  fetch(AJAX,{method:'POST',body:qs(Object.assign({action:'nas_vendor_register',nonce:NONCE},vrData))})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-user-plus"></i> Create Vendor Account';
      if(d.success){vrGoStep(99);document.getElementById('vr-panel-success').classList.add('active');}
      else showError(d.data?.message||'Registration failed. Please try again.');
    }).catch(()=>{btn.disabled=false;showError('Network error. Please try again.');});
};

window.vrTogglePw=function(id,btn){
  var inp=document.getElementById(id);
  inp.type=inp.type==='password'?'text':'password';
  btn.innerHTML=inp.type==='password'?'<i class="fa-regular fa-eye"></i>':'<i class="fa-regular fa-eye-slash"></i>';
};

})();
</script>
