<?php
if (!defined('ABSPATH')) exit;
/** Part 4.10: one form = one CATEGORY (not one service).
 *  @var string $category @var string $categoryTitle @var array $categoryServices
 *  @var array|null $schema @var array $steps @var array $documents
 *  @var string $schemaName @var array $history @var array $sources */
$pageTitle = 'Form Builder — ' . $categoryTitle;
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<style>
  .rfb-wrap{display:flex;flex-direction:column;height:calc(100vh - 140px);min-height:560px}
  .rfb-topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px}
  .rfb-topbar-left{display:flex;align-items:center;gap:10px;min-width:0}
  .rfb-name-input{border:1px solid transparent;background:transparent;font-size:16px;font-weight:700;padding:4px 6px;border-radius:6px;min-width:180px}
  .rfb-name-input:hover,.rfb-name-input:focus{border-color:#e2e8f0;background:#f8fafc;outline:none}
  .rfb-badge{font-size:11px;color:#6b7280;background:#f1f5f9;border-radius:99px;padding:3px 10px;white-space:nowrap}
  .rfb-body{display:flex;flex:1;min-height:0;gap:12px}
  .rfb-palette{width:210px;flex:0 0 210px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px;overflow-y:auto}
  .rfb-palette h4{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin:10px 4px 6px}
  .rfb-palette h4:first-child{margin-top:2px}
  .rfb-pal-item{display:flex;align-items:center;gap:8px;padding:8px 9px;border-radius:8px;cursor:grab;font-size:13px;color:#334155;user-select:none}
  .rfb-pal-item:hover{background:#eff6ff;color:#1d4ed8}
  .rfb-pal-item .ico{width:20px;text-align:center;font-size:14px}
  .rfb-canvas{flex:1;min-width:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:flex;flex-direction:column;overflow:hidden}
  .rfb-steptabs{display:flex;align-items:center;gap:4px;padding:8px 10px;background:#fff;border-bottom:1px solid #e2e8f0;overflow-x:auto;flex-wrap:nowrap}
  .rfb-steptab{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:13px;white-space:nowrap;cursor:pointer;border:1px solid transparent;color:#475569}
  .rfb-steptab.active{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;font-weight:600}
  .rfb-steptab .x{opacity:.5;font-size:11px}
  .rfb-steptab .x:hover{opacity:1;color:#991B1B}
  .rfb-addstep{padding:6px 10px;font-size:12px;color:#2563eb;cursor:pointer;white-space:nowrap;border-radius:8px}
  .rfb-addstep:hover{background:#eff6ff}
  .rfb-droparea{flex:1;overflow-y:auto;padding:16px}
  .rfb-steplabel-row{display:flex;align-items:center;gap:8px;margin-bottom:12px}
  .rfb-steplabel-row input{font-size:13px;padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px}
  .rfb-fieldrow{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:pointer;transition:border-color .1s}
  .rfb-fieldrow:hover{border-color:#93c5fd}
  .rfb-fieldrow.selected{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb}
  .rfb-fieldrow.dragover{border-top:2px solid #2563eb}
  .rfb-fieldrow .drag{cursor:grab;color:#cbd5e1;font-size:14px;line-height:1}
  .rfb-fieldrow .ftype-pill{font-size:10px;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:99px;white-space:nowrap}
  .rfb-fieldrow .flabel{font-size:13px;font-weight:600;color:#1e293b;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rfb-fieldrow .fkey{font-size:11px;color:#94a3b8;font-family:monospace}
  .rfb-fieldrow .fmeta{display:flex;align-items:center;gap:6px}
  .rfb-fieldrow .req-dot{width:6px;height:6px;border-radius:50%;background:#f97316}
  .rfb-fieldrow .cond-dot{font-size:11px;color:#7c3aed}
  .rfb-fieldrow .fdel{color:#cbd5e1;font-size:13px;padding:2px 4px}
  .rfb-fieldrow .fdel:hover{color:#991B1B}
  .rfb-empty{text-align:center;color:#94a3b8;padding:50px 16px;font-size:13px}
  .rfb-empty .big{font-size:28px;margin-bottom:8px}
  .rfb-settings{width:340px;flex:0 0 340px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow-y:auto;padding:16px}
  .rfb-settings-empty{color:#94a3b8;font-size:13px;text-align:center;padding:60px 16px}
  .rfb-sec{margin-bottom:16px}
  .rfb-sec-title{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
  .rfb-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .rfb-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
  .rfb-field-label{font-size:11px;color:#64748b;margin-bottom:3px;display:block}
  .rfb-toggle-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
  .rfb-toggle-row label{font-size:13px;color:#334155}
  .rfb-condline{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;margin-bottom:6px}
  .rfb-condline-grid{display:grid;grid-template-columns:1.3fr 1fr 1.3fr auto;gap:6px;align-items:center}
  .rfb-help{font-size:11px;color:#94a3b8;margin-top:8px;line-height:1.4}
  .rfb-add-mini{font-size:12px;color:#2563eb;cursor:pointer;font-weight:600}

  /* Part 4.13: reference-plugin-style field row (arrows + Edit/Duplicate/
     Delete buttons instead of a click-to-select row + always-visible side
     panel) and the Edit Field modal it opens. */
  .rfb-row-v2{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:8px}
  .rfb-row-v2:hover{border-color:#93c5fd}
  .rfb-row-v2 .rfb-arrows{display:flex;flex-direction:column;gap:1px}
  .rfb-row-v2 .rfb-arrows button{border:none;background:none;color:#94a3b8;cursor:pointer;font-size:10px;line-height:1;padding:2px}
  .rfb-row-v2 .rfb-arrows button:hover{color:#2563eb}
  .rfb-row-v2 .rfb-arrows button:disabled{color:#e2e8f0;cursor:default}
  .rfb-row-v2 .flabel{font-size:13px;font-weight:600;color:#1e293b;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rfb-row-v2 .fmetaline{font-size:11px;color:#94a3b8;margin-top:2px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .rfb-row-v2 .fmetaline .cond-badge{color:#7c3aed}
  .rfb-row-v2 .fmetaline .inactive-badge{color:#b45309;font-weight:600}
  .rfb-row-v2 .rowbtns{display:flex;gap:6px;flex-shrink:0}
  .rfb-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:80;align-items:flex-start;justify-content:center;overflow-y:auto;padding:40px 16px}
  .rfb-modal-overlay.show{display:flex}
  .rfb-modal{background:#fff;border-radius:12px;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25)}
  .rfb-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e2e8f0}
  .rfb-modal-head h3{margin:0;font-size:16px}
  .rfb-modal-tabs{display:flex;gap:2px;padding:0 20px;border-bottom:1px solid #e2e8f0}
  .rfb-modal-tab{padding:10px 6px;font-size:13px;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;font-weight:600}
  .rfb-modal-tab.active{color:#1d4ed8;border-bottom-color:#1d4ed8}
  .rfb-modal-body{padding:18px 20px;max-height:60vh;overflow-y:auto}
  .rfb-modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #e2e8f0}
  .rfb-modal-tabpanel{display:none}
  .rfb-modal-tabpanel.active{display:block}

  /* Mobile: the three-panel builder layout (fixed 210px palette | flexible
     canvas | fixed 340px settings, all side-by-side) cannot work on a
     phone-width viewport at all — it would either overflow horizontally
     or squeeze every panel unreadably narrow. Native HTML5 drag-and-drop
     (used to drag a field from the palette into the canvas) also has no
     touch equivalent, so the palette's primary interaction does not carry
     over to mobile either. Full touch-drag-and-drop redesign of this
     builder is a larger project than this pass covers; what is fixed here
     is the structural, correctness-level problem — the layout is
     restacked to a single column (canvas first, since editing existing
     fields via the row buttons works fine with touch; settings panel
     next, since it is what opens after tapping Edit on a field; palette
     last, kept usable for reference even though drag-in does not work on
     touch) so nothing overflows the viewport or becomes unreachable, and
     the two fixed-width slide-in side panels (Analytics/History, normally
     340–380px docked to the right) become full-width instead of clipping. */
  @media (max-width: 782px) {
    .rfb-wrap { height: auto; min-height: 0; }
    .rfb-body { flex-direction: column; }
    .rfb-canvas { order: 1; width: 100%; }
    .rfb-settings { order: 2; width: 100%; flex: 1 1 auto; max-height: none; }
    .rfb-palette { order: 3; width: 100%; flex: 1 1 auto; max-height: 220px; }
    #analyticsPanel, #historyPanel { width: 100% !important; }
  }
</style>

<div class="rto-page-header">
  <div>
    <h2>Form Builder — <?= esc_html($categoryTitle) ?></h2>
    <p style="color:#6b7280;font-size:13px;margin:2px 0 0">Covers <?= count($categoryServices) ?> service<?= count($categoryServices) === 1 ? '' : 's' ?>: <?= esc_html(implode(', ', $categoryServices)) ?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" id="previewBtn" class="rto-btn rto-btn--sm" aria-label="Preview this form">👁 Preview</button>
    <button type="button" id="analyticsBtn" class="rto-btn rto-btn--sm" aria-label="Show submission analytics for <?= esc_attr($categoryTitle) ?>">Analytics</button>
    <button type="button" id="historyBtn" class="rto-btn rto-btn--sm" aria-label="Show version history for <?= esc_attr($categoryTitle) ?>">Version History</button>
    <?php if ($schema): ?>
      <a class="rto-btn rto-btn--sm" href="<?= esc_url(home_url('/rto-admin/forms/' . esc_attr($category) . '/export/')) ?>" aria-label="Export the current <?= esc_attr($categoryTitle) ?> form schema as a JSON file">Export JSON</a>
    <?php endif; ?>
    <button type="button" id="importBtn" class="rto-btn rto-btn--sm" aria-label="Import a form schema JSON file as a new version of <?= esc_attr($categoryTitle) ?>">Import JSON</button>
    <a class="rto-btn rto-btn--sm" href="<?= esc_url(home_url('/rto-admin/forms/')) ?>" aria-label="Back to Forms list">&larr; Back to Forms</a>
  </div>
</div>

<input type="file" id="importFileInput" accept="application/json" style="display:none">

<div id="analyticsPanel" style="display:none;position:fixed;top:0;right:0;bottom:0;width:340px;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.12);z-index:60;overflow-y:auto;padding:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3 style="margin:0;font-size:15px">Submission Analytics</h3>
    <button type="button" id="closeAnalyticsBtn" class="rto-btn rto-btn--sm">Close</button>
  </div>
  <div id="analyticsBody" style="font-size:13px">Loading…</div>
</div>

<div id="historyPanel" style="display:none;position:fixed;top:0;right:0;bottom:0;width:380px;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.12);z-index:60;overflow-y:auto;padding:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3 style="margin:0;font-size:15px">Version History</h3>
    <button type="button" id="closeHistoryBtn" class="rto-btn rto-btn--sm">Close</button>
  </div>
  <div id="historyList"></div>
</div>

<div id="previewPanel" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:70;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;max-width:640px;width:92%;max-height:86vh;overflow-y:auto;padding:24px;position:relative">
    <button type="button" id="closePreviewBtn" class="rto-btn rto-btn--sm" style="position:absolute;top:14px;right:14px">Close</button>
    <div id="previewBody"></div>
  </div>
</div>

<div class="rfb-wrap">
  <div class="rfb-topbar">
    <div class="rfb-topbar-left">
      <input type="text" id="formName" class="rfb-name-input" value="<?= esc_attr($schemaName) ?>" placeholder="Form name">
      <span class="rfb-badge"><?= $schema ? 'Editing v' . (int)($schema['version'] ?? 1) . ' — saving creates a new version' : 'No custom form yet — saving creates version 1' ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <input type="text" id="versionNote" placeholder="Version note (optional)" style="font-size:12px;padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;width:200px">
      <button type="button" id="saveFormBtn" class="rto-btn rto-btn--primary">Save as New Version</button>
      <span id="saveMsg" style="font-size:12px"></span>
    </div>
  </div>

  <div class="rfb-body">
    <!-- ── Field palette (click a type to add it to the active step) ── -->
    <div class="rfb-palette" id="palette">
      <h4>Text &amp; Numbers</h4>
      <div class="rfb-pal-item" data-type="text"><span class="ico">📝</span>Text</div>
      <div class="rfb-pal-item" data-type="textarea"><span class="ico">📄</span>Textarea</div>
      <div class="rfb-pal-item" data-type="number"><span class="ico">🔢</span>Number</div>
      <div class="rfb-pal-item" data-type="tel"><span class="ico">📞</span>Phone</div>
      <div class="rfb-pal-item" data-type="email"><span class="ico">✉️</span>Email</div>
      <h4>Choices</h4>
      <div class="rfb-pal-item" data-type="select"><span class="ico">▾</span>Dropdown</div>
      <div class="rfb-pal-item" data-type="radio"><span class="ico">◉</span>Radio</div>
      <div class="rfb-pal-item" data-type="checkbox"><span class="ico">☑</span>Checkbox</div>
      <div class="rfb-pal-item" data-type="multiselect"><span class="ico">☰</span>Multi-select</div>
      <div class="rfb-pal-item" data-type="dependent_select"><span class="ico">🔗</span>Dependent Dropdown</div>
      <h4>Other</h4>
      <div class="rfb-pal-item" data-type="date"><span class="ico">📅</span>Date</div>
      <div class="rfb-pal-item" data-type="time"><span class="ico">🕐</span>Time</div>
      <div class="rfb-pal-item" data-type="file"><span class="ico">📎</span>File Upload</div>
      <div class="rfb-pal-item" data-type="address"><span class="ico">📍</span>Address</div>
      <div class="rfb-pal-item" data-type="consent"><span class="ico">✅</span>Consent</div>
      <div class="rfb-pal-item" data-type="heading"><span class="ico">#</span>Section Heading</div>
    </div>

    <!-- ── Canvas: step tabs + the current step's field list ── -->
    <div class="rfb-canvas">
      <div class="rfb-steptabs" id="stepTabs"></div>
      <div class="rfb-droparea" id="dropArea">
        <div class="rfb-steplabel-row">
          <input type="text" id="activeStepLabel" placeholder="Step title (shown to the customer)" style="flex:1">
        </div>
        <div id="fieldList"></div>
      </div>
    </div>

    <!-- ── Settings panel: document requirements only — field editing
         moved into the Edit Field modal below (Part 4.13). ── -->
    <div class="rfb-settings" id="settingsPanel">
      <div class="rfb-settings-empty">Click a document requirement below to edit it, or click "+ Add Document Requirement".</div>
    </div>
  </div>
</div>

<!-- ── Edit Field modal (Part 4.13): Basic / Options / Conditions tabs,
     matching how the reference plugin's own Inquiry Forms editor works —
     replaces the old always-open side settings panel for fields. ── -->
<div class="rfb-modal-overlay" id="fieldModalOverlay">
  <div class="rfb-modal" role="dialog" aria-modal="true" aria-labelledby="fieldModalTitle" tabindex="-1">
    <div class="rfb-modal-head">
      <h3 id="fieldModalTitle">Edit Field</h3>
      <button type="button" class="rto-btn rto-btn--sm" id="fieldModalCloseX" aria-label="Close edit field dialog">✕</button>
    </div>
    <div class="rfb-modal-tabs">
      <div class="rfb-modal-tab active" data-tab="basic">📝 Basic</div>
      <div class="rfb-modal-tab" data-tab="options">📋 Options</div>
      <div class="rfb-modal-tab" data-tab="conditions">🔀 Conditions</div>
    </div>
    <div class="rfb-modal-body">
      <div class="rfb-modal-tabpanel active" id="tabPanelBasic"></div>
      <div class="rfb-modal-tabpanel" id="tabPanelOptions"></div>
      <div class="rfb-modal-tabpanel" id="tabPanelConditions"></div>
    </div>
    <div class="rfb-modal-foot">
      <button type="button" class="rto-btn rto-btn--sm" id="fieldModalCancel">Cancel</button>
      <button type="button" class="rto-btn rto-btn--sm rto-btn--primary" id="fieldModalSave">Update Field</button>
    </div>
  </div>
</div>

<div class="rto-card" style="margin-top:16px">
  <div class="rto-card__body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h3 style="font-size:14px;font-weight:700;margin:0">📎 Document Checklist</h3>
      <span class="rfb-add-mini" id="addDocBtn">+ Add Document Requirement</span>
    </div>
    <p style="color:#6b7280;font-size:12px;margin:0 0 10px">
      Documents come from the shared Document Types catalog and can be made required only when an earlier answer matches — same conditional logic as a field.
    </p>
    <div id="docsList"></div>
    <p id="noDocsMsg" style="text-align:center;color:#94a3b8;padding:16px;<?= empty($documents) ? '' : 'display:none' ?>">No document requirements yet.</p>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
window.RTOFB_SOURCES = <?= wp_json_encode($sources ?: []) ?>;
window.RTOFB_DOCTYPES = <?= wp_json_encode(array_map(function($d){ return ['id'=>(int)$d['id'],'name'=>$d['name'],'category'=>$d['category']]; }, $docTypes ?: [])) ?>;
// Part 4.12: the real list of services this category's schema covers — used
// by the condition editor's "Visible for services" control (see
// renderConditionEditor()) to distinguish the service-gate rule every
// category-flattened field carries from the field's own business condition.
window.RTOFB_CATEGORY_SERVICES = <?= wp_json_encode($categoryServices ?: []) ?>;
</script>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var nonce = (window.rtoflowAdmin || {}).nonce || document.querySelector('meta[name="rto-admin-nonce"]')?.content || '';
  var ajaxUrl = (window.rtoflowAdmin || {}).ajax_url || '/wp-admin/admin-ajax.php';
  var category = <?= wp_json_encode($category) ?>;
  var SOURCES = window.RTOFB_SOURCES || [];
  var DOCTYPES = window.RTOFB_DOCTYPES || [];

  var TYPE_LABEL = {
    text:'Text', textarea:'Textarea', number:'Number', tel:'Phone', email:'Email',
    select:'Dropdown', radio:'Radio', checkbox:'Checkbox', multiselect:'Multi-select',
    dependent_select:'Dependent Dropdown', date:'Date', time:'Time', file:'File Upload',
    address:'Address', consent:'Consent', heading:'Section Heading'
  };
  var OPTION_TYPES = ['select','radio','checkbox','multiselect'];

  // ── In-memory state (single source of truth — the canvas and settings
  // panel are just renders of this; nothing is read back out of the DOM at
  // save time, unlike the old grid-of-inputs builder). ──────────────────
  var state = {
    steps: (<?= wp_json_encode($steps ?: []) ?>).map(normaliseStepIn),
    documents: (<?= wp_json_encode($documents ?: []) ?>).map(normaliseDocIn)
  };
  if (!state.steps.length) state.steps = [{ key:'default', label:'Details', fields: [] }];
  var activeStepIdx = 0;
  var selection = null; // {kind:'field', stepIdx, fieldIdx} | {kind:'doc', idx} | null

  function normaliseStepIn(s){
    return { key: s.key||'', label: s.label||'', fields: (s.fields||[]).map(normaliseFieldIn) };
  }
  function normaliseFieldIn(f){
    return Object.assign({
      key:'', label:'', type:'text', width:'full', required:false, placeholder:'', help_text:'',
      options:{}, validation:{min:null,max:null,min_length:null,max_length:null,pattern:null},
      dependent_source:null, visible_if:null, active:true, show_label:true
    }, f);
  }
  function normaliseDocIn(d){
    return Object.assign({ doc_type_id:0, label:'', required:true, visible_if:null }, d);
  }

  function uid(prefix){ return prefix + Math.random().toString(36).slice(2,8); }

  function allFieldKeys(){
    var keys = [];
    state.steps.forEach(function(s){ s.fields.forEach(function(f){ if (f.key) keys.push(f.key); }); });
    return keys;
  }

  // Known Limitations audit fix: "No visible warning when deleting a field
  // that other fields' conditions depend on." A visible_if condition is
  // {logic:'AND'|'OR', rules:[{field,op,value}], groups:[{logic,rules,groups}...]}
  // (see FormEngineService::evaluateCondition() / ConditionGroupEvaluator.php)
  // — this walks a condition tree recursively (rules can be nested inside
  // groups) looking for any rule whose field matches the key being deleted.
  function conditionReferencesKey(cond, key){
    if (!cond || !key) return false;
    var rules = cond.rules || [];
    for (var i = 0; i < rules.length; i++) {
      if (rules[i] && rules[i].field === key) return true;
    }
    var groups = cond.groups || [];
    for (var j = 0; j < groups.length; j++) {
      if (conditionReferencesKey(groups[j], key)) return true;
    }
    return false;
  }

  // Scans every OTHER field's visible_if and every document's visible_if
  // for a reference to `key`, returning the human-readable labels of what
  // would break — used to warn before a field is deleted.
  function findFieldDependents(key, excludeStepIdx, excludeFieldIdx){
    var labels = [];
    state.steps.forEach(function(s, si){
      s.fields.forEach(function(f, fi){
        if (si === excludeStepIdx && fi === excludeFieldIdx) return;
        if (f.key === key) return; // a field can't condition on itself; skip self-match noise
        if (conditionReferencesKey(f.visible_if, key)) labels.push('Field: ' + (f.label || f.key));
      });
    });
    (state.documents || []).forEach(function(d){
      if (conditionReferencesKey(d.visible_if, key)) labels.push('Document: ' + (d.label || 'Document requirement'));
    });
    return labels;
  }

  // Rewrites every visible_if rule referencing `oldKey` (across every field
  // and document, in every step, walking nested condition groups) to point
  // at `newKey` instead — the safety cascade that makes renaming a Field
  // Key possible without silently orphaning other fields' conditions.
  function renameConditionRefs(cond, oldKey, newKey){
    if (!cond) return;
    (cond.rules || []).forEach(function(r){ if (r && r.field === oldKey) r.field = newKey; });
    (cond.groups || []).forEach(function(g){ renameConditionRefs(g, oldKey, newKey); });
  }
  function renameFieldKeyEverywhere(oldKey, newKey, excludeStepIdx, excludeFieldIdx){
    if (oldKey === newKey) return;
    state.steps.forEach(function(s, si){
      s.fields.forEach(function(f, fi){
        if (si === excludeStepIdx && fi === excludeFieldIdx) return;
        renameConditionRefs(f.visible_if, oldKey, newKey);
      });
    });
    (state.documents || []).forEach(function(d){ renameConditionRefs(d.visible_if, oldKey, newKey); });
  }

  function confirmFieldDelete(key, excludeStepIdx, excludeFieldIdx){
    var dependents = key ? findFieldDependents(key, excludeStepIdx, excludeFieldIdx) : [];
    if (dependents.length) {
      return confirm(
        'Delete this field?\n\nWarning: ' + dependents.length + ' other item(s) have a conditional rule that references this field and will stop working correctly:\n— ' +
        dependents.join('\n— ') +
        '\n\nDeleting it will leave those conditions pointing at a field that no longer exists (they will simply never match). Continue?'
      );
    }
    return confirm('Delete this field?');
  }

  // ── Step tabs ────────────────────────────────────────────────────────
  function renderStepTabs(){
    var wrap = document.getElementById('stepTabs');
    wrap.innerHTML = '';
    state.steps.forEach(function(step, i){
      var tab = document.createElement('div');
      tab.className = 'rfb-steptab' + (i === activeStepIdx ? ' active' : '');
      tab.innerHTML = '<span>' + (step.label || step.key || ('Step ' + (i+1))) + '</span>' +
        (state.steps.length > 1 ? '<span class="x" title="Remove step">✕</span>' : '');
      tab.addEventListener('click', function(e){
        if (e.target.classList.contains('x')) {
          e.stopPropagation();
          if (!confirm('Remove this step and all its fields?')) return;
          state.steps.splice(i, 1);
          if (activeStepIdx >= state.steps.length) activeStepIdx = state.steps.length - 1;
          selection = null;
          renderAll();
          return;
        }
        activeStepIdx = i; selection = null; renderAll();
      });
      wrap.appendChild(tab);
    });
    var add = document.createElement('div');
    add.className = 'rfb-addstep';
    add.textContent = '+ Add Step';
    add.addEventListener('click', function(){
      state.steps.push({ key: uid('step_'), label: 'New Step', fields: [] });
      activeStepIdx = state.steps.length - 1; selection = null; renderAll();
    });
    wrap.appendChild(add);
  }

  // ── Field list (the canvas for the active step) — Part 4.13: rows show
  // ▲▼ reorder arrows plus Edit/Duplicate/Delete buttons (matching the
  // reference plugin's own field list) instead of drag-to-reorder +
  // click-row-to-select; "Edit" opens the tabbed modal below rather than
  // driving the old always-open side settings panel. ────────────────────
  function renderFieldList(){
    var stepLabelInput = document.getElementById('activeStepLabel');
    var step = state.steps[activeStepIdx];
    stepLabelInput.value = step.label;
    stepLabelInput.oninput = function(){ step.label = stepLabelInput.value; renderStepTabs(); };

    var list = document.getElementById('fieldList');
    list.innerHTML = '';
    if (!step.fields.length) {
      list.innerHTML = '<div class="rfb-empty"><div class="big">＋</div>Click a field type on the left to add your first field to this step.</div>';
      return;
    }
    step.fields.forEach(function(field, i){
      var row = document.createElement('div');
      row.className = 'rfb-row-v2';
      var metaBits = [];
      if (field.required) metaBits.push('<span title="Required" style="color:#f97316;font-weight:600">● Required</span>');
      if (field.visible_if) metaBits.push('<span class="cond-badge" title="Has a conditional rule">⑂ Conditional</span>');
      if (field.active === false) metaBits.push('<span class="inactive-badge" title="Hidden from the live form, key preserved for past submissions">Inactive</span>');
      row.innerHTML =
        (function(){
          var fieldName = field.label || ('untitled ' + (TYPE_LABEL[field.type]||field.type) + ' field');
          var fieldNameAttr = escHtml(fieldName);
          return '<div class="rfb-arrows">' +
          '<button type="button" class="fb-up" title="Move up" aria-label="Move field up: ' + fieldNameAttr + '"' + (i===0?' disabled':'') + '>▲</button>' +
          '<button type="button" class="fb-down" title="Move down" aria-label="Move field down: ' + fieldNameAttr + '"' + (i===step.fields.length-1?' disabled':'') + '>▼</button>' +
        '</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div class="flabel">' + escHtml(field.label || '(untitled ' + (TYPE_LABEL[field.type]||field.type) + ')') + '</div>' +
          '<div class="fmetaline"><span class="fkey" style="font-family:monospace">' + escHtml(field.key||'') + '</span>' +
            '<span class="ftype-pill">' + (TYPE_LABEL[field.type] || field.type) + '</span>' + metaBits.join('') +
          '</div>' +
        '</div>' +
        '<div class="rowbtns">' +
          '<button type="button" class="rto-btn rto-btn--sm fb-edit" title="Edit field" aria-label="Edit field: ' + fieldNameAttr + '">✏️ Edit</button>' +
          '<button type="button" class="rto-btn rto-btn--sm fb-dup" title="Duplicate field" aria-label="Duplicate field: ' + fieldNameAttr + '">⧉</button>' +
          '<button type="button" class="rto-btn rto-btn--sm fb-del" title="Delete field" aria-label="Delete field: ' + fieldNameAttr + '">🗑</button>' +
        '</div>';
        })();
      row.querySelector('.fb-up').addEventListener('click', function(){
        if (i === 0) return;
        var tmp = step.fields[i-1]; step.fields[i-1] = step.fields[i]; step.fields[i] = tmp;
        renderAll();
      });
      row.querySelector('.fb-down').addEventListener('click', function(){
        if (i === step.fields.length-1) return;
        var tmp = step.fields[i+1]; step.fields[i+1] = step.fields[i]; step.fields[i] = tmp;
        renderAll();
      });
      row.querySelector('.fb-edit').addEventListener('click', function(e){ openFieldModal(activeStepIdx, i, e.currentTarget); });
      row.querySelector('.fb-dup').addEventListener('click', function(){
        var clone = JSON.parse(JSON.stringify(field));
        clone.key = uid((field.key || 'field') + '_copy_');
        clone.label = (field.label || 'Field') + ' (Copy)';
        step.fields.splice(i + 1, 0, clone);
        renderAll();
      });
      row.querySelector('.fb-del').addEventListener('click', function(){
        if (!confirmFieldDelete(field.key, activeStepIdx, i)) return;
        step.fields.splice(i, 1);
        renderAll();
      });
      list.appendChild(row);
    });
  }

  // ── Settings panel (documents only — field editing moved into the Edit
  // Field modal, see openFieldModal() below) ──────────────────────────────
  function renderSettingsPanel(){
    var panel = document.getElementById('settingsPanel');
    panel.innerHTML = '';
    if (!selection || selection.kind !== 'doc') {
      panel.innerHTML = '<div class="rfb-settings-empty">Click a document requirement below to edit it, or click "+ Add Document Requirement".</div>';
      return;
    }
    renderDocSettings(panel);
  }

  function renderDocSettings(panel){
    var doc = state.documents[selection.idx];
    var sec = document.createElement('div'); sec.className = 'rfb-sec';
    sec.innerHTML = '<div class="rfb-sec-title">Document Requirement</div>' +
      '<label class="rfb-field-label">Document Type</label><select class="rto-input st-doctype" style="width:100%;margin-bottom:8px"><option value="0">Select a document…</option></select>' +
      '<label class="rfb-field-label">Custom Label (optional — overrides the catalog name)</label><input type="text" class="rto-input st-doclabel" style="width:100%;margin-bottom:8px" value="' + escAttr(doc.label) + '">' +
      '<div class="rfb-toggle-row"><label>Required</label><input type="checkbox" class="st-docreq" ' + (doc.required?'checked':'') + '></div>';
    panel.appendChild(sec);
    var sel = sec.querySelector('.st-doctype');
    DOCTYPES.forEach(function(dt){
      var o = document.createElement('option'); o.value = dt.id;
      o.textContent = (dt.category ? dt.category + ' — ' : '') + dt.name;
      if (parseInt(doc.doc_type_id,10) === dt.id) o.selected = true;
      sel.appendChild(o);
    });
    if (!DOCTYPES.length) {
      var warn = document.createElement('div'); warn.className='rfb-help'; warn.style.color='#991B1B';
      warn.textContent = 'No document types found in the catalog yet — add one under Masters first.';
      panel.appendChild(warn);
    }
    sel.addEventListener('change', function(){ doc.doc_type_id = parseInt(sel.value,10)||0; renderDocsList(); });
    sec.querySelector('.st-doclabel').addEventListener('input', function(e){ doc.label = e.target.value; renderDocsList(); });
    sec.querySelector('.st-docreq').addEventListener('change', function(e){ doc.required = e.target.checked; renderDocsList(); });

    var sec2 = document.createElement('div'); sec2.className = 'rfb-sec';
    sec2.innerHTML = '<div class="rfb-sec-title">Required only if…</div><div class="rfb-cond-mount"></div>';
    panel.appendChild(sec2);
    renderConditionEditor(sec2.querySelector('.rfb-cond-mount'), doc.visible_if, function(newCond){ doc.visible_if = newCond; }, null);

    var delBtn = document.createElement('button');
    delBtn.type = 'button'; delBtn.className = 'rto-btn rto-btn--sm rto-btn--danger'; delBtn.style.width='100%';
    delBtn.textContent = 'Delete This Document Requirement';
    delBtn.addEventListener('click', function(){
      if (!confirm('Delete this document requirement?')) return;
      state.documents.splice(selection.idx, 1);
      selection = null; renderAll();
    });
    panel.appendChild(delBtn);
  }

  // ── Plain-language conditional-logic editor: a flat list of
  // "FIELD  operator  value" lines, ANY/ALL joined — collected back into
  // the engine's {logic,rules,groups} shape as a single group. This covers
  // the vast majority of real conditions (which are single-level) with far
  // less UI than a full nested tree; a saved schema that already has
  // nested OR-of-AND groups (built via import, or by a future editor)
  // still round-trips correctly because collect() only ever WRITES a
  // single-level group, and this editor is not the only way to produce a
  // valid schema — the same evaluateCondition() reads whatever shape is
  // stored either way. ──────────────────────────────────────────────────
  /** FIX (Part 4.12 — real gap, not cosmetic): every field in a Part 4.10
   *  category schema carries a service-gate condition — "only show this
   *  field for service X" (or "for services X, Y, Z") — built by
   *  RealFormSchemaSeeder's gateOne()/gateMany() as
   *  {logic:'AND', rules:[{field:'selected_service', op:'equals'|'in', value:...}],
   *   groups:[<the field's own original condition, if it had one>]}.
   *  The OLD version of this editor flattened cond.groups' rules straight
   *  into the same flat list as the field's own business rules with no
   *  distinction — meaning the service-gate rule showed up as just another
   *  anonymous, editable, deletable row. An admin who deleted it (with no
   *  way to tell it apart from a real business rule) would make that field
   *  visible for EVERY service in the category; an admin who edited any
   *  OTHER rule would trigger commit(), which always wrote back a single
   *  flattened {logic, rules, groups:[]} — permanently collapsing the
   *  two-level nesting and silently changing meaning for any field whose
   *  original condition had multiple rules under OR logic (AND-of-flattened
   *  is not equivalent to AND(gate, OR(a,b))).
   *
   *  Fixed by rendering the service gate as its OWN explicit, clearly
   *  labelled multi-select control — never mixed into the generic rule
   *  list — and reconstructing the correct two-level shape on every commit:
   *  {logic:'AND', rules:[gateRule], groups:[businessGroup]} (or just the
   *  business group alone if every service in the category is selected —
   *  i.e. "no real gating," matching how a hand-written non-gated field
   *  looks) so nesting is never destroyed by touching the editor. */
  function renderConditionEditor(mount, cond, onChange, ownFieldKey){
    var categoryServices = window.RTOFB_CATEGORY_SERVICES || [];

    function isServiceGateRule(r){ return r && r.field === 'selected_service'; }
    function gateValues(r){ return r ? (Array.isArray(r.value) ? r.value.slice() : [r.value]) : categoryServices.slice(); }

    // Detect the seeder's {rules:[gate], groups:[business]} shape; anything
    // else (a hand-built condition with no service gate, or a plain flat
    // condition from before this fix) is treated as pure business rules
    // with every category service implicitly selected (i.e. "not gated").
    var gateRule = null, businessGroup = null;
    if (cond && (cond.rules || []).length === 1 && isServiceGateRule(cond.rules[0]) && categoryServices.length) {
      gateRule = cond.rules[0];
      businessGroup = (cond.groups || [])[0] || null;
    } else if (cond) {
      businessGroup = cond;
    }

    var selectedServices = gateValues(gateRule).map(String);
    var lines = [];
    var topLogic = 'AND';
    if (businessGroup) {
      topLogic = businessGroup.logic === 'OR' ? 'OR' : 'AND';
      lines = (businessGroup.rules || []).slice();
      // A hand-built or legacy condition could itself still have its own
      // nested groups — those aren't the service gate (already consumed
      // above), so surface their rules flatly rather than silently drop
      // them, matching this editor's pre-existing (if imperfect) behaviour
      // for genuinely nested business conditions.
      (businessGroup.groups || []).forEach(function(g){ (g.rules||[]).forEach(function(r){ lines.push(r); }); });
    }

    function redrawGateControl(){
      if (!categoryServices.length) return null;
      var wrap = document.createElement('div');
      wrap.style.cssText = 'background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px;margin-bottom:10px';
      var label = document.createElement('div');
      label.style.cssText = 'font-size:11px;font-weight:700;color:#1d4ed8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.03em';
      label.textContent = 'Visible for these services';
      wrap.appendChild(label);
      var help = document.createElement('div');
      help.style.cssText = 'font-size:11px;color:#64748b;margin-bottom:6px';
      help.textContent = 'This field belongs to a category form covering ' + categoryServices.length + ' services — choose which one(s) show it. Selecting all of them means it is never hidden based on the picked service.';
      wrap.appendChild(help);
      categoryServices.forEach(function(name){
        var row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;font-weight:400;font-size:12px;margin:3px 0';
        var box = document.createElement('input'); box.type = 'checkbox'; box.value = name;
        box.checked = selectedServices.indexOf(name) !== -1;
        box.addEventListener('change', function(){
          var idx = selectedServices.indexOf(name);
          if (box.checked && idx === -1) selectedServices.push(name);
          if (!box.checked && idx !== -1) selectedServices.splice(idx, 1);
          commit();
        });
        row.appendChild(box);
        row.appendChild(document.createTextNode(name));
        wrap.appendChild(row);
      });
      return wrap;
    }

    function redraw(){
      mount.innerHTML = '';
      var gateEl = redrawGateControl();
      if (gateEl) mount.appendChild(gateEl);
      if (!lines.length) {
        var p = document.createElement('div'); p.className = 'rfb-help'; p.textContent = 'Always shown — click "+ Add Rule" to make it conditional.';
        mount.appendChild(p);
      } else {
        if (lines.length > 1) {
          var logicSel = document.createElement('select');
          logicSel.className = 'rto-input'; logicSel.style.cssText = 'width:auto;margin-bottom:8px;font-size:12px';
          ['AND','OR'].forEach(function(l){ var o=document.createElement('option'); o.value=l; o.textContent = l==='AND' ? 'Match ALL rules below' : 'Match ANY rule below'; if (l===topLogic) o.selected=true; logicSel.appendChild(o); });
          logicSel.addEventListener('change', function(){ topLogic = logicSel.value; commit(); });
          mount.appendChild(logicSel);
        }
        lines.forEach(function(rule, i){
          var row = document.createElement('div'); row.className = 'rfb-condline';
          row.innerHTML = '<div class="rfb-condline-grid">' +
            '<select class="rto-input rc-field" style="width:100%"></select>' +
            '<select class="rto-input rc-op" style="width:100%">' +
              ['equals','not_equals','in','not_in','contains','filled','empty','greater_than','less_than'].map(function(op){
                var lbl = {equals:'is',not_equals:'is not',in:'is one of',not_in:'is not one of',contains:'contains',filled:'is filled',empty:'is empty',greater_than:'>',less_than:'<'}[op];
                return '<option value="'+op+'"' + (rule.op===op?' selected':'') + '>' + lbl + '</option>';
              }).join('') +
            '</select>' +
            '<input type="text" class="rto-input rc-value" style="width:100%" placeholder="value" value="' + escAttr(Array.isArray(rule.value)?rule.value.join(', '):(rule.value!==undefined?rule.value:'')) + '">' +
            '<span class="fdel" style="cursor:pointer;color:#94a3b8">✕</span>' +
          '</div>';
          var fieldSel = row.querySelector('.rc-field');
          var blank = document.createElement('option'); blank.value=''; blank.textContent='— choose field —'; fieldSel.appendChild(blank);
          allFieldKeys().forEach(function(k){
            if (k === ownFieldKey) return; // a field can't condition on itself
            var o = document.createElement('option'); o.value = k; o.textContent = k;
            if (k === rule.field) o.selected = true;
            fieldSel.appendChild(o);
          });
          var opSel = row.querySelector('.rc-op'), valInput = row.querySelector('.rc-value');
          function updateValVisibility(){ valInput.style.display = (opSel.value==='filled'||opSel.value==='empty') ? 'none' : ''; }
          updateValVisibility();
          function commitLine(){
            rule.field = fieldSel.value; rule.op = opSel.value;
            var raw = valInput.value.trim();
            rule.value = (opSel.value==='in'||opSel.value==='not_in') ? raw.split(',').map(function(s){return s.trim();}).filter(Boolean) : raw;
            commit();
          }
          fieldSel.addEventListener('change', commitLine);
          opSel.addEventListener('change', function(){ updateValVisibility(); commitLine(); });
          valInput.addEventListener('input', commitLine);
          row.querySelector('.fdel').addEventListener('click', function(){ lines.splice(i,1); redraw(); commit(); });
          mount.appendChild(row);
        });
      }
      var addBtn = document.createElement('span');
      addBtn.className = 'rfb-add-mini'; addBtn.textContent = '+ Add Rule';
      addBtn.style.display = 'inline-block'; addBtn.style.marginTop = '4px';
      addBtn.addEventListener('click', function(){ lines.push({ field:'', op:'equals', value:'' }); redraw(); });
      mount.appendChild(addBtn);
    }

    function commit(){
      var valid = lines.filter(function(r){ return r.field; });
      var newBusinessGroup = valid.length ? { logic: topLogic, rules: valid, groups: [] } : null;

      // Only emit an explicit gate rule when it's actually restrictive —
      // every category service selected (or none, which redrawGateControl
      // never allows to persist meaningfully) means "not gated," so the
      // saved schema stays as clean as a hand-built condition with no gate
      // rather than always carrying a no-op "in [every service]" clause.
      var isRestrictive = categoryServices.length > 0 &&
        selectedServices.length > 0 && selectedServices.length < categoryServices.length;
      var newGateRule = isRestrictive
        ? (selectedServices.length === 1
            ? { field: 'selected_service', op: 'equals', value: selectedServices[0] }
            : { field: 'selected_service', op: 'in', value: selectedServices.slice() })
        : null;

      if (newGateRule && newBusinessGroup) {
        onChange({ logic: 'AND', rules: [newGateRule], groups: [newBusinessGroup] });
      } else if (newGateRule) {
        onChange({ logic: 'AND', rules: [newGateRule], groups: [] });
      } else {
        onChange(newBusinessGroup); // null if there's truly no condition left at all
      }
      // Deliberately no redraw() here — every caller of commit() (checkbox
      // toggle, field/op/value change, row delete) already reflects its own
      // change in the DOM before calling commit(), and valInput's 'input'
      // listener calls this on every keystroke — a redraw() here would
      // rebuild the whole editor mid-keystroke and steal focus from the
      // input the admin is actively typing in.
    }

    redraw();
  }

  // ── Edit Field modal (Part 4.13): Basic / Options / Conditions tabs.
  // Edits happen on a CLONE (modalCtx.draft) of the real field so "Cancel"
  // truly discards changes — the live field object is only mutated (or
  // moved to a different step, via the Form Step dropdown) when "Update
  // Field" commits it back into state. ──────────────────────────────────
  var modalCtx = null;
  var fieldModalReturnFocus = null;

  function openFieldModal(stepIdx, fieldIdx, triggerEl){
    var original = state.steps[stepIdx].fields[fieldIdx];
    modalCtx = { origStepIdx: stepIdx, fieldIdx: fieldIdx, targetStepIdx: stepIdx, draft: JSON.parse(JSON.stringify(original)), origKey: original.key };
    document.getElementById('fieldModalTitle').textContent = 'Edit Field';
    renderModalBasicTab();
    renderModalOptionsTab();
    renderModalConditionsTab();
    switchModalTab('basic');
    fieldModalReturnFocus = triggerEl || document.activeElement;
    var overlay = document.getElementById('fieldModalOverlay');
    overlay.classList.add('show');
    var dialog = overlay.querySelector('.rfb-modal');
    if (dialog) dialog.focus();
  }
  function closeFieldModal(){
    modalCtx = null;
    document.getElementById('fieldModalOverlay').classList.remove('show');
    if (fieldModalReturnFocus && typeof fieldModalReturnFocus.focus === 'function') {
      fieldModalReturnFocus.focus();
    }
    fieldModalReturnFocus = null;
  }
  function switchModalTab(tab){
    document.querySelectorAll('.rfb-modal-tab').forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-tab') === tab); });
    var map = { basic:'tabPanelBasic', options:'tabPanelOptions', conditions:'tabPanelConditions' };
    Object.keys(map).forEach(function(k){ document.getElementById(map[k]).classList.toggle('active', k === tab); });
  }
  document.querySelectorAll('.rfb-modal-tab').forEach(function(t){
    t.addEventListener('click', function(){ switchModalTab(t.getAttribute('data-tab')); });
  });
  document.getElementById('fieldModalCloseX').addEventListener('click', closeFieldModal);
  document.getElementById('fieldModalCancel').addEventListener('click', closeFieldModal);
  document.getElementById('fieldModalOverlay').addEventListener('click', function(e){
    if (e.target.id === 'fieldModalOverlay') closeFieldModal(); // click on the dark backdrop = cancel
  });
  document.getElementById('fieldModalOverlay').addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeFieldModal();
  });
  document.getElementById('fieldModalSave').addEventListener('click', function(){
    if (!modalCtx) return;
    var draft = modalCtx.draft;
    if (!draft.key) { alert('Field Key is required.'); return; }
    if (draft._keyError) { alert('Fix the Field Key error before saving.'); return; }
    if (draft.key !== modalCtx.origKey) {
      renameFieldKeyEverywhere(modalCtx.origKey, draft.key, modalCtx.origStepIdx, modalCtx.fieldIdx);
    }
    state.steps[modalCtx.origStepIdx].fields.splice(modalCtx.fieldIdx, 1);
    state.steps[modalCtx.targetStepIdx].fields.push(draft);
    activeStepIdx = modalCtx.targetStepIdx;
    selection = null;
    closeFieldModal();
    renderAll();
  });

  function renderModalBasicTab(){
    var panel = document.getElementById('tabPanelBasic');
    panel.innerHTML = '';
    var draft = modalCtx.draft;
    var wrap = document.createElement('div');
    wrap.innerHTML =
      '<label class="rfb-field-label">Field Type</label>' +
      '<select class="rto-input mf-type" style="width:100%;margin-bottom:8px"></select>' +
      '<div class="rfb-row2" style="margin-bottom:8px">' +
        '<div><label class="rfb-field-label">Form Step</label><select class="rto-input mf-step" style="width:100%"></select></div>' +
        '<div><label class="rfb-field-label">Width</label><select class="rto-input mf-width" style="width:100%">' +
          ['full','half','third'].map(function(w){ return '<option value="'+w+'"' + (draft.width===w?' selected':'') + '>' + w.charAt(0).toUpperCase()+w.slice(1) + '</option>'; }).join('') +
        '</select></div>' +
      '</div>' +
      '<label class="rfb-field-label">Label</label>' +
      '<input type="text" class="rto-input mf-label" style="width:100%;margin-bottom:8px" value="' + escAttr(draft.label) + '">' +
      '<label class="rfb-field-label">Field Key <span style="color:#64748b;font-weight:400">(used by conditions and submitted answers — renaming automatically updates every condition that references it)</span></label>' +
      '<input type="text" class="rto-input mf-key" style="width:100%;margin-bottom:8px" value="' + escAttr(draft.key) + '" pattern="[a-zA-Z0-9_]+">' +
      '<div class="mf-key-err" style="color:#dc2626;font-size:11px;margin:-6px 0 8px;display:none"></div>' +
      '<label class="rfb-field-label">Placeholder</label>' +
      '<input type="text" class="rto-input mf-placeholder" style="width:100%;margin-bottom:8px" value="' + escAttr(draft.placeholder) + '">' +
      '<label class="rfb-field-label">Help Text (shown under the field)</label>' +
      '<input type="text" class="rto-input mf-help" style="width:100%;margin-bottom:10px" value="' + escAttr(draft.help_text) + '">' +
      '<div class="rfb-toggle-row"><label>Required field</label><input type="checkbox" class="mf-required" ' + (draft.required?'checked':'') + '></div>' +
      '<div class="rfb-toggle-row"><label>Show label</label><input type="checkbox" class="mf-showlabel" ' + (draft.show_label!==false?'checked':'') + '></div>' +
      '<div class="rfb-toggle-row"><label>Active <span style="color:#94a3b8;font-weight:400;font-size:11px">(uncheck to hide without deleting — keeps past submissions readable)</span></label><input type="checkbox" class="mf-active" ' + (draft.active!==false?'checked':'') + '></div>';
    panel.appendChild(wrap);

    var typeSel = wrap.querySelector('.mf-type');
    Object.keys(TYPE_LABEL).forEach(function(t){
      var o = document.createElement('option'); o.value = t; o.textContent = TYPE_LABEL[t];
      if (t === draft.type) o.selected = true;
      typeSel.appendChild(o);
    });
    typeSel.addEventListener('change', function(){
      draft.type = typeSel.value;
      if (OPTION_TYPES.indexOf(draft.type) !== -1 && !draft.options) draft.options = { option_1: 'Option 1', option_2: 'Option 2' };
      renderModalOptionsTab();
    });

    var stepSel = wrap.querySelector('.mf-step');
    state.steps.forEach(function(s, si){
      var o = document.createElement('option'); o.value = si; o.textContent = s.label || s.key || ('Step ' + (si+1));
      if (si === modalCtx.targetStepIdx) o.selected = true;
      stepSel.appendChild(o);
    });
    stepSel.addEventListener('change', function(){ modalCtx.targetStepIdx = parseInt(stepSel.value, 10); });

    wrap.querySelector('.mf-width').addEventListener('change', function(e){ draft.width = e.target.value; });
    // Known Limitations audit fix: "Renaming a Field Key after creation is
    // not possible." The key is now editable — validated live for
    // emptiness, allowed characters, and uniqueness against every OTHER
    // field's key — with the actual rename-safety cascade (rewriting every
    // visible_if reference to the old key) applied on Save via
    // renameFieldKeyEverywhere(), reusing the same condition-tree walk
    // built for the delete-dependency warning above.
    wrap.querySelector('.mf-key').addEventListener('input', function(e){
      var newKey = e.target.value.trim();
      var errEl = wrap.querySelector('.mf-key-err');
      var err = '';
      if (!newKey) err = 'Field Key is required.';
      else if (!/^[a-zA-Z0-9_]+$/.test(newKey)) err = 'Only letters, numbers, and underscores are allowed.';
      else {
        var dupe = allFieldKeys().some(function(k){ return k === newKey && k !== modalCtx.origKey; });
        if (dupe) err = 'Another field already uses this key.';
      }
      errEl.textContent = err;
      errEl.style.display = err ? '' : 'none';
      draft.key = newKey;
      draft._keyError = !!err;
    });
    wrap.querySelector('.mf-label').addEventListener('input', function(e){ draft.label = e.target.value; });
    wrap.querySelector('.mf-placeholder').addEventListener('input', function(e){ draft.placeholder = e.target.value; });
    wrap.querySelector('.mf-help').addEventListener('input', function(e){ draft.help_text = e.target.value; });
    wrap.querySelector('.mf-required').addEventListener('change', function(e){ draft.required = e.target.checked; });
    wrap.querySelector('.mf-showlabel').addEventListener('change', function(e){ draft.show_label = e.target.checked; });
    wrap.querySelector('.mf-active').addEventListener('change', function(e){ draft.active = e.target.checked; });
  }

  function renderModalOptionsTab(){
    var panel = document.getElementById('tabPanelOptions');
    panel.innerHTML = '';
    var draft = modalCtx.draft;
    var any = false;
    if (OPTION_TYPES.indexOf(draft.type) !== -1) {
      any = true;
      var sec = document.createElement('div');
      sec.innerHTML = '<div class="rfb-sec-title">Options</div>' +
        '<textarea class="rto-input mf-options" rows="6" style="width:100%" placeholder="One per line: value|Label, or just value">' + escHtml(optionsToText(draft.options)) + '</textarea>' +
        '<div class="rfb-help">One option per line. Use <code>value|Label</code> to show a different label than the stored value.</div>';
      panel.appendChild(sec);
      sec.querySelector('.mf-options').addEventListener('input', function(e){ draft.options = textToOptions(e.target.value); });
    }
    if (draft.type === 'dependent_select') {
      any = true;
      var sec2 = document.createElement('div'); sec2.style.marginTop = '14px';
      sec2.innerHTML = '<div class="rfb-sec-title">Dependent Dropdown</div>' +
        '<label class="rfb-field-label">Data Source</label><select class="rto-input mf-dep-source" style="width:100%;margin-bottom:8px"></select>' +
        '<label class="rfb-field-label">Depends on field (key)</label><input type="text" class="rto-input mf-dep-parent" style="width:100%" placeholder="e.g. state" value="' + escAttr(draft.dependent_source ? draft.dependent_source.parent_field : '') + '">' +
        '<div class="rfb-help">Its options are looked up live from the chosen field\'s current value — e.g. picking a State fills the RTO Office list.</div>';
      panel.appendChild(sec2);
      var depSel = sec2.querySelector('.mf-dep-source');
      SOURCES.forEach(function(s){ var o=document.createElement('option'); o.value=s; o.textContent=s; if (draft.dependent_source && draft.dependent_source.source===s) o.selected=true; depSel.appendChild(o); });
      function syncDep(){ draft.dependent_source = { source: depSel.value, parent_field: sec2.querySelector('.mf-dep-parent').value.trim() }; }
      depSel.addEventListener('change', syncDep);
      sec2.querySelector('.mf-dep-parent').addEventListener('input', syncDep);
    }
    if (!any) panel.innerHTML = '<div class="rfb-settings-empty">This field type has no options to configure.</div>';
  }

  function renderModalConditionsTab(){
    var panel = document.getElementById('tabPanelConditions');
    panel.innerHTML = '';
    var draft = modalCtx.draft;

    var vsec = document.createElement('div');
    var v = draft.validation || {};
    vsec.innerHTML = '<div class="rfb-sec-title">Validation (optional)</div>' +
      '<div class="rfb-row2">' +
        '<div><label class="rfb-field-label">Min value / length</label><input type="text" class="rto-input mf-min" style="width:100%" value="' + escAttr(v.min ?? v.min_length ?? '') + '"></div>' +
        '<div><label class="rfb-field-label">Max value / length</label><input type="text" class="rto-input mf-max" style="width:100%" value="' + escAttr(v.max ?? v.max_length ?? '') + '"></div>' +
      '</div>' +
      '<label class="rfb-field-label" style="margin-top:8px">Regex Pattern</label><input type="text" class="rto-input mf-pattern" style="width:100%;margin-bottom:14px" placeholder="e.g. ^[A-Z]{2}[0-9]{2}" value="' + escAttr(v.pattern||'') + '">';
    panel.appendChild(vsec);
    function syncValidation(){
      var min = vsec.querySelector('.mf-min').value.trim(), max = vsec.querySelector('.mf-max').value.trim(), pattern = vsec.querySelector('.mf-pattern').value.trim();
      draft.validation = {
        min: min !== '' && !isNaN(min) ? Number(min) : null, max: max !== '' && !isNaN(max) ? Number(max) : null,
        min_length: null, max_length: null, pattern: pattern || null
      };
    }
    vsec.querySelectorAll('.mf-min,.mf-max,.mf-pattern').forEach(function(el){ el.addEventListener('input', syncValidation); });

    var csec = document.createElement('div');
    csec.innerHTML = '<div class="rfb-sec-title">Show this field only if…</div><div class="rfb-cond-mount"></div>';
    panel.appendChild(csec);
    renderConditionEditor(csec.querySelector('.rfb-cond-mount'), draft.visible_if, function(newCond){ draft.visible_if = newCond; }, draft.key);
  }

  // ── Documents list (compact rows, same click-to-edit pattern as fields) ──
  function renderDocsList(){
    var list = document.getElementById('docsList');
    var noDocsMsg = document.getElementById('noDocsMsg');
    list.innerHTML = '';
    noDocsMsg.style.display = state.documents.length ? 'none' : '';
    state.documents.forEach(function(doc, i){
      var dt = DOCTYPES.filter(function(d){ return d.id === parseInt(doc.doc_type_id,10); })[0];
      var name = doc.label || (dt ? dt.name : '(choose a document type)');
      var row = document.createElement('div');
      row.className = 'rfb-fieldrow' + (selection && selection.kind==='doc' && selection.idx===i ? ' selected' : '');
      row.innerHTML = '<span class="flabel">' + name + '</span>' +
        '<span class="fmeta">' + (doc.required?'<span class="req-dot" title="Required"></span>':'') + (doc.visible_if?'<span class="cond-dot" title="Conditional">⑂</span>':'') + '</span>' +
        '<span class="fdel" title="Delete">✕</span>';
      row.addEventListener('click', function(e){
        if (e.target.classList.contains('fdel')) {
          if (!confirm('Delete this document requirement?')) return;
          state.documents.splice(i,1);
          if (selection && selection.kind==='doc' && selection.idx===i) selection = null;
          renderAll();
          return;
        }
        selection = { kind:'doc', idx: i };
        renderSettingsPanel(); renderDocsList();
      });
      list.appendChild(row);
    });
  }
  document.getElementById('addDocBtn').addEventListener('click', function(){
    state.documents.push(normaliseDocIn({}));
    selection = { kind:'doc', idx: state.documents.length - 1 };
    renderAll();
  });

  // ── Palette: click a type to append a new field to the active step ──
  document.querySelectorAll('.rfb-pal-item').forEach(function(item){
    item.addEventListener('click', function(){
      var type = item.getAttribute('data-type');
      var step = state.steps[activeStepIdx];
      var field = normaliseFieldIn({ key: uid('field_'), label: 'New ' + (TYPE_LABEL[type]||type) + ' Field', type: type });
      if (OPTION_TYPES.indexOf(type) !== -1) field.options = { option_1: 'Option 1', option_2: 'Option 2' };
      step.fields.push(field);
      selection = { kind:'field', stepIdx: activeStepIdx, fieldIdx: step.fields.length - 1 };
      renderAll();
    });
  });

  function renderAll(){ renderStepTabs(); renderFieldList(); renderSettingsPanel(); renderDocsList(); }
  renderAll();

  // ── Helpers ──────────────────────────────────────────────────────────
  function escAttr(v){ return String(v===undefined||v===null?'':v).replace(/"/g,'&quot;'); }
  function escHtml(v){ return String(v===undefined||v===null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function optionsToText(options){
    if (!options) return '';
    return Object.keys(options).map(function(v){ return options[v] && options[v] !== v ? (v+'|'+options[v]) : v; }).join('\n');
  }
  function textToOptions(text){
    var out = {};
    (text||'').split('\n').forEach(function(line){
      line = line.trim(); if (!line) return;
      var parts = line.split('|'); var v = parts[0].trim();
      var l = (parts[1] !== undefined ? parts[1].trim() : v);
      if (v) out[v] = l || v;
    });
    return out;
  }

  // ── Collect state into the v2 schema shape (identical output contract
  // to the previous builder — same {meta,steps,documents} shape the
  // backend's normaliseSchema() has always expected) ────────────────────
  function collectSchema(){
    var steps = state.steps.map(function(step, si){
      return {
        key: step.key || ('step_' + (si+1)),
        label: step.label || ('Step ' + (si+1)),
        fields: step.fields.map(function(f, fi){
          var out = {
            key: f.key, label: f.label, type: f.type, width: f.width, required: !!f.required,
            order: (fi+1)*10, placeholder: f.placeholder||'', help_text: f.help_text||'',
            options: OPTION_TYPES.indexOf(f.type)!==-1 ? (f.options||{}) : null,
            validation: f.validation || {}, visible_if: f.visible_if || null,
            active: f.active !== false, show_label: f.show_label !== false
          };
          if (f.type === 'dependent_select') out.dependent_source = f.dependent_source;
          return out;
        })
      };
    });
    var documents = state.documents.map(function(d){
      return { doc_type_id: parseInt(d.doc_type_id,10)||0, label: d.label||'', required: !!d.required, visible_if: d.visible_if || null };
    });
    return { meta: { title: document.getElementById('formName').value.trim() }, steps: steps, documents: documents };
  }

  document.getElementById('saveFormBtn').addEventListener('click', function(){
    var msg = document.getElementById('saveMsg');
    var name = document.getElementById('formName').value.trim();
    if (!name) { msg.style.color = '#991B1B'; msg.textContent = 'A form name is required.'; return; }
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.save');
    fd.append('rto_nonce', nonce);
    fd.append('category', category);
    fd.append('name', name);
    fd.append('version_note', document.getElementById('versionNote').value.trim());
    fd.append('schema', JSON.stringify(collectSchema()));
    msg.style.color = '#334155'; msg.textContent = 'Saving…';
    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        msg.style.color = r.success ? '#166534' : '#991B1B';
        msg.textContent = r.message || (r.success ? 'Saved.' : 'Failed.');
        if (r.success) setTimeout(function(){ location.reload(); }, 700);
      })
      .catch(function(){ msg.style.color = '#991B1B'; msg.textContent = 'Network error — form was not saved.'; });
  });

  // ── Live Preview: renders exactly what the customer would see, from the
  // current in-memory state, without needing to save first ──────────────
  document.getElementById('previewBtn').addEventListener('click', function(){
    var body = document.getElementById('previewBody');
    var html = '<h3 style="margin-top:0">' + (document.getElementById('formName').value || 'Preview') + '</h3>';
    state.steps.forEach(function(step){
      html += '<p style="font-weight:700;font-size:13px;color:#475569;margin:16px 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px">' + (step.label||'Step') + '</p>';
      step.fields.forEach(function(f){
        if (f.type === 'heading') { html += '<h4 style="margin:14px 0 4px">' + f.label + '</h4>'; return; }
        html += '<div style="margin-bottom:10px">';
        html += '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">' + f.label + (f.required?' <span style="color:#f97316">*</span>':'') + '</label>';
        if (f.visible_if) html += '<div style="font-size:11px;color:#7c3aed;margin-bottom:4px">Conditional — only shown when its rule matches</div>';
        if (OPTION_TYPES.indexOf(f.type) !== -1) {
          html += '<select disabled style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc">' +
            Object.keys(f.options||{}).map(function(v){ return '<option>'+f.options[v]+'</option>'; }).join('') + '</select>';
        } else if (f.type === 'textarea') {
          html += '<textarea disabled rows="2" placeholder="'+(f.placeholder||'')+'" style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc"></textarea>';
        } else if (f.type === 'file') {
          html += '<div style="padding:8px;border:1px dashed #cbd5e1;border-radius:6px;color:#94a3b8;font-size:12px;background:#f8fafc">📎 File upload</div>';
        } else {
          html += '<input disabled placeholder="'+(f.placeholder||'')+'" style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc">';
        }
        if (f.help_text) html += '<div style="font-size:11px;color:#94a3b8;margin-top:3px">' + f.help_text + '</div>';
        html += '</div>';
      });
    });
    if (state.documents.length) {
      html += '<p style="font-weight:700;font-size:13px;color:#475569;margin:16px 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px">📎 Documents Required</p>';
      state.documents.forEach(function(d){
        var dt = DOCTYPES.filter(function(x){ return x.id === parseInt(d.doc_type_id,10); })[0];
        html += '<div style="font-size:13px;padding:4px 0">• ' + (d.label || (dt?dt.name:'Document')) + (d.required?' (required)':' (optional)') + (d.visible_if?' <span style="color:#7c3aed;font-size:11px">— conditional</span>':'') + '</div>';
      });
    }
    body.innerHTML = html;
    document.getElementById('previewPanel').style.display = 'flex';
  });
  document.getElementById('closePreviewBtn').addEventListener('click', function(){ document.getElementById('previewPanel').style.display = 'none'; });

  // ── Version history ──────────────────────────────────────────────────
  var historyPanel = document.getElementById('historyPanel');
  var historyList = document.getElementById('historyList');
  var existingHistory = <?= wp_json_encode($history ?: []) ?>;
  function renderHistory(rows){
    if (!rows.length) { historyList.innerHTML = '<p style="color:#94a3b8;font-size:13px">No saved versions yet.</p>'; return; }
    historyList.innerHTML = rows.map(function(r){
      var active = r.is_active == 1 ? ' <span style="color:#166534;font-weight:700">(active)</span>' : '';
      return '<div class="rto-card" style="margin-bottom:8px"><div class="rto-card__body" style="padding:10px">' +
        '<div style="font-size:13px;font-weight:600">v' + r.version + active + '</div>' +
        '<div style="font-size:12px;color:#6b7280;margin:2px 0 8px">' + (r.updated_at || r.created_at || '') + '</div>' +
        (r.is_active == 1 ? '' : '<button type="button" class="rto-btn rto-btn--sm restore-btn" data-id="' + r.id + '">Restore as New Version</button> ') +
        (r.is_active == 1 ? '' : '<button type="button" class="rto-btn rto-btn--sm rto-btn--danger delete-version-btn" data-id="' + r.id + '">Delete</button>') +
        '</div></div>';
    }).join('');
    Array.prototype.forEach.call(historyList.querySelectorAll('.restore-btn'), function(btn){
      btn.addEventListener('click', function(){
        if (!confirm('Restore version ' + btn.getAttribute('data-id') + '? This creates a new active version — nothing is deleted.')) return;
        var fd = new FormData();
        fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.restore');
        fd.append('rto_nonce', nonce); fd.append('category', category); fd.append('schema_id', btn.getAttribute('data-id'));
        fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(r){ if (r.success) location.reload(); else alert(r.message || 'Restore failed.'); });
      });
    });
    // Delete ONE specific saved version — confirmation required, since this
    // permanently removes it from the history list (soft-deleted in the DB,
    // never hard-deleted, but not recoverable from this screen).
    Array.prototype.forEach.call(historyList.querySelectorAll('.delete-version-btn'), function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        if (!confirm('Delete version ' + id + '? This cannot be undone from this screen.')) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.delete_version');
        fd.append('rto_nonce', nonce); fd.append('category', category); fd.append('schema_id', id);
        fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(r){
            if (r.success) { location.reload(); }
            else { alert(r.message || 'Delete failed.'); btn.disabled = false; }
          });
      });
    });
  }
  renderHistory(existingHistory);
  document.getElementById('historyBtn').addEventListener('click', function(){ historyPanel.style.display = ''; });
  document.getElementById('closeHistoryBtn').addEventListener('click', function(){ historyPanel.style.display = 'none'; });

  // ── Import ───────────────────────────────────────────────────────────
  var importInput = document.getElementById('importFileInput');
  document.getElementById('importBtn').addEventListener('click', function(){ importInput.click(); });
  importInput.addEventListener('change', function(){
    if (!importInput.files.length) return;
    if (!confirm('Import this file as a new version of this form? You can review it before activating.')) { importInput.value=''; return; }
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','forms.import');
    fd.append('rto_nonce', nonce); fd.append('category', category);
    fd.append('import_file', importInput.files[0]);
    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){ if (r.success) location.reload(); else alert(r.message || 'Import failed.'); importInput.value=''; })
      .catch(function(){ alert('Network error — import failed.'); importInput.value=''; });
  });

  // ── Analytics ────────────────────────────────────────────────────────
  var analyticsPanel = document.getElementById('analyticsPanel');
  var analyticsBody = document.getElementById('analyticsBody');
  document.getElementById('analyticsBtn').addEventListener('click', function(){
    analyticsPanel.style.display = '';
    analyticsBody.textContent = 'Loading…';
    // BUGFIX (found via a systematic rto_action-vs-dispatch-table cross-reference,
    // the same technique that caught 3 missing DI bindings earlier this pass):
    // this used to send rto_action='admin.forms.analytics' while ALSO sending
    // rto_area=admin separately. Router::dispatchAjax() builds its match key as
    // `$area . '.' . $action`, so the real key sent to the server was
    // 'admin.admin.forms.analytics' -- doubling the 'admin.' prefix -- which
    // never matched the real 'admin.forms.analytics' entry in the dispatch
    // table and always fell through to the default 'Unknown action' 400
    // response. This is the exact "AJAX action key consistency" failure mode:
    // rto_action must be the action alone, with no area prefix baked in.
    fetch(ajaxUrl + '?action=rto_admin&rto_area=admin&rto_action=forms.analytics&category=' + encodeURIComponent(category), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (!r.success) { analyticsBody.textContent = r.message || 'Could not load analytics.'; return; }
        var d = r.data;
        var rows = (d.by_day_last_14d || []).map(function(row){
          return '<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;border-bottom:1px solid #f1f5f9">' +
            '<span>' + row.day + '</span><span>' + row.count + '</span></div>';
        }).join('') || '<p style="color:#94a3b8;font-size:12px">No submissions in the last 14 days.</p>';
        // Part 4.12: per-service breakdown — real value now that one form
        // covers many services, since a form that works for one service in
        // this category could still be silently broken/confusing for
        // another, and that used to be invisible in this panel.
        var maxCount = Math.max(1, Math.max.apply(null, (d.by_service || []).map(function(s){ return s.count; }).concat([0])));
        var serviceRows = (d.by_service || []).map(function(s){
          var pct = Math.round((s.count / maxCount) * 100);
          return '<div style="margin-bottom:6px">' +
            '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px"><span>' + s.service_name + '</span><span style="font-weight:600">' + s.count + '</span></div>' +
            '<div style="background:#f1f5f9;border-radius:4px;height:6px;overflow:hidden"><div style="background:#2563eb;height:100%;width:' + pct + '%"></div></div>' +
          '</div>';
        }).join('') || '<p style="color:#94a3b8;font-size:12px">No submissions yet for any service in this category.</p>';

        // FIX (checklist gap closed this pass — see FormFunnelService): the
        // backend now computes abandonment/drop-off/validation-failure
        // metrics from real client-side beacon events (apply.php); render
        // them here rather than leaving the backend work invisible to the
        // admin who actually needs it, matching the standing rule in this
        // codebase that a built-but-never-displayed metric is not a real fix.
        var hasFunnelData = (d.funnel_step_reached || 0) > 0;
        var dropOffRows = (d.drop_off_by_step || []).map(function(s){
          return '<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;border-bottom:1px solid #f1f5f9">' +
            '<span>Step ' + (s.step_index + 1) + '</span><span>' + s.sessions_reached + ' session(s) reached</span></div>';
        }).join('');
        var fieldFailureRows = (d.validation_failures_by_field || []).map(function(f){
          return '<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0;border-bottom:1px solid #f1f5f9">' +
            '<span>' + f.field_key + '</span><span>' + f.failure_count + ' failure(s)</span></div>';
        }).join('') || '<p style="color:#94a3b8;font-size:12px">No field validation failures recorded.</p>';
        var funnelHtml = hasFunnelData
          ? '<div style="font-weight:600;font-size:12px;margin:14px 0 6px">Funnel (from real visitor beacon events)</div>' +
            '<div style="display:flex;gap:16px;margin-bottom:8px">' +
              '<div><div style="font-size:18px;font-weight:700">' + d.funnel_step_reached + '</div><div style="font-size:11px;color:#6b7280">Sessions reached the form</div></div>' +
              '<div><div style="font-size:18px;font-weight:700">' + d.funnel_submitted + '</div><div style="font-size:11px;color:#6b7280">Sessions submitted</div></div>' +
              '<div><div style="font-size:18px;font-weight:700">' + (d.abandonment_rate_pct !== null ? d.abandonment_rate_pct + '%' : '—') + '</div><div style="font-size:11px;color:#6b7280">Abandonment rate</div></div>' +
            '</div>' +
            '<div style="font-weight:600;font-size:12px;margin:10px 0 4px">Drop-off by step</div>' + dropOffRows +
            '<div style="font-weight:600;font-size:12px;margin:10px 0 4px">Field validation failures</div>' + fieldFailureRows
          : '<div style="font-weight:600;font-size:12px;margin:14px 0 6px">Funnel</div><p style="color:#94a3b8;font-size:12px">No visitor beacon events recorded yet for this category — funnel metrics will populate as real visitors use the live form.</p>';

        analyticsBody.innerHTML =
          '<div style="display:flex;gap:16px;margin-bottom:14px">' +
            '<div><div style="font-size:22px;font-weight:700">' + d.total_submissions + '</div><div style="font-size:11px;color:#6b7280">All-time submissions</div></div>' +
            '<div><div style="font-size:22px;font-weight:700">' + d.submissions_last_30d + '</div><div style="font-size:11px;color:#6b7280">Last 30 days</div></div>' +
          '</div>' +
          '<div style="font-weight:600;font-size:12px;margin-bottom:6px">By service (which sub-service customers actually picked)</div>' + serviceRows +
          '<div style="font-weight:600;font-size:12px;margin:14px 0 6px">Last 14 days, by day</div>' + rows +
          funnelHtml +
          '<p style="margin-top:14px;font-size:11px;color:#94a3b8">' + (d.note || '') + '</p>';
      })
      .catch(function(){ analyticsBody.textContent = 'Network error loading analytics.'; });
  });
  document.getElementById('closeAnalyticsBtn').addEventListener('click', function(){ analyticsPanel.style.display = 'none'; });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
