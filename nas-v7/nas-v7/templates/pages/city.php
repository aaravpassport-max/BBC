<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * City landing page — serves /newspaper-ads/{city-slug}/
 * Data injected via Router: $GLOBALS['nas_route_data']['city_slug']
 */
use NAS\Core\Database;

$db        = Database::instance();
$city_slug = $GLOBALS['nas_route_data']['city_slug'] ?? get_query_var('nas_city_slug','');

// Load city record
$city = $db->row( "SELECT * FROM `{$db->t('cities')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [ $city_slug ] );
if ( ! $city ) {
    $city = $db->row( "SELECT * FROM `{$db->t('cities')}` WHERE LOWER(name) = LOWER(%s) AND is_active = 1 LIMIT 1", [ str_replace('-',' ',$city_slug) ] );
}
if ( ! $city ) { global $wp_query; $wp_query->set_404(); status_header(404); include(get_query_template('404')); exit; }

$city_name   = esc_html( $city['name'] );
$state       = esc_html( $city['state'] );
$booking_url = home_url( '/book-newspaper-ad/?city=' . urlencode( $city['name'] ) );
$seo_title   = $city['seo_title'] ?: "Book Newspaper Ads in {$city['name']} | Best Rates";
$seo_desc    = $city['seo_desc']  ?: "Book classified & display newspaper ads in {$city['name']}, {$city['state']}. Fast processing, verified publishers, best rates guaranteed.";

// Load newspapers for this city
$all_papers  = $db->select("SELECT id, name, slug, logo_url, language, base_rate_classified, base_rate_display, description FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC");
$newspapers  = array_filter( $all_papers, function($p) use ($city_name) {
    $cities = json_decode($p['cities_supported'] ?? '[]', true) ?: [];
    return empty($cities) || in_array($city_name, $cities) || count(array_filter($cities, fn($c)=>stripos($c,$city_name)!==false));
});
$newspapers = array_values($newspapers);

// Load categories
$categories = $db->select("SELECT id, name, slug, icon, description FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC LIMIT 12");

?>
<div class="nas-portal-page">
<!-- Hero -->
<section class="nas-city-hero" style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:80px 20px 60px;text-align:center;color:#fff">
  <div style="max-width:860px;margin:0 auto">
    <div style="margin-bottom:16px">
      <span style="background:rgba(255,255,255,.15);padding:4px 14px;border-radius:20px;font-size:13px"><?php echo $state; ?></span>
    </div>
    <h1 style="font-size:clamp(28px,5vw,48px);font-weight:800;margin:0 0 18px;line-height:1.15">
      Book Newspaper Ads in <?php echo $city_name; ?>
    </h1>
    <p style="font-size:18px;opacity:.85;margin:0 0 32px;max-width:640px;margin-inline:auto">
      Classified &amp; display ads in <?php echo $city_name; ?>'s top newspapers — quick, simple, at the best rates.
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:24px">
      <span style="background:rgba(255,255,255,.12);padding:6px 16px;border-radius:20px;font-size:14px"><i class="fa-solid fa-bolt"></i> Same-day Processing</span>
      <span style="background:rgba(255,255,255,.12);padding:6px 16px;border-radius:20px;font-size:14px"><i class="fa-solid fa-shield-check"></i> Verified Publishers</span>
      <span style="background:rgba(255,255,255,.12);padding:6px 16px;border-radius:20px;font-size:14px"><i class="fa-solid fa-headset"></i> Dedicated Support</span>
    </div>
    <a href="<?php echo esc_url($booking_url); ?>" class="nas-btn nas-btn-primary nas-btn-xl" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:16px 36px;border-radius:10px;font-weight:700;font-size:17px;text-decoration:none">
      <i class="fa-solid fa-pen-nib"></i> Book an Ad in <?php echo $city_name; ?>
    </a>
  </div>
</section>

<!-- Categories -->
<?php if ($categories): ?>
<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="text-align:center;font-size:28px;font-weight:700;margin:0 0 36px;color:#0f172a">Ad Categories in <?php echo $city_name; ?></h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px">
      <?php foreach ($categories as $cat): ?>
      <a href="<?php echo esc_url($booking_url.'&category='.urlencode($cat['name'])); ?>" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px 12px;text-align:center;text-decoration:none;color:#334155;transition:all .2s;display:block">
        <div style="font-size:32px;margin-bottom:8px"><?php echo esc_html($cat['icon'] ?? '📰'); ?></div>
        <div style="font-weight:600;font-size:14px"><?php echo esc_html($cat['name']); ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Newspapers in City -->
<section id="newspapers" style="padding:60px 20px;background:#fff">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="text-align:center;font-size:28px;font-weight:700;margin:0 0 12px;color:#0f172a">Newspapers in <?php echo $city_name; ?></h2>
    <p style="text-align:center;color:#64748b;margin:0 0 36px"><?php echo count($newspapers); ?> active publications available</p>
    <?php if ($newspapers): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px">
      <?php foreach ($newspapers as $np): ?>
      <div style="border:1.5px solid #e2e8f0;border-radius:14px;padding:24px;background:#fff">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
          <?php if ($np['logo_url']): ?>
          <img src="<?php echo esc_url($np['logo_url']); ?>" alt="<?php echo esc_attr($np['name']); ?>" style="width:48px;height:48px;object-fit:contain;border-radius:8px">
          <?php else: ?>
          <div style="width:48px;height:48px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#64748b"><?php echo esc_html(substr($np['name'],0,2)); ?></div>
          <?php endif; ?>
          <div>
            <div style="font-weight:700;font-size:16px;color:#0f172a"><?php echo esc_html($np['name']); ?></div>
            <div style="font-size:13px;color:#64748b"><?php echo esc_html($np['language']); ?></div>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:16px;font-size:13px;color:#64748b">
          <span>Classified from <strong style="color:#0f172a">₹<?php echo number_format($np['base_rate_classified'],0); ?>/word</strong></span>
          <span>Display from <strong style="color:#0f172a">₹<?php echo number_format($np['base_rate_display'],0); ?>/sq.cm</strong></span>
        </div>
        <a href="<?php echo esc_url($booking_url.'&newspaper='.urlencode($np['name'])); ?>" style="display:block;text-align:center;background:#0f172a;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Book This Newspaper</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:48px;color:#64748b">
      <i class="fa-solid fa-newspaper" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
      <p>Newspaper listings for <?php echo $city_name; ?> are being updated. <a href="<?php echo esc_url($booking_url); ?>">Book directly</a> and our team will assist you.</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- CTA -->
<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:60px 20px;text-align:center;color:#fff">
  <div style="max-width:640px;margin:0 auto">
    <h2 style="font-size:32px;font-weight:800;margin:0 0 16px">Ready to book your ad in <?php echo $city_name; ?>?</h2>
    <p style="opacity:.85;margin:0 0 28px;font-size:17px">Join thousands of businesses who trust us for newspaper advertising.</p>
    <a href="<?php echo esc_url($booking_url); ?>" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:16px 40px;border-radius:10px;font-weight:700;font-size:17px;text-decoration:none">Get Started →</a>
  </div>
</section>

</div>
