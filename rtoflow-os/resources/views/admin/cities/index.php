<?php
if (!defined('ABSPATH')) exit;
$pageTitle = 'City Management';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
global $wpdb;
$cities = $wpdb->get_results(
    "SELECT c.*, s.name as state_name
     FROM {$wpdb->prefix}rto_cities c
     LEFT JOIN {$wpdb->prefix}rto_states s ON s.id=c.state_id
     ORDER BY s.name, c.name",
    ARRAY_A
) ?: [];
$by_state = [];
foreach ($cities as $c) $by_state[$c['state_name'] ?: 'Unknown'][] = $c;
ksort($by_state);
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-header">
  <div>
    <h2>City Management</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Toggle city visibility — only active cities appear in booking forms</p>
  </div>
  <div style="display:flex;gap:8px">
    <label for="city-search" class="rto-visually-hidden">Search city</label>
    <input type="text" id="city-search" aria-label="Search city" placeholder="🔍 Search city..." style="padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px">
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      <?php foreach (array_keys($by_state) as $sname): ?>
      <a href="#state-<?= sanitize_title($sname) ?>" style="font-size:11px;padding:3px 10px;background:#f3f4f6;border-radius:12px;color:#374151;text-decoration:none"><?= esc_html($sname) ?></a>
      <?php endforeach; ?>
    </div>

    <?php foreach ($by_state as $sname => $state_cities): ?>
    <div id="state-<?= sanitize_title($sname) ?>" class="state-block" style="margin-bottom:20px">
      <h4 style="font-size:13px;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:6px;margin-bottom:8px">
        <?= esc_html($sname) ?> <span style="color:#94a3b8;font-weight:400">(<?= count($state_cities) ?>)</span>
      </h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
        <?php foreach ($state_cities as $c): ?>
        <div class="city-item" id="city-<?= $c['id'] ?>" data-name="<?= esc_attr(strtolower($c['name'])) ?>"
             style="display:flex;align-items:center;justify-content:space-between;background:<?= $c['is_active']?'#F0FDF4':'#F9FAFB' ?>;border:1px solid <?= $c['is_active']?'#BBF7D0':'#e5e7eb' ?>;border-radius:8px;padding:8px 12px">
          <span style="font-size:13px;font-weight:600;color:<?= $c['is_active']?'#166534':'#6b7280' ?>"><?= esc_html($c['name']) ?></span>
          <label style="position:relative;display:inline-block;width:36px;height:20px;cursor:pointer;flex-shrink:0">
            <input type="checkbox" aria-label="<?= $c['is_active']?'Deactivate':'Activate' ?> city: <?= esc_attr($c['name']) ?>" <?= $c['is_active']?'checked':'' ?> class="city-toggle" data-city-id="<?= $c['id'] ?>" style="opacity:0;width:0;height:0">
            <span style="position:absolute;inset:0;background:<?= $c['is_active']?'#16a34a':'#d1d5db' ?>;border-radius:20px;transition:.2s"></span>
            <span style="position:absolute;top:2px;left:<?= $c['is_active']?'18':'2' ?>px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s"></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function toggleCity(id,el){
  var wrap=document.getElementById('city-'+id);
  var on=el.checked;
  wrap.style.background=on?'#F0FDF4':'#F9FAFB';
  wrap.style.borderColor=on?'#BBF7D0':'#e5e7eb';
  var txt=wrap.querySelector('span');
  txt.style.color=on?'#166534':'#6b7280';
  var slider=el.nextElementSibling;
  slider.style.background=on?'#16a34a':'#d1d5db';
  var dot=slider.nextElementSibling;
  dot.style.left=on?'18px':'2px';
  fetch('<?= admin_url('admin-ajax.php') ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rto_admin&rto_area=admin&rto_action=add_city&city_id='+id+'&active='+(on?1:0)+'&nonce=<?= $nonce ?>'});
}
function searchCity(q){
  q=q.toLowerCase().trim();
  document.querySelectorAll('.city-item').forEach(function(el){
    el.style.display=(!q||el.getAttribute('data-name').includes(q))?'flex':'none';
  });
  document.querySelectorAll('.state-block').forEach(function(b){
    var visible=b.querySelectorAll('.city-item[style*="display: flex"],.city-item:not([style])').length;
    b.style.display=(visible>0||!q)?'block':'none';
  });
}
document.querySelectorAll('.city-toggle').forEach(function(el){
  el.addEventListener('change', function(){ toggleCity(parseInt(el.dataset.cityId,10), el); });
});
var citySearchInput = document.getElementById('city-search');
if (citySearchInput) citySearchInput.addEventListener('input', function(){ searchCity(this.value); });
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
