<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Newspaper detail page — serves /newspapers/{newspaper-slug}/
 */
use NAS\Core\Database;

$db       = Database::instance();
$np_slug  = $GLOBALS['nas_route_data']['newspaper_slug'] ?? get_query_var('nas_newspaper_slug','');
$paper    = $db->row("SELECT * FROM `{$db->t('newspapers')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [$np_slug]);
if ( ! $paper ) { global $wp_query; $wp_query->set_404(); status_header(404); include(get_query_template('404')); exit; }

$name         = esc_html($paper['name']);
$language     = esc_html($paper['language']);
$desc         = wp_kses_post($paper['description']);
$cities       = json_decode($paper['cities_supported'] ?? '[]', true) ?: [];
$editions     = json_decode($paper['editions'] ?? '[]', true) ?: [];
$booking_url  = home_url('/book-newspaper-ad/?newspaper='.urlencode($paper['name']));

?>
<div class="nas-portal-page">
<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;color:#fff">
  <div style="max-width:1000px;margin:0 auto;display:flex;align-items:center;gap:32px;flex-wrap:wrap">
    <?php if ($paper['logo_url']): ?>
    <img src="<?php echo esc_url($paper['logo_url']); ?>" alt="<?php echo esc_attr($name); ?>" style="width:90px;height:90px;object-fit:contain;background:#fff;border-radius:12px;padding:8px">
    <?php endif; ?>
    <div>
      <h1 style="font-size:clamp(24px,4vw,42px);font-weight:800;margin:0 0 10px"><?php echo $name; ?></h1>
      <p style="opacity:.8;margin:0;font-size:16px"><?php echo $language; ?> | <?php echo count($cities); ?> cities covered | <?php echo count($editions); ?> editions</p>
    </div>
    <a href="<?php echo esc_url($booking_url); ?>" style="margin-left:auto;display:inline-block;background:#f59e0b;color:#0f172a;padding:14px 28px;border-radius:10px;font-weight:700;font-size:16px;text-decoration:none;white-space:nowrap">Book Ad Now</a>
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1000px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr;gap:32px;align-items:start">
    <div>
      <?php if ($desc): ?>
      <div style="background:#fff;border-radius:14px;padding:28px;margin-bottom:24px;border:1.5px solid #e2e8f0">
        <h2 style="font-size:20px;font-weight:700;margin:0 0 14px;color:#0f172a">About <?php echo $name; ?></h2>
        <div style="color:#475569;line-height:1.7"><?php echo $desc; ?></div>
      </div>
      <?php endif; ?>
      <?php if ($cities): ?>
      <div style="background:#fff;border-radius:14px;padding:28px;border:1.5px solid #e2e8f0">
        <h2 style="font-size:20px;font-weight:700;margin:0 0 16px;color:#0f172a">Available Cities</h2>
        <div style="display:flex;flex-wrap:wrap;gap:10px">
          <?php foreach ($cities as $city): ?>
          <a href="<?php echo esc_url(home_url('/newspaper-ads/'.sanitize_title($city).'/'));?>" style="background:#f1f5f9;padding:6px 14px;border-radius:20px;font-size:13px;color:#334155;text-decoration:none"><?php echo esc_html($city); ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div style="background:#fff;border-radius:14px;padding:28px;border:1.5px solid #e2e8f0;position:sticky;top:20px">
        <h3 style="font-size:18px;font-weight:700;margin:0 0 18px;color:#0f172a">Ad Rates</h3>
        <div style="display:flex;flex-direction:column;gap:14px">
          <div style="display:flex;justify-content:space-between;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
            <span style="color:#64748b;font-size:14px">Classified (per word)</span>
            <strong>₹<?php echo number_format($paper['base_rate_classified'],2); ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
            <span style="color:#64748b;font-size:14px">Display (per sq.cm)</span>
            <strong>₹<?php echo number_format($paper['base_rate_display'],2); ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:#64748b;font-size:14px">Min. charge</span>
            <strong>₹<?php echo number_format($paper['min_charge'],2); ?></strong>
          </div>
        </div>
        <a href="<?php echo esc_url($booking_url); ?>" style="display:block;text-align:center;background:#0f172a;color:#fff;padding:14px 20px;border-radius:10px;font-weight:700;text-decoration:none;margin-top:20px">Book Ad in <?php echo $name; ?></a>
      </div>
    </div>
  </div>
</section>

</div>
