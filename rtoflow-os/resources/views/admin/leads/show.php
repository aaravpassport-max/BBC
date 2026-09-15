<?php
/**
 * Admin Lead Detail View
 * Bug fix: $vendors is ['matched'=>[], 'other'=>[]] — iterate both groups
 */
$pageTitle = 'Order: ' . ($lead['lead_number'] ?? '');
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';

// Flatten vendors for vendor name display lookup
$vendorLookup = [];
foreach (array_merge($vendors['matched'] ?? [], $vendors['other'] ?? []) as $vv) {
    $vendorLookup[(int)$vv['id']] = $vv;
}
$assignedVendor = $lead['vendor_id'] ? ($vendorLookup[(int)$lead['vendor_id']] ?? null) : null;
?>

<div class="rto-lead-header">
  <div class="rto-lead-header-left">
    <a href="<?= esc_url(home_url('/rto-admin/leads/')) ?>" class="rto-back-link">← All Orders</a>
    <h2><?= esc_html($lead['lead_number']) ?></h2>
    <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?> rto-badge-lg">
      <?= esc_html(rto_status_label($lead['status'])) ?>
    </span>
    <?php if (!empty($lead['sla_breached'])): ?>
      <span class="rto-badge rto-badge-danger">SLA BREACHED</span>
    <?php endif; ?>
    <?php // ENTERPRISE GAP FIX (Phase 7, item 3 — "side effects incomplete"
          // flag): surfaces the failure LeadService/AutomationService now
          // record when a workflow transition's side effect(s) threw. ?>
    <?php if (!empty($lead['side_effects_incomplete'])): ?>
      <span class="rto-badge rto-badge-warning" title="<?= esc_attr($lead['side_effects_failure_note'] ?? '') ?>">SIDE EFFECTS INCOMPLETE</span>
      <button class="rto-btn rto-btn-xs rto-btn-outline" id="ackSideEffectsBtn" type="button">Mark Followed Up</button>
    <?php endif; ?>
  </div>
  <div class="rto-lead-header-right rto-order-actions">
    <?php
    // FIX (Order Details 360° audit): only offer statuses
    // LeadService::validTransitionsFrom() — the SAME rule set
    // updateStatus() enforces — will actually accept, plus the current
    // status itself (so the select shows where the order already is).
    // Previously this was a hardcoded list of every status in existence,
    // so an admin could "select" an update the backend would then reject.
    $validTransitions ??= [];
    $isArchived = !empty($lead['deleted_at']);
    ?>
    <?php if (!$isArchived): ?>
      <?php if (empty($validTransitions)): ?>
      <span class="rto-badge rto-badge-secondary">Terminal status — no further transitions</span>
      <?php else: ?>
      <select id="statusSelect" class="rto-select" aria-label="Change status">
        <option value="<?= esc_attr($lead['status'] ?? '') ?>" selected>
          <?= esc_html(rto_status_label($lead['status'] ?? '')) ?> (current)
        </option>
        <?php foreach ($validTransitions as $s): ?>
        <option value="<?= esc_attr($s) ?>"><?= esc_html(rto_status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="rto-btn rto-btn-primary" id="updateStatusBtn"
              data-lead="<?= (int)$lead['id'] ?>"
              data-lock="<?= (int)($lead['lock_version'] ?? 1) ?>">
        Update Status
      </button>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Part 5.7 build: deeper lead-management actions. Export/Print are
         plain links (real GET endpoints, no JS round-trip needed). Share is
         a "copy internal link" action, not a new public-access mechanism —
         see report for the security reasoning (this URL is already gated
         by rto_is_staff(), so copying it grants nothing an unauthenticated
         viewer didn't already lack; a truly public share link would be a
         real regression given this codebase's history of exactly that
         class of bug, so it was deliberately not built). -->
    <a class="rto-btn rto-btn-sm" href="<?= esc_url(home_url('/rto-admin/leads/' . (int)$lead['id'] . '/?export=csv')) ?>">Export CSV</a>
    <a class="rto-btn rto-btn-sm" href="<?= esc_url(home_url('/rto-admin/leads/' . (int)$lead['id'] . '/?export=pdf')) ?>">Export PDF</a>
    <button type="button" class="rto-btn rto-btn-sm" id="printBtn">Print</button>
    <button type="button" class="rto-btn rto-btn-sm" id="copyLinkBtn">Copy Link</button>
    <?php if (rto_is_admin() && !$isArchived): ?>
    <button type="button" class="rto-btn rto-btn-sm" id="editLeadBtn">Edit</button>
    <button type="button" class="rto-btn rto-btn-sm rto-btn-danger" id="archiveLeadBtn">Archive</button>
    <?php elseif (rto_is_admin() && $isArchived): ?>
    <button type="button" class="rto-btn rto-btn-sm rto-btn-success" id="restoreLeadBtn">Restore</button>
    <?php endif; ?>
    <?php if (rto_is_admin() && in_array($lead['status'], ['completed', 'cancelled'], true) && !$isArchived): ?>
    <!-- Known Limitations audit fix: "Terminal statuses cannot be reversed
         from the UI ... requires a direct database correction." Admin-only,
         requires a mandatory reason (enforced in LeadService::reopenLead()),
         restores the status the order held immediately before it became
         terminal, using its own real audit trail — not a guess. -->
    <button type="button" class="rto-btn rto-btn-sm" id="reopenLeadBtn">Reopen</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($isArchived): ?>
<div class="rto-archived-banner">
  <span>This order is <strong>archived</strong> (soft-deleted) — hidden from the default Leads list, read-only, and no longer transitions status. It can be restored at any time.</span>
</div>
<?php endif; ?>

<div class="rto-lead-grid">
  <!-- ── Left column ───────────────────────────────────────────────────── -->
  <div class="rto-lead-left">

    <!-- Order Details -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Order Details</h3></div>
      <div class="rto-card-body">
        <div class="rto-detail-grid">
          <div class="rto-detail-item"><span>Service</span><strong><?= esc_html($lead['service_name'] ?? '—') ?></strong></div>
          <div class="rto-detail-item"><span>City</span><strong><?= esc_html($lead['city_name'] ?? '—') ?></strong></div>
          <div class="rto-detail-item"><span>Client</span><strong><?= esc_html($lead['client_name'] ?? '—') ?></strong></div>
          <div class="rto-detail-item"><span>Client Email</span><strong><?= esc_html($lead['client_email'] ?? '—') ?></strong></div>
          <div class="rto-detail-item"><span>Client Mobile</span><strong><?= esc_html($lead['client_mobile'] ?: '—') ?></strong></div>
          <div class="rto-detail-item"><span>Service Category</span><strong><?= esc_html($lead['service_category'] ?? '—') ?></strong></div>
          <div class="rto-detail-item"><span>Source</span><strong><?= esc_html(ucfirst($lead['source'] ?? '')) ?></strong></div>
          <div class="rto-detail-item"><span>Total Amount</span><strong><?= esc_html(rto_format_inr((float)($lead['total_amount'] ?? 0))) ?></strong></div>
          <div class="rto-detail-item"><span>GST Amount</span><strong><?= esc_html(rto_format_inr((float)($lead['gst_amount'] ?? 0))) ?></strong></div>
          <div class="rto-detail-item">
            <span>Paid Amount</span>
            <strong class="<?= (float)($lead['paid_amount'] ?? 0) >= (float)($lead['total_amount'] ?? 0) ? 'rto-success' : 'rto-warning' ?>">
              <?= esc_html(rto_format_inr((float)($lead['paid_amount'] ?? 0))) ?>
            </strong>
          </div>
          <div class="rto-detail-item"><span>Payment Status</span>
            <span class="rto-badge rto-badge-<?= ($lead['payment_status'] ?? '') === 'paid' ? 'success' : (($lead['payment_status'] ?? '') === 'partial' ? 'warning' : 'secondary') ?>">
              <?= esc_html(ucfirst($lead['payment_status'] ?? 'unpaid')) ?>
            </span>
          </div>
          <div class="rto-detail-item">
            <span>SLA Deadline</span>
            <strong class="<?= !empty($lead['sla_breached']) ? 'rto-danger' : '' ?>">
              <?= esc_html(rto_date($lead['sla_deadline'] ?? '', 'd M Y H:i')) ?>
            </strong>
          </div>
          <div class="rto-detail-item"><span>Created</span><strong><?= esc_html(rto_date($lead['created_at'] ?? '', 'd M Y H:i')) ?></strong></div>
          <?php if ($assignedVendor): ?>
          <div class="rto-detail-item"><span>Assigned Vendor</span>
            <strong><?= esc_html($assignedVendor['full_name']) ?> (<?= esc_html($assignedVendor['vendor_number']) ?>)</strong>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Full Submitted Form Answers — P360-1: the intake Form Engine
         already captures every custom field/option/conditional answer
         the client submitted (rto_leads.form_data + rto_form_submissions),
         it just was never rendered on this screen. Rendered dynamically
         from the real active schema — no hardcoded field names. -->
    <?php
    // Part 5.7 declutter: by default only answers with a real, non-empty
    // value are shown ($formAnswers, built in LeadsController::show()) —
    // grouped by form step into collapsible sections instead of one long
    // flat list. "Show all fields" reveals $formAnswersAll (every field the
    // schema defines, answered or not) without ever discarding data — it's
    // a display toggle, not a second query.
    $formAnswersAll  ??= $formAnswers ?? [];
    $hiddenBlankCount ??= 0;
    if (!function_exists('rto_group_answers_by_step')) {
        function rto_group_answers_by_step(array $answers): array {
            $groups = [];
            foreach ($answers as $ans) {
                $key = $ans['step'] !== '' ? $ans['step'] : 'Other';
                $groups[$key][] = $ans;
            }
            return $groups;
        }
    }
    ?>
    <?php if (!empty($formAnswersAll)): ?>
    <div class="rto-card">
      <div class="rto-card-header">
        <h3>Submitted Application Details</h3>
        <div style="display:flex;align-items:center;gap:10px">
          <?php if (!empty($formSubmission['category'])): ?>
          <span class="rto-badge rto-badge-secondary rto-badge-xs"><?= esc_html($formSubmission['category']) ?></span>
          <?php endif; ?>
          <?php if ($hiddenBlankCount > 0): ?>
          <label class="rto-small rto-muted" style="display:flex;align-items:center;gap:4px;cursor:pointer">
            <input type="checkbox" id="showAllFieldsToggle"> Show all fields (<?= (int)$hiddenBlankCount ?> blank hidden)
          </label>
          <?php endif; ?>
        </div>
      </div>
      <div class="rto-card-body">
        <div id="answersDefault" data-empty="<?= empty($formAnswers) ? '1' : '0' ?>">
          <?php if (empty($formAnswers)): ?>
          <div class="rto-empty-small">Every field for this order was left blank. Check "Show all fields" above to see the full schema.</div>
          <?php else: ?>
          <?php foreach (rto_group_answers_by_step($formAnswers) as $stepLabel => $stepAnswers): ?>
          <details class="rto-step-group" open>
            <summary><?= esc_html($stepLabel) ?> <span class="rto-muted" style="font-weight:400;text-transform:none;letter-spacing:0"><?= count($stepAnswers) ?></span></summary>
            <div class="rto-detail-grid">
              <?php foreach ($stepAnswers as $ans): ?>
              <div class="rto-detail-item"><span><?= esc_html($ans['label']) ?></span><strong><?= esc_html(implode(', ', is_array($ans['value']) ? array_map('strval', $ans['value']) : [(string)$ans['value']])) ?></strong></div>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div id="answersAll" style="display:none">
          <?php foreach (rto_group_answers_by_step($formAnswersAll) as $stepLabel => $stepAnswers): ?>
          <details class="rto-step-group" open>
            <summary><?= esc_html($stepLabel) ?> <span class="rto-muted" style="font-weight:400;text-transform:none;letter-spacing:0"><?= count($stepAnswers) ?></span></summary>
            <div class="rto-detail-grid">
              <?php foreach ($stepAnswers as $ans):
                $v = $ans['value'];
                $display = is_array($v) ? implode(', ', array_map('strval', $v)) : (($v !== '' && $v !== null) ? (string)$v : '—');
              ?>
              <div class="rto-detail-item"><span><?= esc_html($ans['label']) ?></span><strong><?= esc_html($display) ?></strong></div>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="rto-card">
      <div class="rto-card-header"><h3>Submitted Application Details</h3></div>
      <div class="rto-card-body"><div class="rto-empty-small">No custom intake-form answers were captured for this order (legacy/quick-enquiry order, or the service had no active custom form at submission time).</div></div>
    </div>
    <?php endif; ?>

    <!-- Lead Summary (Part 5.8) — auto-generated natural-language paragraph,
         computed server-side by LeadSummaryService from the same declutered
         $formAnswers the "Submitted Application Details" card above renders,
         so it can never contradict what's shown there. Renders as prose,
         not a table, positioned immediately above Internal Notes per the
         explicit placement request. -->
    <?php if (!empty($leadSummary)): ?>
    <div class="rto-card">
      <div class="rto-card-header"><h3>Lead Summary</h3><span class="rto-badge rto-badge-secondary rto-badge-xs">Auto-generated</span></div>
      <div class="rto-card-body">
        <p style="margin:0;line-height:1.6;color:var(--gray-800,#1e293b)"><?= esc_html($leadSummary) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Internal Notes — a real append-only conversation thread
         (rto_lead_notes, see LeadNoteService). Replaces the old single
         shared textarea that overwrote the whole rto_leads.internal_notes
         column on every save (silently destroying another staff member's
         text). Admin-only, same gate as the feature it replaces. -->
    <?php if (rto_is_admin()): ?>
    <div class="rto-card">
      <div class="rto-card-header"><h3>Internal Notes</h3><span class="rto-badge rto-badge-secondary rto-badge-xs">Staff only</span></div>
      <div class="rto-card-body">
        <div class="rto-notes-thread" id="notesThread" role="log" aria-live="polite" aria-label="Internal notes thread"
             style="max-height:320px;overflow-y:auto;margin-bottom:12px;display:flex;flex-direction:column;gap:10px">
          <?php if (empty($leadNotes)): ?>
          <div class="rto-empty-small" id="notesEmptyState">No internal notes yet.</div>
          <?php else: ?>
          <?php foreach ($leadNotes as $note): ?>
          <div class="rto-note-entry" style="border:1px solid var(--gray-200,#e2e8f0);border-radius:6px;padding:8px 10px;background:var(--gray-50,#f8fafc)">
            <div class="rto-note-meta" style="display:flex;gap:8px;align-items:baseline;margin-bottom:4px">
              <strong><?= esc_html($note['author_name'] ?? 'Unknown') ?></strong>
              <span class="rto-muted rto-small"><?= esc_html(rto_date($note['created_at'] ?? '', 'd M Y H:i')) ?></span>
            </div>
            <div class="rto-note-body" style="white-space:pre-wrap"><?= nl2br(esc_html($note['note_text'] ?? '')) ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="rto-note-input" style="display:flex;gap:8px">
          <label for="newNoteText" class="rto-visually-hidden" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Add an internal note</label>
          <textarea id="newNoteText" class="rto-input" rows="2" aria-label="Add an internal note"
                    placeholder="Add an internal note about this order (not visible to the client)…" style="flex:1;resize:vertical"></textarea>
          <button class="rto-btn rto-btn-primary rto-btn-sm" id="addNoteBtn" aria-label="Add note" style="align-self:flex-end">Add Note</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($lead['payment_status'] ?? '') !== 'paid'): ?>
    <!-- Record Manual Payment -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Record Payment</h3></div>
      <div class="rto-card-body">
        <div class="rto-form-row" style="flex-wrap:wrap;gap:8px">
          <input type="number" id="payAmount" placeholder="Amount (₹)" class="rto-input"
                 style="max-width:140px" step="0.01"
                 value="<?= max(0, (float)($lead['total_amount'] ?? 0) - (float)($lead['paid_amount'] ?? 0)) ?>">
          <select id="payMethod" class="rto-select" style="max-width:160px">
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cheque">Cheque</option>
            <option value="online">Online</option>
            <option value="razorpay">Razorpay</option>
          </select>
          <input type="text" id="payTxnId" placeholder="UTR / Txn ID" class="rto-input" style="max-width:180px">
          <button class="rto-btn rto-btn-success" id="recordPayBtn">Record Payment</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Communication Thread -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Communication Thread</h3></div>
      <div class="rto-card-body">
        <div class="rto-messages" id="messagesBox" style="max-height:300px;overflow-y:auto;margin-bottom:12px">
          <?php if (empty($messages)): ?>
          <div class="rto-empty-small">No messages yet.</div>
          <?php else: ?>
          <?php foreach ($messages as $msg): ?>
          <div class="rto-message rto-message-<?= esc_attr($msg['sender_type'] ?? 'staff') ?>">
            <div class="rto-message-meta">
              <strong><?= esc_html($msg['display_name'] ?? 'System') ?></strong>
              <span class="rto-muted"><?= esc_html(rto_date($msg['created_at'] ?? '', 'd M H:i')) ?></span>
              <span class="rto-badge rto-badge-xs"><?= esc_html(ucfirst($msg['sender_type'] ?? '')) ?></span>
            </div>
            <div class="rto-message-body"><?= nl2br(esc_html($msg['message'] ?? '')) ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="rto-message-input" style="display:flex;gap:8px">
          <textarea id="msgText" class="rto-input" rows="2"
                    placeholder="Type a message…" style="flex:1;resize:vertical"></textarea>
          <button class="rto-btn rto-btn-primary" id="sendMsgBtn" style="align-self:flex-end">Send</button>
        </div>
      </div>
    </div>

    <!-- Audit Trail -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Audit Trail</h3></div>
      <div class="rto-card-body rto-table-scroll">
        <?php if (empty($auditLog)): ?>
        <div class="rto-empty-small">No audit entries yet.</div>
        <?php else: ?>
        <table class="rto-table rto-table-sm" data-rto-responsive="cards">
          <thead><tr><th>Action</th><th>By</th><th>Details</th><th>Time</th></tr></thead>
          <tbody>
            <?php foreach ($auditLog as $entry): ?>
            <tr>
              <td data-label="Action" class="rto-mono rto-small"><?= esc_html($entry['action'] ?? '') ?></td>
              <td data-label="By" class="rto-small"><?= esc_html($entry['user_name'] ?? 'System') ?></td>
              <td data-label="Details" class="rto-muted rto-small">
                <?php
                if (!empty($entry['new_value'])) {
                    $d = json_decode($entry['new_value'], true);
                    if (is_array($d)) {
                        echo esc_html(implode(', ', array_map(
                            fn($k,$v) => "$k: $v",
                            array_keys($d),
                            array_values($d)
                        )));
                    }
                }
                ?>
              </td>
              <td data-label="Time" class="rto-muted rto-small"><?= esc_html(rto_date($entry['created_at'] ?? '', 'd M H:i')) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.rto-lead-left -->

  <!-- ── Right column ──────────────────────────────────────────────────── -->
  <div class="rto-lead-right">

    <!-- Vendor Assignment — FIXED: iterate matched + other groups separately -->
    <div class="rto-card">
      <div class="rto-card-header">
        <h3>Vendor Assignment</h3>
        <?php if (!empty($vendors['matched'])): ?>
        <span class="rto-badge rto-badge-success rto-badge-xs"><?= count($vendors['matched']) ?> matched</span>
        <?php endif; ?>
      </div>
      <div class="rto-card-body">
        <?php if ($assignedVendor): ?>
        <div class="rto-msg rto-msg-info rto-mb-3" style="padding:10px 12px;font-size:13px">
          <div style="margin-bottom:6px">Currently assigned: <strong><?= esc_html($assignedVendor['full_name']) ?></strong>
          (<?= esc_html($assignedVendor['vendor_number']) ?>)</div>
          <div class="rto-detail-grid" style="grid-template-columns:1fr 1fr">
            <div class="rto-detail-item"><span>Rating</span><strong>⭐ <?= number_format((float)$assignedVendor['rating'], 1) ?></strong></div>
            <div class="rto-detail-item"><span>Completion Rate</span><strong><?= esc_html(number_format((float)($assignedVendor['completion_rate'] ?? 0), 1)) ?>%</strong></div>
            <div class="rto-detail-item"><span>Mobile</span><strong><?= esc_html($assignedVendor['mobile'] ?: '—') ?></strong></div>
            <div class="rto-detail-item"><span>Email</span><strong><?= esc_html($assignedVendor['email'] ?: '—') ?></strong></div>
          </div>
        </div>
        <?php endif; ?>
        <label class="rto-label" for="vendorSelect">Assign / Reassign Vendor</label>
        <select id="vendorSelect" class="rto-select rto-select-full" style="margin-bottom:10px">
          <option value="">— Select Vendor —</option>
          <?php if (!empty($vendors['matched'])): ?>
          <optgroup label="✓ Matched (covers this city + service)">
            <?php foreach ($vendors['matched'] as $v): ?>
            <option value="<?= esc_attr($v['id']) ?>"
                    data-mobile="<?= esc_attr($v['mobile'] ?? '') ?>"
                    data-email="<?= esc_attr($v['email'] ?? '') ?>"
                    title="<?= esc_attr(trim(($v['mobile'] ?? '') . ' ' . ($v['email'] ?? ''))) ?>"
                    <?= (int)($lead['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
              <?= esc_html($v['full_name']) ?> (<?= esc_html($v['vendor_number']) ?>) ⭐<?= number_format((float)$v['rating'], 1) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($vendors['other'])): ?>
          <optgroup label="Other Vendors">
            <?php foreach ($vendors['other'] as $v): ?>
            <option value="<?= esc_attr($v['id']) ?>"
                    data-mobile="<?= esc_attr($v['mobile'] ?? '') ?>"
                    data-email="<?= esc_attr($v['email'] ?? '') ?>"
                    title="<?= esc_attr(trim(($v['mobile'] ?? '') . ' ' . ($v['email'] ?? ''))) ?>"
                    <?= (int)($lead['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
              <?= esc_html($v['full_name']) ?> (<?= esc_html($v['vendor_number']) ?>) ⭐<?= number_format((float)$v['rating'], 1) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (empty($vendors['matched']) && empty($vendors['other'])): ?>
          <option disabled>No verified vendors available</option>
          <?php endif; ?>
        </select>
        <button class="rto-btn rto-btn-primary rto-btn-full" id="assignVendorBtn">Assign Vendor</button>
      </div>
    </div>

    <!-- Payments -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Payment History</h3></div>
      <div class="rto-card-body">
        <?php if (empty($payments)): ?>
        <div class="rto-empty-small">No payments recorded.</div>
        <?php else: ?>
        <?php foreach ($payments as $pmt): ?>
        <div class="rto-payment-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9">
          <div>
            <strong><?= esc_html(rto_format_inr((float)$pmt['amount'])) ?></strong>
            <span class="rto-badge rto-badge-xs rto-badge-secondary"><?= esc_html(ucfirst($pmt['method'] ?? '')) ?></span>
          </div>
          <div class="rto-muted rto-small" style="text-align:right">
            <?= esc_html($pmt['txn_id'] ?? '') ?><br>
            <?= esc_html(rto_date($pmt['created_at'] ?? '', 'd M Y')) ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Documents -->
    <div class="rto-card">
      <div class="rto-card-header"><h3>Documents</h3></div>
      <div class="rto-card-body">
        <?php if (empty($documents)): ?>
        <div class="rto-empty-small">No documents uploaded yet.</div>
        <?php else: ?>
        <?php foreach ($documents as $doc): ?>
        <div class="rto-doc-row" style="display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f1f5f9;gap:8px">
          <div style="min-width:0">
            <div class="rto-small rto-fw-600"><?= esc_html($doc['doc_type_name'] ?? 'Document') ?></div>
            <div class="rto-muted rto-small" style="word-break:break-all"><?= esc_html($doc['file_name'] ?? '') ?></div>
            <div style="margin-top:4px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <span class="rto-badge rto-badge-secondary rto-badge-xs"><?= esc_html($doc['mime_type'] ?? 'unknown') ?></span>
              <span class="rto-muted rto-small"><?= esc_html($doc['file_size_human'] ?? '—') ?></span>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
            <span class="rto-badge rto-badge-<?= ($doc['status'] ?? '') === 'verified' ? 'success' : (($doc['status'] ?? '') === 'rejected' ? 'danger' : 'warning') ?>">
              <?= esc_html(ucfirst($doc['status'] ?? '')) ?>
            </span>
            <div style="display:flex;gap:4px">
              <a class="rto-btn rto-btn-xs" href="<?= esc_url($doc['view_url']) ?>" target="_blank" rel="noopener"
                 aria-label="Preview document: <?= esc_attr($doc['file_name'] ?? '') ?>">Preview</a>
              <a class="rto-btn rto-btn-xs" href="<?= esc_url($doc['download_url']) ?>"
                 aria-label="Download document: <?= esc_attr($doc['file_name'] ?? '') ?>">Download</a>
              <?php if (($doc['status'] ?? '') === 'pending'): ?>
              <button class="rto-btn rto-btn-xs rto-btn-success"
                      data-doc="<?= esc_attr($doc['id']) ?>" data-action="verify"
                      aria-label="Verify document: <?= esc_attr($doc['file_name'] ?? '') ?>">✓</button>
              <button class="rto-btn rto-btn-xs rto-btn-danger"
                      data-doc="<?= esc_attr($doc['id']) ?>" data-action="reject"
                      aria-label="Reject document: <?= esc_attr($doc['file_name'] ?? '') ?>">✗</button>
              <?php endif; ?>
            </div>
            <?php // ENTERPRISE GAP FIX (Phase 4, item 3 — document expiry tracking):
                  // staff can set/clear an expiry date on any document (license,
                  // insurance, RC etc.); Bootstrap::runDocumentExpiryCheck() picks
                  // this up daily and reminds the client before it lapses. ?>
            <div class="rto-doc-expiry" style="display:flex;gap:4px;align-items:center;margin-top:4px">
              <input type="date" class="rto-input rto-input-xs rto-doc-expiry-input"
                     data-doc="<?= esc_attr($doc['id']) ?>"
                     value="<?= esc_attr($doc['expiry_date'] ?? '') ?>"
                     aria-label="Expiry date for document: <?= esc_attr($doc['file_name'] ?? '') ?>">
              <button class="rto-btn rto-btn-xs rto-doc-expiry-save" data-doc="<?= esc_attr($doc['id']) ?>"
                      aria-label="Save expiry date for document: <?= esc_attr($doc['file_name'] ?? '') ?>">Save</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.rto-lead-right -->
</div><!-- /.rto-lead-grid -->

<?php if (rto_is_admin() && empty($lead['deleted_at'])): ?>
<!-- Part 5.7: Edit core fields modal — deliberately narrow (city + SLA
     deadline extension only). See LeadService::updateCoreFields() for why
     total_amount/gst_amount/service_id are NOT here: they drive GST,
     vendor payout splits, and anything already recorded in rto_payments;
     editing them post-creation needs a real reconciliation path, not a
     quick field swap. -->
<div class="rto-modal-overlay" id="editLeadModal">
  <div class="rto-modal" role="dialog" aria-modal="true" aria-labelledby="editLeadModalTitle" tabindex="-1">
    <div class="rto-modal-header">
      <h3 id="editLeadModalTitle" style="margin:0">Edit Order</h3>
      <button type="button" class="rto-btn rto-btn-xs" id="editLeadClose" aria-label="Close edit order dialog">✕</button>
    </div>
    <div class="rto-modal-body">
      <div>
        <label class="rto-label" for="editCity">City</label>
        <select id="editCity" class="rto-select rto-select-full">
          <?php foreach (($cities ?? []) as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)($lead['city_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= esc_html($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rto-label" for="editSlaDeadline">SLA Deadline</label>
        <input type="datetime-local" id="editSlaDeadline" class="rto-input"
               value="<?= esc_attr(!empty($lead['sla_deadline']) ? str_replace(' ', 'T', substr($lead['sla_deadline'], 0, 16)) : '') ?>">
        <div class="rto-muted rto-small" style="margin-top:4px">Extending this clears any existing SLA-breach flag on this order.</div>
      </div>
    </div>
    <div class="rto-modal-footer">
      <button type="button" class="rto-btn" id="editLeadCancel">Cancel</button>
      <button type="button" class="rto-btn rto-btn-primary" id="editLeadSave">Save Changes</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function() {
  var LEAD_ID   = <?= (int)$lead['id'] ?>;
  var LOCK_VER  = <?= (int)($lead['lock_version'] ?? 1) ?>;
  var AJAX_URL  = <?= json_encode(admin_url('admin-ajax.php')) ?>;
  var NONCE     = <?= json_encode(wp_create_nonce('rto_admin_lead')) ?>;

  /* ── Generic AJAX helper ──────────────────────────────────────────── */
  function rtoPost(params, cb) {
    var fd = new FormData();
    params.action    = 'rto_admin';
    params.rto_area  = 'admin';
    params.rto_nonce = NONCE;
    Object.keys(params).forEach(function(k) { fd.append(k, params[k]); });
    fetch(AJAX_URL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r) { return r.json(); })
      .then(cb)
      .catch(function(e) { if (window.rtoToast) rtoToast('Request failed. Please try again.', 'error'); });
  }

  /* ── Update Status ────────────────────────────────────────────────── */
  var updateBtn = document.getElementById('updateStatusBtn');
  if (updateBtn) {
    updateBtn.addEventListener('click', function() {
      var s    = document.getElementById('statusSelect').value;
      var note = prompt('Optional note for this status change:') || '';
      updateBtn.disabled = true;
      updateBtn.textContent = 'Updating…';
      rtoPost({ rto_action:'update_status', lead_id:LEAD_ID, status:s, note:note, lock_version:LOCK_VER },
        function(r) {
          if (r.success) {
            if (window.rtoToast) rtoToast('Status updated successfully.', 'success');
            setTimeout(function() { location.reload(); }, 1000);
          } else {
            var msg = (r.data && r.data.message) ? r.data.message : 'Update failed.';
            if (window.rtoToast) rtoToast(msg, 'error');
            updateBtn.disabled = false;
            updateBtn.textContent = 'Update Status';
          }
        });
    });
  }

  /* ── Side-effects-incomplete acknowledgement (Phase 7, item 3) ──────── */
  var ackBtn = document.getElementById('ackSideEffectsBtn');
  if (ackBtn) {
    ackBtn.addEventListener('click', function() {
      if (!confirm('Confirm you have manually followed up on the failed side effect(s) shown above?')) return;
      ackBtn.disabled = true;
      rtoPost({ rto_action:'ack_side_effects', lead_id:LEAD_ID },
        function(r) {
          if (r.success) {
            if (window.rtoToast) rtoToast('Cleared.', 'success');
            setTimeout(function() { location.reload(); }, 800);
          } else {
            var msg = (r.data && r.data.message) ? r.data.message : 'Failed.';
            if (window.rtoToast) rtoToast(msg, 'error');
            ackBtn.disabled = false;
          }
        });
    });
  }

  /* ── Assign Vendor ────────────────────────────────────────────────── */
  var assignBtn = document.getElementById('assignVendorBtn');
  if (assignBtn) {
    assignBtn.addEventListener('click', function() {
      var vid = document.getElementById('vendorSelect').value;
      if (!vid) { if (window.rtoToast) rtoToast('Please select a vendor.', 'error'); return; }
      assignBtn.disabled = true;
      assignBtn.textContent = 'Assigning…';
      rtoPost({ rto_action:'assign_vendor', lead_id:LEAD_ID, vendor_id:vid },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Vendor assigned!' : 'Assignment failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          // Known Limitations audit fix: "Manual assignment eligibility is
          // enforced more loosely than auto-assignment eligibility ...
          // without any warning." LeadsController::assignVendor() now
          // returns a non-blocking warnings[] array on success when the
          // chosen vendor fails a coverage/rating/capacity check the
          // auto-assignment engine would have enforced — surfaced here so
          // the override is a conscious choice, not an invisible one.
          if (r.success && r.data && r.data.warnings && r.data.warnings.length && window.rtoToast) {
            r.data.warnings.forEach(function(w) { rtoToast('⚠ ' + w, 'warning'); });
          }
          if (r.success) setTimeout(function() { location.reload(); }, r.data && r.data.warnings && r.data.warnings.length ? 2500 : 1000);
          else { assignBtn.disabled = false; assignBtn.textContent = 'Assign Vendor'; }
        });
    });
  }

  /* ── Record Payment ───────────────────────────────────────────────── */
  var payBtn = document.getElementById('recordPayBtn');
  if (payBtn) {
    payBtn.addEventListener('click', function() {
      var amount = document.getElementById('payAmount').value;
      var method = document.getElementById('payMethod').value;
      var txnId  = document.getElementById('payTxnId').value;
      if (!amount || parseFloat(amount) <= 0) {
        if (window.rtoToast) rtoToast('Please enter a valid amount.', 'error');
        return;
      }
      payBtn.disabled = true;
      payBtn.textContent = 'Recording…';
      rtoPost({ rto_action:'record_payment', lead_id:LEAD_ID, amount:amount, method:method, txn_id:txnId },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Payment recorded!' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 1000);
          else { payBtn.disabled = false; payBtn.textContent = 'Record Payment'; }
        });
    });
  }

  /* ── Send Message ─────────────────────────────────────────────────── */
  var sendBtn = document.getElementById('sendMsgBtn');
  if (sendBtn) {
    sendBtn.addEventListener('click', function() {
      var msgEl = document.getElementById('msgText');
      var msg   = msgEl ? msgEl.value.trim() : '';
      if (!msg) return;
      sendBtn.disabled = true;
      rtoPost({ rto_action:'add_message', lead_id:LEAD_ID, message:msg },
        function(r) {
          if (r.success) {
            msgEl.value = '';
            setTimeout(function() { location.reload(); }, 400);
          } else {
            var err = (r.data && r.data.message) ? r.data.message : 'Failed to send.';
            if (window.rtoToast) rtoToast(err, 'error');
          }
          sendBtn.disabled = false;
        });
    });
  }

  /* ── Add Internal Note (append-only thread, no page reload) ─────────
     Replaces the old "Save Notes" single-textarea overwrite handler.
     Each successful add appends one new entry to #notesThread and clears
     the compose box — it never touches or re-renders any existing entry,
     matching the append-only rto_lead_notes backend. #notesThread is an
     aria-live="polite" region (set in the markup) so a screen-reader user
     is told a new note appeared without needing to re-read the thread. */
  function escHtmlClient(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
  var addNoteBtn = document.getElementById('addNoteBtn');
  if (addNoteBtn) {
    addNoteBtn.addEventListener('click', function() {
      var noteEl = document.getElementById('newNoteText');
      var note   = noteEl ? noteEl.value.trim() : '';
      if (!note) { if (window.rtoToast) rtoToast('Note cannot be empty.', 'error'); return; }
      addNoteBtn.disabled = true;
      addNoteBtn.textContent = 'Adding…';
      rtoPost({ rto_action:'add_lead_note', lead_id:LEAD_ID, note:note },
        function(r) {
          if (r.success) {
            var thread = document.getElementById('notesThread');
            var empty  = document.getElementById('notesEmptyState');
            if (empty) empty.remove();
            var n = (r.data && r.data.note) ? r.data.note : null;
            if (thread && n) {
              var entry = document.createElement('div');
              entry.className = 'rto-note-entry';
              entry.style.cssText = 'border:1px solid var(--gray-200,#e2e8f0);border-radius:6px;padding:8px 10px;background:var(--gray-50,#f8fafc)';
              entry.innerHTML =
                '<div class="rto-note-meta" style="display:flex;gap:8px;align-items:baseline;margin-bottom:4px">' +
                  '<strong>' + escHtmlClient(n.author_name || 'Unknown') + '</strong>' +
                  '<span class="rto-muted rto-small">' + escHtmlClient(n.created_at || '') + '</span>' +
                '</div>' +
                '<div class="rto-note-body" style="white-space:pre-wrap">' + escHtmlClient(n.note_text || '').replace(/\n/g, '<br>') + '</div>';
              thread.appendChild(entry);
              thread.scrollTop = thread.scrollHeight;
            }
            noteEl.value = '';
          } else {
            var err = (r.data && r.data.message) ? r.data.message : 'Failed to add note.';
            if (window.rtoToast) rtoToast(err, 'error');
          }
          addNoteBtn.disabled = false;
          addNoteBtn.textContent = 'Add Note';
        });
    });
  }

  /* ── Verify / Reject document ─────────────────────────────────────── */
  // FIX (Order Details 360° audit, follow-up): the Documents panel's
  // Verify (✓) / Reject (✗) buttons render with data-doc/data-action
  // attributes but had no click handler at all — the backend endpoints
  // (admin.verify_document / admin.reject_document, Router.php) already
  // existed and work; this was dead UI with no wiring, not a missing
  // backend. Wired here rather than left as a "known limitation".
  document.querySelectorAll('[data-action="verify"], [data-action="reject"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var docId  = btn.getAttribute('data-doc');
      var action = btn.getAttribute('data-action');
      var reason = '';
      if (action === 'reject') {
        reason = prompt('Reason for rejecting this document:') || '';
        if (!reason.trim()) { if (window.rtoToast) rtoToast('A rejection reason is required.', 'error'); return; }
      }
      btn.disabled = true;
      rtoPost({ rto_action: action === 'verify' ? 'verify_document' : 'reject_document', doc_id: docId, reason: reason },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Done.' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 800);
          else btn.disabled = false;
        });
    });
  });

  /* ── Set/clear document expiry date (Phase 4, item 3) ─────────────── */
  document.querySelectorAll('.rto-doc-expiry-save').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var docId = btn.getAttribute('data-doc');
      var input = document.querySelector('.rto-doc-expiry-input[data-doc="' + docId + '"]');
      var expiry = input ? input.value : '';
      btn.disabled = true;
      rtoPost({ rto_action: 'set_document_expiry', doc_id: docId, expiry_date: expiry },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Done.' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          btn.disabled = false;
        });
    });
  });

  /* ── Auto-scroll messages to bottom ─────────────────────────────── */
  var msgBox = document.getElementById('messagesBox');
  if (msgBox) msgBox.scrollTop = msgBox.scrollHeight;

  /* ── Part 5.7: Show all fields toggle ─────────────────────────────── */
  var showAllToggle = document.getElementById('showAllFieldsToggle');
  if (showAllToggle) {
    showAllToggle.addEventListener('change', function() {
      document.getElementById('answersDefault').style.display = this.checked ? 'none' : '';
      document.getElementById('answersAll').style.display     = this.checked ? '' : 'none';
    });
  }

  /* ── Part 5.7: Print (real window.print(), print stylesheet already
     hides sidebar/actions — see resources/assets/css/admin.css @media
     print). This is an honest "printable view", not a fake PDF-export
     button — the real, FPDF-generated Export PDF link covers that. ── */
  var printBtn = document.getElementById('printBtn');
  if (printBtn) printBtn.addEventListener('click', function() { window.print(); });

  /* ── Part 5.7: Copy Link — the existing staff-gated deep link to this
     order (already behind rto_is_staff()), NOT a new public-access
     mechanism. See report for why a public share link was deliberately
     not built. ── */
  var copyLinkBtn = document.getElementById('copyLinkBtn');
  if (copyLinkBtn) {
    copyLinkBtn.addEventListener('click', function() {
      var url = window.location.href.split('?')[0];
      var done = function() { if (window.rtoToast) rtoToast('Link copied to clipboard.', 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, function() {
          if (window.rtoToast) rtoToast('Could not copy automatically — link: ' + url, 'error');
        });
      } else {
        if (window.rtoToast) rtoToast('Link: ' + url, 'info');
      }
    });
  }

  /* ── Part 5.7: Edit core fields modal ─────────────────────────────── */
  var editBtn   = document.getElementById('editLeadBtn');
  var editModal = document.getElementById('editLeadModal');
  if (editBtn && editModal) {
    var editModalDialog = editModal.querySelector('.rto-modal');
    var editLeadReturnFocus = null;
    var closeModal = function() {
      editModal.classList.remove('is-open');
      if (editLeadReturnFocus && typeof editLeadReturnFocus.focus === 'function') {
        editLeadReturnFocus.focus();
      }
      editLeadReturnFocus = null;
    };
    editBtn.addEventListener('click', function() {
      editLeadReturnFocus = editBtn;
      editModal.classList.add('is-open');
      // Focus the dialog itself; a real field-level default could also work,
      // but the container is guaranteed to exist regardless of form state.
      if (editModalDialog) editModalDialog.focus();
    });
    document.getElementById('editLeadClose').addEventListener('click', closeModal);
    document.getElementById('editLeadCancel').addEventListener('click', closeModal);
    editModal.addEventListener('click', function(e) { if (e.target === editModal) closeModal(); });
    editModal.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

    document.getElementById('editLeadSave').addEventListener('click', function() {
      var btn = this;
      var city = document.getElementById('editCity').value;
      var sla  = document.getElementById('editSlaDeadline').value; // datetime-local -> "YYYY-MM-DDTHH:MM"
      var slaMysql = sla ? sla.replace('T', ' ') + ':00' : '';
      btn.disabled = true;
      btn.textContent = 'Saving…';
      rtoPost({ rto_action:'update_lead', lead_id:LEAD_ID, city_id:city, sla_deadline:slaMysql },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Order updated.' : 'Update failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 800);
          else { btn.disabled = false; btn.textContent = 'Save Changes'; }
        });
    });
  }

  /* ── Part 5.7: Archive / Restore ──────────────────────────────────── */
  var archiveBtn = document.getElementById('archiveLeadBtn');
  if (archiveBtn) {
    archiveBtn.addEventListener('click', function() {
      if (!confirm('Archive this order? It will be hidden from the default Leads list but can be restored any time.')) return;
      archiveBtn.disabled = true;
      rtoPost({ rto_action:'archive_lead', lead_id:LEAD_ID },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Order archived.' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 800);
          else archiveBtn.disabled = false;
        });
    });
  }
  var restoreBtn = document.getElementById('restoreLeadBtn');
  if (restoreBtn) {
    restoreBtn.addEventListener('click', function() {
      restoreBtn.disabled = true;
      rtoPost({ rto_action:'restore_lead', lead_id:LEAD_ID },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Order restored.' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 800);
          else restoreBtn.disabled = false;
        });
    });
  }

  /* ── Known Limitations audit fix: Reopen a terminal order ────────────
     Mandatory reason (prompt(), not a silent confirm()) — reopenLead()
     server-side rejects a blank reason too, so this is UX guidance, not
     the actual enforcement boundary. */
  var reopenBtn = document.getElementById('reopenLeadBtn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', function() {
      var reason = prompt('Why are you reopening this closed order? (required, logged in the audit trail)');
      if (reason === null) return;
      reason = reason.trim();
      if (!reason) { if (window.rtoToast) rtoToast('A reason is required to reopen a closed order.', 'error'); return; }
      reopenBtn.disabled = true;
      rtoPost({ rto_action:'reopen_lead', lead_id:LEAD_ID, reason:reason },
        function(r) {
          var msg = (r.data && r.data.message) ? r.data.message : (r.success ? 'Order reopened.' : 'Failed.');
          if (window.rtoToast) rtoToast(msg, r.success ? 'success' : 'error');
          if (r.success) setTimeout(function() { location.reload(); }, 800);
          else reopenBtn.disabled = false;
        });
    });
  }

})();
</script>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
