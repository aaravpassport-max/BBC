<?php
$pageTitle = 'Dashboard';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>

<!-- Known Limitations audit fix: "KPI numbers can lag reality by up to 3
     minutes ... staff have to guess whether a number is live or cached."
     A visible "as of HH:MM" timestamp (sourced from the actual cache-set
     time stored in the transient payload, not current time) plus a real
     "Refresh now" action that calls admin.dashboard_refresh over the
     standard AJAX dispatch table and repaints the numbers + timestamp in
     place, no full page reload. The ?refresh=1 GET fallback in
     DashboardController::index() still works for no-JS access. -->
<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:14px;font-size:12px;color:var(--gray-600)">
  <span id="rto-dash-asof">Data as of <?= esc_html(rto_date($computed_at, 'd M, g:i A')) ?></span>
  <button type="button" id="rto-dash-refresh" class="rto-btn rto-btn-sm" style="background:var(--gray-100);color:var(--gray-800)">↻ Refresh now</button>
</div>

<?php
// ENTERPRISE GAP FIX (Phase 9, item 5 — "No anomaly detection on the main
// dashboard ... KPI cards are purely descriptive with no threshold-based
// alerting"): a plain, rule-based alert list — see
// DashboardController::detectAnomalies() for the exact thresholds. Not a
// prediction, just fixed math, stated as such.
$anomalies = $anomalies ?? [];
?>
<div id="rto-dash-anomalies" <?= empty($anomalies) ? 'style="display:none"' : '' ?>>
  <?php foreach ($anomalies as $a): ?>
  <div class="rto-msg <?= $a['severity'] === 'high' ? 'rto-msg-error' : 'rto-msg-warning' ?>" style="margin-bottom:8px" role="status">
    ⚠ <?= esc_html($a['message']) ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- KPI Cards -->
<div class="rto-kpi-grid">
  <!-- Known Limitations audit fix: "No drill-down from a KPI card ...
       directly into a pre-filtered Leads list" — every card below is now a
       real link into /rto-admin/leads/ (or /rto-admin/vendors/,
       /rto-admin/complaints/) using the actual query-string parameter
       names those screens' own filter-handling code reads (verified in
       LeadsController::index() / VendorsController::index() /
       ComplaintsController::index()), not guessed names. Each rto-kpi-value
       carries a data-kpi id so the AJAX refresh below can repaint it in
       place without touching the link/href. -->
  <a href="<?= esc_url(home_url('/rto-admin/leads/')) ?>" class="rto-kpi-card rto-kpi-primary" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">📋</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="leads_month"><?= number_format($kpi['leads_month']) ?></div>
      <div class="rto-kpi-label">Leads This Month</div>
      <?php $g = $kpi['leads_growth']; ?>
      <div class="rto-kpi-change <?= $g >= 0 ? 'positive' : 'negative' ?>" data-kpi-change="leads_growth">
        <?= $g >= 0 ? '↑' : '↓' ?> <?= abs($g) ?>% vs last month
      </div>
    </div>
  </a>

  <a href="<?= esc_url(home_url('/rto-admin/leads/?date_from=' . rawurlencode(date('Y-m-01')) . '&date_to=' . rawurlencode(date('Y-m-d')))) ?>" class="rto-kpi-card rto-kpi-success" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">💰</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="revenue_month"><?= rto_format_inr($kpi['revenue_month']) ?></div>
      <div class="rto-kpi-label">Revenue This Month</div>
      <?php $g = $kpi['revenue_growth']; ?>
      <div class="rto-kpi-change <?= $g >= 0 ? 'positive' : 'negative' ?>" data-kpi-change="revenue_growth">
        <?= $g >= 0 ? '↑' : '↓' ?> <?= abs($g) ?>% vs last month
      </div>
    </div>
  </a>

  <a href="<?= esc_url(home_url('/rto-admin/vendors/?status=active&kyc=verified')) ?>" class="rto-kpi-card rto-kpi-info" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">👷</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="active_vendors"><?= number_format($kpi['active_vendors']) ?></div>
      <div class="rto-kpi-label">Active Vendors</div>
      <div class="rto-kpi-change neutral">KYC Verified · view list →</div>
    </div>
  </a>

  <a href="<?= esc_url(home_url('/rto-admin/leads/?status=created,payment_received')) ?>" class="rto-kpi-card <?= $kpi['pending_leads'] > 10 ? 'rto-kpi-warning' : 'rto-kpi-neutral' ?>" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">⏳</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="pending_leads"><?= number_format($kpi['pending_leads']) ?></div>
      <div class="rto-kpi-label">Pending Leads</div>
      <div class="rto-kpi-change neutral">Awaiting assignment · view list →</div>
    </div>
  </a>

  <a href="<?= esc_url(home_url('/rto-admin/leads/?sla_only=1')) ?>" class="rto-kpi-card <?= $kpi['sla_breached'] > 0 ? 'rto-kpi-danger' : 'rto-kpi-success' ?>" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">🚨</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="sla_breached"><?= number_format($kpi['sla_breached']) ?></div>
      <div class="rto-kpi-label">SLA Breached</div>
      <div class="rto-kpi-change <?= $kpi['sla_breached'] > 0 ? 'negative' : 'positive' ?>">
        <?= $kpi['sla_breached'] > 0 ? 'Needs immediate action · view list →' : 'All on track' ?>
      </div>
    </div>
  </a>

  <a href="<?= esc_url(home_url('/rto-admin/complaints/?status=open')) ?>" class="rto-kpi-card <?= $kpi['open_complaints'] > 5 ? 'rto-kpi-warning' : 'rto-kpi-neutral' ?>" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">📣</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="open_complaints"><?= number_format($kpi['open_complaints']) ?></div>
      <div class="rto-kpi-label">Open Complaints</div>
      <div class="rto-kpi-change neutral">Unresolved · view list →</div>
    </div>
  </a>

  <?php // ENTERPRISE GAP FIX (Phase 7, item 5 — "Complaints module has no
        // SLA tracking despite promising one ... no way for a manager to
        // see complaints past their promised window"): mirrors the lead SLA
        // tile above, same danger/success color rule. ?>
  <a href="<?= esc_url(home_url('/rto-admin/complaints/?status=open')) ?>" class="rto-kpi-card <?= $kpi['complaints_sla_breached'] > 0 ? 'rto-kpi-danger' : 'rto-kpi-success' ?>" style="text-decoration:none;color:inherit">
    <div class="rto-kpi-icon">⏱</div>
    <div class="rto-kpi-body">
      <div class="rto-kpi-value" data-kpi="complaints_sla_breached"><?= number_format($kpi['complaints_sla_breached']) ?></div>
      <div class="rto-kpi-label">Complaints Past SLA</div>
      <div class="rto-kpi-change <?= $kpi['complaints_sla_breached'] > 0 ? 'negative' : 'positive' ?>">
        <?= $kpi['complaints_sla_breached'] > 0 ? 'Past the 48-hour response window · view list →' : 'All within SLA' ?>
      </div>
    </div>
  </a>
