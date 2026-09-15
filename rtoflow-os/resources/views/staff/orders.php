<?php
if (!defined('ABSPATH')) exit;
if (!rto_is_staff()) { wp_redirect(rto_login_url()); exit; }

global $wpdb;
$p      = $wpdb->prefix;
$status = \RTOFLOW\Security\Sanitiser::text($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['paged'] ?? 1));
$perPage = 30;

$where = ['l.deleted_at IS NULL'];
if ($status) $where[] = $wpdb->prepare('l.status=%s', $status);
$ws = 'WHERE ' . implode(' AND ', $where);

$total  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads l {$ws}");
$offset = ($page - 1) * $perPage;
$leads  = $wpdb->get_results(
    "SELECT l.*, s.name as service_name, c.name as city_name, u.display_name as client_name, v.full_name as vendor_name
     FROM {$p}rto_leads l
     LEFT JOIN {$p}rto_services s ON s.id=l.service_id
     LEFT JOIN {$p}rto_cities c ON c.id=l.city_id
     LEFT JOIN {$p}users u ON u.ID=l.client_id
     LEFT JOIN {$p}rto_vendors v ON v.id=l.vendor_id
     {$ws} ORDER BY l.sla_deadline ASC, l.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    ARRAY_A
) ?: [];

$statuses = ['created'=>'Received','payment_pending'=>'Payment Pending','payment_received'=>'Payment Received','assigned'=>'Assigned','in_progress'=>'In Progress','docs_pending'=>'Docs Pending','rto_submitted'=>'At RTO','completed'=>'Completed','cancelled'=>'Cancelled','on_hold'=>'On Hold'];
$pageTitle = 'Staff — All Orders';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>

<div class="rto-page-header">
  <h2>All Orders <span style="font-size:14px;color:#64748b;font-weight:400">(<?= number_format($total) ?>)</span></h2>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <a href="?" style="padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:<?= !$status?'#1B2A6B':'#f3f4f6' ?>;color:<?= !$status?'#fff':'#374151' ?>">All</a>
    <?php foreach(['in_progress'=>'In Progress','docs_pending'=>'Docs Pending','rto_submitted'=>'At RTO','on_hold'=>'On Hold'] as $k=>$l): ?>
    <a href="?status=<?= $k ?>" style="padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:<?= $status===$k?'#1B2A6B':'#f3f4f6' ?>;color:<?= $status===$k?'#fff':'#374151' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Order #</th><th>Service</th><th>Client</th><th>Agent</th><th>Status</th><th>SLA</th><th>Amount</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $l):
          $sc = match($l['status']){'completed'=>'#16A34A','cancelled'=>'#DC2626','payment_pending','created'=>'#D97706',default=>'#2563EB'};
          $sla_ok = !$l['sla_deadline'] || strtotime($l['sla_deadline']) > time();
        ?>
        <tr style="<?= $l['sla_breached'] ? 'background:#FEF2F2' : '' ?>">
          <td data-label="Order #" style="font-weight:700;font-size:12px">
            <a href="?rto_area=admin&rto_page=leads&rto_id=<?= $l['id'] ?>" style="color:#2563EB"><?= esc_html($l['lead_number']) ?></a>
          </td>
          <td data-label="Service" style="font-size:12px;max-width:130px"><?= esc_html($l['service_name'] ?? '—') ?></td>
          <td data-label="Client" style="font-size:12px"><?= esc_html($l['client_name'] ?? '—') ?></td>
          <td data-label="Agent" style="font-size:12px"><?= esc_html($l['vendor_name'] ?? '—') ?></td>
          <td data-label="Status">
            <span style="background:<?= $sc ?>20;color:<?= $sc ?>;font-size:10px;font-weight:700;padding:3px 8px;border-radius:10px">
              <?= esc_html($statuses[$l['status']] ?? ucwords(str_replace('_',' ',$l['status']))) ?>
            </span>
          </td>
          <td data-label="SLA" style="font-size:11px;<?= $l['sla_breached'] ? 'color:#DC2626;font-weight:700' : ($l['sla_deadline'] && strtotime($l['sla_deadline']) < strtotime('+2 days') ? 'color:#D97706;font-weight:600' : 'color:#64748b') ?>">
            <?= $l['sla_deadline'] ? date('d M', strtotime($l['sla_deadline'])) : '—' ?>
            <?= $l['sla_breached'] ? ' ⚠' : '' ?>
          </td>
          <td data-label="Amount" style="font-size:12px;font-weight:600">₹<?= number_format($l['total_amount'], 0) ?></td>
          <td data-label="Actions">
            <a href="?rto_area=admin&rto_page=leads&rto_id=<?= $l['id'] ?>" class="rto-btn rto-btn--sm rto-btn--xs">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($leads)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">No orders found for this filter</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (ceil($total / $perPage) > 1): ?>
<div style="display:flex;gap:6px;margin-top:12px;justify-content:center">
  <?php for ($i = 1; $i <= min(10, ceil($total/$perPage)); $i++): ?>
  <a href="?status=<?= esc_attr($status) ?>&paged=<?= $i ?>"
     style="padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #e2e8f0;background:<?= $i===$page?'#1B2A6B':'#fff' ?>;color:<?= $i===$page?'#fff':'#374151' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
