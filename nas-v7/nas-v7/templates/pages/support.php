<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Support / Help page — serves /support/
 */
$brand      = nas_config('brand_name', get_bloginfo('name'));
$email      = nas_config('support_email', get_option('admin_email'));
$phone      = nas_config('support_phone', '');
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Help & Support | <?php echo esc_html($brand); ?></title>
<meta name="description" content="Get help with your newspaper ad booking. Contact our support team or browse our FAQ.">
<?php wp_head(); ?>
</head>
<body class="nas-fullpage nas-support-page">

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:700px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px">Help &amp; Support</h1>
    <p style="font-size:17px;opacity:.85;margin:0">We're here to help you with every step of your newspaper ad booking.</p>
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:900px;margin:0 auto">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;margin-bottom:48px">
      <?php $channels = [
        ['📧','Email Support',$email ? 'mailto:'.esc_attr($email) : '#','Email us anytime','Response within 4 hours'],
        ['💬','Live Chat',home_url('/client-dashboard/'),'Login to chat','Available 9am–9pm IST'],
        ['📞','Phone',($phone ? 'tel:'.esc_attr(preg_replace('/\D/','',$phone)) : '#'), $phone ?: 'See admin settings','Mon–Sat 10am–6pm'],
      ];
      foreach ($channels as $ch): ?>
      <a href="<?php echo esc_url($ch[2]); ?>" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:28px;text-decoration:none;color:#0f172a;display:block;transition:border-color .2s">
        <div style="font-size:36px;margin-bottom:12px"><?php echo $ch[0]; ?></div>
        <div style="font-weight:700;font-size:17px;margin-bottom:4px"><?php echo esc_html($ch[1]); ?></div>
        <div style="color:#2563eb;font-size:14px;margin-bottom:6px"><?php echo esc_html($ch[3]); ?></div>
        <div style="color:#94a3b8;font-size:12px"><?php echo esc_html($ch[4]); ?></div>
      </a>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:24px;font-weight:700;margin:0 0 24px;color:#0f172a">Frequently Asked Questions</h2>
    <?php $faqs = [
      ['How do I track my booking?','Log in to your dashboard at <a href="'.esc_url(home_url('/client-dashboard/')).'">My Bookings</a>. You\'ll see real-time status updates at every stage.'],
      ['How long does it take to publish?','Classified ads are typically published within 1–3 working days. Display ads take 2–5 days depending on the newspaper.'],
      ['Can I make changes after booking?','Minor text changes are possible before the ad is submitted to the newspaper. Contact support immediately after booking.'],
      ['What payment methods are accepted?','We accept Razorpay (UPI, credit/debit cards, net banking) and bank transfer. GST invoice is issued automatically.'],
      ['Do I get a proof before publication?','Yes — for display ads we share a proof for your approval before submission. Classified ads follow standard newspaper templates.'],
    ];
    foreach ($faqs as $i => $faq): ?>
    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;margin-bottom:12px;overflow:hidden">
      <button onclick="var d=document.getElementById('faq<?php echo $i;?>');d.style.display=d.style.display==='none'?'':'none'" style="width:100%;text-align:left;padding:20px 24px;font-weight:600;font-size:15px;color:#0f172a;background:none;border:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center">
        <?php echo esc_html($faq[0]); ?> <span>+</span>
      </button>
      <div id="faq<?php echo $i; ?>" style="display:none;padding:0 24px 20px;color:#475569;font-size:14px;line-height:1.7"><?php echo wp_kses_post($faq[1]); ?></div>
    </div>
    <?php endforeach; ?>

    <div style="text-align:center;margin-top:40px">
      <p style="color:#64748b;margin-bottom:16px">Still have questions?</p>
      <a href="<?php echo esc_url(home_url('/contact-us/')); ?>" style="display:inline-block;background:#0f172a;color:#fff;padding:13px 28px;border-radius:10px;font-weight:600;text-decoration:none">Contact Us</a>
    </div>
  </div>
</section>

<?php wp_footer(); ?>
</body>
</html>
