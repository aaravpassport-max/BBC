<?php if (!defined('ABSPATH')) exit;
/** @var array $exceptions @var int $total @var int $page @var int $lastPage @var bool $showResolved
 * ENTERPRISE GAP FIX (Phase 2, item 6 — structured exception monitoring):
 * see ExceptionMonitor's docblock for the full problem statement — this is
 * the first in-app place any of these failures are visible without server
 * file access. */
$pageTitle = 'Exceptions';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Exception Monitor</h1>
  </div>
  <p class="rto-small rto-muted rto-mb-4">
    Uncaught exceptions, fatal errors, and warnings captured platform-wide, deduplicated by message/file/line.
    This is separate from the <a href="<?= esc_url(home_url('/rto-admin/audit-log/')) ?>">Audit Log</a>, which records
    user actions, not code failures.
  </p>

  <div class="rto-card">
    <div class="rto-card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h3><?= $showResolved ? 'All' : 'Unresolved' ?> (<?= (int)$total ?>)</h3>
      <a class="rto-btn rto-btn-outline rto-btn-xs" href="<?= esc_url(add_query_arg(['show_resolved' => $showResolved ? '' : '1', 'paged' => 1])) ?>">
        <?= $showResolved ? 'Show unresolved only' : 'Show resolved too' ?>
      </a>
    </div>
    <?php if (empty($exceptions)): ?>
    <div class="rto-empty-state"><p>No <?= $showResolved ? '' : 'unresolved ' ?>exceptions recorded. That's a good sign.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table" data-rto-responsive="cards">
        <thead><tr><th>Level</th><th>Message</th><th>Location</th><th>Occurrences</th><th>First Seen</th><th>Last Seen</th><th><span class="rto-visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($exceptions as $ex): $ctx = json_decode($ex['context_json'] ?? '{}', true) ?: []; ?>
        <tr data-exception-id="<?= esc_attr($ex['id']) ?>">
          <td data-label="Level">
            <span class="rto-badge rto-badge-<?= $ex['level'] === 'fatal' ? 'danger' : ($ex['level'] === 'exception' ? 'warning' : 'secondary') ?>">
              <?= esc_html(ucfirst($ex['level'])) ?>
            </span>
          </td>
          <td data-label="Message" style="max-width:360px;word-break:break-word">
            <?= esc_html(mb_strimwidth($ex['message'], 0, 200, '…')) ?>
            <?php if (!empty($ctx['area']) || !empty($ctx['action'])): ?>
            <div class="rto-small rto-muted">context: <?= esc_html(trim(($ctx['area'] ?? '') . '.' . ($ctx['action'] ?? ''), '.')) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Location" class="rto-small rto-muted"><?= esc_html(basename((string)($ex['file'] ?? ''))) ?><?= $ex['line'] ? ':' . (int)$ex['line'] : '' ?></td>
          <td data-label="Occurrences"><?= (int)$ex['occurrences'] ?></td>
          <td data-label="First Seen" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', strtotime($ex['first_seen']))) ?></td>
          <td data-label="Last Seen" class="rto-small rto-muted"><?= esc_html(date('d M Y H:i', strtotime($ex['last_seen']))) ?></td>
          <td data-label="Actions">
            <?php if (!$ex['resolved']): ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline exception-resolve-btn">Mark Resolved</button>
            <?php else: ?>
            <span class="rto-small rto-muted">Resolved</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($lastPage > 1): ?>
    <div class="rto-pagination" style="padding:12px 16px">
      <?php for ($i = 1; $i <= $lastPage; $i++): ?>
        <a class="rto-btn rto-btn--sm <?= $i === $page ? 'rto-btn--primary' : 'rto-btn-outline' ?>" href="<?= esc_url(add_query_arg(['paged' => $i])) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = <?= wp_json_encode($nonce) ?>;
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  document.querySelectorAll('.exception-resolve-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr');
      var id = tr.dataset.exceptionId;
      var fd = new FormData();
      fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','resolve_exception');
      fd.append('rto_nonce', nonce); fd.append('exception_id', id);
      btn.disabled = true;
      fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){
        if (r.success) { tr.style.opacity = '0.5'; btn.outerHTML = '<span class="rto-small rto-muted">Resolved</span>'; }
        else { btn.disabled = false; alert(r.message || 'Unable to mark resolved.'); }
      }).catch(function(){ btn.disabled = false; alert('Network error — please try again.'); });
    });
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
