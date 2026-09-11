<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All cities index — serves /newspaper-ads/
 */
use NAS\Core\Database;
$db     = Database::instance();
$cities = $db->select("SELECT id, name, slug, state, tier, population FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, population DESC");

// Group by state
$by_state = [];
foreach ($cities as $c) { $by_state[$c['state']][] = $c; }
ksort($by_state);

include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Book Newspaper Ads in India | All Cities</title>
<meta name="description" content="Book classified and display newspaper ads in <?php echo count($cities); ?>+ cities across India. Fast, reliable, best rates.">
<?php wp_head(); ?>
</head>
<body class="nas-fullpage nas-cities-index">

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:800px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,48px);font-weight:800;margin:0 0 16px">Book Newspaper Ads Across India</h1>
    <p style="font-size:18px;opacity:.85;margin:0 0 24px"><?php echo count($cities); ?>+ cities · <?php echo count($by_state); ?> states · All major newspapers</p>
    <input type="text" id="nas-city-search" placeholder="Search your city…" autocomplete="off"
      style="width:100%;max-width:460px;padding:14px 20px;border-radius:10px;border:none;font-size:16px;outline:none">
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1200px;margin:0 auto" id="nas-cities-container">
    <?php foreach ($by_state as $state => $state_cities): ?>
    <div class="nas-state-group" style="margin-bottom:40px" data-state="<?php echo esc_attr(strtolower($state)); ?>">
      <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0"><?php echo esc_html($state); ?></h2>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($state_cities as $city): ?>
        <a href="<?php echo esc_url(home_url('/newspaper-ads/'.($city['slug']?:sanitize_title($city['name'])).'/'));?>"
           class="nas-city-link" data-name="<?php echo esc_attr(strtolower($city['name'])); ?>"
           style="background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 18px;text-decoration:none;color:#334155;font-size:14px;font-weight:500;transition:border-color .2s">
          <?php echo esc_html($city['name']); ?>
          <?php if ($city['tier'] == 1): ?><span style="font-size:10px;background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:10px;margin-left:6px">Top</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div id="nas-no-results" style="display:none;text-align:center;padding:60px;color:#64748b">
      <i class="fa-solid fa-magnifying-glass" style="font-size:40px;margin-bottom:16px;display:block;opacity:.3"></i>
      <p>No cities found. <a href="<?php echo esc_url(home_url('/book-newspaper-ad/')); ?>">Book directly</a> and our team will assist you.</p>
    </div>
  </div>
</section>

<script>
document.getElementById('nas-city-search').addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  var links = document.querySelectorAll('.nas-city-link');
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

<?php wp_footer(); ?>
</body>
</html>
