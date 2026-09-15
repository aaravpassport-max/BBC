<?php
if (!defined('ABSPATH')) exit;
$company = get_option('rtoflow_company_name', 'RTOASSIST');
$phone   = get_option('rtoflow_company_phone', '');
$email   = get_option('rtoflow_support_email', '');
// CORRECTED (service-claims audit): "thousands of vehicle owners across
// 300+ cities" was a hardcoded, unverifiable number with no query behind
// it. Real completed-lead and active-city counts are used instead; the
// copy below only states an exact number where one is real, and falls
// back to accurate non-numeric language otherwise.
global $wpdb;
$realCityCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE is_active=1");
$realLeadCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads WHERE status='completed'");
rto_view('layouts.website-header', compact('company','phone','page_title','meta_desc'));
?>
<section style="background:linear-gradient(135deg,#1B2A6B,#243B8A);color:#fff;padding:52px 24px;text-align:center">
  <div class="rto-container">
    <h1 style="font-size:30px;font-weight:900;margin-bottom:10px">About <?= esc_html($company) ?></h1>
    <p style="opacity:.85">India's Most Trusted RTO Service Provider</p>
  </div>
</section>

<section style="padding:60px 24px;background:#fff">
  <div class="rto-container" style="max-width:840px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:start;margin-bottom:48px">
      <div>
        <h2 style="font-size:22px;font-weight:800;color:#1B2A6B;margin-bottom:14px">Who We Are</h2>
        <p style="color:#64748b;line-height:1.8;margin-bottom:14px"><?= esc_html($company) ?> is an RTO assistance platform<?= $realCityCount > 0 ? ', operating across ' . $realCityCount . ' cities' : '' ?>, helping vehicle owners with RC transfers, driving license services, NOC, hypothecation and other RTO-related documentation<?= $realLeadCount >= 50 ? ' (' . number_format($realLeadCount) . '+ services completed to date)' : '' ?>.</p>
        <p style="color:#64748b;line-height:1.8">Our services are primarily online — we handle document processing digitally and coordinate with local RTO offices on your behalf. Where physically feasible, we also arrange document pickup through courier partners or in-person agents.</p>
      </div>
      <div style="background:linear-gradient(135deg,#EFF6FF,#F5F3FF);border-radius:14px;padding:28px;text-align:center;border:1px solid #e2e8f0">
        <div style="font-size:56px;margin-bottom:12px">🤝</div>
        <div style="font-size:20px;font-weight:800;color:#1B2A6B;margin-bottom:6px">India's Most Trusted</div>
        <div style="font-size:14px;color:#64748b;margin-bottom:16px">RTO Service Provider</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center">
          <?php foreach(['5★ Rated','Pan India','Transparent Pricing','Money-Back'] as $b): ?>
          <span style="background:#EFF6FF;color:#2563EB;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700"><?= $b ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <h2 style="font-size:22px;font-weight:800;color:#1B2A6B;margin-bottom:20px">Why We're Different</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:48px">
      <?php foreach([
        ['📦','Document Assistance','We handle your paperwork digitally. Pickup & delivery arranged where available.'],
        ['💰','Transparent Pricing','Full price quoted upfront — zero hidden charges, ever.'],
        ['⚡','Accurate Application Support','Dedicated agents familiar with local RTO procedures and requirements.'],
        ['📱','Real-Time Updates','SMS & WhatsApp updates at every stage the RTO shares status for.'],
        ['🛡','Documents Safe','Where we physically handle your documents, our agent takes reasonable care and confirms receipt with you directly.'],
        ['↩','Service-Fee Refund Policy','If we fail to deliver the assistance we committed to, we refund our service fee. This does not extend to the RTO\'s decision or timeline, which is outside our control.'],
      ] as [$icon,$title,$desc]): ?>
      <div style="display:flex;align-items:flex-start;gap:12px;background:#F8FAFC;border-radius:10px;padding:14px">
        <div style="width:38px;height:38px;background:#EFF6FF;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0"><?= $icon ?></div>
        <div><div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px"><?= esc_html($title) ?></div><div style="font-size:12px;color:#64748b;line-height:1.6"><?= esc_html($desc) ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="background:linear-gradient(135deg,#1B2A6B,#0A1628);color:#fff;border-radius:14px;padding:36px;text-align:center">
      <h2 style="font-size:22px;font-weight:800;margin-bottom:12px">Ready to Get Started?</h2>
      <p style="opacity:.85;margin-bottom:20px"><?= $realCityCount > 0 ? 'Available across ' . $realCityCount . ' cities.' : 'Start your RTO application with expert assistance.' ?></p>
      <a href="<?= home_url('/rto-apply/') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#E97B28;color:#fff;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:700;text-decoration:none">Get Free Quote →</a>
    </div>
  </div>
</section>

<?php rto_view('layouts.website-footer', compact('company','phone')); ?>
