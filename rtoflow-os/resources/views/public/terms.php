<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:40px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:28px;font-weight:900;margin-bottom:8px">Terms & Conditions</h1>
    <p style="opacity:.8;font-size:14px">Effective: <?= date('F d, Y') ?></p>
  </div>
</section>
<section style="padding:48px 24px;background:#fff">
  <div class="rto-container" style="max-width:760px">
    <div style="line-height:1.8;color:#374151">
      <h2 style="color:#1B2A6B">1. Services</h2>
      <p><?= esc_html($company) ?> provides RTO documentation assistance services. We act as a service provider, application-assistance provider, and consultant — not an official government body. All RTO approvals are subject to government decisions; we cannot guarantee outcomes beyond our control.</p>
      <p>Depending on the service, our assistance may include: explaining the applicable process and requirements; helping complete application forms and gather documents; reviewing documents for completeness where applicable; assisting with appointment-related processes; tracking application/appointment status where tracking is available; and providing procedural guidance and updates. The exact scope of assistance varies by service and is described on that service's own page.</p>

      <h2 style="color:#1B2A6B;margin-top:24px">1A. Home Pickup / Drop of Documents</h2>
      <p>Home pickup and drop of documents, where offered, is not automatically included in the standard service fee and may involve an additional charge. Availability depends on our courier/agent network covering the applicant's city or location at the time of booking; it is not guaranteed for every location. Where this facility is unavailable, the applicant may need to submit, collect, or carry documents themselves, with guidance from us on how to do so.</p>

      <h2 style="color:#1B2A6B;margin-top:24px">1B. No Guarantee of Expedited or Preferential Processing</h2>
      <p>We do not expedite government processing, guarantee an appointment, guarantee approval or issuance, guarantee a particular processing time, influence a government department's decision, bypass official procedures, or obtain preferential treatment from any government authority, embassy, consulate, RTO, PSK/POPSK, or other competent authority. Unless a specific official service genuinely offers an expedited option through the authority itself, we follow the applicable government/official procedures and timelines and do not independently control processing speed.</p>

      <h2 style="color:#1B2A6B;margin-top:24px">2. Fees & Payment</h2>
      <ul style="padding-left:24px;margin:10px 0 16px">
        <li>Our service fee is quoted upfront and locked at the time of booking.</li>
        <li>Government fees are collected separately and are based on actual govt. charges at time of filing.</li>
        <li>Payment is due before or at the time of document pickup unless otherwise agreed in writing.</li>
      </ul>

      <h2 style="color:#1B2A6B;margin-top:24px">3. Refund Policy</h2>
      <ul style="padding-left:24px;margin:10px 0 16px">
        <li><strong>Full refund:</strong> If we are unable to assign an agent within 72 hours of confirmed booking.</li>
        <li><strong>Partial refund:</strong> If service was partially rendered (e.g., documents collected but filing not completed due to RTO rejection on agent error).</li>
        <li><strong>No refund:</strong> Government fees once paid to the RTO are non-refundable. Service fees are non-refundable after RTO submission.</li>
      </ul>

      <h2 style="color:#1B2A6B;margin-top:24px">4. Customer Obligations</h2>
      <p>You agree to provide accurate information and genuine documents. Submission of forged or incorrect documents will result in immediate cancellation without refund, and may be reported to authorities as required by law.</p>

      <h2 style="color:#1B2A6B;margin-top:24px">5. Limitation of Liability</h2>
      <p>Our liability is limited to the service fee paid. We are not responsible for delays caused by RTO offices, government system outages, or incomplete documents provided by the client.</p>

      <h2 style="color:#1B2A6B;margin-top:24px">6. Governing Law</h2>
      <p>These terms are governed by the laws of India. Disputes shall be subject to the jurisdiction of courts in the company's registered city.</p>

      <div style="margin-top:28px;background:#F8FAFC;border-radius:10px;padding:16px;font-size:13px;color:#64748b">
        For any queries regarding these terms, contact us at <?= $phone ? esc_html($phone) : esc_html($company) ?>.
      </div>
    </div>
  </div>
</section>
<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
