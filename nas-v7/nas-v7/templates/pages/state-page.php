<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * State landing page — serves /states/{state-slug}/
 */
use NAS\Core\Database;

$db         = Database::instance();
$state_slug = $GLOBALS['nas_route_data']['state_slug'] ?? get_query_var('nas_state_slug','');
$state_name = ucwords( str_replace('-',' ', $state_slug) );

// Load cities in this state
$cities = $db->select("SELECT id, name, slug, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 AND LOWER(REPLACE(state,' ','-')) = LOWER(%s) ORDER BY tier ASC, population DESC", [$state_slug]);
if ( empty($cities) ) {
    $cities = $db->select("SELECT id, name, slug, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 AND LOWER(state) LIKE %s ORDER BY tier ASC, population DESC", ['%'.str_replace('-',' ',$state_slug).'%']);
}

?>
<div class="nas-portal-page">
<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:800px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px">Newspaper Ads in <?php echo esc_html($state_name); ?></h1>
    <p style="font-size:17px;opacity:.85;margin:0 0 28px">Select your city to book classified or display newspaper ads at the best rates.</p>
    <a href="<?php echo esc_url(home_url('/book-newspaper-ad/')); ?>" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;text-decoration:none">Book an Ad Now</a>
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="font-size:26px;font-weight:700;margin:0 0 32px;color:#0f172a">Cities in <?php echo esc_html($state_name); ?></h2>
    <?php if ($cities): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">
      <?php foreach ($cities as $c): ?>
      <a href="<?php echo esc_url(home_url('/newspaper-ads/'.($c['slug']?:sanitize_title($c['name'])).'/'));?>" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px;text-decoration:none;color:#0f172a;display:block;transition:border-color .2s">
        <div style="font-weight:700;font-size:16px;margin-bottom:4px"><?php echo esc_html($c['name']); ?></div>
        <div style="font-size:12px;color:#94a3b8">Tier <?php echo (int)$c['tier']; ?> City</div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:60px;color:#64748b">
      <i class="fa-solid fa-map-location-dot" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
      <p>No cities found for <?php echo esc_html($state_name); ?>. <a href="<?php echo esc_url(home_url('/newspaper-ads/')); ?>">View all cities</a></p>
    </div>
    <?php endif; ?>
  </div>
</section>

</div>
