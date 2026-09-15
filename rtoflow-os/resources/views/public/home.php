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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,::before,::after{box-sizing:border-box;margin:0;padding:0}
html{margin-top:0!important;scroll-behavior:smooth}
@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
#wpadminbar{position:fixed!important}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;background:#ffffff;line-height:1.6;font-size:15px;-webkit-font-smoothing:antialiased}
.hp-tt,.hp-trust-tt,.ft-logo,.hp-step-lbl{font-family:'Poppins','Inter',sans-serif}
a{color:<?php echo esc_attr($cp); ?>;text-decoration:none}
img{display:block;max-width:100%}
.w{max-width:1220px;margin:0 auto;padding:0 20px}

:root{
  --sh-1:0 1px 2px rgba(16,24,53,.05),0 1px 1px rgba(16,24,53,.04);
  --sh-2:0 10px 26px -10px rgba(16,24,53,.16),0 2px 8px -2px rgba(16,24,53,.08);
  --sh-3:0 26px 56px -18px rgba(16,24,53,.32),0 8px 20px -8px rgba(16,24,53,.16);
  --r-sm:12px;--r-md:16px;--r-lg:22px;--r-pill:999px;
}

/* ---------- reveal-on-scroll ---------- */
.hp-reveal{opacity:0;transform:translateY(18px);transition:opacity .6s ease,transform .6s ease}
.hp-reveal.hp-in{opacity:1;transform:none}
@media(prefers-reduced-motion:reduce){.hp-reveal{opacity:1;transform:none;transition:none}}

