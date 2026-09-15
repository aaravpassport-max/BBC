<?php if (!defined('ABSPATH')) exit;
if (!rto_is_vendor()) { wp_redirect(rto_login_url()); exit; }
global $wpdb; $p = $wpdb->prefix; $userId = get_current_user_id();
$vendor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}rto_vendors WHERE user_id=%d", $userId), ARRAY_A);
if (!$vendor) { wp_die('Vendor profile not found.', 'Not Found', ['response'=>404,'back_link'=>true]); }
$statusFilter = \RTOFLOW\Security\Sanitiser::text($_GET['status'] ?? '');
$where = ['l.vendor_id=%d', 'l.deleted_at IS NULL'];
$params = [$vendor['id']];
if ($statusFilter) { $where[] = 'l.status=%s'; $params[] = $statusFilter; }
$jobs = $wpdb->get_results($wpdb->prepare(
    "SELECT l.id, l.lead_number, l.status, l.sla_deadline, l.sla_breached, l.total_amount, l.created_at,
            s.name as service_name, c.name as city_name, u.display_name as client_name
     FROM {$p}rto_leads l
     LEFT JOIN {$p}rto_services s ON s.id=l.service_id
     LEFT JOIN {$p}rto_cities c ON c.id=l.city_id
     LEFT JOIN {$p}users u ON u.ID=l.client_id
     WHERE " . implode(' AND ', $where) . " ORDER BY l.created_at DESC LIMIT 50",
    $params), ARRAY_A) ?: [];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Jobs — RTOFLOW</title><meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL.'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<?php
// FIX (mobile pass follow-up): standalone markup, never went through
// layouts/vendor-header.php, so it was missed by the mobile bottom-nav
// work even though "My Jobs" is one of the vendor's core screens.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head><body class="rto-vendor-body">
<a href="#rto-main-content" class="rto-skip-link">Skip to main content</a>
<div class="rto-client-wrap">
  <header class="rto-client-header">
    <div class="rto-client-header-inner">
      <div class="rto-client-brand"><?= esc_html(get_option('rtoflow_company_name','RTOFLOW')) ?></div>
      <nav class="rto-client-nav" aria-label="Vendor navigation">
        <a href="<?= esc_url(home_url('/rto-vendor/')) ?>" class="rto-nav-link">Dashboard</a>
        <a href="<?= esc_url(home_url('/rto-vendor/jobs/')) ?>" class="rto-nav-link" aria-current="page">My Jobs</a>
        <a href="<?= esc_url(home_url('/rto-vendor/earnings/')) ?>" class="rto-nav-link">Earnings</a>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-nav-link">Logout</a>
      </nav>
    </div>
  </header>
  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="vendor">
    <a href="<?= esc_url(home_url('/rto-vendor/')) ?>" class="rto-bn-item" data-rto-page="dashboard">
      <span class="rto-bn-icon" aria-hidden="true">🏠</span><span class="rto-bn-label">Dashboard</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/jobs/')) ?>" class="rto-bn-item active" data-rto-page="jobs" aria-current="page">
      <span class="rto-bn-icon" aria-hidden="true">🧰</span><span class="rto-bn-label">Jobs</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/earnings/')) ?>" class="rto-bn-item" data-rto-page="earnings">
      <span class="rto-bn-icon" aria-hidden="true">💰</span><span class="rto-bn-label">Earnings</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-vendor/profile/')) ?>" class="rto-bn-item" data-rto-page="profile">
      <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Profile</span>
    </a>
  </nav>
  <main class="rto-client-main" id="rto-main-content" data-rto-page="jobs">
    <div class="rto-client-card" style="margin-bottom:20px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <h2 style="font-size:20px;color:var(--navy)">My Jobs</h2>
        <form method="GET">
          <label class="rto-label" for="jobStatus">Filter by Status</label>
          <select id="jobStatus" name="status" class="rto-select">
            <option value="">All Jobs</option>
            <?php foreach (['assigned','in_progress','docs_pending','docs_verified','rto_submitted','completed','cancelled'] as $s): ?>
            <option value="<?= esc_attr($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= esc_html(rto_status_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
    <?php if (empty($jobs)): ?>
    <div class="rto-client-card rto-client-empty"><p>No jobs found<?= $statusFilter?' with this status':'' ?>.</p></div>
    <?php else: ?>
    <div class="rto-client-card">
      <div class="rto-table-scroll">
        <table class="rto-table rto-table-hover" data-rto-responsive="cards">
          <thead><tr>
            <th scope="col">Order</th><th scope="col">Service</th><th scope="col">City</th>
            <th scope="col">Client</th><th scope="col">Status</th><th scope="col">SLA</th>
            <th scope="col"><span class="rto-visually-hidden">Actions</span></th>
          </tr></thead>
          <tbody>
          <?php foreach ($jobs as $j): ?>
          <tr class="<?= $j['sla_breached']?'rto-danger-row':'' ?>">
            <td data-label="Order"><strong><?= esc_html($j['lead_number']) ?></strong></td>
            <td data-label="Service"><?= esc_html($j['service_name'] ?? '—') ?></td>
            <td data-label="City"><?= esc_html($j['city_name'] ?? '—') ?></td>
            <td data-label="Client"><?= esc_html($j['client_name'] ?? '—') ?></td>
            <td data-label="Status"><span class="rto-badge rto-badge-<?= rto_status_color($j['status']) ?>"><?= esc_html(rto_status_label($j['status'])) ?></span></td>
            <td data-label="SLA" class="<?= $j['sla_breached']?'rto-danger':($j['sla_deadline']&&strtotime($j['sla_deadline'])<time()+86400?'rto-warning':'') ?>"><?= esc_html(rto_date($j['sla_deadline']??'')) ?></td>
            <td data-label="Actions"><a href="<?= esc_url(home_url('/rto-vendor/jobs/'.(int)$j['id'])) ?>" class="rto-btn rto-btn-xs rto-btn-outline">View</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </main>
  <footer class="rto-client-footer"><p>© <?= date('Y') ?> <?= esc_html(get_option('rtoflow_company_name','RTOFLOW')) ?>.</p></footer>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('jobStatus').addEventListener('change', function(){ this.form.submit(); });
</script>
<script src="<?= esc_url(RTOFLOW_URL.'resources/assets/js/public.js') ?>?v=<?= RTOFLOW_VERSION ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
</body></html>
