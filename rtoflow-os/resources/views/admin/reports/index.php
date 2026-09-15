<?php if (!defined('ABSPATH')) exit;
/** @var string $report @var string $from @var string $to @var array $data
 * @var string $funnelCategory @var array $funnelCategories
 * ENTERPRISE GAP FIX (Phase 3, item 7 — funnel report): $funnelCategory/
 * $funnelCategories back the new Funnel tab below — see
 * ReportsController::funnelReport() and FormFunnelService. */
$pageTitle = 'Reports';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$reports = ['revenue'=>'Revenue','leads'=>'Lead Analytics','vendors'=>'Vendor Performance','services'=>'Service Performance','funnel'=>'Form Funnel','cohort'=>'Cohort & Retention'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Reports</h1>
    <div style="display:flex;gap:8px">
      <?php if (!in_array($report, ['funnel','cohort'], true)): ?>
      <button type="button" id="saveSnapshotBtn" class="rto-btn rto-btn-outline">📌 Save Snapshot</button>
      <a href="<?= esc_url(add_query_arg(array_merge($_GET,['export'=>'csv']))) ?>" class="rto-btn rto-btn-outline">⬇ Export CSV</a>
      <?php endif; ?>
    </div>
  </div>

  <div id="snapMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <?php if (!empty($existingSnapshots)): ?>
  <!-- Known Limitations audit fix: "Reports are never snapshotted — the
       same date range can return different numbers on a later run."
       Surfacing prior saves for this exact report+range so staff know a
       durable, exactly-reproducible copy already exists before assuming
       they need to re-export a CSV to "lock in" a figure. -->
  <div class="rto-msg rto-msg-info rto-mb-4">
    <strong>Saved snapshots for this exact range:</strong>
    <?php foreach ($existingSnapshots as $snap): ?>
    <a href="<?= esc_url(add_query_arg(['snapshot' => (int)$snap['id']])) ?>" style="margin-left:8px">
      <?= esc_html($snap['label'] ?: rto_date($snap['created_at'])) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="reports">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="repType">Report</label>
        <select id="repType" name="report" class="rto-select">
          <?php foreach ($reports as $k=>$l): ?>
          <option value="<?= esc_attr($k) ?>" <?= $report===$k?'selected':'' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php
      // Known Limitations audit fix: "The Revenue report's date range
      // applies to payment date, while the Leads report's applies to
      // lead-creation date — this distinction is not surfaced on the
      // screen itself ... comparing the two for the 'same' date range
      // produces numbers that look inconsistent." Each report type
      // actually queries a different table's own created_at column
      // (ReportsController) — this label now says which one, per report
      // type, instead of a generic "From"/"To" that implies they all mean
      // the same thing.
      $dateBasisLabel = $report === 'revenue' ? 'Payment Date' : 'Lead Created Date';
      ?>
      <?php if ($report === 'funnel'): ?>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="repFunnelCategory">Service Category<?= rto_field_tooltip('The funnel report is all-time (not filtered by the date range above) — it reflects every recorded form session for this category since tracking began.') ?></label>
        <select id="repFunnelCategory" name="funnel_category" class="rto-select">
          <?php foreach ($funnelCategories as $catKey => $cat): ?>
          <option value="<?= esc_attr($catKey) ?>" <?= $funnelCategory === $catKey ? 'selected' : '' ?>><?= esc_html($cat['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($report === 'cohort'): ?>
      <div class="rto-form-group" style="flex:1">
        <p class="rto-small rto-muted" style="margin:0"><?= rto_field_tooltip('The Cohort & Retention report is all-time (not filtered by the date range above) — a cohort\'s repeat-order rate needs the client\'s full order history, not an arbitrary window.') ?> This report is always all-time.</p>
      </div>
      <?php else: ?>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="repFrom">From (<?= esc_html($dateBasisLabel) ?>)</label>
        <input type="date" id="repFrom" name="date_from" class="rto-input" value="<?= esc_attr($from) ?>">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="repTo">To (<?= esc_html($dateBasisLabel) ?>)</label>
        <input type="date" id="repTo" name="date_to" class="rto-input" value="<?= esc_attr($to) ?>">
      </div>
      <?php endif; ?>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Run Report</button>
      </div>
    </div>
  </form>

  <?php if (!empty($data['totals'])): ?>
  <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <?php foreach ($data['totals'] as $k=>$v): ?>
    <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
      <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= is_numeric($v) && $v > 100 ? esc_html(rto_format_inr((float)$v)) : esc_html($v) ?></div>
      <div class="rto-small rto-muted"><?= esc_html(ucwords(str_replace('_',' ',$k))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($report === 'funnel'): ?>
  <div class="rto-card">
    <div class="rto-card-header"><h3>Form Funnel — <?= esc_html($funnelCategories[$funnelCategory]['title'] ?? $funnelCategory) ?></h3></div>
    <?php if (empty($data['summary']['step_reached'])): ?>
    <div class="rto-empty-state"><p>No form-funnel events recorded yet for this category.</p></div>
    <?php else: ?>
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;padding:16px 16px 0">
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= (int)$data['summary']['step_reached'] ?></div>
        <div class="rto-small rto-muted">Sessions Started</div>
      </div>
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= (int)$data['summary']['submitted'] ?></div>
        <div class="rto-small rto-muted">Submitted</div>
      </div>
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:<?= ($data['summary']['abandonment_rate'] ?? 0) > 50 ? 'var(--red)' : 'var(--navy)' ?>"><?= $data['summary']['abandonment_rate'] !== null ? esc_html($data['summary']['abandonment_rate']) . '%' : '—' ?></div>
        <div class="rto-small rto-muted">Abandonment Rate</div>
      </div>
    </div>

    <div style="padding:0 16px 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <h4 class="rto-small" style="text-transform:uppercase;color:var(--muted);margin-bottom:8px">Drop-off by Step</h4>
        <?php if (empty($data['drop_off_by_step'])): ?>
        <p class="rto-small rto-muted">No step data recorded.</p>
        <?php else: ?>
        <table class="rto-table"><thead><tr><th>Step</th><th>Sessions Reached</th></tr></thead><tbody>
        <?php foreach ($data['drop_off_by_step'] as $step): ?>
        <tr><td>Step <?= (int)$step['step_index'] ?></td><td><?= (int)$step['sessions_reached'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
      <div>
        <h4 class="rto-small" style="text-transform:uppercase;color:var(--muted);margin-bottom:8px">Top Validation Failures</h4>
        <?php if (empty($data['validation_failures'])): ?>
        <p class="rto-small rto-muted">No validation failures recorded.</p>
        <?php else: ?>
        <table class="rto-table"><thead><tr><th>Field</th><th>Failures</th></tr></thead><tbody>
        <?php foreach ($data['validation_failures'] as $vf): ?>
        <tr><td><?= esc_html($vf['field_key']) ?></td><td><?= (int)$vf['failure_count'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($report === 'cohort'): ?>
  <!-- ENTERPRISE GAP FIX (Phase 9, item 4 — cohort/churn/CLV report) -->
  <div class="rto-card" style="margin-bottom:16px">
    <div class="rto-card-header"><h3>Overall Repeat-Order Rate</h3></div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;padding:16px">
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= (int)$data['overall']['total_clients'] ?></div>
        <div class="rto-small rto-muted">Total Clients</div>
      </div>
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= (int)$data['overall']['repeat_clients'] ?></div>
        <div class="rto-small rto-muted">Repeat Clients</div>
      </div>
      <div class="rto-card" style="flex:1;min-width:150px;padding:16px;text-align:center">
        <div style="font-size:20px;font-weight:700;color:var(--navy)"><?= esc_html($data['overall']['overall_repeat_rate']) ?>%</div>
        <div class="rto-small rto-muted">Repeat-Order Rate</div>
      </div>
    </div>
  </div>

  <div class="rto-card" style="margin-bottom:16px">
    <div class="rto-card-header"><h3>Acquisition Cohorts (last 12 months)</h3></div>
    <?php if (empty($data['cohorts'])): ?>
    <div class="rto-empty-state"><p>No cohort data yet.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th scope="col">Cohort Month</th><th scope="col">Cohort Size</th><th scope="col">Repeat Customers</th><th scope="col">Repeat Rate</th></tr></thead>
        <tbody>
        <?php foreach ($data['cohorts'] as $c): ?>
        <tr>
          <td data-label="Cohort Month"><?= esc_html($c['cohort_month']) ?></td>
          <td data-label="Cohort Size"><?= (int)$c['cohort_size'] ?></td>
          <td data-label="Repeat Customers"><?= (int)$c['repeat_customers'] ?></td>
          <td data-label="Repeat Rate"><?= esc_html($c['repeat_rate']) ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>At-Risk / Lapsed Customers <span class="rto-small rto-muted">(no order in 90+ days, ranked by lifetime value)</span></h3></div>
    <?php if (empty($data['lapsed'])): ?>
    <div class="rto-empty-state"><p>No lapsed customers found.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th scope="col">Client</th><th scope="col">Total Orders</th><th scope="col">Last Order</th><th scope="col">Lifetime Value (₹)</th></tr></thead>
        <tbody>
        <?php foreach ($data['lapsed'] as $l): ?>
        <tr>
          <td data-label="Client"><?= esc_html($l['client_name'] ?: ('Client #' . (int)$l['client_id'])) ?></td>
          <td data-label="Total Orders"><?= (int)$l['total_orders'] ?></td>
          <td data-label="Last Order"><?= esc_html(rto_date($l['last_order'])) ?></td>
          <td data-label="Lifetime Value (₹)"><?= esc_html(rto_format_inr((float)$l['lifetime_value'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="rto-card">
    <div class="rto-card-header">
      <h3><?= esc_html($reports[$report] ?? 'Report') ?> — <?= esc_html($from) ?> to <?= esc_html($to) ?></h3>
    </div>
    <?php if (empty($data['rows'])): ?>
    <div class="rto-empty-state"><p>No data found for this period.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><?php foreach ($data['columns'] as $col): ?><th scope="col"><?= esc_html($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php $cols = array_values($data['columns']); ?>
        <?php foreach ($data['rows'] as $row): ?>
        <tr>
          <?php foreach (array_values($row) as $i=>$val): ?>
          <td data-label="<?= esc_attr($cols[$i] ?? '') ?>"><?php
            if (is_numeric($val) && $val > 1000 && $i > 0) echo esc_html(rto_format_inr((float)$val));
            else echo esc_html($val);
          ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php // ENTERPRISE GAP FIX (Phase 7, item 7 — "Vendor reports are
        // single-snapshot with no trendline; no city-wise or margin/
        // profitability breakdown exists"): city_breakdown/trend only
        // appear on the reports that now compute them (vendors/services). ?>
  <?php if ($report === 'vendors' && !empty($data['trend'])): ?>
  <div class="rto-card">
    <div class="rto-card-header"><h3>Volume Trend — Last 6 Months</h3></div>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover">
        <thead><tr><th scope="col">Month</th><th scope="col">Total Jobs</th><th scope="col">Completed</th></tr></thead>
        <tbody>
        <?php foreach ($data['trend'] as $t): ?>
        <tr><td data-label="Month"><?= esc_html($t['month']) ?></td><td data-label="Total Jobs"><?= (int)$t['total'] ?></td><td data-label="Completed"><?= (int)$t['completed'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (in_array($report, ['vendors','services'], true) && !empty($data['city_breakdown'])): ?>
  <div class="rto-card">
    <div class="rto-card-header"><h3>City-wise Breakdown (Top 10 by volume)</h3></div>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover">
        <thead><tr>
          <th scope="col">City</th>
          <?php if ($report === 'vendors'): ?>
          <th scope="col">Total</th><th scope="col">Completed</th><th scope="col">Active Vendors</th>
          <?php else: ?>
          <th scope="col">Orders</th><th scope="col">Revenue (₹)</th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($data['city_breakdown'] as $cb): ?>
        <tr>
          <td data-label="City"><?= esc_html($cb['city']) ?></td>
          <?php if ($report === 'vendors'): ?>
          <td data-label="Total"><?= (int)$cb['total'] ?></td>
          <td data-label="Completed"><?= (int)$cb['completed'] ?></td>
          <td data-label="Active Vendors"><?= (int)$cb['vendors_active'] ?></td>
          <?php else: ?>
          <td data-label="Orders"><?= (int)$cb['orders'] ?></td>
          <td data-label="Revenue (₹)"><?= esc_html(rto_format_inr((float)$cb['revenue'])) ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; // $report === 'funnel' ?>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('saveSnapshotBtn')?.addEventListener('click', function () {
  var label = prompt('Optional label for this snapshot (e.g. "March 2026 finance close"):', '');
  if (label === null) return; // cancelled
  var btn = this;
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
  fd.append('rto_action', 'save_report_snapshot');
  fd.append('report', document.getElementById('repType').value);
  fd.append('date_from', document.getElementById('repFrom').value);
  fd.append('date_to', document.getElementById('repTo').value);
  fd.append('label', label);
  fd.append('rto_nonce', rtoflowAdmin.nonce);
  btn.disabled = true;
  fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
    .then(function (r) { return r.json(); })
    .then(function (r) {
      var m = document.getElementById('snapMsg');
      m.className = 'rto-msg rto-msg-' + (r.success ? 'success' : 'error');
      m.textContent = r.data ? r.data.message : 'Done';
      m.style.display = 'block';
      if (r.success) setTimeout(function () { location.reload(); }, 1200);
    })
    .finally(function () { btn.disabled = false; });
});
</script>
<?php rto_help_box('reports'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
