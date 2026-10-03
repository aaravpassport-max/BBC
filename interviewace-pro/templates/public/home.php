<?php
defined('ABSPATH') || exit;
$app_url    = home_url('/');
/* ROOT-CAUSE FIX: was $app_url.'signup'/'login' — no /signup or bare
   /login route exists; the SPA (basename="/app") only knows /app/register
   and /app/login. Every CTA on this page used the broken URL. */
$signup_url = home_url('/app/register');
$login_url  = home_url('/app/login');
$pricing_url = home_url('/ia-pricing');
$priya_img  = esc_url(get_option('ia_priya_avatar_url', IA_URL.'assets/priya-photo.jpg'));

/* SEO meta */
add_action('wp_head', function() {
    echo '<title>InterviewAce — AI-Powered Interview Preparation for Indian Job Seekers</title>';
    echo '<meta name="description" content="Practice job interviews with Priya, your AI HR interviewer. Get instant feedback, detailed reports, ATS analysis and land your dream job at top Indian companies like TCS, Infosys, Wipro, Accenture and more."/>';
    echo '<meta name="keywords" content="interview preparation, mock interview, AI interview, job interview practice, India, HR interview, technical interview, TCS interview, Infosys interview"/>';
    echo '<meta property="og:title" content="InterviewAce — AI Mock Interview Platform"/>';
    echo '<meta property="og:description" content="Practice with Priya, your AI HR interviewer. Get real-time feedback and ace your next job interview."/>';
    echo '<meta property="og:type" content="website"/>';
    echo '<meta name="robots" content="index, follow"/>';
    echo '<link rel="canonical" href="'.home_url('/ia-home').'"/>';
    // Schema.org structured data
    echo '<script type="application/ld+json">'.wp_json_encode([
        '@context'=>'https://schema.org',
        '@type'=>'SoftwareApplication',
        'name'=>'InterviewAce',
        'description'=>'AI-powered interview preparation platform for Indian job seekers',
        'applicationCategory'=>'EducationalApplication',
        'operatingSystem'=>'Web',
        'offers'=>['@type'=>'Offer','price'=>'0','priceCurrency'=>'INR','description'=>'Free plan available'],
        'aggregateRating'=>['@type'=>'AggregateRating','ratingValue'=>'4.8','reviewCount'=>'2400'],
    ]).'</script>';
});

include IA_DIR.'templates/public/header.php';
?>

<main style="padding-top:64px">

<!-- ══════════════════════════════════════
     HERO SECTION
