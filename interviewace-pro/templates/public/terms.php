<?php defined('ABSPATH')||exit;
add_action('wp_head',function(){echo '<title>Terms of Service — InterviewAce</title><meta name="description" content="InterviewAce Terms of Service. Please read these terms carefully before using the InterviewAce AI interview preparation platform."/><link rel="canonical" href="'.home_url('/ia-terms').'"/>';});
include IA_DIR.'templates/public/header.php';?>
<main style="padding-top:64px">
<div style="max-width:820px;margin:0 auto;padding:60px 24px 80px">
  <h1 style="font-size:40px;font-weight:900;margin-bottom:8px">Terms of Service</h1>
  <p style="color:var(--m);font-size:14px;margin-bottom:40px">Last updated: <?php echo date('F j, Y');?> · Effective immediately</p>
  <?php foreach([
    ['1. Acceptance of Terms','By creating an account or using InterviewAce, you agree to these Terms of Service. If you do not agree, please do not use the service. These terms constitute a legal agreement between you and InterviewAce.'],
    ['2. Description of Service','InterviewAce provides an AI-powered interview preparation platform. The service includes: AI mock interviews with Priya, automated performance reports, resume analysis, company-specific question packs, and related features. The service is provided "as is" and we reserve the right to modify or discontinue features with notice.'],
    ['3. Account Registration','You must be at least 18 years old to create an account. You are responsible for maintaining the confidentiality of your password. You must provide accurate information during registration. One account per person — creating multiple accounts to circumvent usage limits is prohibited.'],
    ['4. Acceptable Use','You may not: use the service for any unlawful purpose; share your account credentials; attempt to reverse engineer the AI system; use automated tools to scrape or bulk-download content; impersonate other users or InterviewAce staff; attempt to circumvent usage limits through technical means.'],
    ['5. Payment Terms','Paid subscriptions are billed monthly. Refunds are available within 7 days of purchase if you have not used more than 3 interview sessions. All prices include GST where applicable. We reserve the right to change pricing with 30 days notice to existing subscribers.'],
    ['6. Intellectual Property','All content on InterviewAce including the AI model, question database, report templates, and software is owned by InterviewAce. Your interview transcripts and answers remain your intellectual property. You grant us a limited license to use your data to provide the service and improve our AI (using anonymised data only).'],
    ['7. Disclaimer','InterviewAce provides interview practice and feedback for educational purposes only. We do not guarantee employment outcomes. Interview success depends on many factors beyond practice, including employer decisions, market conditions, and candidate qualifications.'],
    ['8. Limitation of Liability','To the maximum extent permitted by Indian law, InterviewAce shall not be liable for indirect, incidental, or consequential damages. Our total liability shall not exceed the amount you paid us in the 3 months preceding the claim.'],
    ['9. Governing Law','These terms are governed by the laws of India. Any disputes shall be resolved through arbitration in Bangalore, Karnataka, India under the Arbitration and Conciliation Act, 1996.'],
    ['10. Contact','Legal queries: legal@interviewace.in | InterviewAce, India'],
  ] as [$title,$content]):?>
  <h2 style="font-size:20px;font-weight:800;margin:36px 0 12px;padding-bottom:10px;border-bottom:1px solid var(--b)"><?php echo esc_html($title);?></h2>
  <p style="font-size:15px;color:rgba(255,255,255,.72);line-height:1.8"><?php echo esc_html($content);?></p>
  <?php endforeach;?>
</div>
</main>
<?php include IA_DIR.'templates/public/footer.php';?>
