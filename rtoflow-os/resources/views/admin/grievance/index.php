<?php
if (!defined('ABSPATH')) exit;
$pageTitle = 'Grievances & Complaints';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
global $wpdb;
$p       = $wpdb->prefix;
$status  = \RTOFLOW\Security\Sanitiser::text($_GET['status'] ?? '');
$page    = max(1,(int)($_GET['paged']??1));
$perPage = 25;

$where = $status ? $wpdb->prepare("WHERE c.status=%s", $status) : '';
$total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints c {$where}");
$offset = ($page-1)*$perPage;
$items = $wpdb->get_results(
    "SELECT c.*, u.display_name as complainant_name
     FROM {$p}rto_complaints c
     LEFT JOIN {$p}users u ON u.ID=c.complainant_id
     {$where} ORDER BY c.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    ARRAY_A
) ?: [];

$stats = [
    'open'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE status NOT IN ('closed','resolved')"),
    'breached'=> (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE sla_breached=1"),
];

$types = ['delay'=>'Service Delay','misconduct'=>'Vendor Misconduct','payment'=>'Payment Issue','document'=>'Document Issue','staff'=>'Staff Complaint','fraud'=>'Fraud Alert','refund'=>'Refund Dispute','general'=>'General'];
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-header">
  <div>
    <h2>Grievances & Complaints</h2>
  </div>
  <div style="display:flex;gap:12px;align-items:center">
    <span style="background:#FEF3C7;color:#92400E;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700">Open: <?= $stats['open'] ?></span>
    <?php if ($stats['breached']): ?><span style="background:#FEE2E2;color:#DC2626;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700">SLA Breach: <?= $stats['breached'] ?></span><?php endif; ?>
  </div>
</div>

<div class="rto-card" style="margin-bottom:12px">
  <div class="rto-card__body">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="rto_area" value="admin">
      <input type="hidden" name="rto_page" value="complaints">
      <select name="status" id="grievanceStatusFilter" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px">
        <option value="">All Statuses</option>
        <?php foreach(['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed','escalated'=>'Escalated'] as $k=>$l): ?>
        <option value="<?=$k?>" <?=$status===$k?'selected':''?>><?=$l?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="rto-btn rto-btn--primary rto-btn--sm">Filter</button>
    </form>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Complaint #</th><th>Type</th><th>Subject</th><th>Complainant</th><th>Priority</th><th>Status</th><th>SLA</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $c): ?>
        <tr style="<?= $c['sla_breached']?'background:#FEF2F2':'' ?>">
          <td data-label="Complaint #" style="font-weight:700;font-size:12px"><?= esc_html($c['complaint_number']) ?></td>
          <td data-label="Type"><span style="background:#EFF6FF;color:#2563EB;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px"><?= esc_html($types[$c['complaint_type']]??$c['complaint_type']) ?></span></td>
          <td data-label="Subject" style="max-width:180px;font-size:12px" title="<?= esc_attr($c['subject']) ?>"><?= esc_html(mb_substr($c['subject'],0,45,'UTF-8')) ?><?= strlen($c['subject'])>45?'…':'' ?></td>
          <td data-label="Complainant" style="font-size:12px"><?= esc_html($c['complainant_name']??'—') ?></td>
          <td data-label="Priority" style="font-size:12px"><?= (int)$c['priority'] ?>/5</td>
          <td data-label="Status"><span style="background:#e5e7eb;color:#374151;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px"><?= esc_html(ucfirst(str_replace('_',' ',$c['status']))) ?></span></td>
          <td data-label="SLA" style="font-size:11px;<?= $c['sla_breached']?'color:#dc2626;font-weight:700':'' ?>"><?= $c['sla_deadline']?date('d M',strtotime($c['sla_deadline'])):'—' ?><?= $c['sla_breached']?' ⚠':'' ?></td>
          <td data-label="Action">
            <select class="complaint-status-select" data-complaint-id="<?=$c['id']?>" aria-label="Update status for complaint <?= esc_attr($c['complaint_number']) ?>" style="padding:4px 6px;border:1.5px solid #e2e8f0;border-radius:4px;font-size:11px">
              <?php foreach(['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed','escalated'=>'Escalated'] as $k=>$l): ?>
              <option value="<?=$k?>" <?=$c['status']===$k?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">No complaints found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function updateComplaintStatus(id,status){
  var fd=new FormData();
  fd.append('action','rto_admin');
  fd.append('rto_area','admin');
  fd.append('rto_action','respond_complaint');
  fd.append('complaint_id',id);
  fd.append('status',status);
  fd.append('nonce','<?= $nonce ?>');
  fetch('<?= esc_js(admin_url('admin-ajax.php')) ?>',{method:'POST',body:fd});
}
// CSP fix: 'nonce-...' only covers <script> elements, not inline
// onchange= attributes — bind these via addEventListener instead.
(function(){
  var statusFilter = document.getElementById('grievanceStatusFilter');
  if (statusFilter) {
    statusFilter.addEventListener('change', function(){ this.form.submit(); });
  }
  document.querySelectorAll('.complaint-status-select').forEach(function(sel){
    sel.addEventListener('change', function(){
      updateComplaintStatus(this.dataset.complaintId, this.value);
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
