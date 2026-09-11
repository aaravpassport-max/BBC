<?php if ( ! defined( 'ABSPATH' ) ) exit;
$booking_id = (int) ( $_GET['booking_id'] ?? 0 );
$db         = \NAS\Core\Database::instance();
$cfg        = \NAS\Core\Config::instance();

if ( ! $booking_id ) { wp_safe_redirect( home_url('/') ); exit; }

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

$razorpay_key  = $cfg->get('razorpay_key_id','');
$payu_enabled  = (bool) $cfg->get('payu_merchant_key','');
$stripe_key    = $cfg->get('stripe_publishable_key','');
$currency_sym  = $cfg->get('currency_symbol','₹');
$gst_rate      = (float) $cfg->get('gst_percentage',18);
$amount        = (float) $booking['total_amount'];
$nonce         = wp_create_nonce('nas_action');
$brand_color   = $cfg->get('brand_primary_color','#1A3A5C');
$brand_name    = $cfg->get('brand_name','NewspaperAds');
$logo_url      = $cfg->get('logo_url','');
$base          = round($amount / (1 + $gst_rate/100), 2);
$gst           = round($amount - $base, 2);
$default_gw    = $razorpay_key ? 'razorpay' : ($payu_enabled ? 'payu' : ($stripe_key ? 'stripe' : 'bank_transfer'));
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow"><i class="fa-solid fa-lock"></i> Secure Checkout</span>
      <h1>Complete Your <span>Payment</span></h1>
      <p>Order <?php echo esc_html( $booking['uid'] ); ?> · <?php echo esc_html( $booking['newspaper_name'] ); ?> · <?php echo esc_html( $currency_sym . number_format( $amount, 2 ) ); ?></p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section">
    <div class="nhp-container nas-checkout">
      <div class="nas-checkout-grid">
        <div class="nas-checkout-card">
          <h3><i class="fa-solid fa-receipt"></i> Order Summary</h3>
          <div class="nas-order-row"><span>Order ID</span><span><strong><?php echo esc_html($booking['uid']); ?></strong></span></div>
          <div class="nas-order-row"><span>Newspaper</span><span><?php echo esc_html($booking['newspaper_name']); ?></span></div>
          <div class="nas-order-row"><span>City</span><span><?php echo esc_html($booking['city_name']); ?></span></div>
          <div class="nas-order-row"><span>Category</span><span><?php echo esc_html($booking['category_name']); ?></span></div>
          <div class="nas-order-row"><span>Ad Type</span><span><?php echo esc_html($booking['ad_type'] ?? 'N/A'); ?></span></div>
          <div class="nas-order-row"><span>Subtotal</span><span><?php echo $currency_sym . number_format($base,2); ?></span></div>
          <div class="nas-order-row"><span>GST (<?php echo $gst_rate; ?>%)</span><span><?php echo $currency_sym . number_format($gst,2); ?></span></div>
          <div class="nas-order-row total"><span>Total</span><span><?php echo $currency_sym . number_format($amount,2); ?></span></div>
        </div>

        <div class="nas-checkout-card">
          <h3><i class="fa-solid fa-credit-card"></i> Select Payment Method</h3>
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
            <label class="nas-gw<?php echo !$razorpay_key ? ' selected' : ''; ?>" onclick="selectGateway(this,'payu')">
              <input type="radio" name="gateway" value="payu"<?php echo !$razorpay_key ? ' checked' : ''; ?>>
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
            <label class="nas-gw<?php echo $default_gw === 'bank_transfer' ? ' selected' : ''; ?>" onclick="selectGateway(this,'bank_transfer')">
              <input type="radio" name="gateway" value="bank_transfer"<?php echo $default_gw === 'bank_transfer' ? ' checked' : ''; ?>>
              <div>
                <div class="nas-gw-label">Bank Transfer / NEFT</div>
                <div class="nas-gw-desc">Manual payment — admin will confirm receipt</div>
              </div>
            </label>
          </div>

          <div id="nas-stripe-element" class="nas-stripe-element-wrap" style="display:none"></div>

          <div id="nas-bank-info" class="nas-bank-info-wrap" style="display:none">
            <strong>Bank Transfer Details:</strong><br>
            <?php echo nl2br(esc_html($cfg->get('bank_transfer_details','Please contact us for bank transfer details.'))); ?>
            <input type="text" id="nas-bank-ref" placeholder="Enter your UTR / transaction reference">
          </div>

          <button class="nas-pay-btn" id="nas-pay-btn" onclick="initiatePayment()">
            Pay <?php echo $currency_sym . number_format($amount,2); ?> Now
          </button>
          <div class="nas-secure-note"><i class="fa-solid fa-shield-halved"></i> Secure &amp; encrypted payment. Your data is safe.</div>
        </div>
      </div>
    </div>
  </section>

  <?php
  nas_portal_block_process( 'After Payment', 'What Happens Next', 'Your booking moves into our review queue immediately after payment confirmation.' );
  nas_portal_block_quick_links();
  ?>
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
var selectedGateway = '<?php echo esc_js($default_gw); ?>';
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
            action:'nas_payment_failed',
            nonce: NAS_CHECKOUT.nonce,
            booking_id: NAS_CHECKOUT.bookingId,
            bank_ref: ref
        }, function() { window.location = NAS_CHECKOUT.confUrl + '?booking_id=' + NAS_CHECKOUT.bookingId + '&gateway=bank_transfer'; });
        return;
    }

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
