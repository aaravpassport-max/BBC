<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc_html($lead['lead_number']) ?> — RTOFLOW</title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<?php
// FIX (mobile pass follow-up): standalone markup, never went through
// layouts/client-header.php, so the order-detail page — reachable from
// every row of the client's order list — was missed by the mobile
// bottom-nav work.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-client-body">
<div class="rto-client-wrap">
  <header class="rto-client-header">
    <div class="rto-client-header-inner">
      <a href="<?= esc_url(home_url('/')) ?>" class="rto-client-logo"><?= esc_html(get_option('rtoflow_company_name', 'RTOFLOW')) ?></a>
      <nav class="rto-client-nav">
        <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>">← My Orders</a>
        <span class="rto-client-user"><?= esc_html(wp_get_current_user()->display_name) ?></span>
        <a href="<?= esc_url(rto_logout_url()) ?>" class="rto-logout-link">Logout</a>
      </nav>
    </div>
  </header>

  <nav class="rto-bottom-nav" id="rtoBottomNav" aria-label="Primary" data-rto-area="client">
    <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="rto-bn-item" data-rto-page="dashboard">
      <span class="rto-bn-icon" aria-hidden="true">🏠</span><span class="rto-bn-label">Dashboard</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/orders/')) ?>" class="rto-bn-item active" data-rto-page="orders" aria-current="page">
      <span class="rto-bn-icon" aria-hidden="true">📦</span><span class="rto-bn-label">Orders</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/documents/')) ?>" class="rto-bn-item" data-rto-page="documents">
      <span class="rto-bn-icon" aria-hidden="true">📄</span><span class="rto-bn-label">Documents</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-apply/')) ?>" class="rto-bn-item" data-rto-page="apply">
      <span class="rto-bn-icon" aria-hidden="true">➕</span><span class="rto-bn-label">New</span>
    </a>
    <a href="<?= esc_url(home_url('/rto-dashboard/profile/')) ?>" class="rto-bn-item" data-rto-page="profile">
      <span class="rto-bn-icon" aria-hidden="true">👤</span><span class="rto-bn-label">Profile</span>
    </a>
  </nav>

  <main class="rto-client-main" id="rtoContentRegion" data-rto-page="orders">

    <!-- Order header -->
    <div class="rto-order-detail-header">
      <div>
        <h2><?= esc_html($lead['lead_number']) ?></h2>
        <p class="rto-muted"><?= esc_html($lead['service_name']) ?> · <?= esc_html($lead['city_name']) ?></p>
      </div>
      <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?> rto-badge-lg">
        <?= esc_html(rto_status_label($lead['status'])) ?>
      </span>
    </div>

    <!-- Progress tracker -->
    <?php
    $steps = [
      ['key'=>'created',          'label'=>'Order Received'],
      ['key'=>'payment_received', 'label'=>'Payment Confirmed'],
      ['key'=>'assigned',         'label'=>'Agent Assigned'],
      ['key'=>'in_progress',      'label'=>'In Progress'],
      ['key'=>'rto_submitted',    'label'=>'Submitted to RTO'],
      ['key'=>'completed',        'label'=>'Completed'],
    ];
    $stepKeys = array_column($steps, 'key');
    // P6-UX-002 FIX: complete mapping for all 12 lead statuses
    $allStepMap = [
        'created'=>0,'payment_pending'=>0,
        'payment_received'=>1,
        'assigned'=>2,
        'in_progress'=>3,'docs_pending'=>3,'docs_verified'=>3,'on_hold'=>3,
        'rto_submitted'=>4,'rto_processing'=>4,
        'completed'=>5,
        'cancelled'=>-1,
    ];
    $curIdx      = $allStepMap[$lead['status']] ?? 0;
    $isCancelled = ($lead['status'] === 'cancelled');
    ?>
    <div class="rto-client-card rto-progress-tracker">
      <ol class="rto-tracker-steps" aria-label="Order steps">
        <?php foreach ($steps as $i => $step): ?>
        <li class="rto-tracker-step <?= $i < $curIdx ? 'done' : ($i === $curIdx ? 'active' : '') ?>">
          <div class="rto-tracker-dot"><?= $i < $curIdx ? '✓' : ($i + 1) ?></div>
          <div class="rto-tracker-label"><?= esc_html($step['label']) ?></div>
        </li>
        <?php if ($i < count($steps)-1): ?><li class="rto-tracker-line <?= $i < $curIdx ? 'done' : '' ?>" aria-hidden="true"></li><?php endif; ?>
        <?php endforeach; ?>
      </ol>
    </div>

    <div class="rto-order-detail-grid">
      <!-- Left: Order info + payment + docs -->
      <div class="rto-order-detail-left">

        <!-- Order Details -->
        <div class="rto-client-card">
          <div class="rto-client-card-header"><h3>Order Details</h3></div>
          <div class="rto-client-card-body">
            <div class="rto-detail-grid">
              <div class="rto-detail-item"><span>Order Number</span><strong><?= esc_html($lead['lead_number']) ?></strong></div>
              <div class="rto-detail-item"><span>Service</span><strong><?= esc_html($lead['service_name']) ?></strong></div>
              <div class="rto-detail-item"><span>City</span><strong><?= esc_html($lead['city_name']) ?></strong></div>
              <div class="rto-detail-item"><span>Total Amount</span><strong><?= esc_html(rto_format_inr((float)$lead['total_amount'])) ?></strong></div>
              <div class="rto-detail-item"><span>GST Included</span><strong><?= esc_html(rto_format_inr((float)$lead['gst_amount'])) ?></strong></div>
              <div class="rto-detail-item"><span>Amount Paid</span>
                <strong class="<?= (float)$lead['paid_amount'] >= (float)$lead['total_amount'] ? 'rto-success' : 'rto-warning' ?>">
                  <?= esc_html(rto_format_inr((float)$lead['paid_amount'])) ?>
                </strong>
              </div>
              <div class="rto-detail-item"><span>Expected Completion</span><strong><?= esc_html(rto_date($lead['sla_deadline'] ?? '')) ?></strong></div>
              <div class="rto-detail-item"><span>Created On</span><strong><?= esc_html(rto_date($lead['created_at'])) ?></strong></div>
            </div>
          </div>
        </div>

        <!-- Pay Now section -->
        <?php if ($lead['payment_status'] !== 'paid' && $lead['status'] !== 'cancelled'): ?>
        <div class="rto-client-card rto-pay-card">
          <div class="rto-client-card-header"><h3>💳 Pay Now</h3></div>
          <div class="rto-client-card-body">
            <p>Amount due: <strong><?= esc_html(rto_format_inr((float)$lead['total_amount'] - (float)$lead['paid_amount'])) ?></strong></p>
            <button id="payNowBtn" class="rto-btn rto-btn-primary rto-btn-lg" data-lead="<?= esc_attr($lead['id']) ?>">
              Pay with Razorpay
            </button>
            <p class="rto-small rto-muted rto-mt-1">Secured by Razorpay. Accepts UPI, cards, net banking, wallets.</p>
          </div>
        </div>
        <?php endif; ?>

        <!-- ENTERPRISE GAP FIX (Phase 1, item 2 — "No client self-service
             cancellation or refund request"): previously every cancellation
             or refund had to be raised by a support agent on the client's
             behalf. This card lets the client submit either request
             directly; it lands in a staff review queue (Client Requests
             admin screen) rather than acting immediately — a cancellation
             or refund still needs a human decision, this only removes the
             "call/email support first" step. Hidden once the order is
             already cancelled/completed (a cancellation makes no sense
             then) and while a request of that type is already pending
             (server-side also blocks a duplicate — this is just the UI
             reflecting that immediately after submit, via JS below). -->
        <?php if (!in_array($lead['status'], ['cancelled'], true)): ?>
        <div class="rto-client-card" id="clientRequestCard">
          <div class="rto-client-card-header"><h3>✋ Need to Cancel or Get a Refund?</h3></div>
          <div class="rto-client-card-body">
            <div id="crButtons" style="display:flex;gap:8px;flex-wrap:wrap">
              <?php if (!in_array($lead['status'], ['completed'], true)): ?>
              <button type="button" class="rto-btn rto-btn-outline cr-open" data-type="cancellation">Request Cancellation</button>
              <?php endif; ?>
              <?php if ((float)$lead['paid_amount'] > 0): ?>
              <button type="button" class="rto-btn rto-btn-outline cr-open" data-type="refund">Request Refund</button>
              <?php endif; ?>
            </div>
            <div id="crForm" style="display:none;margin-top:12px">
              <label class="rto-label" for="crReason">Reason</label>
              <textarea id="crReason" class="rto-input" rows="3" maxlength="1000" placeholder="Tell us why…"></textarea>
              <button type="button" id="crSubmit" class="rto-btn rto-btn-primary rto-mt-1">Submit Request</button>
              <button type="button" id="crCancel" class="rto-btn rto-btn-outline rto-mt-1">Cancel</button>
            </div>
            <p id="crMsg" class="rto-small rto-mt-1" style="display:none"></p>
          </div>
        </div>
        <?php endif; ?>

        <!-- ENTERPRISE GAP FIX (critical missing functionality — clients had
             no way to submit a rating anywhere: RatingsController::store()
             AJAX action existed server-side but was 100% unreachable dead
             code with zero UI trigger). Rating widget for completed orders. -->
        <?php if ($lead['status'] === 'completed'): ?>
        <div class="rto-client-card" id="ratingCard">
          <div class="rto-client-card-header"><h3>⭐ Rate Your Experience</h3></div>
          <div class="rto-client-card-body">
            <?php if ($myRating): ?>
            <p>You rated this order <strong><?= (int)$myRating['score'] ?>/5</strong>.</p>
            <?php if ($myRating['comment']): ?><p class="rto-small rto-muted"><?= esc_html($myRating['comment']) ?></p><?php endif; ?>
            <?php else: ?>
            <div id="ratingStars" style="font-size:28px;letter-spacing:4px;cursor:pointer;margin-bottom:10px" aria-label="Rate 1 to 5 stars">
              <?php for ($i = 1; $i <= 5; $i++): ?><span class="rto-star" data-score="<?= $i ?>" style="color:#ccc" role="button" tabindex="0" aria-label="<?= $i ?> star<?= $i>1?'s':'' ?>">★</span><?php endfor; ?>
            </div>
            <textarea id="ratingComment" class="rto-input" rows="2" maxlength="500" placeholder="Optional comment…"></textarea>
            <button id="ratingSubmitBtn" class="rto-btn rto-btn-primary rto-mt-1" disabled>Submit Rating</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Invoice download -->
        <?php if ($invoice && $invoice['pdf_path']): ?>
        <div class="rto-client-card">
          <div class="rto-client-card-header"><h3>🧾 Invoice</h3></div>
          <div class="rto-client-card-body">
            <p>Invoice <?= esc_html($invoice['invoice_number']) ?> · <?= esc_html(rto_format_inr((float)$invoice['total'])) ?></p>
            <a href="<?= esc_url(home_url('/rto-invoice/' . $invoice['id'] . '?token=' . hash_hmac('sha256', 'invoice-' . $invoice['id'], AUTH_KEY))) ?>"
               class="rto-btn rto-btn-outline" target="_blank">
              📥 Download Invoice PDF
            </a>
          </div>
        </div>
        <?php endif; ?>

        <!-- Documents -->
        <div class="rto-client-card">
          <div class="rto-client-card-header">
            <h3>Documents</h3>
          </div>
          <div class="rto-client-card-body">
            <?php if (empty($documents)): ?>
            <div class="rto-empty-small">No documents uploaded yet.</div>
            <?php else: ?>
            <?php foreach ($documents as $doc): ?>
            <div class="rto-doc-row">
              <div class="rto-doc-info">
                <span class="rto-doc-icon">📄</span>
                <div>
                  <div class="rto-doc-type"><?= esc_html($doc['doc_type_name'] ?? 'Document') ?></div>
                  <div class="rto-doc-name rto-small rto-muted"><?= esc_html($doc['file_name']) ?></div>
                </div>
              </div>
              <span class="rto-badge rto-badge-<?= $doc['status'] === 'verified' ? 'success' : ($doc['status'] === 'rejected' ? 'danger' : 'warning') ?>">
                <?= esc_html(ucfirst($doc['status'])) ?>
              </span>
              <?php if ($doc['status'] === 'rejected' && $doc['reject_reason']): ?>
              <div class="rto-doc-reject-reason rto-small rto-danger">Reason: <?= esc_html($doc['reject_reason']) ?></div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Upload form -->
            <div class="rto-upload-form rto-mt-2">
              <label class="rto-label">Upload a Document</label>
              <input type="file" id="docFile" class="rto-input" accept=".pdf,.jpg,.jpeg,.png">
              <button class="rto-btn rto-btn-outline rto-mt-1" id="uploadDocBtn">Upload</button>
              <div id="uploadMsg" class="rto-msg" style="display:none"></div>
            </div>
          </div>
        </div>

      </div><!-- /.rto-order-detail-left -->

      <!-- Right: Messages -->
      <div class="rto-order-detail-right">
        <div class="rto-client-card rto-messages-card">
          <div class="rto-client-card-header"><h3>💬 Messages</h3></div>
          <div class="rto-client-card-body">
            <div class="rto-messages" id="msgBox">
              <?php if (empty($messages)): ?>
              <div class="rto-empty-small">No messages yet. Ask us anything about your order!</div>
              <?php else: ?>
              <?php foreach ($messages as $msg): ?>
              <div class="rto-message rto-message-<?= esc_attr($msg['sender_type'] ?? 'client') ?>">
                <div class="rto-message-meta">
                  <strong><?= esc_html($msg['display_name'] ?? 'Team') ?></strong>
                  <span class="rto-muted rto-small"><?= esc_html(rto_date($msg['created_at'], 'd M H:i')) ?></span>
                </div>
                <div class="rto-message-body"><?= nl2br(esc_html($msg['message'])) ?></div>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="rto-message-compose">
              <textarea id="msgText" class="rto-textarea" rows="3" placeholder="Type a message to our team…"></textarea>
              <button class="rto-btn rto-btn-primary" id="sendMsgBtn">Send Message</button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /.rto-order-detail-grid -->
  </main>

  <footer class="rto-client-footer">
    <p>© <?= date('Y') ?> <?= esc_html(get_option('rtoflow_company_name', 'RTOFLOW')) ?>.</p>
  </footer>
</div>

<?php if (\RTOFLOW_Razorpay::isEnabled()): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var leadId    = <?= (int)$lead['id'] ?>;
var ajaxUrl   = "<?= esc_js(admin_url('admin-ajax.php')) ?>";
var clientNonce = "<?= esc_js(wp_create_nonce('rto_client_pay')) ?>";
var uploadNonce = "<?= esc_js(wp_create_nonce('rto_client_upload')) ?>";
var msgNonce    = "<?= esc_js(wp_create_nonce('rto_client_msg')) ?>";
var crNonce     = "<?= esc_js(wp_create_nonce('rto_client_request')) ?>";
var razorpayKey = "<?= esc_js(\RTOFLOW\Config\Env::string('RAZORPAY_KEY','')) ?>";

// ENTERPRISE GAP FIX (Phase 1, item 2 — client self-service cancellation/refund request)
(function(){
  var openBtns = document.querySelectorAll('.cr-open');
  var form = document.getElementById('crForm');
  var buttons = document.getElementById('crButtons');
  var reasonBox = document.getElementById('crReason');
  var submitBtn = document.getElementById('crSubmit');
  var cancelBtn = document.getElementById('crCancel');
  var msg = document.getElementById('crMsg');
  var selectedType = null;

  openBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      selectedType = btn.dataset.type;
      buttons.style.display = 'none';
      form.style.display = 'block';
      reasonBox.value = '';
      reasonBox.focus();
    });
  });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){
    form.style.display = 'none';
    buttons.style.display = 'flex';
  });
  if (submitBtn) submitBtn.addEventListener('click', function(){
    var reason = reasonBox.value.trim();
    if (!reason) { alert('Please describe why you are requesting this.'); return; }
    submitBtn.disabled = true;
    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({
        action: 'rto_client', rto_area: 'client', rto_action: 'request_cancellation_or_refund',
        lead_id: leadId, request_type: selectedType, reason: reason, rto_nonce: crNonce,
      }),
    }).then(function(r){ return r.json(); }).then(function(r){
      msg.style.display = 'block';
      msg.style.color = r.success ? '#166534' : '#991B1B';
      msg.textContent = r.message || (r.success ? 'Submitted.' : 'Failed.');
      if (r.success) {
        form.style.display = 'none';
        // Server also blocks a duplicate pending request of the same type —
        // reflect that immediately here too, rather than letting the client
        // re-click the button and wait for the server to say no.
        var btnForType = document.querySelector('.cr-open[data-type="' + selectedType + '"]');
        if (btnForType) btnForType.remove();
        if (!document.querySelector('.cr-open')) buttons.style.display = 'none';
        else buttons.style.display = 'flex';
      } else {
        submitBtn.disabled = false;
      }
    }).catch(function(){
      msg.style.display = 'block'; msg.style.color = '#991B1B'; msg.textContent = 'Request failed. Please try again.';
      submitBtn.disabled = false;
    });
  });
})();