</div>

<!-- Charts + Status row -->
<div class="rto-grid-2">
  <!-- Revenue chart -->
  <div class="rto-card">
    <div class="rto-card-header">
      <h3>Revenue — Last 6 Months</h3>
    </div>
    <div class="rto-card-body">
      <canvas id="revenueChart" height="220" role="img" aria-label="Bar chart showing monthly revenue for the last 6 months">
        <p class="rto-visually-hidden">Revenue chart data: see the monthly revenue table in Reports for accessible data.</p>
      </canvas>
    </div>
  </div>

  <!-- Status distribution -->
  <div class="rto-card">
    <div class="rto-card-header"><h3>Lead Status Distribution</h3></div>
    <div class="rto-card-body" id="rto-dash-status-dist">
      <?php foreach ($status_distribution as $row): ?>
      <!-- Known Limitations audit fix: each status bucket links straight into
           the Leads list pre-filtered to that exact status, via the real
           `status` query-string param LeadsController::index() reads. -->
      <a href="<?= esc_url(home_url('/rto-admin/leads/?status=' . rawurlencode($row['status']))) ?>" class="rto-status-row" style="text-decoration:none;color:inherit">
        <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($row['status'])) ?>">
          <?= esc_html(rto_status_label($row['status'])) ?>
        </span>
        <div class="rto-progress-bar">
          <?php $pct = $kpi['total_leads'] > 0 ? round($row['count'] / $kpi['total_leads'] * 100) : 0; ?>
          <div class="rto-progress-fill" role="progressbar" style="width:<?= $pct ?>%" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= esc_attr(rto_status_label($row['status'])) ?>: <?= $pct ?>% of all leads (<?= number_format($row['count']) ?> orders)"></div>
        </div>
        <span class="rto-status-count"><?= number_format($row['count']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- SLA Alerts -->
<?php if (!empty($sla_leads)): ?>
<div class="rto-card rto-card-danger">
  <div class="rto-card-header">
    <h3>🚨 SLA Breached — Immediate Action Required (<?= count($sla_leads) ?>)</h3>
  </div>
  <div class="rto-card-body rto-table-scroll">
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr>
        <th scope="col">Order</th><th scope="col">Service</th><th scope="col">Client</th><th scope="col">Vendor</th>
        <th scope="col">SLA Deadline</th><th scope="col">Status</th><th scope="col"><span class="rto-visually-hidden">Actions</span></th>
      </tr></thead>
      <tbody>
        <?php foreach ($sla_leads as $l): ?>
        <tr>
          <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/' . $l['id'])) ?>" class="rto-link"><?= esc_html($l['lead_number']) ?></a></td>
          <td data-label="Service"><?= esc_html($l['service_name']) ?></td>
          <td data-label="Client"><?= esc_html($l['client_name']) ?></td>
          <td data-label="Vendor"><?= esc_html($l['vendor_name'] ?? '— Unassigned') ?></td>
          <td data-label="SLA Deadline" class="rto-danger"><?= esc_html(rto_date($l['sla_deadline'], 'd M Y H:i')) ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-danger"><?= esc_html(rto_status_label($l['status'])) ?></span></td>
          <td data-label="Actions"><a href="<?= esc_url(home_url('/rto-admin/leads/' . $l['id'])) ?>" class="rto-btn rto-btn-sm rto-btn-danger">Act Now</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Two column: Recent Leads + Top Services -->
<div class="rto-grid-2">
  <div class="rto-card">
    <div class="rto-card-header">
      <h3>Recent Leads</h3>
      <a href="<?= esc_url(home_url('/rto-admin/leads/')) ?>" class="rto-btn rto-btn-sm rto-btn-outline">View All</a>
    </div>
    <div class="rto-card-body rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Order</th><th>Service</th><th>Client</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recent_leads as $l): ?>
          <tr>
            <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/' . $l['id'])) ?>" class="rto-link"><?= esc_html($l['lead_number']) ?></a></td>
            <td data-label="Service"><?= esc_html($l['service_name']) ?></td>
            <td data-label="Client"><?= esc_html($l['client_name']) ?></td>
            <td data-label="Amount"><?= esc_html(rto_format_inr((float)$l['total_amount'])) ?></td>
            <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($l['status'])) ?>"><?= esc_html(rto_status_label($l['status'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Top Services</h3></div>
    <div class="rto-card-body">
      <?php foreach ($top_services as $i => $svc): ?>
      <div class="rto-top-service-row">
        <span class="rto-rank"><?= $i + 1 ?></span>
        <div class="rto-top-service-info">
          <div class="rto-top-service-name"><?= esc_html($svc['name']) ?></div>
          <div class="rto-top-service-meta"><?= number_format($svc['count']) ?> orders · <?= rto_format_inr((float)$svc['revenue']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  // Revenue chart using Chart.js
  var ctx = document.getElementById('revenueChart');
  if (!ctx) return;
  var labels  = <?= wp_json_encode(array_column($revenue_chart, 'month')) ?>;
  var revenue = <?= wp_json_encode(array_map(fn($r) => (float)$r['revenue'], $revenue_chart)) ?>;
  var primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--rto-primary') || '#1B2A6B';
  var secondaryColor = getComputedStyle(document.documentElement).getPropertyValue('--rto-secondary') || '#E97B28';

  // Load Chart.js dynamically then render
  if (typeof Chart === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
    script.onload = function() { renderRevenueChart(ctx, labels, revenue); };
    document.head.appendChild(script);
  } else {
    renderRevenueChart(ctx, labels, revenue);
  }
})();

