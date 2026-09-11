<?php
/** NAS Admin — Request Detail Page (matches Image 2 exactly) */
if (!defined('ABSPATH')) exit;

// Get booking ID from URL
$booking_id = (int) ($_GET['id'] ?? 0);
if (!$booking_id) {
    echo '<div class="nas-page-wrap"><div class="nas-alert nas-alert-error">No booking ID provided.</div></div>';
    return;
}

// Pre-load vendors and newspapers for selectors
global $wpdb;
$db      = \NAS\Core\Database::instance();
$vendors = $wpdb->get_results("SELECT id,name,phone FROM `{$db->prefix('vendors')}` WHERE is_active=1 ORDER BY name", OBJECT);
?>
<div class="nas-page-wrap" id="nas-request-detail-wrap" data-id="<?php echo $booking_id; ?>">

<!-- Page Header — matches Image 2 top bar -->
<div class="nas-page-header">
  <div class="nas-header-left">
    <a href="<?php echo esc_url($admin_url('requests')); ?>" class="nas-back-btn">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <h1>Booking: <strong id="nrd-uid">Loading...</strong></h1>
  </div>
  <div class="nas-page-actions" id="nrd-header-actions">
    <div class="nas-header-status-badges" id="nrd-status-badges"></div>
    <button class="nas-btn nas-btn-primary" id="nrd-gen-invoice" style="display:none">
      <i class="fa-solid fa-file-invoice"></i> Invoice PDF
    </button>
    <button class="nas-btn nas-btn-outline" id="nrd-gen-vendor-pdf" style="display:none">
      <i class="fa-solid fa-file-alt"></i> Vendor PDF
    </button>
  </div>
</div>

<!-- Loading State -->
<div id="nrd-loading" style="text-align:center;padding:60px">
  <div class="nas-spinner" style="width:40px;height:40px;margin:0 auto 16px"></div>
  <p>Loading booking details...</p>
</div>

