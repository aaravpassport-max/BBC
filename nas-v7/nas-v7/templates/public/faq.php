<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$cfg   = \NAS\Core\Config::instance();
$contact_url = get_permalink( get_option( 'nas_page_contact' ) ) ?: home_url( '/contact-us/' );
$walink = $cfg->get( 'brand_whatsapp', '' )
    ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $cfg->get( 'brand_whatsapp', '' ) )
    : '';
?>

<div class="nas-portal-page" data-portal-page="faq">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Help Center</span>
      <h1>Frequently Asked <span>Questions</span></h1>
      <p>Find answers about booking newspaper ads, payments, publication timelines, material requirements, and order tracking.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section nas-portal-section--after-ribbon">
    <div class="nhp-container">
      <div class="nas-faq-layout">
      <aside class="nas-faq-sidebar">
        <div class="nas-faq-help-card">
          <div class="nas-faq-help-card__icon"><i class="fa-solid fa-headset"></i></div>
          <h3>Need personal help?</h3>
          <p>Our support team can assist with newspaper selection, ad formatting, and rate quotes.</p>
          <a href="<?php echo esc_url( $contact_url ); ?>" class="nas-btn nas-btn-primary">Contact Support</a>
          <?php if ( $walink ) : ?>
          <a href="<?php echo esc_url( $walink ); ?>" class="nas-btn nas-btn-secondary" style="margin-top:10px;display:flex;justify-content:center" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
          <?php endif; ?>
        </div>
        <div class="nas-faq-search-wrap">
          <i class="fa-solid fa-search"></i>
          <input type="search" id="nas-faq-search" placeholder="Search questions…">
        </div>
        <div class="nas-faq-cats" id="nas-faq-cats">
          <button type="button" class="nas-faq-cat active" data-cat="">All</button>
        </div>
      </aside>

      <div>
        <div id="nas-accordion" class="nas-faq-list">
          <div style="padding:24px;color:#94a3b8;text-align:center">Loading questions…</div>
        </div>
        <div class="nas-faq-cta-bar">Can't find your answer? <a href="<?php echo esc_url( $contact_url ); ?>">Talk to our support team →</a></div>
      </div>
    </div>
    </div>
  </section>

  <?php nas_portal_block_quick_links(); ?>

  <?php nas_portal_block_accent_band(
      'Still Have Questions?',
      'Our support team can help with newspaper selection, ad formatting, and rate quotes.',
      $contact_url,
      'Contact Support'
  ); ?>
</div>