function renderRevenueChart(canvas, labels, revenue) {
  var formattedLabels = labels.map(function(l) {
    var parts = l.split('-');
    var months = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(parts[1]||1)] + ' ' + (parts[0]||'').substring(2);
  });
  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: formattedLabels,
      datasets: [{
        label: 'Revenue (₹)',
        data: revenue,
        backgroundColor: 'rgba(27,42,107,0.85)',
        borderColor: '#1B2A6B',
        borderWidth: 1,
        borderRadius: 5,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              return '₹ ' + ctx.parsed.y.toLocaleString('en-IN');
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#f0f0f0' },
          ticks: {
            callback: function(val) {
              if (val >= 100000) return '₹' + (val/100000).toFixed(1) + 'L';
              if (val >= 1000) return '₹' + (val/1000).toFixed(0) + 'K';
              return '₹' + val;
            },
            font: { size: 11 }
          }
        },
        x: {
          grid: { display: false },
          ticks: { font: { size: 11 } }
        }
      }
    }
  });
}

// Known Limitations audit fix: real "Refresh now" — busts the dashboard
// transient server-side via admin.dashboard_refresh (standard rto_admin
// AJAX dispatch table, nonce-gated) and repaints the KPI numbers and the
// "as of" timestamp in place, no full page reload.
(function(){
  var btn = document.getElementById('rto-dash-refresh');
  if (!btn) return;
  function fmtInr(n){
    n = Number(n) || 0;
    if (n >= 100000) return '₹' + (n/100000).toFixed(1).replace(/\.0$/, '') + 'L';
    return '₹' + n.toLocaleString('en-IN', {maximumFractionDigits: 0});
  }
  btn.addEventListener('click', function(){
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Refreshing…';
    var fd = new FormData();
    fd.append('action', 'rto_admin');
    fd.append('rto_area', 'admin');
    fd.append('rto_action', 'dashboard_refresh');
    fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
    fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
      method: 'POST', credentials: 'same-origin', body: fd
    })
      .then(function(r){ if(!r.ok) throw new Error('Server error ' + r.status); return r.json(); })
      .then(function(r){
        if (!r.success) { (window.rtoToast||alert)(r.data ? r.data.message : 'Refresh failed.', 'error'); return; }
        var kpi = r.data.kpi;
        document.querySelectorAll('[data-kpi]').forEach(function(el){
          var key = el.getAttribute('data-kpi');
          if (!(key in kpi)) return;
          el.textContent = (key === 'revenue_month') ? fmtInr(kpi[key]) : Number(kpi[key]).toLocaleString('en-IN');
        });
        ['leads_growth','revenue_growth'].forEach(function(key){
          var el = document.querySelector('[data-kpi-change="' + key + '"]');
          if (!el) return;
          var g = Number(kpi[key]) || 0;
          el.classList.toggle('positive', g >= 0);
          el.classList.toggle('negative', g < 0);
          el.textContent = (g >= 0 ? '↑ ' : '↓ ') + Math.abs(g) + '% vs last month';
        });
        var asof = document.getElementById('rto-dash-asof');
        if (asof && r.data.computed_at_label) asof.textContent = 'Data as of ' + r.data.computed_at_label;
        // ENTERPRISE GAP FIX (Phase 9, item 5 — dashboard anomaly
        // detection): repaint the alert list from the freshly recomputed
        // data too, so a "Refresh now" click can also clear a stale alert.
        var anomBox = document.getElementById('rto-dash-anomalies');
        if (anomBox) {
          var list = r.data.anomalies || [];
          anomBox.innerHTML = '';
          list.forEach(function (a) {
            var div = document.createElement('div');
            div.className = 'rto-msg ' + (a.severity === 'high' ? 'rto-msg-error' : 'rto-msg-warning');
            div.style.marginBottom = '8px';
            div.setAttribute('role', 'status');
            div.textContent = '⚠ ' + a.message;
            anomBox.appendChild(div);
          });
          anomBox.style.display = list.length ? '' : 'none';
        }
        (window.rtoToast||function(){})('Dashboard refreshed.', 'success');
      })
      .catch(function(){ (window.rtoToast||alert)('Request failed. Please check your connection.', 'error'); })
      .finally(function(){ btn.disabled = false; btn.textContent = originalText; });
  });
})();
</script>

