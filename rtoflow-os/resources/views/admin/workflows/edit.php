<?php
if (!defined('ABSPATH')) exit;
/** @var array|null $service @var int $serviceId @var array|null $definition @var array $states @var array $transitions */
$pageTitle = 'Workflow Builder — ' . ($service['name'] ?? 'Service');
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-header">
  <div>
    <h2>Workflow — <?= esc_html($service['name'] ?? '') ?></h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">
      <?= $definition ? 'This service has a custom workflow. Disable it to fall back to the default lifecycle.' : 'This service currently uses the default lead lifecycle. Enable a custom workflow to define your own states and transitions.' ?>
    </p>
  </div>
  <a class="rto-btn rto-btn--sm" href="?page=rto-admin&rto_page=workflows">&larr; Back to services</a>
</div>

<?php if (!$definition): ?>
<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <form id="wfCreateForm" style="display:flex;gap:10px;align-items:end">
      <input type="hidden" name="service_id" value="<?= (int)$serviceId ?>">
      <div>
        <label class="rto-label" for="wfName">Workflow name</label>
        <input type="text" id="wfName" name="name" class="rto-input" placeholder="Custom workflow" value="<?= esc_attr(($service['name'] ?? '') . ' workflow') ?>">
      </div>
      <button type="submit" class="rto-btn rto-btn--primary">Enable Custom Workflow</button>
      <span id="wfCreateMsg" style="font-size:12px"></span>
    </form>
  </div>
