<?php if (!defined('ABSPATH')) exit;
/** @var array|null $service @var array $services */
// FIX (reported: "this page has some CSS issue" + "no bottom navigation on
// mobile" on /rto-service/all/): this view used to call the WordPress
// THEME's own get_header()/get_footer() instead of the plugin's own
// layouts.website-header / layouts.website-footer partials that every other
// public page (home, all-cities, about, apply, etc.) renders through. That
// meant it never got the plugin's site chrome (branded header/nav, the
// mobile-nav.css stylesheet, or the fixed bottom nav that
// layouts/website-header.php injects for every other public page — see the
// comment in that file) and instead sat inside whatever the active WP theme
// happens to render, which is why it looked unstyled/generic and had no
// bottom nav on mobile. Switched to the same layout partials all-cities.php
// already uses.
rto_view('layouts.website-header', [
    'page_title' => 'RTO Services — ' . get_option('rtoflow_company_name','RTOASSIST'),
    'meta_desc'  => 'Browse every RTO service we assist with — Driving License, RC Services, Hypothecation, NOC, Vehicle Services and more.',
]);
$company = get_option('rtoflow_company_name','RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');

// FIX (reported: duplicate/incomplete category listing — e.g. both a raw
// "Hypothecation" bucket with 2 old services AND a separate "HP /
// Hypothecation" bucket with the current 5, "RC & Ownership" AND "RC
// Services" side by side, etc.): grouping straight off the DB's raw
// `category` text column mixes today's real 7-category catalog
// (IndiaDataSeeder) with a legacy, differently-worded catalog
// (database/seeds/Seeder.php's services(), which predates it and was never
// retired — see IndiaDataSeeder.php's slug-bug fix comment for how both
// ended up active at once). Group by the SAME canonical category map
// apply.php's own dropdowns and RealFormSchemaSeeder use instead, and only
// show a service if it's actually in that canonical catalog — this is the
// single source of truth for what a customer can currently apply for, so a
// leftover legacy row can never reappear here even if it's still is_active
// in the database.
$canonicalCatMap = \RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap();
$serviceNameToCat = [];
foreach ($canonicalCatMap as $ck => $cat) {
    foreach ($cat['services'] as $svcName) { $serviceNameToCat[$svcName] = $cat['title']; }
}
?>
<div style="max-width:1100px;margin:0 auto;padding:40px 20px">
<style>
@media(max-width:640px){
  #rto-svc-all-grid{grid-template-columns:1fr!important}
  #rto-svc-detail{grid-template-columns:1fr!important}
}
</style>

