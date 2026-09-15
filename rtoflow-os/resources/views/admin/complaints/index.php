<?php if (!defined('ABSPATH')) exit;
/** @var array $complaints @var string $status @var string $search */
$pageTitle = 'Complaints';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$statusColors = ['open'=>'danger','under_review'=>'warning','resolved'=>'success','closed'=>'secondary','rejected'=>'secondary'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Complaints / Grievances</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <?php $open = count(array_filter($complaints,fn($c)=>$c['status']==='open')); ?>
      <?php if ($open): ?><span class="rto-badge rto-badge-danger" style="font-size:14px"><?= $open ?> New</span><?php endif; ?>
      <!-- ENTERPRISE GAP FIX (Section 8): every sibling list screen already
           has a CSV export; Complaints didn't. -->
      <a href="<?= esc_url(add_query_arg(array_merge($_GET,['export'=>'csv']))) ?>" class="rto-btn rto-btn-outline">⬇ Export CSV</a>
    </div>
  </div>

  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="complaints">
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:2">
        <label class="rto-label" for="cSearch">Search</label>
        <input type="text" id="cSearch" name="search" class="rto-input" value="<?= esc_attr($search) ?>" placeholder="Complaint # or subject…">
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="cStatus">Status</label>
        <select id="cStatus" name="status" class="rto-select">
          <option value="">All</option>
          <?php foreach (['open'=>'Open','under_review'=>'Under Review','resolved'=>'Resolved','closed'=>'Closed','rejected'=>'Rejected'] as $k=>$l): ?>
          <option value="<?= esc_attr($k) ?>" <?= $status===$k?'selected':'' ?>><?= esc_html($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
        <a href="<?= esc_url(home_url('/rto-admin/complaints/')) ?>" class="rto-btn rto-btn-outline">Reset</a>
      </div>
    </div>
  </form>

  <div class="rto-card">
    <?php if (empty($complaints)): ?>
    <div class="rto-empty-state"><p>No complaints found. 🎉</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th>#</th><th>Client</th><th>Order</th><th>Subject</th><th>Category</th><th>Status</th><th>SLA</th><th>Filed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($complaints as $c): ?>
        <tr <?= $c['status']==='open'?'style="font-weight:600"':'' ?> class="<?= !empty($c['sla_breached'])?'rto-row-danger':'' ?>">
          <td data-label="#"><?= esc_html($c['complaint_number']) ?></td>
          <td data-label="Client"><?= esc_html($c['client_name']) ?></td>
          <td data-label="Order"><?= $c['lead_number']?'<a href="'.esc_url(home_url('/rto-admin/leads/'.(int)$c['lead_id'])).'" class="rto-link">'.esc_html($c['lead_number']).'</a>':'—' ?></td>
          <td data-label="Subject"><?= esc_html(mb_strimwidth($c['subject'],0,60,'…')) ?></td>
          <td data-label="Category"><span class="rto-badge rto-badge-secondary"><?= esc_html(ucfirst($c['category'])) ?></span></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr($statusColors[$c['status']]??'secondary') ?>"><?= esc_html(ucwords(str_replace('_',' ',$c['status']))) ?></span></td>
          <td data-label="SLA" class="<?= !empty($c['sla_breached'])?'rto-danger':'' ?>">
            <?= !empty($c['sla_deadline']) ? esc_html(rto_date($c['sla_deadline'])) : '—' ?><?= !empty($c['sla_breached']) ? ' ⚠' : '' ?>
          </td>
          <td data-label="Filed" class="rto-small rto-muted"><?= esc_html(rto_date($c['created_at'])) ?></td>
          <td data-label="Actions"><a href="<?= esc_url(home_url('/rto-admin/complaints/'.(int)$c['id'])) ?>" class="rto-btn rto-btn-xs rto-btn-outline">View →</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php rto_help_box('complaints'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