<!-- Detail Grid — hidden until loaded -->
<div class="nas-detail-grid" id="nrd-detail-grid" style="display:none">

  <!-- ═══ LEFT COLUMN ═══════════════════════════════════════════════════ -->
  <div class="nas-detail-left">

    <!-- Client Information -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-user"></i> Client Information</h3></div>
      <div class="nas-info-grid">
        <span class="nas-info-label">Name</span>     <span id="nrd-client-name">—</span>
        <span class="nas-info-label">Phone</span>    <span id="nrd-client-phone">—</span>
        <span class="nas-info-label">Email</span>    <span id="nrd-client-email">—</span>
        <span class="nas-info-label">City</span>     <span id="nrd-client-city">—</span>
        <span class="nas-info-label">Company</span>  <span id="nrd-client-company" style="display:none">—</span>
        <span class="nas-info-label" id="lbl-company" style="display:none">Company</span>
        <span class="nas-info-label">GST No.</span>  <span id="nrd-client-gst">—</span>
      </div>
    </div>

    <!-- Booking Details -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-newspaper"></i> Booking Details</h3></div>
      <div class="nas-info-grid">
        <span class="nas-info-label">Category</span>     <span id="nrd-category">—</span>
        <span class="nas-info-label">Newspaper</span>    <span id="nrd-newspaper">—</span>
        <span class="nas-info-label">Edition</span>      <span id="nrd-edition">—</span>
        <span class="nas-info-label">Ad Type</span>      <span id="nrd-adtype">—</span>
        <span class="nas-info-label">Word Count</span>   <span id="nrd-wordcount">—</span>
        <span class="nas-info-label" id="nrd-size-label" style="display:none">Size</span>
        <span id="nrd-size" style="display:none">—</span>
        <span class="nas-info-label">Publish Date</span> <span id="nrd-pubdate">—</span>
        <span class="nas-info-label">Submitted</span>    <span id="nrd-submitted">—</span>
      </div>
    </div>

    <!-- Ad Content -->
    <div class="nas-card">
      <div class="nas-card-header">
        <h3><i class="fa-solid fa-pen"></i> Ad Content</h3>
        <button class="nas-btn nas-btn-sm nas-btn-primary" id="nrd-save-content">Save</button>
      </div>
      <div style="padding:16px 20px">
        <textarea id="nrd-ad-content" class="nas-textarea" rows="6"
          placeholder="Ad content will appear here..."></textarea>
        <div class="nas-wc-bar" style="margin-top:10px">
          <span>Words: <strong id="nrd-wc-count">0</strong></span>
          <div class="nas-preview-box" id="nrd-preview-box"></div>
        </div>
      </div>
    </div>

    <!-- Notes -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-note-sticky"></i> Notes</h3></div>
      <div class="nas-notes-grid">
        <div>
          <label>Admin Notes</label>
          <textarea id="nrd-notes-admin" class="nas-textarea" rows="3" placeholder="Internal notes (not visible to client)..."></textarea>
          <button class="nas-btn nas-btn-sm" onclick="nrdSaveNotes('admin',this)" data-loading-text="Saving…">Save Admin Note</button>
        </div>
        <div>
          <label>Vendor Notes</label>
          <textarea id="nrd-notes-vendor" class="nas-textarea" rows="3" placeholder="Notes for the vendor/newspaper..."></textarea>
          <button class="nas-btn nas-btn-sm" onclick="nrdSaveNotes('vendor',this)" data-loading-text="Saving…">Save Vendor Note</button>
        </div>
      </div>
    </div>

  </div><!-- .nas-detail-left -->

  <!-- ═══ RIGHT COLUMN ══════════════════════════════════════════════════ -->
  <div class="nas-detail-right">

    <!-- Status Control -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-traffic-light"></i> Status</h3></div>
      <div class="nas-status-buttons" id="nrd-status-buttons">
        <?php
        // TRACE: Status buttons use canonical BookingStatus constants.
        //        AdminModule::update_status() normalises legacy aliases → canonical, but we now send canonical directly.
        //        Precondition: admin has nas_manage_bookings cap (enforced by auth gate above).
        //        Postcondition: clicking a button calls nas_admin_update_status with the canonical status string.
        //        Edge cases: current status highlighted via data-current; rejected requires a reason modal.
        $st_icons = [
            'under_review'        => '🔍',
            'quotation_sent'      => '💰',
            'ready_to_process'    => '✅',
            'documents_received'  => '📄',
            'payment_received'    => '💳',
            'ad_processing'       => '🎨',
            'proof_ready'         => '🖼️',
            'submitted_to_pub'    => '📨',
            'published'           => '📰',
            'completed'           => '🎉',
            'rejected'            => '❌',
            'not_able_to_process' => '⛔',
        ];
        foreach( array_keys($st_icons) as $st ): ?>
        <button class="nas-status-btn" data-status="<?php echo $st; ?>"
                data-loading-text="Updating…"
                onclick="nrdUpdateStatus('<?php echo $st; ?>',this)">
          <?php echo ($st_icons[$st]??'').' '.ucwords(str_replace('_',' ',$st)); ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Pricing Panel -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Pricing</h3></div>

      <!-- AI Suggestion Box (shown when enough history) -->
      <div class="nas-ai-suggest-box" id="nrd-ai-suggest" style="display:none">
        <i class="fa-solid fa-lightbulb"></i>
        <strong>AI Suggestion</strong> <span id="nrd-ai-suggest-text"></span>
        <button class="nas-btn nas-btn-xs" id="nrd-apply-suggest">Apply</button>
      </div>

      <div class="nas-pricing-form">
        <div class="nas-form-row">
          <label>Client Price (₹)</label>
          <input type="number" id="nrd-client-price" class="nas-input" step="0.01" min="0" placeholder="0.00">
        </div>
        <div class="nas-form-row">
          <label>Vendor Cost (₹)</label>
          <input type="number" id="nrd-vendor-cost" class="nas-input" step="0.01" min="0" placeholder="0.00">
        </div>
        <div class="nas-form-row">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <label style="margin:0">GST</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:#6b7280;cursor:pointer">
              <input type="checkbox" id="nrd-gst-enabled" style="width:16px;height:16px;cursor:pointer" onchange="nrdToggleGST(this)">
              Apply GST
            </label>
          </div>
          <div id="nrd-gst-row" style="display:none;align-items:center;gap:8px">
            <input type="number" id="nrd-gst-pct" class="nas-input" step="0.01" value="18" style="flex:1">
            <span style="font-size:12px;color:#6b7280;white-space:nowrap">%</span>
          </div>
        </div>
        <div class="nas-pricing-calc" id="nrd-pricing-calc">
          <div class="nas-calc-row"><span>Profit</span><strong id="nrd-calc-profit">₹0.00</strong></div>
          <div class="nas-calc-row"><span>GST Amount</span><strong id="nrd-calc-gst">₹0.00</strong></div>
          <div class="nas-calc-row" id="nrd-margin-row"><span>Margin</span><strong id="nrd-calc-margin">0%</strong></div>
          <div class="nas-calc-row nap-total-row"><span>Total</span><strong id="nrd-calc-total">₹0.00</strong></div>
        </div>
        <button class="nas-btn nas-btn-primary" style="width:100%;justify-content:center" onclick="nrdSavePricing(this)" id="nrd-save-pricing-btn" data-loading-text="Saving…">
          <i class="fa-solid fa-save"></i> Save Pricing
        </button>
      </div>
    </div>

    <!-- Payment Status -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-credit-card"></i> Payment</h3></div>
      <div class="nas-payment-select">
        <select id="nrd-payment-status" class="nas-select">
          <option value="pending">Pending</option>
          <option value="partial">Partial</option>
          <option value="paid">Paid</option>
        </select>
        <input type="text" id="nrd-payment-ref" class="nas-input nas-input-sm" placeholder="Ref / UTR (optional)" style="flex:1.2">
        <button class="nas-btn nas-btn-sm nas-btn-primary" onclick="nrdSavePayment(this)" id="nrd-save-payment-btn" data-loading-text="Updating…">Update</button>
      </div>
    </div>

    <!-- Assign Vendor -->
    <div class="nas-card">
      <div class="nas-card-header"><h3><i class="fa-solid fa-truck"></i> Assign Vendor</h3></div>
      <div style="padding:16px 20px">
        <select id="nrd-vendor-select" class="nas-select" style="margin-bottom:12px">
          <option value="">-- Select Vendor --</option>
          <?php foreach ($vendors as $v): ?>
          <option value="<?php echo $v->id; ?>"><?php echo esc_html($v->name . ' (' . $v->phone . ')'); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="nas-btn nas-btn-primary" style="width:100%;justify-content:center" onclick="nrdAssignVendor(this)" id="nrd-assign-vendor-btn" data-loading-text="Assigning…">
          <i class="fa-solid fa-paper-plane"></i> Assign & Notify Vendor
        </button>
        <div class="nas-current-vendor" id="nrd-current-vendor" style="display:none"></div>
      </div>
    </div>

    <!-- Workflow History -->
    <div class="nas-card">
      <div class="nas-card-header">
        <h3><i class="fa-solid fa-clock-rotate-left"></i> Workflow History</h3>
        <button class="nas-btn nas-btn-xs nas-btn-outline" onclick="nrdToggleHistory()" id="nrd-history-toggle">Show</button>
      </div>
      <div id="nrd-history-list" style="display:none;padding:12px 16px;max-height:200px;overflow-y:auto"></div>
    </div>


  </div><!-- .nas-detail-right -->
