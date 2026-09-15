<?php
/**
 * Home page inline view — used by [rtoflow_home] shortcode.
 * Does NOT output <html>, <head>, or wp_head() — those come from the WP theme.
 * Variables available: $company, $phone, $services, $by_cat, $lead_count, $city_count, $city_list
 */
if (!defined('ABSPATH')) exit;
?>
<style>
/* CSP hardening: hover states moved out of onmouseover/onmouseout attributes
   into real CSS rules (script-src nonce covers <script> tags only, not
   inline event-handler attributes). */
.rto-svc-chip:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.1);border-color:var(--chip-c)!important}
.rto-city-pill:hover{background:#EFF6FF!important;color:#2563EB!important}
</style>
<?php
$company    = $company    ?? get_option('rtoflow_company_name', 'RTOASSIST');
$phone      = $phone      ?? get_option('rtoflow_company_phone', '');
$lead_count = $lead_count ?? 0;
$city_count = $city_count ?? 0;
$by_cat     = $by_cat     ?? [];
$city_list  = $city_list  ?? [];
// CORRECTED (service-claims audit): every one of these four numbers was
// previously either padded with a fake floor (a literal '5,000' fallback,
// city_count ?? 78) or invented outright ('8+ Years Experience', '15+
// Expert Agents' — nothing in this codebase tracks either figure). "Expert
// Agents" now comes from a real, KYC-verified, active vendor count; "Years
// Experience" is dropped entirely rather than replaced with another
// invented number — there's no real founding-date data anywhere to compute
// it from. Any stat with no real data behind it (count of 0) is omitted.
global $wpdb;
$verifiedAgentCount = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}rto_vendors WHERE status='active' AND kyc_status='verified'"
);
$stats = [];
if ($lead_count > 0)        $stats[] = [number_format($lead_count), '+', 'Services Completed'];
if ($city_count > 0)        $stats[] = [$city_count, '+', 'Cities Covered'];
if ($verifiedAgentCount > 0) $stats[] = [$verifiedAgentCount, '+', 'Verified Agents'];
?>

<!-- RTOFLOW Homepage (shortcode) -->
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/website.css') ?>?v=<?= RTOFLOW_VERSION ?>">

<!-- Info strip -->
<div style="background:#1B2A6B;color:rgba(255,255,255,.85);font-size:12px;padding:8px 24px;text-align:center">
  <?php if ($phone): ?><span>📞 <a href="tel:<?= esc_attr($phone) ?>" style="color:#fff;font-weight:700"><?= esc_html($phone) ?></a></span> &nbsp;|&nbsp;<?php endif; ?>
  <span>🕐 Mon–Sat 9AM–7PM</span> &nbsp;|&nbsp; <span>🇮🇳 Pan India Service</span>
</div>

