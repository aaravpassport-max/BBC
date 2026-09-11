<?php
/**
 * NAS Admin — Vendor Payout Management
 * Track outstanding payouts, mark as paid, generate statements
 */
if (!defined('ABSPATH')) exit;
$db  = \NAS\Core\Database::instance();
$cfg = \NAS\Core\Config::instance();
$sym = $cfg->get('currency_symbol','₹');

// Summary stats
$total_owed    = (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM {$db->t('bookings')} WHERE vendor_payment_status='pending' AND vendor_cost>0 AND status NOT IN ('rejected','cancelled')");
$total_paid    = (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM {$db->t('bookings')} WHERE vendor_payment_status='paid' AND vendor_cost>0");
$vendors_owed  = (int)$db->scalar("SELECT COUNT(DISTINCT assigned_vendor_id) FROM {$db->t('bookings')} WHERE vendor_payment_status='pending' AND vendor_cost>0 AND assigned_vendor_id>0");

// Per-vendor summary
$vendor_summary = $db->select(
    "SELECT v.id AS vendor_id, v.name AS vendor_name, v.phone, v.email, v.bank_details,
            COUNT(b.id) AS booking_count,
            COALESCE(SUM(CASE WHEN b.vendor_payment_status='pending' THEN b.vendor_cost ELSE 0 END),0) AS amount_due,
            COALESCE(SUM(CASE WHEN b.vendor_payment_status='paid'    THEN b.vendor_cost ELSE 0 END),0) AS amount_paid,
            MAX(b.updated_at) AS last_booking
     FROM {$db->t('vendors')} v
     JOIN {$db->t('bookings')} b ON b.assigned_vendor_id = v.id AND b.vendor_cost > 0
     WHERE b.status NOT IN ('rejected','cancelled')
     GROUP BY v.id ORDER BY amount_due DESC"
) ?: [];

$mon = (float)$db->scalar("SELECT COALESCE(SUM(vendor_cost),0) FROM {$db->t('bookings')} WHERE vendor_payment_status='pending' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())");
?>
<style>
.nas-vp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px}
.nas-vp-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px;position:relative;overflow:hidden}
.nas-vp-stat-accent{position:absolute;top:0;left:0;right:0;height:3px}
.nas-vp-stat-val{font-size:28px;font-weight:800;color:#0f172a;margin-bottom:4px}
.nas-vp-stat-lbl{font-size:12px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.nas-vp-vendor-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:12px;transition:border-color .15s}
.nas-vp-vendor-card:hover{border-color:#2A8AFA}
.nas-vp-vendor-head{display:flex;align-items:center;gap:16px;padding:16px 20px;cursor:pointer;flex-wrap:wrap}
.nas-vp-vendor-avatar{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#2A8AFA,#202C39);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff;flex-shrink:0}
.nas-vp-vendor-info{flex:1;min-width:180px}
.nas-vp-vendor-name{font-size:14px;font-weight:700;color:#0f172a}
.nas-vp-vendor-contact{font-size:12px;color:#64748b}
.nas-vp-vendor-amounts{display:flex;gap:20px;flex-wrap:wrap}
.nas-vp-amount-due{font-size:18px;font-weight:800;color:#dc2626}
.nas-vp-amount-paid{font-size:14px;color:#059669;font-weight:600}
.nas-vp-amount-label{font-size:11px;color:#94a3b8;font-weight:600}
.nas-vp-actions{display:flex;gap:8px;flex-wrap:wrap}
.nas-vp-vendor-body{display:none;border-top:1px solid #f1f5f9;padding:0}
.nas-vp-vendor-body.open{display:block}
.nas-vp-booking-row{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:12px;align-items:center;padding:12px 20px;border-bottom:1px solid #f8fafc;font-size:13px}
.nas-vp-booking-row:last-child{border-bottom:none}
.nas-vp-booking-uid{font-family:monospace;color:#2A8AFA;font-size:12px;font-weight:700}
.nas-vp-booking-info{color:#374151}
.nas-vp-mark-paid-row{padding:14px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.nas-vp-pay-input{padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;flex:1;min-width:120px}
</style>

<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-money-bill-transfer"></i> Vendor Payouts</h1>
  <div style="display:flex;gap:10px">
    <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="vpExportCSV()"><i class="fa-solid fa-download"></i> Export</button>
  </div>
</div>

<!-- Summary stats -->
<div class="nas-vp-summary">
  <div class="nas-vp-stat">
    <div class="nas-vp-stat-accent" style="background:#dc2626"></div>
    <div class="nas-vp-stat-val" style="color:#dc2626"><?= $sym.number_format($total_owed,2) ?></div>
    <div class="nas-vp-stat-lbl">Total Outstanding</div>
  </div>
  <div class="nas-vp-stat">
    <div class="nas-vp-stat-accent" style="background:#d97706"></div>
    <div class="nas-vp-stat-val" style="color:#d97706"><?= $sym.number_format($mon,2) ?></div>
    <div class="nas-vp-stat-lbl">Due This Month</div>
  </div>
  <div class="nas-vp-stat">
    <div class="nas-vp-stat-accent" style="background:#059669"></div>
    <div class="nas-vp-stat-val" style="color:#059669"><?= $sym.number_format($total_paid,2) ?></div>
    <div class="nas-vp-stat-lbl">Total Paid (All Time)</div>
  </div>
  <div class="nas-vp-stat">
    <div class="nas-vp-stat-accent" style="background:#2A8AFA"></div>
    <div class="nas-vp-stat-val" style="color:#2A8AFA"><?= $vendors_owed ?></div>
    <div class="nas-vp-stat-lbl">Vendors Owed</div>
  </div>
</div>

<?php if(empty($vendor_summary)): ?>
<div style="text-align:center;padding:60px 20px;color:#94a3b8">
  <div style="font-size:48px;margin-bottom:12px">💰</div>
  <h3 style="font-size:18px;font-weight:700;color:#374151;margin-bottom:6px">No payout records yet</h3>
  <p>Vendor payout data appears once bookings are assigned to vendors and vendor costs are set.</p>
</div>
<?php else: ?>

<!-- Search & filter -->
<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <input type="text" id="vp-search" placeholder="Search vendor…" class="nas-input" style="max-width:280px;flex:1" oninput="vpFilter()">
  <select id="vp-filter" class="nas-select nas-select--sm" onchange="vpFilter()">
    <option value="">All Vendors</option>
    <option value="due">With Outstanding Balance</option>
    <option value="clear">Fully Paid</option>
  </select>
</div>

<!-- Vendor payout cards -->
<div id="vp-vendor-list">
<?php foreach($vendor_summary as $v):
  $has_due = (float)$v['amount_due'] > 0;
?>
<div class="nas-vp-vendor-card" data-search="<?= esc_attr(strtolower($v['vendor_name'].$v['phone'])) ?>" data-due="<?= $has_due ? 'due' : 'clear' ?>">
  <div class="nas-vp-vendor-head" onclick="vpToggle(this)">
    <div class="nas-vp-vendor-avatar"><?= strtoupper(substr($v['vendor_name'],0,1)) ?></div>
    <div class="nas-vp-vendor-info">
      <div class="nas-vp-vendor-name"><?= esc_html($v['vendor_name']) ?></div>
      <div class="nas-vp-vendor-contact"><?= esc_html($v['phone']) ?> <?= $v['email']?'· '.esc_html($v['email']):'' ?></div>
    </div>
    <div class="nas-vp-vendor-amounts">
      <div style="text-align:right">
        <div class="nas-vp-amount-label">Outstanding</div>
        <div class="nas-vp-amount-due" style="<?= !$has_due ? 'color:#059669' : '' ?>">
          <?= $sym.number_format((float)$v['amount_due'],2) ?>
        </div>
      </div>
      <div style="text-align:right">
        <div class="nas-vp-amount-label">Total Paid</div>
        <div class="nas-vp-amount-paid"><?= $sym.number_format((float)$v['amount_paid'],2) ?></div>
      </div>
      <div style="text-align:right">
        <div class="nas-vp-amount-label">Bookings</div>
        <div style="font-size:16px;font-weight:700;color:#374151"><?= (int)$v['booking_count'] ?></div>
      </div>
    </div>
    <div class="nas-vp-actions">
      <?php if($has_due): ?>
      <button class="nas-btn nas-btn-sm" style="background:#059669;color:#fff" onclick="event.stopPropagation();vpMarkAllPaid(<?= (int)$v['vendor_id'] ?>,this)">
        <i class="fa-solid fa-check"></i> Mark All Paid
      </button>
      <?php endif; ?>
      <button class="nas-btn nas-btn-sm nas-btn-ghost" onclick="event.stopPropagation();vpDownloadStatement(<?= (int)$v['vendor_id'] ?>)">
        <i class="fa-solid fa-file-pdf"></i> Statement
      </button>
    </div>
    <i class="fa-solid fa-chevron-down" style="color:#94a3b8;font-size:12px;transition:transform .2s" class="vp-chevron"></i>
  </div>

  <div class="nas-vp-vendor-body" id="vp-body-<?= (int)$v['vendor_id'] ?>">
    <!-- Bank details -->
    <?php if(!empty($v['bank_details'])): ?>
    <div style="padding:12px 20px;background:#f0fdf4;border-bottom:1px solid #e2e8f0;font-size:12px;color:#166534">
      <i class="fa-solid fa-building-columns"></i> <strong>Bank:</strong> <?= esc_html($v['bank_details']) ?>
    </div>
    <?php else: ?>
    <div style="padding:10px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:12px;color:#92400e">
      <i class="fa-solid fa-triangle-exclamation"></i> Bank details not set for this vendor. Ask them to update their profile.
    </div>
    <?php endif; ?>

    <!-- Booking list (loaded via AJAX on expand) -->
    <div id="vp-bookings-<?= (int)$v['vendor_id'] ?>">
      <div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">Click to expand for bookings</div>
    </div>

    <!-- Mark paid form -->
    <?php if($has_due): ?>
    <div class="nas-vp-mark-paid-row">
      <input type="text" class="nas-vp-pay-input" id="vp-ref-<?= (int)$v['vendor_id'] ?>" placeholder="Payment reference / UTR (optional)">
      <select class="nas-vp-pay-input" id="vp-method-<?= (int)$v['vendor_id'] ?>" style="flex:0.5;min-width:100px">
        <option value="bank_transfer">Bank Transfer</option>
        <option value="upi">UPI</option>
        <option value="cash">Cash</option>
        <option value="cheque">Cheque</option>
      </select>
      <input type="number" class="nas-vp-pay-input" id="vp-amount-<?= (int)$v['vendor_id'] ?>" placeholder="Amount" value="<?= number_format((float)$v['amount_due'],2,'.','') ?>" step="0.01" min="0.01" style="flex:0.5;min-width:100px">
      <button class="nas-btn nas-btn-primary nas-btn-sm" onclick="vpRecordPayment(<?= (int)$v['vendor_id'] ?>,this)">
        <i class="fa-solid fa-circle-check"></i> Record Payment
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
</div>

<script>
var VPC = JSON.parse(document.getElementById('nas-admin-config').textContent);
var sym = VPC.currency || '₹';
function money(n){return sym+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

window.vpToggle = function(head) {
  var card = head.parentElement;
  var body = card.querySelector('.nas-vp-vendor-body');
  var chevron = head.querySelector('.fa-chevron-down');
  var vendorId = body?.id?.replace('vp-body-','');
  body.classList.toggle('open');
  if (chevron) chevron.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
  // Load bookings on first open
  if (body.classList.contains('open') && vendorId) vpLoadBookings(parseInt(vendorId));
};

function vpLoadBookings(vendorId) {
  var el = document.getElementById('vp-bookings-'+vendorId);
  if (!el || el.dataset.loaded) return;
  el.dataset.loaded = '1';
  el.innerHTML = '<div style="padding:20px;text-align:center"><div class="nas-spinner" style="margin:0 auto"></div></div>';
  var fd=new FormData();fd.append('action','nas_admin_get_vendor_payout_bookings');fd.append('nonce',VPC.nonce);fd.append('vendor_id',vendorId);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(VPC.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(function(res){
    var bks=res.data?.bookings||[];
    if(!bks.length){el.innerHTML='<div style="padding:20px;color:#94a3b8;font-size:13px;text-align:center">No bookings found for this vendor.</div>';return;}
    el.innerHTML='<table style="width:100%;border-collapse:collapse;font-size:12px">'+
      '<thead><tr style="background:#f8fafc"><th style="padding:8px 20px;text-align:left;font-weight:700">Order ID</th><th style="padding:8px;text-align:left">Client</th><th style="padding:8px">Newspaper</th><th style="padding:8px;text-align:right">Vendor Cost</th><th style="padding:8px">Status</th><th style="padding:8px">Payment</th></tr></thead>'+
      '<tbody>'+bks.map(function(b){
        var payStatus=b.vendor_payment_status==='paid'
          ?'<span style="color:#059669;font-weight:700">✅ Paid</span>'
          :'<span style="color:#dc2626;font-weight:700">⏳ Pending</span>';
        return'<tr style="border-bottom:1px solid #f1f5f9">'+
          '<td style="padding:8px 20px;font-family:monospace;color:#2A8AFA">'+esc(b.uid)+'</td>'+
          '<td style="padding:8px">'+esc(b.client_name||'—')+'</td>'+
          '<td style="padding:8px;color:#64748b">'+esc(b.newspaper_name||'—')+'</td>'+
          '<td style="padding:8px;text-align:right;font-weight:700">'+money(b.vendor_cost)+'</td>'+
          '<td style="padding:8px"><span style="font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:99px">'+esc(b.status?.replace(/_/g,' ')||'—')+'</span></td>'+
          '<td style="padding:8px">'+payStatus+'</td>'+
          '</tr>';
      }).join('')+'</tbody></table>';
  });
}

window.vpMarkAllPaid = function(vendorId, btn) {
  if (!confirm('Mark all outstanding bookings for this vendor as paid?')) return;
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  var fd=new FormData();fd.append('action','nas_admin_mark_vendor_all_paid');fd.append('nonce',VPC.nonce);fd.append('vendor_id',vendorId);
  var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
  fetch(VPC.ajaxUrl,{method:'POST',body:fd,signal:_nc2.signal}).then(r=>r.json()).then(function(r){
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Mark All Paid';
    if(r.success){if(window.nasToast)nasToast.show('All bookings marked as paid!','success');else alert('Marked as paid!');location.reload();}
    else alert(r.data?.message||'Failed');
  });
};

window.vpRecordPayment = function(vendorId, btn) {
  var ref    = document.getElementById('vp-ref-'+vendorId)?.value.trim();
  var method = document.getElementById('vp-method-'+vendorId)?.value;
  var amount = parseFloat(document.getElementById('vp-amount-'+vendorId)?.value||0);
  if (!amount||amount<=0){alert('Enter a valid payment amount.');return;}
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  var fd=new FormData();fd.append('action','nas_admin_record_vendor_payment');fd.append('nonce',VPC.nonce);
  fd.append('vendor_id',vendorId);fd.append('amount',amount);fd.append('payment_ref',ref);fd.append('payment_method',method);
  var _nc3=new AbortController();setTimeout(function(){_nc3.abort();},30000);
  fetch(VPC.ajaxUrl,{method:'POST',body:fd,signal:_nc3.signal}).then(r=>r.json()).then(function(r){
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Record Payment';
    if(r.success){if(window.nasToast)nasToast.show('Payment recorded!','success');else alert('Recorded!');location.reload();}
    else alert(r.data?.message||'Failed');
  });
};

window.vpDownloadStatement = function(vendorId) {
  window.open(VPC.ajaxUrl+'?action=nas_admin_vendor_statement&vendor_id='+vendorId+'&nonce='+VPC.nonce,'_blank');
};

window.vpFilter = function() {
  var q = document.getElementById('vp-search').value.toLowerCase();
  var f = document.getElementById('vp-filter').value;
  document.querySelectorAll('.nas-vp-vendor-card').forEach(function(card){
    var matchQ = !q || card.dataset.search?.includes(q);
    var matchF = !f || card.dataset.due === f;
    card.style.display = (matchQ && matchF) ? '' : 'none';
  });
};

window.vpExportCSV = function() {
  var rows=[['Vendor','Phone','Bookings','Amount Due','Amount Paid']];
  document.querySelectorAll('.nas-vp-vendor-card').forEach(function(card){
    var cols=card.querySelectorAll('.nas-vp-vendor-name,.nas-vp-vendor-contact,.nas-vp-stat-val');
    var name=card.querySelector('.nas-vp-vendor-name')?.textContent||'';
    var phone=card.querySelector('.nas-vp-vendor-contact')?.textContent.split('·')[0].trim()||'';
    var bkCount=card.querySelectorAll('.nas-vp-booking-row')?.length||0;
    var due=card.querySelector('.nas-vp-amount-due')?.textContent||'0';
    var paid=card.querySelector('.nas-vp-amount-paid')?.textContent||'0';
    rows.push([name,phone,bkCount,due,paid]);
  });
  var csv=rows.map(r=>r.map(v=>'"'+(v+'').replace(/"/g,'""')+'"').join(',')).join('\n');
  var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
  a.download='vendor-payouts-'+new Date().toISOString().slice(0,10)+'.csv';a.click();
};
</script>