</div><!-- .nas-detail-grid -->

<!-- ═══ FULL-WIDTH PREMIUM CHAT ════════════════════════════════════════════════ -->
<div class="nrd-chat-full" id="nrd-chat-full">

  <!-- Header -->
  <div class="nrd-chat-header">
    <div class="nrd-chat-header-left">
      <div class="nrd-chat-avatar" id="nrd-chat-avatar">C</div>
      <div>
        <div class="nrd-chat-client-name" id="nrd-chat-client-name">Client</div>
        <div class="nrd-chat-status">
          <span class="nrd-chat-online-dot"></span>
          <span>In-platform &amp; multi-channel messaging</span>
        </div>
      </div>
    </div>
    <div class="nrd-chat-header-right">
      <span class="nrd-chat-badge" id="nrd-chat-booking-ref"></span>
      <button class="nrd-chat-icon-btn" onclick="nrdRefreshChat()" title="Refresh messages">
        <i class="fa-solid fa-rotate-right"></i>
      </button>
    </div>
  </div>

  <!-- Messages -->
  <div class="nrd-chat-messages" id="nrd-chat-msgs">
    <div class="nrd-chat-loading">
      <div class="nrd-chat-spinner"></div>
      <span>Loading conversation…</span>
    </div>
  </div>

  <!-- Quick reply templates -->
  <div class="nrd-chat-templates">
    <span class="nrd-tpl-label">Quick replies:</span>
    <button class="nrd-tpl-btn" onclick="nrdInsertTemplate('Thank you for your booking! We are reviewing your requirements and will share the best quotation shortly.')">💬 Booking received</button>
    <button class="nrd-tpl-btn" onclick="nrdInsertTemplate('Your proof is ready. Please log in to your dashboard to review and approve it.')">🎨 Proof ready</button>
    <button class="nrd-tpl-btn" onclick="nrdInsertTemplate('Your advertisement has been published. We will share the tear sheet within 2–3 working days.')">📰 Published</button>
    <button class="nrd-tpl-btn" onclick="nrdInsertTemplate('Could you please share the payment confirmation so we can proceed with your booking?')">💳 Payment reminder</button>
  </div>

  <!-- Composer -->
  <div class="nrd-chat-composer-wrap">
    <div class="nrd-composer-textarea-wrap">
      <textarea
        id="nrd-chat-input"
        class="nrd-composer-textarea"
        placeholder="Type your message… (Enter sends, Shift+Enter = new line)"
        rows="3"
        maxlength="1000"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();nrdSendChat()}"
        oninput="document.getElementById('nrd-char-count').textContent=this.value.length+' / 1000'"
      ></textarea>
      <div class="nrd-composer-footer-meta">
        <span class="nrd-char-count" id="nrd-char-count">0 / 1000</span>
        <span class="nrd-composer-hint"><i class="fa-solid fa-circle-info"></i> Shift+Enter for new line</span>
      </div>
    </div>

    <div class="nrd-send-actions">

      <button class="nrd-send-btn nrd-send-primary" id="nrd-send-main" onclick="nrdSendChat()">
        <div class="nrd-send-btn-icon"><i class="fa-solid fa-paper-plane"></i></div>
        <div class="nrd-send-btn-text">
          <span class="nrd-send-label">Send Message</span>
          <span class="nrd-send-sub">Via platform chat</span>
        </div>
      </button>

      <button class="nrd-send-btn nrd-send-email" onclick="nrdSendVia('email')" id="nrd-send-email">
        <div class="nrd-send-btn-icon"><i class="fa-solid fa-envelope"></i></div>
        <div class="nrd-send-btn-text">
          <span class="nrd-send-label">Send via Email</span>
          <span class="nrd-send-sub" id="nrd-client-email-sub">Send to email</span>
        </div>
      </button>

      <button class="nrd-send-btn nrd-send-wa" onclick="nrdSendVia('whatsapp')" id="nrd-send-wa">
        <div class="nrd-send-btn-icon"><i class="fa-brands fa-whatsapp"></i></div>
        <div class="nrd-send-btn-text">
          <span class="nrd-send-label">Send via WhatsApp</span>
          <span class="nrd-send-sub" id="nrd-client-wa-sub">Open WhatsApp chat</span>
        </div>
      </button>

      <button class="nrd-send-btn nrd-send-sms" onclick="nrdSendVia('sms')" id="nrd-send-sms">
        <div class="nrd-send-btn-icon"><i class="fa-solid fa-comment-sms"></i></div>
        <div class="nrd-send-btn-text">
          <span class="nrd-send-label">Send via SMS</span>
          <span class="nrd-send-sub">To registered mobile</span>
        </div>
      </button>

    </div>
  </div>