══════════════════════════════════════ -->
<section style="min-height:92vh;display:flex;align-items:center;position:relative;overflow:hidden;padding:60px 0 80px">
  <!-- Background gradient -->
  <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(124,58,237,.25) 0%,transparent 60%),radial-gradient(ellipse at 80% 80%,rgba(52,211,153,.08) 0%,transparent 50%);pointer-events:none"></div>
  <!-- Animated grid -->
  <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:60px 60px;pointer-events:none"></div>

  <div style="max-width:1180px;margin:0 auto;padding:0 24px;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;width:100%">
    <div>
      <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(124,58,237,.12);border:1px solid rgba(167,139,250,.25);border-radius:20px;padding:6px 14px;margin-bottom:24px;font-size:13px;color:#C4B5FD;font-weight:600">
        ✨ Trusted by 50,000+ job seekers across India
      </div>
      <h1 style="font-size:clamp(36px,5vw,58px);font-weight:900;line-height:1.1;letter-spacing:-.03em;margin-bottom:20px">
        Ace Every<br><span style="background:linear-gradient(135deg,#A78BFA,#7C3AED,#34D399);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Job Interview</span><br>with AI
      </h1>
      <p style="font-size:18px;color:rgba(255,255,255,.65);line-height:1.7;margin-bottom:32px;max-width:480px">
        Practice with <strong style="color:#F0EBFF">Priya</strong>, your AI HR interviewer. Get real-time feedback, detailed reports, and land your dream job at TCS, Infosys, Wipro, Accenture, and 500+ companies.
      </p>
      <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:40px">
        <a href="<?php echo $signup_url;?>" style="display:inline-flex;align-items:center;gap:8px;padding:15px 32px;background:var(--p);color:#fff;border-radius:12px;font-size:16px;font-weight:800;transition:all .2s;box-shadow:0 8px 32px rgba(124,58,237,.4)" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 40px rgba(124,58,237,.55)'" onmouseout="this.style.transform='';this.style.boxShadow='0 8px 32px rgba(124,58,237,.4)'">
          Start practising free 🎤
        </a>
        <a href="#how-it-works" style="display:inline-flex;align-items:center;gap:8px;padding:15px 28px;border:1.5px solid rgba(255,255,255,.15);color:var(--t);border-radius:12px;font-size:16px;font-weight:600;transition:all .2s" onmouseover="this.style.borderColor='rgba(255,255,255,.4)'" onmouseout="this.style.borderColor='rgba(255,255,255,.15)'">
          ▶ See how it works
        </a>
      </div>
      <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
        <?php foreach(['No credit card required','2 free interviews/week','Instant AI feedback'] as $t): ?>
        <div style="display:flex;align-items:center;gap:6px;font-size:13.5px;color:rgba(255,255,255,.55)">
          <span style="color:#34D399;font-size:16px">✓</span><?php echo esc_html($t);?>
        </div>
        <?php endforeach;?>
      </div>
    </div>

    <!-- Priya hero card -->
    <div style="position:relative;display:flex;justify-content:center">
      <div style="position:absolute;inset:-30px;background:radial-gradient(circle,rgba(124,58,237,.3) 0%,transparent 70%);pointer-events:none;animation:glow 3s ease-in-out infinite alternate"></div>
      <style>@keyframes glow{from{opacity:.5}to{opacity:1}}</style>
      <div style="position:relative;background:linear-gradient(145deg,var(--s2),var(--s1));border:1px solid rgba(167,139,250,.2);border-radius:24px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.6),0 0 0 1px rgba(167,139,250,.15);max-width:380px;width:100%">
        <img src="<?php echo $priya_img;?>" alt="Priya, AI HR Recruiter at InterviewAce" style="width:100%;display:block;object-fit:cover;object-position:center 15%;aspect-ratio:4/5"/>
        <!-- Live interview overlay -->
        <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top,rgba(6,6,16,.95) 0%,rgba(6,6,16,.6) 60%,transparent 100%);padding:20px 20px 22px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <span style="width:8px;height:8px;border-radius:50%;background:#34D399;animation:pulse 1.5s ease infinite"></span>
            <span style="font-size:12px;font-weight:700;color:#34D399;text-transform:uppercase;letter-spacing:.06em">Live Interview</span>
          </div>
          <p style="font-size:14px;color:rgba(255,255,255,.85);line-height:1.55;margin-bottom:12px"><strong style="color:#fff">Priya asked:</strong> Tell me about a time you handled a difficult stakeholder situation using the STAR method.</p>
          <div style="display:flex;align-items:center;gap:8px;background:rgba(124,58,237,.15);border:1px solid rgba(167,139,250,.25);border-radius:10px;padding:8px 12px">
            <span style="font-size:16px">🎤</span>
            <div style="display:flex;gap:3px;align-items:flex-end;height:16px">
              <?php for($i=0;$i<8;$i++): $h=rand(4,16); ?>
              <div style="width:3px;height:<?php echo $h;?>px;background:#A78BFA;border-radius:2px;animation:bar .6s <?php echo $i*.08;?>s ease-in-out infinite alternate"></div>
              <?php endfor;?>
            </div>
            <span style="font-size:13px;color:rgba(255,255,255,.6);margin-left:4px">Speaking…</span>
          </div>
        </div>
        <!-- Name badge -->
        <div style="position:absolute;top:16px;left:16px;background:rgba(11,11,22,.85);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:8px 12px">
          <div style="font-size:13px;font-weight:800;color:#F0EBFF">Priya Sharma</div>
          <div style="font-size:10px;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.06em">AI HR Recruiter</div>
        </div>
      </div>
      <!-- Floating score card -->
      <div style="position:absolute;bottom:-20px;right:-10px;background:var(--s1);border:1px solid rgba(52,211,153,.25);border-radius:14px;padding:14px 18px;box-shadow:0 16px 40px rgba(0,0,0,.5);transform:rotate(2deg)">
        <div style="font-size:28px;font-weight:900;color:#34D399">87<span style="font-size:14px;font-weight:400;color:rgba(255,255,255,.4)">/100</span></div>
        <div style="font-size:11px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;margin-top:2px">Overall score</div>
        <div style="display:inline-block;background:rgba(52,211,153,.12);color:#34D399;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;margin-top:6px">✓ Strong Hire</div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     SOCIAL PROOF / STATS
