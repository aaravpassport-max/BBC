<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:52px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:30px;font-weight:900;margin-bottom:10px"><?= esc_html__('How It Works', 'rtoflow-os') ?></h1>
    <p style="opacity:.85"><?= esc_html__('A simple process, with doorstep pickup/drop where available in your city', 'rtoflow-os') ?></p>
  </div>
</section>

<section style="padding:60px 24px;background:#fff">
  <div class="rto-container" style="max-width:900px">
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:24px;margin-bottom:48px">
      <?php foreach([
        ['01','📋', __('Submit Your Request', 'rtoflow-os'), __("Fill our quick online form with your service requirement and contact details. You'll receive a call-back with a detailed quote, including any pickup/drop charges that apply.", 'rtoflow-os')],
        ['02','🚗', __('Document Collection', 'rtoflow-os'), __("Where our local agent/courier network covers your city, documents can be collected from your home or office for an additional charge. We run a thorough checklist to reduce errors before submission. Where pickup isn't available, you'll submit documents yourself with our guidance.", 'rtoflow-os')],
        ['03','🏛', __('RTO Filing & Follow-Up', 'rtoflow-os'), __('We file your application at the local RTO and track its status. If the RTO raises an objection or asks for more information, we help you understand and respond to it — the RTO decides the outcome.', 'rtoflow-os')],
        ['04','📬', __('Document Handover', 'rtoflow-os'), __('Once the RTO issues your final document (RC, DL, NOC, etc.), we arrange courier delivery where our network covers your address, or guide you on collecting it from the RTO. You get tracking details either way.', 'rtoflow-os')],
      ] as [$num,$icon,$title,$desc]): ?>
      <div style="background:#F8FAFC;border-radius:14px;border:1px solid #e2e8f0;padding:28px;position:relative">
        <div style="position:absolute;top:-12px;left:24px;width:36px;height:36px;border-radius:50%;background:#E97B28;color:#fff;font-size:14px;font-weight:900;display:flex;align-items:center;justify-content:center" aria-hidden="true"><?= $num ?></div>
        <div style="font-size:36px;margin:14px 0 12px" aria-hidden="true"><?= $icon ?></div>
        <h3 style="font-size:16px;font-weight:800;color:#1B2A6B;margin-bottom:8px"><?= esc_html($title) ?></h3>
        <p style="font-size:13px;color:#64748b;line-height:1.7;margin:0"><?= esc_html($desc) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="background:#EFF6FF;border-radius:14px;padding:28px;margin-bottom:40px">
      <h2 style="font-size:20px;font-weight:800;color:#1B2A6B;margin-bottom:16px"><?= esc_html__('Documents We Handle', 'rtoflow-os') ?></h2>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
        <?php foreach([__('RC Book / Smart Card','rtoflow-os'),__('Valid Insurance Certificate','rtoflow-os'),__('PUC Certificate','rtoflow-os'),__('Aadhar Card','rtoflow-os'),__('PAN Card','rtoflow-os'),__('Passport Photo','rtoflow-os'),__('Driving License','rtoflow-os'),__('Form 29 (Transfer)','rtoflow-os'),__('Form 30 (Application)','rtoflow-os'),__('Address Proof','rtoflow-os'),__('Sale Deed / Agreement','rtoflow-os'),__('NOC from Financier','rtoflow-os')] as $doc): ?>
        <div style="background:#fff;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;color:#374151;border:1px solid #DBEAFE"><span aria-hidden="true">✓</span> <?= esc_html($doc) ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;border-radius:14px;padding:36px;text-align:center">
      <h2 style="font-size:22px;font-weight:800;margin-bottom:12px"><?= esc_html__('Start Your Application Today', 'rtoflow-os') ?></h2>
      <p style="opacity:.85;margin-bottom:20px"><?= esc_html__('Free consultation, transparent pricing, and application assistance you can rely on. Government approval, appointment allocation, and processing time are decided by the RTO/authority and are not guaranteed by us.', 'rtoflow-os') ?></p>
      <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none"><?= esc_html__('Get Started →', 'rtoflow-os') ?></a>
    </div>
  </div>
</section>

<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
