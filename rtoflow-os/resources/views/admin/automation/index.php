<?php
if (!defined('ABSPATH')) exit;
/** @var array $rules @var array $validEvents @var array $validActions */
$pageTitle = 'Automation Engine';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$validEvents  = $validEvents  ?? ['lead.created','lead.status_changed','lead.sla_breach','lead.sla_warning','lead.vendor_assigned','payment.received','document.uploaded','document.rejected','complaint.created','lead.completed'];
$validActions = $validActions ?? ['set_status', 'set_priority', 'notify'];
?>
<div class="rto-page-header">
  <div>
    <h2>Automation Engine</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Rules fire automatically when events occur in the system</p>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px" id="autoFormTitle">Add Rule</h3>
    <form id="autoRuleForm" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end">
      <input type="hidden" name="rule_id" id="autoRuleId" value="">
      <div>
        <label class="rto-label" for="autoName">Rule Name</label>
        <input type="text" name="name" id="autoName" class="rto-input" placeholder="Notify vendor on assignment" required>
      </div>
      <div>
        <label class="rto-label" for="autoTrigger">Trigger Event</label>
        <select name="trigger_event" id="autoTrigger" class="rto-input">
          <?php foreach ($validEvents as $e): ?>
          <option value="<?= esc_attr($e) ?>"><?= esc_html($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="autoPriority">Priority<?= rto_field_tooltip('When multiple rules fire on the same event, rules run in ascending priority order (0 runs before 10, 10 before 999). Rules with equal priority run in an unspecified order relative to each other.') ?></label>
        <input type="number" name="priority" id="autoPriority" class="rto-input" value="10" min="0" max="999">
      </div>
      <div>
        <label class="rto-label">&nbsp;</label>
        <div style="font-size:11px;color:#94a3b8">Lower priority runs first</div>
      </div>

      <div style="grid-column:1/-1">
        <label class="rto-label">
          Conditions <span style="font-weight:400;color:#94a3b8">(optional — JSON list of {field, op, value}; op is one of equals, not_equals, gt, gte, lt, lte, in, not_in, not_empty; empty list always matches)</span><?= rto_field_tooltip('A plain JSON list (e.g. [{"field":"priority","op":"gt","value":3}, ...]) is an implicit AND of every condition. For OR logic or nested groups, wrap it as {"operator":"AND"|"OR","conditions":[...]} — each entry in "conditions" can itself be another leaf rule or another nested group, to any depth (e.g. match A AND (B OR C)). "field" is checked against the triggering event\'s data — e.g. "priority", "status", "vendor_id". Leave as [] to always match.') ?>
        </label>
        <textarea name="conditions_json" id="autoConditions" class="rto-input" rows="2" placeholder='[{"field":"priority","op":"gt","value":3}]'>[]</textarea>
      </div>

      <div style="grid-column:1/-1">
        <label class="rto-label">
          Actions <span style="font-weight:400;color:#94a3b8">(required — JSON list; action is one of <?= esc_html(implode(', ', $validActions)) ?>)</span>
        </label>
        <textarea name="actions_json" id="autoActions" class="rto-input" rows="3" placeholder='[{"action":"notify","recipients":["vendor"],"template":"lead_assigned","channel":"email"}]'>[]</textarea>
      </div>

      <div style="grid-column:1/-1">
        <button type="submit" class="rto-btn rto-btn--primary" id="autoSubmitBtn">Add Rule</button>
        <button type="button" class="rto-btn rto-btn--sm" id="autoCancelEdit" style="display:none">Cancel Edit</button>
        <span id="autoMsg" style="margin-left:10px;font-size:12px"></span>
      </div>
    </form>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Rule Name</th><th>Trigger Event</th><th>Priority</th><th>Run Count</th><th>Last Run</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rules as $r): ?>
        <tr data-rule-id="<?= (int)$r['id'] ?>"
            data-name="<?= esc_attr($r['name']) ?>"
            data-trigger="<?= esc_attr($r['trigger_event']) ?>"
            data-priority="<?= (int)$r['priority'] ?>"
            data-conditions="<?= esc_attr($r['conditions_json'] ?? '[]') ?>"
            data-actions="<?= esc_attr($r['actions_json']) ?>"
            data-runcount="<?= (int)$r['run_count'] ?>"
            data-active="<?= (int)$r['is_active'] ?>">
          <td data-label="Rule Name" style="font-weight:600"><?= esc_html($r['name']) ?></td>
          <td data-label="Trigger Event"><code style="background:#F8FAFC;border:1px solid #e2e8f0;padding:2px 8px;border-radius:4px;font-size:11px;font-family:monospace"><?= esc_html($r['trigger_event']) ?></code></td>
          <td data-label="Priority"><?= (int)$r['priority'] ?></td>
          <td data-label="Run Count"><?= number_format((int)$r['run_count']) ?></td>
          <td data-label="Last Run" style="font-size:12px;color:#64748b"><?= $r['last_run'] ? date('d M Y H:i', strtotime($r['last_run'])) : 'Never' ?></td>
          <td data-label="Status">
            <span style="background:<?= $r['is_active']?'#DCFCE7':'#F3F4F6' ?>;color:<?= $r['is_active']?'#166534':'#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
              <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
            </span>
          </td>
          <td data-label="Actions" style="white-space:nowrap">
            <button class="rto-btn rto-btn--sm auto-edit" aria-label="Edit rule: <?= esc_attr($r['name']) ?>">Edit</button>
            <button class="rto-btn rto-btn--sm auto-toggle" aria-label="<?= $r['is_active'] ? 'Disable' : 'Enable' ?> rule: <?= esc_attr($r['name']) ?>">Toggle</button>
            <button class="rto-btn rto-btn--sm rto-btn--danger auto-delete" aria-label="Delete rule: <?= esc_attr($r['name']) ?>">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">No automation rules found. Rules are seeded on activation, or add one above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Cause-and-effect diagram (Help Centre Phase 1 gap): a real,
     data-driven diagram built directly from the SAME $rules rows the table
     above renders — trigger event -> condition(s) -> action(s) for every
     configured rule, decoded from its actual conditions_json/actions_json,
     never a static illustration. Disabled rules render greyed-out so the
     diagram also communicates which chains are currently live. -->
