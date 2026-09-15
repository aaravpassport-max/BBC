<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');

// $services and $servicesByCategory are passed from Router::routeWebsite()
// They contain ONLY visible services with show_price flag per service.
// If $servicesByCategory not passed (legacy), build it.
if (empty($servicesByCategory)) {
    $servicesByCategory = [];
    foreach ($services ?? [] as $s) {
        $servicesByCategory[$s['category']][] = $s;
    }
}
$by_cat = $servicesByCategory; // alias for template
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc','canonical'));
?>

<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:52px 24px">
  <div class="rto-container">
    <div style="font-size:12px;opacity:.7;margin-bottom:8px">
      <a href="<?= home_url('/') ?>" style="color:#fff">Home</a> › 
      <a href="<?= home_url('/rto-services-cities') ?>" style="color:#fff">All Cities</a> › 
      <?= esc_html($city['name'] ?? '') ?>
    </div>
    <h1 style="font-size:32px;font-weight:900;margin-bottom:12px">
      RTO Services in <?= esc_html($city['name'] ?? '') ?>, <?= esc_html($city['state_name'] ?? '') ?>
    </h1>
    <p style="opacity:.85;font-size:16px;margin-bottom:20px">
      Expert RTO application assistance in <?= esc_html($city['name'] ?? '') ?>. Doorstep document pickup/drop, where
      our courier/agent network covers this city, is available for an additional charge.
      RC Transfer, Driving License, NOC, Hypothecation & 30+ more services.
    </p>
    <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote in <?= esc_html($city['name'] ?? '') ?> →</a>
  </div>
</section>

<section style="padding:48px 24px;background:#fff">
  <div class="rto-container">
    <h2 style="font-size:22px;font-weight:800;color:#1B2A6B;margin-bottom:20px">
      Available Services in <?= esc_html($city['name'] ?? '') ?>
    </h2>
    <?php if (empty($services)): ?>
    <p style="color:#64748b">We provide RTO services in <?= esc_html($city['name'] ?? '') ?>. <a href="<?= home_url('/rto-apply/') ?>" style="color:#2563EB">Contact us for a quote</a>.</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
      <?php
      $cat_colors=['RC & Ownership'=>['#EFF6FF','#2563EB'],'NOC Services'=>['#FFF7ED','#EA580C'],'Driving License'=>['#F0FDF4','#16A34A'],'Hypothecation'=>['#FDF2F8','#DB2777'],'Vehicle Registration'=>['#F5F3FF','#7C3AED'],'Commercial & Permits'=>['#FFFBEB','#D97706'],'Other Services'=>['#F0FDFA','#0D9488']];
      foreach ($by_cat as $cat=>$svcs):
        $cc=$cat_colors[$cat]??['#EFF6FF','#2563EB'];
      ?>
      <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden">
        <div style="background:<?=$cc[0]?>;color:<?=$cc[1]?>;padding:12px 16px;font-weight:700;font-size:13px"><?=esc_html($cat)?></div>
        <div style="padding:6px 12px">
          <?php foreach(array_slice($svcs,0,5) as $s): ?>
          <a href="<?=home_url('/rto-apply/')?>" style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:12px;color:#374151;text-decoration:none">
            <span><?=esc_html($s['name'])?></span>
            <?php if (!empty($s['show_price']) && !empty($s['display_total'])): ?>
            <span style="font-weight:700;color:<?=$cc[1]?>">₹<?=number_format($s['display_total'],0)?></span>
            <?php elseif (!empty($s['show_price']) && !empty($s['display_service_charge'])): ?>
            <span style="font-weight:700;color:<?=$cc[1]?>">₹<?=number_format((float)$s['display_service_charge'] + (float)($s['display_govt_fee']??0),0)?></span>
            <?php else: ?>
            <span style="font-size:11px;color:#94a3b8">Get Quote</span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
        <a href="<?=home_url('/rto-apply/')?>" style="display:block;padding:10px 16px;font-size:12px;font-weight:700;color:<?=$cc[1]?>;text-decoration:none;border-top:1px solid #f1f5f9">Book →</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($nearby)): ?>
<section style="padding:40px 24px;background:#F8FAFC">
  <div class="rto-container">
    <h2 style="font-size:18px;font-weight:800;color:#1B2A6B;margin-bottom:14px">Nearby Cities We Also Serve</h2>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach($nearby as $nc): ?>
      <a href="<?=home_url('/rto-agent-in-'.esc_attr($nc['slug']))?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:600;color:#374151;text-decoration:none">
        📍 <?=esc_html($nc['name'])?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;padding:48px 24px;text-align:center">
  <div class="rto-container">
    <h2 style="font-size:24px;font-weight:800;margin-bottom:10px">Book Your RTO Service in <?=esc_html($city['name']??'')?></h2>
    <p style="opacity:.85;margin-bottom:20px">Get a free quote within 30 minutes. No advance payment required.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a href="<?=home_url('/rto-apply/')?>" style="background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
      <?php if($phone): ?><a href="tel:<?=esc_attr($phone)?>" style="background:transparent;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none;border:2px solid rgba(255,255,255,.4)">📞 <?=esc_html($phone)?></a><?php endif; ?>
    </div>
  </div>
</section>

<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
