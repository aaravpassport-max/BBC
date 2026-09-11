<footer style="background:var(--s1);border-top:1px solid var(--b);padding:48px 0 32px;margin-top:80px">
  <div style="max-width:1180px;margin:0 auto;padding:0 24px">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:40px;margin-bottom:40px">
      <div>
        <div style="display:flex;align-items:center;gap:10px;font-size:18px;font-weight:800;margin-bottom:14px">
          <div style="width:32px;height:32px;background:linear-gradient(135deg,#7C3AED,#A78BFA);border-radius:8px;display:flex;align-items:center;justify-content:center">🎤</div>
          InterviewAce
        </div>
        <p style="font-size:14px;color:var(--m);line-height:1.7;max-width:280px">AI-powered mock interview platform built for Indian job seekers. Practice with Priya, your AI HR interviewer, and land your dream job.</p>
        <div style="display:flex;gap:12px;margin-top:16px">
          <a href="https://twitter.com" style="color:var(--m);font-size:13px;transition:color .14s" onmouseover="this.style.color='#A78BFA'" onmouseout="this.style.color='var(--m)'">𝕏 Twitter</a>
          <a href="https://linkedin.com" style="color:var(--m);font-size:13px" onmouseover="this.style.color='#A78BFA'" onmouseout="this.style.color='var(--m)'">in LinkedIn</a>
        </div>
      </div>
      <div>
        <h3 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--m);margin-bottom:14px">Product</h3>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach(['Features'=>'#features','Pricing'=>home_url('/ia-pricing'),'How it works'=>'#how-it-works'] as $l=>$h): ?>
          <a href="<?php echo esc_url($h);?>" style="font-size:14px;color:rgba(255,255,255,.65);transition:color .14s" onmouseover="this.style.color='#F0EBFF'" onmouseout="this.style.color='rgba(255,255,255,.65)'"><?php echo esc_html($l);?></a>
          <?php endforeach;?>
        </div>
      </div>
      <div>
        <h3 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--m);margin-bottom:14px">Company</h3>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach(['About us'=>home_url('/ia-about'),'Contact'=>'mailto:hello@interviewace.in'] as $l=>$h): ?>
          <a href="<?php echo esc_url($h);?>" style="font-size:14px;color:rgba(255,255,255,.65);transition:color .14s" onmouseover="this.style.color='#F0EBFF'" onmouseout="this.style.color='rgba(255,255,255,.65)'"><?php echo esc_html($l);?></a>
          <?php endforeach;?>
        </div>
      </div>
      <div>
        <h3 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--m);margin-bottom:14px">Legal</h3>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach(['Privacy Policy'=>home_url('/ia-privacy'),'Terms of Service'=>home_url('/ia-terms')] as $l=>$h): ?>
          <a href="<?php echo esc_url($h);?>" style="font-size:14px;color:rgba(255,255,255,.65)" onmouseover="this.style.color='#F0EBFF'" onmouseout="this.style.color='rgba(255,255,255,.65)'"><?php echo esc_html($l);?></a>
          <?php endforeach;?>
        </div>
      </div>
    </div>
    <div style="border-top:1px solid var(--b);padding-top:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <p style="font-size:13px;color:var(--m)">© <?php echo date('Y');?> InterviewAce. All rights reserved. Made in India 🇮🇳</p>
      <p style="font-size:13px;color:var(--m)">Helping lakhs of job seekers crack their dream interviews.</p>
    </div>
  </div>
</footer>
<?php wp_footer(); ?>
</body></html>