</div><!-- /nrd-chat-full -->

</div><!-- .nas-page-wrap -->

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const bookingId = parseInt(document.getElementById('nas-request-detail-wrap').dataset.id);
let _booking = null;

const SYM = C.currency;
function money(n){ return SYM + parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function toast(msg, type='success'){ nasAdminToast(msg, type); }
function ajax(action, data){
  const fd = new FormData();
  fd.append('action', action); fd.append('nonce', C.nonce);
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const _ctrl=new AbortController();
  const _tid=setTimeout(()=>_ctrl.abort(),30000);
  return fetch(C.ajaxUrl, {method:'POST', body:fd, signal:_ctrl.signal})
    .then(r=>{clearTimeout(_tid);return r.json();})
    .catch(e=>{clearTimeout(_tid);if(e.name==='AbortError')throw new Error('Request timed out. Please try again.');throw e;});
}

// Load booking
ajax('nas_admin_get_booking', {id: bookingId}).then(res => {
  document.getElementById('nrd-loading').style.display = 'none';
  if (!res.success) { document.getElementById('nrd-detail-grid').innerHTML = `<div class="nas-alert nas-alert-error">${res.data?.message||'Failed to load'}</div>`; return; }
  document.getElementById('nrd-detail-grid').style.display = 'grid';
  populate(res.data);
});

function populate(b){
  _booking = b;

  // Header
  document.getElementById('nrd-uid').textContent = b.uid || ('#' + b.id);
  renderStatusBadges(b);

  // Client info
  document.getElementById('nrd-client-name').textContent  = b.client_name  || '—';
  document.getElementById('nrd-client-phone').textContent = b.client_phone || '—';
  document.getElementById('nrd-client-email').textContent = b.client_email || '—';
  // Update chat header with client info
  nrdUpdateChatHeader(b);
  document.getElementById('nrd-client-city').textContent  = b.city_name    || '—';
  document.getElementById('nrd-client-gst').textContent   = b.client_gst   || '—';
  if (b.client_company) {
    document.getElementById('nrd-client-company').textContent = b.client_company;
    document.getElementById('nrd-client-company').style.display = '';
    document.getElementById('lbl-company').style.display = '';
  }

  // Booking details
  document.getElementById('nrd-category').textContent  = b.category_name || '—';
  document.getElementById('nrd-newspaper').textContent = b.newspaper_name|| '—';
  document.getElementById('nrd-edition').textContent   = b.edition || 'Main Edition';
  document.getElementById('nrd-adtype').textContent    = (b.ad_type||'').replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase());
  document.getElementById('nrd-wordcount').textContent = b.word_count || 0;
  document.getElementById('nrd-pubdate').textContent   = b.publish_date || '—';
  document.getElementById('nrd-submitted').textContent = b.submitted_at || b.created_at || '—';
  if (b.ad_type !== 'classified_text' && b.width_cm) {
    document.getElementById('nrd-size-label').style.display = '';
    document.getElementById('nrd-size').style.display = '';
    document.getElementById('nrd-size').textContent = `${b.width_cm} × ${b.height_cm} cm`;
  }

  // Publish date urgent badge
  if (b.publish_date) {
    const days = Math.round((new Date(b.publish_date)-new Date())/86400000);
    if (days >= 0 && days <= 3) {
      document.getElementById('nrd-pubdate').innerHTML += ` <span class="nas-badge-urgent">In ${days} day(s)</span>`;
    }
  }

  // Ad content
  const ta = document.getElementById('nrd-ad-content');
  ta.value = b.ad_content || '';
  updateWC();
  document.getElementById('nrd-preview-box').textContent = (b.ad_content||'').substring(0,200);

  // Notes
  document.getElementById('nrd-notes-admin').value  = b.notes_admin  || '';
  document.getElementById('nrd-notes-vendor').value = b.notes_vendor || '';

  // Status buttons
  document.querySelectorAll('.nas-status-btn').forEach(btn => {
    btn.classList.toggle('nas-status-active', btn.dataset.status === b.status);
  });

  // Pricing
  document.getElementById('nrd-client-price').value = b.client_price || '';
  document.getElementById('nrd-vendor-cost').value  = b.vendor_cost  || '';
  var gstPct = parseFloat(b.gst_percentage || 0);
  var gstEnabled = gstPct > 0;
  document.getElementById('nrd-gst-pct').value = gstEnabled ? gstPct : 18;
  var gstChk = document.getElementById('nrd-gst-enabled');
  var gstRow = document.getElementById('nrd-gst-row');
  if (gstChk) {
    gstChk.checked = gstEnabled;
    if (gstRow) gstRow.style.display = gstEnabled ? 'flex' : 'none';
  }
  calcPricing();

  // AI suggestion
  if (b.ai_suggestion && b.ai_suggestion.samples >= 3) {
    const s = b.ai_suggestion;
    document.getElementById('nrd-ai-suggest').style.display = 'block';
    document.getElementById('nrd-ai-suggest-text').textContent =
      `Based on ${s.samples} similar orders: Client ${money(s.avg_client)} · Vendor ${money(s.avg_vendor)} · Margin ${parseFloat(s.avg_margin||0).toFixed(1)}%`;
    document.getElementById('nrd-apply-suggest').onclick = () => {
      document.getElementById('nrd-client-price').value = parseFloat(s.avg_client||0).toFixed(2);
      document.getElementById('nrd-vendor-cost').value  = parseFloat(s.avg_vendor||0).toFixed(2);
      calcPricing();
      toast('AI suggestion applied!');
    };
  }

  // Payment
  document.getElementById('nrd-payment-status').value = b.payment_status || 'pending';
  document.getElementById('nrd-payment-ref').value    = b.payment_ref    || '';

  // Vendor
  if (b.assigned_vendor_id) {
    document.getElementById('nrd-vendor-select').value = b.assigned_vendor_id;
    const cv = document.getElementById('nrd-current-vendor');
    cv.style.display = 'block';
    cv.innerHTML = `✅ Currently assigned: <strong>${b.vendor_name||'—'}</strong>`;
  }

  // PDF buttons
  document.getElementById('nrd-gen-invoice').style.display = '';
  document.getElementById('nrd-gen-vendor-pdf').style.display = '';

  // History
  if (b.workflow_history) {
    try {
      const history = JSON.parse(b.workflow_history);
      document.getElementById('nrd-history-list').innerHTML = history.reverse().map(h =>
        `<div style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px">
           <strong style="text-transform:capitalize">${(h.status||'').replace(/_/g,' ')}</strong>
           ${h.note ? `· ${h.note}` : ''}
           <span style="float:right;color:#94a3b8">${h.at||''}</span>
         </div>`
      ).join('');
    } catch(e) {}
  }
}

