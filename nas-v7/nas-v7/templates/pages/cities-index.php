<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All cities index — content fragment
 */
use NAS\Core\Database;

$db     = Database::instance();
$cities = $db->select( "SELECT id, name, slug, state, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, population DESC" ) ?: [];

$by_state = [];
foreach ( $cities as $c ) {
    $by_state[ $c['state'] ][] = $c;
}
ksort( $by_state );

$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Pan-India Coverage</span>
      <h1>Book Newspaper Ads <span>Across India</span></h1>
      <p><?php echo count( $cities ); ?>+ cities · <?php echo count( $by_state ); ?> states · All major newspapers</p>
      <input type="search" id="nas-city-search" class="nas-portal-search-hero" placeholder="Search your city…" autocomplete="off" aria-label="Search cities">
    </div>
  </div>

  <section class="nas-portal-section nas-portal-section--muted">
    <div style="max-width:1200px;margin:0 auto" id="nas-cities-container">
      <?php foreach ( $by_state as $state => $state_cities ) : ?>
      <div class="nas-state-group" style="margin-bottom:40px" data-state="<?php echo esc_attr( strtolower( $state ) ); ?>">
        <h2 style="font-family:var(--nas-font-display);font-size:1.25rem;font-weight:800;color:var(--nas-text);margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid var(--nas-border)"><?php echo esc_html( $state ); ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:10px">
          <?php foreach ( $state_cities as $city ) : ?>
          <a href="<?php echo esc_url( home_url( '/newspaper-ads/' . ( $city['slug'] ?: sanitize_title( $city['name'] ) ) . '/' ) ); ?>"
             class="nas-portal-city-link nas-city-link" data-name="<?php echo esc_attr( strtolower( $city['name'] ) ); ?>">
            <?php echo esc_html( $city['name'] ); ?>
            <?php if ( (int) $city['tier'] === 1 ) : ?><span class="nas-portal-city-link__badge">Top</span><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div id="nas-no-results" style="display:none;text-align:center;padding:60px;color:var(--nas-text-muted)">
        <i class="fa-solid fa-magnifying-glass" style="font-size:2.5rem;margin-bottom:16px;display:block;opacity:.3"></i>
        <p>No cities found. <a href="<?php echo esc_url( $booking_url ); ?>">Book directly</a> and our team will assist you.</p>
      </div>
    </div>
  </section>

  <section class="nas-portal-cta-band">
    <h2>Can't Find Your City?</h2>
    <p>We cover hundreds of editions nationwide. Start booking and our team will confirm availability.</p>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg">Start Booking <i class="fa-solid fa-arrow-right"></i></a>
  </section>
</div>

<script>
document.getElementById('nas-city-search').addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  var groups = document.querySelectorAll('.nas-state-group');
  var anyVisible = false;
  groups.forEach(function(g) {
    var stateMatch = !q || g.dataset.state.includes(q);
    var visibleLinks = 0;
    g.querySelectorAll('.nas-city-link').forEach(function(l) {
      var show = !q || l.dataset.name.includes(q) || stateMatch;
      l.style.display = show ? '' : 'none';
      if (show) visibleLinks++;
    });
    g.style.display = visibleLinks > 0 ? '' : 'none';
    if (visibleLinks > 0) anyVisible = true;
  });
  document.getElementById('nas-no-results').style.display = anyVisible ? 'none' : '';
});
</script>
