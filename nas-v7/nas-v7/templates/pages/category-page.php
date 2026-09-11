<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Category SEO page — serves /categories/{category-slug}/
 */
use NAS\Core\Database;

$db       = Database::instance();
$cat_slug = $GLOBALS['nas_route_data']['category_slug'] ?? get_query_var('nas_category_slug','');
$category = $db->row("SELECT * FROM `{$db->t('categories')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [$cat_slug]);
if ( ! $category ) { global $wp_query; $wp_query->set_404(); status_header(404); include(get_query_template('404')); exit; }

$cat_name    = esc_html($category['name']);
$cat_desc    = wp_kses_post($category['description']);
$booking_url = home_url('/book-newspaper-ad/?category='.urlencode($category['name']));

// Sample ads for this category
$samples = $db->select("SELECT * FROM `{$db->t('sample_ads')}` WHERE is_active=1 AND (category_id=%d OR category_id=0) ORDER BY sort_order ASC LIMIT 6", [$category['id']]);

// Top cities
$cities = $db->select("SELECT id, name, slug FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, population DESC LIMIT 24");

?>
<div class="nas-portal-page">
<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:800px;margin:0 auto">
    <div style="font-size:48px;margin-bottom:16px"><?php echo esc_html($category['icon'] ?? '📰'); ?></div>
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px"><?php echo $cat_name; ?> Ads in Newspapers</h1>
    <?php if ($cat_desc): ?><p style="font-size:17px;opacity:.85;margin:0 0 28px;max-width:600px;margin-inline:auto"><?php echo $cat_desc; ?></p><?php endif; ?>
    <a href="<?php echo esc_url($booking_url); ?>" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;text-decoration:none">Book a <?php echo $cat_name; ?> Ad</a>
  </div>
</section>

<?php if ($samples): ?>
<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="font-size:26px;font-weight:700;margin:0 0 32px;color:#0f172a;text-align:center">Sample <?php echo $cat_name; ?> Ads</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px">
      <?php foreach ($samples as $ad): ?>
      <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px">
        <div style="font-weight:600;font-size:15px;color:#0f172a;margin-bottom:8px"><?php echo esc_html($ad['title'] ?? ''); ?></div>
        <div style="color:#475569;font-size:14px;line-height:1.6"><?php echo esc_html($ad['content'] ?? ''); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:32px">
      <a href="<?php echo esc_url($booking_url); ?>" style="display:inline-block;background:#0f172a;color:#fff;padding:14px 28px;border-radius:10px;font-weight:700;text-decoration:none">Use a Template to Book Your Ad</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($cities): ?>
<section style="padding:60px 20px;background:#fff">
  <div style="max-width:1100px;margin:0 auto">
    <h2 style="font-size:26px;font-weight:700;margin:0 0 32px;text-align:center;color:#0f172a">Book <?php echo $cat_name; ?> Ads by City</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
      <?php foreach ($cities as $city): ?>
      <a href="<?php echo esc_url(home_url('/book-newspaper-ad/?city='.urlencode($city['name']).'&category='.urlencode($category['name']))); ?>" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;text-decoration:none;color:#334155;font-size:13px;font-weight:500"><?php echo esc_html($city['name']); ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

</div>