<?php /*
  Part 5.9 CSS audit FIX (real, severe structural bug, found by reading this
  view file's actual raw output end to end, not by class-existence check):
  everything from here down to the removed second admin-footer.php require
  below was leftover dead code from an old hand-rolled <canvas> renderer
  that predates the Chart.js implementation above. It survived the switch
  to Chart.js and was never deleted, and it had three compounding real
  defects: (1) a stray `</script>`-less JS fragment (`ctx.width = ...` down
  to `})();`) sat as literal PHP-output text AFTER the page's real
  `</html>` close (the require below used to come first) — browsers append
  stray post-</html> content into the body, so every real admin dashboard
  load was silently rendering raw, unexecuted JavaScript source as visible
  page text; (2) `rto_help_box('dashboard')` ran a second time, after that
  garbage text; (3) `admin-footer.php` — which emits a second
  `<script src=".../admin.js">` tag and a second `</body></html>` — was
  required TWICE, meaning admin.js's DOMContentLoaded-bound sidebar-toggle
  and other admin.js event handlers were registered twice on the same
  Dashboard page load. This was a genuine, previously-undetected structural
  defect on the single highest-traffic admin screen, not a missing-CSS-rule
  case — the CSS itself was never wrong here, the DOM/script duplication
  was. Fixed by deleting the dead second copy entirely and keeping exactly
  one `rto_help_box()` call plus one footer require, matching the
  established convention in every other admin view (see leads/index.php:234,
  vendors/index.php:117).
*/ ?>
<?php rto_help_box('dashboard'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
