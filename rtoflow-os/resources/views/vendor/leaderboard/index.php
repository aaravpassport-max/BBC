<?php if (!defined('ABSPATH')) exit;
/** @var array $ranked @var int|null $myRank
 * ENTERPRISE GAP FIX (Phase 5, item 1 — "vendor leaderboard / gamification"):
 * see Vendor\DashboardController::leaderboard() for the scoring/scoping
 * logic — this view only renders what that method already computed.
 */
require RTOFLOW_DIR . 'resources/views/layouts/vendor-header.php';
?>
<div class="rto-page-wrap" style="max-width:640px">
  <h1 class="rto-page-title">Leaderboard</h1>
  <p class="rto-small rto-muted" style="margin:0 0 16px">Ranked by the same score that drives job assignment: rating, completion rate, acceptance rate, and current job capacity. Only vendors in cities you also serve are shown.</p>

  <?php if ($myRank): ?>
  <div class="rto-card rto-mb-4" style="background:var(--rto-primary,#E97B28);color:#fff">
    <div class="rto-card-body" style="text-align:center">
      <div style="font-size:13px;opacity:.9">Your Rank</div>
      <div style="font-size:32px;font-weight:800">#<?= (int)$myRank ?> <span style="font-size:16px;font-weight:400">of <?= count($ranked) ?></span></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="rto-card">
    <div class="rto-card-body" style="padding:0">
      <?php if (empty($ranked)): ?>
      <div class="rto-empty-state"><p>No other vendors to rank against yet in your cities.</p></div>
      <?php else: ?>
      <?php foreach ($ranked as $i => $r): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;<?= $i < count($ranked)-1 ? 'border-bottom:1px solid #f1f5f9' : '' ?>;<?= $r['is_me'] ? 'background:#fff7ed' : '' ?>">
        <div style="width:32px;text-align:center;font-weight:700;<?= $i < 3 ? 'font-size:18px' : 'color:#94a3b8' ?>">
          <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ($i + 1))) ?>
        </div>
        <div style="flex:1">
          <div style="font-weight:<?= $r['is_me'] ? '700' : '500' ?>"><?= esc_html($r['name']) ?><?= $r['is_me'] ? ' (You)' : '' ?></div>
          <div class="rto-small rto-muted">⭐ <?= number_format($r['rating'], 1) ?></div>
        </div>
        <div class="rto-small rto-muted"><?= number_format($r['score'], 2) ?> pts</div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/vendor-footer.php'; ?>
