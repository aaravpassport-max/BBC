<?php if (!defined('ABSPATH')) exit;
/** @var array $logs @var string $action @var int $userId @var int $leadId
 *  @var string $dateFrom @var string $dateTo @var int $page @var int $pages
 *  @var int $total @var array $actions */
$pageTitle = 'Audit Log';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Audit Log</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="rto-small rto-muted"><?= number_format($total) ?> events</span>
      <a href="<?= esc_url(add_query_arg(array_merge($_GET,['export'=>'csv']))) ?>" class="rto-btn rto-btn-outline">⬇ Export CSV</a>
    </div>
  </div>

  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="audit-log">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="alAction">Action</label>
        <select id="alAction" name="action" class="rto-select">
          <option value="">All actions</option>
          <?php foreach ($actions as $a): ?>
          <option value="<?= esc_attr($a) ?>" <?= $action===$a?'selected':'' ?>><?= esc_html($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="alUser">User ID</label>
        <input type="number" id="alUser" name="user_id" class="rto-input" value="<?= $userId ?: '' ?>" min="1">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="alLead">Order ID</label>
        <input type="number" id="alLead" name="lead_id" class="rto-input" value="<?= $leadId ?: '' ?>" min="1">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="alFrom">From</label>
        <input type="date" id="alFrom" name="date_from" class="rto-input" value="<?= esc_attr($dateFrom) ?>">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="alTo">To</label>
        <input type="date" id="alTo" name="date_to" class="rto-input" value="<?= esc_attr($dateTo) ?>">
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
        <a href="<?= esc_url(home_url('/rto-admin/audit-log/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <div class="rto-card">
    <?php if (empty($logs)): ?>
    <div class="rto-empty-state"><p>No audit events match this filter.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th scope="col">When</th><th scope="col">Action</th><th scope="col">User</th>
          <th scope="col">Order</th><th scope="col">Change</th><th scope="col">IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td data-label="When" class="rto-small rto-muted" style="white-space:nowrap"><?= esc_html(rto_date($log['created_at'], 'd M Y H:i')) ?></td>
          <td data-label="Action"><span class="rto-badge rto-badge-secondary"><?= esc_html($log['action']) ?></span></td>
          <td data-label="User"><?= esc_html($log['user_name'] ?? ($log['user_id'] ? ('User #' . $log['user_id']) : 'System')) ?></td>
          <td data-label="Order"><?= $log['lead_number'] ? '<a href="'.esc_url(home_url('/rto-admin/leads/'.(int)$log['lead_id'])).'" class="rto-link">'.esc_html($log['lead_number']).'</a>' : '—' ?></td>
          <td data-label="Change" style="max-width:320px">
            <?php
              $old = $log['old_value'] ? json_decode($log['old_value'], true) : null;
              $new = $log['new_value'] ? json_decode($log['new_value'], true) : null;
            ?>
            <?php if ($old || $new): ?>
            <details>
              <summary class="rto-small" style="cursor:pointer;color:var(--navy)">View diff</summary>
              <?php if ($old): ?><div class="rto-small" style="color:#DC2626"><strong>Before:</strong> <?= esc_html(wp_json_encode($old)) ?></div><?php endif; ?>
              <?php if ($new): ?><div class="rto-small" style="color:#16A34A"><strong>After:</strong> <?= esc_html(wp_json_encode($new)) ?></div><?php endif; ?>
            </details>
            <?php else: ?>
            <span class="rto-muted rto-small">—</span>
            <?php endif; ?>
          </td>
          <td data-label="IP" class="rto-small rto-muted"><?= esc_html($log['ip_address'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($pages > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <?php $url = add_query_arg(array_merge($_GET, ['paged' => $i])); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)$page ?> of <?= (int)$pages ?> (<?= number_format($total) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
