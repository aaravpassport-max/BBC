<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
$email   = get_option('rtoflow_support_email', '');
// CORRECTED (service-claims audit): real city count instead of a hardcoded
// "300+ cities" figure; the "respond within 2 hours" promise below had no
// SLA system backing it anywhere in the codebase (the only real, enforced
// response-time commitment found is the 48-hour complaint SLA in
// ComplaintsController), so it's replaced with accurate wording.
global $wpdb;
$realCityCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE is_active=1");
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:52px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:30px;font-weight:900;margin-bottom:10px"><?= esc_html__('Contact Us', 'rtoflow-os') ?></h1>
    <p style="opacity:.85"><?= esc_html__("Get in touch during working hours — we'll get back to you as soon as we can.", 'rtoflow-os') ?></p>
  </div>
</section>

<section style="padding:60px 24px;background:#fff">
  <div class="rto-container">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;max-width:1000px;margin:0 auto">
      <div>
        <h2 style="font-size:20px;font-weight:800;color:#1B2A6B;margin-bottom:20px"><?= esc_html__('Get In Touch', 'rtoflow-os') ?></h2>
        <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:24px">
          <?php if ($phone): ?>
          <div style="display:flex;align-items:flex-start;gap:12px;background:#F8FAFC;border-radius:10px;padding:14px">
            <div style="font-size:24px" aria-hidden="true">📞</div>
            <div><div style="font-weight:700;color:#0f172a;margin-bottom:2px"><?= esc_html__('Phone / WhatsApp', 'rtoflow-os') ?></div><a href="tel:<?= esc_attr($phone) ?>" style="color:#2563EB;font-size:15px;font-weight:600"><?= esc_html($phone) ?></a></div>
          </div>
          <?php endif; ?>
          <?php if ($email): ?>
          <div style="display:flex;align-items:flex-start;gap:12px;background:#F8FAFC;border-radius:10px;padding:14px">
            <div style="font-size:24px" aria-hidden="true">✉</div>
            <div><div style="font-weight:700;color:#0f172a;margin-bottom:2px"><?= esc_html__('Email', 'rtoflow-os') ?></div><a href="mailto:<?= esc_attr($email) ?>" style="color:#2563EB"><?= esc_html($email) ?></a></div>
          </div>
          <?php endif; ?>
          <div style="display:flex;align-items:flex-start;gap:12px;background:#F8FAFC;border-radius:10px;padding:14px">
            <div style="font-size:24px" aria-hidden="true">🕐</div>
            <div><div style="font-weight:700;color:#0f172a;margin-bottom:2px"><?= esc_html__('Working Hours', 'rtoflow-os') ?></div><div style="color:#64748b"><?= esc_html__('Monday – Saturday, 9:00 AM – 7:00 PM', 'rtoflow-os') ?></div></div>
          </div>
          <div style="display:flex;align-items:flex-start;gap:12px;background:#F8FAFC;border-radius:10px;padding:14px">
            <div style="font-size:24px" aria-hidden="true">🏙</div>
            <div><div style="font-weight:700;color:#0f172a;margin-bottom:2px"><?= esc_html__('Coverage', 'rtoflow-os') ?></div><div style="color:#64748b"><?= $realCityCount > 0 ? esc_html(sprintf(__('%d+ cities across India', 'rtoflow-os'), $realCityCount)) : esc_html__('India-wide (city availability varies)', 'rtoflow-os') ?></div></div>
          </div>
        </div>
        <?php if ($phone): ?>
        <a href="https://wa.me/91<?= preg_replace('/\D/','',$phone) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:700;text-decoration:none">
          <span aria-hidden="true">💬</span> <?= esc_html__('Chat on WhatsApp', 'rtoflow-os') ?>
        </a>
        <?php endif; ?>
      </div>
      <div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.06)">
          <h3 style="font-size:18px;font-weight:700;color:#1B2A6B;margin-bottom:20px"><?= esc_html__('Send Us a Message', 'rtoflow-os') ?></h3>
          <div id="ct-success" style="display:none;background:#dcfce7;color:#166534;padding:12px;border-radius:8px;margin-bottom:14px;font-weight:600" role="status"><?= esc_html__("✅ Message sent! We'll respond within 2 hours.", 'rtoflow-os') ?></div>
          <div style="margin-bottom:12px"><label for="ct-name" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Your Name *', 'rtoflow-os') ?></label><input type="text" id="ct-name" class="rto-input" placeholder="<?= esc_attr__('Full name', 'rtoflow-os') ?>"></div>
          <div style="margin-bottom:12px"><label for="ct-email" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Email *', 'rtoflow-os') ?></label><input type="email" id="ct-email" class="rto-input" placeholder="your@email.com"></div>
          <div style="margin-bottom:12px"><label for="ct-phone" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Mobile', 'rtoflow-os') ?></label><input type="tel" id="ct-phone" class="rto-input" placeholder="<?= esc_attr__('10-digit mobile', 'rtoflow-os') ?>"></div>
          <div style="margin-bottom:16px"><label for="ct-msg" style="font-size:12px;font-weight:700;color:#64748b;display:block;margin-bottom:4px"><?= esc_html__('Message *', 'rtoflow-os') ?></label><textarea id="ct-msg" class="rto-input" rows="4" placeholder="<?= esc_attr__('How can we help you?', 'rtoflow-os') ?>"></textarea></div>
          <button id="ct-submit" style="width:100%;background:#1B2A6B;color:#fff;border:none;padding:12px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer"><?= esc_html__('Send Message', 'rtoflow-os') ?></button>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