// Pay Now
var payBtn = document.getElementById('payNowBtn');
if(payBtn && razorpayKey) {
  payBtn.addEventListener('click', function(){
    fetch(ajaxUrl, {
      method:'POST',
      credentials:'same-origin',
      body: new URLSearchParams({action:'rto_client', rto_area:'client', rto_action:'initiate_pay', lead_id:leadId, rto_nonce:clientNonce})
    }).then(function(r){return r.json();}).then(function(r){
      if(!r.success){ alert(r.data.message||'Could not initiate payment.'); return; }
      var d = r.data;
      var opts = {
        key: razorpayKey,
        amount: d.amount,
        currency: d.currency||'INR',
        order_id: d.order_id,
        name: "<?= esc_js(get_option('rtoflow_company_name','RTOFLOW')) ?>",
        description: "Order <?= esc_js($lead['lead_number']) ?>",
        handler: function(resp){
          fetch(ajaxUrl, {
            method:'POST', credentials:'same-origin',
            body: new URLSearchParams({
              action:'rto_client', rto_area:'client', rto_action:'verify_pay',
              lead_id: leadId,
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature,
              rto_nonce: clientNonce
            })
          }).then(function(r){return r.json();}).then(function(r){
            if(r.success){ alert('Payment successful!'); location.reload(); }
            else { alert(r.data.message||'Payment could not be verified.'); }
          });
        },
        theme:{color:'#1E3A5F'}
      };
      new Razorpay(opts).open();
    }).catch(function(e){ alert('Error: '+e); });
  });
} else if(payBtn) {
  payBtn.textContent = 'Contact Support to Pay';
}

