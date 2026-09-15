<?php if (!defined('ABSPATH')) exit;
/** @var array $template @var array $vars @var string|null $templateError */
$templateError = $templateError ?? null;
$pageTitle = 'Edit Template';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap" style="max-width:720px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Edit Template: <?= esc_html($template['slug']) ?></h1>
    <a href="<?= esc_url(home_url('/rto-admin/email-templates/')) ?>" class="rto-back-link">← All Templates</a>
  </div>

  <?php if ($templateError): ?>
  <div class="rto-msg rto-msg-error rto-mb-4"><?= esc_html($templateError) ?></div>
  <?php endif; ?>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-body">
      <form method="POST" action="<?= esc_url(home_url('/rto-admin/email-templates/'.(int)$template['id'].'/')) ?>">
        <?php wp_nonce_field('rtoflow_template_save','rtoflow_nonce'); ?>
        <input type="hidden" name="rto_area" value="admin">
        <input type="hidden" name="rto_page" value="email-templates">

        <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;margin-bottom:16px">
          <div class="rto-form-group">
            <label class="rto-label">Channel</label>
            <div><span class="rto-badge rto-badge-info"><?= esc_html(ucfirst($template['channel'])) ?></span></div>
          </div>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:24px">
            <input type="checkbox" name="is_active" value="1" <?= $template['is_active']?'checked':'' ?>>
            <strong>Active</strong>
          </label>
        </div>

        <?php if ($template['channel']==='email'): ?>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="tplSubject">Subject Line</label>
          <input type="text" id="tplSubject" name="subject" class="rto-input" value="<?= esc_attr($template['subject']) ?>">
        </div>
        <?php endif; ?>

        <?php if ($template['channel']==='sms'): ?>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="tplDlt">DLT Template ID (TRAI registration)</label>
          <input type="text" id="tplDlt" name="dlt_template_id" class="rto-input" value="<?= esc_attr($template['dlt_template_id'] ?? '') ?>" placeholder="Registered DLT template ID">
        </div>
        <?php endif; ?>

        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="tplBody">
            Message Body
            <span class="rto-small rto-muted" style="font-weight:400">
              <?php if ($template['channel']==='sms'): ?>
              — Keep under 160 chars for 1 SMS unit (currently <span id="charCount">0</span>)
              <?php endif; ?>
            </span>
          </label>
          <textarea id="tplBody" name="body" class="rto-input" rows="<?= $template['channel']==='email'?10:4 ?>"><?= esc_textarea($template['body']) ?></textarea>
        </div>

        <!-- Available variables -->
        <?php if (!empty($vars)): ?>
        <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:6px;padding:12px;margin-bottom:20px">
          <div class="rto-small rto-muted" style="margin-bottom:8px;font-weight:600">Available variables (click to insert):</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($vars as $var): ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline var-insert"
                    data-var="<?= esc_attr($var) ?>"><?= esc_html($var) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px">
          <button type="submit" class="rto-btn rto-btn-primary">Save Template</button>
          <a href="<?= esc_url(home_url('/rto-admin/email-templates/')) ?>" class="rto-btn rto-btn-outline">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
// Variable insert
document.querySelectorAll('.var-insert').forEach(function(btn){
  btn.addEventListener('click',function(){
    var ta=document.getElementById('tplBody');
    var s=ta.selectionStart,e=ta.selectionEnd;
    ta.value=ta.value.substring(0,s)+btn.dataset.var+ta.value.substring(e);
    ta.selectionStart=ta.selectionEnd=s+btn.dataset.var.length;ta.focus();
  });
});
// SMS char counter
var tplBody=document.getElementById('tplBody');
var cc=document.getElementById('charCount');
if(tplBody&&cc){
  function updateCount(){cc.textContent=tplBody.value.length;}
  tplBody.addEventListener('input',updateCount);updateCount();
}
</script>
<?php
// ENTERPRISE GAP FIX (Phase 4, item 8 — versioning/rollback for Email
// Templates): keyed per-template id — see EmailTemplateController::
// applyVersionedPayload().
$configVersionKey = 'email_template_' . (int)$template['id'];
require RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
