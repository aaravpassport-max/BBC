<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
$by_cat  = [];
foreach ($services ?? [] as $s) $by_cat[$s['category']][] = $s;
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));

$icons=['Transfer'=>'🔄','NOC'=>'📄','Hypothecation'=>'🏦','Driving License'=>'🪪','DL'=>'🪪',
        'Renewal'=>'♻️','Duplicate'=>'📋','Address'=>'📍','Fitness'=>'🔍','Scrapping'=>'♻️',
        'Registration'=>'📝','Permit'=>'✅','RC'=>'📃','Ownership'=>'🔄','Accident'=>'🚨'];
function p_icon($name){global $icons;foreach($icons as $k=>$v){if(stripos($name,$k)!==false)return $v;}return '🚗';}
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:52px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:30px;font-weight:900;margin-bottom:10px">Transparent RTO Service Pricing</h1>
    <p style="opacity:.85">All prices quoted upfront. Zero hidden charges. Government fees charged separately.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:16px">
      <span style="background:rgba(255,255,255,.15);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600">✅ No Hidden Fees</span>
      <span style="background:rgba(255,255,255,.15);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600">✅ Govt Fee Shown Separately</span>
      <span style="background:rgba(255,255,255,.15);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600">✅ Price Lock Guarantee</span>
    </div>
  </div>
</section>

<!-- Filter tabs -->
<div style="background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;position:sticky;top:0;z-index:100">
  <div class="rto-container" id="pf-tabs" style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="pf-btn active" data-cat="all" style="padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600;border:1.5px solid #1B2A6B;background:#1B2A6B;color:#fff;cursor:pointer">All Services</button>
    <?php foreach(array_keys($by_cat) as $cat): ?>
    <button class="pf-btn" data-cat="<?= esc_attr(sanitize_title($cat)) ?>" style="padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600;border:1.5px solid #e2e8f0;background:#fff;color:#374151;cursor:pointer"><?= esc_html($cat) ?></button>
    <?php endforeach; ?>
  </div>
</div>

<section style="padding:48px 24px;background:#F8FAFC">
  <div class="rto-container">
    <?php if (empty($by_cat)): ?>
    <div style="text-align:center;padding:60px;color:#64748b">
      <div style="font-size:48px;margin-bottom:12px">💰</div>
      <h3 style="color:#64748b;font-size:18px;font-weight:800;margin-bottom:6px">Pricing is being configured</h3><p>Please check back shortly.</p>
    </div>
    <?php endif; ?>
    <?php foreach ($by_cat as $cat => $svcs): ?>
    <div class="pricing-section" data-cat="<?= esc_attr(sanitize_title($cat)) ?>" style="margin-bottom:36px">
      <h2 style="font-size:18px;font-weight:800;color:#1B2A6B;margin-bottom:16px;display:flex;align-items:center;gap:10px">
        <span style="flex:1;height:1px;background:#e2e8f0"></span>
        <span><?= esc_html($cat) ?></span>
        <span style="flex:1;height:1px;background:#e2e8f0"></span>
      </h2>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        <?php foreach ($svcs as $s): ?>
        <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
          <div style="font-size:28px;margin-bottom:10px"><?= p_icon($s['name']) ?></div>
          <h3 style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:6px;line-height:1.3"><?= esc_html($s['name']) ?></h3>
          <?php if (!empty($s['description'])): ?>
          <p style="font-size:11px;color:#94a3b8;margin-bottom:10px;line-height:1.5"><?= esc_html(substr($s['description'],0,70)) ?></p>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-bottom:12px">
            <div style="flex:1;background:#EFF6FF;border-radius:8px;padding:8px;text-align:center">
              <div style="font-size:10px;color:#64748b;font-weight:600;margin-bottom:2px">OUR FEE</div>
              <div style="font-size:16px;font-weight:800;color:#2563EB">₹<?= number_format($s['base_price'],0) ?></div>
            </div>
            <?php if (!empty($s['govt_fee']) && $s['govt_fee'] > 0): ?>
            <div style="flex:1;background:#F0FDF4;border-radius:8px;padding:8px;text-align:center">
              <div style="font-size:10px;color:#64748b;font-weight:600;margin-bottom:2px">GOVT FEE*</div>
              <div style="font-size:16px;font-weight:800;color:#16A34A">₹<?= number_format($s['govt_fee'],0) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:10px">⏱ SLA: <?= (int)$s['sla_days'] ?> working days</div>
          <a href="<?= home_url('/rto-apply/') ?>" style="display:block;text-align:center;background:#E97B28;color:#fff;padding:8px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none">Book This Service →</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:14px 18px;font-size:12px;color:#78350F">
      <strong>* Government Fees Note:</strong> Govt. fees shown are approximate and may vary by state/city/vehicle type. Actual amount confirmed by our agent before collection. GST applicable as per prevailing rates.
    </div>
  </div>
</section>

<section style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;padding:48px 24px;text-align:center">
  <div class="rto-container">
    <h2 style="font-size:24px;font-weight:800;margin-bottom:10px">Get Your Exact Quote</h2>
    <p style="opacity:.85;margin-bottom:20px">Submit your details and we'll send you a detailed, itemised quote within 30 minutes.</p>
    <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
  </div>
</section>

<style>
@media(max-width:768px){[style*="grid-template-columns:repeat(3,1fr)"]{grid-template-columns:1fr!important}}
.pf-btn.active{background:#1B2A6B!important;color:#fff!important;border-color:#1B2A6B!important}
</style>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('pf-tabs').addEventListener('click',function(e){
  var btn=e.target.closest('.pf-btn');
  if(!btn) return;
  filterCat(btn.dataset.cat,btn);
});
function filterCat(cat,btn){
  document.querySelectorAll('.pf-btn').forEach(function(b){b.classList.remove('active');b.style.background='#fff';b.style.color='#374151';b.style.borderColor='#e2e8f0';});
  btn.classList.add('active');btn.style.background='#1B2A6B';btn.style.color='#fff';btn.style.borderColor='#1B2A6B';
  document.querySelectorAll('.pricing-section').forEach(function(s){
    s.style.display=(cat==='all'||s.getAttribute('data-cat')===cat)?'block':'none';
  });
}
</script>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
