<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All newspapers index — enterprise content fragment
 */
use NAS\Core\Database;

$db         = Database::instance();
$newspapers = $db->select( "SELECT id, name, slug, language, logo_url, base_rate_classified, base_rate_display, cities_supported, circulation FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC" ) ?: [];

$by_lang = [];
$featured = array_slice( $newspapers, 0, 6 );
foreach ( $newspapers as $np ) {
    $by_lang[ $np['language'] ?: 'English' ][] = $np;
}
ksort( $by_lang );

$booking_url = nas_portal_booking_url();
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Publications</span>
      <h1>India's Leading <span>Newspapers</span></h1>
      <p><?php echo count( $newspapers ); ?> verified publications · English, Hindi &amp; regional languages · Instant online booking</p>
      <input type="search" id="nas-np-search" class="nas-portal-search-hero" placeholder="Search newspapers by name…" autocomplete="off" aria-label="Search newspapers">
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php nas_portal_block_formats(); ?>

  <?php if ( $featured ) : ?>
  <section class="nhp-section nhp-section--marketplace nas-portal-section--compact">
    <div class="nhp-container">
      <div class="nhp-section__header nhp-section__header--center">
        <span class="nhp-section__eyebrow">Featured</span>
        <h2 class="nhp-section__title">Most Booked Publications</h2>
        <p class="nhp-section__subtitle">Top newspapers advertisers book through our platform — with verified rates and instant confirmation.</p>
      </div>
      <div class="nhp-papers-grid">
        <?php foreach ( $featured as $i => $np ) :
            $from_price = max( (float) ( $np['base_rate_classified'] ?? 0 ), 0 );
            $detail_url = $np['slug'] ? home_url( '/newspapers/' . $np['slug'] . '/' ) : $booking_url . '?newspaper=' . urlencode( $np['id'] );
        ?>
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
              <?php if ( $i < 3 ) : ?><span class="nhp-paper-card__badge"><i class="fa-solid fa-star"></i> Popular</span><?php endif; ?>
            </div>
          </div>
          <?php if ( $from_price > 0 ) : ?>
          <p class="nhp-paper-card__price">From <strong>₹<?php echo number_format( $from_price, 0 ); ?></strong>/word</p>
          <?php endif; ?>
          <a href="<?php echo esc_url( $detail_url ); ?>" class="nhp-paper-card__cta">Book This Newspaper <i class="fa-solid fa-arrow-right"></i></a>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>All Newspapers by Language</h2>
        <p>Browse the complete directory — filter by search or explore by language group.</p>
      </div>
      <?php foreach ( $by_lang as $lang => $papers ) : ?>
      <div class="nas-lang-group" data-lang="<?php echo esc_attr( strtolower( $lang ) ); ?>">
        <h3 class="nas-lang-group__title"><?php echo esc_html( $lang ); ?> <span>(<?php echo count( $papers ); ?>)</span></h3>
        <div class="nas-lang-group__grid">
          <?php foreach ( $papers as $np ) :
              $cnt = count( json_decode( $np['cities_supported'] ?? '[]', true ) ?: [] );
          ?>
          <div class="nas-portal-np-card nas-np-card" data-name="<?php echo esc_attr( strtolower( $np['name'] ) ); ?>">
            <div class="nas-np-card__row">
              <?php if ( $np['logo_url'] ) : ?>
              <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" class="nas-np-card__logo">
              <?php else : ?>
              <div class="nas-np-card__logo-fallback"><?php echo esc_html( substr( $np['name'], 0, 2 ) ); ?></div>
              <?php endif; ?>
              <div>
                <div class="nas-np-card__name"><?php echo esc_html( $np['name'] ); ?></div>
                <div class="nas-np-card__meta"><?php echo $cnt ? esc_html( $cnt . ' cities' ) : 'Pan India'; ?><?php if ( ! empty( $np['circulation'] ) ) : ?> · <?php echo esc_html( number_format( (int) $np['circulation'] ) ); ?> circulation<?php endif; ?></div>
              </div>
            </div>
            <div class="nas-np-card__rates">Classified from <strong>₹<?php echo number_format( (float) $np['base_rate_classified'], 0 ); ?>/word</strong> · Display from <strong>₹<?php echo number_format( (float) $np['base_rate_display'], 0 ); ?>/sq.cm</strong></div>
            <a href="<?php echo esc_url( $np['slug'] ? home_url( '/newspapers/' . $np['slug'] . '/' ) : $booking_url . '?newspaper=' . urlencode( $np['name'] ) ); ?>"
               class="nas-btn nas-btn-primary nas-np-card__btn">Book This Newspaper</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div id="nas-np-noresults" class="nas-portal-noresults">No newspapers found.</div>
    </div>
  </section>

  <?php nas_portal_block_advantages(); ?>

  <?php nas_portal_block_accent_band(
      'Find Your Newspaper & Book Today',
      'Compare rates, choose your edition, and publish — all in one workflow.',
      $booking_url,
      'Start Booking'
  ); ?>
</div>

<script>
document.getElementById('nas-np-search').addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  var anyVisible = false;
  document.querySelectorAll('.nas-lang-group').forEach(function(g) {
    var shown = 0;
    g.querySelectorAll('.nas-np-card').forEach(function(c) {
      var show = !q || c.dataset.name.includes(q) || g.dataset.lang.includes(q);
      c.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    g.style.display = shown ? '' : 'none';
    if (shown) anyVisible = true;
  });
  document.getElementById('nas-np-noresults').style.display = anyVisible ? 'none' : '';
});
</script>
