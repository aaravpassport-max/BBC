<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pricing page — serves /pricing/
 */
use NAS\Core\Database;
$db      = Database::instance();
$settings = $db->row("SELECT gst_rate, brand_name FROM `{$db->t('settings')}` LIMIT 1") ?: [];
$gst_rate = $settings['gst_rate'] ?? 18;
$brand    = esc_html( $settings['brand_name'] ?? get_bloginfo('name') );
$categories = $db->select("SELECT name, icon FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 8");

include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Newspaper Ad Pricing | <?php echo $brand; ?></title>
<meta name="description" content="Transparent newspaper ad pricing. Classified from ₹5/word, Display from ₹300/sq.cm. GST included. No hidden charges.">
<?php wp_head(); ?>
</head>
<body class="nas-fullpage nas-pricing-page">

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:700px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px">Simple, Transparent Pricing</h1>
    <p style="font-size:17px;opacity:.85;margin:0 0 28px">No hidden charges. All prices include <?php echo (int)$gst_rate; ?>% GST.</p>
    <a href="<?php echo esc_url(home_url('/book-newspaper-ad/')); ?>" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;text-decoration:none">Get an Instant Quote →</a>
  </div>
</section>

<section style="padding:60px 20px;background:#f8fafc">
  <div style="max-width:1000px;margin:0 auto">
    <h2 style="text-align:center;font-size:28px;font-weight:700;margin:0 0 40px;color:#0f172a">Ad Type Pricing</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px">

      <!-- Classified -->
      <div style="background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center">
        <div style="font-size:36px;margin-bottom:12px">📝</div>
        <h3 style="font-size:22px;font-weight:700;margin:0 0 8px;color:#0f172a">Classified Text</h3>
        <div style="font-size:36px;font-weight:800;color:#2563eb;margin:16px 0 4px">₹5<span style="font-size:16px;font-weight:400;color:#64748b">/word</span></div>
        <p style="color:#64748b;font-size:14px;margin:0 0 20px">Minimum 30 words · Most affordable</p>
        <ul style="text-align:left;list-style:none;padding:0;margin:0 0 24px;font-size:14px;color:#475569;display:flex;flex-direction:column;gap:8px">
          <li>✅ Matrimonial, jobs, property</li>
          <li>✅ Same-day processing</li>
          <li>✅ All major newspapers</li>
          <li>✅ AI writing assistance</li>
        </ul>
        <a href="<?php echo esc_url(home_url('/book-newspaper-ad/?ad_type=classified')); ?>" style="display:block;background:#2563eb;color:#fff;padding:12px;border-radius:8px;font-weight:600;text-decoration:none">Book Classified Ad</a>
      </div>

      <!-- Display -->
      <div style="background:#fff;border:2px solid #f59e0b;border-radius:16px;padding:32px;text-align:center;position:relative">
        <div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:#f59e0b;color:#0f172a;padding:4px 16px;border-radius:20px;font-size:12px;font-weight:700">MOST POPULAR</div>
        <div style="font-size:36px;margin-bottom:12px">🖼️</div>
        <h3 style="font-size:22px;font-weight:700;margin:0 0 8px;color:#0f172a">Display Ad</h3>
        <div style="font-size:36px;font-weight:800;color:#f59e0b;margin:16px 0 4px">₹300<span style="font-size:16px;font-weight:400;color:#64748b">/sq.cm</span></div>
        <p style="color:#64748b;font-size:14px;margin:0 0 20px">Custom sizes · High visibility</p>
        <ul style="text-align:left;list-style:none;padding:0;margin:0 0 24px;font-size:14px;color:#475569;display:flex;flex-direction:column;gap:8px">
          <li>✅ Images & logos allowed</li>
          <li>✅ Front page options</li>
          <li>✅ Colour or B&W</li>
          <li>✅ Proof before publication</li>
        </ul>
        <a href="<?php echo esc_url(home_url('/book-newspaper-ad/?ad_type=display')); ?>" style="display:block;background:#f59e0b;color:#0f172a;padding:12px;border-radius:8px;font-weight:600;text-decoration:none">Book Display Ad</a>
      </div>

      <!-- Display Classified -->
      <div style="background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center">
        <div style="font-size:36px;margin-bottom:12px">📰</div>
        <h3 style="font-size:22px;font-weight:700;margin:0 0 8px;color:#0f172a">Display Classified</h3>
        <div style="font-size:36px;font-weight:800;color:#7c3aed;margin:16px 0 4px">₹150<span style="font-size:16px;font-weight:400;color:#64748b">/sq.cm</span></div>
        <p style="color:#64748b;font-size:14px;margin:0 0 20px">Best of both worlds</p>
        <ul style="text-align:left;list-style:none;padding:0;margin:0 0 24px;font-size:14px;color:#475569;display:flex;flex-direction:column;gap:8px">
          <li>✅ Text + small image</li>
          <li>✅ Classified section placement</li>
          <li>✅ More impact than text-only</li>
          <li>✅ Budget-friendly display</li>
        </ul>
        <a href="<?php echo esc_url(home_url('/book-newspaper-ad/?ad_type=display_classified')); ?>" style="display:block;background:#7c3aed;color:#fff;padding:12px;border-radius:8px;font-weight:600;text-decoration:none">Book This Ad</a>
      </div>
    </div>
  </div>
</section>

<section style="padding:40px 20px;background:#fff;text-align:center">
  <p style="color:#64748b;font-size:14px;max-width:700px;margin:0 auto">
    All prices shown are base rates. Final price depends on newspaper, city, edition, and word count. GST @ <?php echo (int)$gst_rate; ?>% applicable. Get an exact quote instantly by <a href="<?php echo esc_url(home_url('/book-newspaper-ad/')); ?>">using the booking wizard</a>.
  </p>
</section>

<?php wp_footer(); ?>
</body>
</html>
