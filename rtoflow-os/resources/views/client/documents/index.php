<?php if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }
global $wpdb; $p = $wpdb->prefix;
$uid = get_current_user_id();

// ENTERPRISE GAP FIX (critical missing functionality — clients had no way
// to upload a document anywhere in the UI: this page only ever listed and
// downloaded documents. The server-side POST handler for uploads already
// existed in Router::routeClient()'s 'documents' arm but discarded its
// result and had no form pointing at it anywhere — 100% dead code with no
// user feedback either way. Root cause fixed in Router.php (captures
// $uploadError/$uploadSuccess and passes them here) plus the upload form
// added below, wired with the matching nonce action/field
// ('rto_client_nonce' / 'rto_nonce') the router checks.
$uploadError   = $uploadError   ?? null;
$uploadSuccess = $uploadSuccess ?? null;

// $docs / $myLeads / $docTypes are normally passed in by Router::routeClient()'s
// 'documents' arm; these fallbacks only apply if this view is ever rendered
// through a different code path.
$docs     = $docs     ?? ($wpdb->get_results($wpdb->prepare(
    "SELECT d.*, l.lead_number FROM {$p}rto_documents d
     JOIN {$p}rto_leads l ON l.id=d.lead_id
     WHERE l.client_id=%d AND l.deleted_at IS NULL ORDER BY d.created_at DESC", $uid
), ARRAY_A) ?: []);
$myLeads  = $myLeads  ?? ($wpdb->get_results($wpdb->prepare(
    "SELECT id, lead_number FROM {$p}rto_leads WHERE client_id=%d AND deleted_at IS NULL ORDER BY created_at DESC", $uid
), ARRAY_A) ?: []);
$docTypes = $docTypes ?? ($wpdb->get_results(
    "SELECT id, name, category FROM {$p}rto_doc_types WHERE is_active=1 ORDER BY category, name", ARRAY_A
) ?: []);
require RTOFLOW_DIR . 'resources/views/layouts/client-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">My Documents</h1>
    <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="rto-back-link">← Dashboard</a>
  </div>

  <?php if ($uploadError): ?>
  <div class="rto-alert rto-alert-danger" style="margin-bottom:16px"><?= esc_html($uploadError) ?></div>
  <?php endif; ?>
  <?php if ($uploadSuccess): ?>
  <div class="rto-alert rto-alert-success" style="margin-bottom:16px"><?= esc_html($uploadSuccess) ?></div>
  <?php endif; ?>

  <?php if (!empty($myLeads)): ?>
  <div class="rto-card" style="margin-bottom:20px" id="uploadDocCard">
    <h3 class="rto-card-title">Upload a Document</h3>
    <form method="post" enctype="multipart/form-data" id="uploadDocForm">
      <?php wp_nonce_field('rto_client_nonce', 'rto_nonce'); ?>
      <input type="hidden" name="rtoflow_doc_upload" value="1">
      <div class="rto-form-row">
        <div class="rto-form-group">
          <label class="rto-label" for="doc_lead_id">Order</label>
          <select name="lead_id" id="doc_lead_id" class="rto-input" required>
            <option value="">Select order…</option>
            <?php foreach ($myLeads as $l): ?>
            <option value="<?= (int)$l['id'] ?>"><?= esc_html($l['lead_number']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="doc_type_id">Document Type</label>
          <select name="doc_type_id" id="doc_type_id" class="rto-input" required>
            <option value="">Select type…</option>
            <?php foreach ($docTypes as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= esc_html($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="document">File (PDF/JPG/PNG)</label>
          <input type="file" name="document" id="document" class="rto-input" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
      </div>
      <button type="submit" class="rto-btn rto-btn-primary">Upload Document</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (empty($docs)): ?>
  <div class="rto-card">
    <div class="rto-empty-state"><p>No documents yet. Documents will appear here as your orders progress.</p></div>
  </div>
  <?php else: ?>
  <div class="rto-card">
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th>Order</th><th>Document</th><th>Uploaded By</th><th>Status</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($docs as $doc): ?>
        <tr>
          <td data-label="Order" class="rto-small"><?= esc_html($doc['lead_number']) ?></td>
          <td data-label="Document">
            <strong><?= esc_html($doc['doc_type'] ?: $doc['original_name'] ?: 'Document') ?></strong>
            <?php if ($doc['doc_type'] && $doc['original_name']): ?>
            <div class="rto-small rto-muted"><?= esc_html($doc['original_name']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Uploaded By" class="rto-small"><?= esc_html(ucfirst($doc['uploaded_by_role']??'—')) ?></td>
          <td data-label="Status">
            <?php $sc = ['pending'=>'secondary','verified'=>'success','rejected'=>'danger']; ?>
            <span class="rto-badge rto-badge-<?= esc_attr($sc[$doc['status']]??'secondary') ?>">
              <?= esc_html(ucfirst($doc['status'])) ?>
            </span>
            <?php if ($doc['status']==='rejected' && $doc['reject_reason']): ?>
            <div class="rto-small rto-danger" style="margin-top:3px"><?= esc_html($doc['reject_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Date" class="rto-small rto-muted"><?= esc_html(rto_date($doc['created_at'])) ?></td>
          <td data-label="Actions">
            <?php
            // FIX (integration pass follow-up — closes the previously-
            // documented "Document proxy is live but nothing links to it"
            // gap): this rendered wp_get_attachment_url() directly, which is
            // an unauthenticated WordPress media URL — anyone with the link
            // (guessable/sequential, or shared) could fetch the file, and it
            // bypassed DocumentAccessController::serve()'s login/ownership
            // checks and audit logging entirely. Now points at the proxy
            // route (/rto-documents/{id}/, see Router.php's 'document' arm)
            // which re-verifies the current user actually owns this document
            // before streaming it.
            ?>
            <a href="<?= esc_url(home_url('/rto-documents/' . (int)$doc['id'] . '/')) ?>" class="rto-btn rto-btn-xs rto-btn-outline" target="_blank" aria-label="Download <?= esc_attr($doc['doc_type']?:'document') ?>">⬇ Download</a>
            <?php if ($doc['status']==='rejected'): ?>
            <?php
            // ENTERPRISE GAP FIX (Phase 6, item — "document rejection does
            // not link to a targeted re-upload"): pre-selects the SAME
            // order + document type in the upload form above, instead of
            // making the client guess which type to pick again from a
            // generic list. This deliberately does not "replace in place"
            // server-side (DocumentService::upload() always inserts a new
            // row) — the rejected row stays visible with its reason, and
            // the new upload appears above it once submitted, both
            // consistent with how every other document row already behaves.
            ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-primary reupload-btn"
                    data-lead-id="<?= (int)$doc['lead_id'] ?>" data-doc-type-id="<?= (int)$doc['doc_type_id'] ?>"
                    aria-label="Re-upload <?= esc_attr($doc['doc_type']?:'document') ?>">Re-upload</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
// ENTERPRISE GAP FIX (Phase 6, item — targeted re-upload)
document.querySelectorAll('.reupload-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var leadSel = document.getElementById('doc_lead_id');
    var typeSel = document.getElementById('doc_type_id');
    if (leadSel) leadSel.value = btn.dataset.leadId;
    if (typeSel) typeSel.value = btn.dataset.docTypeId;
    var card = document.getElementById('uploadDocCard');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/client-footer.php'; ?>