<?php if ($service): ?>
  <!-- Single service detail -->
  <div id="rto-svc-detail" style="display:grid;grid-template-columns:2fr 1fr;gap:32px;margin-bottom:48px">
    <div>
      <div class="rto-small rto-muted" style="margin-bottom:8px">
        <a href="<?= esc_url(home_url('/rto-apply/')) ?>">← All Services</a>
      </div>
      <h1 style="font-size:28px;font-weight:700;color:#1E3A5F;margin:0 0 8px"><?= esc_html($service['name']) ?></h1>
      <span style="background:#e8f4e8;color:#166534;border-radius:20px;padding:4px 12px;font-size:13px;font-weight:600">
        <?= esc_html($service['category']) ?>
      </span>

      <p style="font-size:16px;color:#555;margin:20px 0;line-height:1.7"><?= esc_html($service['description']) ?></p>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px">
        <?php foreach ([
          ['Starting From', '₹' . number_format((float)$service['base_price']), '#1E3A5F'],
          ['SLA',           $service['sla_days'] . ' Business Days', '#059669'],
          ['GST',           $service['gst_applicable']?'18% Applicable':'Exempt', '#6b7280'],
        ] as [$l,$v,$c]): ?>
        <div style="background:#f8fafc;border-radius:8px;padding:16px;text-align:center">
          <div style="font-size:20px;font-weight:700;color:<?= $c ?>"><?= esc_html($v) ?></div>
          <div style="font-size:12px;color:#6b7280;margin-top:4px"><?= esc_html($l) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php
        // Form Builder v2: send the customer to the admin-built schema-driven
        // form when one is active for this service, else the default static
        // apply form — never a broken or empty link either way.
        $applyHref = $hasDynamicForm
            ? home_url('/rto-apply-form/' . (int)$service['id'] . '/')
            : home_url('/rto-apply/?service=' . urlencode($service['id']));
      ?>
      <a href="<?= esc_url($applyHref) ?>"
         style="display:inline-block;background:#1E3A5F;color:#fff;padding:14px 32px;border-radius:6px;font-weight:600;font-size:16px;text-decoration:none">
        Apply Now →
      </a>

      <?php
        // SERVICE-SCOPE DISCLOSURE (service-claims audit): every service page
        // must state what's actually included, what the applicant may still
        // need to do, and that the RTO/authority — not us — controls the
        // outcome, rather than one generic "complete service" claim reused
        // everywhere. The service-specific parts of this (which documents
        // this service needs, whether courier pickup covers this service's
        // typical cities, exact additional charges) live in the service's
        // own admin-editable `description` field above and are NOT
        // fabricated here — this block only adds the parts that are true
        // for every service and were previously missing or contradicted
        // elsewhere on the portal.
      ?>
      <div style="background:#F8FAFC;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-top:28px;font-size:13.5px;color:#475569;line-height:1.7">
        <h3 style="font-size:15px;font-weight:700;color:#1E3A5F;margin:0 0 10px">Important Information About This Service</h3>
        <ul style="margin:0;padding-left:18px">
          <li><strong>What we do:</strong> we provide application assistance, guidance, and documentation support for
            <?= esc_html($service['name']) ?> — helping you understand the requirements, prepare your application and
            documents, and follow the correct RTO procedure.</li>
          <li><strong>Home pickup/drop:</strong> available only where our courier/agent network covers your city, is
            not included in the base fee shown above, and may involve an additional charge. Where it isn't available
            for your location, you'll need to submit or collect documents yourself — we'll guide you through it.</li>
          <li><strong>Government/authority responsibility:</strong> the RTO or relevant authority alone decides
            approval, appointment allocation, document verification, processing time, and issuance. The SLA shown
            above is our own service-processing estimate on our end, not a guarantee of the RTO's timeline or
            outcome.</li>
          <li><strong>What we don't do:</strong> we cannot expedite government processing, guarantee an appointment
            or approval, or influence the authority's decision.</li>
        </ul>
      </div>
    </div>

    <div>
      <div style="background:#1E3A5F;color:#fff;border-radius:12px;padding:24px">
        <h3 style="margin:0 0 16px;font-size:16px">Quick Enquiry</h3>
        <div style="display:flex;flex-direction:column;gap:10px">
          <input type="text" id="enqName" aria-label="Your name" placeholder="Your name" style="padding:10px;border-radius:6px;border:none;font-size:14px">
          <input type="tel" id="enqPhone" aria-label="Mobile number" placeholder="Mobile number" style="padding:10px;border-radius:6px;border:none;font-size:14px">
          <button id="enqBtn"
            style="background:#F59E0B;color:#fff;border:none;padding:12px;border-radius:6px;font-weight:600;font-size:15px;cursor:pointer">
            Get a Call Back
          </button>
          <div id="enqMsg" style="display:none;font-size:13px;margin-top:4px"></div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- All services directory -->
  <h1 style="font-size:28px;font-weight:700;color:#1E3A5F;margin:0 0 8px">RTO Services</h1>
  <p style="color:#6b7280;margin:0 0 32px">Application assistance for your RTO documentation. Select a service to see what's included.</p>

  <div id="rto-svc-all-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px">
    <?php
    // Group by the canonical category (see $serviceNameToCat built above),
    // in the canonical category order, and silently skip any DB row that
    // isn't part of today's real 7-category catalog — see the comment above
    // this view's opening <?php block for why.
    $grouped = [];
    foreach ($canonicalCatMap as $cat) { $grouped[$cat['title']] = []; }
    foreach ($services as $svc) {
        $canonicalTitle = $serviceNameToCat[$svc['name']] ?? null;
        if ($canonicalTitle === null) continue;
        $grouped[$canonicalTitle][] = $svc;
    }
    $grouped = array_filter($grouped);
    foreach ($grouped as $cat=>$svcs):
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05)">
      <h2 style="font-size:15px;font-weight:700;color:#1E3A5F;margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #e5e7eb"><?= esc_html($cat) ?></h2>
      <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px">
        <?php foreach ($svcs as $svc): ?>
        <li style="display:flex;justify-content:space-between;align-items:center">
          <a href="<?= esc_url(home_url('/rto-service/'.urlencode(strtolower(str_replace(' ','-',$svc['name']))))) ?>"
             style="color:#374151;text-decoration:none;font-size:14px;flex:1">
            <?= esc_html($svc['name']) ?>
          </a>
          <span style="font-size:12px;color:#059669;font-weight:600;white-space:nowrap;margin-left:8px">
            ₹<?= number_format((float)$svc['base_price']) ?>+
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="text-align:center;margin-top:40px">
    <a href="<?= esc_url(home_url('/rto-apply/')) ?>"
       style="display:inline-block;background:#1E3A5F;color:#fff;padding:16px 40px;border-radius:8px;font-size:16px;font-weight:600;text-decoration:none">
      Apply for Any Service →
    </a>
  </div>
<?php endif; ?>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('enqBtn')?.addEventListener('click',function(){
  var name=document.getElementById('enqName').value.trim();
  var phone=document.getElementById('enqPhone').value.trim();
  var msg=document.getElementById('enqMsg');
  if(!name||!phone){msg.style.color='#fca5a5';msg.textContent='Please fill both fields.';msg.style.display='block';return;}
  // Submit as a quick enquiry lead
  var fd=new FormData();
  fd.append('action','rto_public');fd.append('rto_area','public');fd.append('rto_action','quick_enquiry');
  fd.append('name',name);fd.append('phone',phone);
  fd.append('service_id','<?= esc_js($service['id']??'') ?>');
  fd.append('rto_nonce','<?= esc_js(wp_create_nonce('rto_public')) ?>'); <?php /* FIX P0-3: must match check_ajax_referer('rto_public', ...) in Router::dispatchPublicAjax() */ ?>
  fetch('<?= esc_js(admin_url('admin-ajax.php')) ?>',{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
      msg.style.color=r.success?'#86efac':'#fca5a5';
      msg.textContent=r.success?'✓ We will call you shortly!':'Please try again.';
      msg.style.display='block';
      if(r.success){document.getElementById('enqName').value='';document.getElementById('enqPhone').value='';}
    })
    .catch(function(){msg.style.color='#fca5a5';msg.textContent='Request failed.';msg.style.display='block';});
});
</script>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
