<?php
/**
 * Public Order Tracking Page — No login required
 * Access via: /rto-track/{token}
 */
if (!defined('ABSPATH')) exit;

$co     = get_option('rtoflow_company_name', 'RTOASSIST');
$tel    = get_option('rtoflow_company_phone', '');
$colors = [
    'primary'   => get_option('rtoflow_color_primary',   '#1B2A6B'),
    'secondary' => get_option('rtoflow_color_secondary', '#E97B28'),
    'accent'    => get_option('rtoflow_color_accent',    '#16A34A'),
    'bg_dark'   => get_option('rtoflow_color_bg_dark',   '#0A1628'),
];

// Status timeline config
$statusTimeline = [
    'created'           => ['icon' => '📋', 'label' => 'Application Received',  'desc' => 'Your application has been received and is being reviewed.'],
    'payment_pending'   => ['icon' => '💳', 'label' => 'Payment Pending',        'desc' => 'Awaiting service fee payment.'],
    'payment_received'  => ['icon' => '✅', 'label' => 'Payment Confirmed',      'desc' => 'Payment received. Assigning an expert agent to your case.'],
    'assigned'          => ['icon' => '👤', 'label' => 'Agent Assigned',         'desc' => 'An expert agent has been assigned to your application.'],
    'in_progress'       => ['icon' => '⚙️', 'label' => 'Work in Progress',       'desc' => 'Your documents are being processed by our agent.'],
    'pending_docs'      => ['icon' => '📎', 'label' => 'Documents Requested',    'desc' => 'Additional documents are required. Please check your messages.'],
    'docs_submitted'    => ['icon' => '📤', 'label' => 'Documents Submitted',    'desc' => 'All required documents have been collected and verified.'],
    'rto_submitted'     => ['icon' => '🏛️', 'label' => 'Submitted to RTO',       'desc' => 'Your application has been submitted at the RTO office.'],
    'rto_processing'    => ['icon' => '⏳', 'label' => 'RTO Processing',          'desc' => 'The RTO office is processing your application.'],
    'completed'         => ['icon' => '🎉', 'label' => 'Completed',              'desc' => 'Your service is complete! Documents will be delivered shortly.'],
    'on_hold'           => ['icon' => '⚠️', 'label' => 'On Hold',                'desc' => 'Application is on hold. Our team will contact you shortly.'],
    'rejected'          => ['icon' => '❌', 'label' => 'Application Rejected',   'desc' => 'Unfortunately your application was rejected. Please contact support.'],
];

$statusOrder = ['created','payment_pending','payment_received','assigned','in_progress','pending_docs','docs_submitted','rto_submitted','rto_processing','completed'];
$currentStatus = $lead ? ($lead['status'] ?? 'created') : '';
$currentIdx    = $lead ? (array_search($currentStatus, $statusOrder) ?: 0) : -1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Track Your Application — <?= esc_html($co) ?></title>
<meta name="description" content="Track the status of your RTO service application online. Real-time updates on your RC Transfer, DL, NOC, and other RTO services.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<style>
:root{--p:<?= esc_attr($colors['primary']) ?>;--s:<?= esc_attr($colors['secondary']) ?>;--a:<?= esc_attr($colors['accent']) ?>;--dk:<?= esc_attr($colors['bg_dark']) ?>}
*,::before,::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
/* ENTERPRISE GAP FIX (Phase 14, item — "same header-overflow bug as
   apply.php's unguarded .hdr/.hdr-nav row: no wrap/shrink safety on
   narrow screens"): this header only ever has 2 items (logo + one CTA
   button), so the risk here was lower than apply.php's 4-item nav, but
   a long company name (via get_option('rtoflow_company_name')) combined
   with the "New Application →" button text could still force horizontal
   overflow on a ~360px phone with no wrap/shrink guard at all. Hardened
   defensively even though no specific complaint was filed against this
   page, since it shares the exact same unguarded-header pattern. */
