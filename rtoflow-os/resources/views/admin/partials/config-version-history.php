<?php
if (!defined('ABSPATH')) exit;
/**
 * Reusable Config Version History Partial
 *
 * Any admin config screen can `include` this to get a version-history table
 * with a "Rollback to this version" button per row, wired to the generic
 * ConfigVersionController AJAX actions (admin.config_version.history / .publish / .rollback).
 *
 * Required before including:
 *   @var string $configVersionKey   The config_key this screen manages, e.g. 'matching_config'.
 *
 * Optional:
 *   @var string $configVersionContainerId  Override the wrapping element id if you include this
 *                                          partial more than once on the same page (default derived
 *                                          from $configVersionKey).
 *
 * This partial is self-contained: it renders an empty shell and fetches its
 * own data over AJAX on load, so the including controller does not need to
 * query ConfigVersionService itself.
 */
$configVersionKey = $configVersionKey ?? '';
$containerId      = $configVersionContainerId ?? ('rto-cfg-versions-' . preg_replace('/[^a-z0-9_]/i', '-', $configVersionKey));
?>
<div class="rto-card" id="<?= esc_attr($containerId) ?>" data-config-key="<?= esc_attr($configVersionKey) ?>" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Version History</h3>
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Version</th><th>Status</th><th>Created By</th><th>Created</th><th>Notes</th><th></th></tr>
      </thead>
      <tbody class="rto-cfgver-rows">
        <tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8">Loading version history…</td></tr>
      </tbody>
    </table>
    <span class="rto-cfgver-msg" style="display:block;margin-top:8px;font-size:12px"></span>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var root = document.getElementById(<?= wp_json_encode($containerId) ?>);
  if (!root) return;

  var nonce  = (window.rtoflowAdmin || {}).nonce || document.querySelector('meta[name="rto-admin-nonce"]')?.content || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  var configKey = root.dataset.configKey;
  var rows = root.querySelector('.rto-cfgver-rows');
  var msg  = root.querySelector('.rto-cfgver-msg');

  var statusColors = {
    draft:     ['#FEF3C7', '#92400E'],
    published: ['#DCFCE7', '#166534'],
    archived:  ['#F3F4F6', '#6B7280']
  };

  function post(action, extra){
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action',action);
    fd.append('rto_nonce', nonce);
    Object.keys(extra||{}).forEach(function(k){ fd.append(k, extra[k]); });
    return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
  }

  function escapeHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function render(history){
    if (!history || !history.length) {
      rows.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8">No versions saved yet.</td></tr>';
      return;
    }
    rows.innerHTML = history.map(function(v){
      var colors = statusColors[v.status] || statusColors.draft;
      var canRollback = v.status !== 'published';
      return '<tr data-version-id="' + v.id + '">' +
        '<td data-label="Version">#' + v.version_number + '</td>' +
        '<td data-label="Status"><span style="background:' + colors[0] + ';color:' + colors[1] + ';font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">' +
          escapeHtml(v.status.charAt(0).toUpperCase() + v.status.slice(1)) + '</span></td>' +
        '<td data-label="Created By">' + escapeHtml(v.created_by_name || v.created_by || '—') + '</td>' +
        '<td data-label="Created" style="font-size:12px;color:#64748b">' + escapeHtml(v.created_at || '') + '</td>' +
        '<td data-label="Notes" style="max-width:240px;font-size:12px">' + escapeHtml(v.notes || '') + '</td>' +
        '<td data-label="Actions" style="white-space:nowrap">' +
          (canRollback ? '<button class="rto-btn rto-btn--sm rto-cfgver-rollback">Rollback to this version</button>' : '') +
        '</td>' +
      '</tr>';
    }).join('');

    rows.querySelectorAll('.rto-cfgver-rollback').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.closest('tr').dataset.versionId;
        if (!confirm('Roll back to this version? This creates and publishes a new version cloned from it.')) return;
        post('admin.config_version.rollback', {config_key: configKey, to_version_id: id}).then(function(r){
          msg.style.color = r.success ? '#166534' : '#991B1B';
          msg.textContent = r.message || (r.success ? 'Rolled back.' : 'Rollback failed.');
          if (r.success) load();
        });
      });
    });
  }

  function load(){
    post('admin.config_version.history', {config_key: configKey}).then(function(r){
      if (r.success) {
        render(r.data.history);
      } else {
        rows.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#991B1B">Could not load version history.</td></tr>';
      }
    });
  }

  load();
})();
</script>
