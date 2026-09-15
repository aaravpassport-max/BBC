<?php if (!defined('ABSPATH')) exit;
/** @var array $ratings @var array $vendors @var int $vendorId @var int $score @var float $avg */
$pageTitle = 'Ratings';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$avg = $avg ?? 0;
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title"><?= $showTrash ? 'Ratings — Trash' : 'Vendor Ratings' ?></h1>
    <div style="display:flex;align-items:center;gap:16px">
      <?php if ($showTrash): ?>
      <a href="<?= esc_url(remove_query_arg('trash')) ?>" class="rto-btn rto-btn-outline">← Back to Ratings</a>
      <?php else: ?>
      <!-- Known Limitations audit fix: "Deletion is permanent with no
           undo." Removed ratings are now recoverable here rather than
           gone forever. -->
      <a href="<?= esc_url(add_query_arg('trash', 1)) ?>" class="rto-btn rto-btn-outline">🗑 Trash<?= $trashCount > 0 ? ' (' . (int)$trashCount . ')' : '' ?></a>
      <a href="<?= esc_url(add_query_arg(['export' => 'csv'])) ?>" class="rto-btn rto-btn-outline">Export CSV</a>
      <span style="font-size:28px;font-weight:700;color:var(--amber)">★ <?= number_format($avg,1) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <form method="GET" class="rto-card rto-mb-4">
    <input type="hidden" name="rto_area" value="admin"><input type="hidden" name="rto_page" value="ratings">
    <?php if ($showTrash): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
    <div class="rto-filter-row">
      <div class="rto-form-group" style="flex:2">
        <label class="rto-label" for="rVendor">Vendor</label>
        <select id="rVendor" name="vendor_id" class="rto-select">
          <option value="">All Vendors</option>
          <?php foreach ($vendors as $v): ?>
          <option value="<?= esc_attr($v['id']) ?>" <?= $vendorId===(int)$v['id']?'selected':'' ?>><?= esc_html($v['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rto-form-group" style="flex:1">
        <label class="rto-label" for="rScore">Stars</label>
        <select id="rScore" name="score" class="rto-select">
          <option value="">Any</option>
          <?php for($i=5;$i>=1;$i--): ?>
          <option value="<?= $i ?>" <?= $score===$i?'selected':'' ?>><?= $i ?> ★</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="rto-form-group" style="align-self:flex-end">
        <button type="submit" class="rto-btn rto-btn-primary">Filter</button>
      </div>
    </div>
  </form>

  <div class="rto-card">
    <?php if (empty($ratings)): ?>
    <div class="rto-empty-state"><p><?= $showTrash ? 'Trash is empty — no removed ratings.' : 'No ratings yet. Ratings appear after clients complete their orders.' ?></p></div>
    <?php else: ?>
    <?php if (!$showTrash): ?>
    <div id="ratBulkBar" class="rto-bulk-bar" style="padding:10px 20px;background:var(--gray-50);border-bottom:1px solid var(--gray-200);display:none;align-items:center;gap:10px">
      <span class="rto-small"><span id="ratBulkCount">0</span> selected</span>
      <button type="button" id="ratBulkDelete" class="rto-btn rto-btn-xs rto-btn-danger">Remove Selected</button>
    </div>
    <?php endif; ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th><input type="checkbox" id="ratSelectAll" aria-label="Select all ratings"></th><th>Order</th><th>Client</th><th>Vendor</th><th>Rating</th><th>Comment</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ratings as $r): ?>
        <tr>
          <td data-label="Select"><input type="checkbox" class="rat-check" value="<?= esc_attr($r['id']) ?>" aria-label="Select rating from <?= esc_attr($r['client_name']) ?> for <?= esc_attr($r['vendor_name']) ?>"></td>
          <td data-label="Order"><a href="<?= esc_url(home_url('/rto-admin/leads/'.(int)$r['lead_id'])) ?>" class="rto-link"><?= esc_html($r['lead_number']??'—') ?></a></td>
          <td data-label="Client"><?= esc_html($r['client_name']) ?></td>
          <td data-label="Vendor"><?= esc_html($r['vendor_name']) ?></td>
          <td data-label="Rating">
            <span style="color:var(--amber);font-size:16px;letter-spacing:1px">
              <?= str_repeat('★',(int)$r['score']) ?><?= str_repeat('☆',5-(int)$r['score']) ?>
            </span>
          </td>
          <td data-label="Comment" class="rto-small">
            <?= $r['comment']?esc_html(mb_strimwidth($r['comment'],0,60,'…')):'<span class="rto-muted">—</span>' ?>
            <?php if (!empty($r['edited_at'])): ?>
            <div class="rto-small rto-muted" title="Original score: <?= (int)$r['original_score'] ?>★">✎ edited</div>
            <?php endif; ?>
          </td>
          <td data-label="Date" class="rto-small rto-muted"><?= esc_html(rto_date($r['created_at'])) ?></td>
          <td data-label="Actions">
            <?php if ($showTrash): ?>
            <button class="rto-btn rto-btn-xs rto-btn-primary restore-rating" data-id="<?= esc_attr($r['id']) ?>" aria-label="Restore rating from <?= esc_attr($r['client_name']) ?> for <?= esc_attr($r['vendor_name']) ?>">Restore</button>
            <?php else: ?>
            <!-- Known Limitations audit fix: "No edit action exists for
                 correcting a rating's score." -->
            <button class="rto-btn rto-btn-xs rto-btn-outline edit-score" data-id="<?= esc_attr($r['id']) ?>" data-score="<?= (int)$r['score'] ?>" aria-label="Edit score for rating from <?= esc_attr($r['client_name']) ?> for <?= esc_attr($r['vendor_name']) ?>">Edit Score</button>
            <button class="rto-btn rto-btn-xs rto-btn-danger del-rating" data-id="<?= esc_attr($r['id']) ?>" aria-label="Remove rating from <?= esc_attr($r['client_name']) ?> for <?= esc_attr($r['vendor_name']) ?>">Remove</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (($lastPage ?? 1) > 1): ?>
      <div class="rto-pagination">
        <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($lastPage, ($page ?? 1) + 2); $i++): ?>
          <?php $url = add_query_arg(['vendor_id' => $vendorId, 'score' => $score, 'paged' => $i]); ?>
          <a href="<?= esc_url($url) ?>" class="rto-page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <span class="rto-page-info">Page <?= (int)($page ?? 1) ?> of <?= (int)$lastPage ?> (<?= number_format((int)($total ?? 0)) ?> total)</span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.querySelectorAll('.del-rating').forEach(function(btn){
  btn.addEventListener('click',function(){
    if(!confirm('Remove this rating? Vendor score will be recalculated.'))return;
    var fd=new FormData();
    fd.append('action','rto_admin');fd.append('rto_area','admin');
    fd.append('rto_action','delete_rating');fd.append('rating_id',btn.dataset.id);
    fd.append('rto_nonce',rtoflowAdmin.nonce);
    fetch(rtoflowAdmin.ajax_url,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(r){if(r.success)btn.closest('tr').remove();else window.rtoToast(r.data?r.data.message:'Failed','error');});
  });
});