function renderStatusBadges(b){
  const statusColors = {booking_received:'#6b7280',under_review:'#f59e0b',quotation_sent:'#202C39',ready_to_process:'#10b981',documents_received:'#f97316',payment_received:'#f59e0b',ad_processing:'#06b6d4',proof_ready:'#7c3aed',submitted_to_pub:'#7c3aed',published:'#22c55e',completed:'#16a34a',rejected:'#ef4444',not_able_to_process:'#ef4444',cancelled:'#94a3b8'};
  const payColors = {pending:'#f59e0b',partial:'#3b82f6',paid:'#10b981'};
  const sc = statusColors[b.status]||'#6b7280';
  const pc = payColors[b.payment_status]||'#6b7280';
  document.getElementById('nrd-status-badges').innerHTML =
    `<span class="nas-badge" style="background:${sc}">${(b.status||'').replace(/_/g,' ')}</span>
     <span class="nas-badge" style="background:${pc}">${b.payment_status||''}</span>`;
}

// Auto-calc pricing
function calcPricing(){
  const cp      = parseFloat(document.getElementById('nrd-client-price').value)||0;
  const vc      = parseFloat(document.getElementById('nrd-vendor-cost').value)||0;
  const gstChk  = document.getElementById('nrd-gst-enabled');
  const gstOn   = gstChk ? gstChk.checked : false;
  const gp      = gstOn ? (parseFloat(document.getElementById('nrd-gst-pct').value)||0) : 0;
  const profit  = cp - vc;
  const gst     = gstOn ? cp*(gp/100) : 0;
  const total   = cp + gst;
  const margin  = cp > 0 ? ((profit/cp)*100).toFixed(1) : 0;
  document.getElementById('nrd-calc-profit').textContent = money(profit);
  document.getElementById('nrd-calc-gst').textContent    = money(gst);
  document.getElementById('nrd-calc-total').textContent  = money(total);
  document.getElementById('nrd-calc-margin').textContent = margin + '%';
  document.getElementById('nrd-pricing-calc').className = 'nas-pricing-calc' + (profit < 0 ? ' nas-calc-loss' : '');
}
['nrd-client-price','nrd-vendor-cost','nrd-gst-pct'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', calcPricing);
});

