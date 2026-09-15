<?php
if (!defined('ABSPATH')) exit;
/**
 * Renders ONE field of a Dynamic Form Engine schema (see FormEngineService).
 *
 * Expects:
 *   @var array $field   one field definition from FormEngineService's
 *                       'fields' array — keys: key, label, type, required,
 *                       options, help_text, order, visible_if.
 *   @var mixed $value   (optional) previously submitted value for this
 *                       field, e.g. when redisplaying the form after a
 *                       validation error.
 *
 * Usage from a future apply-form integration:
 *   foreach ($schema['fields'] as $field) {
 *       rto_view('public.partials.dynamic-form-field', ['field' => $field, 'value' => $old[$field['key']] ?? null]);
 *   }
 *
 * Conditional visibility ("show if another field equals X") is applied with
 * a small vanilla-JS snippet keyed by data-visible-if-* attributes rather
 * than a framework, matching the rest of this codebase's public-facing JS.
 * Each field wraps itself in a container carrying that data so the snippet
 * can be dropped in once per page (see bottom of this file) and it wires up
 * every dynamic field on the page, however many are printed.
 */

$field = $field ?? [];
$key       = $field['key'] ?? '';
$label     = $field['label'] ?? '';
$type      = $field['type'] ?? 'text';
$required  = !empty($field['required']);
$options   = $field['options'] ?? [];
$helpText  = $field['help_text'] ?? '';
$visibleIf = $field['visible_if'] ?? null;
$value     = $value ?? null;

if ($key === '') return;

$inputName = 'rto_answers[' . $key . ']';
$inputId   = 'rto-field-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $key);

$wrapAttrs = 'class="rto-dynamic-field rto-dynamic-field--' . esc_attr($type) . '" data-field-key="' . esc_attr($key) . '"';
if ($visibleIf && !empty($visibleIf['field'])) {
    $wrapAttrs .= ' data-visible-if-field="' . esc_attr($visibleIf['field']) . '"'
                . ' data-visible-if-equals="' . esc_attr((string)($visibleIf['equals'] ?? '')) . '"'
                . ' style="display:none"'; // hidden until the JS below evaluates it
}
?>
<div <?= $wrapAttrs ?>>
  <label class="rto-label" for="<?= esc_attr($inputId) ?>">
    <?= esc_html($label) ?><?php if ($required): ?><span style="color:#DC2626"> *</span><?php endif; ?>
  </label>

  <?php if ($type === 'text' || $type === 'number' || $type === 'date'): ?>
    <input
      type="<?= esc_attr($type) ?>"
      id="<?= esc_attr($inputId) ?>"
      name="<?= esc_attr($inputName) ?>"
      class="rto-input"
      value="<?= esc_attr((string)($value ?? '')) ?>"
      <?= $required ? 'required' : '' ?>
      <?php if ($type === 'number'): ?>data-dynamic-input<?php endif; ?>
    >

  <?php elseif ($type === 'select'): ?>
    <select id="<?= esc_attr($inputId) ?>" name="<?= esc_attr($inputName) ?>" class="rto-input" <?= $required ? 'required' : '' ?> data-dynamic-input>
      <option value="">Select…</option>
      <?php foreach ($options as $optValue => $optLabel): ?>
        <option value="<?= esc_attr($optValue) ?>" <?= (string)$value === (string)$optValue ? 'selected' : '' ?>><?= esc_html($optLabel) ?></option>
      <?php endforeach; ?>
    </select>

  <?php elseif ($type === 'radio'): ?>
    <div class="rto-radio-group">
      <?php foreach ($options as $optValue => $optLabel): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;font-weight:400">
          <input
            type="radio"
            name="<?= esc_attr($inputName) ?>"
            value="<?= esc_attr($optValue) ?>"
            <?= (string)$value === (string)$optValue ? 'checked' : '' ?>
            <?= $required ? 'required' : '' ?>
            data-dynamic-input
          >
          <?= esc_html($optLabel) ?>
        </label>
      <?php endforeach; ?>
    </div>

  <?php elseif ($type === 'checkbox'): ?>
    <label style="display:flex;align-items:center;gap:8px;font-weight:400">
      <input
        type="checkbox"
        id="<?= esc_attr($inputId) ?>"
        name="<?= esc_attr($inputName) ?>"
        value="1"
        <?= !empty($value) ? 'checked' : '' ?>
        data-dynamic-input
      >
      <span><?= esc_html($helpText ?: 'Yes') ?></span>
    </label>

  <?php elseif ($type === 'file'): ?>
    <input
      type="file"
      id="<?= esc_attr($inputId) ?>"
      name="<?= esc_attr($inputName) ?>"
      class="rto-input"
      <?= $required ? 'required' : '' ?>
    >
  <?php endif; ?>

  <?php if ($helpText && $type !== 'checkbox'): ?>
    <p style="font-size:11px;color:#6b7280;margin:4px 0 0"><?= esc_html($helpText) ?></p>
  <?php endif; ?>
</div>

<?php if (!defined('RTO_DYNAMIC_FIELD_JS_PRINTED')): define('RTO_DYNAMIC_FIELD_JS_PRINTED', true); ?>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  // Wires up every .rto-dynamic-field on the page that declares
  // data-visible-if-field/-equals: hides it until the referenced field's
  // current value matches, and toggles required/disabled so hidden fields
  // never block submission or get posted with stale values.
  function evaluate(){
    document.querySelectorAll('.rto-dynamic-field[data-visible-if-field]').forEach(function(wrap){
      var depKey = wrap.getAttribute('data-visible-if-field');
      var expected = wrap.getAttribute('data-visible-if-equals');
      var depWrap = document.querySelector('.rto-dynamic-field[data-field-key="' + CSS.escape(depKey) + '"]');
      var current = '';
      if (depWrap) {
        var checked = depWrap.querySelector('[data-dynamic-input]:checked');
        var single = depWrap.querySelector('[data-dynamic-input]');
        if (checked) current = checked.value;
        else if (single) current = single.type === 'checkbox' ? (single.checked ? '1' : '') : single.value;
      }
      var show = String(current) === String(expected);
      wrap.style.display = show ? '' : 'none';
      wrap.querySelectorAll('[data-dynamic-input]').forEach(function(input){
        input.disabled = !show;
        if (!show) input.removeAttribute('required');
      });
    });
  }
  document.addEventListener('input', function(e){ if (e.target.matches('[data-dynamic-input]')) evaluate(); });
  document.addEventListener('change', function(e){ if (e.target.matches('[data-dynamic-input]')) evaluate(); });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', evaluate);
  } else {
    evaluate();
  }
})();
</script>
<?php endif; ?>
