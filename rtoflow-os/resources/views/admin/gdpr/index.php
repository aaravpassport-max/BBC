<?php
if (!defined('ABSPATH')) exit;
$pageTitle = 'GDPR Compliance';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-header">
  <h2>GDPR Compliance</h2>
</div>

<div class="rto-card" style="margin-bottom:16px">
  <div class="rto-card__body">
    <h3 style="margin-top:0">Data Export (Article 15)</h3>
    <p style="color:#64748b;font-size:13px">Find a user below and open their record to export their personal data as a JSON file. Every export is recorded in the audit log.</p>
  </div>
</div>

<div class="rto-card" style="margin-bottom:16px;border:1.5px solid #FCA5A5">
  <div class="rto-card__body">
    <h3 style="margin-top:0;color:#B91C1C">Erasure / Right to be Forgotten (Article 17)</h3>
    <p style="color:#64748b;font-size:13px">
      Permanently erases or anonymises every record for a data subject, identified by email or mobile number.
      Rows with no financial/legal retention requirement (abandoned leads, chat messages) are <strong>hard deleted</strong>.
      Rows tied to a financial/legal record (paid leads, payments ledger, resolved complaints, vendor payout history)
      are <strong>anonymised</strong> — identifying fields are scrubbed but amounts, dates and ledger integrity are preserved.
      This action is logged and cannot be undone.
    </p>
    <form id="gdpr-erase-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <label for="gdpr-id-type" class="rto-visually-hidden">Identifier type</label>
      <select id="gdpr-id-type" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px">
        <option value="email">Email</option>
        <option value="mobile">Mobile</option>
      </select>
      <label for="gdpr-id-value" class="rto-visually-hidden">Identifier value (email or mobile)</label>
      <input type="text" id="gdpr-id-value" placeholder="e.g. jane@example.com" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;min-width:240px">
      <button type="submit" class="rto-btn rto-btn--sm" style="background:#DC2626;color:#fff">Erase Subject</button>
    </form>
    <div id="gdpr-erase-result" style="margin-top:16px"></div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('gdpr-erase-form').addEventListener('submit', function(e){
  e.preventDefault();
  var type  = document.getElementById('gdpr-id-type').value;
  var value = document.getElementById('gdpr-id-value').value.trim();
  var resultBox = document.getElementById('gdpr-erase-result');
  if (!value) { alert('Enter an email or mobile number.'); return; }
  if (!confirm(
    'This will permanently erase or anonymise ALL records for "' + value + '".\n' +
    'This action cannot be undone. Continue?'
  )) return;

  var fd = new FormData();
  fd.append('action', 'rto_admin');
  fd.append('rto_area', 'admin');
  fd.append('rto_action', 'gdpr_erase');
  fd.append('rto_nonce', '<?= $nonce ?>');
  fd.append('identifier_type', type);
  fd.append('identifier_value', value);
  fd.append('confirm', '1');

  resultBox.innerHTML = '<p style="color:#64748b">Processing…</p>';

  fetch('<?= esc_js(admin_url('admin-ajax.php')) ?>', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        resultBox.innerHTML = '<p style="color:#DC2626;font-weight:600">Failed: ' + (res.message || 'Unknown error') + '</p>';
        return;
      }
      var d = res.data;
      var rows = '';
      function section(title, obj, color) {
        var out = '';
        for (var table in obj) {
          var v = obj[table];
          if (typeof v === 'object' && v !== null) {
            out += '<tr><td data-label="Table" style="font-weight:600">' + table + '</td><td data-label="Rows">' + v.count + '</td><td data-label="Detail" style="color:#64748b;font-size:12px">' + v.reason + '</td></tr>';
          } else {
            out += '<tr><td data-label="Table" style="font-weight:600">' + table + '</td><td data-label="Rows">—</td><td data-label="Detail" style="color:#64748b;font-size:12px">' + v + '</td></tr>';
          }
        }
        if (!out) return '';
        return '<h4 style="color:' + color + ';margin-bottom:4px">' + title + '</h4><table class="rto-table" data-rto-responsive="cards" style="margin-bottom:12px"><thead><tr><th>Table</th><th>Rows</th><th>Detail</th></tr></thead><tbody>' + out + '</tbody></table>';
      }
      rows += section('Hard Deleted', d.hard_deleted, '#DC2626');
      rows += section('Anonymised', d.anonymized, '#B45309');
      rows += section('Skipped / Not Applicable', d.skipped, '#64748b');
      resultBox.innerHTML =
        '<p style="color:#059669;font-weight:600">Erasure completed for identifier ' + d.identifier.value + (d.matched_user_id ? ' (WP user #' + d.matched_user_id + ')' : '') + '.</p>' + rows;
    })
    .catch(err => {
      resultBox.innerHTML = '<p style="color:#DC2626;font-weight:600">Request failed: ' + err + '</p>';
    });
});
</script>
<?php rto_help_box('gdpr'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
