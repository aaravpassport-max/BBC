<?php
/**
 * Enterprise Customizer Hub — links to every reusable engine config
 * surface confirmed to exist in this admin today.
 * @var array $cards
 */
if (!defined('ABSPATH')) exit;
$pageTitle = 'Customizer Hub';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header">
    <div>
      <h1 class="rto-page-title">🧩 Enterprise Customizer Hub</h1>
      <p class="rto-muted" style="margin:4px 0 0">
        One place to reach every configuration surface that lets you change platform behaviour
        without a code deployment.
      </p>
    </div>
  </div>

  <div class="rto-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px">
    <?php foreach ($cards as $card): ?>
    <div class="rto-card">
      <div class="rto-card__body" style="padding:20px">
        <div style="font-size:28px;line-height:1;margin-bottom:10px"><?= esc_html($card['icon']) ?></div>
        <h3 style="margin:0 0 6px;font-size:15px">
          <?= esc_html($card['title']) ?>
          <?php if (!empty($card['status'])): ?>
            <span class="rto-badge rto-badge--warn" style="margin-left:6px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#fff3cd;color:#8a6300;vertical-align:middle"><?= esc_html($card['status']) ?></span>
          <?php endif; ?>
        </h3>
        <p class="rto-muted" style="margin:0 0 14px;font-size:13px;line-height:1.5"><?= esc_html($card['desc']) ?></p>
        <a href="<?= esc_url($card['url']) ?>" class="rto-btn rto-btn--primary rto-btn--sm">Configure →</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php rto_help_box('customizer'); ?>
<?php
require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