══════════════════════════════════════ -->
<section style="border-top:1px solid var(--b);border-bottom:1px solid var(--b);padding:40px 0;background:var(--s1)">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px;display:grid;grid-template-columns:repeat(4,1fr);gap:0;text-align:center">
    <?php
    $stats = [
        ['50,000+', 'Job seekers trained', '👥'],
        ['4.8★', 'Average rating', '⭐'],
        ['87%', 'Interview success rate', '🎯'],
        ['500+', 'Companies covered', '🏢'],
    ];
    foreach ($stats as [$num, $lbl, $icon]): ?>
    <div style="padding:20px;border-right:1px solid var(--b)">
      <div style="font-size:14px;margin-bottom:6px"><?php echo $icon;?></div>
      <div style="font-size:32px;font-weight:900;background:linear-gradient(135deg,#F0EBFF,#A78BFA);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text"><?php echo esc_html($num);?></div>
      <div style="font-size:13px;color:var(--m);margin-top:4px"><?php echo esc_html($lbl);?></div>
    </div>
    <?php endforeach;?>
  </div>
</section>

<!-- ══════════════════════════════════════
     FEATURES
══════════════════════════════════════ -->
<section id="features" style="padding:100px 0">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px">
    <div style="text-align:center;margin-bottom:64px">
      <div style="display:inline-block;background:rgba(124,58,237,.1);border:1px solid rgba(167,139,250,.2);border-radius:20px;padding:5px 16px;font-size:12px;font-weight:700;color:#C4B5FD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px">Features</div>
      <h2 style="font-size:clamp(28px,4vw,44px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Everything you need to<br><span style="color:#A78BFA">crack your interview</span></h2>
      <p style="font-size:17px;color:var(--m);max-width:560px;margin:0 auto;line-height:1.7">From mock interviews to ATS analysis — InterviewAce covers your entire interview preparation journey.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
      <?php
      $features = [
        ['🎙️','AI Voice Interview','Talk to Priya just like a real HR interviewer. She listens, evaluates, and responds in real time. Supports Hindi, English and Hinglish.'],
        ['📊','Instant Detailed Report','Get a comprehensive report with scores for communication, technical knowledge, confidence, and a hiring recommendation within 30 seconds.'],
        ['🎯','Company-Specific Packs','Practice with interview questions specifically tailored for TCS, Infosys, Wipro, Accenture, Amazon, Flipkart, Deloitte, and 500+ more companies.'],
        ['📄','Resume & ATS Analysis','Upload your resume and job description. Get a keyword match percentage, missing skills, and AI suggestions to optimise your resume for ATS systems.'],
        ['🧠','STAR Method Coaching','Priya coaches you on the STAR (Situation, Task, Action, Result) framework for behavioral questions — the secret to impressing HR interviewers.'],
        ['📚','Spaced Repetition Library','Save your best answers and weak areas. Our SM-2 spaced repetition system schedules review sessions so you never forget what you practised.'],
        ['📈','Progress Analytics','Track your improvement over time. See score trends, badge achievements, XP levels, and how you rank against other candidates in your field.'],
        ['⏱️','Flexible Session Lengths','Free plan: 15-minute sessions. Pro plan: 60 minutes. Premium: 90 minutes. Practice at your own pace, on your schedule.'],
        ['🔒','100% Private & Secure','Your interview recordings and answers are never shared. All data is encrypted and stored securely on Indian servers. DPDP compliant.'],
      ];
      foreach ($features as [$icon, $title, $desc]):?>
      <div style="background:var(--s1);border:1px solid var(--b);border-radius:16px;padding:24px;transition:all .2s" onmouseover="this.style.borderColor='rgba(167,139,250,.3)';this.style.transform='translateY(-3px)'" onmouseout="this.style.borderColor='var(--b)';this.style.transform=''">
        <div style="font-size:32px;margin-bottom:14px"><?php echo $icon;?></div>
        <h3 style="font-size:17px;font-weight:800;margin-bottom:8px"><?php echo esc_html($title);?></h3>
        <p style="font-size:14px;color:var(--m);line-height:1.65"><?php echo esc_html($desc);?></p>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════ -->
