<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * All newspapers index — serves /newspapers/
 */
use NAS\Core\Database;
$db         = Database::instance();
$newspapers = $db->select("SELECT id, name, slug, language, logo_url, base_rate_classified, base_rate_display, cities_supported FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC");

// Group by language
$by_lang = [];
foreach ($newspapers as $np) { $by_lang[$np['language']][] = $np; }
ksort($by_lang);

include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>All Newspapers | Book Ads Online</title>
<meta name="description" content="Book ads in <?php echo count($newspapers); ?>+ newspapers across India. Classified & display ads in English, Hindi, regional language newspapers.">
<?php wp_head(); ?>
</head>
<body class="nas-fullpage nas-newspapers-index">

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:800px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px">All Newspapers</h1>
    <p style="font-size:17px;opacity:.85;margin:0 0 24px"><?php echo count($newspapers); ?> publications · English, Hindi &amp; regional</p>
    <input type="text" id="nas-np-search" placeholder="Search newspapers…" autocomplete="off"
      style="width:100%;max-width:440px;padding:14px 20px;border-radius:10px;border:none;font-size:16px;outline:none">
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1200px;margin:0 auto">
    <?php foreach ($by_lang as $lang => $papers): ?>
    <div class="nas-lang-group" style="margin-bottom:40px" data-lang="<?php echo esc_attr(strtolower($lang)); ?>">
      <h2 style="font-size:20px;font-weight:700;margin:0 0 20px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;color:#0f172a"><?php echo esc_html($lang); ?> Newspapers</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px">
        <?php foreach ($papers as $np): ?>
        <div class="nas-np-card" data-name="<?php echo esc_attr(strtolower($np['name'])); ?>" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px">
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
            <?php if ($np['logo_url']): ?>
            <img src="<?php echo esc_url($np['logo_url']); ?>" alt="<?php echo esc_attr($np['name']); ?>" style="width:44px;height:44px;object-fit:contain;border-radius:6px;border:1px solid #f1f5f9">
            <?php else: ?>
            <div style="width:44px;height:44px;background:#e2e8f0;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#64748b;font-size:14px"><?php echo esc_html(substr($np['name'],0,2)); ?></div>
            <?php endif; ?>
            <div>
              <div style="font-weight:700;font-size:15px;color:#0f172a"><?php echo esc_html($np['name']); ?></div>
              <div style="font-size:12px;color:#94a3b8"><?php $cnt=count(json_decode($np['cities_supported']??'[]',true)?:[]); echo $cnt ? $cnt.' cities' : 'Pan India'; ?></div>
            </div>
          </div>
          <div style="font-size:12px;color:#64748b;margin-bottom:14px">Classified from <strong>₹<?php echo number_format($np['base_rate_classified'],0); ?>/word</strong></div>
          <a href="<?php echo esc_url($np['slug'] ? home_url('/newspapers/'.esc_attr($np['slug']).'/') : home_url('/book-newspaper-ad/?newspaper='.urlencode($np['name']))); ?>"
             style="display:block;text-align:center;background:#0f172a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px">Book This Newspaper</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div id="nas-np-noresults" style="display:none;text-align:center;padding:60px;color:#64748b">No newspapers found.</div>
  </div>
</section>

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

<?php wp_footer(); ?>
</body>
</html>
