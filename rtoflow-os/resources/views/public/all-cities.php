<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<style>
/* CSP hardening: hover state moved out of onmouseover/onmouseout attributes
   into a real CSS rule (script-src nonce covers <script> tags only, not
   inline event-handler attributes). */
.rto-city-chip:hover{background:#EFF6FF!important;color:#2563EB!important;border-color:#2563EB!important}
</style>

<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:48px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:30px;font-weight:900;margin-bottom:10px">RTO Agent Services in <?= esc_html((string)($total ?? 0)) ?>+ Cities Across India</h1>
    <p style="opacity:.85;font-size:15px">Online RTO assistance — RC Transfer, DL, NOC, Hypothecation & more</p>
    <div style="margin-top:20px;max-width:400px;margin-left:auto;margin-right:auto">
      <input type="text" id="city-search" aria-label="Search your city" placeholder="🔍 Search your city..."
             style="width:100%;padding:12px 18px;font-size:14px;border-radius:50px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.15);outline:none;color:#1B2A6B">
    </div>
  </div>
</section>

<section style="padding:48px 24px;background:#F8FAFC">
  <div class="rto-container" id="cities-grid">
    <?php if (empty($by_state)): ?>
    <div style="text-align:center;padding:60px 20px">
      <div style="font-size:48px;margin-bottom:12px">🏙</div>
      <h3 style="color:#1B2A6B;margin-bottom:8px">Cities Loading...</h3>
      <p style="color:#64748b">Our city database is being set up. Please check back shortly.</p>
    </div>
    <?php else: ?>
      <?php foreach ($by_state as $state => $cities): ?>
      <div class="cities-state-block" data-state="<?= esc_attr(strtolower($state)) ?>" style="margin-bottom:28px">
        <h2 style="font-size:16px;font-weight:800;color:#1B2A6B;border-bottom:2px solid #EFF6FF;padding-bottom:10px;margin-bottom:14px">
          📍 <?= esc_html($state) ?> <span style="font-size:13px;font-weight:400;color:#94a3b8">(<?= count($cities) ?> cities)</span>
        </h2>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
          <?php foreach ($cities as $c): ?>
          <a href="<?= home_url('/rto-agent-in-' . esc_attr($c['slug'] ?: sanitize_title($c['name']))) ?>"
             class="rto-city-chip"
             style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;color:#374151;text-decoration:none;transition:all .15s">
            📍 <?= esc_html($c['name']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<section style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;padding:48px 24px;text-align:center">
  <div class="rto-container">
    <h2 style="font-size:24px;font-weight:800;margin-bottom:10px">Don't See Your City?</h2>
    <p style="opacity:.85;margin-bottom:20px">We're expanding rapidly. Call us to check availability in your area.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
      <?php if ($phone): ?><a href="tel:<?= esc_attr($phone) ?>" style="display:inline-flex;align-items:center;gap:6px;background:transparent;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none;border:2px solid rgba(255,255,255,.4)">📞 <?= esc_html($phone) ?></a><?php endif; ?>
    </div>
  </div>
</section>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('city-search').addEventListener('input',function(){searchCity(this.value);});
function searchCity(q){
  q=q.toLowerCase().trim();
  document.querySelectorAll('.cities-state-block').forEach(function(block){
    var links=block.querySelectorAll('a');
    var visible=0;
    links.forEach(function(a){
      var match=a.textContent.toLowerCase().includes(q);
      a.parentElement.style.display=match?'':'none';
      if(match) visible++;
    });
    block.style.display=(visible>0||q==='')?'block':'none';
  });
}
</script>

<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
