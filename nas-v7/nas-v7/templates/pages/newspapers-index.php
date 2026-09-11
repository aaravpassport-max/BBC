<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All newspapers index — content fragment
 */
use NAS\Core\Database;

$db         = Database::instance();
$newspapers = $db->select( "SELECT id, name, slug, language, logo_url, base_rate_classified, base_rate_display, cities_supported FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC" ) ?: [];

$by_lang = [];
foreach ( $newspapers as $np ) {
    $by_lang[ $np['language'] ?: 'English' ][] = $np;
}
ksort( $by_lang );

$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Publications</span>
      <h1>All <span>Newspapers</span></h1>
      <p><?php echo count( $newspapers ); ?> publications · English, Hindi &amp; regional languages</p>
      <input type="search" id="nas-np-search" class="nas-portal-search-hero" placeholder="Search newspapers…" autocomplete="off" aria-label="Search newspapers">
    </div>
  </div>

  <section class="nas-portal-section nas-portal-section--muted">
    <div style="max-width:1200px;margin:0 auto">
      <?php foreach ( $by_lang as $lang => $papers ) : ?>
      <div class="nas-lang-group" style="margin-bottom:40px" data-lang="<?php echo esc_attr( strtolower( $lang ) ); ?>">
        <h2 style="font-family:var(--nas-font-display);font-size:1.25rem;font-weight:800;margin:0 0 20px;padding-bottom:10px;border-bottom:2px solid var(--nas-border);color:var(--nas-text)"><?php echo esc_html( $lang ); ?> Newspapers</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px">
          <?php foreach ( $papers as $np ) :
              $cnt = count( json_decode( $np['cities_supported'] ?? '[]', true ) ?: [] );
          ?>
          <div class="nas-portal-np-card nas-np-card" data-name="<?php echo esc_attr( strtolower( $np['name'] ) ); ?>">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
              <?php if ( $np['logo_url'] ) : ?>
              <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" style="width:44px;height:44px;object-fit:contain;border-radius:8px;border:1px solid var(--nas-border)">
              <?php else : ?>
              <div style="width:44px;height:44px;background:var(--nas-border);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--nas-text-muted);font-size:14px"><?php echo esc_html( substr( $np['name'], 0, 2 ) ); ?></div>
              <?php endif; ?>
              <div>
                <div style="font-weight:700;font-size:0.9375rem;color:var(--nas-text)"><?php echo esc_html( $np['name'] ); ?></div>
                <div style="font-size:0.75rem;color:var(--nas-text-muted)"><?php echo $cnt ? esc_html( $cnt . ' cities' ) : 'Pan India'; ?></div>
              </div>
            </div>
            <div style="font-size:0.8125rem;color:var(--nas-text-muted);margin-bottom:14px">Classified from <strong>₹<?php echo number_format( (float) $np['base_rate_classified'], 0 ); ?>/word</strong></div>
            <a href="<?php echo esc_url( $np['slug'] ? home_url( '/newspapers/' . $np['slug'] . '/' ) : $booking_url . '?newspaper=' . urlencode( $np['name'] ) ); ?>"
               class="nas-btn nas-btn-primary" style="width:100%;justify-content:center;font-size:0.8125rem">Book This Newspaper</a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div id="nas-np-noresults" style="display:none;text-align:center;padding:60px;color:var(--nas-text-muted)">No newspapers found.</div>
    </div>
  </section>

  <section class="nas-portal-cta-band">
    <h2>Ready to Book Your Ad?</h2>
    <p>Compare rates across publications and get an instant quote in our booking wizard.</p>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg">Check Ad Rates <i class="fa-solid fa-arrow-right"></i></a>
  </section>
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
