<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vendor Dashboard — RTOFLOW</title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<?php
// FIX (mobile pass follow-up): unlike jobs/show.php, profile/index.php and
// earnings/index.php, this dashboard page never went through
// layouts/vendor-header.php — it builds its own markup inline — so it was
// completely missed by the mobile bottom-nav/app-shell work even though
// it is the vendor's actual landing page. Wired in directly here (rather
// than switching this file to require the shared layout, which risks
// breaking whatever it currently expects from this page's own variables)
// so the vendor's most-visited screen gets the same mobile treatment as
// every other vendor page.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-vendor-body">
<div class="rto-client-wrap">
  <header class="rto-client-header rto-vendor-header">
    <div class="rto-client-header-inner">
      <a href="<?= esc_url(home_url('/')) ?>" class="rto-client-logo"><?= esc_html(get_option('rtoflow_company_name','RTOFLOW')) ?></a>
      <nav class="rto-client-nav">
        <a href="<?= esc_url(home_url('/rto-vendor/')) ?>" class="active">Dashboard</a>
        <a href="<?= esc_url(home_url('/rto-vendor/jobs/')) ?>">My Jobs</a>
        <a href="<?= esc_url(home_url('/rto-vendor/earnings/')) ?>">Earnings</a>
        <a href="<?= esc_url(home_url('/rto-vendor/profile/')) ?>">Profile</a>
        <span class="rto-client-user"><?= esc_html($vendor['full_name']) ?></span>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout-link">Logout</a>
      </nav>
    </div>
  </header>

  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="vendor">
    <a href="<?= esc_url(home_url('/rto-vendor/')) ?>" class="rto-bn-item active" data-rto-page="dashboard" aria-current="page">
      <span class="rto-bn-icon" aria-hidden="true">🏠</span><span class="rto-bn-label">Dashboard</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/jobs/')) ?>" class="rto-bn-item" data-rto-page="jobs">
      <span class="rto-bn-icon" aria-hidden="true">🧰</span><span class="rto-bn-label">Jobs</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/earnings/')) ?>" class="rto-bn-item" data-rto-page="earnings">
      <span class="rto-bn-icon" aria-hidden="true">💰</span><span class="rto-bn-label">Earnings</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/profile/')) ?>" class="rto-bn-item" data-rto-page="profile">
      <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Profile</span>
    </a>
  </nav>

  <main class="rto-client-main" id="rtoContentRegion" data-rto-page="dashboard">

    <!-- KPI row -->
    <div class="rto-client-kpi-row">
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= count($activeJobs) ?></div>
        <div class="rto-client-kpi-label">Active Jobs</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= number_format($completedMonth) ?></div>
        <div class="rto-client-kpi-label">Completed This Month</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= esc_html(rto_format_inr($earningsMonth)) ?></div>
        <div class="rto-client-kpi-label">Earnings This Month</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= esc_html(rto_format_inr($pendingPayout)) ?></div>
        <div class="rto-client-kpi-label">Pending Payout</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= number_format((float)$vendor['rating'], 1) ?> ⭐</div>
        <div class="rto-client-kpi-label">Rating</div>
      </div>
    </div>

    <!-- ENTERPRISE GAP FIX (Phase 4, item 4 — "no vendor performance
         scorecard visible to the vendor"): $performanceScore is the exact
         same VendorService::scoreVendor() output that drives this vendor's
         own job-matching priority — not a simplified re-derivation. -->
    <div class="rto-client-card rto-mb-4">
      <div class="rto-client-card-header"><h3>My Performance Scorecard</h3></div>
      <div class="rto-client-card-body">
        <p class="rto-small rto-muted" style="margin:0 0 14px">
          This is what drives how often you're offered new jobs — the same score the assignment engine uses, calculated from your rating, completion rate, acceptance rate, and current workload.
        </p>
        <div class="rto-client-kpi-row">
          <div class="rto-client-kpi">
            <div class="rto-client-kpi-value"><?= number_format((float)$vendor['completion_rate'], 1) ?>%</div>
            <div class="rto-client-kpi-label">Completion Rate</div>
          </div>
          <div class="rto-client-kpi">
            <div class="rto-client-kpi-value"><?= number_format((float)$vendor['acceptance_rate'], 1) ?>%</div>
            <div class="rto-client-kpi-label">Acceptance Rate</div>
          </div>
          <div class="rto-client-kpi">
            <div class="rto-client-kpi-value"><?= (int)$vendor['total_jobs'] ?></div>
            <div class="rto-client-kpi-label">Total Jobs Completed</div>
          </div>
          <div class="rto-client-kpi">
            <div class="rto-client-kpi-value"><?= $activeJobCount ?> / <?= (int)$maxActiveJobs ?></div>
            <div class="rto-client-kpi-label">Active Job Capacity</div>
          </div>
          <div class="rto-client-kpi">
            <div class="rto-client-kpi-value"><?= number_format((float)$performanceScore, 1) ?></div>
            <div class="rto-client-kpi-label">Overall Matching Score</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Pending job assignments that need a response -->
    <?php if (!empty($pendingJobs)): ?>
    <div class="rto-client-card rto-card-highlight">
      <div class="rto-client-card-header">
        <h3>🔔 New Job Assignments — Please Respond</h3>
      </div>
      <div class="rto-client-card-body">
        <?php foreach ($pendingJobs as $j): ?>
        <div class="rto-vendor-job-card rto-vendor-job-pending">
          <div class="rto-vendor-job-info">
            <strong><?= esc_html($j['lead_number']) ?></strong>
            <span><?= esc_html($j['service_name']) ?></span>
            <span><?= esc_html($j['city_name']) ?></span>
            <span class="rto-success"><strong>Your Earning: <?= esc_html(rto_format_inr(round((float)$j['total_amount'] * ((float)($j['vendor_share'] ?? 45) / 100), 2))) ?></strong></span>
            <span class="rto-muted">SLA: <?= esc_html(rto_date($j['sla_deadline'] ?? '')) ?></span>
          </div>
          <div class="rto-vendor-job-actions">
            <button class="rto-btn rto-btn-success job-accept-btn" data-id="<?= esc_attr($j['id']) ?>">✓ Accept</button>
            <button class="rto-btn rto-btn-danger job-reject-btn"  data-id="<?= esc_attr($j['id']) ?>">✗ Decline</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active Jobs -->
    <div class="rto-client-card">
      <div class="rto-client-card-header">
        <h3>Active Jobs</h3>
      </div>
      <?php if (empty($activeJobs)): ?>
      <div class="rto-client-empty">
        <div class="rto-client-empty-icon">🎉</div>
        <p>No active jobs right now. New assignments will appear here.</p>
      </div>
      <?php else: ?>
      <div class="rto-client-card-body rto-table-scroll">
        <table class="rto-table" data-rto-responsive="cards">
          <thead><tr><th>Order</th><th>Service</th><th>City</th><th>Status</th><th>SLA</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($activeJobs as $j): ?>
            <tr class="<?= $j['sla_breached'] ? 'rto-row-danger' : '' ?>">
              <td data-label="Order"><strong><?= esc_html($j['lead_number']) ?></strong></td>
              <td data-label="Service"><?= esc_html($j['service_name']) ?></td>
              <td data-label="City"><?= esc_html($j['city_name']) ?></td>
              <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($j['status'])) ?>"><?= esc_html(rto_status_label($j['status'])) ?></span></td>
              <td data-label="SLA" class="<?= $j['sla_breached'] ? 'rto-danger' : '' ?>"><?= esc_html(rto_date($j['sla_deadline'] ?? '')) ?></td>
              <td data-label="Actions"><a href="<?= esc_url(home_url('/rto-vendor/jobs/' . $j['id'])) ?>" class="rto-btn rto-btn-sm rto-btn-outline">View</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Recent jobs -->
    <?php if (!empty($recentJobs)): ?>
    <div class="rto-client-card">
      <div class="rto-client-card-header"><h3>Recent Activity</h3></div>
      <div class="rto-client-card-body rto-table-scroll">
        <table class="rto-table rto-table-sm" data-rto-responsive="cards">
          <thead><tr><th>Order</th><th>Service</th><th>Status</th><th>Last Updated</th></tr></thead>
          <tbody>
            <?php foreach ($recentJobs as $j): ?>
            <tr>
              <td data-label="Order"><?= esc_html($j['lead_number']) ?></td>
              <td data-label="Service"><?= esc_html($j['service_name']) ?></td>
              <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($j['status'])) ?>"><?= esc_html(rto_status_label($j['status'])) ?></span></td>
              <td data-label="Last Updated" class="rto-muted"><?= esc_html(rto_date($j['updated_at'] ?? $j['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </main>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var ajaxUrl  = "<?= esc_js(admin_url('admin-ajax.php')) ?>";
var jobNonce = "<?= esc_js(wp_create_nonce('rto_vendor_job')) ?>";

document.querySelectorAll('.job-accept-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.dataset.id;
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'rto_vendor',rto_area:'vendor',rto_action:'respond_job',assignment_id:id,action_type:'accept',rto_nonce:jobNonce})})
    .then(r=>r.json()).then(function(r){
      if(r.success){ alert(r.data.message||'Job accepted!'); location.reload(); }
      else { alert(r.data.message||'Error'); }
    });
  });
});

document.querySelectorAll('.job-reject-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.dataset.id;
    var reason = prompt('Please provide a reason for declining (required):');
    if(!reason){ return; }
    fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'rto_vendor',rto_area:'vendor',rto_action:'respond_job',assignment_id:id,action_type:'reject',reason:reason,rto_nonce:jobNonce})})
    .then(r=>r.json()).then(function(r){
      if(r.success){ alert(r.data.message||'Job declined.'); location.reload(); }
      else { alert(r.data.message||'Error'); }
    });
  });
});
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
</body>
</html>
