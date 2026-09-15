<?php if (!defined('ABSPATH')) exit;
/** @var array $snap @var string $report @var string $from @var string $to @var array $data */
$pageTitle = 'Saved Report Snapshot';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$reports = ['revenue'=>'Revenue','leads'=>'Lead Analytics','vendors'=>'Vendor Performance','services'=>'Service Performance'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Saved Snapshot<?= !empty($snap['label']) ? ': ' . esc_html($snap['label']) : '' ?></h1>
    <a href="<?= esc_url(home_url('/rto-admin/reports/')) ?>" class="rto-back-link">← Live Reports</a>
  </div>

  <!-- Known Limitations audit fix: this figure is the exact payload stored
       at save time (rto_report_snapshots.payload_json) — it is never
       re-queried from live data, so it stays identical even if underlying
       records change afterwards. The banner below makes that permanence
       explicit rather than leaving staff to assume this is a live report. -->
  <div class="rto-msg rto-msg-info rto-mb-4">
    This is a frozen snapshot saved on <strong><?= esc_html(rto_date($snap['created_at'])) ?></strong>
    by <strong><?= esc_html($snap['created_by_name'] ?: 'Unknown') ?></strong>.
    These figures will not change even if underlying records are later edited, refunded, or deleted.
  </div>

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

  <div class="rto-card">
    <div class="rto-card-header">
      <h3><?= esc_html($reports[$report] ?? 'Report') ?> — <?= esc_html($from) ?> to <?= esc_html($to) ?></h3>
    </div>
    <?php if (empty($data['rows'])): ?>
    <div class="rto-empty-state"><p>No data was present in this snapshot.</p></div>
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
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