</div>
<?php else: ?>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <strong><?= esc_html($definition['name']) ?></strong>
      <span style="margin-left:10px;background:<?= $definition['is_active']?'#DCFCE7':'#F3F4F6' ?>;color:<?= $definition['is_active']?'#166534':'#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
        <?= $definition['is_active'] ? 'Active (custom)' : 'Disabled (using default)' ?>
      </span>
    </div>
    <button class="rto-btn rto-btn--sm" id="wfToggleBtn" data-definition-id="<?= (int)$definition['id'] ?>">
      <?= $definition['is_active'] ? 'Revert to Default Workflow' : 'Re-enable Custom Workflow' ?>
    </button>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">States</h3>
    <form id="wfStateForm" style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;align-items:end;margin-bottom:16px">
      <input type="hidden" name="workflow_definition_id" value="<?= (int)$definition['id'] ?>">
      <div>
        <label class="rto-label" for="wfStatusKey">Status key</label>
        <input type="text" id="wfStatusKey" name="status_key" class="rto-input" placeholder="docs_pending" required>
      </div>
      <div>
        <label class="rto-label" for="wfStateLabel">Label</label>
        <input type="text" id="wfStateLabel" name="label" class="rto-input" placeholder="Documents Pending" required>
      </div>
      <div>
        <label class="rto-label" for="wfIsTerminal">Terminal?<?= rto_field_tooltip('Marks this a final state for the order — once reached, no further status changes are expected (e.g. Completed, Cancelled). It does not by itself block transitions; you still control which moves are allowed via the Transitions table below.') ?></label>
        <select id="wfIsTerminal" name="is_terminal" class="rto-input">
          <option value="0">No</option>
          <option value="1">Yes</option>
        </select>
      </div>
      <div>
        <label class="rto-label" for="wfDisplayOrder">Order</label>
        <input type="number" id="wfDisplayOrder" name="display_order" class="rto-input" value="0" min="0">
      </div>
      <div>
        <button type="submit" class="rto-btn rto-btn--primary">Add State</button>
      </div>
    </form>
    <span id="wfStateMsg" style="font-size:12px"></span>

    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>Order</th><th>Status key</th><th>Label</th><th>Terminal</th><th></th></tr></thead>
      <tbody id="wfStatesBody">
        <?php foreach ($states as $s): ?>
        <tr data-state-id="<?= (int)$s['id'] ?>">
          <td data-label="Order"><?= (int)$s['display_order'] ?></td>
          <td data-label="Status key"><code><?= esc_html($s['status_key']) ?></code></td>
          <td data-label="Label"><?= esc_html($s['label']) ?></td>
          <td data-label="Terminal"><?= $s['is_terminal'] ? 'Yes' : 'No' ?></td>
          <td data-label="Actions"><button class="rto-btn rto-btn--sm rto-btn--danger wf-state-delete" aria-label="Delete state: <?= esc_attr($s['label']) ?>">Delete</button></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($states)): ?>
        <tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8">No states defined yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Transitions</h3>
    <form id="wfTransitionForm" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end;margin-bottom:16px">
      <input type="hidden" name="workflow_definition_id" value="<?= (int)$definition['id'] ?>">
      <div>
        <label class="rto-label" for="wfFromStatus">From</label>
        <select id="wfFromStatus" name="from_status" class="rto-input" required>
          <?php foreach ($states as $s): ?>
          <option value="<?= esc_attr($s['status_key']) ?>"><?= esc_html($s['label']) ?> (<?= esc_html($s['status_key']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="wfToStatus">To</label>
        <select id="wfToStatus" name="to_status" class="rto-input" required>
          <?php foreach ($states as $s): ?>
          <option value="<?= esc_attr($s['status_key']) ?>"><?= esc_html($s['label']) ?> (<?= esc_html($s['status_key']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="wfRequiresRole">Requires role<?= rto_field_tooltip('Restricts who can move an order across this specific From → To transition. Enforced only when the acting user\'s role is known to the caller — if no role could be resolved for that request, this check is skipped and the transition is allowed regardless of the role picked here.') ?></label>
        <select id="wfRequiresRole" name="requires_role" class="rto-input">
          <option value="">Any</option>
          <option value="admin">Admin</option>
          <option value="staff">Staff</option>
          <option value="vendor">Vendor</option>
          <option value="client">Client</option>
        </select>
      </div>
      <div style="grid-column:1/-1">
        <label class="rto-label">Guard condition (optional)<?= rto_field_tooltip('All rows are ANDed together — the transition is only allowed if EVERY row you fill in is true for the lead being moved. Leave every row blank for no guard (always allowed, same as today). Evaluated against the lead\'s own current field values at the moment of the status change.') ?></label>
        <?php for ($gi = 0; $gi < 3; $gi++): ?>
        <div style="display:flex;gap:6px;margin-bottom:4px">
          <select name="guard_field[]" class="rto-input" style="max-width:150px">
            <option value="">— not used —</option>
            <?php foreach (['status','priority','total_amount','city_id','service_id','vendor_id','client_id','risk_score','source','lead_id'] as $gf): ?>
            <option value="<?= esc_attr($gf) ?>"><?= esc_html($gf) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="guard_op[]" class="rto-input" style="max-width:130px">
            <option value="equals">equals</option>
            <option value="not_equals">not equals</option>
            <option value="gt">&gt;</option>
            <option value="gte">&gt;=</option>
            <option value="lt">&lt;</option>
            <option value="lte">&lt;=</option>
            <option value="in">in (comma list)</option>
            <option value="not_in">not in (comma list)</option>
            <option value="not_empty">is not empty</option>
          </select>
          <input type="text" name="guard_value[]" class="rto-input" placeholder="value">
        </div>
        <?php endfor; ?>
      </div>
      <div>
        <button type="submit" class="rto-btn rto-btn--primary" <?= empty($states) ? 'disabled' : '' ?>>Add Transition</button>
      </div>
    </form>
    <span id="wfTransitionMsg" style="font-size:12px"></span>

    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>From</th><th>To</th><th>Requires role</th><th>Guard</th><th></th></tr></thead>
      <tbody id="wfTransitionsBody">
        <?php foreach ($transitions as $t): ?>
        <?php
          $guardRules = [];
          if (!empty($t['guard_condition_json'])) {
              $decoded = json_decode($t['guard_condition_json'], true);
              if (is_array($decoded)) $guardRules = $decoded;
          }
        ?>
        <tr data-transition-id="<?= (int)$t['id'] ?>" data-guard-json='<?= esc_attr(wp_json_encode($guardRules)) ?>'>
          <td data-label="From"><code><?= esc_html($t['from_status']) ?></code></td>
          <td data-label="To"><code><?= esc_html($t['to_status']) ?></code></td>
          <td data-label="Requires role"><?= esc_html($t['requires_role'] ?: 'Any') ?></td>
          <td data-label="Guard">
            <?php if ($guardRules): ?>
              <span class="rto-small" title="<?= esc_attr($t['guard_condition_json']) ?>">
                <?= esc_html(implode(' AND ', array_map(function($r){ return ($r['field'] ?? '?') . ' ' . ($r['op'] ?? '?') . ' ' . (is_array($r['value'] ?? null) ? implode(',', $r['value']) : ($r['value'] ?? '')); }, $guardRules))) ?>
              </span>
            <?php else: ?>
              <span class="rto-small rto-muted">None</span>
            <?php endif; ?>
            <button type="button" class="rto-btn rto-btn--sm rto-btn-outline wf-transition-edit-guard" aria-label="Edit guard for transition: <?= esc_attr($t['from_status']) ?> to <?= esc_attr($t['to_status']) ?>" style="margin-left:6px">Edit</button>
          </td>
          <td data-label="Actions"><button class="rto-btn rto-btn--sm rto-btn--danger wf-transition-delete" aria-label="Delete transition: <?= esc_attr($t['from_status']) ?> to <?= esc_attr($t['to_status']) ?>">Delete</button></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($transitions)): ?>
        <tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8">No transitions defined yet — no status changes will be allowed until you add some.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || document.querySelector('meta[name="rto-admin-nonce"]')?.content || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';

  function post(action, extra){
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action',action);
    fd.append('rto_nonce', nonce);
    Object.keys(extra||{}).forEach(function(k){
      var v = extra[k];
      if (Array.isArray(v)) { v.forEach(function(item){ fd.append(k, item); }); }
      else { fd.append(k, v); }
    });
    return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
  }

  // Groups repeated form field names (e.g. multiple "guard_field[]" inputs)
  // into arrays instead of the naive fd.forEach(function(v,k){extra[k]=v})
  // pattern the other forms use, which would silently keep only the LAST
  // value for any repeated key — wrong for the 3-row guard builder below.
  function extractFields(form){
    var fd = new FormData(form), extra = {};
    fd.forEach(function(v,k){
      if (Object.prototype.hasOwnProperty.call(extra, k)) {
        if (!Array.isArray(extra[k])) extra[k] = [extra[k]];
        extra[k].push(v);
      } else {
        extra[k] = v;
      }
    });
    return extra;
  }

  var createForm = document.getElementById('wfCreateForm');
  if (createForm) {
    createForm.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(e.target);
      var extra = {}; fd.forEach(function(v,k){ extra[k]=v; });
      post('workflows.create_definition', extra).then(function(r){
        var msg = document.getElementById('wfCreateMsg');
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
        if (r.success) location.reload();
      });
    });
  }

  var toggleBtn = document.getElementById('wfToggleBtn');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function(){
      post('workflows.toggle_definition', {definition_id: toggleBtn.dataset.definitionId}).then(function(){ location.reload(); });
    });
  }

  var stateForm = document.getElementById('wfStateForm');
  if (stateForm) {
    stateForm.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(e.target);
      var extra = {}; fd.forEach(function(v,k){ extra[k]=v; });
      post('workflows.add_state', extra).then(function(r){
        var msg = document.getElementById('wfStateMsg');
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
        if (r.success) location.reload();
      });
    });
  }

  document.querySelectorAll('.wf-state-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Delete this state?')) return;
      var id = btn.closest('tr').dataset.stateId;
      post('workflows.delete_state', {state_id:id}).then(function(){ location.reload(); });
    });
  });

  var transitionForm = document.getElementById('wfTransitionForm');
  if (transitionForm) {
    transitionForm.addEventListener('submit', function(e){
      e.preventDefault();
      var extra = extractFields(e.target);
      post('workflows.add_transition', extra).then(function(r){
        var msg = document.getElementById('wfTransitionMsg');
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
        if (r.success) location.reload();
      });
    });
  }

  document.querySelectorAll('.wf-transition-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Delete this transition?')) return;
      var id = btn.closest('tr').dataset.transitionId;
      post('workflows.delete_transition', {transition_id:id}).then(function(){ location.reload(); });
    });
  });

  // Inline guard editor: expands a 3-row field/op/value builder (same shape
  // as the "Add Transition" form's guard rows) directly under the clicked
  // transition's row, pre-filled from its current guard_condition_json.
  var guardFieldOptions = ['','status','priority','total_amount','city_id','service_id','vendor_id','client_id','risk_score','source','lead_id'];
  var guardOpOptions = [['equals','equals'],['not_equals','not equals'],['gt','&gt;'],['gte','&gt;='],['lt','&lt;'],['lte','&lt;='],['in','in (comma list)'],['not_in','not in (comma list)'],['not_empty','is not empty']];

  document.querySelectorAll('.wf-transition-edit-guard').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr');
      var existing = tr.nextElementSibling && tr.nextElementSibling.classList.contains('wf-guard-editor-row');
      if (existing) { tr.nextElementSibling.remove(); return; }

      var id = tr.dataset.transitionId;
      var current = [];
      try { current = JSON.parse(tr.dataset.guardJson || 'null') || []; } catch(e) { current = []; }

      var editorRow = document.createElement('tr');
      editorRow.className = 'wf-guard-editor-row';
      var td = document.createElement('td');
      td.colSpan = 5;
      td.style.background = '#f8fafc';
      td.style.padding = '10px';

      var rowsHtml = '';
      for (var i = 0; i < 3; i++) {
        var c = current[i] || {};
        rowsHtml += '<div style="display:flex;gap:6px;margin-bottom:4px">'
          + '<select class="rto-input guard-edit-field" style="max-width:150px">'
          + guardFieldOptions.map(function(f){ return '<option value="'+f+'"'+(c.field===f?' selected':'')+'>'+(f||'— not used —')+'</option>'; }).join('')
          + '</select>'
          + '<select class="rto-input guard-edit-op" style="max-width:130px">'
          + guardOpOptions.map(function(o){ return '<option value="'+o[0]+'"'+(c.op===o[0]?' selected':'')+'>'+o[1]+'</option>'; }).join('')
          + '</select>'
          + '<input type="text" class="rto-input guard-edit-value" placeholder="value" value="'+(c.value!==undefined && c.value!==null ? String(c.value).replace(/"/g,'&quot;') : '')+'">'
          + '</div>';
      }
      rowsHtml += '<button type="button" class="rto-btn rto-btn--sm rto-btn--primary wf-guard-save">Save Guard</button> '
        + '<span class="wf-guard-msg rto-small" style="margin-left:8px"></span>';
      td.innerHTML = rowsHtml;
      editorRow.appendChild(td);
      tr.parentNode.insertBefore(editorRow, tr.nextSibling);

      td.querySelector('.wf-guard-save').addEventListener('click', function(){
        var fields = Array.from(td.querySelectorAll('.guard-edit-field')).map(function(s){ return s.value; });
        var ops    = Array.from(td.querySelectorAll('.guard-edit-op')).map(function(s){ return s.value; });
        var values = Array.from(td.querySelectorAll('.guard-edit-value')).map(function(i){ return i.value; });
        var msg = td.querySelector('.wf-guard-msg');
        post('workflows.update_transition_guard', {transition_id: id, guard_field: fields, guard_op: ops, guard_value: values}).then(function(r){
          msg.style.color = r.success ? '#166534' : '#991B1B';
          msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
          if (r.success) location.reload();
        });
      });
    });
  });
})();
</script>

<?php if ($definition): ?>
<?php
// ENTERPRISE GAP FIX (Phase 4, item 8 — versioning/rollback for Workflow
// Definitions): keyed per-definition id — see WorkflowBuilderController::
// applyVersionedPayload() for why states+transitions restore together.
$configVersionKey = 'workflow_definition_' . (int)$definition['id'];
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
<?php endif; ?>
<?php rto_help_box('workflows'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
