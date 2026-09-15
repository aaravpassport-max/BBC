<?php
if (!defined('ABSPATH')) exit;
/** @var array $service @var array $schema @var array $dependentSources
 * Form Builder v2 — generic schema-driven renderer. Kept deliberately
 * separate from apply.php (the static 111-field form): this page only
 * exists for a service an admin has actually built a v2 form for
 * (routeApplyDynamic() redirects to /rto-apply/ otherwise), so the legacy
 * page is never at risk from anything on this one.
 *
 * Self-contained, same as every other public view in this codebase
 * (apply.php, track.php, etc. all render their own full <html> document —
 * there is no shared public layout partial to require here).
 */
$co = get_option('rtoflow_company_name', 'RTOASSIST');
$c  = [
    'primary'   => get_option('rtoflow_color_primary',   '#1B2A6B'),
    'secondary' => get_option('rtoflow_color_secondary', '#E97B28'),
    'accent'    => get_option('rtoflow_color_accent',    '#16A34A'),
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc_html($service['name']) ?> — <?= esc_html($co) ?></title>
<?php wp_head(); ?>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;background:#f8fafc;color:#111827}
  .rto-input{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box}
  .rto-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px}
  .rto-btn{padding:10px 18px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:14px;cursor:pointer}
  .rto-btn--primary{background:<?= esc_attr($c['primary']) ?>;border-color:<?= esc_attr($c['primary']) ?>;color:#fff}
  @media (max-width:600px){ #stepsContainer > div{grid-template-columns:1fr !important} }
</style>
<?php
// FIX (mobile pass — public site bottom nav): standalone page, never went
// through layouts/website-header.php — same gap as apply.php/home.php.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-website">
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>
<div class="rto-apply-dynamic" style="max-width:760px;margin:0 auto;padding:24px 16px 60px">
  <h1 style="font-size:22px;margin:0 0 4px"><?= esc_html($service['name']) ?></h1>
  <p style="color:#6b7280;font-size:13px;margin:0 0 20px"><?= esc_html($service['category']) ?></p>

  <div id="stepTabs" style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap"></div>

  <form id="dynForm" novalidate>
    <div id="stepsContainer"></div>

    <div id="docsSection" style="display:none;margin-top:24px;padding-top:20px;border-top:1px solid #e5e7eb">
      <h3 style="font-size:15px;margin:0 0 12px">Documents Required</h3>
      <div id="docsContainer"></div>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:28px">
      <button type="button" id="prevBtn" class="rto-btn" style="display:none">Back</button>
      <button type="button" id="nextBtn" class="rto-btn rto-btn--primary">Next</button>
      <button type="submit" id="submitBtn" class="rto-btn rto-btn--primary" style="display:none">Submit Application</button>
    </div>
    <p id="formMsg" style="margin-top:12px;font-size:13px"></p>
  </form>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var SCHEMA = <?= wp_json_encode($schema) ?>;
  var SOURCES = <?= wp_json_encode($dependentSources) ?>;
  var SERVICE_ID = <?= (int)$service['id'] ?>;
  // Part 4.10: SCHEMA is now the whole CATEGORY's schema (every service in
  // it flattened in, gated by a selected_service picker field) — this page
  // is for exactly ONE service, so that picker's answer is fixed server-side
  // to this service's own name and its field is never itself rendered (the
  // real category/sub-service choice that got the customer here already
  // fills that role).
  var SERVICE_NAME = <?= wp_json_encode($service['name']) ?>;
  // Same globals apply.php already sets up for its own submit_apply_v2 call.
  var nonce = <?= wp_json_encode(wp_create_nonce('rto_public')) ?>;
  var ajaxUrl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;

  var steps = SCHEMA.steps || [];
  var documents = SCHEMA.documents || [];
  var answers = { selected_service: SERVICE_NAME };
  var currentStep = 0;

  var stepTabs = document.getElementById('stepTabs');
  var stepsContainer = document.getElementById('stepsContainer');
  var docsSection = document.getElementById('docsSection');
  var docsContainer = document.getElementById('docsContainer');
  var prevBtn = document.getElementById('prevBtn');
  var nextBtn = document.getElementById('nextBtn');
  var submitBtn = document.getElementById('submitBtn');
  var formMsg = document.getElementById('formMsg');

  // ── Mirrors FormEngineService::evaluateCondition()/evaluateRule() exactly
  // (same operators, same AND/OR semantics) so a field that is client-side
  // visible is also what the server will accept — the server re-checks this
  // independently in submitApplyDynamic(), this is only for a responsive UI. ──
  function evaluateRule(rule, ans){
    var actual = ans[rule.field]; if (actual === undefined) actual = null;
    var expected = rule.value;
    switch (rule.op) {
      case 'equals': return String(actual) === String(expected);
      case 'not_equals': return String(actual) !== String(expected);
      case 'in': return (Array.isArray(expected) ? expected : [expected]).map(String).indexOf(String(actual)) !== -1;
      case 'not_in': return (Array.isArray(expected) ? expected : [expected]).map(String).indexOf(String(actual)) === -1;
      case 'contains': return String(actual == null ? '' : actual).indexOf(String(expected)) !== -1;
      case 'filled': return actual !== null && actual !== '' && !(Array.isArray(actual) && actual.length === 0);
      case 'empty': return actual === null || actual === '' || (Array.isArray(actual) && actual.length === 0);
      case 'greater_than': return parseFloat(actual) > parseFloat(expected);
      case 'less_than': return parseFloat(actual) < parseFloat(expected);
      default: return true;
    }
  }
  function evaluateCondition(group, ans){
    if (!group) return true;
    var ruleResults = (group.rules || []).map(function(r){ return evaluateRule(r, ans); });
    var groupResults = (group.groups || []).map(function(g){ return evaluateCondition(g, ans); });
    var all = ruleResults.concat(groupResults);
    if (!all.length) return true;
    return group.logic === 'OR' ? all.indexOf(true) !== -1 : all.indexOf(false) === -1;
  }

  function fieldVisible(f){ return evaluateCondition(f.visible_if, answers); }
  function docVisible(d){ return evaluateCondition(d.visible_if, answers); }

  function widthStyle(w){ return w === 'half' ? 'grid-column:span 3' : (w === 'third' ? 'grid-column:span 2' : 'grid-column:1/-1'); }

  function renderOptions(select, options, multiple){
    Object.keys(options || {}).forEach(function(v){
      var opt = document.createElement('option');
      opt.value = v; opt.textContent = options[v];
      select.appendChild(opt);
    });
  }

  function renderField(f){
    var wrap = document.createElement('div');
    wrap.style.cssText = widthStyle(f.width) + ';margin-bottom:14px';
    wrap.dataset.fieldKey = f.key;

    if (f.type === 'heading') {
      wrap.innerHTML = '<h3 style="font-size:15px;margin:18px 0 6px;grid-column:1/-1">' + (f.label || '') + '</h3>';
      return wrap;
    }

    // Part 4.13: matches the reference plugin's "Show label" checkbox.
    var label = null;
    if (f.show_label !== false) {
      label = document.createElement('label');
      label.className = 'rto-label';
      label.textContent = f.label + (f.required ? ' *' : '');
      wrap.appendChild(label);
    }

    var input;
    if (f.type === 'textarea') {
      input = document.createElement('textarea'); input.rows = 3;
    } else if (['select','dependent_select'].indexOf(f.type) !== -1) {
      input = document.createElement('select');
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'Select…'; input.appendChild(ph);
      if (f.type === 'select') renderOptions(input, f.options, false);
    } else if (f.type === 'multiselect') {
      input = document.createElement('select'); input.multiple = true;
      renderOptions(input, f.options, true);
    } else if (f.type === 'radio' || f.type === 'checkbox') {
      input = document.createElement('div');
      Object.keys(f.options || {}).forEach(function(v){
        var id = f.key + '_' + v;
        var row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;margin:4px 0';
        var box = document.createElement('input');
        box.type = f.type === 'radio' ? 'radio' : 'checkbox';
        box.name = f.key; box.value = v;
        // Native defense-in-depth: a required radio group is satisfiable by
        // the browser (any one of the same-named inputs checked). A required
        // checkbox GROUP has no native equivalent ("check at least one of
        // these" isn't expressible via the required attribute) — that case
        // is already caught by validateStep() above, which treats an empty
        // answers[] array as missing before Next/Submit can proceed.
        if (f.type === 'radio' && f.required) box.required = true;
        box.addEventListener('change', function(){
          if (f.type === 'radio') { answers[f.key] = v; }
          else {
            var checked = Array.prototype.slice.call(row.parentNode.querySelectorAll('input:checked')).map(function(i){ return i.value; });
            answers[f.key] = checked;
          }
          rerender();
        });
        row.appendChild(box);
        row.appendChild(document.createTextNode(f.options[v]));
        input.appendChild(row);
      });
    } else if (f.type === 'consent') {
      input = document.createElement('label');
      input.style.cssText = 'display:flex;align-items:flex-start;gap:8px;font-weight:400;font-size:13px';
      var cb = document.createElement('input'); cb.type = 'checkbox';
      cb.addEventListener('change', function(){ answers[f.key] = cb.checked; rerender(); });
      input.appendChild(cb);
      input.appendChild(document.createTextNode(f.help_text || f.label));
    } else if (f.type === 'file') {
      input = document.createElement('input'); input.type = 'file';
      input.addEventListener('change', function(){ answers[f.key] = input.files.length ? input.files[0].name : ''; });
    } else {
      input = document.createElement('input');
      input.type = ({tel:'tel', email:'email', number:'number', date:'date', time:'time'})[f.type] || 'text';
    }

    if (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA' || input.tagName === 'SELECT') {
      input.className = 'rto-input';
      // ACCESSIBILITY FIX: this label/input pair was built entirely with
      // document.createElement() and never got an id/for association —
      // WCAG "form fields have a label" failure identical to the one fixed
      // across the static views (login.php, contact.php, apply.php, etc.)
      // in this same audit pass, just generated at runtime instead of
      // baked into markup. Screen readers announced these fields with no
      // programmatic name at all; clicking the label text also did not
      // focus the field. id is derived from f.key, which is already unique
      // per field within a form definition.
      input.id = 'df_' + f.key;
      if (label) label.htmlFor = input.id;
      if (f.placeholder) input.placeholder = f.placeholder;
      if (answers[f.key] !== undefined && input.type !== 'file') {
        if (input.multiple && Array.isArray(answers[f.key])) {
          Array.prototype.forEach.call(input.options, function(o){ o.selected = answers[f.key].indexOf(o.value) !== -1; });
        } else {
          input.value = answers[f.key];
        }
      }
      // FIX (real bug found during the audit — related to, but distinct
      // from, the reported "data disappears" issue on the main /rto-apply/
      // page): this used to call rerender() — a full renderStep() teardown
      // and rebuild of every field in the step — from the 'input' event,
      // i.e. on EVERY KEYSTROKE, for any text-like field another field's
      // visible_if depends on. renderField() does correctly restore each
      // rebuilt input's VALUE from the persisted `answers` object (so the
      // characters typed so far were never actually lost) — but the
      // rebuilt <input> is a brand-new DOM node, so the browser's FOCUS and
      // cursor position were destroyed on every single character typed.
      // In practice this made such a field nearly unusable: type one
      // character, the field silently loses focus, the next keystroke goes
      // nowhere until the customer clicks back into it. Fixed by only
      // re-evaluating conditional visibility on 'change' (fires once, on
      // blur — the same natural point a customer moves to the next field)
      // instead of on every keystroke; dependent-dropdown refreshing is
      // left on 'input' since selecting a parent value should reveal its
      // children immediately and rebuilding a <select>'s options list
      // (fillDependentOptions(), not a full rerender()) never touches focus
      // on the field the customer is actually interacting with.
      input.addEventListener('input', function(){
        if (input.multiple) {
          answers[f.key] = Array.prototype.filter.call(input.options, function(o){ return o.selected; }).map(function(o){ return o.value; });
        } else {
          answers[f.key] = input.value;
        }
        if (f.type === 'dependent_select') rerenderDependents();
      });
      input.addEventListener('change', function(){
        if (['select','dependent_select'].indexOf(f.type) !== -1 || f.type === 'date') { rerender(); return; }
        if (f.visible_if || dependsOnMe(f.key)) rerender();
      });
      wrap.appendChild(input);

      if (f.type === 'dependent_select') {
        // Populate once now; refreshed whenever the parent field changes.
        input.dataset.depSource = f.dependent_source.source;
        input.dataset.depParent = f.dependent_source.parent_field;
        fillDependentOptions(input);
      }
    } else {
      wrap.appendChild(input);
    }

    if (f.help_text && f.type !== 'consent') {
      var help = document.createElement('div');
      help.style.cssText = 'font-size:12px;color:#6b7280;margin-top:4px';
      help.textContent = f.help_text;
      wrap.appendChild(help);
    }
    return wrap;
  }

  function dependsOnMe(key){
    return allFields().some(function(f){ return f.dependent_source && f.dependent_source.parent_field === key; });
  }

  function fillDependentOptions(select){
    var source = select.dataset.depSource;
    var parentVal = answers[select.dataset.depParent];
    var map = SOURCES[source] || {};
    var children = parentVal ? (map[parentVal] || []) : [];
    var current = select.value;
    select.innerHTML = '<option value="">' + (parentVal ? 'Select…' : 'Select the field above first') + '</option>';
    children.forEach(function(c){
      var opt = document.createElement('option'); opt.value = c; opt.textContent = c;
      select.appendChild(opt);
    });
    if (children.indexOf(current) !== -1) select.value = current;
  }

  function rerenderDependents(){
    Array.prototype.forEach.call(stepsContainer.querySelectorAll('select[data-dep-source]'), fillDependentOptions);
  }

  function allFields(){
    var out = [];
    steps.forEach(function(s){ (s.fields || []).forEach(function(f){ out.push(f); }); });
    return out;
  }

  function renderStep(i){
    stepsContainer.innerHTML = '';
    var step = steps[i]; if (!step) return;
    var grid = document.createElement('div');
    grid.style.cssText = 'display:grid;grid-template-columns:repeat(6,1fr);gap:14px';
    (step.fields || []).forEach(function(f){
      if (f.key === 'selected_service') return; // fixed server-side to this page's own service — never rendered
      if (f.active === false) return; // Part 4.13: soft-hidden by the admin
      if (f.type !== 'heading' && !fieldVisible(f)) return;
      if (f.type === 'heading' && !fieldVisible(f)) return;
      grid.appendChild(renderField(f));
    });
    stepsContainer.appendChild(grid);
    rerenderDependents();

    stepTabs.innerHTML = '';
    steps.forEach(function(s, idx){
      var tab = document.createElement('span');
      tab.textContent = s.label;
      tab.style.cssText = 'font-size:12px;padding:6px 12px;border-radius:14px;' +
        (idx === i ? 'background:#111827;color:#fff;font-weight:600' : 'background:#f3f4f6;color:#6b7280');
      stepTabs.appendChild(tab);
    });

    prevBtn.style.display = i > 0 ? '' : 'none';
    var isLast = i === steps.length - 1;
    nextBtn.style.display = isLast ? 'none' : '';
    submitBtn.style.display = isLast ? '' : 'none';
    docsSection.style.display = isLast ? '' : 'none';
    if (isLast) renderDocs();
  }

  function renderDocs(){
    docsContainer.innerHTML = '';
    var visible = documents.filter(docVisible);
    docsSection.style.display = visible.length ? '' : 'none';
    visible.forEach(function(d){
      var row = document.createElement('div');
      row.style.cssText = 'margin-bottom:12px;padding:10px;border:1px solid #e5e7eb;border-radius:8px';
      // ACCESSIBILITY FIX: same missing label/input association as the main
      // field renderer above — this innerHTML-built label had no `for`,
      // and the file input built right after it had no `id` to point to.
      var docInputId = 'doc_' + d.doc_type_id;
      row.innerHTML = '<label class="rto-label" for="' + docInputId + '">' + d.label + (d.required ? ' *' : ' (optional)') + '</label>';
      var input = document.createElement('input');
      input.type = 'file'; input.name = 'doc_' + d.doc_type_id; input.id = docInputId;
      row.appendChild(input);
      docsContainer.appendChild(row);
    });
  }

  function rerender(){ renderStep(currentStep); }

  function validateStep(i){
    var step = steps[i]; var missing = [];
    (step.fields || []).forEach(function(f){
      if (f.key === 'selected_service') return;
      if (f.active === false) return; // Part 4.13: soft-hidden by the admin — never required
      if (f.type === 'heading' || !fieldVisible(f)) return;
      if (!f.required) return;
      var v = answers[f.key];
      if (v === undefined || v === '' || v === null || (Array.isArray(v) && !v.length)) missing.push(f.label);
    });
    if (missing.length) { formMsg.style.color = '#991B1B'; formMsg.textContent = 'Please complete: ' + missing.join(', '); return false; }
    formMsg.textContent = '';
    return true;
  }

  nextBtn.addEventListener('click', function(){
    if (!validateStep(currentStep)) return;
    currentStep = Math.min(currentStep + 1, steps.length - 1);
    renderStep(currentStep);
    window.scrollTo({top:0, behavior:'smooth'});
  });
  prevBtn.addEventListener('click', function(){
    currentStep = Math.max(currentStep - 1, 0);
    renderStep(currentStep);
    window.scrollTo({top:0, behavior:'smooth'});
  });

  document.getElementById('dynForm').addEventListener('submit', function(e){
    e.preventDefault();
    if (!validateStep(currentStep)) return;
    submitBtn.disabled = true;
    formMsg.style.color = '#334155'; formMsg.textContent = 'Submitting…';

    var fd = new FormData();
    fd.append('action', 'rto_public'); fd.append('rto_area', 'public'); fd.append('rto_action', 'submit_apply_dynamic');
    fd.append('rto_nonce', nonce);
    fd.append('service_id', SERVICE_ID);
    fd.append('answers', JSON.stringify(answers));
    Array.prototype.forEach.call(docsContainer.querySelectorAll('input[type=file]'), function(input){
      if (input.files.length) fd.append(input.name, input.files[0]);
    });

    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        submitBtn.disabled = false;
        formMsg.style.color = r.success ? '#166534' : '#991B1B';
        formMsg.textContent = r.message || (r.success ? 'Submitted.' : 'Something went wrong.');
        if (r.success && r.data && r.data.tracking_url) {
          setTimeout(function(){ window.location.href = r.data.tracking_url; }, 1200);
        }
      })
      .catch(function(){
        submitBtn.disabled = false;
        formMsg.style.color = '#991B1B'; formMsg.textContent = 'Network error — your application was not submitted. Please try again.';
      });
  });

  renderStep(0);
})();
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
