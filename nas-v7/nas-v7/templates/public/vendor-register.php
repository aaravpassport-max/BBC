<?php
/**
 * NAS Vendor Registration Page — portal design
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
$s         = nas_portal_live_stats();
?>
<div class="nas-portal-page nas-vr-page">
  <div class="nas-portal-wrap" style="padding-bottom:0">
    <div class="nas-portal-hero" style="margin-bottom:0">
      <span class="nas-portal-hero__eyebrow"><i class="fa-solid fa-handshake"></i> Partner Program</span>
      <h1>Become a <span>Vendor Partner</span></h1>
      <p>Join <?php echo esc_html( $brand ); ?> and start receiving newspaper ad bookings in your area.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <div class="nas-vr-split">
    <div class="nas-vr-trust-panel">
      <h2>Grow Your Agency Business</h2>
      <p>Partner with India's trusted newspaper ad platform and receive qualified bookings from advertisers in your coverage area.</p>
      <ul class="nas-vr-benefits">
        <li><i class="fa-solid fa-check"></i> Receive verified ad bookings from your cities</li>
        <li><i class="fa-solid fa-check"></i> Dedicated vendor dashboard with order management</li>
        <li><i class="fa-solid fa-check"></i> Transparent commission structure and payouts</li>
        <li><i class="fa-solid fa-check"></i> Access to <?php echo $s['papers'] > 0 ? (int) $s['papers'] . '+' : '50+'; ?> newspaper partnerships</li>
        <li><i class="fa-solid fa-check"></i> Training and onboarding support from our team</li>
      </ul>
    </div>

    <div class="nas-vr-form-panel">
      <div class="nas-vr-wrap">
        <div class="nas-vr-header">
          <?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" height="36" style="margin-bottom:12px" alt="<?php echo esc_attr( $brand ); ?>"><br><?php endif; ?>
          <h1>Vendor Registration</h1>
          <p>Complete the form below — our team reviews applications within 24 hours.</p>
        </div>

        <div class="nas-vr-card">
          <div class="nas-vr-progress"><div class="nas-vr-progress-bar" id="vr-progress" style="width:33%"></div></div>

          <div class="nas-vr-steps">
            <button type="button" class="nas-vr-step-btn active" id="vr-step-btn-1" onclick="vrGoStep(1)">
              <span class="nas-vr-step-num">1</span>Business Info
            </button>
            <button type="button" class="nas-vr-step-btn" id="vr-step-btn-2" onclick="vrGoStep(2)" disabled>
              <span class="nas-vr-step-num">2</span>Coverage Area
            </button>
            <button type="button" class="nas-vr-step-btn" id="vr-step-btn-3" onclick="vrGoStep(3)" disabled>
              <span class="nas-vr-step-num">3</span>Account Setup
            </button>
          </div>

          <div id="vr-error" class="nas-vr-error"></div>

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
              <button type="button" class="nas-vr-btn nas-vr-btn-primary" onclick="vrStep1Next()">Next: Coverage Area <i class="fa-solid fa-arrow-right"></i></button>
            </div>
          </div>

          <div class="nas-vr-panel" id="vr-panel-2">
            <div class="nas-vr-grid">
              <div class="nas-vr-field full">
                <label class="nas-vr-label">Cities You Cover <span>*</span></label>
                <select id="vr-cities" class="nas-vr-select" multiple size="8" style="height:180px">
                  <option value="loading" disabled>Loading cities…</option>
                </select>
                <small style="color:var(--nas-text-muted);font-size:0.75rem">Hold Ctrl / Cmd to select multiple cities</small>
              </div>
              <div class="nas-vr-field full">
                <label class="nas-vr-label">Newspapers You Work With</label>
                <select id="vr-newspapers" class="nas-vr-select" multiple size="8" style="height:180px">
                  <option value="loading" disabled>Loading newspapers…</option>
                </select>
                <small style="color:var(--nas-text-muted);font-size:0.75rem">Hold Ctrl / Cmd to select multiple newspapers</small>
              </div>
            </div>
            <div class="nas-vr-actions">
              <button type="button" class="nas-vr-btn nas-vr-btn-outline" onclick="vrGoStep(1)"><i class="fa-solid fa-arrow-left"></i> Back</button>
              <button type="button" class="nas-vr-btn nas-vr-btn-primary" onclick="vrStep2Next()">Next: Account Setup <i class="fa-solid fa-arrow-right"></i></button>
            </div>
          </div>

          <div class="nas-vr-panel" id="vr-panel-3">
            <div class="nas-vr-grid">
              <div class="nas-vr-field full">
                <label class="nas-vr-label">Choose a Username <span>*</span></label>
                <input type="text" id="vr-username" class="nas-vr-input" placeholder="lowercase, no spaces" autocomplete="new-password">
                <small style="color:var(--nas-text-muted);font-size:0.75rem">Used to log in. Cannot be changed later.</small>
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
              <label for="vr-agree">I agree to the <a href="<?php echo esc_url( home_url( '/terms-conditions/' ) ); ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a> and <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank" rel="noopener">Privacy Policy</a></label>
            </div>
            <div class="nas-vr-actions">
              <button type="button" class="nas-vr-btn nas-vr-btn-outline" onclick="vrGoStep(2)"><i class="fa-solid fa-arrow-left"></i> Back</button>
              <button type="button" class="nas-vr-btn nas-vr-btn-primary" id="vr-submit-btn" onclick="vrSubmit()">
                <i class="fa-solid fa-user-plus"></i> Create Vendor Account
              </button>
            </div>
            <div class="nas-vr-agreement">Already have an account? <a href="<?php echo esc_url( $login_url ); ?>">Login here</a></div>
          </div>

          <div class="nas-vr-panel" id="vr-panel-success">
            <div class="nas-vr-success">
              <div class="nas-vr-success-icon">🎉</div>
              <h2>Registration Successful!</h2>
              <p>Your vendor account has been created. Our team will review your application and activate your account within 24 hours. You will receive a confirmation email shortly.</p>
              <a href="<?php echo esc_url( $login_url ); ?>" class="nas-vr-btn nas-vr-btn-primary" style="display:inline-flex">
                <i class="fa-solid fa-right-to-bracket"></i> Login to Vendor Portal
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php
  nas_portal_block_process( 'Partner Onboarding', 'How Vendor Partnership Works', 'From application to your first booking — a clear, supported process.' );
  nas_portal_block_faq( [
      [ 'Who can become a vendor partner?', 'Newspaper ad agencies, freelancers, and media professionals with experience placing ads in local or national newspapers can apply. You must cover at least one city and have valid contact details.' ],
      [ 'How long does approval take?', 'Most applications are reviewed within 24 business hours. You will receive an email once your account is activated.' ],
      [ 'How do I receive bookings?', 'Once activated, bookings for your covered cities are assigned to your vendor dashboard. You manage fulfillment, proof submission, and client communication through the portal.' ],
      [ 'What commission structure applies?', 'Commission rates are shared during onboarding. Transparent payout tracking is available in your vendor dashboard.' ],
  ], 'Vendor FAQ' );
  nas_portal_block_cta( 'Questions About Partnering?', 'Speak with our partnerships team before you apply — we are happy to walk you through the program.', nas_portal_contact_url(), 'Contact Us' );
  ?>
</div>

<script>
(function(){
var AJAX='<?php echo esc_js(get_permalink() ?: home_url('/')); ?>';
var NONCE='<?php echo esc_js($nonce); ?>';
var vrData={};
var vrCurrentStep=1;

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
  if(n===99){
    document.querySelectorAll('.nas-vr-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('vr-panel-success')?.classList.add('active');
    return;
  }
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
      if(d.success){vrGoStep(99);}
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