window.nrdToggleGST = function(checkbox) {
  var row = document.getElementById('nrd-gst-row');
  var inp = document.getElementById('nrd-gst-pct');
  if (checkbox.checked) {
    if (row) row.style.display = 'flex';
    if (inp && (!inp.value || parseFloat(inp.value) === 0)) inp.value = '18';
  } else {
    if (row) row.style.display = 'none';
    if (inp) inp.value = '0';
  }
  calcPricing();
};

// Word count
function updateWC(){
  const ta = document.getElementById('nrd-ad-content');
  const wc = ta.value.trim().split(/\s+/).filter(Boolean).length;
  document.getElementById('nrd-wc-count').textContent = wc;
  document.getElementById('nrd-preview-box').textContent = ta.value.substring(0,200);
}
document.getElementById('nrd-ad-content')?.addEventListener('input', updateWC);

// Status update
window.nrdUpdateStatus = async function(status, btn){
  const ok = await nasConfirm({icon:'🔄',title:'Update Status',message:'Set status to <strong>'+status.replace(/_/g,' ')+'</strong>?',confirmText:'Update',type:'primary'});
  if(!ok) return;
  if(btn) nasSetBtnLoading(btn,true);
  ajax('nas_admin_update_status',{booking_id:bookingId,status}).then(res=>{
    if(btn) nasSetBtnLoading(btn,false);
    if(res.success){
      nasAdminToast('✅ Status: '+status.replace(/_/g,' '),'success');
      document.querySelectorAll('.nas-status-btn').forEach(b=>b.classList.toggle('nas-status-active',b.dataset.status===status));
      if(_booking){_booking.status=status;renderStatusBadges(_booking);}
    } else nasAdminToast(res.data?.message||'Error','error');
  }).catch(()=>{if(btn)nasSetBtnLoading(btn,false);nasAdminToast('Network error','error');});
};

// Save pricing
window.nrdSavePricing = function(btn){
  btn=btn||document.getElementById('nrd-save-pricing-btn');
  if(btn)nasSetBtnLoading(btn,true);
  const _gstOn = document.getElementById('nrd-gst-enabled')?.checked;
  const _gstPct = _gstOn ? (document.getElementById('nrd-gst-pct').value||'18') : '0';
  ajax('nas_admin_update_pricing',{booking_id:bookingId,client_price:document.getElementById('nrd-client-price').value,vendor_cost:document.getElementById('nrd-vendor-cost').value,gst_percentage:_gstPct}).then(res=>{
    if(btn)nasSetBtnLoading(btn,false);
    if(res.success){const d=res.data;document.getElementById('nrd-calc-profit').textContent=d.profit;document.getElementById('nrd-calc-gst').textContent=d.gst_amt;document.getElementById('nrd-calc-total').textContent=d.total;document.getElementById('nrd-calc-margin').textContent=d.margin;nasAdminToast('💰 Pricing saved! Margin: '+d.margin,'success');}
    else nasAdminToast(res.data?.message||'Error','error');
  }).catch(()=>{if(btn)nasSetBtnLoading(btn,false);nasAdminToast('Network error','error');});
};

// Save notes
window.nrdSaveNotes = function(type,btn){
  const note=document.getElementById('nrd-notes-'+type)?.value||'';
  if(btn)nasSetBtnLoading(btn,true);
  ajax('nas_admin_save_notes',{booking_id:bookingId,note_type:type,note}).then(res=>{
    if(btn)nasSetBtnLoading(btn,false);
    nasAdminToast(res.success?'📝 Note saved':'Error saving note',res.success?'success':'error');
  }).catch(()=>{if(btn)nasSetBtnLoading(btn,false);nasAdminToast('Network error','error');});
};