<section id="how-it-works" style="padding:100px 0;background:var(--s1);border-top:1px solid var(--b);border-bottom:1px solid var(--b)">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px">
    <div style="text-align:center;margin-bottom:64px">
      <h2 style="font-size:clamp(28px,4vw,44px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Ready in <span style="color:#34D399">3 minutes</span></h2>
      <p style="font-size:17px;color:var(--m)">No downloads. No setup. Just open and start practising.</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:24px;position:relative">
      <!-- Connector line -->
      <div style="position:absolute;top:40px;left:15%;right:15%;height:2px;background:linear-gradient(90deg,#7C3AED,#A78BFA,#34D399);opacity:.3;pointer-events:none"></div>
      <?php
      $steps = [
        ['01','Create your free account','Sign up in 30 seconds. No credit card required. 2 free interview sessions every week.'],
        ['02','Set up your profile','Enter your target role, upload your resume (optional), and paste the job description you\'re applying for.'],
        ['03','Start your AI interview','Click "Start Interview" and Priya begins. Speak naturally — she listens and asks follow-up questions.'],
        ['04','Get your report','Receive a detailed score report in under 30 seconds with specific, actionable feedback to improve.'],
      ];
      foreach ($steps as [$num, $title, $desc]):?>
      <div style="text-align:center;position:relative">
        <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(124,58,237,.08));border:2px solid rgba(167,139,250,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:22px;font-weight:900;color:#A78BFA"><?php echo $num;?></div>
        <h3 style="font-size:16px;font-weight:800;margin-bottom:10px"><?php echo esc_html($title);?></h3>
        <p style="font-size:13.5px;color:var(--m);line-height:1.65"><?php echo esc_html($desc);?></p>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     TESTIMONIALS
══════════════════════════════════════ -->
<section style="padding:100px 0">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px">
    <div style="text-align:center;margin-bottom:64px">
      <h2 style="font-size:clamp(28px,4vw,44px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Loved by job seekers<br><span style="color:#A78BFA">across India</span></h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
      <?php
      $testimonials = [
        ['Rahul Sharma','Software Engineer @ TCS','5★','InterviewAce helped me crack my TCS interview on the first try! Priya asked exactly the kinds of HR questions they ask in real interviews. The feedback was incredibly specific and actionable. Highly recommend to every fresher!','RS','#7C3AED'],
        ['Priya Mehta','HR Executive @ Infosys','5★','As an HR professional myself, I\'m impressed by how realistic Priya\'s interview style is. I used InterviewAce to prepare for my lateral move and the STAR method coaching was a game changer. Got my offer in 2 weeks!','PM','#059669'],
        ['Arjun Nair','Data Analyst @ Wipro','5★','I was very nervous about interviews. After practising with InterviewAce daily for 2 weeks, I went from scoring 45/100 to 84/100. The detailed reports showed me exactly where I was going wrong. Worth every rupee!','AN','#D97706'],
        ['Sneha Patel','MBA Graduate','5★','The company-specific packs are brilliant. I practised the Deloitte pack before my interview and 3 out of 5 questions were very similar to what they actually asked me. I got the job! Thank you InterviewAce 🙏','SP','#7C3AED'],
        ['Amit Kumar','Fresher, B.Tech CSE','5★','As a final year student with no work experience, I was scared of HR rounds. InterviewAce\'s AI understood my profile and asked appropriate fresher-level questions. The spaced repetition for weak answers is genius.','AK','#059669'],
        ['Divya Krishnan','Product Manager @ Amazon','5★','I was skeptical about AI interview prep but InterviewAce changed my mind completely. The speaking pace analysis and filler word detection caught habits I didn\'t even know I had. Highly polished product.','DK','#D97706'],
      ];
      foreach ($testimonials as [$name, $role, $rating, $text, $initials, $color]):?>
      <div style="background:var(--s1);border:1px solid var(--b);border-radius:16px;padding:24px;display:flex;flex-direction:column;gap:0">
        <div style="font-size:18px;color:#FBBF24;margin-bottom:12px"><?php echo $rating;?></div>
        <p style="font-size:14px;color:rgba(255,255,255,.75);line-height:1.7;flex:1;margin-bottom:20px">"<?php echo esc_html($text);?>"</p>
        <div style="display:flex;align-items:center;gap:12px;border-top:1px solid var(--b);padding-top:16px">
          <div style="width:40px;height:40px;border-radius:50%;background:<?php echo $color;?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;flex-shrink:0"><?php echo $initials;?></div>
          <div>
            <div style="font-size:14px;font-weight:700"><?php echo esc_html($name);?></div>
            <div style="font-size:12px;color:var(--m)"><?php echo esc_html($role);?></div>
          </div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     PRICING PREVIEW
══════════════════════════════════════ -->
<section style="padding:100px 0;background:var(--s1);border-top:1px solid var(--b)">
  <div style="max-width:900px;margin:0 auto;padding:0 24px;text-align:center">
    <h2 style="font-size:clamp(28px,4vw,44px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Simple, transparent<br><span style="color:#A78BFA">pricing</span></h2>
    <p style="font-size:17px;color:var(--m);margin-bottom:48px">Start free. Upgrade when you need more practice sessions.</p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;text-align:left">
      <?php
      $plans = [
        ['Free','₹0','/month','#94a3b8',[
          '2 interviews per week','15 min per session','Basic score report','Answer Library','ATS keyword check',
        ],false,''],
        ['Pro','₹299','/month','#7C3AED',[
          'Unlimited interviews','60 min per session','Full detailed report with hiring radar','Company-specific question packs','STAR coaching & feedback','Resume + JD keyword analysis','Spaced repetition review','Priority report generation',
        ],true,'Most popular'],
        ['Premium','₹599','/month','#059669',[
          'Everything in Pro','90 min per session','Salary intelligence insights','Coding round support','HR round simulations','Priority support','Early access to new features',
        ],false,''],
      ];
      foreach ($plans as [$name, $price, $period, $color, $features, $highlight, $badge]):?>
      <div style="background:var(--s2);border:1.5px solid <?php echo $highlight?'rgba(124,58,237,.5)':'var(--b)';?>;border-radius:16px;padding:24px;position:relative;<?php echo $highlight?'box-shadow:0 0 0 1px rgba(124,58,237,.15)':'';?>">
        <?php if($badge):?><div style="position:absolute;top:0;right:14px;background:var(--p);color:#fff;font-size:11px;font-weight:700;padding:3px 12px;border-radius:0 0 9px 9px"><?php echo $badge;?></div><?php endif;?>
        <div style="font-size:18px;font-weight:800;margin-bottom:4px"><?php echo $name;?></div>
        <div style="font-size:36px;font-weight:900;color:<?php echo $color;?>;line-height:1.1;margin-bottom:16px"><?php echo $price;?><span style="font-size:14px;font-weight:400;color:var(--m)"><?php echo $period;?></span></div>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;margin-bottom:20px">
          <?php foreach($features as $f):?>
          <li style="font-size:13.5px;color:rgba(255,255,255,.75);display:flex;gap:8px"><span style="color:#34D399">✓</span><?php echo esc_html($f);?></li>
          <?php endforeach;?>
        </ul>
        <a href="<?php echo $signup_url;?>" style="display:block;text-align:center;padding:12px;background:<?php echo $highlight?'var(--p)':'rgba(255,255,255,.07)';?>;color:<?php echo $highlight?'#fff':'var(--t)';?>;border:1px solid <?php echo $highlight?'transparent':'var(--b)';?>;border-radius:10px;font-size:14px;font-weight:700;transition:all .15s" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">Get started</a>
      </div>
      <?php endforeach;?>
    </div>
    <p style="margin-top:24px;font-size:14px;color:var(--m)">All plans include a 7-day free trial of Pro features. <a href="<?php echo $pricing_url;?>" style="color:var(--pa)">See full pricing comparison →</a></p>
  </div>
</section>

<!-- ══════════════════════════════════════
     FAQ
══════════════════════════════════════ -->
<section style="padding:100px 0">
  <div style="max-width:780px;margin:0 auto;padding:0 24px">
    <div style="text-align:center;margin-bottom:56px">
      <h2 style="font-size:clamp(28px,4vw,40px);font-weight:900;letter-spacing:-.03em;margin-bottom:12px">Frequently asked<br><span style="color:#A78BFA">questions</span></h2>
    </div>
    <?php
    $faqs = [
      ['Is InterviewAce really free?','Yes! The Free plan gives you 2 interview sessions per week with 15 minutes per session, no credit card required. You can upgrade to Pro (₹299/month) for unlimited sessions with 60-minute duration.'],
      ['How realistic is the AI interview?','Very realistic. Priya uses advanced AI to generate contextually appropriate follow-up questions based on your answers, just like a real HR interviewer would. She adapts to your experience level and the specific role you\'re applying for.'],
      ['Which companies\' interview styles are covered?','We cover TCS, Infosys, Wipro, Accenture, HCL, Cognizant, Deloitte, PwC, Amazon, Flipkart, Zomato, Swiggy, Paytm, HDFC Bank, ICICI Bank, and 500+ more Indian and multinational companies.'],
      ['Is my data private?','Absolutely. Your interview recordings, answers, and personal data are encrypted and never shared with employers or third parties. We are fully compliant with India\'s Digital Personal Data Protection (DPDP) Act 2023.'],
      ['Do I need to install anything?','No downloads required. InterviewAce runs entirely in your web browser. You just need a microphone and an internet connection. Works on Chrome, Firefox, and Edge.'],
      ['Can I practice in Hindi or Hinglish?','Yes! InterviewAce supports English, Hindi, and Hinglish (a mix of Hindi and English commonly used in Indian professional settings). Select your preferred language when starting an interview.'],
      ['How long does it take to see improvement?','Most users see significant improvement within 5-7 practice sessions (1-2 weeks of regular practice). The spaced repetition system ensures you retain what you learn between sessions.'],
    ];
    // Schema FAQ structured data
    $faq_schema = ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>array_map(fn($q)=>['@type'=>'Question','name'=>$q[0],'acceptedAnswer'=>['@type'=>'Answer','text'=>$q[1]]],$faqs)];
    echo '<script type="application/ld+json">'.wp_json_encode($faq_schema).'</script>';
    foreach ($faqs as $i=>[$q,$a]):?>
    <details style="border:1px solid var(--b);border-radius:12px;padding:20px 22px;margin-bottom:12px;background:var(--s1);cursor:pointer">
      <summary style="font-size:16px;font-weight:700;color:var(--t);list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px">
        <?php echo esc_html($q);?>
        <span style="font-size:20px;color:var(--m);flex-shrink:0;transition:transform .2s">+</span>
      </summary>
      <p style="font-size:14.5px;color:var(--m);line-height:1.75;margin-top:14px;padding-top:14px;border-top:1px solid var(--b)"><?php echo esc_html($a);?></p>
    </details>
    <?php endforeach;?>
  </div>
