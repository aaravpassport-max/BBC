<?php if ( ! defined( 'ABSPATH' ) ) exit;
$booking_id = (int) ( $_GET['booking_id'] ?? 0 );
$db         = \NAS\Core\Database::instance();
$cfg        = \NAS\Core\Config::instance();

if ( ! $booking_id ) { wp_safe_redirect( home_url('/') ); exit; }  // fixed: was wp_redirect() without exit

$booking = $db->row(
    "SELECT b.*, cl.name as client_name, cl.email as client_email, cl.phone as client_phone,
            n.name as newspaper_name, cat.name as category_name, ci.name as city_name
     FROM {$db->t('bookings')} b
     LEFT JOIN {$db->t('clients')} cl ON cl.id = b.client_id
     LEFT JOIN {$db->t('newspapers')} n ON n.id = b.newspaper_id
     LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
     LEFT JOIN {$db->t('cities')} ci ON ci.id = b.city_id
     WHERE b.id = %d",
    $booking_id
);
if ( ! $booking ) { echo '<p>Booking not found.</p>'; return; }

$brand_color   = $cfg->get('brand_primary_color','#1A3A5C');
$brand_name    = $cfg->get('brand_name','NewspaperAds');
$logo_url      = $cfg->get('logo_url','');
$razorpay_key  = $cfg->get('razorpay_key_id','');
$payu_enabled  = (bool) $cfg->get('payu_merchant_key','');
$stripe_key    = $cfg->get('stripe_publishable_key','');
$currency_sym  = $cfg->get('currency_symbol','₹');
$gst_rate      = (float) $cfg->get('gst_percentage',18);
$amount        = (float) $booking['total_amount'];
$nonce         = wp_create_nonce('nas_action');
?>
<link rel="stylesheet" href="<?php echo NAS_ASSETS; ?>css/nas-core.css">
<style>
.nas-checkout{max-width:900px;margin:40px auto;padding:0 16px;font-family:'Inter','Segoe UI',sans-serif}
.nas-checkout-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media(max-width:768px){.nas-checkout-grid{grid-template-columns:1fr}}
.nas-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.nas-card h3{margin:0 0 16px;color:<?php echo esc_js($brand_color); ?>;font-size:16px;font-weight:600;border-bottom:1px solid #e2e8f0;padding-bottom:12px}
.nas-order-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;color:#374151}
.nas-order-row.total{font-weight:700;font-size:16px;color:#0f172a;border-bottom:none;padding-top:12px}
.nas-gateway-list{display:flex;flex-direction:column;gap:10px}
.nas-gw{border:2px solid #e2e8f0;border-radius:10px;padding:16px 20px;cursor:pointer;display:flex;align-items:center;gap:12px;transition:all .2s}
.nas-gw:hover,.nas-gw.selected{border-color:<?php echo esc_js($brand_color); ?>;background:#f0f7ff}
.nas-gw input[type=radio]{accent-color:<?php echo esc_js($brand_color); ?>}
.nas-gw-label{font-weight:600;font-size:14px;color:#1e293b}
.nas-gw-desc{font-size:12px;color:#64748b}
.nas-pay-btn{width:100%;padding:16px;background:<?php echo esc_js($brand_color); ?>;color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;margin-top:16px;transition:opacity .2s}
.nas-pay-btn:hover{opacity:.9}
.nas-pay-btn:disabled{opacity:.5;cursor:not-allowed}
.nas-secure-note{text-align:center;font-size:12px;color:#94a3b8;margin-top:12px}
.nas-logo{height:36px;margin-bottom:16px}
</style>

<div class="nas-checkout">
  <?php if($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" class="nas-logo" alt="<?php echo esc_attr($brand_name); ?>"><br><?php endif; ?>
  <h2 style="margin:0 0 24px;color:<?php echo esc_attr($brand_color); ?>">Complete Your Payment</h2>

  <div class="nas-checkout-grid">
    <!-- Order Summary -->
    <div class="nas-card">
      <h3>📋 Order Summary</h3>
      <div class="nas-order-row"><span>Order ID</span><span><strong><?php echo esc_html($booking['uid']); ?></strong></span></div>
      <div class="nas-order-row"><span>Newspaper</span><span><?php echo esc_html($booking['newspaper_name']); ?></span></div>
      <div class="nas-order-row"><span>City</span><span><?php echo esc_html($booking['city_name']); ?></span></div>
      <div class="nas-order-row"><span>Category</span><span><?php echo esc_html($booking['category_name']); ?></span></div>
      <div class="nas-order-row"><span>Ad Type</span><span><?php echo esc_html($booking['ad_type'] ?? 'N/A'); ?></span></div>
      <?php $base = round($amount / (1 + $gst_rate/100), 2); $gst = round($amount - $base, 2); ?>
      <div class="nas-order-row"><span>Subtotal</span><span><?php echo $currency_sym . number_format($base,2); ?></span></div>
      <div class="nas-order-row"><span>GST (<?php echo $gst_rate; ?>%)</span><span><?php echo $currency_sym . number_format($gst,2); ?></span></div>
      <div class="nas-order-row total"><span>Total</span><span><?php echo $currency_sym . number_format($amount,2); ?></span></div>
    </div>

    <!-- Payment Gateway Selection -->
    <div class="nas-card">
      <h3>💳 Select Payment Method</h3>
      <div class="nas-gateway-list">
        <?php if($razorpay_key): ?>
        <label class="nas-gw selected" onclick="selectGateway(this,'razorpay')">
          <input type="radio" name="gateway" value="razorpay" checked>
          <div>
            <div class="nas-gw-label">Razorpay</div>
            <div class="nas-gw-desc">UPI, Net Banking, Cards, Wallets — all Indian payment methods</div>
          </div>
        </label>
        <?php endif; ?>
        <?php if($payu_enabled): ?>
        <label class="nas-gw" onclick="selectGateway(this,'payu')">
          <input type="radio" name="gateway" value="payu">
          <div>
            <div class="nas-gw-label">PayU</div>
            <div class="nas-gw-desc">Alternative Indian payment gateway</div>
          </div>
        </label>
        <?php endif; ?>
        <?php if($stripe_key): ?>
        <label class="nas-gw" onclick="selectGateway(this,'stripe')">
          <input type="radio" name="gateway" value="stripe">
          <div>
            <div class="nas-gw-label">Stripe</div>
            <div class="nas-gw-desc">International credit / debit cards</div>
          </div>
        </label>
        <?php endif; ?>
        <label class="nas-gw" onclick="selectGateway(this,'bank_transfer')">
          <input type="radio" name="gateway" value="bank_transfer">
          <div>
            <div class="nas-gw-label">Bank Transfer / NEFT</div>
            <div class="nas-gw-desc">Manual payment — admin will confirm receipt</div>
          </div>
        </label>
      </div>

      <!-- Stripe card element (shown only when Stripe selected) -->
      <div id="nas-stripe-element" style="display:none;margin-top:16px;padding:12px;border:1px solid #e2e8f0;border-radius:8px"></div>

      <!-- Bank transfer info (shown only when bank_transfer selected) -->
      <div id="nas-bank-info" style="display:none;margin-top:12px;background:#f8fafc;padding:14px;border-radius:8px;font-size:13px">
        <strong>Bank Transfer Details:</strong><br>
        <?php echo nl2br(esc_html($cfg->get('bank_transfer_details','Please contact us for bank transfer details.'))); ?>
        <br><br>
        <input type="text" id="nas-bank-ref" placeholder="Enter your UTR / transaction reference" style="width:100%;padding:8px 10px;border:1px solid #d0d7de;border-radius:6px;font-size:13px">
      </div>

      <button class="nas-pay-btn" id="nas-pay-btn" onclick="initiatePayment()">
        Pay <?php echo $currency_sym . number_format($amount,2); ?> Now
      </button>
      <div class="nas-secure-note">🔒 Secure & encrypted payment. Your data is safe.</div>
    </div>
  </div>
</div>

<?php if($razorpay_key): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>
<?php if($stripe_key): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>

<script>
var NAS_CHECKOUT = {
    bookingId:   <?php echo (int)$booking_id; ?>,
    amount:      <?php echo $amount; ?>,
    nonce:       '<?php echo $nonce; ?>',
    ajaxUrl:     '<?php echo admin_url('admin-ajax.php'); ?>',
    currency:    '<?php echo esc_js($cfg->get('currency','INR')); ?>',
    brandName:   '<?php echo esc_js($brand_name); ?>',
    logoUrl:     '<?php echo esc_js($logo_url); ?>',
    brandColor:  '<?php echo esc_js($brand_color); ?>',
    clientName:  '<?php echo esc_js($booking['client_name']); ?>',
    clientEmail: '<?php echo esc_js($booking['client_email']); ?>',
    clientPhone: '<?php echo esc_js($booking['client_phone']); ?>',
    confUrl:     '<?php echo esc_url(get_permalink(get_option('nas_page_confirmation'))); ?>',
    stripeKey:   '<?php echo esc_js($stripe_key); ?>',
    gateways:    {
        razorpay: <?php echo $razorpay_key ? 'true' : 'false'; ?>,
        payu:     <?php echo $payu_enabled ? 'true' : 'false'; ?>,
        stripe:   <?php echo $stripe_key ? 'true' : 'false'; ?>
    }
};
var selectedGateway = '<?php echo $razorpay_key ? 'razorpay' : ($payu_enabled ? 'payu' : ($stripe_key ? 'stripe' : 'bank_transfer')); ?>';
var stripeInstance, stripeElements, cardElement;

function selectGateway(el, gw) {
    document.querySelectorAll('.nas-gw').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    selectedGateway = gw;
    document.getElementById('nas-stripe-element').style.display = (gw === 'stripe') ? 'block' : 'none';
    document.getElementById('nas-bank-info').style.display = (gw === 'bank_transfer') ? 'block' : 'none';
    if (gw === 'stripe' && NAS_CHECKOUT.stripeKey && !stripeInstance) initStripe();
}

function initStripe() {
    stripeInstance = Stripe(NAS_CHECKOUT.stripeKey);
    stripeElements = stripeInstance.elements();
    cardElement = stripeElements.create('card', {style:{base:{fontSize:'15px'}}});
    cardElement.mount('#nas-stripe-element');
}

function initiatePayment() {
    var btn = document.getElementById('nas-pay-btn');
    btn.disabled = true;
    btn.textContent = 'Processing…';

    if (selectedGateway === 'bank_transfer') {
        var ref = document.getElementById('nas-bank-ref').value.trim();
        if (!ref) { alert('Please enter your transaction reference / UTR number'); btn.disabled=false; btn.textContent='Pay Now'; return; }
        jQuery.post(NAS_CHECKOUT.ajaxUrl, {
            action:'nas_payment_failed', // uses this to record bank ref + set status
            nonce: NAS_CHECKOUT.nonce,
            booking_id: NAS_CHECKOUT.bookingId,
            bank_ref: ref
        }, function() { window.location = NAS_CHECKOUT.confUrl + '?booking_id=' + NAS_CHECKOUT.bookingId + '&gateway=bank_transfer'; });
        return;
    }

    // Create payment order server-side
    jQuery.post(NAS_CHECKOUT.ajaxUrl, {
        action: 'nas_create_payment_order',
        nonce:  NAS_CHECKOUT.nonce,
        booking_id: NAS_CHECKOUT.bookingId,
        gateway: selectedGateway
    }, function(resp) {
        if (!resp.success) { alert(resp.data.message || 'Could not initiate payment. Please try again.'); btn.disabled=false; btn.textContent='Pay Now'; return; }
        var data = resp.data;
        if (selectedGateway === 'razorpay') openRazorpay(data);
        else if (selectedGateway === 'payu') openPayU(data);
        else if (selectedGateway === 'stripe') processStripe(data);
    });
}

function openRazorpay(data) {
    var options = {
        key: data.key_id,
        amount: data.amount,
        currency: data.currency,
        name: NAS_CHECKOUT.brandName,
        description: 'Booking #' + data.booking_uid,
        image: NAS_CHECKOUT.logoUrl,
        order_id: data.order_id,
        prefill: { name: NAS_CHECKOUT.clientName, email: NAS_CHECKOUT.clientEmail, contact: NAS_CHECKOUT.clientPhone },
        theme: { color: NAS_CHECKOUT.brandColor },
        handler: function(response) {
            jQuery.post(NAS_CHECKOUT.ajaxUrl, {
                action: 'nas_verify_payment',
                nonce: NAS_CHECKOUT.nonce,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature
            }, function(r) {
                if (r.success) window.location = r.data.redirect || NAS_CHECKOUT.confUrl;
                else alert(r.data.message);
            });
        },
        modal: { ondismiss: function() { document.getElementById('nas-pay-btn').disabled=false; document.getElementById('nas-pay-btn').textContent='Pay Now'; } }
    };
    var rzp = new Razorpay(options);
    rzp.on('payment.failed', function(r){ alert('Payment failed: ' + r.error.description); document.getElementById('nas-pay-btn').disabled=false; document.getElementById('nas-pay-btn').textContent='Pay Now'; });
    rzp.open();
}

function openPayU(data) {
    // Build PayU form and submit
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = data.payu_url;
    var fields = { key:data.merchant_key, txnid:data.txnid, amount:data.amount,
        productinfo:data.productinfo, firstname:NAS_CHECKOUT.clientName,
        email:NAS_CHECKOUT.clientEmail, phone:NAS_CHECKOUT.clientPhone,
        surl: NAS_CHECKOUT.confUrl, furl: window.location.href,
        hash:data.hash };
    for (var k in fields) {
        var i = document.createElement('input');
        i.type='hidden'; i.name=k; i.value=fields[k];
        form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
}

async function processStripe(data) {
    if (!cardElement) { alert('Please enter card details'); return; }
    var result = await stripeInstance.confirmCardPayment(data.client_secret, {
        payment_method: { card: cardElement, billing_details: { name: NAS_CHECKOUT.clientName, email: NAS_CHECKOUT.clientEmail } }
    });
    if (result.error) { alert(result.error.message); document.getElementById('nas-pay-btn').disabled=false; }
    else { window.location = NAS_CHECKOUT.confUrl + '?booking_id=' + NAS_CHECKOUT.bookingId; }
}
</script>
