<?php if (!defined('ABSPATH')) exit;
global $wpdb;
$p   = $wpdb->prefix;
$vid = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}rto_vendors WHERE user_id=%d", get_current_user_id()));

$payouts = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$p}rto_vendor_payouts WHERE vendor_id=%d ORDER BY created_at DESC LIMIT 24", $vid
), ARRAY_A) ?: [];

// Graceful handling - pending_balance may not exist yet
$hasPendingBalance = $wpdb->get_var("SHOW COLUMNS FROM {$p}rto_vendors LIKE 'pending_balance'");
$pendingBalance = $hasPendingBalance
    ? (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(pending_balance,0) FROM {$p}rto_vendors WHERE id=%d", $vid))
    : (float)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(p.amount * (COALESCE(s.vendor_share,45)/100)),0)
         FROM {$p}rto_leads l
         LEFT JOIN {$p}rto_services s ON s.id=l.service_id
         LEFT JOIN {$p}rto_payments p ON p.lead_id=l.id
         WHERE l.vendor_id=%d AND l.status='completed' AND l.payment_status='paid'
         AND NOT EXISTS(SELECT 1 FROM {$p}rto_vendor_payouts vp WHERE vp.vendor_id=l.vendor_id AND vp.status='paid' AND vp.period = DATE_FORMAT(l.completed_at,'%Y-%m'))",
        $vid
      ));

$totalEarned = (float)$wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(gross_amount),0) FROM {$p}rto_vendor_payouts WHERE vendor_id=%d AND status='paid'", $vid));
$totalTds    = (float)$wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(tds_amount),0) FROM {$p}rto_vendor_payouts WHERE vendor_id=%d AND status='paid'", $vid));

require RTOFLOW_DIR . 'resources/views/layouts/vendor-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Earnings &amp; Payouts</h1>
  </div>

  <!-- Summary -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
    <?php foreach ([
      ['Pending Balance',  rto_format_inr($pendingBalance), 'var(--amber)'],
      ['Total Earned',     rto_format_inr($totalEarned),   'var(--green)'],
      ['TDS Deducted',     rto_format_inr($totalTds),      'var(--gray-600)'],
    ] as [$l,$v,$c]): ?>
    <div class="rto-card" style="padding:20px;text-align:center">
      <div style="font-size:22px;font-weight:700;color:<?= $c ?>"><?= esc_html($v) ?></div>
      <div class="rto-small rto-muted"><?= esc_html($l) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="rto-card">
    <div class="rto-card-header"><h3>Payout History</h3></div>
    <?php if (empty($payouts)): ?>
    <div class="rto-empty-state"><p>No payout records yet. Payouts are generated monthly by the admin.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Period</th><th>Gross</th><th>TDS (2%)</th><th>Net</th><th>Status</th><th>Reference</th><th>Paid On</th></tr></thead>
        <tbody>
        <?php foreach ($payouts as $po): ?>
        <tr>
          <td data-label="Period"><strong><?= esc_html($po['period']) ?></strong></td>
          <td data-label="Gross"><?= esc_html(rto_format_inr((float)$po['gross_amount'])) ?></td>
          <td data-label="TDS (2%)" class="rto-muted"><?= esc_html(rto_format_inr((float)$po['tds_amount'])) ?></td>
          <td data-label="Net"><strong><?= esc_html(rto_format_inr((float)$po['net_amount'])) ?></strong></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= $po['status']==='paid'?'success':'warning' ?>"><?= esc_html(ucfirst($po['status'])) ?></span></td>
          <td data-label="Reference" class="rto-small rto-muted"><?= esc_html($po['payment_ref'] ?: '—') ?></td>
          <td data-label="Paid On" class="rto-small rto-muted"><?= $po['paid_at'] ? esc_html(rto_date($po['paid_at'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/vendor-footer.php'; ?>