</section>

<!-- ══════════════════════════════════════
     CTA SECTION
══════════════════════════════════════ -->
<section style="padding:80px 24px;text-align:center;background:linear-gradient(135deg,rgba(124,58,237,.15) 0%,rgba(52,211,153,.08) 100%);border-top:1px solid var(--b);border-bottom:1px solid var(--b);position:relative;overflow:hidden">
  <div style="position:absolute;inset:0;background:radial-gradient(circle at 50% 50%,rgba(124,58,237,.12) 0%,transparent 70%);pointer-events:none"></div>
  <div style="position:relative;max-width:640px;margin:0 auto">
    <h2 style="font-size:clamp(28px,4vw,48px);font-weight:900;letter-spacing:-.03em;margin-bottom:16px">Your dream job is<br><span style="color:#A78BFA">one practice away</span></h2>
    <p style="font-size:17px;color:var(--m);margin-bottom:36px;line-height:1.7">Join 50,000+ job seekers who have used InterviewAce to land jobs at India's top companies. Start for free today.</p>
    <a href="<?php echo $signup_url;?>" style="display:inline-flex;align-items:center;gap:10px;padding:18px 40px;background:var(--p);color:#fff;border-radius:14px;font-size:17px;font-weight:800;box-shadow:0 8px 32px rgba(124,58,237,.45);transition:all .2s" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 40px rgba(124,58,237,.6)'" onmouseout="this.style.transform='';this.style.boxShadow='0 8px 32px rgba(124,58,237,.45)'">
      Start practising free 🎤
    </a>
    <p style="margin-top:16px;font-size:13px;color:var(--m)">No credit card required · 2 free sessions per week · Cancel anytime</p>
  </div>
</section>

</main>

<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes bar{from{height:4px;opacity:.5}to{height:14px;opacity:1}}
@media(max-width:768px){
  section > div > div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}
  section > div > div[style*="grid-template-columns:repeat(3"]{grid-template-columns:1fr!important}
  section > div > div[style*="grid-template-columns:repeat(4"]{grid-template-columns:repeat(2,1fr)!important}
  section > div > div[style*="grid-template-columns:2fr"]{grid-template-columns:1fr!important}
}
details summary::-webkit-details-marker{display:none}
details[open] summary span{transform:rotate(45deg)}
</style>

<?php include IA_DIR.'templates/public/footer.php'; ?>
