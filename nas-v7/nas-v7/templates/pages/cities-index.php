<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All cities index — enterprise content fragment
 */
use NAS\Core\Database;

$db     = Database::instance();
$cities = $db->select( "SELECT id, name, slug, state, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, population DESC" ) ?: [];

$by_state = [];
$tier1    = [];
foreach ( $cities as $c ) {
    $by_state[ $c['state'] ][] = $c;
    if ( (int) $c['tier'] === 1 ) {
        $tier1[] = $c;
    }
}
ksort( $by_state );
$tier1 = array_slice( $tier1, 0, 12 );

$booking_url = nas_portal_booking_url();
$newspapers_url = home_url( '/newspapers/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Pan-India Coverage</span>
      <h1>Book Newspaper Ads <span>Across India</span></h1>
      <p><?php echo count( $cities ); ?>+ cities · <?php echo count( $by_state ); ?> states · English, Hindi &amp; regional language newspapers</p>
      <input type="search" id="nas-city-search" class="nas-portal-search-hero" placeholder="Search your city or state…" autocomplete="off" aria-label="Search cities">
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $tier1 ) : ?>
  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Top Metro &amp; Tier-1 Cities</h2>
        <p>Book ads in India's largest advertising markets — instant rates available online.</p>
      </div>
      <div class="nas-portal-city-grid">
        <?php foreach ( $tier1 as $city ) : ?>
        <a href="<?php echo esc_url( home_url( '/newspaper-ads/' . ( $city['slug'] ?: sanitize_title( $city['name'] ) ) . '/' ) ); ?>"
           class="nas-portal-feature nas-portal-feature--link">
          <div class="nas-portal-feature__icon"><i class="fa-solid fa-city"></i></div>
          <h3><?php echo esc_html( $city['name'] ); ?></h3>
          <p><?php echo esc_html( $city['state'] ); ?></p>
          <span class="nas-portal-city-link__badge">Tier 1</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>All Cities by State</h2>
        <p>Browse every city we cover, grouped by state. Can't find yours? Book directly and we'll confirm availability.</p>
      </div>
      <div id="nas-cities-container">
        <?php foreach ( $by_state as $state => $state_cities ) : ?>
        <div class="nas-state-group" style="margin-bottom:40px" data-state="<?php echo esc_attr( strtolower( $state ) ); ?>">
          <h3 style="font-family:var(--nas-font-display);font-size:1.125rem;font-weight:800;color:var(--nas-text);margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid var(--nas-border)"><?php echo esc_html( $state ); ?> <span style="color:var(--nas-text-muted);font-weight:600;font-size:0.875rem">(<?php echo count( $state_cities ); ?>)</span></h3>
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
    </div>
  </section>

  <?php nas_portal_block_process( 'How City Booking Works', 'Book an Ad in Your City', 'Select your city, choose a newspaper, and get published — all online.' ); ?>

  <?php nas_portal_block_advantages(); ?>

  <?php
  nas_portal_block_faq( [
      [ 'Is my city covered?', 'We cover ' . count( $cities ) . '+ cities across India. Search above or start the booking wizard — if your city isn\'t listed, book directly and our team will confirm newspaper availability.' ],
      [ 'Do rates differ by city?', 'Yes. The same newspaper may have different rates for different city editions. Our booking wizard shows the exact rate for your selected city and edition.' ],
      [ 'Can I book in multiple cities at once?', 'Each booking is for one city/edition. For multi-city campaigns, place separate bookings or contact our team for campaign support.' ],
  ], 'Cities FAQ' );
  ?>

  <?php nas_portal_block_accent_band(
      'Ready to Advertise in Your City?',
      'Instant rates for ' . count( $cities ) . '+ cities — book in minutes.',
      $booking_url,
      'Check Rates in Your City'
  ); ?>
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