// Upload document
document.getElementById('uploadDocBtn').addEventListener('click', function(){
  var file = document.getElementById('docFile').files[0];
  if(!file){ alert('Please select a file.'); return; }
  var fd = new FormData();
  fd.append('action','rto_client'); fd.append('rto_area','client'); fd.append('rto_action','upload_doc');
  fd.append('lead_id', leadId); fd.append('rto_nonce', uploadNonce); fd.append('file', file);
  fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(function(r){
    var msg = document.getElementById('uploadMsg');
    msg.style.display='block';
    msg.className = r.success ? 'rto-msg rto-msg-success' : 'rto-msg rto-msg-error';
    msg.textContent = r.data ? (r.data.message||'Done') : 'Error';
    if(r.success) setTimeout(function(){ location.reload(); }, 1500);
  });
});

// Send message
document.getElementById('sendMsgBtn').addEventListener('click', function(){
  var txt = document.getElementById('msgText').value.trim();
  if(!txt){ return; }
  fetch(ajaxUrl,{
    method:'POST',credentials:'same-origin',
    body: new URLSearchParams({action:'rto_client', rto_area:'client', rto_action:'send_message', lead_id:leadId, message:txt, rto_nonce:msgNonce})
  }).then(r=>r.json()).then(function(r){
    if(r.success){ document.getElementById('msgText').value=''; location.reload(); }
    else { alert(r.data.message||'Failed to send.'); }
  });
});