// Save content
document.getElementById('nrd-save-content')?.addEventListener('click', () => {
  ajax('nas_admin_save_content', {
    booking_id: bookingId,
    ad_content:  document.getElementById('nrd-ad-content').value,
  }).then(res => {
    if (res.success) { toast('Content saved. Words: ' + res.data.word_count); document.getElementById('nrd-wc-count').textContent = res.data.word_count; }
    else toast('Error saving content', 'error');
  });
});

// Save payment
window.nrdSavePayment = function(btn){
  btn=btn||document.getElementById('nrd-save-payment-btn');
  if(btn)nasSetBtnLoading(btn,true);
  ajax('nas_admin_save_payment',{booking_id:bookingId,payment_status:document.getElementById('nrd-payment-status').value,payment_ref:document.getElementById('nrd-payment-ref')?.value||''}).then(res=>{
    if(btn)nasSetBtnLoading(btn,false);
    nasAdminToast(res.success?'💳 Payment updated':'Error',res.success?'success':'error');
    if(res.success&&_booking){_booking.payment_status=document.getElementById('nrd-payment-status').value;renderStatusBadges(_booking);}
  }).catch(()=>{if(btn)nasSetBtnLoading(btn,false);nasAdminToast('Network error','error');});
};

// Assign vendor
window.nrdAssignVendor = async function(btn){
  const sel=document.getElementById('nrd-vendor-select');
  const vid=sel?.value;
  if(!vid){nasAdminToast('Please select a vendor','error');return;}
  const vName=sel.options[sel.selectedIndex]?.text||'vendor';
  const ok=await nasConfirm({icon:'📤',title:'Assign Vendor',message:'Assign <strong>'+vName+'</strong> and send them a notification?',confirmText:'Assign',type:'success'});
  if(!ok)return;
  btn=btn||document.getElementById('nrd-assign-vendor-btn');
  if(btn)nasSetBtnLoading(btn,true);
  ajax('nas_admin_assign_vendor',{booking_id:bookingId,vendor_id:vid}).then(res=>{
    if(btn)nasSetBtnLoading(btn,false);
    nasAdminToast(res.success?'📤 '+(res.data?.message||'Vendor assigned'):'Error assigning vendor',res.success?'success':'error');
    if(res.success){const cv=document.getElementById('nrd-current-vendor');if(cv){cv.style.display='flex';cv.innerHTML='<i class="fa-solid fa-circle-check"></i> Currently assigned: <strong>'+vName+'</strong>';}}
  }).catch(()=>{if(btn)nasSetBtnLoading(btn,false);nasAdminToast('Network error','error');});
};

// PDF
document.getElementById('nrd-gen-invoice')?.addEventListener('click', () => {
  window.open(`${C.ajaxUrl}?action=nas_admin_gen_pdf&type=invoice&id=${bookingId}&nonce=${C.pdfNonce}`, '_blank');
});
document.getElementById('nrd-gen-vendor-pdf')?.addEventListener('click', () => {
  window.open(`${C.ajaxUrl}?action=nas_admin_gen_pdf&type=vendor&id=${bookingId}&nonce=${C.pdfNonce}`, '_blank');
});

// Toggle history
window.nrdToggleHistory = function(){
  const el = document.getElementById('nrd-history-list');
  const btn = document.getElementById('nrd-history-toggle');
  const isHidden = el.style.display === 'none';
  el.style.display = isHidden ? 'block' : 'none';
  btn.textContent = isHidden ? 'Hide' : 'Show';
};


// ── Premium Full-Width Chat ────────────────────────────────────────────────────
var nrdChatTimer = null;

// Update header with booking/client info (called after booking loads)
function nrdUpdateChatHeader(b) {
  var nameEl = document.getElementById('nrd-chat-client-name');
  var refEl  = document.getElementById('nrd-chat-booking-ref');
  var avEl   = document.getElementById('nrd-chat-avatar');
  var emailSub = document.getElementById('nrd-client-email-sub');
  var waSub    = document.getElementById('nrd-client-wa-sub');
  if (nameEl) nameEl.textContent = b.client_name || 'Client';
  if (refEl)  refEl.textContent  = '#'+(b.uid||b.booking_uid||b.id||'—');
  if (avEl)   avEl.textContent   = (b.client_name||'C').charAt(0).toUpperCase();
  if (emailSub && b.client_email) emailSub.textContent = b.client_email;
  if (waSub && b.client_phone)    waSub.textContent    = b.client_phone;
}

