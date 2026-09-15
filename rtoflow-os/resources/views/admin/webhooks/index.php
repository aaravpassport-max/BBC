<?php
if (!defined('ABSPATH')) exit;
/** @var array $subscriptions @var array $validEvents */
$pageTitle = 'Webhooks';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$validEvents = $validEvents ?? ['lead.created','lead.status_changed'];
?>
<div class="rto-page-header">
  <div>
    <h2>Webhooks</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Notify an external system by HTTP POST when a lead event occurs</p>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px" id="webhookFormTitle">Add Subscription</h3>
    <form id="webhookForm" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;align-items:end">
      <input type="hidden" name="subscription_id" id="webhookSubId" value="">
      <div>
        <label class="rto-label" for="webhookEvent">Event Type</label>
        <select name="event_type" id="webhookEvent" class="rto-input" aria-label="Event Type">
          <?php foreach ($validEvents as $e): ?>
          <option value="<?= esc_attr($e) ?>"><?= esc_html($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="webhookUrl">Target URL<?= rto_field_tooltip('The HTTPS endpoint that will receive an HTTP POST when this event fires. The request body is signed — verify it using the X-RTOFlow-Signature header and the secret shown after you save.') ?></label>
        <input type="url" name="target_url" id="webhookUrl" class="rto-input" placeholder="https://example.com/webhooks/rtoflow" required>
      </div>
      <div>
        <button type="submit" class="rto-btn rto-btn--primary" id="webhookSubmitBtn">Add Subscription</button>
        <button type="button" class="rto-btn rto-btn--sm" id="webhookCancelEdit" style="display:none">Cancel Edit</button>
      </div>
      <div style="grid-column:1/-1">
        <span id="webhookMsg" style="font-size:12px"></span>
      </div>
    </form>
    <div id="webhookSecretBox" style="display:none;margin-top:10px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;padding:10px 14px;font-size:12px;color:#92400E">
      Signing secret (shown once — store it now, it cannot be viewed again):
      <code id="webhookSecretValue" style="display:block;margin-top:4px;font-family:monospace;font-size:12px;word-break:break-all"></code>
    </div>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Event Type</th><th>Target URL</th><th>Created</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($subscriptions as $s): ?>
        <tr data-sub-id="<?= (int)$s['id'] ?>"
            data-event="<?= esc_attr($s['event_type']) ?>"
            data-url="<?= esc_attr($s['target_url']) ?>"
            data-active="<?= (int)$s['is_active'] ?>">
          <td data-label="Event Type"><code style="background:#F8FAFC;border:1px solid #e2e8f0;padding:2px 8px;border-radius:4px;font-size:11px;font-family:monospace"><?= esc_html($s['event_type']) ?></code></td>
          <td data-label="Target URL" style="font-size:12px;word-break:break-all"><?= esc_html($s['target_url']) ?></td>
          <td data-label="Created" style="font-size:12px;color:#64748b"><?= $s['created_at'] ? date('d M Y H:i', strtotime($s['created_at'])) : '' ?></td>
          <td data-label="Status">
            <span style="background:<?= $s['is_active']?'#DCFCE7':'#F3F4F6' ?>;color:<?= $s['is_active']?'#166534':'#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
              <?= $s['is_active'] ? 'Active' : 'Disabled' ?>
            </span>
          </td>
          <td data-label="Actions" style="white-space:nowrap">
            <button class="rto-btn rto-btn--sm webhook-edit" aria-label="Edit webhook for event: <?= esc_attr($s['event_type']) ?>">Edit</button>
            <button class="rto-btn rto-btn--sm webhook-toggle" aria-label="<?= $s['is_active'] ? 'Disable' : 'Enable' ?> webhook for event: <?= esc_attr($s['event_type']) ?>">Toggle</button>
            <!-- Known Limitations audit fix: rotate the secret for THIS
                 row in place instead of forcing a delete + recreate (which
                 changed the subscription ID and briefly stopped delivery). -->
            <button class="rto-btn rto-btn--sm webhook-rotate" aria-label="Rotate signing secret for event: <?= esc_attr($s['event_type']) ?>">Rotate Secret</button>
            <button class="rto-btn rto-btn--sm rto-btn--danger webhook-delete" aria-label="Delete webhook for event: <?= esc_attr($s['event_type']) ?>">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($subscriptions)): ?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">No webhook subscriptions yet. Add one above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ENTERPRISE GAP FIX (Section 7/8 — "no delivery-log admin UI", "no
     retry queue"): reads rto_webhook_delivery_log, which every delivery
     attempt (including retries) has always been written to — this is the
     first screen that ever shows it. -->
