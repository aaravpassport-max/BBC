<?php
if (!defined('ABSPATH')) exit;
$co  = $company    ?? get_option('rtoflow_company_name', 'RTOASSIST');
$tel = $phone      ?? get_option('rtoflow_company_phone', '');
$lc  = $lead_count ?? 0;
$cc  = $city_count ?? 0;
// CORRECTED (service-claims audit): $rating_avg/$rating_count come from a
// real query against rto_ratings (see Router::routeSomething() above the
// view dispatch). Neither is padded with a fake floor — if there isn't
// enough real rating data yet, the badge below is simply not shown rather
// than displaying an invented number.
$ratingAvg   = $rating_avg   ?? null;
$ratingCount = $rating_count ?? 0;

// Real testimonials (design pass): $testimonials comes from a genuine
// rto_ratings query in Router.php (score>=4, non-empty comment, not soft-
// deleted). We reduce the joined WP display_name to "First L." here — first
// name plus last-initial only — so no full name/phone/email is ever printed,
// and we never fabricate a row: if the array is empty, the view below shows
// the honest aggregate-rating card instead (or nothing, if that has no data
// either). Nothing here invents content.
if (!function_exists('rtoflow_home_short_name')) {
function rtoflow_home_short_name(string $full): string {
    $full = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === '') return 'Customer';
    $parts = explode(' ', $full);
    $first = $parts[0];
    if (count($parts) > 1) {
        $last = end($parts);
        return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
    }
    return $first;
}
}
$testimonialItems = [];
foreach (($testimonials ?? []) as $t) {
    $comment = trim((string)($t['comment'] ?? ''));
    if ($comment === '') continue;
    $testimonialItems[] = [
        'name'    => rtoflow_home_short_name((string)($t['client_name'] ?? '')),
        'city'    => trim((string)($t['city_name'] ?? '')),
        'service' => trim((string)($t['service_name'] ?? '')),
        'score'   => max(1, min(5, (int)($t['score'] ?? 5))),
        'comment' => $comment,
    ];
}
$cp = get_option('rtoflow_color_primary',   '#1B2A6B');
$cs = get_option('rtoflow_color_secondary', '#E97B28');
$ca = get_option('rtoflow_color_accent',    '#16A34A');
$cd = get_option('rtoflow_color_bg_dark',   '#0A1628');

// Home Hero Section slider (see database/migrations/
// 2026_09_09_002_create_hero_slides.php + HeroSliderController). $hero_slides
// / $hero_settings are passed in by Router.php's home() renderer; the
// fallback here only covers a direct/manual rto_view('public.home', ...)
// call that omitted them, so this view never fatals for want of a variable.
$heroSlides   = $hero_slides ?? [];
$heroSettings = $hero_settings ?? \RTOFLOW\Controllers\Admin\HeroSliderController::loadSettingsForFrontend();
$heroBreakpoint = (int) ($heroSettings['general']['breakpoint'] ?? 760);

