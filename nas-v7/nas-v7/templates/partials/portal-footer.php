<?php
/**
 * Unified public portal footer — matches homepage footer structure.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$cfg         = \NAS\Core\Config::instance();
$db          = \NAS\Core\Database::instance();
$brand       = $cfg->get( 'brand_name', get_bloginfo( 'name' ) );
$phone       = $cfg->get( 'brand_phone', '' );
$email       = $cfg->get( 'brand_email', '' );
$walink      = $cfg->get( 'brand_whatsapp', '' )
    ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $cfg->get( 'brand_whatsapp', '' ) )
    : '';
$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
$faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
$track_url   = nas_get_page_url( 'nas_page_track_order', '/track-order/' );
$papers_url  = home_url( '/newspapers/' );
$cities_url  = home_url( '/cities/' );

$fb = $cfg->get( 'social_facebook', '' );
$ig = $cfg->get( 'social_instagram', '' );
$li = $cfg->get( 'social_linkedin', '' );
$tw = $cfg->get( 'social_twitter', '' );

$newspapers = $db->select(
    "SELECT id, name, slug FROM {$db->t('newspapers')} WHERE is_active=1 ORDER BY sort_order ASC, name ASC LIMIT 5"
) ?: [];
$top_cities = $db->select(
    "SELECT id, name, slug FROM {$db->t('cities')} WHERE is_active=1 ORDER BY tier ASC, name ASC LIMIT 5"
) ?: [];
?>
<section class="nhp-payments">
  <div class="nhp-container nhp-payments__inner">
    <div class="nhp-payments__label">
      <i class="fa-solid fa-shield-halved"></i>
      <span>Secure payments powered by Razorpay</span>
    </div>
    <div class="nhp-payments__icons" aria-hidden="true">
      <span class="nhp-pay-icon"><i class="fa-brands fa-cc-visa"></i></span>
      <span class="nhp-pay-icon"><i class="fa-brands fa-cc-mastercard"></i></span>
      <span class="nhp-pay-icon"><i class="fa-solid fa-mobile-screen"></i> UPI</span>
      <span class="nhp-pay-icon"><i class="fa-solid fa-building-columns"></i> Net Banking</span>
      <span class="nhp-pay-icon"><i class="fa-solid fa-file-invoice"></i> GST Invoice</span>
    </div>
  </div>
</section>

<footer class="nhp-footer">
  <div class="nhp-container">
    <div class="nhp-footer__grid">
      <div>
        <div class="nhp-footer__brand-name">
          <i class="fa-solid fa-newspaper"></i> <?php echo esc_html( $brand ); ?>
        </div>
        <p class="nhp-footer__tagline"><?php echo esc_html( $cfg->get( 'footer_tagline', 'Book newspaper ads online across India — fast, transparent, reliable.' ) ); ?></p>
        <?php if ( $email || $phone ) : ?>
        <div class="nhp-footer__contact">
          <?php if ( $email ) : ?><div><i class="fa-solid fa-envelope"></i> <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></div><?php endif; ?>
          <?php if ( $phone ) : ?><div style="margin-top:6px"><i class="fa-solid fa-phone"></i> <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="nhp-footer__social">
          <?php if ( $fb ) : ?><a href="<?php echo esc_url( $fb ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-facebook-f"></i></a><?php endif; ?>
          <?php if ( $ig ) : ?><a href="<?php echo esc_url( $ig ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-instagram"></i></a><?php endif; ?>
          <?php if ( $li ) : ?><a href="<?php echo esc_url( $li ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-linkedin-in"></i></a><?php endif; ?>
          <?php if ( $tw ) : ?><a href="<?php echo esc_url( $tw ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-x-twitter"></i></a><?php endif; ?>
          <?php if ( $walink ) : ?><a href="<?php echo esc_url( $walink ); ?>" target="_blank" rel="noopener" style="color:#25D366"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?>
        </div>
      </div>
      <div class="nhp-footer__col">
        <h4>Newspapers</h4>
        <ul>
          <?php foreach ( $newspapers as $np ) : ?>
          <li><a href="<?php echo esc_url( $booking_url . '?newspaper=' . urlencode( $np['id'] ) ); ?>"><?php echo esc_html( $np['name'] ); ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?php echo esc_url( $papers_url ); ?>">View All →</a></li>
        </ul>
      </div>
      <div class="nhp-footer__col">
        <h4>Top Cities</h4>
        <ul>
          <?php foreach ( $top_cities as $city ) : ?>
          <li><a href="<?php echo esc_url( $booking_url . '?city=' . urlencode( $city['id'] ) ); ?>"><?php echo esc_html( $city['name'] ); ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?php echo esc_url( $cities_url ); ?>">All Cities →</a></li>
        </ul>
      </div>
      <div class="nhp-footer__col">
        <h4>Support</h4>
        <ul>
          <li><a href="<?php echo esc_url( $booking_url ); ?>">Book an Ad</a></li>
          <li><a href="<?php echo esc_url( home_url( '/pricing/' ) ); ?>">Pricing</a></li>
          <li><a href="<?php echo esc_url( $track_url ); ?>">Track Order</a></li>
          <li><a href="<?php echo esc_url( $faq_url ); ?>">FAQ</a></li>
          <li><a href="<?php echo esc_url( home_url( '/support/' ) ); ?>">Support</a></li>
          <li><a href="<?php echo esc_url( $contact_url ); ?>">Contact Us</a></li>
          <li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>">About Us</a></li>
        </ul>
      </div>
    </div>
    <div class="nhp-footer__bottom">
      <div class="nhp-footer__copy">© <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( $brand ); ?>. All rights reserved.</div>
      <div class="nhp-footer__legal">
        <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a>
        <a href="<?php echo esc_url( home_url( '/terms-conditions/' ) ); ?>">Terms</a>
        <a href="<?php echo esc_url( home_url( '/refund-policy/' ) ); ?>">Refund Policy</a>
      </div>
    </div>
  </div>
</footer>

<?php if ( $walink ) : ?>
<a href="<?php echo esc_url( $walink ); ?>" class="nhp-wa-float" target="_blank" rel="noopener" title="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
<?php endif; ?>