<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card-header"><h3>Recent Delivery Attempts</h3></div>
  <div style="overflow-x:auto">
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>When</th><th>Event</th><th>Target</th><th>Result</th><th>Attempt</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $queueLabels = ['final' => 'success', 'pending_retry' => 'warning', 'exhausted' => 'danger', 'superseded' => 'secondary']; ?>
      <?php foreach (($recentDeliveries ?? []) as $d): ?>
      <tr>
        <td data-label="When" class="rto-small rto-muted" style="white-space:nowrap"><?= esc_html(rto_date($d['attempted_at'], 'd M Y H:i')) ?></td>
        <td data-label="Event"><span class="rto-badge rto-badge-secondary"><?= esc_html($d['event_type']) ?></span></td>
        <td data-label="Target" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= esc_attr($d['target_url']) ?>"><?= esc_html($d['target_url']) ?></td>
        <td data-label="Result">
          <?php if ($d['success']): ?>
          <span class="rto-badge rto-badge-success">✓ Delivered (<?= (int)$d['response_code'] ?>)</span>
          <?php else: ?>
          <span class="rto-badge rto-badge-<?= esc_attr($queueLabels[$d['queue_status'] ?? 'final'] ?? 'danger') ?>">
            <?= $d['response_code'] ? 'HTTP ' . (int)$d['response_code'] : esc_html($d['error_message'] ?? 'Failed') ?>
            <?= ($d['queue_status'] ?? '') === 'pending_retry' ? ' — retry scheduled' : '' ?>
            <?= ($d['queue_status'] ?? '') === 'exhausted' ? ' — retries exhausted' : '' ?>
          </span>
          <?php endif; ?>
        </td>
        <td data-label="Attempt" class="rto-small rto-muted">#<?= (int)$d['retry_count'] + 1 ?></td>
        <td data-label="Actions">
          <?php /* ENTERPRISE GAP FIX (Phase 1, item 4 — "delivery-log admin
                   screen with a manual 'replay' action"): only offered for
                   attempts that did not already succeed and have not
                   already been superseded by an automatic retry or an
                   earlier manual replay — those states are final. */ ?>
          <?php if (!$d['success'] && ($d['queue_status'] ?? '') !== 'superseded'): ?>
          <button type="button" class="rto-btn rto-btn-sm rto-btn-secondary webhook-replay" data-log-id="<?= (int)$d['id'] ?>">Replay</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($recentDeliveries)): ?>
      <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">No delivery attempts yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || document.querySelector('meta[name="rto-admin-nonce"]')?.content || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';

  function post(action, extra){
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action',action);
    fd.append('rto_nonce', nonce);
    Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
  }

  var form = document.getElementById('webhookForm');
  var msg = document.getElementById('webhookMsg');

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(form);
    var extra = {}; fd.forEach(function(v,k){ extra[k]=v; });
    var editingId = document.getElementById('webhookSubId').value;
    var action = editingId ? 'webhooks.update' : 'webhooks.create';
    post(action, extra).then(function(r){
      msg.style.color = r.success ? '#166534' : '#991B1B';
      msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
      if (r.success && r.data && r.data.secret) {
        document.getElementById('webhookSecretValue').textContent = r.data.secret;
        document.getElementById('webhookSecretBox').style.display = '';
      }
      if (r.success) setTimeout(function(){ location.reload(); }, 4000);
    });
  });

  document.getElementById('webhookCancelEdit').addEventListener('click', function(){ resetForm(); });

  function resetForm(){
    document.getElementById('webhookSubId').value = '';
    form.reset();
    document.getElementById('webhookFormTitle').textContent = 'Add Subscription';
    document.getElementById('webhookSubmitBtn').textContent = 'Add Subscription';
    document.getElementById('webhookCancelEdit').style.display = 'none';
  }

  document.querySelectorAll('.webhook-edit').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr');
      document.getElementById('webhookSubId').value = tr.dataset.subId;
      document.getElementById('webhookEvent').value = tr.dataset.event;
      document.getElementById('webhookUrl').value = tr.dataset.url;
      document.getElementById('webhookFormTitle').textContent = 'Edit Subscription #' + tr.dataset.subId;
      document.getElementById('webhookSubmitBtn').textContent = 'Save Changes';
      document.getElementById('webhookCancelEdit').style.display = '';
      window.scrollTo({top: 0, behavior: 'smooth'});
    });
  });

  document.querySelectorAll('.webhook-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.closest('tr').dataset.subId;
      post('webhooks.toggle', {subscription_id:id}).then(function(){ location.reload(); });
    });
  });
  document.querySelectorAll('.webhook-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Delete this webhook subscription?')) return;
      var id = btn.closest('tr').dataset.subId;
      post('webhooks.delete', {subscription_id:id}).then(function(){ location.reload(); });
    });
  });

  document.querySelectorAll('.webhook-rotate').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Rotate the signing secret for this subscription? The old secret will stop working immediately, and the new one is shown only once — update your endpoint before continuing.')) return;
      var id = btn.closest('tr').dataset.subId;
      btn.disabled = true;
      post('webhooks.rotate_secret', {subscription_id:id}).then(function(r){
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Rotated.' : 'Failed.');
        if (r.success && r.data && r.data.secret) {
          window.scrollTo({top: 0, behavior: 'smooth'});
          document.getElementById('webhookSecretValue').textContent = r.data.secret;
          document.getElementById('webhookSecretBox').style.display = '';
        } else {
          btn.disabled = false;
        }
      }).catch(function(){ btn.disabled = false; });
    });
  });

  // ENTERPRISE GAP FIX (Phase 1, item 4 — manual replay action)
  document.querySelectorAll('.webhook-replay').forEach(function(btn){
    btn.addEventListener('click', function(){
      var logId = btn.dataset.logId;
      btn.disabled = true;
      btn.textContent = 'Replaying…';
      post('webhooks.replay', {log_id: logId}).then(function(r){
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Replayed.' : 'Replay failed.');
        window.scrollTo({top: 0, behavior: 'smooth'});
        setTimeout(function(){ location.reload(); }, 1500);
      }).catch(function(){ btn.disabled = false; btn.textContent = 'Replay'; });
    });
  });
})();
</script>
<?php
// ENTERPRISE GAP FIX (Phase 4, item 8 — versioning/rollback for Webhooks)
$configVersionKey = 'webhook_subscriptions';
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
<?php rto_help_box('webhooks'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