<!-- Hero Section -->
<section style="background:linear-gradient(135deg,#0A1628 0%,#0F172A 40%,#1B2A6B 70%,#243B8A 100%);color:#fff;padding:60px 24px 52px">
  <div class="rto-container" style="max-width:1100px;margin:0 auto">
    <div style="display:grid;grid-template-columns:1fr 360px;gap:48px;align-items:center">
      <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.15em;color:#E97B28;text-transform:uppercase;margin-bottom:14px">✦ TRUSTED RTO SERVICE PARTNER</div>
        <h2 style="font-size:36px;font-weight:900;line-height:1.2;margin-bottom:20px;letter-spacing:-.02em">
          All Your RTO Work,<br>
          <span style="color:#E97B28">Done Quick & Hassle-Free</span>
        </h2>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
          <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:13px 26px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
          <a href="<?= home_url('/pricing') ?>" style="display:inline-flex;align-items:center;gap:6px;background:transparent;color:#fff;padding:13px 26px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none;border:2px solid rgba(255,255,255,.35)">Our Pricing</a>
        </div>
        <?php if ($phone): ?>
        <a href="tel:<?= esc_attr($phone) ?>" style="display:inline-flex;align-items:center;gap:12px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px 18px;color:#fff;text-decoration:none">
          <span style="background:#E97B28;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center">📞</span>
          <div><div style="font-size:10px;opacity:.7;letter-spacing:.05em">CALL NOW FREE</div><div style="font-size:18px;font-weight:800"><?= esc_html($phone) ?></div></div>
        </a>
        <?php endif; ?>
      </div>
      <!-- Quick Quote Card -->
      <div style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="background:#E97B28;color:#fff;padding:14px 20px;font-size:15px;font-weight:700;text-align:center">⚡ Get Estimated Quote Instantly</div>
        <div style="padding:18px">
          <div style="margin-bottom:10px">
            <label for="qs-svc" style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px">Service Required</label>
            <select id="qs-svc" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;color:#374151">
              <option value="">Select a service...</option>
              <?php foreach ($services ?? [] as $s): ?>
              <option value="<?= esc_attr($s['slug']) ?>"><?= esc_html($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="margin-bottom:10px">
            <label for="qs-phone" style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px">Mobile Number</label>
            <input type="tel" id="qs-phone" placeholder="10-digit mobile" maxlength="10" style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;color:#374151">
          </div>
          <button id="qs-submit-btn" style="width:100%;background:#E97B28;color:#fff;border:none;padding:12px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">Get Free Quote →</button>
          <p style="font-size:11px;color:#94a3b8;text-align:center;margin:8px 0 0">Free consultation. No spam.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Stats Bar -->
<div style="background:#fff;border-top:3px solid #E97B28;padding:20px 24px;box-shadow:0 2px 12px rgba(0,0,0,.06)">
  <div style="display:flex;justify-content:center;flex-wrap:wrap;max-width:800px;margin:0 auto">
    <?php foreach ($stats as [$n,$s,$l]): ?>
    <div style="flex:1;min-width:120px;text-align:center;padding:10px 16px;border-right:1px solid #e2e8f0">
      <div style="font-size:30px;font-weight:900;color:#1B2A6B;line-height:1"><?= esc_html((string)$n) ?><span style="font-size:22px;color:#E97B28"><?= $s ?></span></div>
      <div style="font-size:12px;color:#64748b;font-weight:600;margin-top:3px"><?= esc_html($l) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Featured Services -->
<section style="padding:60px 24px;background:#fff">
  <div class="rto-container" style="max-width:1100px;margin:0 auto">
    <div style="text-align:center;margin-bottom:28px">
      <h2 style="font-size:26px;font-weight:800;color:#1B2A6B;margin-bottom:8px">Our Most Popular Services</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
      <?php foreach([
        ['🔄','RC Transfer',     '#EFF6FF','#2563EB','₹2,500+'],
        ['♻️','RC Renewal',      '#F0FDF4','#16A34A','₹1,000+'],
        ['🪪','Driving License', '#FFF7ED','#EA580C','₹2,500+'],
        ['📄','NOC for Vehicle', '#FFFBEB','#D97706','₹1,200+'],
        ['🏦','Hypothecation',   '#F0FDFA','#0D9488','₹1,000+'],
        ['📍','Address Change',  '#FEF2F2','#DC2626','₹600+'],
        ['🔁','DL Renewal',      '#F5F3FF','#7C3AED','₹1,500+'],
        ['✈️','Intl. DL',        '#FDF4FF','#9333EA','₹3,000+'],
      ] as [$icon,$name,$bg,$color,$price]): ?>
      <a href="<?= home_url('/rto-apply/') ?>" class="rto-svc-chip" style="--chip-c:<?= esc_attr($color) ?>;display:flex;flex-direction:column;align-items:center;gap:10px;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px 12px;text-align:center;text-decoration:none;transition:all .2s">
        <div style="width:58px;height:58px;border-radius:12px;background:<?= $bg ?>;color:<?= $color ?>;display:flex;align-items:center;justify-content:center;font-size:26px"><?= $icon ?></div>
        <div style="font-size:13px;font-weight:700;color:#0f172a"><?= esc_html($name) ?></div>
        <div style="font-size:13px;font-weight:800;color:<?= $color ?>"><?= $price ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:24px">
      <a href="<?= home_url('/rto-service/all') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#1B2A6B;color:#fff;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700;text-decoration:none">View All 30+ Services →</a>
    </div>
  </div>
</section>

<!-- How It Works -->
<section style="background:#F8FAFC;padding:60px 24px">
  <div class="rto-container" style="max-width:1100px;margin:0 auto">
    <div style="text-align:center;margin-bottom:28px">
      <h2 style="font-size:26px;font-weight:800;color:#1B2A6B;margin-bottom:8px">How It Works</h2>
      <p style="color:#64748b">A simple process, with doorstep pickup/drop where available in your city</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px">
      <?php foreach([
        ['01','📋','Submit Request','Fill quick form or call us.'],
        ['02','🚗','Document Collection','Where available in your city, an agent collects documents (charges may apply); otherwise you submit them yourself.'],
        ['03','🏛','RTO Filing','We file your application and follow up on your behalf; the RTO decides the outcome.'],
        ['04','📬','Document Handover','Final document delivered where available, or ready for you to collect.'],
      ] as [$num,$icon,$title,$desc]): ?>
      <div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:24px 18px;text-align:center">
        <div style="width:36px;height:36px;border-radius:50%;background:#E97B28;color:#fff;font-size:13px;font-weight:900;display:flex;align-items:center;justify-content:center;margin:0 auto 10px"><?= $num ?></div>
        <div style="font-size:28px;margin-bottom:8px"><?= $icon ?></div>
        <h3 style="font-size:14px;font-weight:700;color:#1B2A6B;margin-bottom:5px"><?= esc_html($title) ?></h3>
        <p style="font-size:12px;color:#64748b;margin:0"><?= esc_html($desc) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Cities -->
<?php if ($city_list): ?>
<section style="padding:48px 24px;background:#fff">
  <div class="rto-container" style="max-width:1100px;margin:0 auto">
    <div style="text-align:center;margin-bottom:16px">
      <h2 style="font-size:20px;font-weight:800;color:#1B2A6B">We Serve <?= $city_count ?>+ Cities</h2>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:7px;justify-content:center;margin-bottom:14px">
      <?php foreach ($city_list as $c): ?>
      <a href="<?= home_url('/rto-agent-in-' . esc_attr($c['slug'])) ?>"
         class="rto-city-pill"
         style="background:#F8FAFC;border:1px solid #e2e8f0;border-radius:20px;padding:5px 12px;font-size:11px;font-weight:600;color:#374151;text-decoration:none">
        📍 <?= esc_html($c['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center">
      <a href="<?= home_url('/rto-services-cities') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:2px solid #1B2A6B;color:#1B2A6B;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">View All <?= $city_count ?>+ Cities →</a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- CTA Band -->
<section style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;padding:52px 24px;text-align:center">
  <div class="rto-container" style="max-width:700px;margin:0 auto">
    <h2 style="font-size:26px;font-weight:800;margin-bottom:10px">Ready to Get Your RTO Work Done?</h2>
    <p style="opacity:.85;margin-bottom:22px">Free quote in 30 minutes. No office visits required.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      <a href="<?= home_url('/rto-apply/') ?>" style="background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
      <?php if ($phone): ?><a href="tel:<?= esc_attr($phone) ?>" style="background:transparent;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none;border:2px solid rgba(255,255,255,.4)">📞 <?= esc_html($phone) ?></a><?php endif; ?>
    </div>
  </div>
</section>

<style>
@media(max-width:768px){
  [style*="grid-template-columns:1fr 360px"],[style*="grid-template-columns:repeat(4,1fr)"]{display:block!important}
  [style*="grid-template-columns:repeat(4,1fr)"] > *{margin-bottom:12px}
  h2[style*="font-size:36px"]{font-size:24px!important}
}
</style>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('qs-submit-btn').addEventListener('click',rtoQuickSubmit);
function rtoQuickSubmit(){
  var ph=document.getElementById('qs-phone').value.trim().replace(/\D/g,'');
  if(!ph||ph.length<10){alert('Please enter a valid 10-digit mobile number');return;}
  window.location.href='<?= esc_js(home_url('/rto-apply/')) ?>';
}
</script>
