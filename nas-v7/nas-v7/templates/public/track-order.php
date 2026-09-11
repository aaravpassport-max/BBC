<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
$faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
$dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
?>
<div class="nas-portal-page" data-portal-page="track-order">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Order Tracking</span>
      <h1>Track Your <span>Newspaper Ad</span></h1>
      <p>Enter your Order ID and email to see real-time status from submission to publication — every stage from review to print proof.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section nas-portal-section--after-ribbon">
    <div class="nhp-container">
      <div class="nas-faq-layout">
      <aside class="nas-faq-sidebar">
        <div class="nas-faq-help-card">
          <div class="nas-faq-help-card__icon"><i class="fa-solid fa-circle-info"></i></div>
          <h3>Need help tracking?</h3>
          <p>Check your booking confirmation email for the Order ID, or log in to your dashboard for full details.</p>
          <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-btn nas-btn-primary">My Dashboard</a>
          <a href="<?php echo esc_url( $faq_url ); ?>" class="nas-btn nas-btn-secondary" style="margin-top:10px;display:flex;justify-content:center">View FAQ</a>
        </div>
      </aside>

      <div class="nas-portal-card" style="background:#fff;border:1.5px solid var(--nas-border);border-radius:20px;padding:32px;box-shadow:0 8px 32px rgba(12,18,34,.06)">
        <h2 style="font-family:var(--nas-font-display);font-size:1.25rem;font-weight:800;margin:0 0 8px;color:var(--nas-text)"><i class="fa-solid fa-magnifying-glass" style="color:var(--nas-teal,#0D9488);margin-right:8px"></i> Track Your Order</h2>
        <p style="color:var(--nas-text-muted);margin:0 0 24px;font-size:0.9375rem">Enter your Order ID and the email address used during booking.</p>

        <div class="nas-track-form" style="display:flex;flex-direction:column;gap:14px">
          <div class="nas-field">
            <label for="nas-oid">Order ID</label>
            <input type="text" id="nas-oid" placeholder="e.g. BK-10042" autocomplete="off">
          </div>
          <div class="nas-field">
            <label for="nas-temail">Email Address</label>
            <input type="email" id="nas-temail" placeholder="email@example.com">
          </div>
          <button type="button" class="nas-submit-btn nas-track-submit">Track Order</button>
        </div>

        <div class="nas-result" id="nas-result" style="margin-top:28px;display:none">
          <div id="nas-status-badge" class="nas-status-badge" style="display:inline-block;padding:6px 14px;border-radius:999px;font-size:0.75rem;font-weight:700;background:var(--nas-primary);color:#fff;margin-bottom:16px"></div>
          <div class="nas-booking-info" id="nas-booking-info" style="background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:8px"></div>
          <div class="nas-timeline" id="nas-timeline"></div>
          <div id="nas-track-proof" style="display:none;margin-top:20px;background:#fff;border:1.5px solid var(--nas-border);border-radius:14px;overflow:hidden">
            <div style="padding:14px 20px;border-bottom:1px solid var(--nas-border);font-weight:700;font-size:0.875rem;color:var(--nas-text)">
              <i class="fa-solid fa-image" style="color:var(--nas-teal,#0D9488);margin-right:8px"></i> Ad Proof
            </div>
            <div style="padding:20px">
              <p style="font-size:0.8125rem;color:var(--nas-text-muted);margin:0 0 12px">Review how your ad will appear in the newspaper.</p>
              <a id="nas-proof-link" href="#" target="_blank" rel="noopener">
                <img id="nas-proof-img" src="" alt="Ad Proof" style="max-width:100%;border-radius:10px;border:1px solid var(--nas-border);cursor:zoom-in;display:block">
              </a>
              <div id="nas-proof-actions" style="margin-top:16px;display:none">
                <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-btn nas-btn-primary" style="display:inline-flex"><i class="fa-solid fa-gauge-high"></i> Go to My Dashboard</a>
              </div>
            </div>
          </div>
        </div>

        <div id="nas-track-error" class="nas-form-error" style="display:none;margin-top:16px"></div>
      </div>
    </div>
    </div>
  </section>

  <?php nas_portal_block_process( 'Order Lifecycle', 'What Happens After You Book', 'Understand each stage from payment to published proof.' ); ?>

  <?php nas_portal_block_quick_links(); ?>
</div>
