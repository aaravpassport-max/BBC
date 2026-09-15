<?php
if (!defined('ABSPATH')) exit;
/** @var array $rules @var array $services @var int $serviceId @var array $recentChecks */
$pageTitle = 'Eligibility Rules Engine';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-header">
  <div>
    <h2>Eligibility Rules Engine</h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">
      Configure who is eligible for each service — no developer required. A rule with no service selected applies to every service.
    </p>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Add Rule</h3>
    <form id="eligRuleForm" style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;align-items:end">
      <div>
        <label class="rto-label" for="eligService">Service</label>
        <select name="service_id" id="eligService" class="rto-input">
          <option value="">All services</option>
          <?php foreach ($services as $svc): ?>
          <option value="<?= (int)$svc['id'] ?>"><?= esc_html($svc['category'] . ' — ' . $svc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="eligFieldKey">Field Key</label>
        <input type="text" name="field_key" id="eligFieldKey" class="rto-input" placeholder="applicant_age" required>
      </div>
      <div>
        <label class="rto-label" for="eligFieldLabel">Field Label</label>
        <input type="text" name="field_label" id="eligFieldLabel" class="rto-input" placeholder="Applicant Age" required>
      </div>
      <div>
        <label class="rto-label" for="eligOperator">Operator<?= rto_field_tooltip('"in" / "not in" compare against a comma-separated list typed in Value (e.g. "delhi, mumbai"). "not empty" ignores Value entirely — it only checks the applicant actually answered this field. "greater/less than" only match when both the answer and Value are numeric; anything else fails that rule.') ?></label>
        <select name="operator" id="eligOperator" class="rto-input">
          <option value="equals">equals</option>
          <option value="not_equals">not equals</option>
          <option value="gt">greater than</option>
          <option value="gte">greater or equal</option>
          <option value="lt">less than</option>
          <option value="lte">less or equal</option>
          <option value="in">in (comma list)</option>
          <option value="not_in">not in (comma list)</option>
          <option value="not_empty">not empty</option>
        </select>
      </div>
      <div>
        <label class="rto-label" for="eligValue">Value</label>
        <input type="text" name="value" id="eligValue" class="rto-input" placeholder="18">
      </div>
      <div>
        <label class="rto-label" for="eligSeverity">Severity<?= rto_field_tooltip('Block makes the applicant ineligible for this service outright — they cannot proceed. Warn records the failure and shows the message but still lets them continue. Every active rule is evaluated regardless of severity, so a warn rule never suppresses a block rule elsewhere.') ?></label>
        <select name="severity" id="eligSeverity" class="rto-input">
          <option value="block">Block (hard stop)</option>
          <option value="warn">Warn (allow with notice)</option>
        </select>
      </div>
      <div style="grid-column:1/6">
        <label class="rto-label" for="eligFailMessage">Message shown to applicant on failure</label>
        <input type="text" name="fail_message" id="eligFailMessage" class="rto-input" placeholder="You must be at least 18 years old to apply for this licence." required>
      </div>
      <div>
        <label class="rto-label" for="eligPriority">Priority<?= rto_field_tooltip('Controls only the order rules are checked and listed in (lower runs first; service-specific rules are always checked before rules that apply to all services). Every active rule still runs regardless of priority — this does not let one rule skip or override another.') ?></label>
        <input type="number" name="priority" id="eligPriority" class="rto-input" value="10" min="0" max="999">
      </div>
      <div style="grid-column:1/-1">
        <button type="submit" class="rto-btn rto-btn--primary">Add Rule</button>
        <span id="eligMsg" style="margin-left:10px;font-size:12px"></span>
      </div>
    </form>
  </div>
</div>

<!-- Known Limitations audit fix: "No bulk enable/disable for rules
     belonging to the same service ... requires toggling each of that
     service's rules off individually." -->
<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    <div>
      <label class="rto-label" for="eligBulkService">Bulk enable/disable rules for</label>
      <select id="eligBulkService" class="rto-input">
        <option value="0">All services (service_id IS NULL rules)</option>
        <?php foreach ($services as $svc): ?>
        <option value="<?= (int)$svc['id'] ?>"><?= esc_html($svc['category'] . ' — ' . $svc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" class="rto-btn rto-btn--sm" id="eligBulkEnable">Enable All</button>
    <button type="button" class="rto-btn rto-btn--sm rto-btn--danger" id="eligBulkDisable">Disable All</button>
    <span id="eligBulkMsg" style="font-size:12px"></span>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body" style="padding:0">
    <table class="rto-table" data-rto-responsive="cards">
      <thead>
        <tr><th>Service</th><th>Field</th><th>Condition</th><th>Severity</th><th>Message</th><th>Priority</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rules as $r): $value = json_decode($r['value_json'] ?? 'null', true); ?>
        <tr data-rule-id="<?= (int)$r['id'] ?>">
          <td data-label="Service"><?= $r['service_id'] ? esc_html($r['service_name']) : '<em>All services</em>' ?></td>
          <td data-label="Field"><code><?= esc_html($r['field_key']) ?></code><br><span style="font-size:11px;color:#94a3b8"><?= esc_html($r['field_label']) ?></span></td>
          <td data-label="Condition"><code><?= esc_html($r['operator']) ?></code> <?= esc_html(is_array($value) ? implode(', ', $value) : (string)$value) ?></td>
          <td data-label="Severity">
            <span style="background:<?= $r['severity']==='block'?'#FEE2E2':'#FEF3C7' ?>;color:<?= $r['severity']==='block'?'#991B1B':'#92400E' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
              <?= esc_html(ucfirst($r['severity'])) ?>
            </span>
          </td>
          <td data-label="Message" style="max-width:260px;font-size:12px"><?= esc_html($r['fail_message']) ?></td>
          <td data-label="Priority"><?= (int)$r['priority'] ?></td>
          <td data-label="Status">
            <span style="background:<?= $r['is_active']?'#DCFCE7':'#F3F4F6' ?>;color:<?= $r['is_active']?'#166534':'#6B7280' ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px">
              <?= $r['is_active'] ? 'Active' : 'Disabled' ?>
            </span>
          </td>
          <td data-label="Actions" style="white-space:nowrap">
            <button class="rto-btn rto-btn--sm elig-toggle" aria-label="<?= $r['is_active'] ? 'Disable' : 'Enable' ?> eligibility rule for <?= esc_attr($r['field_label']) ?>">Toggle</button>
            <button class="rto-btn rto-btn--sm rto-btn--danger elig-delete" aria-label="Delete eligibility rule for <?= esc_attr($r['field_label']) ?>">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">No eligibility rules configured yet — every applicant is currently eligible for every service.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="rto-card">
  <div class="rto-card__body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Recent Eligibility Checks</h3>
    <table class="rto-table" data-rto-responsive="cards">
      <thead><tr><th>Service</th><th>Client</th><th>Result</th><th>Why</th><th>Checked</th></tr></thead>
      <tbody>
        <?php foreach ($recentChecks as $chk):
          // Known Limitations audit fix: "reviewing an old check-log entry
          // for a since-deleted rule shows the outcome ... but not a
          // human-readable description of what that rule actually
          // required." ROOT-CAUSE CORRECTION: EligibilityService::evaluate()
          // has always stored a denormalized snapshot (field_label +
          // fail_message per failed rule, not just a rule_id) in
          // failed_rules_json — the data already survives rule deletion.
          // The real gap was narrower: this table simply never rendered
          // that column at all. Fixed by decoding and showing it here.
          $failedRules = json_decode($chk['failed_rules_json'] ?? '[]', true) ?: [];
        ?>
        <tr>
          <td data-label="Service"><?= esc_html($chk['service_name'] ?? '—') ?></td>
          <td data-label="Client"><?= $chk['client_id'] ? (int)$chk['client_id'] : '<em>Anonymous</em>' ?></td>
          <td data-label="Result"><?= $chk['eligible'] ? '<span style="color:#166534">Eligible</span>' : '<span style="color:#991B1B">Rejected</span>' ?></td>
          <td data-label="Why" style="font-size:12px;max-width:320px">
            <?php if (empty($failedRules)): ?>
            <span style="color:#94a3b8">—</span>
            <?php else: foreach ($failedRules as $fr): ?>
            <div><strong><?= esc_html($fr['field_label'] ?? $fr['field_key'] ?? '?') ?>:</strong> <?= esc_html($fr['message'] ?? '') ?></div>
            <?php endforeach; endif; ?>
          </td>
          <td data-label="Checked" style="font-size:12px;color:#64748b"><?= esc_html(date('d M Y H:i', strtotime($chk['checked_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentChecks)): ?>
        <tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8">No eligibility checks recorded yet.</td></tr>
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

  document.getElementById('eligRuleForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(e.target);
    var extra = {}; fd.forEach(function(v,k){ extra[k]=v; });
    var msg = document.getElementById('eligMsg');
    post('eligibility.store', extra).then(function(r){
      msg.style.color = r.success ? '#166534' : '#991B1B';
      msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
      if (r.success) location.reload();
    });
  });

  // FIX (Help Centre Phase 1 gap: "Impact preview before saving"): before
  // actually toggling a rule, ask the server how many recently-recorded
  // applicants it genuinely affected (rule active) or would affect (rule
  // inactive) — real numbers computed from rto_eligibility_checks by
  // EligibilityService::previewToggleImpact(), never a fabricated count —
  // then let the admin confirm or back out.
  document.querySelectorAll('.elig-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.closest('tr').dataset.ruleId;
      btn.disabled = true;
      post('eligibility.preview_toggle', {rule_id:id}).then(function(r){
        btn.disabled = false;
        var proceed = true;
        if (r.success && r.data) {
          var d = r.data;
          var verb = d.affected === 1 ? 'was' : 'were';
          var text = d.sample_size > 0
            ? 'Of the last ' + d.sample_size + ' eligibility check(s) recorded for ' + d.scope + ', ' + d.affected + ' ' + verb + ' affected by this rule (' + d.severity + ').\n\nToggle this rule now?'
            : 'No eligibility checks have been recorded yet for ' + d.scope + ', so no historical impact can be shown.\n\nToggle this rule now?';
          proceed = window.confirm(text);
        }
        if (proceed) post('eligibility.toggle', {rule_id:id}).then(function(){ location.reload(); });
      });
    });
  });
  document.querySelectorAll('.elig-delete').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!confirm('Delete this eligibility rule?')) return;
      var id = btn.closest('tr').dataset.ruleId;
      post('eligibility.delete', {rule_id:id}).then(function(){ location.reload(); });
    });
  });

  function bulkToggle(active){
    var serviceId = document.getElementById('eligBulkService').value;
    var msg = document.getElementById('eligBulkMsg');
    post('eligibility.bulk_toggle', {service_id: serviceId, active: active ? 1 : 0}).then(function(r){
      msg.style.color = r.success ? '#166534' : '#991B1B';
      msg.textContent = r.message || (r.success ? 'Done.' : 'Failed.');
      if (r.success) setTimeout(function(){ location.reload(); }, 700);
    });
  }
  document.getElementById('eligBulkEnable').addEventListener('click', function(){ bulkToggle(true); });
  document.getElementById('eligBulkDisable').addEventListener('click', function(){
    if (!confirm('Disable ALL eligibility rules for the selected service? Applicants will no longer be checked against any of them until re-enabled.')) return;
    bulkToggle(false);
  });
})();
</script>

<?php
// FIX (Config Versioning wiring, Eligibility Rules, Part 5.5): every
// create/toggle/delete now records a full-table snapshot under config_key
// 'eligibility_rules' (see EligibilityController::snapshotVersion()); this
// makes that history visible and rollback-able from the screen itself,
// exactly like the Settings → Matching tab and City Pricing screens already
// do with this same partial.
$configVersionKey = 'eligibility_rules';
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
<?php rto_help_box('eligibility'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