// Design pass — "RTO Seva" reference rebuild: category "icons" are genuinely
// drawn inline-SVG marks (unchanged from the previous pass — hex-code below
// is used both as a gradient stop on old surfaces and as the flat fallback
// color), looked up by the short 'icon' key in $catIcons.
$cat_config = [
    'Driving License'    => ['icon'=>'dl',  'c1'=>'#3355D8','c2'=>'#1B2A6B','label'=>'Driving License'],
    'RC Services'        => ['icon'=>'rc',  'c1'=>'#F5A623','c2'=>'#B4670E','label'=>'RC Services'],
    'HP / Hypothecation' => ['icon'=>'hp',  'c1'=>'#9B5DE5','c2'=>'#6B21A8','label'=>'Hypothecation (HP)'],
    'NOC'                => ['icon'=>'noc', 'c1'=>'#2FBE73','c2'=>'#166534','label'=>'NOC'],
    'Vehicle Services'   => ['icon'=>'veh', 'c1'=>'#F0537A','c2'=>'#9F1239','label'=>'Vehicle Services'],
    'Commercial Vehicle' => ['icon'=>'com', 'c1'=>'#D6A928','c2'=>'#854D0E','label'=>'Commercial Vehicle'],
    'Other Services'     => ['icon'=>'oth', 'c1'=>'#64748B','c2'=>'#334155','label'=>'Other Services'],
];
$catIcons = [
    'dl'  => '<path d="M9 20l1.6-9.6A3 3 0 0 1 13.55 8H20a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3h-2M9 20H5a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2h2.3M9 20l.6-3.6M15 13.5h4.5M15 16.5h3" stroke-width="1.6"/><circle cx="12.2" cy="16.4" r="1.9" stroke-width="1.6"/>',
    'rc'  => '<rect x="3.5" y="7.5" width="17" height="10" rx="2.4" stroke-width="1.6"/><path d="M3.5 11.5h17" stroke-width="1.6"/><path d="M7 14.7h4" stroke-width="1.6"/><circle cx="16.3" cy="14.7" r="1.5" stroke-width="1.6"/>',
    'hp'  => '<path d="M12 3.4l7.5 3.3v5.1c0 4.7-3.1 7.7-7.5 8.8-4.4-1.1-7.5-4.1-7.5-8.8V6.7z" stroke-width="1.6"/><path d="M9 12.2l2.1 2.1L15.4 10" stroke-width="1.6"/>',
    'noc' => '<path d="M7 3.2h7.2l3.8 3.8v13.8H7z" stroke-width="1.6"/><path d="M14.2 3.2v3.8H18" stroke-width="1.6"/><path d="M9.6 17.2l1.9 1.9 3.4-3.9" stroke-width="1.6"/>',
    'veh' => '<path d="M4.2 15.4l1.4-4.6a2 2 0 0 1 1.9-1.4h9a2 2 0 0 1 1.9 1.4l1.4 4.6" stroke-width="1.6"/><rect x="3.2" y="15.4" width="17.6" height="4.4" rx="1.6" stroke-width="1.6"/><circle cx="7.3" cy="19.8" r="1.35" stroke-width="1.6"/><circle cx="16.7" cy="19.8" r="1.35" stroke-width="1.6"/>',
    'com' => '<path d="M3 16V8.4A1.4 1.4 0 0 1 4.4 7h7.2A1.4 1.4 0 0 1 13 8.4V16" stroke-width="1.6"/><path d="M13 11h3.6L20 14v2h-2" stroke-width="1.6"/><rect x="3" y="16" width="14" height="0.01" stroke-width="1.6"/><circle cx="7.2" cy="18.3" r="1.6" stroke-width="1.6"/><circle cx="16.2" cy="18.3" r="1.6" stroke-width="1.6"/>',
    'oth' => '<circle cx="12" cy="12" r="8.2" stroke-width="1.6"/><path d="M9.4 9.4a2.6 2.6 0 1 1 3.4 2.5c-.8.3-1.3.9-1.3 1.9" stroke-width="1.6"/><circle cx="11.6" cy="16.3" r=".15" stroke-width="2.6"/>',
];
// NEW (RTO Seva reference rebuild): a small fixed pastel palette that the
// services grid cycles through per card, same technique $cat_config already
// uses for its gradient accent colors — just a softer, block-color set of
// {bg, ic} pairs instead of a single gradient. Nothing here is per-category
// "branding", it is purely a repeating 5-color cycle so the grid reads like
// the reference's color-blocked cards.
$pastels = [
    ['bg'=>'#EAF1FF','ic'=>'#2F6FED'], // soft blue
    ['bg'=>'#FDEAF0','ic'=>'#E23F6B'], // soft pink/red
    ['bg'=>'#E8F8EF','ic'=>'#1FAE63'], // soft green
    ['bg'=>'#FFF1E3','ic'=>'#C2660F'], // soft orange
    ['bg'=>'#F1EAFB','ic'=>'#7C3AED'], // soft purple
];
$biz = ['@type'=>'LocalBusiness','name'=>$co,'description'=>'Expert RTO services across India.','areaServed'=>['@type'=>'Country','name'=>'India'],'url'=>home_url('/'),'telephone'=>($tel?:null),'priceRange'=>'500-5000 INR'];
// Genuine SEO addition (design pass): only emitted when we have real
// aggregate data (unchanged threshold used elsewhere on this page) and/or
// real individual reviews — never fabricated to satisfy the schema.
if ($ratingAvg !== null && $ratingCount >= 5) {
    $biz['aggregateRating'] = ['@type'=>'AggregateRating','ratingValue'=>$ratingAvg,'reviewCount'=>$ratingCount,'bestRating'=>5,'worstRating'=>1];
}
if (!empty($testimonialItems)) {
    $biz['review'] = array_map(function($t){
        return [
            '@type'=>'Review',
            'author'=>['@type'=>'Person','name'=>$t['name']],
            'reviewRating'=>['@type'=>'Rating','ratingValue'=>$t['score'],'bestRating'=>5,'worstRating'=>1],
            'reviewBody'=>$t['comment'],
        ];
    }, array_slice($testimonialItems, 0, 6));
}
$schema = json_encode(['@context'=>'https://schema.org','@graph'=>[
    $biz,
    ['@type'=>'WebSite','url'=>home_url('/'),'name'=>$co],
]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
// CORRECTED (service-claims audit): the old title/meta claimed universal
// "Doorstep Assistance" for every service across India. Home pickup/drop is
// only available where a local agent or courier partner covers that city
// and is not included free with every service — see the disclosure block
// further down this page and on each service page for the real, per-city
// position. Wording changed to describe what we actually and consistently
// provide (application assistance, guidance, documentation help) rather
// than a doorstep guarantee.
$pt = $co . ' - RTO Application Assistance Across India';
$md = 'RTO application assistance across India — RC Transfer, Driving License, NOC, Hypothecation & 30+ services. Home pickup/drop where available (may involve additional charges); final approval rests with the RTO.';

// Tracking-mockup stages, kept identical in wording/order to the real
// timeline used by resources/views/public/track.php ($statusOrder / label
// text) so this decorative mockup never invents different stage names than
// the actual product. Only a representative subset is shown for the visual.
$trackMock = [
    ['label'=>'Submitted',           'done'=>true],
    ['label'=>'Documents Verified',  'done'=>true],
    ['label'=>'Processing',          'done'=>true],
    ['label'=>'RTO Processing',      'done'=>false],
    ['label'=>'Completed',           'done'=>false],
];

// Real "top categories" for the hero's quick-filter pills — sorted by actual
// service count in $by_cat (falls back to config order if no data), capped
// at 4 to match the reference's pill row. Category names, not invented tags.
$popQuick = $cat_config;
uksort($popQuick, function($a, $b) use ($by_cat) {
    return count($by_cat[$b] ?? []) <=> count($by_cat[$a] ?? []);
});
$popQuick = array_slice($popQuick, 0, 4, true);

// "Most Popular" category = real highest service count in $by_cat (used for
// both the services-grid ribbon and, honestly, nowhere else).
$popularCat = null; $popularMax = 0;
foreach ($cat_config as $cn0 => $ci0) {
    $n0 = count($by_cat[$cn0] ?? []);
    if ($n0 > $popularMax) { $popularMax = $n0; $popularCat = $cn0; }
}

// WhatsApp deep-link built from the same real, already-published phone
// number used elsewhere on this page (top bar / header / footer) — never a
// separate/fabricated contact channel.
$waNumber = $tel ? preg_replace('/\D+/', '', $tel) : '';
if ($waNumber && substr($waNumber, 0, 2) !== '91' && strlen($waNumber) === 10) $waNumber = '91' . $waNumber;
$waLink = $waNumber ? 'https://wa.me/' . $waNumber : '';

// Featured services for marketplace grid (real DB data, capped for layout).
$featuredServices = array_slice($services ?? [], 0, 9);
$svcTotal = count($services ?? []);
$dockCities = $city_list ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc_html($pt) ?></title>
<meta name="description" content="<?= esc_attr($md) ?>">
<meta name="robots" content="index,follow">
<link rel="canonical" href="<?= esc_url(home_url('/')) ?>">
<meta property="og:title" content="<?= esc_attr($pt) ?>">
<meta property="og:description" content="<?= esc_attr($md) ?>">
<meta property="og:type" content="website">
<script type="application/ld+json"><?= $schema ?></script>
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/rtoflow-home.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/rtoflow-home.css')) ?>">
<style>
.rfh-page{
  --rfh-brand:<?php echo esc_attr($cp); ?>;
  --rfh-accent:<?php echo esc_attr($cs); ?>;
  --rfh-success:<?php echo esc_attr($ca); ?>;
  --rfh-dark:<?php echo esc_attr($cd); ?>;
}
/* Hero slider baseline (admin-managed — unchanged) */
.hs-hero{position:relative;overflow:hidden;background:#EEF3FB;width:100%}
.hs-slide-desktop-media{display:block}
.hs-slide-mobile-media{display:none}
@media(max-width:<?php echo (int)$heroBreakpoint; ?>px){
  .hs-slide-desktop-media{display:none}
  .hs-slide-mobile-media{display:block}
}
</style>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/hero-slider.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/hero-slider.css')) ?>">
<style>
@media(max-width:<?php echo (int)$heroBreakpoint; ?>px){
  .hs-cp-mobile-center        { align-items:center; justify-content:center; text-align:center }
  .hs-cp-mobile-center-left   { align-items:center; justify-content:flex-start; text-align:left }
  .hs-cp-mobile-center-right  { align-items:center; justify-content:flex-end; text-align:right }
  .hs-cp-mobile-top-left      { align-items:flex-start; justify-content:flex-start; text-align:left }
  .hs-cp-mobile-top-center    { align-items:flex-start; justify-content:center; text-align:center }
  .hs-cp-mobile-top-right     { align-items:flex-start; justify-content:flex-end; text-align:right }
  .hs-cp-mobile-bottom-left   { align-items:flex-end; justify-content:flex-start; text-align:left }
  .hs-cp-mobile-bottom-center { align-items:flex-end; justify-content:center; text-align:center }
  .hs-cp-mobile-bottom-right  { align-items:flex-end; justify-content:flex-end; text-align:right }
}
</style>
<?php $heroFirstSlide = $heroSlides[0] ?? null; ?>
<?php if ($heroFirstSlide): ?>
<link rel="preload" as="image" href="<?= esc_url($heroFirstSlide['desktop_image_url']) ?>" media="(min-width:<?= (int)$heroBreakpoint + 1 ?>px)">
<link rel="preload" as="image" href="<?= esc_url($heroFirstSlide['mobile_image_url']) ?>" media="(max-width:<?= (int)$heroBreakpoint ?>px)">
<?php endif; ?>
<link rel="stylesheet" href="<?= esc_url(home_url('/rto-design.css')) ?>?v=<?= esc_attr(\RTOFLOW\Services\DesignSettingsService::version()) ?>">
</head>
<body class="rto-website rfh-page has-bottom-nav">
<a class="rfh-skip" href="#rfh-main">Skip to content</a>
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>

<div class="rfh-topbar">
  <div class="rfh-container rfh-topbar-in">
    <div class="rfh-topbar-left">
      <?php if ($tel): ?><a href="tel:<?php echo esc_attr($tel); ?>"><i class="fa-solid fa-phone"></i><?php echo esc_html($tel); ?></a><?php endif; ?>
      <span class="mid">Mon–Sat 9AM–7PM</span>
      <span class="mid">Pan India RTO Assistance</span>
    </div>
    <div class="rfh-topbar-right">
      <a href="<?php echo esc_url(home_url('/rto-track/')); ?>"><i class="fa-solid fa-location-crosshairs"></i>Track application</a>
      <?php if ($waLink): ?><a href="<?php echo esc_url($waLink); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i>WhatsApp</a><?php endif; ?>
    </div>
  </div>
</div>

<header class="rfh-header" id="rfhHeader">
  <div class="rfh-container rfh-header-in">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="rfh-logo">
      <span class="rfh-logo-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg></span>
      <span><b><?php echo esc_html($co); ?></b><small>Drive legal. Drive easy.</small></span>
    </a>
    <nav class="rfh-nav" id="rfhNav" aria-label="Main navigation">
      <a href="<?php echo esc_url(home_url('/')); ?>" class="is-active">Home</a>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>">Services</a>
      <a href="<?php echo esc_url(home_url('/rto-services-cities')); ?>">RTO Info</a>
      <a href="<?php echo esc_url(home_url('/rto-track/')); ?>">Track</a>
      <a href="<?php echo esc_url(home_url('/how-it-works')); ?>">Guides</a>
      <a href="<?php echo esc_url(home_url('/about')); ?>">About</a>
    </nav>
    <div class="rfh-header-actions">
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-icon-btn" aria-label="Search services"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></a>
      <?php if (is_user_logged_in()): ?>
      <a href="<?php echo esc_url(home_url('/rto-dashboard/')); ?>" class="rfh-btn rfh-btn--secondary">Dashboard</a>
      <?php else: ?>
      <a href="<?php echo esc_url(home_url('/login')); ?>" class="rfh-btn rfh-btn--outline">Login</a>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--primary">Get started</a>
      <?php endif; ?>
      <button type="button" class="rfh-menu-toggle" id="rfhMenuToggle" aria-label="Toggle menu" aria-expanded="false">&#9776;</button>
    </div>
  </div>
</header>

<?php if (!empty($heroSlides)): ?>
<?php
  // Home Hero Section Slider — replaces the previous single hardcoded
  // hero-desktop.jpg/hero-mobile.jpg <picture> pair with N admin-managed
  // slides (see database/migrations/2026_09_09_002_create_hero_slides.php,
  // HeroSliderController, resources/assets/js/hero-slider.js). Every slide
  // renders BOTH its Desktop and Mobile <img>, one shown at a time purely
  // via the CSS breakpoint rule generated above — this is the same
  // "preload both, let CSS decide" approach the old static hero already
  // used, so there is no JS-driven image swap that could cause a flash of
  // the wrong image or a layout shift while it resolves.
  $heroFitCss = function (string $fit): string {
      return $fit === 'auto' ? 'none' : $fit; // CSS object-fit has no 'auto' value — map to 'none' (natural size)
  };
  $heroPosCss = function (string $pos): string {
      $map = [
          'center' => 'center', 'center-top' => 'center top', 'center-bottom' => 'center bottom',
          'left' => 'left center', 'right' => 'right center',
          'top-left' => 'left top', 'top-right' => 'right top',
          'bottom-left' => 'left bottom', 'bottom-right' => 'right bottom',
          'custom' => 'center',
      ];
      return $map[$pos] ?? 'center';
  };
  $ds = $heroSettings['desktop']; $ms = $heroSettings['mobile'];
  $heroStyleVars = sprintf(
      '--hs-d-height:%dpx;--hs-d-min:%dpx;--hs-d-max:%dpx;--hs-m-height:%dpx;--hs-m-min:%dpx;--hs-m-max:%dpx',
      (int)$ds['height'], (int)$ds['min_height'], (int)$ds['max_height'],
      (int)$ms['height'], (int)$ms['min_height'], (int)$ms['max_height']
  );
  $heroFrontendSettings = wp_json_encode([
      'breakpoint' => $heroBreakpoint,
      'desktop'    => $ds,
      'mobile'     => $ms,
  ], JSON_UNESCAPED_SLASHES);
?>
<section class="hs-hero" id="hsHero" style="<?= esc_attr($heroStyleVars) ?>">
  <script type="application/json" id="hsHeroSettings"><?= $heroFrontendSettings ?></script>
  <div class="hs-track">
    <?php foreach ($heroSlides as $si => $slide): ?>
    <div class="hs-slide<?= $si === 0 ? ' hs-active' : '' ?>" data-index="<?= (int)$si ?>">
      <div class="hs-slide-media hs-slide-desktop-media">
        <img src="<?= esc_url($slide['desktop_image_url']) ?>"
             alt="<?= esc_attr($slide['heading'] !== '' ? $slide['heading'] : ($co . ' — RTO application assistance')) ?>"
             style="object-fit:<?= esc_attr($heroFitCss($slide['desktop_fit'])) ?>;object-position:<?= esc_attr($heroPosCss($slide['desktop_position'])) ?>"
             <?= $si === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?>>
      </div>
      <div class="hs-slide-media hs-slide-mobile-media">
        <img src="<?= esc_url($slide['mobile_image_url']) ?>"
             alt="<?= esc_attr($slide['heading'] !== '' ? $slide['heading'] : ($co . ' — RTO application assistance')) ?>"
             style="object-fit:<?= esc_attr($heroFitCss($slide['mobile_fit'])) ?>;object-position:<?= esc_attr($heroPosCss($slide['mobile_position'])) ?>"
             <?= $si === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?>>
      </div>
      <?php if (!empty($slide['overlay_enabled'])): ?>
      <div class="hs-overlay" style="background:<?= esc_attr($slide['overlay_color']) ?>;opacity:<?= (float)$slide['overlay_opacity'] / 100 ?>"></div>
      <?php endif; ?>

<main id="rfh-main">

<!-- Floating apply dock -->
<div class="rfh-hero-dock rfh-reveal">
  <div class="rfh-container">
    <div class="rfh-dock-card">
      <div>
        <div class="rfh-dock-label">Start your RTO application in minutes</div>
        <div class="rfh-dock-sub">Choose a service and city — we'll guide you through documents, fees, and tracking.</div>
        <div class="rfh-dock-steps">
          <span class="rfh-dock-step is-on" data-step="0">1. Service</span>
          <span class="rfh-dock-step" data-step="1">2. City</span>
          <span class="rfh-dock-step" data-step="2">3. Apply</span>
        </div>
      </div>
      <div class="rfh-field">
        <label for="rfhDockService">Select service</label>
        <select id="rfhDockService" aria-label="Select a service">
          <option value="">Choose a service…</option>
          <?php foreach ($services ?? [] as $svc): ?>
          <option value="<?php echo esc_attr($svc['slug'] ?? ''); ?>"><?php echo esc_html($svc['name'] ?? ''); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!empty($dockCities)): ?>
      <div class="rfh-field">
        <label for="rfhDockCity">Your city</label>
        <select id="rfhDockCity" aria-label="Select your city">
          <option value="">Select city (optional)…</option>
          <?php foreach ($dockCities as $c): ?>
          <option value="<?php echo esc_attr($c['slug'] ?? ''); ?>"><?php echo esc_html($c['name'] ?? ''); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--primary rfh-btn--lg" id="rfhDockGo" data-base="<?php echo esc_attr(home_url('/rto-apply/')); ?>">Continue <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</div>

<!-- Guarantees ribbon -->
<div class="rfh-guarantees rfh-reveal">
  <div class="rfh-container rfh-guarantees-grid">
    <div class="rfh-guarantee"><div class="rfh-guarantee-ic"><i class="fa-solid fa-shield-halved"></i></div><div><b>Application Assistance</b><span>Handled as per official RTO rules and procedures</span></div></div>
    <div class="rfh-guarantee"><div class="rfh-guarantee-ic"><i class="fa-solid fa-map-location-dot"></i></div><div><b>Pan-India Coverage</b><span><?php echo $cc > 0 ? (int)$cc . '+ cities with active support' : 'Growing city coverage across India'; ?></span></div></div>
    <div class="rfh-guarantee"><div class="rfh-guarantee-ic"><i class="fa-solid fa-receipt"></i></div><div><b>Transparent Pricing</b><span>Clear service fees with government charges at actual cost</span></div></div>
    <div class="rfh-guarantee"><div class="rfh-guarantee-ic"><i class="fa-solid fa-headset"></i></div><div><b>Expert Support</b><span>Dedicated team from submission through completion</span></div></div>
  </div>
</div>

<?php
$hpStats = [];
if ($lead_count > 0)   $hpStats[] = ['icon' => 'fa-solid fa-file-circle-check', 'val' => number_format($lead_count), 'lbl' => 'Applications Completed'];
if ($city_count > 0)   $hpStats[] = ['icon' => 'fa-solid fa-city', 'val' => $city_count . '+', 'lbl' => 'Cities Covered'];
if ($svcTotal > 0)     $hpStats[] = ['icon' => 'fa-solid fa-list-check', 'val' => $svcTotal . '+', 'lbl' => 'RTO Services'];
if ($ratingAvg !== null && $ratingCount >= 5) $hpStats[] = ['icon' => 'fa-solid fa-star', 'val' => number_format($ratingAvg, 1) . '★', 'lbl' => 'Customer Rating'];
?>
<?php if (!empty($hpStats)): ?>
<section class="rfh-section rfh-metrics rfh-reveal" aria-label="Platform statistics">
  <div class="rfh-container">
    <div class="rfh-metrics-grid">
      <?php foreach ($hpStats as $st): ?>
      <div class="rfh-metric">
        <div class="rfh-metric-ic"><i class="<?php echo esc_attr($st['icon']); ?>"></i></div>
        <div class="rfh-metric-val"><?php echo esc_html($st['val']); ?></div>
        <div class="rfh-metric-lbl"><?php echo esc_html($st['lbl']); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Service categories bento -->
<section class="rfh-section rfh-section--pattern" id="services">
  <div class="rfh-container">
    <div class="rfh-sec-head rfh-reveal">
      <div>
        <div class="rfh-eyebrow">Service Marketplace</div>
        <h2 class="rfh-title">Every RTO service, <span class="accent">one platform</span></h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">Browse by category or search individual services — RC transfer, driving licence, NOC, hypothecation and more.</p>
      </div>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--outline">View all services <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="rfh-bento rfh-reveal">
      <?php
      $pi = 0;
      $accents = ['#2F6FED', '#E23F6B', '#1FAE63', '#C2660F', '#7C3AED', '#0D9488', '#64748B'];
      foreach ($cat_config as $cn => $ci):
        $svcs = ($by_cat[$cn] ?? []);
        $cnt = count($svcs);
        $accent = $accents[$pi % count($accents)];
        $wide = ($popularCat !== null && $cn === $popularCat && $popularMax > 0);
        $pi++;
      ?>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-cat-card<?php echo $wide ? ' rfh-cat-card--wide' : ''; ?>"
         style="--rfh-card-accent:<?php echo esc_attr($accent); ?>;--rfh-card-bg:<?php echo esc_attr($pastels[($pi - 1) % count($pastels)]['bg']); ?>">
        <?php if ($wide): ?><span class="rfh-tag">Most popular</span><?php endif; ?>
        <div class="rfh-cat-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $catIcons[$ci['icon']]; ?></svg></div>
        <div class="rfh-cat-name"><?php echo esc_html($ci['label']); ?></div>
        <div class="rfh-cat-meta"><?php echo $cnt > 0 ? ($cnt . ' service' . ($cnt === 1 ? '' : 's')) : 'Explore services'; ?></div>
      </a>
      <?php endforeach; ?>
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-cat-card rfh-cat-card--cta">
        <div class="rfh-cat-name">Explore all <?php echo $svcTotal > 0 ? (int)$svcTotal . '+' : ''; ?> services</div>
        <div class="rfh-cat-meta" style="color:rgba(255,255,255,.8)">Start application &rarr;</div>
      </a>
    </div>
  </div>
</section>

<?php if (!empty($featuredServices)): ?>
<section class="rfh-section rfh-section--muted">
  <div class="rfh-container">
    <div class="rfh-sec-head rfh-reveal">
      <div>
        <div class="rfh-eyebrow">Popular Services</div>
        <h2 class="rfh-title">Find the right service <em>fast</em></h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">Search or filter by category — every listing is pulled from your live service catalogue.</p>
      </div>
    </div>
    <div class="rfh-toolbar rfh-reveal">
      <div class="rfh-search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="search" id="rfhServiceSearch" placeholder="Search services…" autocomplete="off" aria-label="Search services">
      </div>
      <div class="rfh-chips" role="group" aria-label="Filter by category">
        <button type="button" class="rfh-chip is-active" data-cat="">All</button>
        <?php foreach (array_keys($cat_config) as $chipCat): ?>
        <button type="button" class="rfh-chip" data-cat="<?php echo esc_attr($chipCat); ?>"><?php echo esc_html($cat_config[$chipCat]['label']); ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="rfh-service-grid rfh-reveal" id="rfhServiceGrid">
      <?php foreach ($featuredServices as $svc):
        $price = isset($svc['base_price']) && $svc['base_price'] > 0 ? '₹' . number_format((float)$svc['base_price']) . '+' : 'Get quote';
        $sla = !empty($svc['sla_days']) ? (int)$svc['sla_days'] . ' day SLA' : 'Varies by RTO';
      ?>
      <a href="<?php echo esc_url(home_url('/rto-apply/?service=' . rawurlencode($svc['slug'] ?? ''))); ?>" class="rfh-service-card"
         data-name="<?php echo esc_attr($svc['name'] ?? ''); ?>" data-cat="<?php echo esc_attr($svc['category'] ?? ''); ?>">
        <h3><?php echo esc_html($svc['name'] ?? ''); ?></h3>
        <p><?php echo esc_html($svc['category'] ?? 'RTO Service'); ?> — expert assistance with documentation and submission.</p>
        <div class="rfh-service-meta"><span class="price"><?php echo esc_html($price); ?></span><span class="sla"><?php echo esc_html($sla); ?></span></div>
      </a>
      <?php endforeach; ?>
      <div class="rfh-empty" id="rfhServiceEmpty" style="display:none">No services match your search. <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>">Browse all services</a></div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- How it works -->
<section class="rfh-section" id="how-it-works">
  <div class="rfh-container">
    <div class="rfh-process-layout">
      <div class="rfh-reveal">
        <div class="rfh-eyebrow">Simple Process</div>
        <h2 class="rfh-title">From documents to done in <span class="accent">four steps</span></h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">A guided workflow designed to remove RTO confusion — with real-time tracking at every stage.</p>
        <div class="rfh-timeline" style="margin-top:28px">
          <?php
          $steps = [
            ['c'=>'#E97B28','n'=>'01','t'=>'Choose your service','d'=>'Select from 30+ RTO services with clear pricing and timelines.'],
            ['c'=>'#2F6FED','n'=>'02','t'=>'Submit documents','d'=>'Upload digitally or arrange pickup where available in your city.'],
            ['c'=>'#1FAE63','n'=>'03','t'=>'Expert verification','d'=>'Our team reviews, prepares, and submits your application correctly.'],
            ['c'=>'#7C3AED','n'=>'04','t'=>'Track to completion','d'=>'Follow every milestone until the RTO issues your certificate.'],
          ];
          foreach ($steps as $st):
          ?>
          <div class="rfh-step" style="--rfh-step-c:<?php echo esc_attr($st['c']); ?>">
            <div class="rfh-step-num"><?php echo esc_html($st['n']); ?></div>
            <div><h3><?php echo esc_html($st['t']); ?></h3><p><?php echo esc_html($st['d']); ?></p></div>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--primary rfh-btn--lg" style="margin-top:20px">Get started now <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="rfh-reveal" aria-hidden="true">
        <div class="rfh-phone-mock">
          <div class="rfh-phone-screen">
            <div class="rfh-phone-top">Application Status</div>
            <div class="rfh-phone-body">
              <?php $curSet = false; foreach ($trackMock as $i => $st):
                $isDone = $st['done'];
                $isCur = !$isDone && !$curSet; if ($isCur) $curSet = true;
                $cls = $isDone ? 'is-done' : ($isCur ? 'is-current' : '');
              ?>
              <div class="rfh-phone-item <?php echo $cls; ?>">
                <div class="rfh-phone-dot"><?php echo $isDone ? '✓' : ($i + 1); ?></div>
                <span><?php echo esc_html($st['label']); ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="rfh-disclosure rfh-reveal">
      Doorstep pickup/drop is available only where a local agent or courier partner covers your city, may involve additional charges beyond the standard service fee, and is not part of every service's base price. Government approval, processing time, and issuance are determined solely by the concerned RTO/authority — we assist with the application, we do not control or guarantee the outcome.
    </div>
  </div>
</section>

<!-- Platform capabilities -->
<section class="rfh-section rfh-section--muted rfh-section--pattern">
  <div class="rfh-container">
    <div class="rfh-sec-head is-center rfh-reveal">
      <div class="rfh-eyebrow">Platform</div>
      <h2 class="rfh-title">Built like an <span class="accent">enterprise RTO OS</span></h2>
      <div class="rfh-title-bar is-center"></div>
      <p class="rfh-lead">Not just a contact form — a full operating platform with tracking, notifications, and expert workflows behind every application.</p>
    </div>
    <div class="rfh-platform rfh-reveal">
      <div class="rfh-feature-stack">
        <?php
        $features = [
          ['fc'=>'#2F6FED','ic'=>'fa-solid fa-bell','t'=>'Real-time notifications','d'=>'SMS and WhatsApp updates at every milestone — no guessing.'],
          ['fc'=>'#1FAE63','ic'=>'fa-solid fa-folder-open','t'=>'Document intelligence','d'=>'Know exactly which documents you need before you apply.'],
          ['fc'=>'#E97B28','ic'=>'fa-solid fa-chart-line','t'=>'Live application tracking','d'=>'Track status 24/7 with the same timeline your team sees.'],
          ['fc'=>'#7C3AED','ic'=>'fa-solid fa-user-shield','t'=>'Verified expert network','d'=>'Applications handled by trained RTO specialists in your city.'],
        ];
        foreach ($features as $ft):
        ?>
        <div class="rfh-feature" style="--rfh-fc:<?php echo esc_attr($ft['fc']); ?>">
          <div class="rfh-feature-ic"><i class="<?php echo esc_attr($ft['ic']); ?>"></i></div>
          <div><h3><?php echo esc_html($ft['t']); ?></h3><p><?php echo esc_html($ft['d']); ?></p></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="rfh-dash">
        <div class="rfh-dash-bar"><span>Client Dashboard</span><span>Live</span></div>
        <div class="rfh-dash-body">
          <div class="rfh-dash-row"><span>RC Transfer — Mumbai</span><span class="rfh-dash-pill">Processing</span></div>
          <div class="rfh-dash-row"><span>DL Renewal — Pune</span><span class="rfh-dash-pill">Documents Verified</span></div>
          <div class="rfh-dash-row"><span>NOC — Bangalore</span><span class="rfh-dash-pill">Submitted</span></div>
          <div class="rfh-dash-row"><span>HP Termination — Delhi</span><span class="rfh-dash-pill">Completed</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Documents -->
<section class="rfh-section" id="documents">
  <div class="rfh-container">
    <div class="rfh-sec-head rfh-reveal">
      <div>
        <div class="rfh-eyebrow">Document Guide</div>
        <h2 class="rfh-title">Know what you need <em>before</em> you apply</h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">Common document checklists by service type — your exact list is confirmed during application.</p>
      </div>
    </div>
    <div class="rfh-docs-grid rfh-reveal">
      <div class="rfh-doc-card"><div class="rfh-doc-head">RC Transfer</div><ul class="rfh-doc-list"><li><i class="fa-solid fa-check"></i> Original RC Book</li><li><i class="fa-solid fa-check"></i> Form 29 &amp; 30</li><li><i class="fa-solid fa-check"></i> Valid insurance &amp; PUC</li><li><i class="fa-solid fa-check"></i> Buyer &amp; seller ID proof</li></ul></div>
      <div class="rfh-doc-card"><div class="rfh-doc-head">Driving Licence</div><ul class="rfh-doc-list"><li><i class="fa-solid fa-check"></i> Learner's licence (if applicable)</li><li><i class="fa-solid fa-check"></i> Address proof</li><li><i class="fa-solid fa-check"></i> Age proof</li><li><i class="fa-solid fa-check"></i> Passport-size photographs</li></ul></div>
      <div class="rfh-doc-card"><div class="rfh-doc-head">NOC / Hypothecation</div><ul class="rfh-doc-list"><li><i class="fa-solid fa-check"></i> Original RC &amp; insurance</li><li><i class="fa-solid fa-check"></i> Bank NOC (if financed)</li><li><i class="fa-solid fa-check"></i> Form 35 / Form 28</li><li><i class="fa-solid fa-check"></i> Owner ID &amp; address proof</li></ul></div>
    </div>
  </div>
</section>

<?php if (!empty($city_list)): ?>
<section class="rfh-section rfh-section--muted" id="coverage">
  <div class="rfh-container">
    <div class="rfh-sec-head rfh-reveal">
      <div>
        <div class="rfh-eyebrow">Nationwide Coverage</div>
        <h2 class="rfh-title">RTO assistance <span class="accent">across India</span></h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">Application support in <?php echo $city_count > 0 ? (int)$city_count . '+' : 'major'; ?> cities — find your city and get started.</p>
      </div>
      <a href="<?php echo esc_url(home_url('/rto-services-cities')); ?>" class="rfh-btn rfh-btn--outline">All cities <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="rfh-coverage rfh-reveal">
      <div class="rfh-map-panel">
        <div class="rfh-map-num"><?php echo $city_count > 0 ? (int)$city_count . '+' : count($city_list); ?></div>
        <div class="rfh-map-lbl">cities with active support</div>
        <p class="rfh-map-copy">Local agents and expert coordinators for RC transfer, driving licence, NOC, hypothecation and more — wherever the RTO serves your vehicle.</p>
        <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--white" style="margin-top:22px">Apply in your city</a>
      </div>
      <div class="rfh-cities-panel">
        <input type="search" class="rfh-cities-search" id="rfhCitySearch" placeholder="Search cities…" autocomplete="off" aria-label="Search cities">
        <div class="rfh-city-tags" id="rfhCityTags">
          <?php foreach ($city_list as $c): ?>
          <a href="<?php echo esc_url(home_url('/rto-services-cities/' . ($c['slug'] ?? ''))); ?>" class="rfh-city-tag" data-name="<?php echo esc_attr(strtolower($c['name'] ?? '')); ?>">
            <span class="rfh-city-dot"></span><?php echo esc_html($c['name'] ?? ''); ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Track -->
<section class="rfh-section rfh-section--dark" id="track">
  <div class="rfh-container rfh-track-layout">
    <div class="rfh-reveal">
      <div class="rfh-eyebrow" style="background:rgba(255,255,255,.1);color:#fff">Track Application</div>
      <h2 class="rfh-title">Check your status <span class="accent">anytime</span></h2>
      <p class="rfh-lead">Enter your application number for the latest update. Final processing time and outcome are determined by the RTO.</p>
      <form class="rfh-track-form" action="<?php echo esc_url(home_url('/rto-track/')); ?>" method="get">
        <input type="text" name="token" placeholder="Enter application number" aria-label="Application number" required>
        <button type="submit" class="rfh-btn rfh-btn--primary rfh-btn--lg">Check status</button>
      </form>
    </div>
    <div class="rfh-reveal" aria-hidden="true">
      <div class="rfh-phone-mock">
        <div class="rfh-phone-screen">
          <div class="rfh-phone-top" style="background:linear-gradient(90deg,var(--rfh-accent),var(--rfh-brand))">Live Tracking</div>
          <div class="rfh-phone-body">
            <?php $curSet = false; foreach ($trackMock as $i => $st):
              $isDone = $st['done']; $isCur = !$isDone && !$curSet; if ($isCur) $curSet = true;
              $cls = $isDone ? 'is-done' : ($isCur ? 'is-current' : '');
            ?>
            <div class="rfh-phone-item <?php echo $cls; ?>"><div class="rfh-phone-dot"><?php echo $isDone ? '✓' : ($i + 1); ?></div><span><?php echo esc_html($st['label']); ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Comparison -->
<section class="rfh-section">
  <div class="rfh-container">
    <div class="rfh-sec-head is-center rfh-reveal">
      <div class="rfh-eyebrow">Why assisted?</div>
      <h2 class="rfh-title">DIY at the RTO vs <span class="accent"><?php echo esc_html($co); ?></span></h2>
      <div class="rfh-title-bar is-center"></div>
      <p class="rfh-lead">See what changes when you have a dedicated team handling your application end-to-end.</p>
    </div>
    <div class="rfh-compare rfh-reveal">
      <table>
        <thead><tr><th>Capability</th><th>DIY at RTO</th><th class="is-brand"><?php echo esc_html($co); ?></th></tr></thead>
        <tbody>
          <tr><td>Document checklist guidance</td><td class="rfh-no">Limited</td><td class="is-brand rfh-yes">✓ Personalized</td></tr>
          <tr><td>Application tracking</td><td class="rfh-no">Manual visits</td><td class="is-brand rfh-yes">✓ 24/7 online</td></tr>
          <tr><td>Expert review before submission</td><td class="rfh-no">—</td><td class="is-brand rfh-yes">✓ Included</td></tr>
          <tr><td>Status notifications</td><td class="rfh-no">—</td><td class="is-brand rfh-yes">✓ SMS &amp; WhatsApp</td></tr>
          <tr><td>Transparent pricing</td><td class="rfh-no">Varies</td><td class="is-brand rfh-yes">✓ Upfront quote</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Benefits -->
<section class="rfh-section rfh-section--muted" id="why">
  <div class="rfh-container">
    <div class="rfh-sec-head is-center rfh-reveal">
      <div class="rfh-eyebrow">Why choose us</div>
      <h2 class="rfh-title">The <?php echo esc_html($co); ?> <span class="accent">advantage</span></h2>
      <div class="rfh-title-bar is-center"></div>
      <p class="rfh-lead">Six reasons customers trust us with their most important vehicle paperwork.</p>
    </div>
    <div class="rfh-benefits rfh-reveal">
      <?php
      $benefits = [
        ['bc'=>'#1FAE63','ic'=>'fa-solid fa-clock','t'=>'Time saving','d'=>'Skip queues, confusion, and repeat visits to the RTO office.'],
        ['bc'=>'#2F6FED','ic'=>'fa-solid fa-user-tie','t'=>'Expert guidance','d'=>'RTO specialists who know the rules, forms, and local nuances.'],
        ['bc'=>'#C2660F','ic'=>'fa-solid fa-tags','t'=>'Transparent pricing','d'=>'Clear service fees — government charges at actual cost with receipts.'],
        ['bc'=>'#E23F6B','ic'=>'fa-solid fa-map','t'=>'Pan-India support','d'=>'Coordinated assistance across RTOs in every state.'],
        ['bc'=>'#7C3AED','ic'=>'fa-solid fa-lock','t'=>'Document safety','d'=>'Careful handling with confirmation at every handoff.'],
        ['bc'=>'#0D9488','ic'=>'fa-solid fa-mobile-screen','t'=>'Always connected','d'=>'Track, chat, and get updates from your phone — no app required.'],
      ];
      foreach ($benefits as $b):
      ?>
      <div class="rfh-benefit" style="--rfh-bc:<?php echo esc_attr($b['bc']); ?>">
        <div class="rfh-benefit-ic"><i class="<?php echo esc_attr($b['ic']); ?>"></i></div>
        <h3><?php echo esc_html($b['t']); ?></h3>
        <p><?php echo esc_html($b['d']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (!empty($testimonialItems)): ?>
<section class="rfh-section" id="testimonials">
  <div class="rfh-container">
    <div class="rfh-sec-head rfh-reveal">
      <div>
        <div class="rfh-eyebrow">Customer reviews</div>
        <h2 class="rfh-title">Trusted by <span class="accent">real customers</span></h2>
        <div class="rfh-title-bar"></div>
        <p class="rfh-lead">Genuine reviews from completed applications — never fabricated.</p>
      </div>
    </div>
    <div class="rfh-testi-grid rfh-reveal">
      <?php foreach (array_slice($testimonialItems, 0, 6) as $t):
        $metaParts = array_filter([$t['service'] ?: null, $t['city'] ?: null]);
        $initial = mb_strtoupper(mb_substr($t['name'], 0, 1));
      ?>
      <article class="rfh-testi">
        <div class="rfh-testi-head">
          <div class="rfh-avatar" aria-hidden="true"><?php echo esc_html($initial); ?></div>
          <div>
            <div class="rfh-testi-name"><?php echo esc_html($t['name']); ?></div>
            <?php if ($metaParts): ?><div class="rfh-testi-meta"><?php echo esc_html(implode(' · ', $metaParts)); ?></div><?php endif; ?>
          </div>
        </div>
        <div class="rfh-stars" aria-label="<?php echo esc_attr($t['score']); ?> out of 5 stars"><?php echo str_repeat('★', $t['score']) . str_repeat('☆', 5 - $t['score']); ?></div>
        <p class="rfh-quote">&ldquo;<?php echo esc_html($t['comment']); ?>&rdquo;</p>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if ($ratingAvg !== null && $ratingCount >= 5): ?>
    <p style="text-align:center;font-size:13px;color:var(--rfh-muted);margin-top:24px" class="rfh-reveal">
      Average <?php echo number_format($ratingAvg, 1); ?>/5 across <?php echo number_format($ratingCount); ?> real customer ratings.
    </p>
    <?php endif; ?>
  </div>
</section>
<?php elseif ($ratingAvg !== null && $ratingCount >= 5): ?>
<section class="rfh-section">
  <div class="rfh-container rfh-rating-banner rfh-reveal">
    <div class="rfh-eyebrow" style="justify-content:center">Customer ratings</div>
    <div class="rfh-rating-big"><?php echo number_format($ratingAvg, 1); ?><span style="font-size:22px;color:var(--rfh-muted)">/5</span></div>
    <div class="rfh-stars" style="font-size:22px;margin:10px 0">★★★★★</div>
    <p style="color:var(--rfh-muted)">Based on <?php echo number_format($ratingCount); ?> real customer ratings collected after service completion.</p>
  </div>
</section>
<?php endif; ?>

<?php if ($waLink): ?>
<section class="rfh-section rfh-section--muted">
  <div class="rfh-container rfh-reveal">
    <div class="rfh-support">
      <div class="rfh-support-main">
        <div class="rfh-eyebrow">Stay connected</div>
        <h2 class="rfh-title">RTO support, <span class="accent">right in your pocket</span></h2>
        <p class="rfh-lead">Chat with our team on WhatsApp — book services, track applications, and get expert answers without downloading an app.</p>
        <div class="rfh-support-list">
          <div><i class="fa-solid fa-check"></i> Book a service</div>
          <div><i class="fa-solid fa-check"></i> Track applications</div>
          <div><i class="fa-solid fa-check"></i> Get status updates</div>
          <div><i class="fa-solid fa-check"></i> Chat with experts</div>
        </div>
        <a href="<?php echo esc_url($waLink); ?>" class="rfh-btn rfh-btn--primary rfh-btn--lg" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp</a>
      </div>
      <div class="rfh-support-side">
        <div class="rfh-qr" aria-hidden="true">
          <svg viewBox="0 0 100 100">
            <rect x="4" y="4" width="26" height="26" fill="none" stroke="#fff" stroke-width="5"/>
            <rect x="70" y="4" width="26" height="26" fill="none" stroke="#fff" stroke-width="5"/>
            <rect x="4" y="70" width="26" height="26" fill="none" stroke="#fff" stroke-width="5"/>
            <rect x="14" y="14" width="6" height="6" fill="#fff"/><rect x="80" y="14" width="6" height="6" fill="#fff"/><rect x="14" y="80" width="6" height="6" fill="#fff"/>
          </svg>
        </div>
        <div style="font-weight:800;font-size:14px">Scan to chat</div>
        <div style="font-size:12px;opacity:.75;margin-top:6px">Mon–Sat, 9AM–7PM</div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Accent CTA -->
<section class="rfh-accent rfh-reveal">
  <div class="rfh-container rfh-accent-in">
    <div>
      <h2>Get your RTO work done with confidence</h2>
      <p>Expert assistance · Transparent pricing · Real-time tracking · Pan-India support</p>
    </div>
    <div class="rfh-accent-actions">
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--white rfh-btn--lg">Start application <i class="fa-solid fa-arrow-right"></i></a>
      <?php if ($waLink): ?>
      <a href="<?php echo esc_url($waLink); ?>" class="rfh-btn rfh-btn--ghost rfh-btn--lg" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
      <?php elseif ($tel): ?>
      <a href="tel:<?php echo esc_attr($tel); ?>" class="rfh-btn rfh-btn--ghost rfh-btn--lg"><i class="fa-solid fa-phone"></i> Call us</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rfh-section" id="faq">
  <div class="rfh-container">
    <div class="rfh-sec-head is-center rfh-reveal">
      <div class="rfh-eyebrow">FAQ</div>
      <h2 class="rfh-title">Questions? <span class="accent">We've got answers.</span></h2>
      <div class="rfh-title-bar is-center"></div>
    </div>
    <div class="rfh-faq-layout rfh-reveal">
      <aside class="rfh-faq-help">
        <h3>Still need help?</h3>
        <p>Our support team is available Mon–Sat, 9AM–7PM to guide you through any RTO query.</p>
        <input type="search" class="rfh-faq-search" id="rfhFaqSearch" placeholder="Search FAQs…" aria-label="Search FAQs">
        <?php if ($tel): ?><a href="tel:<?php echo esc_attr($tel); ?>" class="rfh-btn rfh-btn--white rfh-btn--block"><i class="fa-solid fa-phone"></i> <?php echo esc_html($tel); ?></a><?php endif; ?>
        <?php if ($waLink): ?><a href="<?php echo esc_url($waLink); ?>" class="rfh-btn rfh-btn--ghost rfh-btn--block" style="margin-top:10px" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp support</a><?php endif; ?>
      </aside>
      <div class="rfh-faq-list" id="rfhFaqList">
        <?php foreach ([
          ['What documents do I need for RC Transfer?','You need the original RC Book, Form 29 and 30 (we provide them), valid insurance, PUC certificate, and Aadhaar of buyer and seller. If there is an active loan, a bank NOC is also required.'],
          ['How long does RC Transfer take?','Within the same state, RC Transfer takes 15-30 working days. Inter-state transfers take 30-45 days due to the additional NOC step.'],
          ['Is my document safe with you?','Where we physically collect your documents, our agent handles them with care and confirms receipt with you directly. Contact our helpline immediately if you have any concern about a specific pickup.'],
          ['Do I need to visit the RTO even once?','In most cases, no. Occasionally the RTO requires the vehicle owner to be physically present. We notify you in advance if that applies to your case.'],
          ['How is payment handled?','We collect the service fee after you confirm your application. Government fees are passed at actual cost with receipts. No advance payment is required to apply.'],
          ['Can I track my application status?','Yes. After submission you receive a unique application number and can track status 24/7 at /rto-track/ with SMS and WhatsApp updates at every milestone.'],
          ['What if there is a problem?','Our team monitors every application. If there is any query from the RTO, we contact you immediately.'],
        ] as $i => [$q, $a]): ?>
        <div class="rfh-faq-item" id="rfhFaq<?php echo $i; ?>">
          <button type="button" class="rfh-faq-q" aria-expanded="false" aria-controls="rfhFaqA<?php echo $i; ?>">
            <span class="rfh-faq-num"><?php echo str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); ?></span>
            <span class="rfh-faq-q-text"><?php echo esc_html($q); ?></span>
            <span class="rfh-faq-icon" aria-hidden="true">+</span>
          </button>
          <div class="rfh-faq-a" id="rfhFaqA<?php echo $i; ?>"><?php echo esc_html($a); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- Final CTA -->
<section class="rfh-final-cta rfh-reveal">
  <div class="rfh-container">
    <h2>Ready to simplify your RTO journey?</h2>
    <p>Join thousands of vehicle owners who trust <?php echo esc_html($co); ?> for RC transfer, driving licence, NOC, and every RTO service in between.</p>
    <div class="rfh-final-actions">
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--primary rfh-btn--lg">Start your application</a>
      <a href="<?php echo esc_url(home_url('/rto-track/')); ?>" class="rfh-btn rfh-btn--ghost rfh-btn--lg">Track existing application</a>
    </div>
  </div>
</section>

</main>

<div class="rfh-payments">
  <div class="rfh-container rfh-payments-in">
    <span>Secure payments</span>
    <span class="rfh-pay-badge">UPI</span>
    <span class="rfh-pay-badge">Net Banking</span>
    <span class="rfh-pay-badge">Debit / Credit Card</span>
    <span class="rfh-pay-badge">GST Invoicing</span>
  </div>
</div>


<footer class="rfh-footer">
  <div class="rfh-container rfh-footer-grid">
    <div>
      <div class="rfh-footer-logo">
        <span class="rfh-logo-mark" aria-hidden="true" style="width:40px;height:40px"><svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:#fff;fill:none;stroke-width:1.8"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4"/></svg></span>
        <b><?php echo esc_html($co); ?></b>
      </div>
      <p class="rfh-footer-desc">Your trusted partner for all RTO-related services. We simplify the process so you can get on the road faster.</p>
      <div class="rfh-social">
        <a href="#" aria-label="Facebook"><svg viewBox="0 0 24 24"><path d="M14 9h3V6h-3a4 4 0 0 0-4 4v2H8v3h2v6h3v-6h3l1-3h-4v-2a1 1 0 0 1 1-1z"/></svg></a>
        <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg></a>
        <a href="#" aria-label="YouTube"><svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="3"/><path d="M10 9l6 3-6 3z" fill="currentColor" stroke="none"/></svg></a>
      </div>
    </div>
    <div>
      <h4>Quick links</h4>
      <div class="rfh-footer-links">
        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
        <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>">All services</a>
        <a href="<?php echo esc_url(home_url('/rto-track/')); ?>">Track application</a>
        <a href="<?php echo esc_url(home_url('/rto-services-cities')); ?>">RTO information</a>
        <a href="<?php echo esc_url(home_url('/about')); ?>">About us</a>
      </div>
    </div>
    <div>
      <h4>Popular services</h4>
      <div class="rfh-footer-links">
        <?php foreach (array_slice($cat_config, 0, 5, true) as $cn => $ci): ?>
        <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>"><?php echo esc_html($ci['label']); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <h4>Contact</h4>
      <div class="rfh-footer-contact">
        <?php if ($tel): ?><div><a href="tel:<?php echo esc_attr($tel); ?>"><?php echo esc_html($tel); ?></a></div><?php endif; ?>
        <div>support@<?php echo esc_html(strtolower(preg_replace('/[^a-z0-9]/i', '', $co) ?: 'rtoflow')); ?>.in</div>
        <div>Pan India support</div>
        <div>Mon – Sat, 9AM – 7PM</div>
      </div>
    </div>
  </div>
  <div class="rfh-container rfh-footer-bottom">
    <span>&copy; <?php echo date('Y'); ?> <?php echo esc_html($co); ?>. All rights reserved.</span>
    <div>
      <a href="<?php echo esc_url(home_url('/privacy-policy')); ?>">Privacy</a>
      <a href="<?php echo esc_url(home_url('/terms-conditions')); ?>">Terms</a>
    </div>
  </div>
</footer>

<?php if ($waLink): ?>
<a href="<?php echo esc_url($waLink); ?>" class="rfh-wa-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
<?php endif; ?>

<div class="rfh-sticky-cta" aria-hidden="false">
  <div class="rfh-container rfh-sticky-cta-in">
    <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="rfh-btn rfh-btn--primary">Apply now</a>
    <a href="<?php echo esc_url(home_url('/rto-track/')); ?>" class="rfh-btn rfh-btn--outline">Track</a>
  </div>
</div>

<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/rtoflow-home.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/rtoflow-home.js')) ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/hero-slider.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/hero-slider.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