/* ---------- top bar / header (RTO Seva style: white sticky bar) ---------- */
.hp-topbar{background:<?php echo esc_attr($cd); ?>;color:rgba(255,255,255,.78);font-size:12px;padding:7px 20px;text-align:center}
.hp-topbar a{color:#fff;font-weight:700}
.hp-hdr{background:#fff;border-bottom:1px solid #eef1f6;position:sticky;top:0;z-index:200;box-shadow:0 1px 10px rgba(16,24,53,.05)}
.admin-bar .hp-hdr{top:32px}
@media screen and (max-width:782px){.admin-bar .hp-hdr{top:46px}}
.hp-hdr-in{display:flex;align-items:center;height:70px;gap:18px}
.hp-logo{display:flex;align-items:center;gap:10px;text-decoration:none;flex-shrink:0}
.hp-logo-mark{width:38px;height:38px;border-radius:50%;background:<?php echo esc_attr($cp); ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(27,42,107,.28)}
.hp-logo-mark svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.hp-logo-txt{display:flex;flex-direction:column;line-height:1.15}
.hp-logo-txt b{font-size:18px;font-weight:900;color:<?php echo esc_attr($cp); ?>;letter-spacing:-.01em}
.hp-logo-txt small{font-size:10px;font-weight:600;color:#94a3b8;letter-spacing:.02em}
.hp-nav{display:flex;align-items:center;gap:2px;flex:1;justify-content:center}
.hp-nav a{position:relative;padding:8px 13px;border-radius:6px;font-size:13.5px;font-weight:600;color:#475569;transition:all .15s;white-space:nowrap}
.hp-nav a:hover{color:<?php echo esc_attr($cp); ?>}
.hp-nav a.hp-active{color:<?php echo esc_attr($cp); ?>;font-weight:800}
.hp-nav a.hp-active::after{content:"";position:absolute;left:13px;right:13px;bottom:1px;height:2px;border-radius:2px;background:<?php echo esc_attr($cs); ?>}
.hp-cta{display:flex;align-items:center;gap:10px;flex-shrink:0}
.hp-search-ico{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#64748b;border:1px solid #e6e9f0;background:#fff;flex-shrink:0}
.hp-search-ico svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 20px;border-radius:var(--r-pill);font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:inherit;text-decoration:none;transition:all .18s cubic-bezier(.2,.7,.3,1);line-height:1;min-height:40px}
.btn-p{background:<?php echo esc_attr($cs); ?>;color:#fff;box-shadow:0 4px 14px rgba(233,123,40,.32)}
.btn-p:hover{filter:brightness(1.06);transform:translateY(-2px);box-shadow:0 8px 22px rgba(233,123,40,.42);color:#fff}
.btn-o{background:transparent;color:<?php echo esc_attr($cp); ?>;border:1.5px solid #dbe1ee}
.btn-o:hover{border-color:<?php echo esc_attr($cp); ?>;background:#F8FAFF;transform:translateY(-2px)}
.btn-lg{padding:16px 32px;font-size:16px;border-radius:var(--r-pill);min-height:52px}
.hp-mb{display:none;background:none;border:none;cursor:pointer;font-size:22px;color:<?php echo esc_attr($cp); ?>;padding:8px;line-height:1;min-width:44px;min-height:44px}
@media(max-width:1080px){.hp-search-ico{display:none}}

/* ---------- hero ----------
   Full Hero Slider structural/animation CSS lives in hero-slider.css
   (enqueued in <head> below); this stylesheet only carries the two things
   that must be set from the admin-configured, per-site $heroSettings PHP
   values — the desktop/mobile image show/hide breakpoint (a real CSS media
   query needs a literal number, not a CSS custom property) and, for the
   handful of hosts where hero-slider.css somehow fails to load, a safe
   baseline so the hero never renders as a blank box. */
.hs-hero{position:relative;overflow:hidden;background:#EEF3FB;width:100%}
.hs-slide-desktop-media{display:block}
.hs-slide-mobile-media{display:none}
@media(max-width:<?php echo (int)$heroBreakpoint; ?>px){
  .hs-slide-desktop-media{display:none}
  .hs-slide-mobile-media{display:block}
}

/* ---------- trust bar (4 pastel-icon items) ----------
   Desktop screenshot review: the user does not want this strip showing on
   desktop (it reads as redundant under the hero image there) but confirmed
   it works fine on mobile, so it's hidden by default and only switched on
   at the same max-width:760px breakpoint the hero <picture> already uses
   for its desktop/mobile image swap, so both change together. */
.hp-trust-bar{display:block;background:#fff;border-top:1px solid #eef1f6;border-bottom:1px solid #eef1f6;padding:22px 20px}
.hp-trust-row{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.hp-trust-item{display:flex;align-items:center;gap:12px}
.hp-trust-ic{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.hp-trust-ic svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.hp-trust-tt{font-size:13.5px;font-weight:800;color:#1e293b}
.hp-trust-sub{font-size:11.5px;color:#94a3b8;font-weight:600}
@media(max-width:900px){.hp-trust-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.hp-trust-row{grid-template-columns:1fr}}

/* ---------- quick apply bar (NAS-style proceed strip) ---------- */
.hp-quickbar{background:linear-gradient(90deg,<?php echo esc_attr($cp); ?>,#28389c);color:#fff;padding:14px 20px}
.hp-quickbar-in{display:flex;align-items:center;gap:14px;flex-wrap:wrap;max-width:1220px;margin:0 auto}
.hp-quickbar-txt{flex:1;min-width:200px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.hp-quickbar select{flex:1;min-width:180px;max-width:320px;border:none;border-radius:10px;padding:11px 14px;font-size:13px;font-family:inherit;color:#1e293b;background:#fff}
.hp-quickbar .btn-p{flex-shrink:0;white-space:nowrap}
@media(max-width:680px){.hp-quickbar-in{flex-direction:column;align-items:stretch}.hp-quickbar select{max-width:none}}

/* ---------- stats band ---------- */
.hp-stats-band{background:linear-gradient(180deg,#F8FAFC,#fff);padding:48px 20px;border-bottom:1px solid #eef1f6}
.hp-stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;max-width:1220px;margin:0 auto}
.hp-stat-card{text-align:center;padding:20px 16px;background:#fff;border:1px solid #eef1f6;border-radius:var(--r-md);box-shadow:var(--sh-1)}
.hp-stat-val{font-size:clamp(26px,3vw,34px);font-weight:900;color:<?php echo esc_attr($cp); ?>;line-height:1.1;font-family:'Poppins',sans-serif}
.hp-stat-lbl{font-size:12px;font-weight:700;color:#64748b;margin-top:6px;text-transform:uppercase;letter-spacing:.04em}
@media(max-width:760px){.hp-stats-grid{grid-template-columns:repeat(2,1fr)}}

/* ---------- cities coverage (NAS-style) ---------- */
.hp-cities-sec{background:#F8FAFC}
.hp-cities-layout{display:grid;grid-template-columns:1fr 1.2fr;gap:36px;align-items:start}
.hp-cities-map{background:linear-gradient(145deg,<?php echo esc_attr($cp); ?>,#28389c);border-radius:var(--r-lg);padding:36px 32px;color:#fff;position:relative;overflow:hidden}
.hp-cities-map::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.08) 1.5px,transparent 1.5px);background-size:22px 22px;opacity:.5}
.hp-cities-map>*{position:relative;z-index:1}
.hp-cities-num{font-size:clamp(36px,5vw,52px);font-weight:900;line-height:1;font-family:'Poppins',sans-serif}
.hp-cities-lbl{font-size:14px;opacity:.85;margin-top:8px;font-weight:600}
.hp-cities-search{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px 12px 40px;font-size:13px;font-family:inherit;margin-bottom:16px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='7' cy='7' r='5'/%3E%3Cpath d='M15 15l-3.5-3.5'/%3E%3C/svg%3E") 14px center no-repeat}
.hp-cities-tags{display:flex;flex-wrap:wrap;gap:10px;max-height:280px;overflow-y:auto;padding-right:4px}
.hp-city-tag{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:var(--r-pill);font-size:12.5px;font-weight:700;color:#334155;transition:all .15s}
.hp-city-tag:hover{border-color:<?php echo esc_attr($cs); ?>;color:<?php echo esc_attr($cp); ?>;transform:translateY(-2px);box-shadow:var(--sh-1)}
.hp-city-dot{width:7px;height:7px;border-radius:50%;background:<?php echo esc_attr($cs); ?>;flex-shrink:0}
@media(max-width:900px){.hp-cities-layout{grid-template-columns:1fr}}

/* ---------- accent CTA band ---------- */
.hp-accent-cta{background:linear-gradient(135deg,<?php echo esc_attr($cp); ?>,#28389c 55%,<?php echo esc_attr($cd); ?>);color:#fff;padding:56px 20px;position:relative;overflow:hidden}
.hp-accent-cta::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.06) 1.5px,transparent 1.5px);background-size:24px 24px;opacity:.5;pointer-events:none}
.hp-accent-in{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;max-width:1220px;margin:0 auto}
.hp-accent-in h2{font-size:clamp(22px,3vw,30px);font-weight:900;color:#fff;margin-bottom:8px;font-family:'Poppins',sans-serif}
.hp-accent-in p{font-size:14px;color:rgba(255,255,255,.75);max-width:520px}
.hp-accent-actions{display:flex;gap:12px;flex-wrap:wrap}
.btn-w{background:#fff;color:<?php echo esc_attr($cp); ?>;box-shadow:0 4px 14px rgba(0,0,0,.15)}
.btn-w:hover{filter:brightness(1.03);transform:translateY(-2px);color:<?php echo esc_attr($cp); ?>}
.btn-g{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.45)}
.btn-g:hover{background:rgba(255,255,255,.1);color:#fff}

.sec{padding:88px 20px;position:relative}
.sec-alt{background:linear-gradient(180deg,#F8FAFC,#F1F5F9);position:relative;overflow:hidden}
/* subtle color-block rhythm behind alternate sections — soft radial blobs
   tied to the real brand accent/primary/secondary colors, kept low-opacity
   and non-interactive so they read as depth, not decoration competing with
   content */
.sec-alt::before,.sec-alt::after{content:"";position:absolute;border-radius:50%;pointer-events:none;z-index:0;filter:blur(2px)}
.sec-alt::before{width:500px;height:500px;top:-200px;right:-160px;background:radial-gradient(circle,<?php echo esc_attr($cs); ?>1f,transparent 70%)}
.sec-alt::after{width:440px;height:440px;bottom:-180px;left:-140px;background:radial-gradient(circle,<?php echo esc_attr($cp); ?>1a,transparent 70%)}
.sec-alt>.w{position:relative;z-index:1}

.hp-sec-hd{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:40px}
.hp-ey{display:inline-flex;align-items:center;gap:9px;font-size:12px;font-weight:800;color:<?php echo esc_attr($cs); ?>;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}
.hp-ey::before{content:"";width:16px;height:2px;border-radius:2px;background:<?php echo esc_attr($cs); ?>;flex-shrink:0}
.hp-tt{font-size:clamp(24px,3.4vw,32px);font-weight:900;color:<?php echo esc_attr($cp); ?>;line-height:1.2;letter-spacing:-.02em}
.hp-st{font-size:14.5px;color:#64748b;max-width:520px;line-height:1.7;margin-top:8px}
.hp-head{text-align:center;margin-bottom:56px}.hp-head .hp-st{margin:8px auto 0}
@media(max-width:600px){.hp-head{margin-bottom:32px}.hp-sec-hd{margin-bottom:26px}.sec{padding:52px 16px}}

/* ---------- services: pastel color-blocked cards, 5-up ---------- */
.svc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:22px}
@media(max-width:1080px){.svc-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:680px){.svc-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:420px){.svc-grid{grid-template-columns:1fr}}
.svc-flat{position:relative;border-radius:var(--r-md);padding:26px 22px;background:var(--svc-bg);display:block;color:inherit;transition:transform .22s cubic-bezier(.2,.7,.3,1),box-shadow .22s;overflow:hidden}
.svc-flat::after{content:"";position:absolute;bottom:-26px;right:-26px;width:64px;height:64px;border-radius:50%;background:var(--svc-ic);opacity:.08;transition:transform .3s}
.svc-flat:hover::after{transform:scale(1.35)}
.svc-flat:hover{transform:translateY(-5px);box-shadow:var(--sh-2)}
.svc-flat-ico{width:46px;height:46px;border-radius:12px;background:var(--svc-ic);display:flex;align-items:center;justify-content:center;margin-bottom:16px}
.svc-flat-ico svg{width:23px;height:23px;stroke:#fff;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.svc-flat-nm{font-size:14.5px;font-weight:800;color:#1e293b;margin-bottom:3px;letter-spacing:-.005em}
.svc-flat-ct{font-size:12px;color:#64748b;font-weight:600}
.svc-flat-tag{position:absolute;top:14px;right:14px;background:rgba(255,255,255,.85);color:var(--svc-ic);font-size:9.5px;font-weight:900;letter-spacing:.03em;text-transform:uppercase;border-radius:var(--r-pill);padding:4px 9px}
.svc-more{display:flex;align-items:center;justify-content:center;min-height:120px}
.svc-more span{font-size:13.5px;font-weight:800;color:#475569}

/* ---------- 2-col "how it works" band (photo + checklist / numbered steps) ---------- */
.hp-people{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
.hp-people-visual{position:relative}
.hp-people-photo{position:relative;aspect-ratio:420/340;border-radius:20px;overflow:hidden;background:linear-gradient(150deg,<?php echo esc_attr($cp); ?>,#28389c 60%,#3a4bab)}
.hp-people-photo::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.1) 1.5px,transparent 1.5px);background-size:22px 22px;opacity:.5}
.hp-people-photo svg{position:absolute;inset:0;width:100%;height:100%}
.hp-people-script{position:absolute;top:14px;left:16px;color:#fff;font-family:Georgia,'Times New Roman',serif;font-style:italic;font-size:17px;z-index:2}
.hp-checklist{position:absolute;right:-18px;bottom:-22px;background:#fff;border-radius:14px;box-shadow:0 20px 44px -14px rgba(16,24,53,.3);padding:16px 18px;display:flex;flex-direction:column;gap:10px;z-index:2;min-width:190px}
.hp-checklist div{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:700;color:#1e293b}
.hp-checklist i{width:18px;height:18px;border-radius:50%;background:#DCFCE7;color:#16A34A;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
@media(max-width:900px){.hp-people{grid-template-columns:1fr}.hp-checklist{right:10px;bottom:-16px}}
@media(max-width:480px){.hp-checklist{position:static;margin-top:14px;box-shadow:var(--sh-1)}}

.hp-steps-row{display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin:30px 0 30px;position:relative}
.hp-step{position:relative;text-align:center}
.hp-step-badge{width:64px;height:64px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;position:relative;background:var(--step-pale)}
.hp-step-badge i{width:42px;height:42px;border-radius:50%;background:var(--step-c);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px -4px color-mix(in srgb, var(--step-c) 55%, transparent)}
.hp-step-badge svg{width:17px;height:17px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.hp-step-arrow{position:absolute;top:32px;left:calc(50% + 38px);width:calc(100% - 76px);height:2px;background:#dbe1ee}
.hp-step-arrow::after{content:"";position:absolute;right:-1px;top:-3px;width:0;height:0;border:4px solid transparent;border-left-color:#dbe1ee}
.hp-step:last-child .hp-step-arrow{display:none}
.hp-step-lbl{font-size:13px;font-weight:800;color:<?php echo esc_attr($cp); ?>}
.hp-step-lbl small{display:block;font-size:11px;font-weight:600;color:#94a3b8;margin-top:2px}
@media(max-width:900px){.hp-steps-row{grid-template-columns:1fr;gap:22px;text-align:left;max-width:340px;margin-left:auto;margin-right:auto}.hp-step{display:flex;align-items:center;gap:16px;text-align:left}.hp-step-badge{margin:0}.hp-step-arrow{display:none}}

/* ---------- track band: full-bleed dark navy ---------- */
.hp-track-sec{background:<?php echo esc_attr($cd); ?>;color:#fff;padding:72px 20px;position:relative;overflow:hidden}
.hp-track-sec::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.06) 1.5px,transparent 1.5px);background-size:26px 26px;opacity:.6;pointer-events:none}
.hp-track-sec::after{content:"";position:absolute;top:-140px;right:-120px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,<?php echo esc_attr($cs); ?>26,transparent 70%);pointer-events:none}
.hp-track{position:relative;z-index:1;display:grid;grid-template-columns:1fr .82fr;gap:48px;align-items:center}
.hp-track h2{color:#fff}
.hp-track .hp-st{color:rgba(255,255,255,.65)}
.hp-track-form{display:flex;gap:10px;max-width:440px;margin-top:24px}
.hp-track-form input{flex:1;border:none;border-radius:10px;padding:13px 16px;font-size:13.5px;font-family:inherit;outline:none}
.hp-track-phone{position:relative;max-width:230px;margin:0 auto}
.hp-track-phone-body{background:#fff;border-radius:26px;border:6px solid #0f1f3d;box-shadow:0 30px 60px -20px rgba(0,0,0,.6);overflow:hidden}
.hp-track-phone-hd{background:linear-gradient(135deg,<?php echo esc_attr($cp); ?>,#28389c);color:#fff;padding:14px 16px;font-size:12px;font-weight:800}
.hp-track-phone-bd{padding:16px 14px}
.hp-tstep{display:flex;gap:10px;position:relative;padding-bottom:18px}
.hp-tstep:last-child{padding-bottom:0}
.hp-tstep::before{content:"";position:absolute;left:8px;top:20px;bottom:0;width:2px;background:#e2e8f0}
.hp-tstep:last-child::before{display:none}
.hp-tstep.done::before{background:<?php echo esc_attr($ca); ?>}
.hp-tdot{width:18px;height:18px;border-radius:50%;background:#e2e8f0;color:#94a3b8;font-size:9px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;z-index:1}
.hp-tstep.done .hp-tdot{background:<?php echo esc_attr($ca); ?>;color:#fff}
.hp-tstep.current .hp-tdot{background:<?php echo esc_attr($cs); ?>;color:#fff}
.hp-tlabel{font-size:11.5px;font-weight:700;color:#1e293b}
.hp-tstep:not(.done):not(.current) .hp-tlabel{color:#94a3b8}
.hp-track-script{text-align:center;color:rgba(255,255,255,.7);font-family:Georgia,'Times New Roman',serif;font-style:italic;font-size:14px;margin-top:14px}
@media(max-width:900px){.hp-track{grid-template-columns:1fr}.hp-track-phone{margin-top:24px}}

/* ---------- why-choose + video ---------- */
.hp-why-band{display:grid;grid-template-columns:1fr .85fr;gap:22px;align-items:stretch}
.why-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.why-card2{position:relative;background:#fff;border:1px solid #eef1f6;border-radius:var(--r-md);padding:28px 24px 26px;box-shadow:var(--sh-1);transition:box-shadow .2s,transform .2s;overflow:hidden}
.why-card2::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--why-c)}
.why-card2::after{content:"";position:absolute;top:-30px;right:-30px;width:80px;height:80px;border-radius:50%;background:var(--why-bg);opacity:.6;z-index:0}
.why-card2>*{position:relative;z-index:1}
.why-card2:hover{box-shadow:var(--sh-2);transform:translateY(-4px)}
.why-ico2{width:46px;height:46px;border-radius:50%;background:var(--why-bg);color:var(--why-c);display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.why-ico2 svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.why-tt2{font-size:15.5px;font-weight:800;color:#1e293b;margin-bottom:4px}
.why-ds2{font-size:12.5px;color:#94a3b8;font-weight:600}
.hp-video-card{position:relative;border-radius:var(--r-md);overflow:hidden;min-height:260px;background:linear-gradient(155deg,#101c33,#233a63 55%,#0f1c33);display:flex;flex-direction:column;justify-content:space-between;padding:22px}
.hp-video-card::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.08) 1.5px,transparent 1.5px);background-size:22px 22px;opacity:.5}
.hp-video-card h3{position:relative;color:#fff;font-size:17px;font-weight:800;line-height:1.3;z-index:1}
.hp-video-play{position:relative;z-index:1;align-self:center;margin:auto 0}
.hp-video-play button{width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,.95);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 10px 26px -6px rgba(0,0,0,.5)}
.hp-video-play svg{width:20px;height:20px;fill:<?php echo esc_attr($cp); ?>;margin-left:2px}
.hp-video-cap{position:relative;z-index:1;color:rgba(255,255,255,.75);font-size:12px;font-weight:700}
@media(max-width:900px){.hp-why-band{grid-template-columns:1fr}}
@media(max-width:560px){.why-grid2{grid-template-columns:1fr}}

/* ---------- testimonials ---------- */
.testi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
@media(max-width:900px){.testi-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.testi-grid{grid-template-columns:1fr}}
.testi-card{position:relative;background:#fff;border:1px solid #eef1f6;border-radius:var(--r-md);padding:30px 26px;box-shadow:var(--sh-1);transition:box-shadow .2s,transform .2s;display:flex;flex-direction:column;gap:14px;overflow:hidden}
.testi-card::before{content:"\201C";position:absolute;top:-14px;right:14px;font-family:Georgia,'Times New Roman',serif;font-size:88px;font-weight:900;line-height:1;color:<?php echo esc_attr($cp); ?>;opacity:.07;pointer-events:none}
.testi-card:hover{box-shadow:var(--sh-3);transform:translateY(-5px)}
.testi-who{display:flex;align-items:center;gap:11px}
.testi-avatar{width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,<?php echo esc_attr($cp); ?>,<?php echo esc_attr($cs); ?>);color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.testi-name{font-size:14.5px;font-weight:800;color:#1e293b}
.testi-meta{font-size:11.5px;color:#94a3b8;font-weight:600}
.testi-stars{color:<?php echo esc_attr($cs); ?>;font-size:15px;letter-spacing:1.5px}
.testi-quote{font-size:14.5px;color:#334155;line-height:1.75;font-style:italic}

/* ---------- rating trust element (no fabricated testimonials) ---------- */
.hp-rating-card{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:38px 36px;text-align:center;box-shadow:0 16px 40px -18px rgba(27,42,107,.22)}
.hp-rating-big{font-size:44px;font-weight:900;color:<?php echo esc_attr($cp); ?>;line-height:1}
.hp-rating-stars{color:<?php echo esc_attr($cs); ?>;font-size:20px;letter-spacing:2px;margin:10px 0}
.hp-rating-note{font-size:13px;color:#64748b;max-width:440px;margin:8px auto 0;line-height:1.6}

/* ---------- WhatsApp / stay-connected band (replaces fabricated app-store band) ---------- */
.hp-app-band{background:#F1F5F9;border-radius:var(--r-lg);padding:44px 40px;display:grid;grid-template-columns:1.2fr .8fr 1fr;gap:32px;align-items:center}
.hp-app-check{display:flex;flex-direction:column;gap:10px;margin:16px 0 20px}
.hp-app-check div{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;color:#334155}
.hp-app-check i{width:18px;height:18px;border-radius:50%;background:#DCFCE7;color:#16A34A;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
.hp-phone-mini{width:130px;height:230px;margin:0 auto;border-radius:20px;border:6px solid #0f1f3d;background:linear-gradient(150deg,<?php echo esc_attr($cp); ?>,#28389c);display:flex;align-items:center;justify-content:center;box-shadow:0 20px 44px -14px rgba(16,24,53,.35)}
.hp-phone-mini svg{width:44px;height:44px;stroke:#fff;fill:none;stroke-width:1.4}
.hp-qr-block{text-align:center}
.hp-qr-box{width:104px;height:104px;margin:0 auto 8px;border-radius:12px;background:#fff;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;box-shadow:var(--sh-1)}
.hp-qr-box svg{width:74px;height:74px}
.hp-qr-cap{font-family:Georgia,'Times New Roman',serif;font-style:italic;color:<?php echo esc_attr($cp); ?>;font-size:13px}
@media(max-width:900px){.hp-app-band{grid-template-columns:1fr;text-align:center}.hp-app-check{align-items:center}}

/* ---------- FAQ ---------- */
.faq-wrap{max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:12px}
.faq-it{position:relative;background:#fff;border:1px solid #e6e9f0;border-left:3px solid transparent;border-radius:var(--r-sm);box-shadow:var(--sh-1);transition:box-shadow .2s,border-color .2s}
.faq-it.faq-open{box-shadow:var(--sh-2);border-color:<?php echo esc_attr($cp); ?>26;border-left-color:<?php echo esc_attr($cs); ?>}
.faq-q{width:100%;background:none;border:none;text-align:left;padding:22px 26px;font-size:16.5px;font-weight:700;color:#1e293b;cursor:pointer;display:flex;align-items:center;gap:16px;font-family:inherit;line-height:1.4;min-height:48px}
.faq-q-txt{flex:1}
.faq-idx{flex-shrink:0;width:30px;height:30px;border-radius:9px;background:#EFF6FF;color:<?php echo esc_attr($cp); ?>;font-size:12.5px;font-weight:900;display:flex;align-items:center;justify-content:center;transition:background .2s,color .2s}
.faq-it.faq-open .faq-idx{background:<?php echo esc_attr($cp); ?>;color:#fff}
.faq-q:hover{color:<?php echo esc_attr($cp); ?>}
.faq-plus{width:26px;height:26px;border-radius:50%;background:#EFF6FF;font-size:16px;font-weight:600;flex-shrink:0;color:<?php echo esc_attr($cp); ?>;transition:transform .25s,background .2s;line-height:1;display:flex;align-items:center;justify-content:center}
.faq-a-wrap{display:grid;grid-template-rows:0fr;transition:grid-template-rows .25s ease}
.faq-a-in{overflow:hidden}
.faq-a{padding:0 22px 20px;font-size:14px;color:#64748b;line-height:1.75}
.faq-it.faq-open .faq-a-wrap{grid-template-rows:1fr}
.faq-it.faq-open .faq-plus{transform:rotate(135deg);background:<?php echo esc_attr($cs); ?>;color:#fff}
@media(prefers-reduced-motion:reduce){.faq-a-wrap{transition:none}}

/* ---------- footer (4-col dark navy) ---------- */
footer{background:<?php echo esc_attr($cd); ?>;color:rgba(255,255,255,.7)}
.ft-top{padding:60px 20px 40px;display:grid;grid-template-columns:1.4fr 1fr 1fr 1.2fr;gap:36px}
.ft-logo{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:900;color:#fff;margin-bottom:10px}
.ft-logo-mark{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ft-logo-mark svg{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.ft-desc{font-size:13px;line-height:1.7;margin-bottom:16px}
.ft-social{display:flex;gap:10px}
.ft-social a{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:#fff}
.ft-social svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.8}
.ft-title{font-size:14px;font-weight:800;color:#fff;margin-bottom:16px}
.ft-links{display:flex;flex-direction:column;gap:10px}
.ft-links a{font-size:13px;color:rgba(255,255,255,.6);transition:color .15s}.ft-links a:hover{color:#fff}
.ft-contact{display:flex;flex-direction:column;gap:12px}
.ft-contact div{display:flex;align-items:flex-start;gap:9px;font-size:13px;color:rgba(255,255,255,.65)}
.ft-contact svg{width:15px;height:15px;stroke:<?php echo esc_attr($cs); ?>;fill:none;stroke-width:1.8;flex-shrink:0;margin-top:2px}
.ft-bot{border-top:1px solid rgba(255,255,255,.1);padding:16px 20px;display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center;font-size:12px}
.ft-bl{display:flex;gap:16px;flex-wrap:wrap}
.ft-bl a{color:rgba(255,255,255,.5)}.ft-bl a:hover{color:#fff}
@media(max-width:900px){
  .ft-top{grid-template-columns:1fr 1fr}
  .hp-nav{display:none}.hp-mb{display:block}
  .hp-nav.hp-open{display:flex;flex-direction:column;position:absolute;top:70px;left:0;right:0;background:#fff;z-index:300;border-bottom:2px solid #e2e8f0;padding:12px;gap:2px;box-shadow:0 8px 24px rgba(0,0,0,.1)}
  .admin-bar .hp-nav.hp-open{top:102px}
}
@media(max-width:600px){
  .ft-top{grid-template-columns:1fr 1fr}
  .sec{padding:44px 16px}
  .w{padding:0 16px}
}
@supports(padding:max(0px)){
  .w{padding-left:max(20px,env(safe-area-inset-left));padding-right:max(20px,env(safe-area-inset-right))}
  @media(max-width:600px){.w{padding-left:max(16px,env(safe-area-inset-left));padding-right:max(16px,env(safe-area-inset-right))}}
}

/* ---------- design-polish pass ----------
   Additive refinements only — no selector removed/renamed, no markup
   changed, no admin-configurable default color/spacing/radius value
   altered (DesignSettingsService's zero-delta-at-default guarantee for
   .hp-tt/.hp-ey/.btn/.hp-nav a/.sec/.w/--r-md/--sh-1..3 stays intact,
   since none of those rules are touched below). Inspired by the NAS v7
   reference's premium finishing touches — gradient accent stripes,
   pill-style eyebrow labels, gradient underline under section titles,
   and consistent icon-hover lift — reinterpreted with RTOFlow's own
   primary/secondary/accent colors instead of NAS's palette. */
.hp-ey{background:linear-gradient(90deg,<?php echo esc_attr($cs); ?>14,<?php echo esc_attr($cp); ?>0d);padding:5px 12px 5px 10px;border-radius:var(--r-pill)}
.hp-ey::before{width:14px}
.hp-head .hp-tt::after,.hp-sec-hd>div .hp-tt::after{content:"";display:block;width:52px;height:3px;border-radius:2px;margin-top:14px;background:linear-gradient(90deg,<?php echo esc_attr($cs); ?>,<?php echo esc_attr($cp); ?>)}
.hp-head .hp-tt::after{margin-left:auto;margin-right:auto}
.svc-flat-ico,.why-ico2,.hp-step-badge i,.hp-trust-ic{transition:transform .25s cubic-bezier(.2,.7,.3,1)}
.svc-flat:hover .svc-flat-ico{transform:scale(1.08) rotate(-4deg)}
.why-card2:hover .why-ico2{transform:scale(1.1)}
.hp-trust-bar{position:relative}
.hp-trust-bar::before{content:"";position:absolute;top:-1px;left:0;right:0;height:3px;background:linear-gradient(90deg,<?php echo esc_attr($cs); ?>,<?php echo esc_attr($cp); ?>,<?php echo esc_attr($ca); ?>)}
#faq{position:relative}
#faq::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,<?php echo esc_attr($cs); ?>,<?php echo esc_attr($cp); ?>,<?php echo esc_attr($ca); ?>)}
.faq-it{transition:box-shadow .2s,border-color .2s,transform .2s}
.faq-it.faq-open{transform:translateY(-2px)}
footer{position:relative}
footer::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,<?php echo esc_attr($cs); ?>,<?php echo esc_attr($cp); ?>,<?php echo esc_attr($ca); ?>,<?php echo esc_attr($cs); ?>)}
.testi-card:hover .testi-avatar{transform:scale(1.06)}
.testi-avatar{transition:transform .2s}
@media(prefers-reduced-motion:reduce){
  .svc-flat:hover .svc-flat-ico,.why-card2:hover .why-ico2,.testi-card:hover .testi-avatar{transform:none}
}

/* ---------- design-polish pass, part 2 ----------
   Continues the NAS v7-inspired finishing pass: a real 3-way accent-color
   cycle (RTOFlow's own primary/secondary/accent — never an invented
   4th/5th color) applied to testimonial cards and FAQ items the same way
   the services grid already cycles $pastels per card, plus color-tinted
   "glow" shadows on hover (NAS's color-mix() shadow technique) instead of
   the flat grey .sh-2/.sh-3 shadow every card currently shares regardless
   of its own accent. Still fully additive — .sh-1/.sh-2/.sh-3 and --r-md
   keep their existing values and are only overridden per-card on :hover,
   never at rest, so DesignSettingsService's default output is unaffected. */
.testi-card{border-top:3px solid var(--testi-ac,<?php echo esc_attr($cp); ?>);transition:box-shadow .2s,transform .2s}
.testi-card:nth-child(3n+1){--testi-ac:<?php echo esc_attr($cp); ?>}
.testi-card:nth-child(3n+2){--testi-ac:<?php echo esc_attr($cs); ?>}
.testi-card:nth-child(3n+3){--testi-ac:<?php echo esc_attr($ca); ?>}
.testi-card:hover{box-shadow:0 16px 36px -16px color-mix(in srgb, var(--testi-ac,<?php echo esc_attr($cp); ?>) 45%, transparent)}
.testi-avatar{background:linear-gradient(135deg,var(--testi-ac,<?php echo esc_attr($cp); ?>),<?php echo esc_attr($cs); ?>)}

.faq-it:nth-child(3n+1){--faq-ac:<?php echo esc_attr($cp); ?>}
.faq-it:nth-child(3n+2){--faq-ac:<?php echo esc_attr($cs); ?>}
.faq-it:nth-child(3n+3){--faq-ac:<?php echo esc_attr($ca); ?>}
.faq-it.faq-open{border-left-color:var(--faq-ac,<?php echo esc_attr($cs); ?>);box-shadow:0 10px 28px -10px color-mix(in srgb, var(--faq-ac,<?php echo esc_attr($cs); ?>) 30%, transparent)}
.faq-it.faq-open .faq-idx{background:var(--faq-ac,<?php echo esc_attr($cp); ?>)}
.faq-it.faq-open .faq-plus{background:var(--faq-ac,<?php echo esc_attr($cs); ?>)}

.svc-flat:hover{box-shadow:0 16px 34px -14px color-mix(in srgb, var(--svc-ic) 45%, transparent)}
.why-card2:hover{box-shadow:0 16px 34px -14px color-mix(in srgb, var(--why-c) 40%, transparent)}

/* Hero → content seam: NAS avoids a hard cut between the hero band and the
   section below it; the hero itself lives in hero-slider.css (an admin-
   managed asset, intentionally not touched here), so the seam is softened
   from this side instead with a short gradient fade at the very top of the
   trust bar / services section that follows it. */
.hs-hero + .hp-trust-bar,.hs-hero + section.sec{position:relative}
.hs-hero + .hp-trust-bar::after,.hs-hero + section.sec::after{content:"";position:absolute;top:0;left:0;right:0;height:28px;background:linear-gradient(180deg,rgba(10,22,40,.05),transparent);pointer-events:none}

/* Section-to-section rhythm: give the dark/full-bleed track band the same
   gradient top-stripe language already applied to the trust bar, FAQ and
   footer above, so the accent stripe reads as one consistent motif used
   everywhere a section boundary needs marking. NOTE: .hp-track-sec already
   owns both ::before (dot-grid overlay) and ::after (radial glow orb) —
   see the "track band" rules earlier in this stylesheet — so the stripe is
   added via border-image on the element itself instead of a third pseudo-
   element, which doesn't exist here, to avoid silently overwriting either
   existing effect. */
.hp-track-sec{border-top:3px solid;border-image:linear-gradient(90deg,<?php echo esc_attr($cs); ?>,<?php echo esc_attr($cp); ?>,<?php echo esc_attr($ca); ?>) 1}

/* ---------- design-polish pass, part 3 ----------
   Hero itself stays exactly as-is per instruction (hero-slider.css/.js
   untouched, no rule here targets .hs-hero, .hs-slide*, .hs-content*, or
   .hs-arrow/.hs-dots). This pass finishes the two sections that hadn't
   received the color-tinted "glow shadow" + hover-lift treatment yet: the
   why-us video card and the WhatsApp/stay-connected band, both of which
   previously used only the flat grey shadow scale or no hover feedback at
   all despite being clickable (.hp-video-card is an <a>). */
.hp-video-card{transition:transform .25s cubic-bezier(.2,.7,.3,1),box-shadow .25s}
.hp-video-card:hover{transform:translateY(-5px);box-shadow:0 20px 44px -16px rgba(19,31,56,.45)}
.hp-video-play button{transition:transform .2s}
.hp-video-card:hover .hp-video-play button{transform:scale(1.08)}
@media(prefers-reduced-motion:reduce){.hp-video-card:hover{transform:none}.hp-video-card:hover .hp-video-play button{transform:none}}

.hp-app-band{box-shadow:var(--sh-1);transition:box-shadow .25s}
.hp-app-band:hover{box-shadow:0 20px 44px -18px color-mix(in srgb, <?php echo esc_attr($cp); ?> 22%, transparent)}
.hp-phone-mini{transition:transform .25s}
.hp-app-band:hover .hp-phone-mini{transform:translateY(-3px)}
@media(prefers-reduced-motion:reduce){.hp-app-band:hover .hp-phone-mini{transform:none}}

.hp-checklist{transition:box-shadow .25s}
.hp-people-visual:hover .hp-checklist{box-shadow:0 24px 50px -16px color-mix(in srgb, <?php echo esc_attr($cp); ?> 30%, rgba(16,24,53,.3))}
</style>
<?php
// FIX (mobile pass — public site bottom nav): home.php builds its own
// standalone <html>/<body> and never went through layouts/website-header.php,
// so it was missed by that shared-layout bottom-nav fix even though it's
// the site's single most-visited public page.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/hero-slider.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/hero-slider.css')) ?>">
<style>
/* Mobile content-position override for the hero slider: each slide's
   .hs-content-inner carries BOTH a .hs-cp-{desktop position} class (rules
   defined unconditionally in hero-slider.css, loaded just above) and a
   .hs-cp-mobile-{mobile position} class. This block must be printed AFTER
   that stylesheet link so — at equal selector specificity — these
   mobile-only, breakpoint-gated rules win the cascade under the site's
   admin-configured breakpoint instead of the always-on desktop rule. */
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
<?php
// Preload the FIRST active slide's matching device image only (not every
// slide — preloading N slides' worth of high-res images would fight the
// first paint instead of helping it; slide 2+ load lazily as normal <img>
// tags below). Mirrors the exact technique the old static hero used: two
// candidates, gated by media= so only the one the browser will actually
// render is fetched early.
$heroFirstSlide = $heroSlides[0] ?? null;
?>
<?php if ($heroFirstSlide): ?>
<link rel="preload" as="image" href="<?= esc_url($heroFirstSlide['desktop_image_url']) ?>" media="(min-width:<?= (int)$heroBreakpoint + 1 ?>px)">
<link rel="preload" as="image" href="<?= esc_url($heroFirstSlide['mobile_image_url']) ?>" media="(max-width:<?= (int)$heroBreakpoint ?>px)">
<?php endif; ?>
<?php
// Design & Typography Settings (see app/Services/DesignSettingsService.php)
// — deliberately the LAST stylesheet in <head>. Every rule it emits targets
// a selector already used above (h1/h2/h3, .hp-tt, .hp-st, .hp-ey, .btn,
// .hp-nav a, .sec, .w) at the SAME specificity as this page's own inline
// <style>, so loading it last is what makes it win the cascade — the exact
// mechanism already used (and the exact mistake already made once, then
// fixed — see hero-slider.css) elsewhere on this page. ?v= is the saved
// settings' own update timestamp, so a save always busts the 1-year cache
// this URL is served with.
?>
<link rel="stylesheet" href="<?= esc_url(home_url('/rto-design.css')) ?>?v=<?= esc_attr(\RTOFLOW\Services\DesignSettingsService::version()) ?>">
</head>
<body class="rto-website">
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>
<div class="hp-topbar">
<?php if($tel):?><a href="tel:<?php echo esc_attr($tel);?>"><?php echo esc_html($tel);?></a> &nbsp;&middot;&nbsp;<?php endif;?>
Mon-Sat 9AM-7PM &nbsp;&middot;&nbsp; Pan India RTO Assistance &nbsp;&middot;&nbsp;
<a href="<?php echo esc_url(home_url('/rto-track/'));?>">Track Application &rarr;</a>
</div>
<header class="hp-hdr">
<div class="w hp-hdr-in">
  <a href="<?php echo esc_url(home_url('/'));?>" class="hp-logo">
    <span class="hp-logo-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.6 2.6M15.4 15.4L18 18M18 6l-2.6 2.6M8.6 15.4L6 18"/></svg></span>
    <span class="hp-logo-txt"><b><?php echo esc_html($co);?></b><small>Drive Legal, Drive Easy.</small></span>
  </a>
  <nav class="hp-nav" id="hpNav" aria-label="Main navigation">
    <a href="<?php echo esc_url(home_url('/'));?>" class="hp-active">Home</a>
    <a href="<?php echo esc_url(home_url('/rto-apply/'));?>">Services</a>
    <a href="<?php echo esc_url(home_url('/rto-services-cities'));?>">RTO Information</a>
    <a href="<?php echo esc_url(home_url('/rto-track/'));?>">Check Status</a>
    <a href="<?php echo esc_url(home_url('/how-it-works'));?>">Guides</a>
    <a href="<?php echo esc_url(home_url('/about'));?>">About</a>
  </nav>
  <div class="hp-cta">
    <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="hp-search-ico" aria-label="Search services"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></a>
    <?php if(is_user_logged_in()):?>
    <a href="<?php echo esc_url(home_url('/rto-dashboard/'));?>" class="btn btn-p">My Dashboard</a>
    <?php else:?>
    <a href="<?php echo esc_url(home_url('/login'));?>" class="btn btn-o">Login</a>
    <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="btn btn-p">Get Started</a>
    <?php endif;?>
  </div>
  <button class="hp-mb" id="hpMB" aria-label="Toggle menu" aria-expanded="false">&#9776;</button>
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
      <?php if ($slide['heading'] !== '' || $slide['description'] !== '' || $slide['cta_text'] !== ''): ?>
      <div class="hs-content">
        <div class="hs-content-inner hs-cp-<?= esc_attr($slide['content_position_desktop']) ?> hs-cp-mobile-<?= esc_attr($slide['content_position_mobile']) ?>">
          <?php if ($slide['heading'] !== ''): ?><h2><?= esc_html($slide['heading']) ?></h2><?php endif; ?>
          <?php if ($slide['description'] !== ''): ?><p><?= esc_html($slide['description']) ?></p><?php endif; ?>
          <?php if ($slide['cta_text'] !== '' && $slide['cta_url'] !== ''): ?>
          <a href="<?= esc_url($slide['cta_url']) ?>" class="hs-cta"><?= esc_html($slide['cta_text']) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($heroSlides) > 1): ?>
  <button type="button" class="hs-arrow hs-arrow-prev" aria-label="Previous slide">&#8249;</button>
  <button type="button" class="hs-arrow hs-arrow-next" aria-label="Next slide">&#8250;</button>
  <div class="hs-dots" role="tablist" aria-label="Hero slides">
    <?php foreach ($heroSlides as $si => $slide): ?>
    <button type="button" class="hs-dot<?= $si === 0 ? ' hs-active' : '' ?>" data-index="<?= (int)$si ?>" role="tab" aria-selected="<?= $si === 0 ? 'true' : 'false' ?>" aria-label="Go to slide <?= (int)$si + 1 ?>"></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="hp-trust-bar hp-reveal">
<div class="w hp-trust-row">
  <?php
    // CORRECTED (service-claims audit): every item below is a real, already-
    // documented claim used elsewhere on this page — no fabricated stats.
    $trustQuad = [
      ['bg'=>'#E8F8EF','ic'=>'#1FAE63','svg'=>'<path d="M5 13l4 4L19 7"/>','tt'=>'Application Assistance','sub'=>'As per RTO rules'],
      ['bg'=>'#FFF1E3','ic'=>'#C2660F','svg'=>'<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.4"/>','tt'=>'Pan India Support','sub'=>$cc>0?((int)$cc.'+ Cities Covered'):'Growing City Coverage'],
      ['bg'=>'#EAF1FF','ic'=>'#2F6FED','svg'=>'<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M7 7V5.5A2.5 2.5 0 0 1 9.5 3h5A2.5 2.5 0 0 1 17 5.5V7"/>','tt'=>'Transparent Pricing','sub'=>'No Hidden Charges'],
      ['bg'=>'#F1EAFB','ic'=>'#7C3AED','svg'=>'<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-4 3-7 7-7s7 3 7 7"/>','tt'=>'Expert Team','sub'=>'End-to-End Assistance'],
    ];
    foreach ($trustQuad as $q):
  ?>
  <div class="hp-trust-item">
    <div class="hp-trust-ic" style="background:<?php echo esc_attr($q['bg']);?>;color:<?php echo esc_attr($q['ic']);?>"><svg viewBox="0 0 24 24"><?php echo $q['svg'];?></svg></div>
    <div><div class="hp-trust-tt"><?php echo esc_html($q['tt']);?></div><div class="hp-trust-sub"><?php echo esc_html($q['sub']);?></div></div>
  </div>
  <?php endforeach; ?>
</div>
</div>

<div class="hp-quickbar hp-reveal">
  <div class="hp-quickbar-in w">
    <span class="hp-quickbar-txt"><i class="fa-solid fa-file-signature"></i> Start your RTO application in minutes</span>
    <select id="hpQuickService" aria-label="Select a service">
      <option value="">Choose a service…</option>
      <?php foreach ($services ?? [] as $svc): ?>
      <option value="<?php echo esc_attr($svc['slug'] ?? ''); ?>"><?php echo esc_html($svc['name'] ?? ''); ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="btn btn-p" id="hpQuickGo">Apply Now <i class="fa-solid fa-arrow-right"></i></a>
  </div>
</div>

<?php
$hpStats = [];
if ($lead_count > 0)   $hpStats[] = [number_format($lead_count), 'Applications Completed'];
if ($city_count > 0)   $hpStats[] = [$city_count . '+', 'Cities Covered'];
$svcTotal = count($services ?? []);
if ($svcTotal > 0)     $hpStats[] = [$svcTotal . '+', 'RTO Services'];
if ($ratingAvg !== null && $ratingCount >= 5) $hpStats[] = [number_format($ratingAvg, 1) . '★', 'Customer Rating'];
?>
<?php if (!empty($hpStats)): ?>
<section class="hp-stats-band hp-reveal" aria-label="Platform statistics">
  <div class="hp-stats-grid">
    <?php foreach ($hpStats as $st): ?>
    <div class="hp-stat-card">
      <div class="hp-stat-val"><?php echo esc_html($st[0]); ?></div>
      <div class="hp-stat-lbl"><?php echo esc_html($st[1]); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="sec" id="services">
<div class="w">
  <div class="hp-sec-hd hp-reveal">
    <div><div class="hp-ey">What We Do</div><h2 class="hp-tt">Our RTO Services</h2><p class="hp-st">Choose from a wide range of RTO services. Click on any service to get started.</p></div>
    <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="btn btn-o">View All Services &rarr;</a>
  </div>
  <div class="svc-grid">
  <?php
    // Pastel color-blocked cards, still driven entirely by the real
    // $cat_config / $by_cat data from Router.php — no category name or
    // count is invented here. $pastels (defined above, top of file) cycles
    // per card in display order, same technique as $cat_config's own
    // gradient-color assignment, just applied to a softer palette.
    $pi = 0;
    foreach($cat_config as $cn=>$ci):
    $svcs=($by_cat[$cn]??[]);$cnt=count($svcs);
    $pal = $pastels[$pi % count($pastels)]; $pi++;
    ?>
  <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="svc-flat hp-reveal" style="--svc-bg:<?php echo esc_attr($pal['bg']);?>;--svc-ic:<?php echo esc_attr($pal['ic']);?>">
    <?php if ($popularCat !== null && $cn === $popularCat && $popularMax > 0): ?>
    <span class="svc-flat-tag">Popular</span>
    <?php endif; ?>
    <div class="svc-flat-ico"><svg viewBox="0 0 24 24" stroke-width="1.6" aria-hidden="true"><?php echo $catIcons[$ci['icon']]; ?></svg></div>
    <div class="svc-flat-nm"><?php echo esc_html($ci['label']);?></div>
    <div class="svc-flat-ct"><?php echo $cnt>0 ? ($cnt.' service'.($cnt===1?'':'s')) : 'Explore services';?></div>
  </a>
  <?php endforeach;?>
  <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="svc-flat svc-more hp-reveal" style="--svc-bg:#F1F5F9;--svc-ic:<?php echo esc_attr($cp);?>">
    <span>All Services &rarr;</span>
  </a>
  </div>
</div>
</section>

<section class="sec sec-alt">
<div class="w">
  <div class="hp-people">
    <div class="hp-people-visual hp-reveal">
      <!--
        RECOMMENDED PHOTO (once available): a customer smiling at their
        phone while checking application status. No stock photo access in
        this session — recreated as a CSS/SVG illustration standing in for
        the reference's photo, with the same floating checklist card.
      -->
      <div class="hp-people-photo">
        <svg viewBox="0 0 420 340" role="img" aria-label="Illustration of a customer checking their RTO application on a phone">
          <ellipse cx="230" cy="190" rx="150" ry="150" fill="rgba(255,255,255,.06)"/>
          <path d="M70 340c0-72 46-118 118-118h20c72 0 118 46 118 118" fill="rgba(255,255,255,.15)"/>
          <path d="M92 340c0-60 40-98 100-98h6c60 0 100 38 100 98" fill="rgba(255,255,255,.22)"/>
          <rect x="188" y="150" width="36" height="34" rx="12" fill="rgba(255,255,255,.24)"/>
          <circle cx="204" cy="116" r="46" fill="rgba(255,255,255,.28)"/>
          <path d="M168 118c0-24 16-42 38-42 8 16 26 22 40 20-2 26-20 44-42 44-20 0-36-10-36-22z" fill="rgba(255,255,255,.34)"/>
          <path d="M258 222c22-6 40-24 46-48l18 6c-6 30-28 54-58 62z" fill="rgba(255,255,255,.24)"/>
          <path d="M300 178c10-14 14-30 12-46l18-2c4 20-2 40-14 56z" fill="rgba(255,255,255,.3)"/>
          <rect x="292" y="118" width="40" height="70" rx="9" fill="rgba(10,22,40,.55)" stroke="rgba(255,255,255,.5)" stroke-width="2"/>
          <rect x="299" y="130" width="26" height="6" rx="3" fill="rgba(255,255,255,.55)"/>
          <rect x="299" y="142" width="20" height="5" rx="2.5" fill="rgba(255,255,255,.35)"/>
          <rect x="299" y="152" width="24" height="5" rx="2.5" fill="rgba(255,255,255,.35)"/>
          <circle cx="311" cy="170" r="7" fill="rgba(52,211,153,.85)"/>
          <path d="M307.5 170l2.5 3 5-6" stroke="#0A1628" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="hp-people-script">RTO Work<br>Made Easy!</div>
      <div class="hp-checklist">
        <div><i>&#10003;</i>Select Service</div>
        <div><i>&#10003;</i>Share Documents</div>
        <div><i>&#10003;</i>Get Expert Support</div>
        <div><i>&#10003;</i>Track Progress</div>
      </div>
    </div>
    <div class="hp-reveal">
      <div class="hp-ey">Simple Process</div>
      <h2 class="hp-tt">Get Your RTO Work Done<br>in 4 Simple Steps</h2>
      <p class="hp-st" style="margin-bottom:8px">Document pickup/drop is available in select cities, subject to charges &mdash; see the disclosure below.</p>
      <?php
        $stepData = [
          ['pale'=>'#FFF1E3','c'=>'#E97B28','svg'=>'<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>','n'=>'1','t'=>'Choose','s'=>'Your Service'],
          ['pale'=>'#EAF1FF','c'=>'#2F6FED','svg'=>'<path d="M12 4v11M7 10l5 5 5-5"/><path d="M5 19h14"/>','n'=>'2','t'=>'Submit','s'=>'Documents'],
          ['pale'=>'#E8F8EF','c'=>'#1FAE63','svg'=>'<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-4 3-7 7-7s7 3 7 7"/>','n'=>'3','t'=>'Expert','s'=>'Verification'],
          ['pale'=>'#F1EAFB','c'=>'#7C3AED','svg'=>'<path d="M5 13l4 4L19 7"/>','n'=>'4','t'=>'Service','s'=>'Completed'],
        ];
      ?>
      <div class="hp-steps-row">
      <?php foreach ($stepData as $sd): ?>
      <div class="hp-step" style="--step-pale:<?php echo esc_attr($sd['pale']);?>;--step-c:<?php echo esc_attr($sd['c']);?>">
        <div class="hp-step-badge"><i><svg viewBox="0 0 24 24"><?php echo $sd['svg'];?></svg></i></div>
        <span class="hp-step-arrow" aria-hidden="true"></span>
        <div class="hp-step-lbl"><?php echo esc_html($sd['n'].'. '.$sd['t']);?><small><?php echo esc_html($sd['s']);?></small></div>
      </div>
      <?php endforeach; ?>
      </div>
      <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="btn btn-p btn-lg">Get Started Now &rarr;</a>
    </div>
  </div>
  <p style="text-align:center;font-size:12px;color:#94a3b8;max-width:900px;margin:36px auto 0;padding:0 16px">
    Doorstep pickup/drop is available only where a local agent or courier partner covers your city, may involve additional
    charges beyond the standard service fee, and is not part of every service's base price. Government approval, processing
    time, and issuance are determined solely by the concerned RTO/authority &mdash; we assist with the application, we do not
    control or guarantee the outcome.
  </p>
</div>
</section>

<?php if (!empty($city_list)): ?>
<section class="sec hp-cities-sec" id="coverage">
<div class="w">
  <div class="hp-sec-hd hp-reveal">
    <div>
      <div class="hp-ey">Nationwide Coverage</div>
      <h2 class="hp-tt">RTO Services Across India</h2>
      <p class="hp-st">Application assistance in <?php echo $city_count > 0 ? (int)$city_count . '+' : 'major'; ?> cities — find your city and get started.</p>
    </div>
    <a href="<?php echo esc_url(home_url('/rto-services-cities')); ?>" class="btn btn-o">View All Cities &rarr;</a>
  </div>
  <div class="hp-cities-layout hp-reveal">
    <div class="hp-cities-map">
      <div class="hp-cities-num"><?php echo $city_count > 0 ? (int)$city_count . '+' : count($city_list); ?></div>
      <div class="hp-cities-lbl">cities covered across India</div>
      <p style="margin-top:20px;font-size:13px;opacity:.8;line-height:1.6">Local agents and expert support for RC transfer, driving licence, NOC, hypothecation and more.</p>
    </div>
    <div>
      <input type="search" class="hp-cities-search" id="hpCitySearch" placeholder="Search cities…" autocomplete="off" aria-label="Search cities">
      <div class="hp-cities-tags" id="hpCityTags">
        <?php foreach ($city_list as $c): ?>
        <a href="<?php echo esc_url(home_url('/rto-services-cities/' . ($c['slug'] ?? ''))); ?>" class="hp-city-tag" data-name="<?php echo esc_attr(strtolower($c['name'] ?? '')); ?>">
          <span class="hp-city-dot"></span><?php echo esc_html($c['name'] ?? ''); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</section>
<?php endif; ?>

<section class="hp-track-sec" id="track">
<div class="w hp-track">
  <div class="hp-reveal">
    <div class="hp-ey" style="color:#ffb27a">Stay Informed</div>
    <h2 class="hp-tt">Track Your Application in Real-Time</h2>
    <p class="hp-st">Enter your application number to check the latest status. The RTO/authority alone determines final processing time and outcome.</p>
    <form class="hp-track-form" action="<?php echo esc_url(home_url('/rto-track/'));?>" method="get">
      <input type="text" name="token" placeholder="Enter your application number" aria-label="Application number">
      <button type="submit" class="btn btn-p">Check Status</button>
    </form>
  </div>
  <div class="hp-reveal" aria-hidden="true">
    <div class="hp-track-phone">
      <div class="hp-track-phone-body">
        <div class="hp-track-phone-hd">Application Status</div>
        <div class="hp-track-phone-bd">
          <?php $curSet=false; foreach($trackMock as $i=>$st):
            $isDone = $st['done'];
            $isCur  = !$isDone && !$curSet; if($isCur) $curSet = true;
            $cls = $isDone ? 'done' : ($isCur ? 'current' : '');
          ?>
          <div class="hp-tstep <?php echo $cls;?>">
            <div class="hp-tdot"><?php echo $isDone ? '&#10003;' : ($i+1);?></div>
            <div class="hp-tlabel"><?php echo esc_html($st['label']);?></div>
          </div>
          <?php endforeach;?>
        </div>
      </div>
    </div>
    <div class="hp-track-script">Stay Updated Always! &#9992;</div>
  </div>
</div>
</section>

<section class="sec sec-alt" id="why">
<div class="w">
  <div class="hp-head hp-reveal"><div class="hp-ey" style="justify-content:center">Why Choose Us</div>
    <h2 class="hp-tt">Why Choose <?php echo esc_html($co);?>?</h2>
    <p class="hp-st">We make your RTO experience simple, fast and reliable.</p>
  </div>
  <div class="hp-why-band hp-reveal">
    <div class="why-grid2">
      <?php
        $why4 = [
          ['bg'=>'#E8F8EF','c'=>'#1FAE63','svg'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>','t'=>'Time Saving','s'=>'Avoid long queues at RTO offices'],
          ['bg'=>'#EAF1FF','c'=>'#2F6FED','svg'=>'<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-4 3-7 7-7s7 3 7 7"/>','t'=>'Expert Guidance','s'=>'Get help from RTO specialists'],
          ['bg'=>'#FFF1E3','c'=>'#C2660F','svg'=>'<path d="M7 6h10M7 10h10M7 6a5 5 0 0 1 0 10M7 10h6"/>','t'=>'Transparent Pricing','s'=>'No hidden charges'],
          ['bg'=>'#FDEAF0','c'=>'#E23F6B','svg'=>'<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.4"/>','t'=>'Pan India Support','s'=>'Service across all RTOs in India'],
        ];
        foreach ($why4 as $w4):
      ?>
      <div class="why-card2">
        <div class="why-ico2" style="--why-bg:<?php echo esc_attr($w4['bg']);?>;--why-c:<?php echo esc_attr($w4['c']);?>"><svg viewBox="0 0 24 24"><?php echo $w4['svg'];?></svg></div>
        <div class="why-tt2"><?php echo esc_html($w4['t']);?></div>
        <div class="why-ds2"><?php echo esc_html($w4['s']);?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <!--
      No real explainer video exists in this codebase, so this is an honest
      illustrative placeholder (CSS gradient + play icon), not a claim of a
      real video — clicking it goes to the services page rather than
      pretending to play something that doesn't exist.
    -->
    <a href="<?php echo esc_url(home_url('/rto-apply/'));?>" class="hp-video-card">
      <h3>Drive Towards a<br>Hassle-Free Tomorrow</h3>
      <span class="hp-video-play"><button type="button" aria-label="Explore our services" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M6 4l14 8-14 8V4z"/></svg></button></span>
      <span class="hp-video-cap">See How It Works</span>
    </a>
  </div>
</div>
</section>

<?php
// TESTIMONIALS — real data only, never fabricated.
?>
<?php if (!empty($testimonialItems)): ?>
<section class="sec" id="testimonials">
<div class="w">
  <div class="hp-sec-hd hp-reveal">
    <div><div class="hp-ey">Real Customer Reviews</div><h2 class="hp-tt">What Our Customers Say</h2><p class="hp-st">Trusted by real customers who completed their RTO work with us.</p></div>
    <?php if (count($testimonialItems) > 6): ?><a href="<?php echo esc_url(home_url('/about'));?>" class="btn btn-o">View All Reviews &rarr;</a><?php endif; ?>
  </div>
  <div class="testi-grid hp-reveal">
  <?php foreach (array_slice($testimonialItems, 0, 6) as $t):
    $metaParts = array_filter([$t['service'] ?: null, $t['city'] ?: null]);
    $initial = mb_strtoupper(mb_substr($t['name'], 0, 1));
  ?>
    <article class="testi-card">
      <div class="testi-who">
        <div class="testi-avatar" aria-hidden="true"><?php echo esc_html($initial); ?></div>
        <div>
          <div class="testi-name"><?php echo esc_html($t['name']); ?></div>
          <?php if ($metaParts): ?><div class="testi-meta"><?php echo esc_html(implode(' &middot; ', $metaParts)); ?></div><?php endif; ?>
        </div>
      </div>
      <div class="testi-stars" aria-label="<?= esc_attr($t['score']) ?> out of 5 stars"><?= str_repeat('&#9733;', $t['score']) . str_repeat('&#9734;', 5 - $t['score']) ?></div>
      <p class="testi-quote">&ldquo;<?php echo esc_html($t['comment']); ?>&rdquo;</p>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($ratingAvg !== null && $ratingCount >= 5): ?>
  <p style="text-align:center;font-size:13px;color:#64748b;margin-top:24px" class="hp-reveal">
    Average <?php echo number_format($ratingAvg,1);?>/5 across <?php echo number_format($ratingCount);?> real customer ratings.
  </p>
  <?php endif; ?>
</div>
</section>
<?php elseif ($ratingAvg !== null && $ratingCount >= 5): ?>
<section class="sec">
<div class="w">
  <div class="hp-rating-card hp-reveal">
    <div class="hp-ey" style="justify-content:center">Customer Ratings</div>
    <div class="hp-rating-big"><?php echo number_format($ratingAvg,1);?><span style="font-size:22px;color:#94a3b8">/5</span></div>
    <div class="hp-rating-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
    <p class="hp-rating-note">Based on <?php echo number_format($ratingCount);?> real customer ratings collected after service completion.</p>
  </div>
</div>
</section>
<?php endif; ?>

<?php if ($waLink): ?>
<section class="sec sec-alt">
<div class="w">
  <div class="hp-app-band hp-reveal">
    <div>
      <div class="hp-ey">Stay Connected</div>
      <h2 class="hp-tt">RTO Support, Right In Your Pocket</h2>
      <p class="hp-st">Chat with our team directly on WhatsApp &mdash; no app to download.</p>
      <div class="hp-app-check">
        <div><i>&#10003;</i>Book a Service</div>
        <div><i>&#10003;</i>Track Applications</div>
        <div><i>&#10003;</i>Get Status Updates</div>
        <div><i>&#10003;</i>Chat With Experts</div>
      </div>
      <a href="<?php echo esc_url($waLink);?>" class="btn btn-p btn-lg" target="_blank" rel="noopener">Chat on WhatsApp &rarr;</a>
    </div>
    <div class="hp-phone-mini" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg></div>
    <div class="hp-qr-block">
      <div class="hp-qr-box" aria-hidden="true">
        <svg viewBox="0 0 100 100">
          <rect x="4" y="4" width="26" height="26" fill="none" stroke="<?php echo esc_attr($cp);?>" stroke-width="6"/>
          <rect x="70" y="4" width="26" height="26" fill="none" stroke="<?php echo esc_attr($cp);?>" stroke-width="6"/>
          <rect x="4" y="70" width="26" height="26" fill="none" stroke="<?php echo esc_attr($cp);?>" stroke-width="6"/>
          <rect x="14" y="14" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="80" y="14" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="14" y="80" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="44" y="10" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/><rect x="56" y="20" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="44" y="44" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/><rect x="56" y="56" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="70" y="44" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/><rect x="82" y="70" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
          <rect x="44" y="82" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/><rect x="60" y="80" width="6" height="6" fill="<?php echo esc_attr($cp);?>"/>
        </svg>
      </div>
      <div class="hp-qr-cap">Scan to Chat</div>
    </div>
  </div>
</div>
</section>
<?php endif; ?>

<section class="hp-accent-cta hp-reveal">
  <div class="hp-accent-in w">
    <div>
      <h2>Get Your RTO Work Done with Confidence</h2>
      <p>Expert assistance · Transparent pricing · Real-time tracking · Pan-India support</p>
    </div>
    <div class="hp-accent-actions">
      <a href="<?php echo esc_url(home_url('/rto-apply/')); ?>" class="btn btn-w btn-lg">Start Application &rarr;</a>
      <?php if ($waLink): ?>
      <a href="<?php echo esc_url($waLink); ?>" class="btn btn-g btn-lg" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp Us</a>
      <?php elseif ($tel): ?>
      <a href="tel:<?php echo esc_attr($tel); ?>" class="btn btn-g btn-lg"><i class="fa-solid fa-phone"></i> Call Us</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="sec" id="faq">
<div class="w">
  <div class="hp-head hp-reveal"><div class="hp-ey" style="justify-content:center">FAQ</div>
    <h2 class="hp-tt">Frequently Asked Questions</h2>
  </div>
  <div class="faq-wrap hp-reveal">
  <?php foreach([
    ['What documents do I need for RC Transfer?','You need the original RC Book, Form 29 and 30 (we provide them), valid insurance, PUC certificate, and Aadhaar of buyer and seller. If there is an active loan, a bank NOC is also required.'],
    ['How long does RC Transfer take?','Within the same state, RC Transfer takes 15-30 working days. Inter-state transfers take 30-45 days due to the additional NOC step.'],
    ['Is my document safe with you?','Where we physically collect your documents, our agent handles them with care and confirms receipt with you directly. If you have any concern about a specific pickup, contact our helpline right away so we can follow up.'],
    ['Do I need to visit the RTO even once?','In most cases, no. Occasionally the RTO requires the vehicle owner to be physically present. We notify you in advance if that applies to your case.'],
    ['How is payment handled?','We collect the service fee after you confirm your application. Government fees are passed at actual cost with receipts. No advance payment is required to apply.'],
    ['Can I track my application status?','Yes. After submission you receive a unique application number and can track status 24/7 at /rto-track/ with SMS and WhatsApp updates at every milestone.'],
    ['What if there is a problem?','Our team monitors every application. If there is any query from the RTO, we contact you immediately.'],
  ] as $i=>[$q,$a]):?>
  <div class="faq-it" id="fi<?php echo $i;?>">
    <button class="faq-q" data-faq-index="<?php echo $i;?>" aria-expanded="false" aria-controls="fa<?php echo $i;?>"><span class="faq-idx"><?php echo str_pad((string)($i+1),2,'0',STR_PAD_LEFT);?></span><span class="faq-q-txt"><?php echo esc_html($q);?></span><span class="faq-plus">+</span></button>
    <div class="faq-a-wrap"><div class="faq-a-in"><div class="faq-a" id="fa<?php echo $i;?>"><?php echo esc_html($a);?></div></div></div>
  </div>
  <?php endforeach;?>
  </div>
</div>
</section>

<footer>
<div class="w ft-top">
  <div>
    <div class="ft-logo"><span class="ft-logo-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg></span><?php echo esc_html($co);?></div>
    <p class="ft-desc">Your trusted partner for all RTO related services. Simplifying the process, so you can get on the road faster.</p>
    <div class="ft-social">
      <a href="#" aria-label="Facebook"><svg viewBox="0 0 24 24"><path d="M14 9h3V6h-3a4 4 0 0 0-4 4v2H8v3h2v6h3v-6h3l1-3h-4v-2a1 1 0 0 1 1-1z"/></svg></a>
      <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg></a>
      <a href="#" aria-label="YouTube"><svg viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="3"/><path d="M10 9l6 3-6 3z" fill="currentColor" stroke="none"/></svg></a>
      <a href="#" aria-label="LinkedIn"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7v.01M12 17v-4a2 2 0 0 1 4 0v4M12 13v4"/></svg></a>
    </div>
  </div>
  <div>
    <div class="ft-title">Quick Links</div>
    <div class="ft-links">
      <a href="<?php echo esc_url(home_url('/'));?>">Home</a>
      <a href="<?php echo esc_url(home_url('/rto-apply/'));?>">All Services</a>
      <a href="<?php echo esc_url(home_url('/rto-track/'));?>">Check Status</a>
      <a href="<?php echo esc_url(home_url('/rto-services-cities'));?>">RTO Information</a>
      <a href="<?php echo esc_url(home_url('/about'));?>">About Us</a>
    </div>
  </div>
  <div>
    <div class="ft-title">Popular Services</div>
    <div class="ft-links">
      <?php foreach(array_slice($cat_config, 0, 5) as $cn=>$ci):?>
      <a href="<?php echo esc_url(home_url('/rto-apply/'));?>"><?php echo esc_html($ci['label']);?></a>
      <?php endforeach;?>
    </div>
  </div>
  <div>
    <div class="ft-title">Contact Us</div>
    <div class="ft-contact">
      <?php if($tel):?><div><svg viewBox="0 0 24 24"><path d="M4 5c0 8.3 6.7 15 15 15l3-4-6-3-2 2c-2.5-1.2-4.5-3.2-5.7-5.7l2-2-3-6z"/></svg><a href="tel:<?php echo esc_attr($tel);?>" style="color:inherit"><?php echo esc_html($tel);?></a></div><?php endif;?>
      <div><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>support@<?php echo esc_html(strtolower(preg_replace('/[^a-z0-9]/i','', $co) ?: 'rtoflow'));?>.in</div>
      <div><svg viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.4"/></svg>Pan India Support</div>
      <div><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>Mon &ndash; Sat, 9AM &ndash; 7PM</div>
    </div>
  </div>
</div>
<div class="w ft-bot">
  <span>&copy; <?php echo date('Y');?> <?php echo esc_html($co);?>. All rights reserved.</span>
  <div class="ft-bl">
    <a href="<?php echo esc_url(home_url('/privacy-policy'));?>">Privacy Policy</a>
    <a href="<?php echo esc_url(home_url('/terms-conditions'));?>">Terms &amp; Conditions</a>
  </div>
</div>
</footer>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
function hpMN(){var n=document.getElementById('hpNav'),b=document.getElementById('hpMB'),o=n.classList.toggle('hp-open');b.setAttribute('aria-expanded',o?'true':'false');}
document.getElementById('hpMB').addEventListener('click',hpMN);
var hpQuickGo=document.getElementById('hpQuickGo');
var hpQuickSvc=document.getElementById('hpQuickService');
if(hpQuickGo&&hpQuickSvc){
  var applyBase='<?php echo esc_js(home_url('/rto-apply/')); ?>';
  hpQuickGo.addEventListener('click',function(e){
    var slug=hpQuickSvc.value;
    if(slug){e.preventDefault();window.location.href=applyBase+(applyBase.indexOf('?')>-1?'&':'?')+'service='+encodeURIComponent(slug);}
  });
}
var hpCitySearch=document.getElementById('hpCitySearch');
if(hpCitySearch){
  hpCitySearch.addEventListener('input',function(){
    var q=(hpCitySearch.value||'').toLowerCase().trim();
    document.querySelectorAll('#hpCityTags .hp-city-tag').forEach(function(el){
      el.style.display=(!q||((el.dataset.name||'').indexOf(q)!==-1))?'':'none';
    });
  });
}
document.addEventListener('click',function(e){var n=document.getElementById('hpNav'),b=document.getElementById('hpMB');if(n&&b&&!n.contains(e.target)&&!b.contains(e.target)){n.classList.remove('hp-open');b.setAttribute('aria-expanded','false');}});
function faqT(i){var it=document.getElementById('fi'+i),btn=it.querySelector('.faq-q'),open=it.classList.contains('faq-open');document.querySelectorAll('.faq-it').forEach(function(el){el.classList.remove('faq-open');el.querySelector('.faq-q').setAttribute('aria-expanded','false');});if(!open){it.classList.add('faq-open');btn.setAttribute('aria-expanded','true');}}
document.getElementById('faq').addEventListener('click',function(e){
  var btn=e.target.closest('.faq-q');
  if(!btn) return;
  faqT(parseInt(btn.dataset.faqIndex,10));
});

/* Scroll-reveal — small vanilla JS, respects reduced motion */
(function(){
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var reveals = document.querySelectorAll('.hp-reveal');
  if (reduce || !('IntersectionObserver' in window)) {
    reveals.forEach(function(el){ el.classList.add('hp-in'); });
  } else {
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if (e.isIntersecting) { e.target.classList.add('hp-in'); io.unobserve(e.target); }
      });
    }, {threshold:.12, rootMargin:'0px 0px -40px 0px'});
    reveals.forEach(function(el){ io.observe(el); });
  }
})();
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/hero-slider.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/hero-slider.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
