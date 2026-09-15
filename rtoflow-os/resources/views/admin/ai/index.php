<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$p = $wpdb->prefix;

/** @var string $from */
/** @var string $to */
$from = $from ?? '';
$to   = $to   ?? '';

// Risk score distribution
$risk_dist = $wpdb->get_results(
    "SELECT
        CASE WHEN risk_score >= 80 THEN 'High Risk (80-100)'
             WHEN risk_score >= 50 THEN 'Medium Risk (50-79)'
             ELSE 'Low Risk (0-49)' END as label,
        COUNT(*) as count
     FROM {$p}rto_leads WHERE deleted_at IS NULL GROUP BY 1 ORDER BY MIN(risk_score) DESC",
    ARRAY_A
) ?: [];

// SLA performance
$sla_total   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE deleted_at IS NULL");
$sla_breached= (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE sla_breached=1");
$sla_rate    = $sla_total > 0 ? round((1 - $sla_breached / $sla_total) * 100, 1) : 100;

// Known Limitations audit fix: "No date-range filter exists anywhere on
// this screen." Top Converting Services and Monthly Trend now respect an
// optional From/To range (see the filter form below) instead of always
// being all-time / a fixed 6-month window — falling back to exactly their
// previous behavior when no range is chosen, so this is purely additive.
$svcWhere = "l.status='completed' AND l.deleted_at IS NULL";
$svcParams = [];
if ($from) { $svcWhere .= " AND l.created_at >= %s"; $svcParams[] = $from . ' 00:00:00'; }
if ($to)   { $svcWhere .= " AND l.created_at <= %s"; $svcParams[] = $to   . ' 23:59:59'; }
$svcSql = "SELECT s.name, COUNT(l.id) as orders, COALESCE(SUM(l.paid_amount),0) as revenue
     FROM {$p}rto_leads l
     LEFT JOIN {$p}rto_services s ON s.id=l.service_id
     WHERE {$svcWhere}
     GROUP BY l.service_id ORDER BY orders DESC LIMIT 8";
$top_svcs = $wpdb->get_results($svcParams ? $wpdb->prepare($svcSql, $svcParams) : $svcSql, ARRAY_A) ?: [];

// Monthly trend — defaults to the last 6 months exactly as before when no
// range is chosen; an explicit From/To replaces that window entirely.
$trendWhere = "deleted_at IS NULL";
$trendParams = [];
if ($from || $to) {
    if ($from) { $trendWhere .= " AND created_at >= %s"; $trendParams[] = $from . ' 00:00:00'; }
    if ($to)   { $trendWhere .= " AND created_at <= %s"; $trendParams[] = $to   . ' 23:59:59'; }
} else {
    $trendWhere .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
}
// %b/%Y are literal MySQL DATE_FORMAT specifiers, not $wpdb->prepare()
// placeholders — doubled here because prepare() itself treats a bare %
// as one of its own format tokens regardless of whether $trendParams is
// non-empty, so an unescaped %b/%Y would otherwise corrupt the query the
// moment a date range makes this go through prepare().
$trendSql = "SELECT DATE_FORMAT(created_at,'%%b %%Y') as month, COUNT(*) as leads, COALESCE(SUM(paid_amount),0) as revenue
     FROM {$p}rto_leads WHERE {$trendWhere}
     GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY MIN(created_at)";
$monthly = $wpdb->get_results($trendParams ? $wpdb->prepare($trendSql, $trendParams) : str_replace('%%', '%', $trendSql), ARRAY_A) ?: [];
?>

<div class="rto-page-header">
  <div>
    <h2>AI Insights & Analytics</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">System performance metrics, risk analysis, and trend data</p>
  </div>
</div>

<!-- ENTERPRISE GAP FIX (Phase 1, item 4 — "AI Insights is a mislabeled BI
     dashboard, not AI"): this was previously disclosed honestly only in the
     tooltip help content (rto_help_box('ai')), which a viewer has to click
     to open — the screen itself said nothing. A due-diligence reviewer, or
     a staff member relying on this for a real decision, could reasonably
     read "AI Insights" as model-driven risk scoring. This banner states the
     real mechanism in the one place everyone actually sees: the top of the
     page itself, not a click-through. -->
<div class="rto-card" style="margin-bottom:16px;border-left:3px solid #f59e0b;background:#fffbeb">
  <div class="rto-card__body" style="padding:10px 14px;font-size:12.5px;color:#78350f">
    <strong>How Risk Score is actually calculated:</strong> this is a simple, transparent rule-based point system (e.g. +10 for a new client, +15 for a high order value, +20 for a high-risk service category, up to +25 for SLA urgency — capped at 100) — not a trained machine-learning model, no training data, no prediction pipeline. "Re-score All Leads Now" re-runs these same fixed rules against current data. Treat it as a configurable risk signal, not a probabilistic forecast.
  </div>
</div>

<!-- Known Limitations audit fix: "No date-range filter exists anywhere on
     this screen." Applies to Monthly Trend and Top Converting Services
     only — Risk Score Distribution and the KPI cards above stay all-time
     by design, matching this screen's documented scope. -->
<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="rto_area" value="admin">
      <input type="hidden" name="rto_page" value="ai">
      <label class="rto-small rto-muted" for="aiFrom">From</label>
      <input type="date" id="aiFrom" name="date_from" class="rto-input" value="<?= esc_attr($from) ?>">
      <label class="rto-small rto-muted" for="aiTo">To</label>
      <input type="date" id="aiTo" name="date_to" class="rto-input" value="<?= esc_attr($to) ?>">
      <button type="submit" class="rto-btn rto-btn--primary rto-btn--sm">Apply</button>
      <?php if ($from || $to): ?>
      <a href="<?= esc_url(home_url('/rto-admin/ai/')) ?>" class="rto-btn rto-btn--sm">Reset (last 6 months)</a>
      <?php endif; ?>
      <span class="rto-small rto-muted">Applies to Monthly Trend and Top Converting Services below — defaults to the last 6 months when empty.</span>
    </form>
  </div>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
  <?php
  $kpis = [
    ['SLA Compliance', $sla_rate . '%', $sla_rate >= 90 ? '#16A34A' : ($sla_rate >= 70 ? '#D97706' : '#DC2626'), '📊'],
    ['Breached SLAs', number_format($sla_breached), '#DC2626', '⚠'],
    ['Total Leads', number_format($sla_total), '#2563EB', '📋'],
    ['Completion Rate', $sla_total > 0 ? round((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE status='completed'")/max(1,$sla_total)*100,1).'%' : '0%', '#7C3AED', '✅'],
  ];
  foreach ($kpis as [$label,$val,$color,$icon]): ?>
  <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:20px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05)">
    <div style="font-size:28px;margin-bottom:8px"><?= $icon ?></div>
    <div style="font-size:24px;font-weight:900;color:<?= $color ?>;line-height:1"><?= esc_html($val) ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600"><?= esc_html($label) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Monthly Trend -->
  <div class="rto-card">
    <div class="rto-card__header"><h3>Monthly Lead & Revenue Trend</h3></div>
    <div class="rto-card__body" style="padding:0">
      <?php if (empty($monthly)): ?>
      <div style="padding:40px;text-align:center;color:#94a3b8">No data yet</div>
      <?php else: ?>
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Month</th><th>Leads</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($monthly as $m): ?>
          <tr>
            <td data-label="Month" style="font-weight:600"><?= esc_html($m['month']) ?></td>
            <td data-label="Leads"><?= number_format((int)$m['leads']) ?></td>
            <td data-label="Revenue" style="font-weight:700;color:#2563EB">₹<?= number_format((float)$m['revenue'], 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Top Services -->
  <div class="rto-card">
    <div class="rto-card__header"><h3>Top Completing Services</h3></div>
    <div class="rto-card__body" style="padding:0">
      <?php if (empty($top_svcs)): ?>
      <div style="padding:40px;text-align:center;color:#94a3b8">No completed orders yet</div>
      <?php else: ?>
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Service</th><th>Orders</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($top_svcs as $s): ?>
          <tr>
            <td data-label="Service" style="font-size:12px;max-width:160px"><?= esc_html($s['name']) ?></td>
            <td data-label="Orders"><span style="background:#EFF6FF;color:#2563EB;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700"><?= (int)$s['orders'] ?></span></td>
            <td data-label="Revenue" style="font-weight:700;color:#2563EB;font-size:12px">₹<?= number_format((float)$s['revenue'], 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Risk Distribution -->
  <div class="rto-card">
    <div class="rto-card__header" style="display:flex;align-items:center;justify-content:space-between">
      <h3>Risk Score Distribution</h3>
      <!-- Known Limitations audit fix: the scorer previously only ever ran
           once, at lead creation — this re-runs the exact same rules
           against every lead's CURRENT data (order history, amount,
           service) instead of leaving old leads permanently unscored or
           stuck with a stale score. -->
      <button type="button" id="rescoreLeadsBtn" class="rto-btn rto-btn--sm" title="Re-run the risk scorer against every lead's current data">Re-score All Leads Now</button>
    </div>
    <div id="rescoreMsg" class="rto-msg" style="display:none;margin:0 16px 10px"></div>
    <div class="rto-card__body">
      <?php if (empty($risk_dist)): ?>
      <p style="color:#94a3b8;text-align:center;padding:20px">No leads to analyse yet</p>
      <?php else: ?>
      <?php foreach ($risk_dist as $r):
        $pct = $sla_total > 0 ? round($r['count'] / $sla_total * 100) : 0;
        $bg  = str_contains($r['label'], 'High') ? '#FEE2E2' : (str_contains($r['label'], 'Medium') ? '#FEF3C7' : '#DCFCE7');
        $fg  = str_contains($r['label'], 'High') ? '#DC2626' : (str_contains($r['label'], 'Medium') ? '#D97706' : '#16A34A');
      ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px;font-size:13px">
          <span style="font-weight:600;color:<?= $fg ?>"><?= esc_html($r['label']) ?></span>
          <span style="font-weight:700"><?= number_format((int)$r['count']) ?> (<?= $pct ?>%)</span>
        </div>
        <div style="background:#f3f4f6;border-radius:6px;height:10px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:<?= $fg ?>;height:100%;border-radius:6px;transition:width .3s"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- System Health -->
  <div class="rto-card">
    <div class="rto-card__header"><h3>System Health</h3></div>
    <div class="rto-card__body">
      <?php
      global $wpdb;
      $checks = [
        ['Database connection', (bool)$wpdb->get_var('SELECT 1'), true],
        ['SLA cron scheduled', (bool)wp_next_scheduled('rtoflow_sla_check'), true],
        ['Notification queue', (bool)wp_next_scheduled('rtoflow_notification_queue'), false],
        ['Notification templates', (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_notification_templates") >= 3, true],
        ['Services seeded', (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_services") >= 10, true],
        ['Cities seeded', (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_cities WHERE is_active=1") >= 20, true],
      ];
      foreach ($checks as [$label, $ok, $required]): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9">
        <span style="font-size:13px"><?= esc_html($label) ?><?= !$required ? ' <span style="font-size:10px;color:#94a3b8">(optional)</span>' : '' ?></span>
        <span style="font-size:14px"><?= $ok ? '✅' : ($required ? '❌' : '⚠') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('rescoreLeadsBtn')?.addEventListener('click', function() {
  var btn = this;
  if (!confirm('Re-score every lead against its current data now? This updates risk_score for every non-deleted lead and cannot be undone.')) return;
  btn.disabled = true;
  btn.textContent = 'Re-scoring…';
  var fd = new FormData();
  fd.append('action', 'rto_admin');
  fd.append('rto_area', 'admin');
  fd.append('rto_action', 'rescore_leads');
  fd.append('rto_nonce', (window.rtoflowAdmin || {}).nonce || '');
  fetch((window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php', {
    method: 'POST', credentials: 'same-origin', body: fd
  })
    .then(function(r) { return r.json(); })
    .then(function(r) {
      var box = document.getElementById('rescoreMsg');
      box.className = 'rto-msg rto-msg-' + (r.success ? 'success' : 'error');
      box.textContent = r.message || (r.success ? 'Done.' : 'Failed.');
      box.style.display = '';
      if (r.success) { setTimeout(function() { location.reload(); }, 1200); }
      else { btn.disabled = false; btn.textContent = 'Re-score All Leads Now'; }
    })
    .catch(function() {
      var box = document.getElementById('rescoreMsg');
      box.className = 'rto-msg rto-msg-error';
      box.textContent = 'Request failed.';
      box.style.display = '';
      btn.disabled = false;
      btn.textContent = 'Re-score All Leads Now';
    });
});
</script>
