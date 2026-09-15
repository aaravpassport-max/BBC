<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard — RTOFLOW</title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<?php
// FIX (mobile pass follow-up): this is the client's actual landing page
// after login, but — unlike profile/complaints/documents — it never went
// through layouts/client-header.php, so it was missed entirely by the
// mobile bottom-nav/app-shell work. Wired in directly here rather than
// switching this file to the shared layout (safer than risking a
// regression on the page's own existing variables/logic).
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-client-body">

<div class="rto-client-wrap">
  <!-- Header -->
  <header class="rto-client-header">
    <div class="rto-client-header-inner">
      <a href="<?= esc_url(home_url('/')) ?>" class="rto-client-logo">
        <?= esc_html(get_option('rtoflow_company_name', 'RTOFLOW')) ?>
      </a>
      <nav class="rto-client-nav">
        <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="active">My Orders</a>
        <a href="<?= esc_url(home_url('/rto-apply/')) ?>">New Request</a>
        <span class="rto-client-user"><?= esc_html(wp_get_current_user()->display_name) ?></span>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout-link">Logout</a>
      </nav>
    </div>
  </header>

  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="client">
    <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="rto-bn-item active" data-rto-page="dashboard" aria-current="page">
      <span class="rto-bn-icon" aria-hidden="true">🏠</span><span class="rto-bn-label">Dashboard</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/orders/')) ?>" class="rto-bn-item" data-rto-page="orders">
      <span class="rto-bn-icon" aria-hidden="true">📦</span><span class="rto-bn-label">Orders</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/documents/')) ?>" class="rto-bn-item" data-rto-page="documents">
      <span class="rto-bn-icon" aria-hidden="true">📄</span><span class="rto-bn-label">Documents</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-apply/')) ?>" class="rto-bn-item" data-rto-page="apply">
      <span class="rto-bn-icon" aria-hidden="true">➕</span><span class="rto-bn-label">New</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/profile/')) ?>" class="rto-bn-item" data-rto-page="profile">
      <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Profile</span>
    </a>
  </nav>

  <main class="rto-client-main" id="rtoContentRegion" data-rto-page="dashboard">

    <!-- Summary cards -->
    <div class="rto-client-kpi-row">
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= number_format($activeCount) ?></div>
        <div class="rto-client-kpi-label">Active Orders</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= number_format($completedCount) ?></div>
        <div class="rto-client-kpi-label">Completed</div>
      </div>
      <div class="rto-client-kpi">
        <div class="rto-client-kpi-value"><?= esc_html(rto_format_inr($totalSpent)) ?></div>
        <div class="rto-client-kpi-label">Total Spent</div>
      </div>
      <div class="rto-client-kpi rto-client-kpi-action">
        <a href="<?= esc_url(home_url('/rto-apply/')) ?>" class="rto-btn rto-btn-primary">+ New Request</a>
      </div>
    </div>

    <!-- My Orders -->
    <div class="rto-client-card">
      <div class="rto-client-card-header">
        <h2>My Service Requests</h2>
      </div>

      <?php if (empty($myLeads['data'])): ?>
      <div class="rto-client-empty">
        <div class="rto-client-empty-icon">📋</div>
        <p>You have no service requests yet.</p>
        <a href="<?= esc_url(home_url('/rto-apply/')) ?>" class="rto-btn rto-btn-primary">Start a New Request</a>
      </div>
      <?php else: ?>

      <div class="rto-client-orders">
        <?php foreach ($myLeads['data'] as $lead): ?>
        <div class="rto-order-card">
          <div class="rto-order-card-header">
            <div class="rto-order-number">
              <a href="<?= esc_url(home_url('/rto-dashboard/orders/' . $lead['id'])) ?>" class="rto-link">
                <?= esc_html($lead['lead_number']) ?>
              </a>
            </div>
            <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?>">
              <?= esc_html(rto_status_label($lead['status'])) ?>
            </span>
          </div>

          <div class="rto-order-card-body">
            <div class="rto-order-meta">
              <span><strong><?= esc_html($lead['service_name']) ?></strong></span>
              <span><?= esc_html($lead['city_name']) ?></span>
              <span><?= esc_html(rto_format_inr((float)$lead['total_amount'])) ?></span>
            </div>

            <!-- Progress bar -->
            <?php
            $steps = ['created','payment_received','assigned','in_progress','rto_submitted','completed'];
            $curStep = array_search($lead['status'], $steps);
            $curStep = ($curStep === false) ? 0 : $curStep;
            $pct = round(($curStep / (count($steps)-1)) * 100);
            ?>
            <div class="rto-order-progress">
              <div class="rto-order-progress-bar" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="rto-order-steps">
              <?php foreach ($steps as $i => $step): ?>
              <span class="rto-order-step <?= $i <= $curStep ? 'done' : '' ?>">
                <?= esc_html(rto_status_label($step)) ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="rto-order-card-footer">
            <span class="rto-muted rto-small">Created: <?= esc_html(rto_date($lead['created_at'])) ?></span>
            <?php if ($lead['sla_deadline']): ?>
            <span class="rto-small <?= $lead['sla_breached'] ? 'rto-danger' : 'rto-muted' ?>">
              SLA: <?= esc_html(rto_date($lead['sla_deadline'])) ?>
              <?= $lead['sla_breached'] ? ' ⚠️ Overdue' : '' ?>
            </span>
            <?php endif; ?>
            <div class="rto-order-actions">
              <?php if ($lead['payment_status'] !== 'paid' && $lead['status'] !== 'cancelled'): ?>
              <a href="<?= esc_url(home_url('/rto-dashboard/orders/' . $lead['id'])) ?>" class="rto-btn rto-btn-sm rto-btn-warning">Pay Now</a>
              <?php endif; ?>
              <a href="<?= esc_url(home_url('/rto-dashboard/orders/' . $lead['id'])) ?>" class="rto-btn rto-btn-sm rto-btn-outline">View Details</a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php endif; ?>
    </div>

    <!-- Recent Payments -->
    <?php if (!empty($recentPayments)): ?>
    <div class="rto-client-card">
      <div class="rto-client-card-header"><h2>Recent Payments</h2></div>
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Order</th><th>Amount</th><th>Method</th><th>Transaction ID</th><th>Date</th></tr></thead>
        <tbody>
          <?php foreach ($recentPayments as $pmt): ?>
          <tr>
            <td data-label="Order"><?= esc_html($pmt['lead_number']) ?></td>
            <td data-label="Amount"><?= esc_html(rto_format_inr((float)$pmt['amount'])) ?></td>
            <td data-label="Method"><?= esc_html(ucfirst($pmt['method'])) ?></td>
            <td data-label="Transaction ID" class="rto-muted rto-small"><?= esc_html($pmt['txn_id'] ?? '—') ?></td>
            <td data-label="Date" class="rto-muted"><?= esc_html(rto_date($pmt['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </main>

  <footer class="rto-client-footer">
    <p>© <?= date('Y') ?> <?= esc_html(get_option('rtoflow_company_name', 'RTOFLOW')) ?>. All rights reserved.</p>
  </footer>
</div>

<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/public.js') ?>?v=<?= RTOFLOW_VERSION ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
</body>
</html>