function nrdRefreshChat() {
  ajax('nas_get_messages',{booking_id:bookingId}).then(function(res){
    var area = document.getElementById('nrd-chat-msgs');
    if (!area) return;
    var msgs = res.messages || [];

    if (!msgs.length) {
      area.innerHTML = '<div class="nrd-chat-empty"><i class="fa-solid fa-comment-dots"></i><p>No messages yet. Be the first to send a message.</p></div>';
      return;
    }

    var atBot = area.scrollHeight - area.scrollTop - area.clientHeight < 100;

    area.innerHTML = msgs.map(function(m) {
      var mine = parseInt(m.sender_id) === parseInt(C.userId);
      var t    = new Date(m.created_at).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
      var d    = new Date(m.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short'});
      var txt  = (m.message||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
      var ch   = m.channel || 'platform';
      var chIcon = {platform:'💬',email:'✉️',whatsapp:'📱',sms:'📨'}[ch] || '💬';
      var chLabel = {platform:'Platform',email:'Email',whatsapp:'WhatsApp',sms:'SMS'}[ch] || ch;

      return '<div class="nrd-bubble '+(mine?'nrd-bubble-mine':'nrd-bubble-theirs')+'">'+
        '<div class="nrd-bubble-name">'+(mine?'You (Admin)':escH(m.sender_name||'Client'))+'</div>'+
        '<div class="nrd-bubble-text">'+txt+'</div>'+
        '<div class="nrd-bubble-meta">'+
          d+' · '+t+' '+
          '<span class="nrd-bubble-channel">'+chIcon+' '+chLabel+'</span>'+
        '</div>'+
      '</div>';
    }).join('');

    if (atBot) area.scrollTop = area.scrollHeight;
  }).catch(function(){});
}

// Insert quick template text
window.nrdInsertTemplate = function(text) {
  var inp = document.getElementById('nrd-chat-input');
  if (!inp) return;
  inp.value = text;
  inp.focus();
  document.getElementById('nrd-char-count').textContent = text.length + ' / 1000';
  inp.setSelectionRange(text.length, text.length);
};

// Primary platform send
window.nrdSendChat = function() {
  var inp = document.getElementById('nrd-chat-input');
  var msg = inp?.value.trim();
  if (!msg) { nasAdminToast('Please type a message first.','warning'); return; }
  var btn = document.getElementById('nrd-send-main');
  if (btn) { btn.classList.add('sending'); }
  ajax('nas_send_message',{booking_id:bookingId, message:msg, channel:'platform'}).then(function(){
    inp.value = '';
    document.getElementById('nrd-char-count').textContent = '0 / 1000';
    if (btn) { btn.classList.remove('sending'); btn.classList.add('sent'); setTimeout(function(){btn.classList.remove('sent');},1500); }
    nrdRefreshChat();
  }).catch(function(e){
    if (btn) btn.classList.remove('sending');
    nasAdminToast(e.message||'Send failed','error');
  });
};

// Multi-channel send
window.nrdSendVia = function(channel) {
  var inp  = document.getElementById('nrd-chat-input');
  var msg  = inp?.value.trim();
  if (!msg) { nasAdminToast('Please type a message before choosing a channel.','warning'); return; }

  var btnMap = {email:'nrd-send-email', whatsapp:'nrd-send-wa', sms:'nrd-send-sms'};
  var btn    = document.getElementById(btnMap[channel]);
  if (btn) btn.classList.add('sending');

  // WhatsApp: open deep link (no server call needed)
  if (channel === 'whatsapp') {
    var phone = document.getElementById('nrd-client-phone')?.textContent?.replace(/\D/g,'') || '';
    if (!phone) { nasAdminToast('No client phone number on this booking.','error'); if(btn)btn.classList.remove('sending'); return; }
    var waUrl = 'https://wa.me/91' + phone + '?text=' + encodeURIComponent(msg);
    window.open(waUrl,'_blank','noopener');
    // Log it as sent
    ajax('nas_send_message',{booking_id:bookingId, message:'[WhatsApp] ' + msg, channel:'whatsapp'}).then(function(){
      inp.value=''; document.getElementById('nrd-char-count').textContent='0 / 1000';
      if(btn){btn.classList.remove('sending');btn.classList.add('sent');setTimeout(function(){btn.classList.remove('sent');},1500);}
      nrdRefreshChat();
    }).catch(function(){if(btn)btn.classList.remove('sending');});
    return;
  }

  // Email / SMS: server-side send
  ajax('nas_admin_send_client_message',{
    booking_id: bookingId,
    message:    msg,
    channel:    channel
  }).then(function(d){
    inp.value=''; document.getElementById('nrd-char-count').textContent='0 / 1000';
    if(btn){btn.classList.remove('sending');btn.classList.add('sent');setTimeout(function(){btn.classList.remove('sent');},1500);}
    nasAdminToast((channel==='email'?'📧 Email':'📨 SMS')+' sent to client!','success');
    nrdRefreshChat();
  }).catch(function(e){
    if(btn) btn.classList.remove('sending');
    nasAdminToast(e.message||channel+' send failed','error');
  });
};

// Start chat polling
nrdRefreshChat();
nrdChatTimer = setInterval(nrdRefreshChat, 6000);

})();
</script>
