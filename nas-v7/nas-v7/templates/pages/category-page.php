<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Category SEO page — /categories/{category-slug}/
 */
use NAS\Core\Database;

$db       = Database::instance();
$cat_slug = $GLOBALS['nas_route_data']['category_slug'] ?? get_query_var( 'nas_category_slug', '' );
$category = $db->row( "SELECT * FROM `{$db->t('categories')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [ $cat_slug ] );
if ( ! $category ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

$cat_name    = $category['name'];
$cat_desc    = wp_kses_post( $category['description'] );
$booking_url = home_url( '/book-newspaper-ad/?category=' . urlencode( $category['name'] ) );
$samples     = $db->select( "SELECT * FROM `{$db->t('sample_ads')}` WHERE is_active=1 AND (category_id=%d OR category_id=0) ORDER BY sort_order ASC LIMIT 6", [ $category['id'] ] ) ?: [];
$cities      = $db->select( "SELECT id, name, slug, tier FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, population DESC LIMIT 24" ) ?: [];
$newspapers  = $db->select( "SELECT id, name, slug, logo_url, language FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 8" ) ?: [];
?>
<div class="nas-portal-page">
  <section class="nhp-hero" style="min-height:auto;padding:clamp(56px,8vw,88px) 0;text-align:center">
    <div class="nhp-hero__bg" aria-hidden="true"></div>
    <div class="nhp-container" style="position:relative;z-index:2">
      <div style="font-size:3rem;margin-bottom:16px"><?php echo esc_html( $category['icon'] ?? '📰' ); ?></div>
      <span class="nhp-hero__eyebrow">Ad Category</span>
      <h1 class="nhp-hero__title" style="max-width:700px;margin:0 auto 16px"><?php echo esc_html( $cat_name ); ?> Ads in <em>Newspapers</em></h1>
      <?php if ( $cat_desc ) : ?><p class="nhp-hero__subtitle" style="max-width:600px;margin:0 auto 28px"><?php echo $cat_desc; ?></p><?php endif; ?>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--xl">Book a <?php echo esc_html( $cat_name ); ?> Ad <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </section>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $samples ) : ?>
  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Sample <?php echo esc_html( $cat_name ); ?> Ads</h2>
        <p>Use these templates as inspiration — our booking wizard includes sample ads and AI copywriting assistance.</p>
      </div>
      <div class="nas-portal-feature-grid">
        <?php foreach ( $samples as $ad ) : ?>
        <div class="nas-portal-feature">
          <h3><?php echo esc_html( $ad['title'] ?? 'Sample Ad' ); ?></h3>
          <p><?php echo esc_html( wp_trim_words( $ad['content'] ?? '', 40 ) ); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center;margin-top:32px">
        <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--lg">Use a Template to Book <i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php nas_portal_block_formats(); ?>

  <?php if ( $newspapers ) : ?>
  <section class="nhp-section">
    <div class="nhp-container">
      <div class="nhp-section__header nhp-section__header--center">
        <span class="nhp-section__eyebrow">Publications</span>
        <h2 class="nhp-section__title">Popular Newspapers for <?php echo esc_html( $cat_name ); ?> Ads</h2>
      </div>
      <div class="nhp-papers-grid">
        <?php foreach ( $newspapers as $i => $np ) : ?>
        <article class="nhp-paper-card nhp-paper-card--a<?php echo (int) ( $i % 6 ); ?>">
          <div class="nhp-paper-card__top">
            <div class="nhp-paper-card__logo">
              <?php if ( $np['logo_url'] ) : ?>
              <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" loading="lazy">
              <?php else : ?>
              <span class="nhp-paper-card__logo-fallback"><?php echo esc_html( strtoupper( substr( $np['name'], 0, 2 ) ) ); ?></span>
              <?php endif; ?>
            </div>
            <div>
              <h3 class="nhp-paper-card__name"><?php echo esc_html( $np['name'] ); ?></h3>
              <p class="nhp-paper-card__meta"><?php echo esc_html( $np['language'] ?: 'English' ); ?></p>
            </div>
          </div>
          <a href="<?php echo esc_url( $booking_url . '&newspaper=' . urlencode( $np['id'] ) ); ?>" class="nhp-paper-card__cta">Book <?php echo esc_html( $cat_name ); ?> Ad <i class="fa-solid fa-arrow-right"></i></a>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ( $cities ) : ?>
  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Book <?php echo esc_html( $cat_name ); ?> Ads by City</h2>
        <p>Select your city to see available newspapers and instant rates.</p>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
        <?php foreach ( $cities as $city ) : ?>
        <a href="<?php echo esc_url( home_url( '/book-newspaper-ad/?city=' . urlencode( $city['id'] ) . '&category=' . urlencode( $category['name'] ) ) ); ?>" class="nas-portal-city-link" style="justify-content:center"><?php echo esc_html( $city['name'] ); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php nas_portal_block_process(); ?>
  <?php nas_portal_block_advantages(); ?>
  <?php nas_portal_block_cta( 'Book Your ' . $cat_name . ' Ad Now', 'Instant rates across India\'s leading newspapers — secure payment and online tracking.', $booking_url ); ?>
</div>
