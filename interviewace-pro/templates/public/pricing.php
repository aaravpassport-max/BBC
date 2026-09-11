<?php
defined('ABSPATH') || exit;
add_action('wp_head', function(){
    echo '<title>Pricing Plans — InterviewAce | AI Interview Preparation</title>';
    echo '<meta name="description" content="InterviewAce pricing: Free plan with 2 interviews/week. Pro plan at ₹299/month for unlimited interviews. Premium at ₹599/month for advanced features. Start free today."/>';
    echo '<link rel="canonical" href="'.home_url('/ia-pricing').'"/>';
});
include IA_DIR.'templates/public/header.php';
/* ROOT-CAUSE FIX: no /signup route exists; the SPA (basename="/app") only knows /app/register. */
$signup_url = home_url('/app/register');

$plans = [
    ['Free','₹0','/month','#94a3b8',false,'',['2 interviews per week','15 min per session','Basic AI score report','Answer library access','Browser STT (no API key needed)','Email support'],
    ['Unlimited','Unlimited','15 min','Basic','—','—']],
    ['Pro','₹299','/month','#7C3AED',true,'Most popular',['Unlimited interviews per day','60 min per session','Full 20-metric report with hiring radar','Company-specific question packs (500+)','STAR method coaching & feedback','Resume + JD ATS keyword analysis','Spaced repetition review queue','Progress analytics & leaderboard','Priority report generation (<30 sec)','Email digest of your progress'],
    ['Unlimited','Unlimited','60 min','Full 20-metric','✓','✓']],
    ['Premium','₹599','/month','#059669',false,'',['Everything in Pro','90 min per session','Salary intelligence insights','Coding round support (DSA, SQL, Python)','HR round deep simulations','Group discussion preparation','Mock GD + HR combo rounds','Priority email support','Early access to new features','Dedicated practice coach (AI)'],
    ['Unlimited','Unlimited','90 min','Full 20-metric + extra','✓','✓']],
];
?>
<main style="padding-top:64px">
<section style="padding:80px 0 60px;text-align:center;background:radial-gradient(ellipse at 50% 0%,rgba(124,58,237,.15) 0%,transparent 60%)">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px">
    <h1 style="font-size:clamp(32px,5vw,52px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Simple, honest <span style="color:#A78BFA">pricing</span></h1>
    <p style="font-size:18px;color:var(--m);max-width:520px;margin:0 auto 48px">Start free. Upgrade when you want more. No hidden fees, no auto-renewals without consent.</p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;max-width:980px;margin:0 auto;text-align:left">
      <?php foreach($plans as [$name,$price,$period,$color,$hi,$badge,$features,$_unused1,$_unused2,$_unused3,$_unused4,$_unused5,$_unused6]):?>
      <?php foreach($plans as [$name,$price,$period,$color,$hi,$badge,$features]): break; endforeach;?>
      <?php endforeach;?>
      <?php foreach($plans as $p): [$name,$price,$period,$color,$hi,$badge,$features] = array_slice($p,0,7); ?>
      <div style="background:var(--s1);border:1.5px solid <?php echo $hi?'rgba(124,58,237,.5)':'var(--b)';?>;border-radius:20px;padding:28px;position:relative;<?php echo $hi?'box-shadow:0 0 0 1px rgba(124,58,237,.15)':'';?>">
        <?php if($badge):?><div style="position:absolute;top:0;right:16px;background:var(--p);color:#fff;font-size:11px;font-weight:700;padding:4px 14px;border-radius:0 0 10px 10px"><?php echo $badge;?></div><?php endif;?>
        <div style="font-size:22px;font-weight:900;margin-bottom:6px"><?php echo $name;?></div>
        <div style="font-size:44px;font-weight:900;color:<?php echo $color;?>;line-height:1;margin-bottom:4px"><?php echo $price;?></div>
        <div style="font-size:14px;color:var(--m);margin-bottom:24px"><?php echo $period;?> · Billed monthly · Cancel anytime</div>
        <a href="<?php echo $signup_url;?>" style="display:block;text-align:center;padding:13px;margin-bottom:24px;background:<?php echo $hi?'var(--p)':'rgba(255,255,255,.07)';?>;color:<?php echo $hi?'#fff':'var(--t)';?>;border:1px solid <?php echo $hi?'transparent':'var(--b)';?>;border-radius:10px;font-size:15px;font-weight:700">
          <?php echo $name==='Free'?'Start free':'Get '.$name;?>
        </a>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:10px">
          <?php foreach($features as $f):?>
          <li style="font-size:13.5px;color:rgba(255,255,255,.75);display:flex;gap:8px;line-height:1.45">
            <span style="color:#34D399;flex-shrink:0">✓</span><?php echo esc_html($f);?>
          </li>
          <?php endforeach;?>
        </ul>
      </div>
      <?php endforeach;?>
    </div>
    <p style="margin-top:24px;font-size:14px;color:var(--m)">All paid plans include a 7-day free trial. Questions? <a href="mailto:hello@interviewace.in" style="color:var(--pa)">Contact us</a></p>
  </div>
</section>

<!-- FAQ -->
<section style="padding:80px 0">
  <div style="max-width:720px;margin:0 auto;padding:0 24px">
    <h2 style="font-size:32px;font-weight:900;text-align:center;margin-bottom:40px">Pricing <span style="color:#A78BFA">FAQs</span></h2>
    <?php foreach([
      ['Can I change my plan anytime?','Yes. You can upgrade, downgrade, or cancel at any time from your billing settings. Changes take effect at the start of your next billing cycle.'],
      ['Is there a student discount?','Yes! Students with a valid college email (.edu or .ac.in) get 30% off all paid plans. Enter your student email during signup and the discount applies automatically.'],
      ['What payment methods are accepted?','We accept all major credit/debit cards, UPI, net banking, and wallets through Razorpay. Payments are processed securely.'],
      ['What happens when my free trial ends?','You\'ll be moved to the Free plan automatically. Your data and interview history are preserved. You can upgrade any time to regain Pro/Premium features.'],
    ] as [$q,$a]):?>
    <details style="border:1px solid var(--b);border-radius:12px;padding:18px 20px;margin-bottom:10px;background:var(--s1)">
      <summary style="font-size:15px;font-weight:700;list-style:none;cursor:pointer;display:flex;justify-content:space-between">
        <?php echo esc_html($q);?> <span style="color:var(--m)">+</span>
      </summary>
      <p style="font-size:14px;color:var(--m);margin-top:12px;line-height:1.7"><?php echo esc_html($a);?></p>
    </details>
    <?php endforeach;?>
  </div>
</section>
</main>
<?php include IA_DIR.'templates/public/footer.php'; ?>
