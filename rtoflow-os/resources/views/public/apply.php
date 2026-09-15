<?php
/**
 * RTOFLOW OS — Public Apply Form (v2)
 * Complete 111-field intake form from FluentForm JSON
 * All 7 service categories with full conditional logic
 */
if (!defined('ABSPATH')) exit;

global $wpdb;
$co  = get_option('rtoflow_company_name','RTOASSIST');
$tel = get_option('rtoflow_company_phone','');
$c   = [
    'primary'   => get_option('rtoflow_color_primary',   '#1B2A6B'),
    'secondary' => get_option('rtoflow_color_secondary', '#E97B28'),
    'accent'    => get_option('rtoflow_color_accent',    '#16A34A'),
    'bg_dark'   => get_option('rtoflow_color_bg_dark',   '#0A1628'),
];
require_once RTOFLOW_DIR . 'resources/views/public/apply-rto-data.php';
$banks = ['State Bank of India','Punjab National Bank','Bank of Baroda','Canara Bank','Union Bank of India','Bank of India','Indian Bank','Central Bank of India','UCO Bank','Indian Overseas Bank','Punjab & Sind Bank','HDFC Bank','ICICI Bank','Axis Bank','Kotak Mahindra Bank','Yes Bank','IndusInd Bank','IDFC FIRST Bank','Federal Bank','South Indian Bank','RBL Bank','Bandhan Bank','DCB Bank','Karnataka Bank','CitiBank','HSBC Bank','Standard Chartered Bank','AU Small Finance Bank','Ujjivan Small Finance Bank','Equitas Small Finance Bank','Jana Small Finance Bank','Tata Capital','Bajaj Finance','Mahindra Finance','HDB Financial Services','Cholamandalam Finance','Shriram Finance','Sundaram Finance','Muthoot Fincorp','Hero FinCorp','Maruti Suzuki Financial Services','Tata Motors Finance','Hyundai Finance','Toyota Financial Services'];
$states = array_keys($rtos_by_state);
sort($states);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Apply for RTO Service Online — <?=esc_html($co)?> | Application Assistance</title>
<meta name="description" content="Apply online for RC Transfer, Driving License, NOC, Hypothecation & 30+ RTO services. Expert application assistance across India; doorstep pickup/drop where available, additional charges may apply.">
<meta name="robots" content="index,follow">
<meta property="og:title" content="Apply for RTO Service — <?=esc_html($co)?>">
<meta property="og:type" content="website">
<link rel="canonical" href="<?=esc_url(get_permalink())?>">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Service","name":"RTO Services","provider":{"@type":"Organization","name":"<?=esc_js($co)?>"},"serviceType":"Vehicle Registration Services","areaServed":"IN"}</script>
<link rel="stylesheet" href="<?=esc_url(RTOFLOW_URL.'resources/assets/css/public.css')?>?v=<?=RTOFLOW_VERSION?>">
<style>
:root{--p:<?=esc_attr($c['primary'])?>;--s:<?=esc_attr($c['secondary'])?>;--a:<?=esc_attr($c['accent'])?>;--dk:<?=esc_attr($c['bg_dark'])?>}
*,::before,::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;min-height:100vh;color:#1e293b}
a{color:var(--p)}
/* Header */
.hdr{background:var(--dk);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.3)}
.hdr-logo{font-size:18px;font-weight:900;color:#fff;text-decoration:none;display:flex;align-items:center;gap:10px;min-width:0;flex-shrink:1}
.hdr-logo em{background:var(--s);border-radius:7px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-style:normal;font-size:18px;flex-shrink:0}
.hdr-nav{display:flex;align-items:center;gap:14px;flex-shrink:0}
.hdr-nav a{color:rgba(255,255,255,.75);text-decoration:none;font-size:13px;font-weight:500;white-space:nowrap}
.hdr-nav a.btn{background:var(--s);color:#fff;padding:7px 14px;border-radius:6px;font-weight:700;min-height:44px;display:inline-flex;align-items:center}
/* Hero */
.hero{background:linear-gradient(135deg,var(--dk) 0%,var(--p) 100%);color:#fff;padding:36px 20px;text-align:center}
.hero h1{font-size:clamp(20px,5vw,28px);font-weight:900;margin-bottom:8px;line-height:1.25}
.hero p{opacity:.85;font-size:14px;max-width:500px;margin:0 auto}
.breadcrumb{font-size:12px;color:rgba(255,255,255,.65);margin-bottom:10px}
.breadcrumb a{color:rgba(255,255,255,.65);text-decoration:none}
/* Main layout */
.main{max-width:820px;margin:0 auto;padding:24px 16px 48px}
/* Alerts */
.alert{padding:14px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:none;align-items:flex-start;gap:10px}
.alert.show{display:flex}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
/* Card */
.card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px}
.card-hdr{background:var(--p);color:#fff;padding:16px 22px;font-size:15px;font-weight:700;display:flex;align-items:center;gap:10px}
.card-body{padding:22px}
/* Steps */
.steps{display:flex;padding:16px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.step{flex:1;display:flex;align-items:center}
.step-n{width:26px;height:26px;border-radius:50%;border:2px solid #d1d5db;background:#fff;color:#94a3b8;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-l{font-size:10px;font-weight:600;color:#94a3b8;margin-left:5px;white-space:nowrap}
.step-line{flex:1;height:2px;background:#e2e8f0;margin:0 6px}
.step.active .step-n{background:var(--p);border-color:var(--p);color:#fff}
.step.active .step-l{color:var(--p);font-weight:700}
.step.done .step-n{background:var(--a);border-color:var(--a);color:#fff}
.step.done .step-line{background:var(--a)}
/* Service category buttons */
.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:16px}
.cat-btn{padding:13px 8px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;cursor:pointer;text-align:center;font-family:inherit;transition:all .15s}
.cat-btn:hover,.cat-btn.sel{border-color:var(--p);background:var(--p);color:#fff}
.cat-icon{font-size:24px;margin-bottom:5px}
.cat-lbl{font-size:11px;font-weight:700;line-height:1.3}
/* Form controls */
.fg{margin-bottom:14px}
.fg label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.fg label .req{color:#ef4444}
.inp,.sel,.ta{width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;color:#1e293b;transition:border-color .15s,box-shadow .15s;background:#fff}
.inp:focus,.sel:focus,.ta:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(27,42,107,.08)}
.inp[type="file"]{padding:7px 13px;background:#f8fafc;cursor:pointer}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
/* Conditional visibility */
.cond{display:none}
.cond.on{display:block}
.cond-g2.on{display:grid;grid-template-columns:1fr 1fr;gap:14px}
/* Section titles */
.sec-title{font-size:12px;font-weight:700;color:var(--p);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #eff6ff}
/* Checkboxes */
.chk-group{display:flex;flex-direction:column;gap:7px}
.chk-item{display:flex;align-items:center;gap:9px;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500}
.chk-item input[type="checkbox"]{width:17px;height:17px;accent-color:var(--p);cursor:pointer;flex-shrink:0}
.chk-item:hover{border-color:var(--p);background:#f8fafc}
/* Buttons */
.btn-primary{background:var(--s);color:#fff;border:none;padding:13px 22px;border-radius:9px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .15s}
.btn-primary:hover{opacity:.9}
.btn-primary:disabled{opacity:.65;cursor:not-allowed}
.btn-back{background:#6b7280;color:#fff;border:none;padding:13px 22px;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;transition:opacity .15s}
.btn-back:hover{opacity:.9}
.btn-row{display:flex;gap:12px;margin-top:20px}
.btn-row .btn-primary{flex:1}
/* Trust badges */
.trust{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;padding:16px;background:#fff;border-radius:12px;margin-top:16px}
.trust-item{display:flex;align-items:center;gap:5px;font-size:12px;color:#475569;font-weight:600}
/* Price estimate */
.price-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:13px 16px;margin:14px 0;display:none}
.price-box.on{display:block}
.price-box-label{font-size:11px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.price-box-amount{font-size:22px;font-weight:900;color:#1d4ed8}
/* FAQ */
.faq{margin-top:32px}
.faq h2{font-size:18px;font-weight:800;color:var(--p);margin-bottom:16px}
.faq-item{border-bottom:1px solid #e2e8f0;padding:12px 0}
.faq-q{font-size:14px;font-weight:700;color:var(--p);margin-bottom:4px}
.faq-a{font-size:13px;color:#64748b;line-height:1.6}
/* Footer */
footer{background:var(--dk);color:rgba(255,255,255,.65);text-align:center;padding:14px 20px;font-size:12px}
footer a{color:rgba(255,255,255,.65);text-decoration:none}
/* Responsive */
@media(max-width:560px){.g2,.g3,.cond-g2.on{grid-template-columns:1fr}.cats{grid-template-columns:repeat(auto-fill,minmax(90px,1fr))}.main{padding:14px 10px 36px}.step-l{display:none}}
@media(max-width:480px){.hdr{padding:12px 14px}.hdr-nav{gap:8px}.hdr-nav a:not(.btn){display:none}.hdr-logo{font-size:15px}.hero{padding:26px 14px}}
@media(max-width:400px){.hdr-logo em{width:28px;height:28px;font-size:15px}.hdr-nav a.btn{padding:7px 12px;font-size:13px}}
</style>
<?php wp_head(); ?>
<?php
// FIX (mobile pass — public site bottom nav): apply.php builds its own
// standalone <html>/<body> and never went through layouts/website-header.php,
// so it was missed by that shared-layout bottom-nav fix even though "Apply"
// is one of the site's core visitor actions.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-website">
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>
<header class="hdr">
  <a href="<?=esc_url(home_url('/'))?>" class="hdr-logo"><em>🔑</em><?=esc_html($co)?></a>
  <nav class="hdr-nav">
    <?php if($tel):?><a href="tel:<?=esc_attr($tel)?>">📞 <?=esc_html($tel)?></a><?php endif;?>
    <a href="<?=esc_url(home_url('/rto-login/'))?>">Login</a>
    <a href="<?=esc_url(home_url('/rto-track/'))?>" class="btn">Track →</a>
  </nav>
</header>

<section class="hero">
  <nav class="breadcrumb"><a href="<?=esc_url(home_url('/'))?>">Home</a> › Apply for RTO Service</nav>
  <h1>Apply for RTO Service Online</h1>
  <p>RC Transfer · Driving License · NOC · Hypothecation · 30+ services — expert application assistance across India. Doorstep pickup/drop where available (additional charges may apply); final approval rests with the RTO.</p>
</section>

<main class="main">
  <div class="alert alert-success" id="formSuccess">
    <span style="font-size:20px">✅</span>
    <div><strong>Application Submitted!</strong><div id="successMsg" style="font-weight:400;margin-top:3px"></div></div>
  </div>
  <div class="alert alert-error" id="formError"></div>

  <div class="card" id="formWrap">
    <!-- Step indicator -->
    <div class="steps" id="stepBar">
      <div class="step active" id="s1"><div class="step-n">1</div><div class="step-l">Service</div><div class="step-line"></div></div>
      <div class="step" id="s2"><div class="step-n">2</div><div class="step-l">Details</div><div class="step-line"></div></div>
      <div class="step" id="s3"><div class="step-n">3</div><div class="step-l">Location</div><div class="step-line"></div></div>
      <div class="step" id="s4"><div class="step-n">4</div><div class="step-l">Contact</div></div>
    </div>

    <form id="applyForm" novalidate>
      <?php wp_nonce_field('rto_public','rto_nonce'); ?>
      <input type="hidden" name="rto_action" value="submit_apply_v2">
      <input type="hidden" name="category" id="fCategory">
      <input type="hidden" name="sub_service" id="fSubService">
      <input type="hidden" name="draft_token" id="fDraftToken" value="">
      <div class="rto-draft-banner" id="draftBanner" style="display:none;background:#fff8e1;border:1px solid #f0d060;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:14px">
        We found a saved draft from your last visit (<span id="draftSavedAt"></span>).
        <button type="button" id="draftRestoreBtn" style="margin-left:8px">Restore</button>
        <button type="button" id="draftDiscardBtn" style="margin-left:4px">Discard</button>
      </div>

      <!-- ══════════════════════════════════════════ -->
      <!-- STEP 1: Select Service Category            -->
      <!-- ══════════════════════════════════════════ -->
      <div id="p1" class="card-body">
        <p class="sec-title">🗂 What service do you need?</p>
        <div class="cats" id="catsGroup" role="group" aria-label="Select service category">
          <?php
          // Part 4.15 FIX: a category whose every real service has been
          // deactivated from the admin panel (Part 4.14's "Deactivate
          // Category" button) is skipped here entirely — this is the actual
          // customer-facing effect of that admin action; before this fix,
          // deactivating rto_services rows changed nothing on this page at
          // all, since this list used to be unconditionally hard-coded.
          // $activeCategoryKeys is computed server-side in routeApply() from
          // the live, current rto_services.is_active state — never cached
          // client-side, so a category hidden by an admin 30 seconds ago is
          // already gone on the next page load (the shared 1-hour
          // rtofl_active_services transient is explicitly cleared by the
          // toggle action itself, so it doesn't even wait out that TTL).
          $activeCats = $activeCategoryKeys ?? ['dl','rc','hp','noc','vehicle','commercial','other'];
          foreach([['dl','🪪','Driving License'],['rc','🔄','RC Services'],['hp','🏦','Hypothecation (HP)'],['noc','📄','NOC'],['vehicle','🚗','Vehicle Services'],['commercial','🚛','Commercial Vehicle'],['other','🔧','Other Services']] as[$k,$icon,$lbl]):
            if (!in_array($k, $activeCats, true)) continue;
          ?>
          <button type="button" class="cat-btn" data-cat="<?=esc_attr($k)?>" aria-pressed="false">
            <div class="cat-icon"><?=$icon?></div>
            <div class="cat-lbl"><?=esc_html($lbl)?></div>
          </button>
          <?php endforeach;?>
          <?php if (empty($activeCats)): ?>
            <p style="color:#94a3b8;font-size:13px;padding:12px 0">No services are currently available for online booking. Please check back soon or contact us directly.</p>
          <?php endif; ?>
        </div>

        <?php
        // Known Limitations audit fix: "A single deactivated service is not
        // filtered out of its still-active category's sub-service dropdown
        // on the public form." These 4 simple-<select> categories' options
        // are now filtered against $serviceNameToId (built in
        // Router::routeApply() from the LIVE rto_services.is_active state,
        // never cached beyond the same 1-hour transient the category list
        // itself already respects) — a service deactivated from the admin
        // panel disappears from these lists on the next page load, the same
        // way an entire deactivated category already disappears from Step 1.
        // NOC and Commercial Vehicle remain the disclosed, NOT-yet-closed
        // exception (see Router::routeApply()'s own comment) — their pickers
        // are structured too differently from a plain <select> to filter
        // safely in this same pass.
        $dlOptions = [
            'Learning License' => 'Learning License', 'New Driving License' => 'New Driving License',
            'Duplicate Driving License' => 'Duplicate Driving License', 'Driving License Renewal' => 'Driving License Renewal',
            'Transport License' => 'Transport License', 'Smart Card Driving License' => 'Smart Card Driving License',
            'Change of Address in Driving License' => 'Change of Address in DL',
            'Renewal of Driving License After Expiry' => 'Renewal After Expiry',
            'Permanent License Service' => 'Permanent License Service',
            'Information Changes / Correction on DL' => 'Information Changes / Correction',
        ];
        $rcOptions = [
            'Transfer of Ownership' => 'Transfer of Ownership', 'Change of Address in RC' => 'Change of Address in RC',
            'Duplicate RC (Lost / Damaged / Stolen)' => 'Duplicate RC', 'RC Particulars / RC Extract' => 'RC Particulars / RC Extract',
            'RC Correction' => 'RC Correction', 'RC Cancellation' => 'RC Cancellation', 'RC Surrender' => 'RC Surrender',
        ];
        $hpOptions = [
            'Hypothecation Addition' => 'Hypothecation Addition',
            'Hypothecation Termination / Removal' => 'Hypothecation Termination / Removal (loan closed)',
            'Hypothecation Continuation' => 'Hypothecation Continuation (refinance/extend)',
            'RC Release after HP Removal' => 'RC Release after HP Removal',
            'Hypothecation Transfer' => 'Hypothecation Transfer',
        ];
        // Known Limitations audit fix (follow-up): the `noc_type` picker below
        // is structurally a plain <select> exactly like DL/RC/HP/Vehicle above
        // — it was only ever built with hardcoded <option> tags in markup
        // instead of a PHP array, which is why it missed the same_serviceNameToId
        // filter pass those 4 got. Its option *values* aren't the literal
        // rto_services.name rows (see NOC_REAL_NAME in the JS below, which maps
        // each value to the real service name) — the associative array here
        // carries that same value => [label, real service name] mapping so the
        // filter below can be checked against the real name, matching the
        // identical `isset($serviceNameToId[...]) continue;` pattern used for
        // every other category's picker.
        $nocOptions = [
            'Inter-state NOC'        => ['Inter-state NOC', 'Inter-state NOC'],
            'Within-state transfer'  => ['Within-state transfer', 'Within-state Transfer NOC'],
            'Hypothecation removal'  => ['Hypothecation removal NOC', 'Hypothecation Removal NOC'],
            'Export / Out-of-country'=> ['Export / Out of country', 'Export / Out-of-Country NOC'],
        ];
        ?>
        <!-- DL sub-service selector -->
        <div class="cond fg" id="sub_dl">
          <label for="dl_service">Which Driving License service do you need? <span class="req">*</span></label>
          <select name="dl_service" id="dl_service" class="sel">
            <option value="">— Select —</option>
            <?php foreach ($dlOptions as $val => $label): if (!isset($serviceNameToId[$val])) continue; ?>
            <option value="<?=esc_attr($val)?>"><?=esc_html($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- RC sub-service selector -->
        <div class="cond fg" id="sub_rc">
          <label for="rc_service">Which RC service do you need? <span class="req">*</span></label>
          <select name="rc_service" id="rc_service" class="sel">
            <option value="">— Select —</option>
            <?php foreach ($rcOptions as $val => $label): if (!isset($serviceNameToId[$val])) continue; ?>
            <option value="<?=esc_attr($val)?>"><?=esc_html($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- HP sub-service selector -->
        <div class="cond fg" id="sub_hp">
          <label for="hp_service">What do you need for Hypothecation? <span class="req">*</span></label>
          <select name="hp_service" id="hp_service" class="sel">
            <option value="">— Select —</option>
            <?php foreach ($hpOptions as $val => $label): if (!isset($serviceNameToId[$val])) continue; ?>
            <option value="<?=esc_attr($val)?>"><?=esc_html($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php
        $vehicleOptions = [
            'New Vehicle Registration' => 'New Vehicle Registration', 'RC Ownership Transfer' => 'RC Ownership Transfer',
            'RC Renewal (After 15 Years)' => 'RC Renewal (After 15 Years)', 'Duplicate RC' => 'Duplicate RC',
            'Address Change / Correction in RC' => 'Address Change / Correction in RC',
            'Re-Registration (10–15 Years Old)' => 'Re-Registration (10–15 Years Old)',
            'Re-Assignment' => 'Re-Assignment', 'Fitness Certificate' => 'Fitness Certificate',
            'Fitness Certificate Renewal' => 'Fitness Certificate Renewal',
            'Duplicate Fitness Certificate' => 'Duplicate Fitness Certificate',
            'No Objection Certificate (NOC)' => 'No Objection Certificate (NOC)', 'Road Tax Payment' => 'Road Tax Payment',
            'Road Tax Refund' => 'Road Tax Refund', 'Permit Services / National Permit' => 'Permit Services / National Permit',
            'Vehicle Insurance' => 'Vehicle Insurance', 'Alteration / Conversion of Vehicle' => 'Alteration / Conversion of Vehicle',
        ];
        ?>
        <!-- Vehicle sub-service selector -->
        <div class="cond fg" id="sub_vehicle">
          <label for="vehicle_service">Which Vehicle service do you need? <span class="req">*</span></label>
          <select name="vehicle_service" id="vehicle_service" class="sel">
            <option value="">— Select —</option>
            <?php foreach ($vehicleOptions as $val => $label): if (!isset($serviceNameToId[$val])) continue; ?>
            <option value="<?=esc_attr($val)?>"><?=esc_html($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- NOC info box -->
        <div class="cond" id="sub_noc_info" style="background:#eff6ff;border-radius:10px;padding:13px 16px;font-size:13px;color:#1d4ed8;margin-bottom:14px">
          <strong>📄 NOC — No Objection Certificate</strong><br>Please continue to fill in your vehicle details and NOC type on the next step.
        </div>

        <!-- Commercial info box -->
        <div class="cond" id="sub_commercial_info" style="background:#fef9c3;border-radius:10px;padding:13px 16px;font-size:13px;color:#92400e;margin-bottom:14px">
          <strong>🚛 Commercial Vehicle Services</strong><br>Our team will contact you to discuss specific permits, fitness certificates, and other requirements.
        </div>

        <!-- Other services -->
        <div class="cond fg" id="sub_other">
          <label for="other_description">Describe what you need</label>
          <textarea name="other_description" id="other_description" class="ta" rows="3" placeholder="Describe the RTO service you require…"></textarea>
        </div>

        <div style="margin-top:18px">
          <button type="button" class="btn-primary" style="max-width:260px" id="s1Btn">
            Continue to Details →
          </button>
        </div>
      </div>

      <!-- ══════════════════════════════════════════ -->
      <!-- STEP 2: Service-Specific Fields            -->
      <!-- ══════════════════════════════════════════ -->
      <div id="p2" class="card-body" style="display:none">
        <p class="sec-title" id="p2Title">📋 Service Details</p>

        <!-- ┌── DL: Learning License ──┐ -->
        <div class="cond" id="dl_ll">
          <div class="g2">
            <div class="fg"><label for="ll_first_time">First Time or Reissue?</label>
              <select name="ll_first_time" id="ll_first_time" class="sel"><option value="Yes">First Time</option><option value="No">Reissue</option></select>
            </div>
            <div class="fg"><label>Are you above 18 years? <span class="req">*</span></label>
              <select name="ll_age18" class="sel" required><option value="">— Select —</option><option value="Yes">Yes</option><option value="No">No (light vehicles only)</option></select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Upload Proof of Age <span class="req">*</span></label><input type="file" name="proof_age" class="inp" accept=".pdf,.jpg,.jpeg,.png"><div style="font-size:11px;color:#94a3b8;margin-top:3px">Aadhaar, Birth Cert., Class 10 Marksheet</div></div>
            <div class="fg"><label>Upload Proof of Address <span class="req">*</span></label><input type="file" name="proof_addr" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── DL: New DL ──┐ -->
        <div class="cond" id="dl_new">
          <div class="g2">
            <div class="fg"><label>Do you have a valid Learning License? <span class="req">*</span></label>
              <select name="has_ll" class="sel" required><option value="">— Select —</option><option value="Yes">Yes</option><option value="No">No (need LL first)</option></select>
            </div>
            <div class="fg"><label>Are you above 18 years? <span class="req">*</span></label>
              <select name="new_dl_age18" class="sel" required><option value="">— Select —</option><option value="Yes">Yes</option><option value="No">No</option></select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Upload Proof of Age</label><input type="file" name="proof_age_new" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="fg"><label>Upload Proof of Address</label><input type="file" name="proof_addr_new" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── DL: Duplicate ──┐ -->
        <div class="cond" id="dl_dup">
          <div class="fg"><label>Reason for Duplicate DL <span class="req">*</span></label>
            <select name="dup_dl_reason" id="dup_dl_reason" class="sel" required>
              <option value="">— Select —</option><option value="Lost">Lost</option><option value="Stolen">Stolen (FIR required)</option><option value="Damaged">Damaged</option>
            </select>
          </div>
          <div class="cond fg" id="dup_fir"><label>Upload FIR Copy <span class="req">*</span></label><input type="file" name="fir_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          <div class="cond fg" id="dup_damaged"><label>Upload Photo of Damaged DL <span class="req">*</span></label><input type="file" name="damaged_dl_photo" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>

        <!-- ┌── DL: Renewal ──┐ -->
        <div class="cond" id="dl_renewal">
          <div class="g2">
            <div class="fg"><label>DL Number <span class="req">*</span></label><input type="text" name="dl_number" class="inp" placeholder="e.g. DL-0420110012345" required></div>
            <div class="fg"><label>Date of Expiry <span class="req">*</span></label><input type="date" name="dl_expiry" class="inp" required></div>
          </div>
          <div class="g2">
            <div class="fg"><label for="renewal_reason">Reason for Renewal</label>
              <select name="renewal_reason" id="renewal_reason" class="sel"><option value="Regular Renewal">Regular Renewal</option><option value="Expired">Expired</option><option value="Address Change">Address Change</option></select>
            </div>
            <div class="fg"><label>Upload Current DL Copy</label><input type="file" name="current_dl" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── DL: Transport ──┐ -->
        <div class="cond" id="dl_transport">
          <div class="fg"><label for="transport_type">New or Renewal?</label>
              <select name="transport_type" id="transport_type" class="sel"><option value="New Transport License">New Transport License</option><option value="Renew Transport License">Renew Existing</option></select>
          </div>
          <div class="g2">
            <div class="fg"><label>Business Registration Document</label><input type="file" name="biz_reg_doc" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="fg"><label>Proof of Vehicle Ownership</label><input type="file" name="veh_ownership_proof" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── DL: Smart Card ──┐ -->
        <div class="cond" id="dl_smartcard">
          <div class="g2">
            <div class="fg"><label for="has_existing_dl">Do you have an existing DL?</label>
              <select name="has_existing_dl" id="has_existing_dl" class="sel">
                <option value="">— Select —</option><option value="Yes">Yes</option><option value="No">No</option>
              </select>
            </div>
            <div class="fg"><label>Do you want to change address? <span class="req">*</span></label>
              <select name="sc_change_addr" class="sel" required><option value="">— Select —</option><option value="Yes">Yes</option><option value="No">No</option></select>
            </div>
          </div>
          <div class="cond" id="sc_dl_num">
            <div class="g2">
              <div class="fg"><label>DL Number</label><input type="text" name="sc_dl_number" class="inp" placeholder="DL-XXXXXXXXXXXX"></div>
              <div class="fg"><label>Upload Current DL Copy</label><input type="file" name="sc_dl_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
            </div>
          </div>
        </div>

        <!-- ┌── DL: Change of Address ──┐ -->
        <div class="cond" id="dl_addr">
          <div class="fg"><label>New Address <span class="req">*</span></label><textarea name="dl_new_address" class="ta" rows="2" placeholder="Complete new address…" required></textarea></div>
          <div class="g2">
            <div class="fg"><label>Upload Current DL Copy</label><input type="file" name="dl_copy_addr" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="fg"><label>Upload Proof of New Address <span class="req">*</span></label><input type="file" name="addr_proof_dl" class="inp" accept=".pdf,.jpg,.jpeg,.png" required></div>
          </div>
        </div>

        <!-- ┌── DL: Renewal After Expiry ──┐ -->
        <div class="cond" id="dl_expiry_renew">
          <div class="g2">
            <div class="fg"><label>Expired DL Number <span class="req">*</span></label><input type="text" name="expired_dl_no" class="inp" placeholder="Expired DL number" required></div>
            <div class="fg"><label>Date of Expiry <span class="req">*</span></label><input type="date" name="expired_dl_date" class="inp" required></div>
          </div>
          <div class="g2">
            <div class="fg"><label for="expiry_period">Reason</label>
              <select name="expiry_period" id="expiry_period" class="sel"><option value="Under 1 Year Expired">Under 1 Year Expired</option><option value="Over 1 Year Expired">Over 1 Year Expired</option></select>
            </div>
            <div class="fg"><label>Upload Expired DL Copy</label><input type="file" name="expired_dl_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── DL: Permanent License ──┐ -->
        <div class="cond" id="dl_permanent">
          <div class="g2">
            <div class="fg"><label>Learning License Number <span class="req">*</span></label><input type="text" name="ll_no_perm" class="inp" placeholder="LL number" required></div>
            <div class="fg"><label>LL Issue Date <span class="req">*</span></label><input type="date" name="ll_issue_date" class="inp" required></div>
          </div>
          <div class="fg"><label>Upload LL Copy</label><input type="file" name="ll_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>

        <!-- ┌── DL: Information Changes ──┐ -->
        <div class="cond" id="dl_correction">
          <div class="fg"><label>What information to change? <span class="req">*</span></label>
            <select name="dl_change_type" id="dl_change_type" class="sel" required>
              <option value="">— Select —</option><option value="Name">Name Correction</option><option value="Address">Address Change</option><option value="Date of Birth">Date of Birth Correction</option><option value="Father Name">Father Name Correction</option>
            </select>
          </div>
          <div class="cond fg" id="dl_chg_addr_field"><label>New Address</label><textarea name="dl_corr_addr" class="ta" rows="2" placeholder="Complete new address…"></textarea></div>
          <div class="cond fg" id="dl_chg_name_proof"><label>Upload Name Change Proof</label><input type="file" name="name_change_proof" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          <div class="cond fg" id="dl_chg_dob_proof"><label>Upload Date of Birth Proof</label><input type="file" name="dob_proof" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>

        <!-- ── RC FIELDS ── -->
        <!-- ┌── RC: Transfer of Ownership ──┐ -->
        <div class="cond" id="rc_transfer">
          <div class="g2">
            <div class="fg"><label>Are you the Buyer or Seller? <span class="req">*</span></label>
              <select name="transfer_role" class="sel" required><option value="">— Select —</option><option value="Buyer">Buyer</option><option value="Seller">Seller</option></select>
            </div>
            <div class="fg"><label for="family_transfer">Family Transfer?</label>
              <select name="family_transfer" id="family_transfer" class="sel"><option value="No">No</option><option value="Yes">Yes (lower fee)</option></select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Vehicle Registration No. <span class="req">*</span></label><input type="text" name="veh_reg_transfer" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC) <span class="req">*</span></label><input type="text" name="owner_name_transfer" class="inp" placeholder="Name on RC" required></div>
          </div>
          <div class="g2">
            <div class="fg"><label>Seller City <span class="req">*</span></label><input type="text" name="seller_city" class="inp" placeholder="Seller's city" required></div>
            <div class="fg"><label>Buyer City <span class="req">*</span></label><input type="text" name="buyer_city" class="inp" placeholder="Buyer's city" required></div>
          </div>
          <div class="fg"><label>Upload RC Copy <span class="req">*</span></label><input type="file" name="rc_copy_transfer" class="inp" accept=".pdf,.jpg,.jpeg,.png" required></div>
        </div>

        <!-- ┌── RC: Address Change ──┐ -->
        <div class="cond" id="rc_addr">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rcaddr" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC) <span class="req">*</span></label><input type="text" name="owner_name_rcaddr" class="inp" placeholder="Name on RC" required></div>
          </div>
          <div class="g2">
            <div class="fg"><label for="rc_addr_type">Type of Address Change</label>
              <select name="rc_addr_type" id="rc_addr_type" class="sel"><option value="Within same state">Within same state</option><option value="New state">New state (inter-state)</option></select>
            </div>
            <div class="fg"><label for="rc_addr_reason">Reason for Change</label>
              <select name="rc_addr_reason" id="rc_addr_reason" class="sel"><option value="Shifted Residence">Shifted Residence</option><option value="Correction">Address Correction</option></select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label for="rc_rto_type">RTO Type</label>
              <select name="rc_rto_type" id="rc_rto_type" class="sel"><option value="Within same RTO office">Within same RTO office</option><option value="New RTO office">New RTO office</option></select>
            </div>
            <div class="fg"><label>Upload RC Copy</label><input type="file" name="rc_copy_rcaddr" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
        </div>

        <!-- ┌── RC: Duplicate RC ──┐ -->
        <div class="cond" id="rc_dup">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rcdup" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC) <span class="req">*</span></label><input type="text" name="owner_name_rcdup" class="inp" placeholder="Name on RC" required></div>
          </div>
          <div class="fg"><label>Reason <span class="req">*</span></label>
            <select name="rc_dup_reason" class="sel" required><option value="">— Select —</option><option value="Lost">Lost</option><option value="Damaged">Damaged</option><option value="Stolen">Stolen (FIR required)</option></select>
          </div>
          <div class="fg"><label>Upload RC Copy (if available)</label><input type="file" name="rc_copy_rcdup" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>

        <!-- ┌── RC: Particulars / Extract ──┐ -->
        <div class="cond" id="rc_particulars">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rcpart" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label for="rc_extract_purpose">Purpose of Extract</label>
              <select name="rc_extract_purpose" id="rc_extract_purpose" class="sel"><option value="Ownership Check">Ownership Check</option><option value="Legal Case">Legal Case</option><option value="Buyer Verification">Buyer Verification</option></select>
            </div>
          </div>
        </div>

        <!-- ┌── RC: Correction ──┐ -->
        <div class="cond" id="rc_correction">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rccorr" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC) <span class="req">*</span></label><input type="text" name="owner_name_rccorr" class="inp" placeholder="Name on RC" required></div>
          </div>
          <div class="fg"><label>Type of Correction <span class="req">*</span></label>
            <select name="rc_corr_type" class="sel" required>
              <option value="">— Select —</option><option value="Name Correction">Name Correction</option><option value="Engine No Correction">Engine Number Correction</option><option value="Chassis No">Chassis Number Correction</option><option value="Other Info">Other Information</option>
            </select>
          </div>
          <div class="fg"><label>Upload RC Copy</label><input type="file" name="rc_copy_rccorr" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div>

        <!-- ┌── RC: Cancellation ──┐ -->
        <div class="cond" id="rc_cancel">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rccancel" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Reason <span class="req">*</span></label>
              <select name="rc_cancel_reason" class="sel" required><option value="">— Select —</option><option value="Vehicle Written Off">Vehicle Written Off</option><option value="Destroyed">Destroyed</option><option value="Total Loss">Total Loss</option></select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Date of Incident (approx.)</label><input type="date" name="incident_date" class="inp"></div>
            <div class="fg"><label for="insurance_claim">Insurance Claim / Settlement?</label>
              <select name="insurance_claim" id="insurance_claim" class="sel"><option value="No">No</option><option value="Yes">Yes, claim in progress / settled</option></select>
            </div>
          </div>
        </div>

        <!-- ┌── RC: Surrender ──┐ -->
        <div class="cond" id="rc_surrender">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_rcsurr" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label for="surrender_reason">Reason for Surrender</label>
              <select name="surrender_reason" id="surrender_reason" class="sel"><option value="Scrapped">Scrapped / Condemned</option><option value="Exported">Exported</option><option value="Vehicle Not in Use">Not in Use</option></select>
            </div>
          </div>
        </div>

        <!-- ── HP FIELDS ── -->
        <!-- ┌── HP: Termination / Removal ──┐ -->
        <div class="cond" id="hp_termination">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_hpterm" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Loan Closed (Month/Year) <span class="req">*</span></label><input type="month" name="loan_closed" class="inp" required></div>
          </div>
          <div class="fg"><label>Financer / Bank Name <span class="req">*</span></label>
            <select name="financer_name" class="sel" required>
              <option value="">— Select Bank / NBFC —</option>
              <?php foreach($banks as $b):?><option value="<?=esc_attr($b)?>"><?=esc_html($b)?></option><?php endforeach;?>
            </select>
          </div>
        </div>

        <!-- ┌── HP: Continuation ──┐ -->
        <div class="cond" id="hp_continuation">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_hpcont" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Reason <span class="req">*</span></label>
              <select name="hp_cont_reason" id="hp_cont_reason" class="sel" required>
                <option value="">— Select —</option><option value="Extension">Extension (same bank)</option><option value="Refinance">Refinance (new bank)</option><option value="Bank Change">Bank Change</option>
              </select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Current Bank Name <span class="req">*</span></label><input type="text" name="current_bank" class="inp" placeholder="Current financer" required></div>
            <div class="cond fg" id="hp_new_bank"><label>New Bank Name (Refinance) <span class="req">*</span></label><input type="text" name="new_bank" class="inp" placeholder="New bank / NBFC name"></div>
          </div>
        </div>

        <!-- ┌── HP: Other (Addition / Transfer / RC Release) ──┐ -->
        <div class="cond" id="hp_other">
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_hpother" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC)</label><input type="text" name="owner_name_hp" class="inp" placeholder="As on RC"></div>
          </div>
        </div>

        <!-- ── NOC FIELDS ── -->
        <div class="cond" id="noc_fields">
          <div class="g2">
            <div class="fg"><label>Vehicle Type <span class="req">*</span></label>
              <select name="veh_type_noc" class="sel" required><option value="">— Select —</option><option value="Two-wheeler">Two-Wheeler</option><option value="Four-wheeler">Four-Wheeler</option><option value="Commercial">Commercial</option></select>
            </div>
            <div class="fg"><label for="noc_type">Type of NOC Required</label>
              <select name="noc_type" id="noc_type" class="sel">
                <option value="">— Select —</option>
                <?php foreach ($nocOptions as $val => $meta): [$label, $realName] = $meta; if (!isset($serviceNameToId[$realName])) continue; ?>
                <option value="<?=esc_attr($val)?>"><?=esc_html($label)?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="g2">
            <div class="fg"><label>Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_noc" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
            <div class="fg"><label>Owner Name (As per RC) <span class="req">*</span></label><input type="text" name="owner_name_noc" class="inp" placeholder="Name on RC" required></div>
          </div>
          <div class="cond fg" id="noc_docs_section">
            <label>Select Available Documents <span class="req">*</span></label>
            <div class="chk-group">
              <?php foreach(['RC','Insurance','PUC','Aadhaar / ID Proof','Bank NOC + Form 35 (if loan active)'] as $d):?>
              <label class="chk-item"><input type="checkbox" name="noc_docs[]" value="<?=esc_attr($d)?>"> <?=esc_html($d)?></label>
              <?php endforeach;?>
            </div>
          </div>
        </div>

        <!-- ── VEHICLE SERVICES FIELDS ── -->
        <div class="cond" id="vehicle_fields">
          <div class="g2">
            <div class="fg"><label>Vehicle Type <span class="req">*</span></label>
              <select name="veh_type_main" class="sel" required><option value="">— Select —</option><option value="Two-wheeler">Two-Wheeler</option><option value="Four-wheeler">Four-Wheeler</option><option value="Commercial">Commercial</option></select>
            </div>
            <div class="fg"><label id="veh_reg_lbl">Vehicle Reg. No. <span class="req">*</span></label><input type="text" name="veh_reg_main" class="inp" placeholder="e.g. DL01AB1234" style="text-transform:uppercase" required></div>
          </div>
          <div class="g2" id="veh_owner_row">
            <div class="fg"><label>Owner Name (As per RC)</label><input type="text" name="owner_name_veh" class="inp" placeholder="As on RC"></div>
            <div class="fg"><label>Upload RC Copy</label><input type="file" name="rc_copy_veh" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
          </div>
          <!-- Fitness Certificate files -->
          <div class="cond" id="fitness_files">
            <div class="g2">
              <div class="fg"><label>Old Fitness Certificate Copy</label><input type="file" name="old_fitness_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png"></div>
              <div class="fg"><label>Vehicle Photos (Front + Back) <span class="req">*</span></label><input type="file" name="vehicle_photos" class="inp" accept=".jpg,.jpeg,.png" required></div>
            </div>
          </div>
          <!-- New Vehicle Registration files -->
          <div class="cond fg" id="invoice_file">
            <label>Invoice Copy <span class="req">*</span></label>
            <input type="file" name="invoice_copy" class="inp" accept=".pdf,.jpg,.jpeg,.png" required>
          </div>
        </div>

        <!-- ── COMMERCIAL VEHICLE FIELDS ── -->
        <div class="cond" id="commercial_fields">
          <div class="g2">
            <div class="fg"><label for="commercial_veh_type">Vehicle Type</label>
              <select name="commercial_veh_type" id="commercial_veh_type" class="sel">
                <option value="">— Select —</option>
                <option value="Truck">Truck / HGV</option>
                <option value="Bus">Bus / Passenger</option>
                <option value="Tempo">Tempo / LCV</option>
                <option value="Auto">Auto Rickshaw</option>
                <option value="Taxi">Taxi / Cab</option>
                <option value="Other">Other Commercial</option>
              </select>
            </div>
            <div class="fg"><label>Vehicle Registration No.</label>
              <input type="text" name="commercial_veh_reg" class="inp" placeholder="e.g. DL01G1234" style="text-transform:uppercase">
            </div>
          </div>
          <div class="fg"><label>Describe the service needed <span class="req">*</span></label>
            <textarea name="commercial_description" class="ta" rows="3" placeholder="E.g. National Permit renewal, Fitness Certificate, Route Permit, etc." required></textarea>
          </div>
        </div>

        <!-- ── OTHER SERVICES ── -->
        <div class="cond" id="other_fields_step2">
          <div class="fg"><label>Describe what you need <span class="req">*</span></label>
            <textarea name="other_description" class="ta" rows="3" placeholder="Describe the RTO service you require…"></textarea>
          </div>
        </div>

        <!-- ── FORM BUILDER v2: admin-managed fields for a specific
             sub-service render here, replacing the static block above for
             that ONE service only — see renderDynamicStep2() below. Every
             other sub-service is completely unaffected. ── -->
        <div class="cond" id="dynamic_step2_container"></div>

        <div class="btn-row">
          <button type="button" class="btn-back" id="s2BackBtn">← Back</button>
          <button type="button" class="btn-primary" id="s2Btn">Continue to Location →</button>
        </div>
      </div>

      <!-- ══════════════════════════════════════════ -->
      <!-- STEP 3: State / RTO Location               -->
      <!-- ══════════════════════════════════════════ -->
      <div id="p3" class="card-body" style="display:none">
        <p class="sec-title">📍 Vehicle / Service Location</p>
        <div class="g2">
          <div class="fg">
            <label>State where Vehicle is Registered <span class="req">*</span></label>
            <select name="rto_state" id="rto_state" class="sel" required>
              <option value="">— Select State / UT —</option>
              <?php foreach($states as $st):?><option value="<?=esc_attr($st)?>"><?=esc_html($st)?></option><?php endforeach;?>
            </select>
          </div>
          <div class="fg">
            <label>RTO Office <span class="req">*</span></label>
            <select name="rto_office" id="rto_office" class="sel" required disabled>
              <option value="">— Select State First —</option>
            </select>
          </div>
        </div>
        <!-- Destination state (NOC / DL addr change / inter-state) -->
        <div class="cond" id="dest_state_row">
          <div class="g2">
            <div class="fg">
              <label for="destination_state">Destination State (where vehicle/owner is moving)</label>
              <select name="destination_state" id="destination_state" class="sel">
                <option value="">— Select Destination State —</option>
                <?php foreach($states as $st):?><option value="<?=esc_attr($st)?>"><?=esc_html($st)?></option><?php endforeach;?>
              </select>
            </div>
            <div class="fg">
              <label for="destination_rto">Destination RTO Office</label>
              <select name="destination_rto" id="destination_rto" class="sel" disabled>
                <option value="">— Select Destination State First —</option>
              </select>
            </div>
          </div>
        </div>
        <div class="btn-row">
          <button type="button" class="btn-back" id="s3BackBtn">← Back</button>
          <button type="button" class="btn-primary" id="s3Btn">Continue to Contact →</button>
        </div>
      </div>

      <!-- ══════════════════════════════════════════ -->
      <!-- STEP 4: Personal Info + Submit             -->
      <!-- ══════════════════════════════════════════ -->
      <div id="p4" class="card-body" style="display:none">
        <p class="sec-title">👤 Your Contact Information</p>
        <div class="g2">
          <div class="fg"><label>First Name <span class="req">*</span></label><input type="text" name="first_name" class="inp" placeholder="First name" required autocomplete="given-name"></div>
          <div class="fg"><label>Last Name <span class="req">*</span></label><input type="text" name="last_name" class="inp" placeholder="Last name" required autocomplete="family-name"></div>
        </div>
        <div class="g2">
          <div class="fg"><label>Mobile Number <span class="req">*</span></label><input type="tel" name="mobile" class="inp" placeholder="10-digit mobile" maxlength="10" required autocomplete="tel-national" pattern="[6-9][0-9]{9}" inputmode="numeric"></div>
          <div class="fg"><label>Email Address</label><input type="email" name="email" class="inp" placeholder="your@email.com" autocomplete="email"></div>
        </div>
        <div class="fg"><label>Address Line 1</label><input type="text" name="address_line1" class="inp" placeholder="House No., Street" autocomplete="address-line1"></div>
        <div class="g2">
          <div class="fg"><label>City</label><input type="text" name="city" class="inp" placeholder="Your city" autocomplete="address-level2"></div>
          <div class="fg"><label>Pincode</label><input type="text" name="pincode" class="inp" placeholder="6-digit pincode" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"></div>
        </div>

        <div class="fg" style="margin-top:14px">
          <label class="chk-item" style="background:#f8fafc">
            <input type="checkbox" name="consent" value="1" id="consentChk" required>
            I authorize <?=esc_html($co)?> to contact me about this service request. I agree to the <a href="<?=esc_url(home_url('/terms-conditions'))?>" target="_blank">Terms of Service</a> and <a href="<?=esc_url(home_url('/privacy-policy'))?>" target="_blank">Privacy Policy</a>. <span class="req">*</span>
          </label>
        </div>

        <div class="price-box" id="priceBox">
          <div class="price-box-label">💰 Estimated Service Fee</div>
          <div class="price-box-amount" id="priceAmt"></div>
          <div style="font-size:11px;color:#64748b;margin-top:3px">+ Government fees (to be communicated by our agent)</div>
        </div>

        <div class="btn-row">
          <button type="button" class="btn-back" id="s4BackBtn">← Back</button>
          <button type="submit" class="btn-primary" id="submitBtn">
            <span id="submitTxt">Submit Application →</span>
          </button>
        </div>
        <p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:10px">🔒 Your data is encrypted. No advance payment required to submit.</p>
      </div>
    </form>
  </div>

  <div class="trust">
    <div class="trust-item">✅ Free Consultation</div>
    <div class="trust-item">🔒 Secure Document Handling</div>
    <div class="trust-item">📞 Doorstep Pickup Where Available*</div>
    <div class="trust-item">🇮🇳 Pan India Coverage</div>
    <div class="trust-item">💰 No Hidden Charges</div>
    <div class="trust-item">↩ Service-Fee Refund Policy*</div>
  </div>
  <p style="font-size:12px;color:#94a3b8;max-width:760px;margin:8px auto 0;text-align:center">
    *Doorstep pickup/drop depends on courier/agent availability in your city and may involve an additional charge on top of
    the service fee. Where unavailable, you may need to submit or collect documents yourself. Refunds apply to our own
    service fee where we fail to deliver the assistance we committed to — we do not control, and cannot guarantee, the
    RTO's decision, appointment allocation, or processing time.
  </p>

  <section class="faq">
    <h2>Frequently Asked Questions</h2>
    <?php foreach([
      ['How long does RC Transfer take?','RC Transfer typically takes 15–30 working days depending on the RTO\'s own processing time. We help you prepare a complete, error-free application to avoid delays on our side, but the timeline and final approval are decided by the RTO.'],
      ['Do I need to visit the RTO office?','It depends on your city and the service. Where a local agent or courier partner is available, we can arrange document pickup from your home/office and delivery of the processed document for an additional charge. Where this isn\'t available for your location, you\'ll need to submit or collect documents yourself — we\'ll guide you through it either way.'],
      ['What documents are required for Hypothecation removal?','You need: RC Book, Form 35 from the bank, Bank NOC letter, and valid insurance. Our agent will share a complete checklist after reviewing your application.'],
      ['How do I track my application status?','After submission, you receive a unique application number and can track it anytime on our website or call our helpline. You also receive SMS/WhatsApp updates. Status shown reflects what the RTO has updated on their end, which we cannot speed up.'],
      ['Is my document safe with you?','Where we physically handle your documents (pickup/delivery), our agent takes reasonable care and confirms receipt with you directly. This does not apply when you submit/collect documents yourself. Contact our helpline immediately if you have any concern about a specific handover.'],
    ] as[$q,$a]):?>
    <div class="faq-item"><div class="faq-q"><?=esc_html($q)?></div><div class="faq-a"><?=esc_html($a)?></div></div>
    <?php endforeach;?>
  </section>
</main>

<footer>
  © <?=date('Y')?> <?=esc_html($co)?> &nbsp;·&nbsp;
  <a href="<?=esc_url(home_url('/privacy-policy'))?>">Privacy</a> &nbsp;·&nbsp;
  <a href="<?=esc_url(home_url('/terms-conditions'))?>">Terms</a>
  <?php if($tel):?>&nbsp;·&nbsp;<a href="tel:<?=esc_attr($tel)?>" style="color:#fff;font-weight:700">📞 <?=esc_html($tel)?></a><?php endif;?>
</footer>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var AJAX=<?=json_encode(admin_url('admin-ajax.php'))?>,NONCE=<?=json_encode(wp_create_nonce('rto_public'))?>;
var RTO=<?=wp_json_encode($rtos_by_state)?>;
var cur=1,cat='',svc='';

// ── Funnel event beacons (closes checklist gap: "Analytics for dynamic form
//    submissions did not exist" — abandonment/drop-off/validation-failure
//    rates were previously impossible to compute; see FormFunnelService and
//    Router::recordFormFunnelEvent()) ─────────────────────────────────────
// DISCLOSED LIMITATION: this JS has not been exercised in a live browser in
// this sandbox (no browser tool available here) — same standing limitation
// already logged for every other piece of dynamic-form JS in this codebase.
// It is deliberately fire-and-forget: a beacon failure must never block or
// visibly affect the real visitor's ability to fill out and submit the form.
var RTO_FUNNEL = {
  sessionToken: null,
  init: function(){
    try {
      var stored = window.localStorage ? localStorage.getItem('rto_funnel_session') : null;
      if (stored && /^[a-f0-9]{16,64}$/.test(stored)) { this.sessionToken = stored; return; }
    } catch(e) {}
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    this.sessionToken = Array.prototype.map.call(bytes, function(b){ return ('0'+b.toString(16)).slice(-2); }).join('');
    try { if (window.localStorage) localStorage.setItem('rto_funnel_session', this.sessionToken); } catch(e) {}
  },
  send: function(eventType, stepIndex, fieldKey){
    if (!this.sessionToken) this.init();
    try {
      var body = new URLSearchParams({
        action: 'rto_public', rto_area: 'public', rto_action: 'form_funnel_event',
        event_type: eventType, category: cat || '', service_name: svc || '',
        step_index: String(stepIndex||0), field_key: fieldKey || '',
        session_token: this.sessionToken
      });
      if (navigator.sendBeacon) {
        navigator.sendBeacon(AJAX, body);
      } else {
        fetch(AJAX, {method:'POST', credentials:'same-origin', body: body, keepalive: true}).catch(function(){});
      }
    } catch(e) { /* never let a beacon failure affect the real form */ }
  }
};
RTO_FUNNEL.init();
RTO_FUNNEL.send('step_reached', 0); // page load / step 1 (category+service picker)

// ── Form Builder v2: real integration into THIS existing form ──────────────
// Part 4.10 re-architecture: the Form Builder's unit is a CATEGORY (dl/rc/hp/
// noc/vehicle/commercial/other — 7 forms), not a service (44 forms). Each
// category schema carries every field for every service in that category,
// each gated by a visible_if condition on a `selected_service` picker field.
// CATEGORY_SCHEMAS holds the full decorated schema per category (empty
// object for any category with no active v2 schema yet). SERVICE_IDS is kept
// (still used elsewhere on this page) purely as the real-name lookup; it is
// no longer used to key a schema.
var SERVICE_IDS = <?= wp_json_encode($serviceNameToId ?? []) ?>;
var SERVICE_TO_CATEGORY = <?= wp_json_encode($serviceToCategory ?? []) ?>;
var CATEGORY_SCHEMAS = <?= wp_json_encode($categorySchemas ?? []) ?>;
var dynamicActive = false, dynamicCategoryKey = null;

function dynEvalRule(rule, ans){
  var actual = ans[rule.field]; if (actual === undefined) actual = null;
  var expected = rule.value;
  switch (rule.op) {
    case 'equals': return String(actual) === String(expected);
    case 'not_equals': return String(actual) !== String(expected);
    case 'in': return (Array.isArray(expected)?expected:[expected]).map(String).indexOf(String(actual)) !== -1;
    case 'not_in': return (Array.isArray(expected)?expected:[expected]).map(String).indexOf(String(actual)) === -1;
    case 'contains': return String(actual==null?'':actual).indexOf(String(expected)) !== -1;
    case 'filled': return actual !== null && actual !== '' && !(Array.isArray(actual) && !actual.length);
    case 'empty': return actual === null || actual === '' || (Array.isArray(actual) && !actual.length);
    case 'greater_than': return parseFloat(actual) > parseFloat(expected);
    case 'less_than': return parseFloat(actual) < parseFloat(expected);
    default: return true;
  }
}
/** True if `el` sits inside a field/document wrapper this page itself set
 *  to display:none via dynRefreshVisibility() — i.e. its visible_if is
 *  currently false. Deliberately checks OUR OWN wrapper's inline style
 *  rather than a layout-dependent signal like offsetParent, since that
 *  depends on the browser having actually run layout (never true inside a
 *  headless DOM test with no rendering engine) and could also be fooled by
 *  unrelated ancestor CSS this page doesn't control. */
function dynIsFieldWrapHidden(el){
  var wrap = el.closest('[data-dyn-field], [data-dyn-doc]');
  return !!wrap && wrap.style.display === 'none';
}

function dynEvalCond(group, ans){
  if (!group) return true;
  var rr = (group.rules||[]).map(function(r){ return dynEvalRule(r, ans); });
  var gr = (group.groups||[]).map(function(g){ return dynEvalCond(g, ans); });
  var all = rr.concat(gr);
  if (!all.length) return true;
  return group.logic === 'OR' ? all.indexOf(true) !== -1 : all.indexOf(false) === -1;
}

/** Returns null if every required checkbox GROUP in the container has at
 *  least one option checked; otherwise returns the human label of the
 *  first one that doesn't, so the caller can show a real error instead of
 *  silently submitting past a required-but-empty group (native HTML
 *  `required` cannot express "at least one of these" for checkboxes —
 *  see the comment on dynRenderFieldHtml()'s radio/checkbox branch). */
function dynValidateRequiredGroups(container){
  var groups = container.querySelectorAll('[data-dyn-required-group]');
  for (var i = 0; i < groups.length; i++) {
    // FIX: since Part 4.17's incremental re-render keeps every field's DOM
    // node permanently present (only display:none when its visible_if is
    // false — see dynRefreshVisibility()), a required checkbox group that
    // is currently hidden from the customer must not be able to block
    // submission. Checks the specific wrapper WE control (data-dyn-field)
    // rather than offsetParent, which depends on layout having actually run
    // — correct in every real browser, but also makes this exact check
    // provably verifiable in a headless DOM test with no layout engine.
    if (dynIsFieldWrapHidden(groups[i])) continue;
    var key = groups[i].getAttribute('data-dyn-required-group');
    var checked = container.querySelectorAll('[data-dyn-key="'+key+'"]:checked');
    if (!checked.length) return groups[i].getAttribute('data-dyn-required-label') || key;
  }
  return null;
}

/** visibleOnly=false (default): used internally by dynRefreshVisibility() to
 *  evaluate visible_if conditions — a condition may legitimately reference a
 *  field that is itself currently hidden (e.g. re-shown later by yet another
 *  answer changing), so every value present in the DOM is considered.
 *  visibleOnly=true: used at final submit time — since Part 4.17's
 *  incremental re-render keeps every field's node permanently in the DOM
 *  (just display:none when not applicable), a stale value a customer typed
 *  before an earlier answer hid that field must NOT be sent to the server
 *  as if it were still part of their answer. */
function dynCollectAnswers(container, visibleOnly){
  var ans = {};
  container.querySelectorAll('[data-dyn-key]').forEach(function(el){
    if (visibleOnly && el.type !== 'hidden' && dynIsFieldWrapHidden(el)) return;
    var key = el.dataset.dynKey;
    if (el.type === 'checkbox') {
      if (el.dataset.dynMulti) {
        ans[key] = Array.prototype.filter.call(container.querySelectorAll('[data-dyn-key="'+key+'"]:checked'), function(){return true;}).map(function(c){return c.value;});
      } else { ans[key] = el.checked; }
    } else if (el.type === 'radio') {
      if (el.checked) ans[key] = el.value;
    } else if (el.tagName === 'SELECT' && el.multiple) {
      ans[key] = Array.prototype.filter.call(el.options, function(o){return o.selected;}).map(function(o){return o.value;});
    } else if (el.type !== 'file') {
      ans[key] = el.value;
    }
  });
  return ans;
}

function dynFieldWrapHtml(f, idx){
  return '<div data-dyn-field="'+idx+'" style="display:none">' + dynRenderFieldHtml(f) + '</div>';
}

function dynRenderFieldHtml(f){
  var reqAttr = f.required ? 'required' : '';
  var reqStar = f.required ? ' <span class="req">*</span>' : '';
  // Part 4.13: matches the reference plugin's "Show label" checkbox — when
  // off, the field still renders and still submits under its key, just
  // without a visible <label> (e.g. a lone checkbox whose option text
  // already reads as the label). Defaults on for every existing schema.
  var labelHtml = (f.show_label === false) ? '' : ('<label>'+f.label+reqStar+'</label>');
  if (f.type === 'heading') return '<p class="sec-title" style="margin-top:16px">'+f.label+'</p>';
  if (f.type === 'consent') {
    return '<div class="fg"><label><input type="checkbox" data-dyn-key="'+f.key+'" '+reqAttr+'> '+(f.help_text||f.label)+'</label></div>';
  }
  var help = f.help_text ? '<div style="font-size:12px;color:#64748b;margin-top:3px">'+f.help_text+'</div>' : '';
  if (f.type === 'textarea') {
    return '<div class="fg">'+labelHtml+'<textarea class="ta" rows="3" data-dyn-key="'+f.key+'" placeholder="'+(f.placeholder||'')+'" '+reqAttr+'></textarea>'+help+'</div>';
  }
  if (['select','dependent_select'].indexOf(f.type) !== -1) {
    var opts = '<option value="">— Select —</option>';
    if (f.type === 'select') Object.keys(f.options||{}).forEach(function(v){ opts += '<option value="'+v+'">'+f.options[v]+'</option>'; });
    return '<div class="fg">'+labelHtml+'<select class="sel" data-dyn-key="'+f.key+'" '+
      (f.type==='dependent_select' ? 'data-dyn-source="'+f.dependent_source.source+'" data-dyn-parent="'+f.dependent_source.parent_field+'"' : '') +
      ' '+reqAttr+'>'+opts+'</select>'+help+'</div>';
  }
  if (f.type === 'multiselect') {
    var mopts = ''; Object.keys(f.options||{}).forEach(function(v){ mopts += '<option value="'+v+'">'+f.options[v]+'</option>'; });
    return '<div class="fg">'+labelHtml+'<select class="sel" multiple data-dyn-key="'+f.key+'">'+mopts+'</select>'+help+'</div>';
  }
  if (f.type === 'radio' || f.type === 'checkbox') {
    // FIX (gap disclosed after the last delivery, closed here): required
    // radio/checkbox fields were previously enforced server-side only —
    // native browser validation only sees `required` when it's actually
    // present on the <input>. A radio group can express "must pick one"
    // natively (required on every same-named radio — the browser is
    // satisfied once any one of them is checked). A checkbox GROUP cannot:
    // native `required` on a checkbox means "this exact box must be
    // checked", which is the wrong semantic for "check at least one of
    // these" — so a required checkbox group instead gets a
    // data-dyn-required-group marker, and dynValidateRequiredGroups()
    // (called from the submit handler below, before anything is sent) does
    // the "at least one checked" check that native HTML can't express.
    var rows = Object.keys(f.options||{}).map(function(v){
      var reqRadio = (f.type === 'radio' && f.required) ? ' required' : '';
      return '<label style="display:flex;align-items:center;gap:6px;font-weight:400;margin:4px 0"><input type="'+(f.type==='radio'?'radio':'checkbox')+'" name="dyn_'+f.key+'" value="'+v+'" data-dyn-key="'+f.key+'" '+(f.type==='checkbox'?'data-dyn-multi="1"':'')+reqRadio+'> '+f.options[v]+'</label>';
    }).join('');
    var groupAttr = (f.type === 'checkbox' && f.required) ? ' data-dyn-required-group="'+f.key+'" data-dyn-required-label="'+f.label.replace(/"/g,'&quot;')+'"' : '';
    return '<div class="fg"'+groupAttr+'>'+labelHtml+rows+help+'</div>';
  }
  if (f.type === 'file') {
    return '<div class="fg">'+labelHtml+'<input type="file" class="inp" name="'+f.key+'" data-dyn-key="'+f.key+'" '+reqAttr+'>'+help+'</div>';
  }
  var htmlType = ({tel:'tel',email:'email',number:'number',date:'date',time:'time'})[f.type] || 'text';
  return '<div class="fg">'+labelHtml+'<input type="'+htmlType+'" class="inp" data-dyn-key="'+f.key+'" placeholder="'+(f.placeholder||'')+'" '+reqAttr+'>'+help+'</div>';
}

function dynFillDependent(select){
  var source = select.dataset.dynSource;
  var parentEl = document.querySelector('[data-dyn-key="'+select.dataset.dynParent+'"]');
  var parentVal = parentEl ? parentEl.value : '';
  // Today's only registered dependent source (state_rto) is exactly the RTO
  // state->office map this page already embeds — reused rather than
  // duplicated, so both the static and admin-built fields share one dataset.
  var map = (source === 'state_rto') ? RTO : {};
  var children = parentVal ? (map[parentVal] || []) : [];
  var cur = select.value;
  select.innerHTML = '<option value="">'+(parentVal?'— Select —':'— Select the field above first —')+'</option>';
  children.forEach(function(c){ var o=document.createElement('option'); o.value=c; o.textContent=c; select.appendChild(o); });
  if (children.indexOf(cur) !== -1) select.value = cur;
}

/** forcedAnswers carries values not present as rendered fields in this
 *  container — today, always {selected_service: <picked name>}, since the
 *  real static category/sub-service dropdowns already serve as the picker
 *  and the schema's own selected_service field is never itself rendered
 *  (see the skip below). A hidden input keeps that value present in every
 *  dynCollectAnswers() call across re-renders, including ones triggered by
 *  a field's own change event.
 *
 *  FIX (critical data-loss bug reported by the user — root-caused, not
 *  patched): this function used to do `container.innerHTML = html` on
 *  EVERY 'change' event fired by ANY field in the form (wired at the bottom
 *  of the old version of this function), rebuilding every field's <input>
 *  from scratch with no `value`/`checked`/`selected` restored from the just
 *  -collected `answers` object — so the instant a customer finished typing
 *  into any field and tabbed/clicked to the next one (which fires 'change'
 *  on blur), the WHOLE step re-rendered and every previously-entered value
 *  vanished, exactly as reported ("enter data, move to next field, previous
 *  field's data disappears"). For file inputs this was actually worse than
 *  a cosmetic bug and COULD NOT have been fixed by restoring a `value`
 *  attribute even if one had been added — browsers refuse, for security
 *  reasons, to let JavaScript programmatically re-select a file into an
 *  <input type=file> — so a previously chosen document was unrecoverably
 *  lost on every single keystroke-triggered re-render anywhere else on the
 *  form, which is almost certainly also why a customer could see a
 *  submission fail (a document the UI showed as attached three fields ago
 *  was, in the DOM the submit handler actually read from, never there).
 *
 *  Fixed at the root, not by adding `value=` attributes to a rebuild that
 *  still happens every keystroke: this now renders every field's DOM node
 *  EXACTLY ONCE per schema (see dynRefreshVisibility() below), and every
 *  subsequent change anywhere in the form only shows/hides existing nodes
 *  (toggling `display` + the `required` attribute) — it never destroys or
 *  recreates an <input>, <select>, or <textarea> that was already on the
 *  page, so whatever a customer typed, checked, or attached earlier stays
 *  exactly as they left it no matter what else on the form changes. A full
 *  rebuild only ever happens when the SCHEMA OBJECT itself changes — i.e.
 *  the customer picked a service in a genuinely different category — which
 *  is the one case where starting over is actually correct. */
function renderDynamicStep2(container, schema, forcedAnswers){
  forcedAnswers = forcedAnswers || {};
  if (container._dynSchema === schema) {
    // Same schema still loaded (e.g. the customer switched to a different
    // sub-service within the SAME category, or any field's own 'change'
    // fired) — never rebuild the DOM, just re-evaluate visibility.
    container._dynForced = forcedAnswers;
    dynRefreshVisibility(container, schema, forcedAnswers);
    return;
  }

  // Genuinely new/different schema (first render for this container, or a
  // real category switch) — the one case a full rebuild is correct, since
  // the whole set of fields is different and nothing to preserve applies.
  container._dynSchema = schema;
  container._dynForced = forcedAnswers;
  var answers = {};
  Object.keys(forcedAnswers).forEach(function(k){ answers[k] = forcedAnswers[k]; });

  var html = '<p class="sec-title">📋 '+(schema.name||'Service Details')+'</p>';
  Object.keys(forcedAnswers).forEach(function(k){
    html += '<input type="hidden" data-dyn-key="'+k+'" data-dyn-forced="1" value="'+String(forcedAnswers[k]).replace(/"/g,'&quot;')+'">';
  });
  var renderableFields = (schema.all_fields || []).filter(function(f){
    return f.key !== 'selected_service' && f.active !== false;
  });
  renderableFields.forEach(function(f, idx){ html += dynFieldWrapHtml(f, idx); });

  var docs = schema.documents || [];
  if (docs.length) {
    html += '<p class="sec-title" style="margin-top:18px">📎 Documents Required</p>';
    docs.forEach(function(d, idx){
      html += '<div data-dyn-doc="'+idx+'" style="display:none" class="fg"><label>'+(d.label||'Document')+(d.required?' <span class="req">*</span>':' (optional)')+
        '</label><input type="file" class="inp" name="doc_'+d.doc_type_id+'" data-dyn-doc-required="'+(d.required?'1':'0')+'"></div>';
    });
  }

  container.innerHTML = html;
  container._dynFields = renderableFields;
  container._dynDocs = docs;

  // Attach every field's change listener exactly once, here, at initial
  // build time — dynRefreshVisibility() never touches these nodes again, so
  // this is the only place listeners are ever bound for this render.
  container.querySelectorAll('[data-dyn-key]:not([data-dyn-forced])').forEach(function(el){
    if (el.type === 'file') return; // files have nothing meaningful to react to via 'change' here beyond their own value
    el.addEventListener('change', function(){ dynRefreshVisibility(container, schema, container._dynForced); });
  });

  dynRefreshVisibility(container, schema, forcedAnswers);
}

/** Re-evaluates every field's (and document requirement's) visible_if
 *  against the current live DOM values, and toggles ONLY display + required
 *  — see the FIX note on renderDynamicStep2() above for why this exists.
 *  Never sets .value, .checked, or .selected on anything, so nothing a
 *  customer already entered is ever touched by a condition elsewhere on the
 *  form changing. */
function dynRefreshVisibility(container, schema, forcedAnswers){
  forcedAnswers = forcedAnswers || {};
  // Keep the hidden forced-answer inputs (e.g. selected_service) in sync in
  // place — updates their value without recreating the node.
  Object.keys(forcedAnswers).forEach(function(k){
    var hid = container.querySelector('[data-dyn-key="'+k+'"][data-dyn-forced]');
    if (hid) hid.value = String(forcedAnswers[k]);
  });

  var answers = dynCollectAnswers(container);
  Object.keys(forcedAnswers).forEach(function(k){ answers[k] = forcedAnswers[k]; });

  (container._dynFields || []).forEach(function(f, idx){
    var wrap = container.querySelector('[data-dyn-field="'+idx+'"]');
    if (!wrap) return;
    var visible = dynEvalCond(f.visible_if, answers);
    wrap.style.display = visible ? '' : 'none';
    // A hidden required field must not block native browser validation (or
    // FormData collection) for a field the customer can no longer even see;
    // restore `required` the moment it becomes visible again so the
    // original validation behaviour for a visible required field is
    // unchanged from before this fix.
    wrap.querySelectorAll('[data-dyn-key]').forEach(function(el){
      if (!f.required) return;
      if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type !== 'radio')) {
        el.required = !!visible;
      } else if (el.type === 'radio') {
        el.required = !!visible;
      }
    });
  });

  (container._dynDocs || []).forEach(function(d, idx){
    var wrap = container.querySelector('[data-dyn-doc="'+idx+'"]');
    if (!wrap) return;
    var visible = dynEvalCond(d.visible_if, answers);
    wrap.style.display = visible ? '' : 'none';
    var fileInput = wrap.querySelector('input[type="file"]');
    if (fileInput) fileInput.required = visible && fileInput.dataset.dynDocRequired === '1';
  });

  // Dependent dropdowns: safe to re-run on every refresh now that the
  // <select> node itself is never destroyed — dynFillDependent() correctly
  // preserves the current selection (reading it from the SAME, still-live
  // node) when it's still a valid option for the (possibly now-different)
  // parent value, and only actually changes anything when the parent's
  // value has changed since the last check.
  container.querySelectorAll('select[data-dyn-source]').forEach(dynFillDependent);
}

/** Called from selDL()/selRC()/selHP()/selVehicle()/selCat()/nocType() with
 *  the exact service name the user just picked. Resolves which of the 7
 *  category schemas that service belongs to (SERVICE_IDS' keys are the same
 *  real names every category schema's selected_service picker was built
 *  from — see RealFormSchemaSeeder::categoryMap()) and, if that category has
 *  an active v2 schema, renders it with selected_service forced to this
 *  service so only its own fields become visible. Returns true if an
 *  admin-built form took over Step 2 for this service (caller should skip
 *  its own static block); false means render the static fields as always. */
function checkDynamicForService(name){
  var catKey = SERVICE_TO_CATEGORY[name] || null;
  var schema = catKey ? CATEGORY_SCHEMAS[catKey] : null;
  var container = document.getElementById('dynamic_step2_container');
  if (schema) {
    dynamicActive = true; dynamicCategoryKey = catKey;
    renderDynamicStep2(container, schema, {selected_service: name});
    show('dynamic_step2_container');
    RTO_FUNNEL.send('step_reached', 1); // step 2 — dynamic fields shown for the chosen service
    return true;
  }
  dynamicActive = false; dynamicCategoryKey = null;
  container.innerHTML = '';
  // Must clear these alongside the DOM wipe above — otherwise switching to a
  // no-schema service and then BACK to a schema-having one whose object
  // reference happens to equal container._dynSchema (e.g. the same category
  // picked twice) would make renderDynamicStep2() think the fields are
  // already rendered (since the reference still matches) and skip rebuilding
  // them entirely, leaving the container blank.
  container._dynSchema = null;
  container._dynForced = null;
  container._dynFields = null;
  container._dynDocs = null;
  hide('dynamic_step2_container');
  return false;
}

// ── Utility ───────────────────────────────────────────────────────────────
function show(id){var e=document.getElementById(id);if(e)e.classList.add('on');}
function hide(id){var e=document.getElementById(id);if(e)e.classList.remove('on');}
function val(n){var e=document.querySelector('[name="'+n+'"]');return e?e.value:'';}
function err(msg){var e=document.getElementById('formError');e.className='alert alert-error show';e.innerHTML='⚠️ '+msg;e.scrollIntoView({behavior:'smooth',block:'nearest'});setTimeout(function(){e.classList.remove('show');},5000);}

// ── Step navigation ───────────────────────────────────────────────────────
function goStep(n){
  if(n>cur&&!validateStep(cur))return;
  document.getElementById('p'+cur).style.display='none';
  document.getElementById('p'+n).style.display='block';
  for(var i=1;i<=4;i++){
    var d=document.getElementById('s'+i);if(!d)continue;
    d.classList.remove('active','done');
    if(i<n)d.classList.add('done');
    else if(i===n)d.classList.add('active');
  }
  cur=n;
  window.scrollTo({top:0,behavior:'smooth'});
}

function validateStep(s){
  if(s===1){
    if(!cat){err('Please select a service category.');return false;}
    var needSvc={'dl':1,'rc':1,'hp':1,'vehicle':1};
    if(needSvc[cat]&&!svc){err('Please select the specific service you need.');return false;}
    return true;
  }
  if(s===2){
    // Validate visible required fields in step 2
    var visible=document.querySelectorAll('#p2 .cond.on [required]');
    for(var i=0;i<visible.length;i++){
      var f=visible[i];
      if(f.type==='file') continue; // file uploads optional at this stage
      if(!f.value.trim()){
        f.focus();
        err('Please fill in all required fields before continuing.');
        return false;
      }
    }
    return true;
  }
  if(s===3){
    if(!document.getElementById('rto_state').value){err('Please select your state.');return false;}
    if(!document.getElementById('rto_office').value){err('Please select an RTO office.');return false;}
    return true;
  }
  return true;
}

// ── Category selection ────────────────────────────────────────────────────
function selCat(k){
  cat=k; svc='';
  document.getElementById('fCategory').value=k;
  document.getElementById('fSubService').value='';
  document.querySelectorAll('.cat-btn').forEach(function(b){
    var s=b.dataset.cat===k;b.classList.toggle('sel',s);b.setAttribute('aria-pressed',s?'true':'false');
  });
  // Hide all sub selectors in step 1
  ['sub_dl','sub_rc','sub_hp','sub_vehicle','sub_noc_info','sub_commercial_info','sub_other'].forEach(hide);
  // Hide all step 2 panels
  hideAllStep2Panels();
  // Reset destination state row
  hide('dest_state_row');

  var map={dl:'sub_dl',rc:'sub_rc',hp:'sub_hp',vehicle:'sub_vehicle',noc:'sub_noc_info',commercial:'sub_commercial_info',other:'sub_other'};
  if(map[k]) show(map[k]);

  // For categories that don't need sub-service selection, pre-wire step 2
  if(k==='noc'){
    // 'NOC' itself is not a real rto_services row — the 4 real NOC services
    // ('Inter-state NOC', 'Within-state Transfer NOC', 'Hypothecation
    // Removal NOC', 'Export / Out-of-Country NOC') are only resolved once
    // the customer picks a specific noc_type inside the panel below — see
    // nocType(), which sets `svc` to the real name and re-checks
    // checkDynamicForService() at that point. So this just shows the
    // generic panel; it does not (and cannot) resolve a dynamic form yet.
    svc='NOC';
    document.getElementById('fSubService').value='NOC';
    show('noc_fields');        // pre-show NOC panel in step 2
    show('dest_state_row');    // destination state shown in step 3
  } else if(k==='commercial'){
    // Real rto_services row for this category is 'Commercial Vehicle Service'
    // (confirmed against IndiaDataSeeder.php) — NOT the literal 'Commercial
    // Vehicle' this used to send, which matched no real service and meant
    // checkDynamicForService() could never activate a Form Builder schema
    // for this category. Fixed to the real name.
    svc='Commercial Vehicle Service';
    document.getElementById('fSubService').value='Commercial Vehicle Service';
    if(!checkDynamicForService('Commercial Vehicle Service')){
      show('commercial_fields'); // pre-show commercial panel in step 2
    }
  } else if(k==='other'){
    // Real rto_services row for this category is 'RTO Consultation &
    // Documentation' (confirmed against IndiaDataSeeder.php) — NOT the
    // literal 'Other Services' this used to send, for the same reason as
    // the Commercial Vehicle fix above.
    svc='RTO Consultation & Documentation';
    document.getElementById('fSubService').value='RTO Consultation & Documentation';
    if(!checkDynamicForService('RTO Consultation & Documentation')){
      show('other_fields_step2'); // pre-show other panel in step 2
    }
  }
}

function hideAllStep2Panels(){
  var ids=['dl_ll','dl_new','dl_dup','dl_renewal','dl_transport','dl_smartcard','dl_addr','dl_expiry_renew','dl_permanent','dl_correction',
           'rc_transfer','rc_addr','rc_dup','rc_particulars','rc_correction','rc_cancel','rc_surrender',
           'hp_termination','hp_continuation','hp_other','noc_fields','vehicle_fields',
           'commercial_fields','other_fields_step2','dynamic_step2_container'];
  ids.forEach(hide);
}

// ── DL sub-service ────────────────────────────────────────────────────────
function selDL(v){
  svc=v; document.getElementById('fSubService').value=v;
  hideAllStep2Panels();
  if(checkDynamicForService(v)) return; // admin-built form takes over Step 2 for this service
  var m={'Learning License':'dl_ll','New Driving License':'dl_new','Duplicate Driving License':'dl_dup','Driving License Renewal':'dl_renewal',
         'Transport License':'dl_transport','Smart Card Driving License':'dl_smartcard',
         'Change of Address in Driving License':'dl_addr','Renewal of Driving License After Expiry':'dl_expiry_renew',
         'Permanent License Service':'dl_permanent','Information Changes / Correction on DL':'dl_correction'};
  if(m[v]) show(m[v]);
  v==='Change of Address in Driving License'?show('dest_state_row'):hide('dest_state_row');
}

// ── RC sub-service ────────────────────────────────────────────────────────
function selRC(v){
  svc=v; document.getElementById('fSubService').value=v;
  hideAllStep2Panels();
  if(checkDynamicForService(v)) return;
  var m={'Transfer of Ownership':'rc_transfer','Change of Address in RC':'rc_addr','Duplicate RC (Lost / Damaged / Stolen)':'rc_dup',
         'RC Particulars / RC Extract':'rc_particulars','RC Correction':'rc_correction',
         'RC Cancellation':'rc_cancel','RC Surrender':'rc_surrender'};
  if(m[v]) show(m[v]);
}

// ── HP sub-service ────────────────────────────────────────────────────────
function selHP(v){
  svc=v; document.getElementById('fSubService').value=v;
  hideAllStep2Panels();
  if(checkDynamicForService(v)) return;
  if(v==='Hypothecation Termination / Removal') show('hp_termination');
  else if(v==='Hypothecation Continuation') show('hp_continuation');
  else if(v) show('hp_other');
}

// ── Vehicle sub-service ───────────────────────────────────────────────────
function selVehicle(v){
  svc=v; document.getElementById('fSubService').value=v;
  hideAllStep2Panels();
  if(checkDynamicForService(v)) return;
  show('vehicle_fields');
  hide('fitness_files'); hide('invoice_file');
  var lbl=document.getElementById('veh_reg_lbl');
  if(v==='New Vehicle Registration'){
    show('invoice_file');
    if(lbl) lbl.innerHTML='Temp. Reg. No. / Chassis No. <span class="req">*</span>';
  } else {
    if(lbl) lbl.innerHTML='Vehicle Reg. No. <span class="req">*</span>';
    if(['Fitness Certificate','Fitness Certificate Renewal','Duplicate Fitness Certificate'].indexOf(v)>-1) show('fitness_files');
  }
  // NOC under vehicle → destination state
  if(v==='No Objection Certificate (NOC)') show('dest_state_row'); else hide('dest_state_row');
}

// ── DL sub-handlers ───────────────────────────────────────────────────────
function dlDupReason(v){hide('dup_fir');hide('dup_damaged');if(v==='Lost'||v==='Stolen')show('dup_fir');if(v==='Damaged')show('dup_damaged');}
function dlChangeType(v){hide('dl_chg_addr_field');hide('dl_chg_name_proof');hide('dl_chg_dob_proof');if(v==='Address')show('dl_chg_addr_field');if(v==='Name')show('dl_chg_name_proof');if(v==='Date of Birth')show('dl_chg_dob_proof');}

// ── NOC type handler ──────────────────────────────────────────────────────
// NOC_REAL_NAME maps this panel's `noc_type` dropdown values to the actual
// rto_services.name rows (confirmed against IndiaDataSeeder.php: 'Inter-state
// NOC', 'Within-state Transfer NOC', 'Hypothecation Removal NOC', 'Export /
// Out-of-Country NOC'). Previously `svc`/`fSubService` stayed the literal
// string 'NOC' (set once by selCat('noc')) no matter which noc_type the
// customer picked — meaning checkDynamicForService() could never activate an
// admin-built form for any of the 4 real NOC services, since none of them is
// literally named 'NOC'. Fixed here: choosing a noc_type now updates `svc`
// to the matching real service name and re-runs checkDynamicForService(),
// exactly like selDL()/selRC()/selHP()/selVehicle() already do for their own
// sub-service selects, so a Form-Builder schema saved against any of the 4
// real NOC services now actually takes over this panel when active.
var NOC_REAL_NAME={'Inter-state NOC':'Inter-state NOC','Within-state transfer':'Within-state Transfer NOC',
  'Hypothecation removal':'Hypothecation Removal NOC','Export / Out-of-country':'Export / Out-of-Country NOC'};
function nocType(v){
  v?show('noc_docs_section'):hide('noc_docs_section');
  (v==='Inter-state NOC'||v==='Export / Out-of-country')?show('dest_state_row'):hide('dest_state_row');
  var real=NOC_REAL_NAME[v];
  if(real){
    svc=real;
    var f=document.getElementById('fSubService'); if(f) f.value=real;
    if(checkDynamicForService(real)){
      hide('noc_fields'); // admin-built form replaces this whole static panel
    }
  }
}

// ── State → RTO office ────────────────────────────────────────────────────
function stateChange(state,targetId){
  var sel=document.getElementById(targetId);
  if(!sel) return;
  sel.innerHTML='<option value="">— Select RTO Office —</option>';
  sel.disabled=true;
  if(!state) return;
  var rtos=RTO[state]||[];
  rtos.forEach(function(r){var o=document.createElement('option');o.value=r;o.textContent=r;sel.appendChild(o);});
  sel.disabled=rtos.length===0;
  if(rtos.length===0){var o=document.createElement('option');o.value='';o.textContent='— Contact us for RTO details —';sel.appendChild(o);sel.disabled=false;}
}

// ── CSP compliance: wire up all controls that used to carry inline
// onclick/onchange attributes. Nonce'd script blocks execute fine under the
// hardened script-src CSP, but inline event-handler attributes (onclick=,
// onchange=, etc.) do not — 'nonce-...' only covers <script> elements, not
// intrinsic event attributes, so every listener below must be attached via
// addEventListener instead of relying on markup. Behaviour is unchanged;
// only how each handler gets invoked changed. ────────────────────────────
document.getElementById('catsGroup').addEventListener('click', function(e){
  var btn = e.target.closest('.cat-btn');
  if (!btn) return;
  selCat(btn.dataset.cat);
});
document.getElementById('dl_service').addEventListener('change', function(){ selDL(this.value); });
document.getElementById('rc_service').addEventListener('change', function(){ selRC(this.value); });
document.getElementById('hp_service').addEventListener('change', function(){ selHP(this.value); });
document.getElementById('vehicle_service').addEventListener('change', function(){ selVehicle(this.value); });
document.getElementById('s1Btn').addEventListener('click', function(){ goStep(2); });
document.getElementById('s2BackBtn').addEventListener('click', function(){ goStep(1); });
document.getElementById('s2Btn').addEventListener('click', function(){ goStep(3); });
document.getElementById('s3BackBtn').addEventListener('click', function(){ goStep(2); });
document.getElementById('s3Btn').addEventListener('click', function(){ goStep(4); });
document.getElementById('s4BackBtn').addEventListener('click', function(){ goStep(3); });
document.getElementById('dup_dl_reason').addEventListener('change', function(){ dlDupReason(this.value); });
document.getElementById('has_existing_dl').addEventListener('change', function(){
  this.value==='Yes' ? show('sc_dl_num') : hide('sc_dl_num');
});
document.getElementById('dl_change_type').addEventListener('change', function(){ dlChangeType(this.value); });
document.getElementById('hp_cont_reason').addEventListener('change', function(){
  this.value==='Refinance' ? show('hp_new_bank') : hide('hp_new_bank');
});
document.getElementById('noc_type').addEventListener('change', function(){ nocType(this.value); });
document.getElementById('rto_state').addEventListener('change', function(){ stateChange(this.value,'rto_office'); });
document.getElementById('destination_state').addEventListener('change', function(){ stateChange(this.value,'destination_rto'); });

// ── Autosave / draft restore ────────────────────────────────────────────
// Persists in-progress answers (via Router::saveDraft(), backed by
// FormDraftService / rto_form_drafts) so navigating away or a dropped
// session doesn't lose everything typed so far. The draft token itself is
// server-issued (see RTO_DRAFT.save()) and cached in localStorage under
// RTO_DRAFT_STORAGE_KEY purely so THIS browser can find its own draft again
// on a later visit — the server never trusts a client-supplied token beyond
// the hex-shape check FormDraftService::normaliseToken() applies.
var RTO_DRAFT_STORAGE_KEY = 'rtoflow_draft_token_apply';
var RTO_DRAFT_AUTOSAVE_MS = 15000; // periodic autosave interval
var RTO_DRAFT = {
  token: null,
  saving: false,
  dirty: false,
  timer: null,

  storedToken: function(){
    try { return localStorage.getItem(RTO_DRAFT_STORAGE_KEY) || ''; } catch(e){ return ''; }
  },
  storeToken: function(tok){
    try { localStorage.setItem(RTO_DRAFT_STORAGE_KEY, tok); } catch(e){}
  },
  clearStoredToken: function(){
    try { localStorage.removeItem(RTO_DRAFT_STORAGE_KEY); } catch(e){}
  },

  collectAnswers: function(){
    var out = {};
    var form = document.getElementById('applyForm');
    new FormData(form).forEach(function(v,k){
      if (k==='rto_nonce' || k==='rto_action' || k==='draft_token') return;
      out[k] = v;
    });
    if (dynamicActive) {
      var dynContainer = document.getElementById('dynamic_step2_container');
      if (dynContainer) {
        var dynAnswers = dynCollectAnswers(dynContainer, false);
        Object.keys(dynAnswers).forEach(function(k){ out[k] = dynAnswers[k]; });
      }
    }
    return out;
  },

  markDirty: function(){ RTO_DRAFT.dirty = true; },

  save: function(){
    if (RTO_DRAFT.saving || !RTO_DRAFT.dirty) return;
    var category = document.getElementById('fCategory').value || cat || '';
    if (!category) return; // nothing worth saving until step 1 is chosen
    RTO_DRAFT.saving = true;
    RTO_DRAFT.dirty = false;
    var fd = new FormData();
    fd.set('action','rto_public');
    fd.set('rto_nonce',NONCE);
    fd.set('rto_action','save_draft');
    fd.set('draft_token', RTO_DRAFT.token || '');
    fd.set('category', category);
    fd.set('service_id', SERVICE_IDS[svc] || '');
    fd.set('answers', JSON.stringify(RTO_DRAFT.collectAnswers()));
    fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        RTO_DRAFT.saving = false;
        if (d.success && d.data && d.data.draft_token) {
          RTO_DRAFT.token = d.data.draft_token;
          document.getElementById('fDraftToken').value = RTO_DRAFT.token;
          RTO_DRAFT.storeToken(RTO_DRAFT.token);
        }
      })
      .catch(function(){ RTO_DRAFT.saving = false; RTO_DRAFT.dirty = true; });
  },

  scheduleAutosave: function(){
    RTO_DRAFT.markDirty();
    if (RTO_DRAFT.timer) return;
    RTO_DRAFT.timer = setInterval(RTO_DRAFT.save, RTO_DRAFT_AUTOSAVE_MS);
  },

  restore: function(answers){
    Object.keys(answers).forEach(function(k){
      var v = answers[k];
      var el = document.querySelector('[name="'+k+'"]');
      if (el) { el.value = v; return; }
      var dynEl = document.getElementById('dynamic_step2_container');
      if (dynEl) {
        var f = dynEl.querySelector('[data-dyn-key="'+k+'"]');
        if (f) f.value = v;
      }
    });
  },

  checkForExisting: function(){
    var tok = RTO_DRAFT.storedToken();
    if (!tok) return;
    var fd = new FormData();
    fd.set('action','rto_public');
    fd.set('rto_nonce',NONCE);
    fd.set('rto_action','load_draft');
    fd.set('draft_token', tok);
    fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if (!d.success || !d.data) { RTO_DRAFT.clearStoredToken(); return; }
        RTO_DRAFT.token = d.data.draft_token;
        document.getElementById('fDraftToken').value = RTO_DRAFT.token;
        var banner = document.getElementById('draftBanner');
        document.getElementById('draftSavedAt').textContent = d.data.updated_at || '';
        banner.style.display = 'block';
        document.getElementById('draftRestoreBtn').addEventListener('click', function(){
          RTO_DRAFT.restore(d.data.answers || {});
          banner.style.display = 'none';
        });
        document.getElementById('draftDiscardBtn').addEventListener('click', function(){
          RTO_DRAFT.clearStoredToken();
          RTO_DRAFT.token = null;
          document.getElementById('fDraftToken').value = '';
          banner.style.display = 'none';
        });
      })
      .catch(function(){});
  }
};
document.addEventListener('DOMContentLoaded', RTO_DRAFT.checkForExisting);
document.getElementById('applyForm').addEventListener('input', RTO_DRAFT.scheduleAutosave);
document.getElementById('applyForm').addEventListener('blur', RTO_DRAFT.scheduleAutosave, true);

// ── Form submit ───────────────────────────────────────────────────────────
document.getElementById('applyForm').addEventListener('submit',function(e){
  e.preventDefault();
  if(!document.getElementById('consentChk').checked){err('Please accept the terms and conditions to submit.');return;}
  var mob=document.querySelector('[name="mobile"]').value.replace(/\D/g,'');
  if(mob.length!==10||!/^[6-9]/.test(mob)){err('Please enter a valid 10-digit Indian mobile number.');return;}
  var btn=document.getElementById('submitBtn');
  var txt=document.getElementById('submitTxt');
  btn.disabled=true; txt.textContent='Submitting…';
  var fd=new FormData(this);
  fd.set('action','rto_public');fd.set('rto_nonce',NONCE);fd.set('mobile',mob);
  // FIX (found during the Part 4.10 gap sweep — real regression introduced
  // by that pass, not present before it): this used to check `dynamicServiceId`,
  // a per-service id set by the old checkDynamicForService()/DYNAMIC_SCHEMAS
  // mechanism. Part 4.10 replaced that with dynamicCategoryKey (a category
  // key like 'dl', not a service id) but this submit handler was never
  // updated — `dynamicServiceId` was undefined, meaning every dynamic-form
  // submission would have thrown a ReferenceError right here and silently
  // fallen through, or (depending on hoisting) always evaluated falsy and
  // silently submitted as the STATIC v2 form even when an admin-built form
  // was on screen — a customer's answers to the custom fields would never
  // have reached the server at all. The server (submitApplyDynamic() in
  // Router.php) resolves the category from service_id itself, so what it
  // actually needs here is the real numeric service_id of whichever
  // specific sub-service `svc` currently holds — looked up the same way
  // SERVICE_IDS is already used everywhere else on this page.
  var dynServiceId = SERVICE_IDS[svc] || null;
  if(dynamicActive && dynServiceId){
    var missingGroupLabel = dynValidateRequiredGroups(document.getElementById('dynamic_step2_container'));
    if (missingGroupLabel) {
      RTO_FUNNEL.send('validation_failed', 1, missingGroupLabel);
      btn.disabled=false; txt.textContent='Submit Application →'; err('Please select at least one option for "'+missingGroupLabel+'".'); return;
    }
    // Form Builder v2 fields (text/select/etc.) render without a `name`
    // attribute (see dynRenderFieldHtml()) so they can never collide with a
    // static field of the same key from another category — FormData(this)
    // above already picked up file inputs and the shared contact/location
    // fields (mobile/email/rto_state/rto_office/etc., which DO have names),
    // so only the non-file dynamic answers need appending here by key.
    fd.set('rto_action','submit_apply_dynamic');
    fd.set('service_id', dynServiceId);
    var dynContainer=document.getElementById('dynamic_step2_container');
    var dynAnswers=dynCollectAnswers(dynContainer, true); // visibleOnly — see dynCollectAnswers()'s doc comment
    Object.keys(dynAnswers).forEach(function(k){
      var v=dynAnswers[k];
      fd.set(k, Array.isArray(v)?v.join(','):(v===true?'1':(v===false?'':v)));
    });
  } else {
    fd.set('rto_action','submit_apply_v2');
  }
  fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    btn.disabled=false; txt.textContent='Submit Application →';
    if(d.success){
      RTO_FUNNEL.send('submitted', dynamicActive ? 1 : 0);
      // Server already deleted the consumed draft row (see
      // Router::submitApplyDynamic()'s post-insert consumeDraft() call) —
      // clear the client-side pointer to it too so a later visit never
      // offers a "restore" banner for an application already submitted.
      if (RTO_DRAFT.timer) { clearInterval(RTO_DRAFT.timer); RTO_DRAFT.timer=null; }
      RTO_DRAFT.clearStoredToken();
      document.getElementById('formWrap').style.display='none';
      var s=document.getElementById('formSuccess');s.classList.add('show');
      document.getElementById('successMsg').innerHTML='Application Number: <strong>'+(d.data.lead_number||'')+'</strong>'+(d.data.message?'<br>'+d.data.message:'');
      window.scrollTo({top:0,behavior:'smooth'});
    } else {err(d.data&&d.data.message?d.data.message:'Submission failed. Please try again.');}
  })
  .catch(function(){btn.disabled=false;txt.textContent='Submit Application →';err('Network error. Please check your connection and try again.');});
});
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