// Scroll messages to bottom
var msgBox = document.getElementById('msgBox');
if(msgBox) msgBox.scrollTop = msgBox.scrollHeight;

// ENTERPRISE GAP FIX (critical missing functionality — rating widget wiring)
var ratingNonce = "<?= esc_js(wp_create_nonce('rto_client_action')) ?>";
var ratingStarsEl = document.getElementById('ratingStars');
if (ratingStarsEl) {
  var chosenScore = 0;
  var stars = ratingStarsEl.querySelectorAll('.rto-star');
  var submitBtn = document.getElementById('ratingSubmitBtn');
  function paintStars(score) {
    stars.forEach(function(s){ s.style.color = (parseInt(s.dataset.score,10) <= score) ? '#f5a623' : '#ccc'; });
  }
  stars.forEach(function(s){
    s.addEventListener('click', function(){
      chosenScore = parseInt(s.dataset.score,10);
      paintStars(chosenScore);
      submitBtn.disabled = false;
    });
  });
  submitBtn.addEventListener('click', function(){
    if (!chosenScore) return;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting…';
    fetch(ajaxUrl, {
      method:'POST', credentials:'same-origin',
      body: new URLSearchParams({
        action:'rto_client', rto_area:'client', rto_action:'submit_rating',
        lead_id:leadId, score:chosenScore,
        comment: document.getElementById('ratingComment').value,
        rto_nonce: ratingNonce
      })
    }).then(function(r){return r.json();}).then(function(r){
      if(r.success){ location.reload(); }
      else { alert(r.data.message||'Could not submit rating.'); submitBtn.disabled = false; submitBtn.textContent = 'Submit Rating'; }
    }).catch(function(){ submitBtn.disabled = false; submitBtn.textContent = 'Submit Rating'; });
  });
}
</script>
<!-- P3-JS-008: Razorpay moved before inline JS -->
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
</body>
</html>
