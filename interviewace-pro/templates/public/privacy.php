<?php defined('ABSPATH')||exit;
add_action('wp_head',function(){echo '<title>Privacy Policy — InterviewAce</title><meta name="description" content="InterviewAce Privacy Policy. We are committed to protecting your personal data and complying with India\'s Digital Personal Data Protection Act 2023."/><link rel="canonical" href="'.home_url('/ia-privacy').'"/>';});
include IA_DIR.'templates/public/header.php';?>
<main style="padding-top:64px">
<div style="max-width:820px;margin:0 auto;padding:60px 24px 80px">
  <h1 style="font-size:40px;font-weight:900;margin-bottom:8px">Privacy Policy</h1>
  <p style="color:var(--m);font-size:14px;margin-bottom:40px">Last updated: <?php echo date('F j, Y');?></p>
  <?php
  $sections = [
    ['What information we collect','We collect: (1) Account information — name, email address, and password when you register. (2) Profile information — your work experience level, target role, industry, and resume (if uploaded). (3) Interview data — your voice responses are transcribed to text during interview sessions. Audio is not stored — only the text transcript. (4) Usage data — interview scores, report data, session duration, and feature usage to improve the platform. (5) Payment information — processed securely by Razorpay. We never store credit card or UPI details.'],
    ['How we use your information','We use your information to: provide the InterviewAce service; generate AI-powered interview feedback and reports; improve our AI models and product features (using anonymised, aggregated data only); send you weekly progress digests (you can unsubscribe at any time); process payments and manage subscriptions; provide customer support.'],
    ['Data retention','Interview transcripts and reports are retained for 2 years from the date of the interview. You can delete your data at any time from your Profile settings. Account data is deleted within 30 days of account closure.'],
    ['Data sharing','We do not sell your personal data. We do not share your interview content with employers. We share data with: Anthropic (Claude AI) for interview evaluation — your data is processed under their data processing agreement and not used for training. Razorpay for payment processing. No other third parties receive your personal data without explicit consent.'],
    ['Your rights under DPDP Act 2023','Under India\'s Digital Personal Data Protection Act 2023, you have the right to: access your personal data; correct inaccurate data; erase your data; withdraw consent; nominate a person to exercise your rights in case of death or incapacity. To exercise these rights, email privacy@interviewace.in or use the data controls in your Profile settings.'],
    ['Security','All data is encrypted in transit (TLS 1.3) and at rest (AES-256). Servers are hosted in India. We conduct regular security audits. We will notify you within 72 hours of any data breach that may affect you.'],
    ['Cookies','We use essential cookies only: session authentication cookies and preference cookies. We do not use advertising cookies or third-party tracking pixels.'],
    ['Contact','For privacy concerns: privacy@interviewace.in | InterviewAce, India'],
  ];
  foreach($sections as [$title,$content]):?>
  <h2 style="font-size:20px;font-weight:800;margin:36px 0 12px;padding-bottom:10px;border-bottom:1px solid var(--b)"><?php echo esc_html($title);?></h2>
  <p style="font-size:15px;color:rgba(255,255,255,.72);line-height:1.8"><?php echo esc_html($content);?></p>
  <?php endforeach;?>
</div>
</main>
<?php include IA_DIR.'templates/public/footer.php';?>
