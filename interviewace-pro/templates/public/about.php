<?php defined('ABSPATH')||exit;
add_action('wp_head',function(){echo '<title>About InterviewAce — AI Interview Prep Made in India</title><meta name="description" content="InterviewAce is an AI-powered interview preparation platform built for Indian job seekers. Our mission: help every job seeker in India walk into their interview with confidence."/><link rel="canonical" href="'.home_url('/ia-about').'"/>';});
include IA_DIR.'templates/public/header.php';?>
<main style="padding-top:64px">
<section style="padding:80px 0;text-align:center;background:radial-gradient(ellipse at 50% 0%,rgba(124,58,237,.15) 0%,transparent 60%)">
  <div style="max-width:780px;margin:0 auto;padding:0 24px">
    <h1 style="font-size:clamp(32px,5vw,52px);font-weight:900;margin-bottom:20px">Built in India, for <span style="color:#A78BFA">India's job seekers</span></h1>
    <p style="font-size:18px;color:var(--m);line-height:1.75">InterviewAce was born from a simple observation: millions of talented Indians lose job opportunities not because they lack skills — but because they lack interview practice.</p>
  </div>
</section>
<section style="padding:80px 0">
  <div style="max-width:920px;margin:0 auto;padding:0 24px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;margin-bottom:80px">
      <div>
        <h2 style="font-size:32px;font-weight:900;margin-bottom:20px">Our <span style="color:#34D399">mission</span></h2>
        <p style="font-size:16px;color:var(--m);line-height:1.8;margin-bottom:16px">Every year, over 10 crore Indians appear for job interviews. The majority are rejected not because they aren't qualified — but because they didn't practice enough.</p>
        <p style="font-size:16px;color:var(--m);line-height:1.8;margin-bottom:16px">Traditional interview coaching costs ₹5,000–₹30,000 per session and is only accessible to a privileged few. We believed every job seeker deserved access to world-class interview preparation — at a price anyone can afford.</p>
        <p style="font-size:16px;color:var(--m);line-height:1.8">InterviewAce is our answer: an AI-powered platform where you can practice with Priya — a realistic AI HR interviewer — as many times as you want, get instant feedback, and walk into your real interview with confidence.</p>
      </div>
      <div style="background:var(--s1);border:1px solid var(--b);border-radius:20px;padding:32px">
        <div style="font-size:48px;margin-bottom:16px">🇮🇳</div>
        <h3 style="font-size:22px;font-weight:800;margin-bottom:16px">India-first design</h3>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:12px">
          <?php foreach(['Built specifically for Indian HR practices and interview culture','Supports Hindi, English and Hinglish','Company packs for TCS, Infosys, Wipro and 500+ Indian companies','DPDP Act 2023 compliant data handling','Pricing designed for India: starts at ₹0','Servers in India for low latency'] as $f):?>
          <li style="font-size:14px;color:rgba(255,255,255,.75);display:flex;gap:8px"><span style="color:#34D399">✓</span><?php echo esc_html($f);?></li>
          <?php endforeach;?>
        </ul>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:80px">
      <?php foreach([['2023','Year founded'],['50,000+','Job seekers helped'],['87%','Interview success rate'],['500+','Companies covered'],['4.8★','Average rating'],['30 sec','Report generation time']] as [$n,$l]):?>
      <div style="background:var(--s1);border:1px solid var(--b);border-radius:14px;padding:24px;text-align:center">
        <div style="font-size:36px;font-weight:900;background:linear-gradient(135deg,#A78BFA,#34D399);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:6px"><?php echo $n;?></div>
        <div style="font-size:13px;color:var(--m)"><?php echo $l;?></div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>
<section style="padding:60px 24px;text-align:center;background:var(--s1);border-top:1px solid var(--b)">
  <h2 style="font-size:32px;font-weight:900;margin-bottom:14px">Ready to crack your next interview?</h2>
  <p style="font-size:16px;color:var(--m);margin-bottom:28px">Join 50,000+ job seekers who have transformed their interview performance with InterviewAce.</p>
  <a href="<?php echo home_url('/app/register');?>" style="display:inline-flex;align-items:center;gap:8px;padding:15px 32px;background:var(--p);color:#fff;border-radius:12px;font-size:16px;font-weight:800;box-shadow:0 8px 32px rgba(124,58,237,.4)">Start practising free 🎤</a>
</section>
</main>
<?php include IA_DIR.'templates/public/footer.php';?>
