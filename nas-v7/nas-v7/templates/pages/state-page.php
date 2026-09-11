<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * State landing page — /states/{state-slug}/
 */
use NAS\Core\Database;

$db         = Database::instance();
$state_slug = $GLOBALS['nas_route_data']['state_slug'] ?? get_query_var( 'nas_state_slug', '' );
$state_name = ucwords( str_replace( '-', ' ', $state_slug ) );

$cities = $db->select( "SELECT id, name, slug, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 AND LOWER(REPLACE(state,' ','-')) = LOWER(%s) ORDER BY tier ASC, population DESC", [ $state_slug ] );
if ( empty( $cities ) ) {
    $cities = $db->select( "SELECT id, name, slug, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 AND LOWER(state) LIKE %s ORDER BY tier ASC, population DESC", [ '%' . str_replace( '-', ' ', $state_slug ) . '%' ] );
}

$booking_url = nas_portal_booking_url();
$tier1 = array_filter( $cities, fn( $c ) => (int) $c['tier'] === 1 );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">State Coverage</span>
      <h1>Newspaper Ads in <span><?php echo esc_html( $state_name ); ?></span></h1>
      <p>Book classified &amp; display newspaper ads in <?php echo count( $cities ); ?> cities across <?php echo esc_html( $state_name ); ?> — verified publishers, instant rates.</p>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--lg" style="margin-top:20px">Book an Ad <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $tier1 ) : ?>
  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Major Cities in <?php echo esc_html( $state_name ); ?></h2>
        <p>Tier-1 cities with the highest circulation and advertising reach.</p>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
        <?php foreach ( $tier1 as $c ) : ?>
        <a href="<?php echo esc_url( home_url( '/newspaper-ads/' . ( $c['slug'] ?: sanitize_title( $c['name'] ) ) . '/' ) ); ?>" class="nas-portal-feature" style="text-decoration:none;color:inherit">
          <div class="nas-portal-feature__icon"><i class="fa-solid fa-city"></i></div>
          <h3><?php echo esc_html( $c['name'] ); ?></h3>
          <p>Tier <?php echo (int) $c['tier']; ?> · <?php echo esc_html( $state_name ); ?></p>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>All Cities in <?php echo esc_html( $state_name ); ?></h2>
        <p><?php echo count( $cities ); ?> cities available for newspaper ad booking.</p>
      </div>
      <?php if ( $cities ) : ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">
        <?php foreach ( $cities as $c ) : ?>
        <a href="<?php echo esc_url( home_url( '/newspaper-ads/' . ( $c['slug'] ?: sanitize_title( $c['name'] ) ) . '/' ) ); ?>" class="nas-portal-feature" style="text-decoration:none;color:inherit;padding:20px">
          <h3 style="margin:0 0 4px;font-size:1rem"><?php echo esc_html( $c['name'] ); ?></h3>
          <p style="margin:0;font-size:0.8125rem">Tier <?php echo (int) $c['tier']; ?> City</p>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else : ?>
      <div style="text-align:center;padding:60px;color:var(--nas-text-muted)">
        <i class="fa-solid fa-map-location-dot" style="font-size:3rem;margin-bottom:16px;display:block;opacity:.3"></i>
        <p>No cities found for <?php echo esc_html( $state_name ); ?>. <a href="<?php echo esc_url( home_url( '/cities/' ) ); ?>">View all cities</a></p>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php nas_portal_block_formats(); ?>
  <?php nas_portal_block_process( 'How It Works', 'Book in ' . $state_name, 'Select your city, choose a newspaper, and publish — all online.' ); ?>
  <?php nas_portal_block_advantages(); ?>
  <?php nas_portal_block_cta( 'Advertise in ' . $state_name . ' Today', 'Reach audiences across ' . $state_name . ' with India\'s trusted newspaper ad platform.', $booking_url ); ?>
</div>
