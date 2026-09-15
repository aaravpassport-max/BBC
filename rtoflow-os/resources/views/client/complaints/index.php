<?php if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }
global $wpdb; $p = $wpdb->prefix;
$uid = get_current_user_id();
// Use complainant_id (correct column) OR client_id (added in migration)
$col = $wpdb->get_var("SHOW COLUMNS FROM {$p}rto_complaints LIKE 'client_id'") ? 'c.client_id' : 'c.complainant_id';
// $complaints / $myLeads / $complaintError / $complaintSuccess are normally
// passed in by Router::routeClient()'s 'complaints' arm.
$complaints = $complaints ?? ($wpdb->get_results($wpdb->prepare(
    "SELECT c.*, l.lead_number FROM {$p}rto_complaints c
     LEFT JOIN {$p}rto_leads l ON l.id=c.lead_id
     WHERE {$col}=%d ORDER BY c.created_at DESC", $uid
), ARRAY_A) ?: []);
$myLeads = $myLeads ?? ($wpdb->get_results($wpdb->prepare(
    "SELECT id, lead_number FROM {$p}rto_leads WHERE client_id=%d AND deleted_at IS NULL ORDER BY created_at DESC", $uid
), ARRAY_A) ?: []);
$complaintError   = $complaintError   ?? null;
$complaintSuccess = $complaintSuccess ?? null;
$statusColors = ['open'=>'danger','under_review'=>'warning','resolved'=>'success','closed'=>'secondary','rejected'=>'secondary'];
require RTOFLOW_DIR . 'resources/views/layouts/client-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">My Complaints</h1>
    <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="rto-back-link">← Dashboard</a>
  </div>

  <?php if ($complaintError): ?>
  <div class="rto-alert rto-alert-danger" style="margin-bottom:16px"><?= esc_html($complaintError) ?></div>
  <?php endif; ?>
  <?php if ($complaintSuccess): ?>
  <div class="rto-alert rto-alert-success" style="margin-bottom:16px"><?= esc_html($complaintSuccess) ?></div>
  <?php endif; ?>

  <div class="rto-card" style="margin-bottom:20px">
    <h3 class="rto-card-title">File a Complaint</h3>
    <form method="post">
      <?php wp_nonce_field('rto_client_nonce', 'rto_nonce'); ?>
      <input type="hidden" name="rtoflow_file_complaint" value="1">
      <div class="rto-form-row">
        <div class="rto-form-group">
          <label class="rto-label" for="cplt_lead_id">Related Order (optional)</label>
          <select name="lead_id" id="cplt_lead_id" class="rto-input">
            <option value="">Not related to a specific order</option>
            <?php foreach ($myLeads as $l): ?>
            <option value="<?= (int)$l['id'] ?>"><?= esc_html($l['lead_number']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="cplt_type">Category</label>
          <select name="complaint_type" id="cplt_type" class="rto-input" required>
            <option value="general">General</option>
            <option value="service">Service Quality</option>
            <option value="payment">Payment</option>
            <option value="refund">Refund</option>
            <option value="misconduct">Vendor Misconduct</option>
            <option value="fraud">Fraud</option>
          </select>
        </div>
      </div>
      <div class="rto-form-group rto-mb-4">
        <label class="rto-label" for="cplt_subject">Subject</label>
        <input type="text" name="subject" id="cplt_subject" class="rto-input" maxlength="200" required>
      </div>
      <div class="rto-form-group rto-mb-4">
        <label class="rto-label" for="cplt_desc">Description</label>
        <textarea name="description" id="cplt_desc" class="rto-input" rows="4" maxlength="2000" required></textarea>
      </div>
      <button type="submit" class="rto-btn rto-btn-primary">Submit Complaint</button>
    </form>
  </div>

  <?php if (empty($complaints)): ?>
  <div class="rto-card">
    <div class="rto-empty-state">
      <p>You have not filed any complaints yet.</p>
    </div>
  </div>
  <?php else: ?>
  <div class="rto-card">
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr><th>#</th><th>Order</th><th>Subject</th><th>Status</th><th>Response</th><th>Filed</th></tr></thead>
        <tbody>
        <?php foreach ($complaints as $c): ?>
        <tr>
          <td data-label="#" class="rto-small"><strong><?= esc_html($c['complaint_number']) ?></strong></td>
          <td data-label="Order" class="rto-small"><?= $c['lead_number']?esc_html($c['lead_number']):'—' ?></td>
          <td data-label="Subject"><?= esc_html(mb_strimwidth($c['subject'],0,60,'…')) ?></td>
          <td data-label="Status"><span class="rto-badge rto-badge-<?= esc_attr($statusColors[$c['status']]??'secondary') ?>"><?= esc_html(ucwords(str_replace('_',' ',$c['status']))) ?></span></td>
          <td data-label="Response" class="rto-small">
            <?php if ($c['admin_response']): ?>
            <span style="color:var(--green)">✓ Responded</span>
            <?php else: ?>
            <span class="rto-muted">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Filed" class="rto-small rto-muted"><?= esc_html(rto_date($c['created_at'])) ?></td>
        </tr>
        <?php if ($c['admin_response']): ?>
        <tr style="background:var(--gray-50)">
          <td colspan="6" style="padding:8px 16px">
            <div class="rto-small" style="color:var(--green);font-weight:600;margin-bottom:4px">Response from us:</div>
            <div class="rto-small"><?= esc_html($c['admin_response']) ?></div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/client-footer.php'; ?>
