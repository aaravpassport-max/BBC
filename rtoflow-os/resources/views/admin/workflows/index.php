<?php
if (!defined('ABSPATH')) exit;
/** @var array $services */
$pageTitle = 'Workflow Builder';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-header">
  <div>
    <h2>Workflow Builder</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">
      Every service uses the same default lead lifecycle unless it has its own custom workflow configured here.
    </p>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Category</th><th>Service</th><th>Workflow</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($services as $svc): ?>
        <tr>
          <td data-label="Category"><?= esc_html($svc['category']) ?></td>
          <td data-label="Service"><?= esc_html($svc['name']) ?></td>
          <td data-label="Workflow">
            <?php if ($svc['definition_id']): ?>
              <?= esc_html($svc['workflow_name']) ?>
            <?php else: ?>
              <em style="color:#94a3b8">Default workflow</em>
            <?php endif; ?>
          </td>
          <td data-label="Status">
            <?php if ($svc['definition_id']): ?>
              <span style="background:<?= $svc['is_active']?'#DCFCE7':'#F3F4F6' ?>;color:<?= $svc['is_active']?'#166534':'#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
                <?= $svc['is_active'] ? 'Custom' : 'Disabled (using default)' ?>
              </span>
            <?php else: ?>
              <span style="background:#F3F4F6;color:#6B7280;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">Default</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions" style="white-space:nowrap">
            <a class="rto-btn rto-btn--sm" href="?page=rto-admin&rto_page=workflows&id=<?= (int)$svc['id'] ?>">
              <?= $svc['definition_id'] ? 'Edit' : 'Configure' ?>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($services)): ?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">No active services found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php rto_help_box('workflows'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