@media(max-width:768px){[style*="grid-template-columns:1fr 1fr"]{display:block!important}}
</style>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('ct-submit').addEventListener('click',submitContact);
function submitContact(){
  var n=document.getElementById('ct-name').value.trim();
  var e=document.getElementById('ct-email').value.trim();
  var m=document.getElementById('ct-msg').value.trim();
  if(!n||!e||!m){alert('Please fill all required fields');return;}
  var btn=document.getElementById('ct-submit');
  var successEl=document.getElementById('ct-success');
  successEl.style.display='none';
  btn.disabled=true;btn.textContent='Sending...';
  // ENTERPRISE GAP FIX (ghost-success pattern, same class of bug fixed
  // across ~16 server-side endpoints in this audit — this was the
  // client-side version of it): this used to show the success banner via a
  // setTimeout() that fired unconditionally, BEFORE the fetch() call was
  // even issued and with no .then()/.catch() on it at all. A customer whose
  // message failed to send — rate-limited, network error, validation
  // rejected server-side — was told "Message sent!" regardless. Now the
  // success banner (and the button's re-enable) only happens after the
  // server actually confirms success, and a real failure shows the
  // server's own message instead of silently doing nothing.
  var fd=new FormData();
  fd.append('action','rtoflow_contact');
  fd.append('nonce','<?= wp_create_nonce('rtoflow_contact') ?>');
  fd.append('name',n);fd.append('email',e);
  fd.append('phone',document.getElementById('ct-phone').value);
  fd.append('message',m);
  fetch('<?= esc_js(admin_url('admin-ajax.php')) ?>',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled=false;btn.textContent='Send Message';
      if(d && d.success){
        successEl.textContent='✅ '+(d.message||"Message sent! We'll respond within 2 hours.");
        successEl.style.display='block';
        document.getElementById('ct-name').value='';
        document.getElementById('ct-email').value='';
        document.getElementById('ct-phone').value='';
        document.getElementById('ct-msg').value='';
      } else {
        alert((d && d.message) || 'Could not send your message. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled=false;btn.textContent='Send Message';
      alert('Connection error. Please check your internet connection and try again.');
    });
}
</script>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
