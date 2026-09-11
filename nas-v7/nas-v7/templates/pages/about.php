<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * About page — serves /about/
 */
$brand = nas_config('brand_name', get_bloginfo('name'));
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>About Us | <?php echo esc_html($brand); ?></title>
<meta name="description" content="<?php echo esc_attr($brand); ?> — India's trusted online newspaper ad booking platform. Book classified & display ads in 300+ cities.">
<?php wp_head(); ?>
</head>
<body class="nas-fullpage nas-about-page">

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:70px 20px 50px;text-align:center;color:#fff">
  <div style="max-width:700px;margin:0 auto">
    <h1 style="font-size:clamp(26px,5vw,46px);font-weight:800;margin:0 0 16px">About <?php echo esc_html($brand); ?></h1>
    <p style="font-size:18px;opacity:.85;margin:0">India's trusted platform for newspaper ad booking — fast, reliable, transparent.</p>
  </div>
</section>

<section style="padding:70px 20px;background:#fff">
  <div style="max-width:900px;margin:0 auto">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;margin-bottom:60px">
      <div>
        <h2 style="font-size:28px;font-weight:700;margin:0 0 16px;color:#0f172a">Our Mission</h2>
        <p style="color:#475569;line-height:1.8;font-size:16px">We make newspaper advertising simple and accessible for every business in India. From a single classified ad to a full-page display, we handle everything — so you can focus on your business.</p>
      </div>
      <div style="background:#f8fafc;border-radius:16px;padding:32px;text-align:center">
        <div style="font-size:48px;margin-bottom:12px">📰</div>
        <div style="font-size:36px;font-weight:800;color:#2563eb;margin-bottom:4px">300+</div>
        <div style="color:#64748b">Cities Covered</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:60px">
      <?php $stats = [['🗞️','50+','Newspapers'],['🏙️','300+','Cities'],['⭐','10,000+','Happy Clients']];
      foreach ($stats as $s): ?>
      <div style="background:#f8fafc;border-radius:14px;padding:28px;text-align:center">
        <div style="font-size:36px;margin-bottom:8px"><?php echo $s[0]; ?></div>
        <div style="font-size:32px;font-weight:800;color:#0f172a;margin-bottom:4px"><?php echo $s[1]; ?></div>
        <div style="color:#64748b;font-size:14px"><?php echo $s[2]; ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="background:#f8fafc;border-radius:16px;padding:40px;text-align:center">
      <h2 style="font-size:24px;font-weight:700;margin:0 0 12px;color:#0f172a">Why Choose <?php echo esc_html($brand); ?>?</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:24px;text-align:left">
        <?php $why = [['✅','Instant Quote','Get exact pricing in seconds — no calls needed'],['✅','Verified Publishers','Every newspaper is directly partnered'],['✅','Real-time Tracking','Follow your ad from submission to publication'],['✅','AI Copywriting','Let AI help write a compelling ad']];
        foreach ($why as $w): ?>
        <div><div style="font-weight:700;margin-bottom:4px;color:#0f172a"><?php echo $w[0]; ?> <?php echo $w[1]; ?></div><div style="font-size:13px;color:#64748b"><?php echo $w[2]; ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section style="background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:60px 20px;text-align:center;color:#fff">
  <h2 style="font-size:28px;font-weight:700;margin:0 0 16px">Ready to place your ad?</h2>
  <a href="<?php echo esc_url(home_url('/book-newspaper-ad/')); ?>" style="display:inline-block;background:#f59e0b;color:#0f172a;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;text-decoration:none">Book Now →</a>
</section>

<?php wp_footer(); ?>
</body>
</html>