document.querySelectorAll('.restore-rating').forEach(function(btn){
  btn.addEventListener('click',function(){
    var fd=new FormData();
    fd.append('action','rto_admin');fd.append('rto_area','admin');
    fd.append('rto_action','restore_rating');fd.append('rating_id',btn.dataset.id);
    fd.append('rto_nonce',rtoflowAdmin.nonce);
    btn.disabled = true;
    fetch(rtoflowAdmin.ajax_url,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(r){
        if (window.rtoToast) rtoToast(r.data?r.data.message:(r.success?'Restored.':'Failed.'), r.success?'success':'error');
        if (r.success) btn.closest('tr').remove(); else btn.disabled = false;
      });
  });
});

document.querySelectorAll('.edit-score').forEach(function(btn){
  btn.addEventListener('click',function(){
    var current = parseInt(btn.dataset.score, 10);
    var next = prompt('Corrected score (1–5). The comment is preserved as-is:', current);
    if (next === null) return;
    next = parseInt(next, 10);
    if (!next || next < 1 || next > 5) { window.rtoToast('Enter a whole number from 1 to 5.', 'error'); return; }
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin');
    fd.append('rto_action','update_rating_score');
    fd.append('rating_id', btn.dataset.id); fd.append('score', next);
    fd.append('rto_nonce', rtoflowAdmin.nonce);
    btn.disabled = true;
    fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (window.rtoToast) rtoToast(r.data ? r.data.message : (r.success ? 'Updated.' : 'Failed.'), r.success ? 'success' : 'error');
        if (r.success) setTimeout(function(){ location.reload(); }, 900);
        else btn.disabled = false;
      });
  });
});

// Bulk delete
(function() {
  var bar = document.getElementById('ratBulkBar');
  if (!bar) return;
  var countEl = document.getElementById('ratBulkCount');
  var selectAll = document.getElementById('ratSelectAll');
  function checks() { return Array.prototype.slice.call(document.querySelectorAll('.rat-check')); }
  function refresh() {
    var n = checks().filter(function(c){ return c.checked; }).length;
    countEl.textContent = n;
    bar.style.display = n > 0 ? 'flex' : 'none';
  }
  selectAll && selectAll.addEventListener('change', function() {
    checks().forEach(function(c){ c.checked = selectAll.checked; });
    refresh();
  });
  checks().forEach(function(c){ c.addEventListener('change', refresh); });
  document.getElementById('ratBulkDelete').addEventListener('click', function() {
    var ids = checks().filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
    if (!ids.length) return;
    if (!confirm('Remove ' + ids.length + ' rating(s)? Vendor scores will be recalculated.')) return;
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin');
    fd.append('rto_action','delete_rating');
    ids.forEach(function(id){ fd.append('rating_ids[]', id); });
    fd.append('rto_nonce', rtoflowAdmin.nonce);
    fetch(rtoflowAdmin.ajax_url, {method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (r.success) { window.rtoToast(r.data.message,'success'); setTimeout(function(){location.reload();},800); }
        else window.rtoToast(r.data ? r.data.message : 'Failed.','error');
      })
      .catch(function(){ window.rtoToast('Request failed.','error'); });
  });
})();
</script>

<?php rto_help_box('ratings'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