.hdr{background:var(--dk);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px 14px}
.hdr-logo{font-size:18px;font-weight:900;color:#fff;text-decoration:none;display:flex;align-items:center;gap:8px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex-shrink:1}
.hdr-logo em{background:var(--s);border-radius:6px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-style:normal;flex-shrink:0}
@media(max-width:420px){
  .hdr{padding:12px 14px}
  .hdr-logo{font-size:15px;max-width:56%}
  .hdr > a[style*="background"]{padding:7px 11px!important;font-size:12px!important;white-space:nowrap;min-height:36px;display:inline-flex;align-items:center}
}
.hero{background:linear-gradient(135deg,var(--dk),var(--p));color:#fff;padding:32px 20px;text-align:center}
.hero h1{font-size:24px;font-weight:900;margin-bottom:6px}
.hero p{opacity:.8;font-size:14px}
.main{max-width:760px;margin:0 auto;padding:24px 16px 48px}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px}
.card-hdr{background:var(--p);color:#fff;padding:14px 20px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.card-body{padding:20px}
/* Search form */
.search-form{display:flex;gap:10px;flex-wrap:wrap}
.search-input{flex:1;min-width:200px;padding:12px 16px;border:2px solid #e2e8f0;border-radius:9px;font-size:15px;font-family:inherit;background:#fff;color:#1e293b}
.search-input:focus{outline:none;border-color:var(--p)}
.search-btn{background:var(--s);color:#fff;border:none;padding:12px 22px;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap}
.search-btn:hover{opacity:.9}
/* Status timeline */
.timeline{display:flex;flex-direction:column;gap:0}
.tl-item{display:flex;gap:14px;position:relative}
.tl-item:not(:last-child)::before{content:'';position:absolute;left:18px;top:36px;bottom:0;width:2px;background:#e2e8f0;z-index:0}
.tl-item.done::before,.tl-item.active::before{background:var(--a)}
.tl-dot{width:36px;height:36px;border-radius:50%;border:2px solid #e2e8f0;background:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;position:relative;z-index:1}
.tl-item.done .tl-dot{background:var(--a);border-color:var(--a)}
.tl-item.active .tl-dot{background:var(--p);border-color:var(--p);box-shadow:0 0 0 4px rgba(27,42,107,.12)}
.tl-content{padding:6px 0 20px;flex:1}
.tl-label{font-size:14px;font-weight:700;color:#1e293b}
.tl-item.active .tl-label{color:var(--p)}
.tl-item.done .tl-label{color:var(--a)}
.tl-item.future .tl-label,.tl-item.future .tl-desc{color:#94a3b8}
.tl-desc{font-size:12px;color:#64748b;margin-top:2px}
/* Lead info */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.info-item{background:#f8fafc;border-radius:8px;padding:12px 14px}
.info-label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.info-value{font-size:14px;font-weight:600;color:#1e293b;word-break:break-word}
/* Status badge */
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;background:#eff6ff;color:var(--p)}
.status-badge.completed{background:#dcfce7;color:#166534}
.status-badge.rejected{background:#fee2e2;color:#dc2626}
.status-badge.on_hold{background:#fef9c3;color:#92400e}
/* SLA progress */
.sla-bar{height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-top:6px}
.sla-fill{height:100%;border-radius:4px;transition:width .3s}
/* Not found state */
.not-found{text-align:center;padding:40px 20px}
.not-found-icon{font-size:56px;margin-bottom:12px}
.not-found-title{font-size:18px;font-weight:700;color:#374151;margin-bottom:6px}
.not-found-desc{font-size:14px;color:#64748b}
/* Contact box */
.contact-box{background:linear-gradient(135deg,var(--p),var(--dk));color:#fff;border-radius:12px;padding:20px;text-align:center;margin-top:20px}
.contact-box h3{font-size:16px;font-weight:700;margin-bottom:6px}
.contact-box p{font-size:13px;opacity:.85;margin-bottom:12px}
.contact-box a{background:var(--s);color:#fff;padding:9px 20px;border-radius:7px;text-decoration:none;font-weight:700;font-size:14px}
@media(max-width:520px){.info-grid{grid-template-columns:1fr}.search-form{flex-direction:column}.search-btn{width:100%}}
</style>
<?php wp_head(); ?>
<?php
// FIX (mobile pass — public site bottom nav): standalone page, never went
// through layouts/website-header.php — same gap as apply.php/home.php.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-website">
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>

<header class="hdr">
  <a href="<?= esc_url(home_url('/')) ?>" class="hdr-logo">
    <em>🔑</em><?= esc_html($co) ?>
  </a>
  <a href="<?= esc_url(home_url('/rto-apply/')) ?>" style="background:var(--s);color:#fff;padding:7px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:700">New Application →</a>
</header>

<section class="hero">
  <h1>📍 Track Your Application</h1>
  <p>Enter your application number or tracking token to see real-time status updates</p>
</section>

<main class="main">

  <!-- Search Form -->
  <div class="card">
    <div class="card-hdr">🔍 Find Your Application</div>
    <div class="card-body">
      <form method="POST" action="<?= esc_url(home_url('/rto-track/')) ?>">
        <?php wp_nonce_field('rto_track_search', 'track_nonce'); ?>
        <div class="search-form">
          <input type="text" name="tracking_token" class="search-input"
                 placeholder="Enter Application Number (e.g. RTO-XXXXXXXX) or Tracking Token"
                 value="<?= esc_attr($token ?? '') ?>" required
                 aria-label="Application number or tracking token">
          <button type="submit" class="search-btn">Track →</button>
        </div>
      </form>
      <?php if ($token && !$lead): ?>
      <div style="margin-top:12px;padding:12px 14px;background:#fee2e2;border-radius:8px;color:#dc2626;font-size:13px;font-weight:600">
        ❌ No application found for this token. Please check the number and try again.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($lead): ?>
  <!-- Application Found -->

  <!-- Status overview card -->
  <div class="card">
    <div class="card-hdr">
      Application <?= esc_html($lead['lead_number']) ?>
    </div>
    <div class="card-body">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <div style="font-size:12px;color:#64748b;margin-bottom:4px;font-weight:600">CURRENT STATUS</div>
          <?php
          $sInfo = $statusTimeline[$currentStatus] ?? ['icon' => '📋', 'label' => ucfirst(str_replace('_',' ',$currentStatus)), 'desc' => ''];
          $badgeClass = in_array($currentStatus, ['completed']) ? 'completed' : (in_array($currentStatus, ['rejected']) ? 'rejected' : ($currentStatus === 'on_hold' ? 'on_hold' : ''));
          ?>
          <div class="status-badge <?= esc_attr($badgeClass) ?>">
            <?= $sInfo['icon'] ?> <?= esc_html($sInfo['label']) ?>
          </div>
        </div>
        <?php if ($lead['sla_deadline']): ?>
        <div style="text-align:right">
          <div style="font-size:12px;color:#64748b;font-weight:600;margin-bottom:4px">EXPECTED COMPLETION</div>
          <div style="font-size:14px;font-weight:700;color:var(--p)">
            <?= esc_html(date('d M Y', strtotime($lead['sla_deadline']))) ?>
          </div>
          <?php
          $slaDays = max(0, (int)((strtotime($lead['sla_deadline']) - time()) / 86400));
          $slaColor = $slaDays <= 0 ? '#dc2626' : ($slaDays <= 2 ? '#d97706' : '#16a34a');
          ?>
          <div style="font-size:11px;color:<?= $slaColor ?>;font-weight:600">
            <?= $slaDays <= 0 ? '⚠️ Overdue' : "{$slaDays} days remaining" ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Application details grid -->
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Service</div>
          <div class="info-value"><?= esc_html($lead['service_name'] ?? 'RTO Service') ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Category</div>
          <div class="info-value"><?= esc_html($lead['category'] ?? '—') ?></div>
        </div>
        <?php if ($lead['rto_state']): ?>
        <div class="info-item">
          <div class="info-label">State</div>
          <div class="info-value"><?= esc_html($lead['rto_state']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($lead['rto_office']): ?>
        <div class="info-item">
          <div class="info-label">RTO Office</div>
          <div class="info-value"><?= esc_html($lead['rto_office']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($lead['vehicle_number']): ?>
        <div class="info-item">
          <div class="info-label">Vehicle Number</div>
          <div class="info-value" style="font-family:monospace;font-size:16px;font-weight:800;color:var(--p)"><?= esc_html(strtoupper($lead['vehicle_number'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
          <div class="info-label">Applied On</div>
          <div class="info-value"><?= esc_html(date('d M Y', strtotime($lead['created_at']))) ?></div>
        </div>
        <?php if ($lead['challan_number']): ?>
        <div class="info-item">
          <div class="info-label">RTO Challan No.</div>
          <div class="info-value" style="font-family:monospace"><?= esc_html($lead['challan_number']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($lead['rto_submission_date']): ?>
        <div class="info-item">
          <div class="info-label">Submitted to RTO</div>
          <div class="info-value"><?= esc_html(date('d M Y', strtotime($lead['rto_submission_date']))) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Progress Timeline -->
  <div class="card">
    <div class="card-hdr">📊 Application Progress</div>
    <div class="card-body">
      <div class="timeline">
        <?php foreach ($statusOrder as $idx => $s):
          $info = $statusTimeline[$s];
          $isDone   = $idx < $currentIdx;
          $isActive = $idx === $currentIdx;
          $isFuture = $idx > $currentIdx;
          $cls  = $isDone ? 'done' : ($isActive ? 'active' : 'future');
        ?>
        <div class="tl-item <?= $cls ?>">
          <div class="tl-dot">
            <?php if ($isDone): ?>✓<?php elseif ($isActive): ?><?= $info['icon'] ?><?php else: ?>○<?php endif; ?>
          </div>
          <div class="tl-content">
            <div class="tl-label"><?= esc_html($info['label']) ?></div>
            <?php if ($isActive): ?>
            <div class="tl-desc"><?= esc_html($info['desc']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Help note -->
  <div style="background:#eff6ff;border-radius:10px;padding:14px 16px;font-size:13px;color:#1d4ed8;margin-bottom:20px">
    <strong>💡 Need help?</strong> If you have queries about your application, please contact us with your Application Number <strong><?= esc_html($lead['lead_number']) ?></strong>.
    <?php if (is_user_logged_in()): ?>
    <a href="<?= esc_url(home_url('/rto-dashboard/orders/' . $lead['id'])) ?>" style="margin-left:8px;font-weight:700;color:var(--p)">View Full Details →</a>
    <?php else: ?>
    <a href="<?= esc_url(home_url('/rto-login/')) ?>" style="margin-left:8px;font-weight:700;color:var(--p)">Login for full details →</a>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- No application found / initial state -->
  <div class="card">
    <div class="card-body">
      <div class="not-found">
        <div class="not-found-icon">🔍</div>
        <div class="not-found-title">
          <?= $token ? 'Application Not Found' : 'Enter Your Application Number' ?>
        </div>
        <div class="not-found-desc">
          <?= $token
            ? 'We couldn\'t find any application with this token. Please double-check the number or contact support.'
            : 'Enter your application number or tracking token above to see the current status of your RTO service request.' ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <p style="text-align:center;font-size:12px;color:#94a3b8;max-width:640px;margin:16px auto 0">
    Status shown reflects the latest update available from the RTO/authority for your application. Processing time,
    approval, and issuance are determined solely by them — we track and share what they report, we do not control it.
  </p>

  <!-- Contact card -->
  <div class="contact-box">
    <h3>Need Assistance?</h3>
    <p>Our expert team is available to help you with any RTO service queries.</p>
    <?php if ($tel): ?>
    <a href="tel:<?= esc_attr($tel) ?>">📞 Call <?= esc_html($tel) ?></a>
    <?php else: ?>
    <a href="<?= esc_url(home_url('/contact')) ?>">Contact Us →</a>
    <?php endif; ?>
  </div>

</main>

<footer style="background:var(--dk);color:rgba(255,255,255,.65);text-align:center;padding:12px 20px;font-size:12px">
  © <?= date('Y') ?> <?= esc_html($co) ?> ·
  <a href="<?= esc_url(home_url('/privacy-policy')) ?>" style="color:rgba(255,255,255,.65)">Privacy</a> ·
  <a href="<?= esc_url(home_url('/terms-conditions')) ?>" style="color:rgba(255,255,255,.65)">Terms</a>
</footer>

<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
