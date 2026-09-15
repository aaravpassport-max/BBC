<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:40px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:28px;font-weight:900;margin-bottom:8px">Privacy Policy</h1>
    <p style="opacity:.8;font-size:14px">Last updated: <?= date('F d, Y') ?></p>
  </div>
</section>
<section style="padding:48px 24px;background:#fff">
  <div class="rto-container" style="max-width:760px">
    <div style="line-height:1.8;color:#374151">
      <h2 style="color:#1B2A6B">Information We Collect</h2>
      <p><?= esc_html($company) ?> ("we", "us", "our") collects the following information to process your RTO service requests:</p>
      <ul style="margin:10px 0 16px;padding-left:24px">
        <li>Personal identification (name, email, mobile number, address)</li>
        <li>Vehicle information (registration number, RC details, vehicle type)</li>
        <li>Identity documents (Aadhar, PAN, driving license copies) — only as required for service delivery</li>
        <li>Payment information (processed securely via Razorpay; we do not store card details)</li>
      </ul>
      <h2 style="color:#1B2A6B;margin-top:24px">How We Use Your Information</h2>
      <ul style="margin:10px 0 16px;padding-left:24px">
        <li>To process your RTO service requests at local RTO offices</li>
        <li>To communicate service updates via SMS, WhatsApp, and email</li>
        <li>To coordinate with local RTO offices and authorised agents on your behalf</li>
        <li>To send invoices and payment receipts</li>
      </ul>
      <h2 style="color:#1B2A6B;margin-top:24px">Data Security</h2>
      <p>All uploaded documents are encrypted at rest and transmitted over HTTPS. Access is restricted to authorised team members processing your request only.</p>
      <h2 style="color:#1B2A6B;margin-top:24px">Data Retention</h2>
      <p>We retain your data for 3 years after service completion for legal and audit purposes. You may request deletion by emailing us. Govt-mandated records may be retained longer as required by law.</p>
      <h2 style="color:#1B2A6B;margin-top:24px">Contact</h2>
      <p>For privacy queries: <?= get_option('rtoflow_support_email') ? '<a href="mailto:' . esc_attr(get_option('rtoflow_support_email')) . '">' . esc_html(get_option('rtoflow_support_email')) . '</a>' : esc_html($company) ?></p>
    </div>
  </div>
</section>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