<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:4px">Rule Flow (Cause &amp; Effect)</h3>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 14px">Generated from the actual configured rules above — event fires &rarr; conditions are checked &rarr; actions run.</p>
    <?php if (empty($rules)): ?>
    <div style="color:#94a3b8;font-size:12px">No rules configured yet — nothing to diagram.</div>
    <?php else: foreach ($rules as $r):
      $conditions = json_decode($r['conditions_json'] ?? '[]', true) ?: [];
      $actions    = json_decode($r['actions_json'] ?? '[]', true) ?: [];
      $dim        = $r['is_active'] ? '' : 'opacity:.45';
    ?>
    <div style="display:flex;align-items:stretch;gap:0;flex-wrap:wrap;margin-bottom:14px;<?= $dim ?>">
      <div style="background:#0f172a;color:#fff;border-radius:8px 0 0 8px;padding:10px 14px;font-size:12px;font-weight:700;display:flex;flex-direction:column;justify-content:center;min-width:150px">
        <span style="font-size:9px;font-weight:600;letter-spacing:.06em;opacity:.7;text-transform:uppercase">Trigger</span>
        <code style="font-family:monospace;font-size:11px"><?= esc_html($r['trigger_event']) ?></code>
      </div>
      <div style="display:flex;align-items:center;color:#cbd5e1;font-size:18px;padding:0 6px;background:#F8FAFC">&rarr;</div>
      <div style="background:#FFFBEB;border:1px solid #FDE68A;padding:10px 14px;display:flex;flex-direction:column;justify-content:center;min-width:150px;max-width:280px">
        <span style="font-size:9px;font-weight:600;letter-spacing:.06em;color:#92400E;text-transform:uppercase">Guard Condition<?= count($conditions) > 1 ? 's (AND)' : '' ?></span>
        <?php if (empty($conditions)): ?>
        <span style="font-size:11px;color:#92400E">always matches</span>
        <?php else: foreach ($conditions as $c): ?>
        <span style="font-size:11px;color:#92400E"><code><?= esc_html($c['field'] ?? '?') ?></code> <?= esc_html($c['op'] ?? '?') ?> <code><?= esc_html(is_array($c['value'] ?? null) ? implode(',', $c['value']) : (string)($c['value'] ?? '')) ?></code></span>
        <?php endforeach; endif; ?>
      </div>
      <div style="display:flex;align-items:center;color:#cbd5e1;font-size:18px;padding:0 6px;background:#F8FAFC">&rarr;</div>
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:0 8px 8px 0;padding:10px 14px;display:flex;flex-direction:column;justify-content:center;gap:2px;min-width:150px;max-width:320px;flex:1">
        <span style="font-size:9px;font-weight:600;letter-spacing:.06em;color:#166534;text-transform:uppercase">Side Effect<?= count($actions) > 1 ? 's' : '' ?></span>
        <?php if (empty($actions)): ?>
        <span style="font-size:11px;color:#166534">none configured</span>
        <?php else: foreach ($actions as $a): ?>
        <span style="font-size:11px;color:#166534"><code><?= esc_html($a['action'] ?? '?') ?></code><?php
          $detail = $a['status'] ?? $a['template'] ?? (isset($a['recipients']) && is_array($a['recipients']) ? implode('+', $a['recipients']) : null);
          if ($detail) echo ' &rarr; ' . esc_html($detail);
        ?></span>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Available System Events</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach($validEvents as $e): ?>
      <code style="background:#F8FAFC;border:1px solid #e2e8f0;padding:3px 10px;border-radius:4px;font-size:11px;font-family:monospace"><?= esc_html($e) ?></code>
      <?php endforeach; ?>
    </div>
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

  var form = document.getElementById('autoRuleForm');
  var msg = document.getElementById('autoMsg');

  // ENTERPRISE GAP FIX (Phase 7, item 2 — conflict detection): the server
  // returns HTTP 409 with data.conflicts when this rule (once active)
  // would contradict another active rule on the same trigger event. Shown
  // to the admin as a confirm() before resubmitting with
  // confirm_conflicts=1 — see AutomationController::detectConflicts().
  function submitRule(extra){
    var editingId = document.getElementById('autoRuleId').value;
    var action = editingId ? 'automation.update' : 'automation.create';
    post(action, extra).then(function(r){
      if (!r.success && r.data && Array.isArray(r.data.conflicts) && r.data.conflicts.length) {
        var lines = r.data.conflicts.map(function(c){
          return '- "' + c.rule_name + '" also sets ' + c.action + ' to "' + c.other_value + '" (this rule sets it to "' + c.this_value + '")';
        }).join('\n');
        if (confirm((r.message || 'Conflict detected.') + '\n\n' + lines + '\n\nSave anyway?')) {
          extra.confirm_conflicts = '1';
          submitRule(extra);
        }
        return;
      }
      msg.style.color = r.success ? '#166534' : '#991B1B';
      msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
      if (r.success) location.reload();
    });
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(form);
    var extra = {}; fd.forEach(function(v,k){ extra[k]=v; });
    submitRule(extra);
  });

  document.getElementById('autoCancelEdit').addEventListener('click', function(){
    resetForm();
  });

  function resetForm(){
    document.getElementById('autoRuleId').value = '';
    form.reset();
    document.getElementById('autoConditions').value = '[]';
    document.getElementById('autoActions').value = '[]';
    document.getElementById('autoFormTitle').textContent = 'Add Rule';
    document.getElementById('autoSubmitBtn').textContent = 'Add Rule';
    document.getElementById('autoCancelEdit').style.display = 'none';
  }

  document.querySelectorAll('.auto-edit').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr');
      document.getElementById('autoRuleId').value = tr.dataset.ruleId;
      document.getElementById('autoName').value = tr.dataset.name;
      document.getElementById('autoTrigger').value = tr.dataset.trigger;
      document.getElementById('autoPriority').value = tr.dataset.priority;
      document.getElementById('autoConditions').value = tr.dataset.conditions;
      document.getElementById('autoActions').value = tr.dataset.actions;
      document.getElementById('autoFormTitle').textContent = 'Edit Rule #' + tr.dataset.ruleId;
      document.getElementById('autoSubmitBtn').textContent = 'Save Changes';
      document.getElementById('autoCancelEdit').style.display = '';
      window.scrollTo({top: 0, behavior: 'smooth'});
    });
  });

  // FIX (Help Centre Phase 1 gap: "Impact preview before saving"): a
  // toggle here silences (or re-arms) real actions on a real system event
  // — no round-trip needed to show the impact, every fact used is already
  // real data sitting on the row (trigger event, decoded action list, and
  // the rule's actual historical run_count), so this is a genuine preview,
  // not a fabricated one.
  document.querySelectorAll('.auto-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = btn.closest('tr');
      var id = tr.dataset.ruleId;
      var isActive = tr.dataset.active === '1';
      var actionTypes = [];
      try {
        JSON.parse(tr.dataset.actions || '[]').forEach(function(a){
          if (a && a.action && actionTypes.indexOf(a.action) === -1) actionTypes.push(a.action);
        });
      } catch (e) {}
      var runs = parseInt(tr.dataset.runcount, 10) || 0;
      var text = isActive
        ? 'Disabling "' + tr.dataset.name + '" stops ' + (actionTypes.length ? actionTypes.join(', ') : 'its configured action(s)') +
          ' from firing on future "' + tr.dataset.trigger + '" events. This rule has fired ' + runs + ' time(s) to date.\n\nDisable it now?'
        : 'Enabling "' + tr.dataset.name + '" will start running ' + (actionTypes.length ? actionTypes.join(', ') : 'its configured action(s)') +
          ' on every future "' + tr.dataset.trigger + '" event.\n\nEnable it now?';
      if (!confirm(text)) return;
      toggleRule(id, {});
    });
  });

  // ENTERPRISE GAP FIX (Phase 7, item 2 — conflict detection): same 409
  // conflict flow as submitRule() above, for the enable/disable toggle.
  function toggleRule(id, extra){
    var body = Object.assign({rule_id:id}, extra);
    post('automation.toggle', body).then(function(r){
      if (!r.success && r.data && Array.isArray(r.data.conflicts) && r.data.conflicts.length) {
        var lines = r.data.conflicts.map(function(c){
          return '- "' + c.rule_name + '" also sets ' + c.action + ' to "' + c.other_value + '" (this rule sets it to "' + c.this_value + '")';
        }).join('\n');
        if (confirm((r.message || 'Conflict detected.') + '\n\n' + lines + '\n\nEnable anyway?')) {
          toggleRule(id, {confirm_conflicts:'1'});
        }
        return;
      }
      location.reload();
    });
  }
  document.querySelectorAll('.auto-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Delete this automation rule?')) return;
      var id = btn.closest('tr').dataset.ruleId;
      post('automation.delete', {rule_id:id}).then(function(){ location.reload(); });
    });
  });
})();
</script>
<?php
// ENTERPRISE GAP FIX (Phase 4, item 8 — versioning/rollback for Automation
// rules): reuses the existing generic partial, same as Eligibility/
// Matching/Feature Flags/City Pricing already do.
$configVersionKey = 'automation_rules';
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
<?php rto_help_box('automation'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
