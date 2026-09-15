<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Centralized Design & Typography Settings — single source of truth for
 * both the admin UI (DesignSettingsController) and the public stylesheet
 * this class generates (served by Router::designCss()).
 *
 * USER REQUEST: "Create a centralized, fully functional Design & Typography
 * Settings system that allows me to control the visual style of the Home
 * Page as well as all other pages from the admin panel, without modifying
 * code." Phase 1 (this delivery, per the phased plan the user chose):
 * Typography, Colors, Buttons, Spacing — global, applied to the Home page
 * first. Cards/Sections/Images/Effects and Header/Footer + per-page
 * overrides are later phases.
 *
 * STORAGE SPLIT (deliberate, not arbitrary):
 *   - Colors: kept as the SAME flat wp_options this codebase already uses
 *     (rtoflow_color_primary/secondary/accent — read directly by home.php
 *     and CustomizerHubController today) plus new flat options for the
 *     slots that didn't exist yet (heading/body/link/button text/
 *     background/border/muted). This is a single source of truth — the
 *     new admin screen edits the EXACT SAME options the rest of the
 *     plugin already reads, instead of creating a second, competing copy
 *     that could drift out of sync.
 *   - Typography / Spacing / Buttons: no prior options existed for these,
 *     so they live together in one JSON option, 'rtoflow_design_settings'
 *     (same pattern as 'rtoflow_hero_settings').
 *
 * HOW THIS ACTUALLY REACHES THE FRONTEND (the "real working settings, not
 * just UI controls" requirement): buildCss() emits a real stylesheet that
 * targets the SAME selectors home.php's own markup already uses (h1/h2/h3,
 * .hp-tt, .hp-st, .hp-ey, .btn/.btn-p/.btn-o, .hp-nav a, .sec, .w) — not a
 * parallel set of unused classes. Router::designCss() serves it as
 * /rto-design.css, loaded as the LAST stylesheet in home.php's <head>, so
 * at equal selector specificity it wins the cascade over home.php's own
 * inline <style> by source order — the exact mechanism already proven
 * (and the exact mistake already fixed once — see the hero-slider.css
 * cascade bug) elsewhere in this delivery. Selectors already protected by
 * a MORE specific local rule in home.php (e.g. ".hp-video-card h3{color:
 * #fff}", specificity 0,0,1,1) correctly keep winning over this
 * stylesheet's bare "h3{...}" (0,0,0,1) regardless of load order — by
 * design, so a global H3 color change cannot break that one deliberately
 * white-on-dark card.
 *
 * MAPPING NOTE (documented here because it is genuinely part of the
 * contract, not an implementation detail): this specific theme's real
 * "section heading" convention is <h2 class="hp-tt">, not a bare <h2> —
 * .hp-tt already carries its own font-size/weight/color in home.php's
 * inline <style>, and a class selector always beats a bare element
 * selector regardless of load order. So the "H2" typography control
 * targets BOTH `h2` and `.hp-tt` with an identical ruleset — anything
 * else would mean "H2" visibly does nothing on this page, which would
 * fail the user's explicit "must actually affect the corresponding
 * frontend element" requirement. Likewise "Labels" targets `.hp-ey` (the
 * page's actual small-caps eyebrow labels) and `label`; "Body text"
 * targets `body`, `p`, and `.hp-st` (the page's actual subtitle/body
 * class); "Navigation" targets `.hp-nav a`.
 *
 * PHASE 2 (Cards & Components, Sections, Effects — this delivery):
 *   - Cards: --r-md is the shared radius variable already used by
 *     .svc-flat/.why-card2/.hp-video-card/.testi-card, so one radius
 *     control genuinely reshapes all four. Border/background/shadow are
 *     scoped to .why-card2 and .testi-card only — .svc-flat and
 *     .hp-video-card have deliberate tinted/gradient backgrounds and
 *     .faq-it has a deliberate colored left-accent border tied to its
 *     open/closed accordion state; a blanket override would visibly break
 *     those, which the user's own "must not break existing design"
 *     requirement rules out.
 *   - "Card Spacing" (a Phase 1 Spacing-tab field) is wired for real here
 *     to .testi-grid's gap. Phase 1 shipped it writing only to an unused
 *     CSS variable no selector ever read, so it looked like a working
 *     control but changed nothing — a real bug, now fixed. It targets
 *     .testi-grid specifically (not .svc-grid/.why-grid2, whose native
 *     gaps are 18px/16px, not 20px) because that is the one grid whose
 *     current gap exactly equals the setting's default, so shipping the
 *     fix changes nothing on its own.
 *   - Images: deliberately NOT added as a control surface in Phase 2. The
 *     only <img> elements the Home page actually renders belong to the
 *     Hero Slider, which already has its own dedicated, more specific
 *     per-slide fit/position controls (HeroSliderController). A second,
 *     more-generic "Images" control here would either do nothing (if it
 *     targeted a selector nothing renders) or fight the Hero Slider's own
 *     settings (if it targeted the same images) — both outcomes are
 *     explicitly ruled out by the user's requirements. This will become a
 *     real, meaningful tab once the design engine reaches a page with
 *     independent content photography (Phase 3 rollout).
 *   - Entrance animations were considered for Effects and deliberately
 *     left out: a real implementation needs new scroll-triggered JS
 *     (IntersectionObserver) with its own tested fallback, and an
 *     "opacity:0 until JS runs" pattern risks permanently hiding content
 *     if that JS ever fails to load — a regression risk the user's "must
 *     not break existing functionality" requirement rules out for a
 *     feature built in this pass. Card hover motion + transition speed
 *     are the Effects this page can genuinely support today without that
 *     risk.
 *
 * PHASE 3 (Header & Navigation, Footer — this delivery):
 *   - Header background/border reuse this feature's own new fields;
 *     header "height" ALSO rewrites the mobile dropdown's top offset
 *     (.hp-nav.hp-open{top}) so a changed height can never leave a gap
 *     or overlap under the open mobile menu — the two are the same
 *     physical measurement in the markup and must move together.
 *   - Footer background deliberately reuses the SAME
 *     rtoflow_color_bg_dark option the topbar already reads (added to
 *     COLOR_KEYS as 'bg_dark') rather than creating a second footer-only
 *     background field — this is the identical single-source-of-truth
 *     reasoning Phase 1 applied to primary/secondary/accent.
 *   - Footer text: only heading_color and link_color are exposed. The
 *     footer currently renders body-ish text (.ft-desc, .ft-contact div,
 *     .ft-links a) at three DIFFERENT opacities of white (.7/.65/.6) by
 *     design — collapsing them into one "footer text color" field would
 *     necessarily change at least two of those three the moment this
 *     shipped, which the user's "must not break existing design"
 *     requirement rules out. heading_color and link_color are each
 *     already a single uniform value today, so they are safe to expose.
 *   - Per-page overrides: NOT built in this delivery. The user's own
 *     chosen rollout order was "Home page first, then roll out" — with
 *     only the Home page wired to this engine so far, a "per-page
 *     override" UI would have nothing to override against and would be
 *     non-functional chrome, which fails the same "must be real, working
 *     settings" requirement this whole feature was built to satisfy.
 *     This belongs at the point the design engine actually reaches a
 *     second page.
 *
 * ROLLOUT (Colors/Header/Footer → the shared marketing-page layout, this
 * delivery): the user chose the narrower of two options here — extend the
 * Colors, Header and Footer tabs to resources/views/layouts/website-header.php
 * and website-footer.php (the shared chrome used by About/Contact/Pricing/
 * How-It-Works/Terms/Privacy/Login/All Cities/City Page/Service — the 10
 * pages that actually render through that shared layout), WITHOUT
 * rewriting those pages' own body content, which is almost entirely
 * hardcoded inline `style=""` attributes an external stylesheet can never
 * override.
 *
 * IMPORTANT — this rollout is served by its OWN, SEPARATE stylesheet,
 * buildSiteCss() / /rto-design-site.css, NOT the same /rto-design.css
 * buildCss() generates for the Home page, and it is deliberately a much
 * smaller subset. Reusing the Home stylesheet outright was tried first and
 * rejected mid-build: buildCss() emits a real `body{background:...}` rule
 * (Colors tab default #ffffff) that would have overridden
 * resources/assets/css/public.css's existing `body{background:#f8fafc}`
 * on all 10 pages — a real, previously-unverified visual regression this
 * class's own "must not break existing design" standard rules out. The
 * same is true of buildCss()'s bare h1–h6/body/label/nav typography rules,
 * which were only ever audited against Home's markup, not these 10 pages'
 * (one bare, unstyled `<h3>` was found and fixed in resources/views/
 * public/pricing.php's empty state as part of this audit — see that
 * file). Rather than re-run that same audit against every rule in
 * buildCss() for 10 more pages, buildSiteCss() emits ONLY the specific,
 * individually-verified-safe rules below — everything else in buildCss()
 * (typography, spacing, cards, sections, effects) stays Home-page-only
 * until a future pass actually verifies it against these other pages too.
 * What genuinely reaches those 10 pages, and why:
 *   - Colors: overriding `:root{--primary:...}` in this stylesheet reaches
 *     every one of those pages for real — resources/assets/css/website.css
 *     already reads `var(--primary)` pervasively (headings, nav, hover
 *     states, prices, icons). Its default (#1B2A6B) already equals the
 *     Colors tab's Primary default, so this changes nothing on its own.
 *     --primary-light and --accent are NOT wired: website.css hardcodes
 *     its own :root values for them (#2563EB / #7C3AED) that don't match
 *     any existing Colors-tab default, and no field here has ever claimed
 *     to control them — wiring them would mean either a silent color
 *     shift the moment this shipped, or inventing new fields the user
 *     never asked for. Left alone, honestly, rather than faked.
 *   - Header: background, sticky, and the top info-bar visibility toggle
 *     all reach `.rto-site-header` / `.rto-topbar-strip` too — their
 *     current values already equal this tab's existing defaults. Header
 *     Height and Border Color do NOT reach the shared layout: its header
 *     height is a different measurement (60px, via --nav-h) than Home's
 *     (70px) and it has no border to color (a box-shadow only) — applying
 *     either would silently change or add something that isn't there
 *     today. They stay Home-page-only, honestly, rather than approximated.
 *   - Footer: heading_color and link_color reach `.rto-footer__col
 *     h3/h4` and `.rto-footer__col a` too (their current values already
 *     match). Background needed a NEW field (site_bg) because the shared
 *     footer's current background (#0F172A) is a different value than
 *     Home's own footer background — see the 'footer' default array
 *     comment. Padding does NOT reach the shared footer: its current top
 *     padding (56px) differs from Home's (60px), so reusing the same
 *     field would shift it at ship time.
 */
class DesignSettingsService
{
    public const OPTION_DESIGN = 'rtoflow_design_settings';

    /** Flat color option keys this screen reads/writes — see class docblock. */
    public const COLOR_KEYS = [
        'primary'     => 'rtoflow_color_primary',    // pre-existing, reused as-is
        'secondary'   => 'rtoflow_color_secondary',  // pre-existing, reused as-is
        'accent'      => 'rtoflow_color_accent',     // pre-existing, reused as-is
        'heading'     => 'rtoflow_color_heading',    // new
        'body'        => 'rtoflow_color_body',       // new
        'link'        => 'rtoflow_color_link',       // new
        'button_bg'   => 'rtoflow_color_button_bg',  // new
        'button_text' => 'rtoflow_color_button_text',// new
        'background'  => 'rtoflow_color_background', // new
        'border'      => 'rtoflow_color_border',     // new
        'muted'       => 'rtoflow_color_muted',      // new
        'bg_dark'     => 'rtoflow_color_bg_dark',    // pre-existing, reused as-is — the topbar AND footer's shared dark background (see Phase 3 note)
    ];

    public const FONT_STACKS = [
        'system'    => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
        'georgia'   => "Georgia,'Times New Roman',serif",
        'arial'     => "Arial,Helvetica,sans-serif",
        'verdana'   => "Verdana,Geneva,sans-serif",
        'trebuchet' => "'Trebuchet MS',sans-serif",
        'courier'   => "'Courier New',Courier,monospace",
    ];
    // Deliberately web-safe/OS-shipped stacks only — no Google Fonts or any
    // other network font load. A named font this plugin never loads would
    // silently fall back to the default and LOOK like the setting did
    // nothing, which fails the "must actually affect the frontend element"
    // requirement just as surely as a wiring bug would.

    public const TRANSFORM_VALUES = ['none', 'uppercase', 'lowercase', 'capitalize'];
    public const WEIGHT_VALUES    = [300, 400, 500, 600, 700, 800, 900];
    public const SHADOW_VALUES    = ['none', 'sm', 'md', 'lg'];
    public const HOVER_VALUES     = ['none', 'lift', 'glow'];

    /** Phase 2 — Cards & Components: 3-step shadow scale (resting/hover/deep), 'subtle' = today's exact values. */
    public const CARD_SHADOW_VALUES = ['flat', 'subtle', 'bold'];
    public const CARD_SHADOW_PRESETS = [
        'flat'   => ['0 0 0 rgba(0,0,0,0)',                                             '0 0 0 rgba(0,0,0,0)',                                              '0 0 0 rgba(0,0,0,0)'],
        'subtle' => ['0 1px 2px rgba(16,24,53,.05),0 1px 1px rgba(16,24,53,.04)',        '0 10px 26px -10px rgba(16,24,53,.16),0 2px 8px -2px rgba(16,24,53,.08)', '0 26px 56px -18px rgba(16,24,53,.32),0 8px 20px -8px rgba(16,24,53,.16)'],
        'bold'   => ['0 2px 6px rgba(16,24,53,.10),0 1px 2px rgba(16,24,53,.08)',        '0 16px 36px -12px rgba(16,24,53,.28),0 4px 12px -2px rgba(16,24,53,.14)', '0 34px 70px -16px rgba(16,24,53,.42),0 12px 26px -8px rgba(16,24,53,.22)'],
    ];
    /** Phase 2 — Sections: header alignment. */
    public const SECTION_ALIGN_VALUES = ['left', 'center'];

    /** Phase 3 — Header/Footer: on-off toggles. */
    public const TOGGLE_VALUES = ['on', 'off'];

    public static function defaultColors(): array
    {
        return [
            'primary'     => '#1B2A6B',
            'secondary'   => '#E97B28',
            'accent'      => '#16A34A',
            'heading'     => '#1B2A6B',
            'body'        => '#1e293b',
            'link'        => '#1B2A6B',
            'button_bg'   => '#E97B28',
            'button_text' => '#ffffff',
            'background'  => '#ffffff',
            'border'      => '#dbe1ee',
            'muted'       => '#64748b',
            'bg_dark'     => '#0A1628',
        ];
    }

    /** Current colors: existing plugin options where they already exist, new ones with their own defaults. */
    public static function loadColors(): array
    {
        $defaults = self::defaultColors();
        $out = [];
        foreach (self::COLOR_KEYS as $key => $optionName) {
            $out[$key] = get_option($optionName, $defaults[$key]);
        }
        return $out;
    }

    private static function defaultElement(float $desktop, float $tablet, float $mobile, int $weight, float $lineHeight, float $letterSpacing, string $transform, string $colorSlot): array
    {
        return [
            'size_desktop' => $desktop, 'size_tablet' => $tablet, 'size_mobile' => $mobile,
            'weight' => $weight, 'line_height' => $lineHeight, 'letter_spacing' => $letterSpacing,
            'transform' => $transform, 'color_slot' => $colorSlot,
        ];
    }

    public static function defaultSettings(): array
    {
        return [
            'typography' => [
                'body_font_family'    => 'system',
                'heading_font_family' => 'system',
                // DESIGN PASS (bigger/bolder redesign, NAS v7 as the quality
                // reference): these defaults used to be set to exactly mirror
                // home.php's then-current hardcoded inline values (see the
                // "matches X today" comments this replaces) — that zero-
                // delta guarantee is what made the earlier polish-only pass
                // invisible: buildCss()'s h2/.hp-tt and .hp-ey/label rules
                // load LAST in <head> (see class docblock) and win the
                // cascade on font-size/weight/line-height/letter-spacing/
                // transform at equal specificity, so home.php's own inline
                // <style> values for those exact properties can never be the
                // ones that actually render. The fix is here, not in
                // home.php: the DEFAULTS below are now the real bigger/
                // bolder scale, so an admin who has never touched this panel
                // still gets the redesigned type scale, and anyone who HAS
                // customized these fields keeps their own choice untouched
                // (defaults only apply to sites that never saved an override).
                'elements' => [
                    'h1'      => self::defaultElement(46, 36, 30, 900, 1.15, -0.02, 'none', 'heading'),
                    'h2'      => self::defaultElement(40, 32, 27, 900, 1.15, -0.02, 'none', 'heading'), // was 32/28/24 — bigger, bolder section headings
                    'h3'      => self::defaultElement(25, 22, 19, 800, 1.3, 0,     'none', 'heading'),
                    'heading' => self::defaultElement(18, 17, 16, 700, 1.4, 0,     'none', 'heading'), // h4-h6 fallback
                    'body'    => self::defaultElement(15.5, 15, 14, 400, 1.7, 0,   'none', 'body'),    // slightly larger + more line-height for a roomier, premium read
                    'label'   => self::defaultElement(13, 13, 12, 800, 1.4, 0.12,  'uppercase', 'secondary'), // was 12/12/11 — the pill eyebrow reads better a touch larger
                    'button'  => self::defaultElement(13, 13, 12.5, 700, 1.0, 0,   'none', 'button_text'),
                    'nav'     => self::defaultElement(13.5, 13, 13, 600, 1.4, 0,   'none', 'muted'),
                ],
            ],
            'spacing' => [
                // DESIGN PASS: roomier section rhythm (was 88/64/52) — more
                // breathing room around each section is one of the clearest,
                // most-immediately-visible signals of a "premium" page (the
                // NAS v7 reference uses a comparably generous clamp(64px,
                // 8vw,96px) on its own sections). buildCss() writes
                // padding-top/padding-bottom for .sec from these values and
                // wins the cascade the same way typography above does, so
                // this is the value that actually has to change.
                'section_padding_desktop' => 108, 'section_padding_tablet' => 76, 'section_padding_mobile' => 56,
                'section_gap'             => 0,   // extra margin-bottom stacked on top of section padding, 0 = current look
                'card_spacing'            => 20,  // matches .testi-grid{gap:20px} today — see buildCss() MAPPING NOTE
                'container_width'         => 1220, // matches .w{max-width:1220px} today
                'content_width'           => 720,  // reserved for text-block max-width (FAQ, about-style copy) in later phases
            ],
            'buttons' => [
                'radius'           => 999,   // matches var(--r-pill) today (a full pill)
                'padding_y'        => 9,
                'padding_x'        => 20,
                'font_weight'      => 700,
                'border_width'     => 0,
                'border_color'     => '#dbe1ee',
                'shadow'           => 'md',  // matches .btn-p's existing box-shadow today
                'hover_effect'     => 'lift', // matches every .btn:hover today (translateY)
                'transition_speed' => 180,   // ms, matches today's .18s
            ],
            // Phase 2 — Cards & Components. Targets .why-card2/.testi-card
            // (the two "plain" content cards) plus the shared --r-md radius
            // var also used by .svc-flat and .hp-video-card. See buildCss()
            // MAPPING NOTE for exactly why .faq-it and .svc-flat/.hp-video-
            // card backgrounds are deliberately excluded from border/
            // background control (they have design-specific accents that a
            // blanket override would visibly break).
            'cards' => [
                // DESIGN PASS: was 16/'subtle' — a larger radius plus the
                // already-coded 'bold' CARD_SHADOW_PRESETS entry (deeper,
                // more diffused shadow at rest AND on hover) is what gives
                // .svc-flat/.why-card2/.hp-video-card/.testi-card real
                // depth instead of the previous barely-there 1-2px shadow.
                // No new preset invented — 'bold' already existed and was
                // simply never selected as the default.
                'radius'       => 20,      // was 16 — matches var(--r-md), reshapes all 4 card types that share it
                'shadow'       => 'bold',  // was 'subtle' — see CARD_SHADOW_PRESETS
                'border_width' => 1,       // matches .why-card2/.testi-card today
                'border_color' => '#eef1f6', // matches today
                'background'   => '#ffffff', // matches today
            ],
            // Phase 2 — Sections. min_height=0 and alt_pattern=on and
            // header_align=left all match today's rendered output exactly,
            // so saving Phase 2 defaults changes nothing until an admin
            // actually edits a value.
            'sections' => [
                'min_height'   => 0,      // 0 = auto (today's behavior)
                'alt_pattern'  => 'on',   // the decorative blurred circles on .sec-alt
                'header_align' => 'left', // .hp-sec-hd today is left-aligned with space-between
            ],
            // Phase 2 — Effects. Card hover motion + transition speed —
            // the only genuinely-real, always-visible "effects" surface on
            // this page without adding new JS (see class docblock).
            'effects' => [
                'card_hover_effect'   => 'lift', // unchanged — lift reads as more premium/tactile than the flat 'glow' ring alternative
                'card_hover_lift'     => 8,       // was 5px — a more noticeable, confident hover response
                'card_transition_ms'  => 220,     // was 200ms — a hair slower so the bigger 8px lift doesn't feel snappy/cheap
            ],
            // Phase 3 — Header & Navigation. All defaults below match
            // home.php's current hardcoded values exactly, so shipping
            // Phase 3 changes nothing until an admin edits a value. Header
            // typography/link color already come from the Typography tab's
            // "Navigation" element and the Colors tab's primary/link colors
            // — not duplicated here, per the single-source-of-truth rule.
            'header' => [
                'background'     => '#ffffff', // matches .hp-hdr today
                'height'         => 70,        // px — matches .hp-hdr-in{height:70px} today; also drives the mobile dropdown's top offset so it never overlaps/gaps
                'sticky'         => 'on',      // matches position:sticky today
                'border_color'   => '#eef1f6', // matches .hp-hdr's border-bottom today
                'topbar_visible' => 'on',      // the "Mon-Sat 9AM-7PM..." bar above the header
            ],
            // Phase 3 — Footer. Background reuses the Colors tab's new
            // "bg_dark" slot (the SAME rtoflow_color_bg_dark option the
            // topbar already reads) rather than a second competing field —
            // see class docblock and COLOR_KEYS. heading_color/link_color
            // and padding are the footer's own real, currently-uniform
            // values; see buildCss() MAPPING NOTE for why a single "body
            // text color" field was deliberately left out (the footer
            // today mixes 3 different text opacities on the same white,
            // and forcing them to one value would shift the design the
            // moment this ships).
            'footer' => [
                'heading_color' => '#ffffff', // matches .ft-title/.ft-logo b today
                'link_color'    => '#ffffff', // rendered at 60% opacity — matches .ft-links a's rgba(255,255,255,.6) today exactly
                'padding_top'   => 60,        // matches .ft-top's "60px 20px 40px" today
                'padding_bottom'=> 40,
                'site_bg'       => '#0F172A', // Rollout — the OTHER pages' shared .rto-footer background (About/Contact/Pricing/etc). Deliberately its own field, not bg_dark: the shared layout's footer already renders a different dark shade (#0F172A) than Home's (#0A1628) today, and reusing bg_dark would shift that color the moment this ships.
            ],
        ];
    }

    public static function mergeSettings(array $defaults, array $stored): array
    {
        foreach (['typography', 'spacing', 'buttons', 'cards', 'sections', 'effects', 'header', 'footer'] as $bucket) {
            if (empty($stored[$bucket]) || !is_array($stored[$bucket])) continue;
            if ($bucket === 'typography') {
                if (isset($stored['typography']['body_font_family'])) $defaults['typography']['body_font_family'] = $stored['typography']['body_font_family'];
                if (isset($stored['typography']['heading_font_family'])) $defaults['typography']['heading_font_family'] = $stored['typography']['heading_font_family'];
                if (!empty($stored['typography']['elements']) && is_array($stored['typography']['elements'])) {
                    foreach ($defaults['typography']['elements'] as $el => $vals) {
                        if (!empty($stored['typography']['elements'][$el]) && is_array($stored['typography']['elements'][$el])) {
                            $defaults['typography']['elements'][$el] = array_merge($vals, $stored['typography']['elements'][$el]);
                        }
                    }
                }
            } else {
                $defaults[$bucket] = array_merge($defaults[$bucket], $stored[$bucket]);
            }
        }
        return $defaults;
    }

    public static function loadSettings(): array
    {
        $defaults = self::defaultSettings();
        $stored   = json_decode((string) get_option(self::OPTION_DESIGN, ''), true);
        return is_array($stored) ? self::mergeSettings($defaults, $stored) : $defaults;
    }

    public static function saveSettings(array $settings): bool
    {
        $old = json_decode((string) get_option(self::OPTION_DESIGN, ''), true);
        $encoded = wp_json_encode($settings, JSON_UNESCAPED_SLASHES);
        $saved = update_option(self::OPTION_DESIGN, $encoded, false);
        $reread = json_decode((string) get_option(self::OPTION_DESIGN, ''), true);
        return $saved || $reread === $settings; // ghost-success guard: update_option() also returns false on a legitimate no-op
    }

    /**
     * Generate the real, working stylesheet. Every property here maps to a
     * selector home.php's own markup already renders — see the class
     * docblock's "MAPPING NOTE" for exactly which selectors each control
     * targets and why.
     */
    public static function buildCss(array $settings, array $colors): string
    {
        $t  = $settings['typography'];
        $sp = $settings['spacing'];
        $b  = $settings['buttons'];
        $cd = $settings['cards'] ?? self::defaultSettings()['cards'];
        $se = $settings['sections'] ?? self::defaultSettings()['sections'];
        $ef = $settings['effects'] ?? self::defaultSettings()['effects'];
        $hd = $settings['header'] ?? self::defaultSettings()['header'];
        $ft = $settings['footer'] ?? self::defaultSettings()['footer'];

        $bodyFont = self::FONT_STACKS[$t['body_font_family']] ?? self::FONT_STACKS['system'];
        $headFont = self::FONT_STACKS[$t['heading_font_family']] ?? self::FONT_STACKS['system'];

        $colorFor = function (string $slot) use ($colors): string {
            return $colors[$slot] ?? $colors['body'];
        };

        $elRule = function (string $selector, array $el, string $font, array $colors) use ($colorFor): string {
            $color = $colorFor($el['color_slot']);
            return sprintf(
                "%s{font-family:%s;font-size:%spx;font-weight:%d;line-height:%s;letter-spacing:%sem;text-transform:%s;color:%s}\n",
                $selector, $font, self::num($el['size_desktop']), (int) $el['weight'],
                self::num($el['line_height']), self::num($el['letter_spacing']), $el['transform'], $color
            );
        };

        $css = "/* Generated by DesignSettingsService — Design & Typography Settings (Phase 1+2). Do not hand-edit; changes are overwritten on next save. */\n";
        // NOTE: --rto-content-width is intentionally unused by any selector
        // yet (reserved — see defaultSettings() comment). --rto-container-
        // width is exposed for reference but the REAL effect is the direct
        // ".w{max-width:...}" rule below (kept as the single source of
        // truth so this class doesn't rely on home.php also reading a var
        // it never declared).
        $css .= ":root{--rto-container-width:{$sp['container_width']}px;--rto-content-width:{$sp['content_width']}px}\n";

        $css .= "body,p,.hp-st{" . self::typographyDecl($t['elements']['body'], $bodyFont, $colorFor('body')) . "}\n";
        $css .= "h1{" . self::typographyDecl($t['elements']['h1'], $headFont, $colorFor($t['elements']['h1']['color_slot'])) . "}\n";
        // "H2" targets BOTH h2 and .hp-tt — see class docblock MAPPING NOTE.
        $css .= "h2,.hp-tt{" . self::typographyDecl($t['elements']['h2'], $headFont, $colorFor($t['elements']['h2']['color_slot'])) . "}\n";
        $css .= "h3{" . self::typographyDecl($t['elements']['h3'], $headFont, $colorFor($t['elements']['h3']['color_slot'])) . "}\n";
        $css .= "h4,h5,h6{" . self::typographyDecl($t['elements']['heading'], $headFont, $colorFor($t['elements']['heading']['color_slot'])) . "}\n";
        $css .= ".hp-ey,label{" . self::typographyDecl($t['elements']['label'], $bodyFont, $colorFor($t['elements']['label']['color_slot'])) . "}\n";
        $css .= ".hp-nav a{" . self::typographyDecl($t['elements']['nav'], $bodyFont, $colorFor($t['elements']['nav']['color_slot'])) . "}\n";
        $css .= ".btn{" . self::typographyDecl($t['elements']['button'], $bodyFont, null) . "}\n"; // button TEXT color comes from Buttons tab below, not typography

        // Colors that don't have a dedicated typography element but are real, always-visible surfaces.
        $css .= "body{background:{$colorFor('background')}}\n";
        $css .= "a{color:{$colorFor('link')}}\n";
        $css .= ".btn-o{border-color:{$colorFor('border')}}\n";

        // Spacing.
        $css .= ".sec{padding-top:{$sp['section_padding_desktop']}px;padding-bottom:{$sp['section_padding_desktop']}px}\n";
        $css .= ".w{max-width:{$sp['container_width']}px}\n";
        if ((float) $sp['section_gap'] > 0) {
            $css .= ".sec{margin-bottom:{$sp['section_gap']}px}\n";
        }
        // "Card Spacing" targets .testi-grid's gap — the one card grid on
        // this page whose CURRENT gap (20px) exactly equals this setting's
        // default, so shipping this fix changes nothing until an admin
        // edits it. .svc-grid (18px) and .why-grid2 (16px) intentionally
        // use their own slightly different native gaps and are excluded —
        // forcing them to this same value would visibly shift those two
        // grids the moment this ships, which fails the "must not break
        // existing design" requirement. (Phase 1 shipped this option
        // wired to an unused CSS variable that no selector ever read —
        // this is that fix, made in Phase 2.)
        $css .= ".testi-grid{gap:" . self::num($sp['card_spacing']) . "px}\n";

        // Cards & Components (Phase 2). --r-md is the shared radius var
        // used by .svc-flat, .why-card2, .hp-video-card and .testi-card
        // today, so overriding it here genuinely reshapes all four at once.
        // Border/background are scoped to .why-card2 and .testi-card only —
        // .svc-flat and .hp-video-card have deliberate tinted/gradient
        // backgrounds and .faq-it has a deliberate colored left-accent
        // border used by its open/closed state, and a blanket override
        // here would visibly break those. See class docblock.
        $css .= ":root{--r-md:" . self::num($cd['radius']) . "px}\n";
        $cardShadow = self::CARD_SHADOW_PRESETS[$cd['shadow']] ?? self::CARD_SHADOW_PRESETS['subtle'];
        $css .= ":root{--sh-1:{$cardShadow[0]};--sh-2:{$cardShadow[1]};--sh-3:{$cardShadow[2]}}\n";
        $css .= ".why-card2,.testi-card{border-width:" . self::num($cd['border_width']) . "px;border-style:solid;border-color:{$cd['border_color']};background:{$cd['background']}}\n";

        // Sections (Phase 2).
        if ((float) $se['min_height'] > 0) {
            $css .= ".sec{min-height:" . self::num($se['min_height']) . "px}\n";
        }
        if ($se['alt_pattern'] === 'off') {
            $css .= ".sec-alt::before,.sec-alt::after{display:none}\n";
        }
        if ($se['header_align'] === 'center') {
            $css .= ".hp-sec-hd{justify-content:center;text-align:center}\n";
        }

        // Effects (Phase 2) — card hover motion + transition speed. Button
        // hover/transition stay under the Buttons tab above (single source
        // of truth per element type, matching the class docblock's rule).
        $css .= ".why-card2,.testi-card,.svc-flat{transition-duration:" . (int) $ef['card_transition_ms'] . "ms}\n";
        if ($ef['card_hover_effect'] === 'lift') {
            $css .= ".why-card2:hover,.testi-card:hover,.svc-flat:hover{transform:translateY(-" . self::num($ef['card_hover_lift']) . "px)}\n";
        } elseif ($ef['card_hover_effect'] === 'glow') {
            $css .= ".why-card2:hover,.testi-card:hover,.svc-flat:hover{transform:none;box-shadow:0 0 0 4px " . self::withAlpha($colorFor('primary'), 0.18) . "}\n";
        } else {
            $css .= ".why-card2:hover,.testi-card:hover,.svc-flat:hover{transform:none}\n";
        }

        // Header & Navigation (Phase 3). Height also rewrites the mobile
        // dropdown's top offset so it never overlaps/gaps under the header
        // — see class docblock.
        $css .= ".hp-hdr{background:{$hd['background']};border-bottom-color:{$hd['border_color']}}\n";
        $css .= ".hp-hdr-in{height:" . self::num($hd['height']) . "px}\n";
        $css .= ".hp-hdr{position:" . ($hd['sticky'] === 'off' ? 'relative' : 'sticky') . "}\n";
        if ($hd['topbar_visible'] === 'off') {
            $css .= ".hp-topbar{display:none}\n";
        }
        $css .= "@media(max-width:900px){\n";
        $css .= ".hp-nav.hp-open{top:" . self::num($hd['height']) . "px;background:{$hd['background']}}\n";
        $css .= ".admin-bar .hp-nav.hp-open{top:calc(" . self::num($hd['height']) . "px + 32px)}\n";
        $css .= "}\n";

        // Footer (Phase 3). Background reuses the Colors tab's bg_dark
        // slot (see COLOR_KEYS) — the SAME option the topbar already
        // reads, not a second competing field.
        $css .= "footer{background:{$colorFor('bg_dark')}}\n";
        $css .= ".ft-title,.ft-logo b{color:{$ft['heading_color']}}\n";
        $css .= ".ft-logo-mark svg{stroke:{$ft['heading_color']}}\n";
        $css .= ".ft-links a{color:" . self::withAlpha($ft['link_color'], 0.6) . "}\n";
        $css .= ".ft-top{padding-top:" . self::num($ft['padding_top']) . "px;padding-bottom:" . self::num($ft['padding_bottom']) . "px}\n";

        // Buttons — shape, size, border, shadow, hover, transition. Button
        // TEXT color and background come from the Colors tab (button_text /
        // button_bg) so there is exactly one place that controls each,
        // matching the "single source of truth" rule the class docblock
        // opens with.
        $shadowMap = ['none' => 'none', 'sm' => '0 2px 8px rgba(0,0,0,.10)', 'md' => '0 4px 14px rgba(0,0,0,.18)', 'lg' => '0 10px 28px rgba(0,0,0,.24)'];
        $shadow = $shadowMap[$b['shadow']] ?? 'none';
        $css .= sprintf(
            ".btn{border-radius:%spx;padding:%spx %spx;font-weight:%d;border-width:%spx;border-style:solid;border-color:%s;transition:all %sms cubic-bezier(.2,.7,.3,1)}\n",
            self::num($b['radius']), self::num($b['padding_y']), self::num($b['padding_x']), (int) $b['font_weight'],
            self::num($b['border_width']), $b['border_color'], (int) $b['transition_speed']
        );
        $css .= ".btn-p,.btn-lg.btn-p{background:{$colorFor('button_bg')};color:{$colorFor('button_text')};box-shadow:{$shadow}}\n";
        $css .= ".btn-lg{border-radius:" . self::num($b['radius']) . "px}\n";
        if ($b['hover_effect'] === 'lift') {
            $css .= ".btn:hover{transform:translateY(-2px)}\n";
        } elseif ($b['hover_effect'] === 'glow') {
            $css .= ".btn-p:hover{box-shadow:0 0 0 4px " . self::withAlpha($colorFor('button_bg'), 0.25) . "}\n";
        } else {
            $css .= ".btn:hover{transform:none}\n";
        }

        // ── Responsive overrides (tablet ≤1024px, mobile ≤640px) — only
        // font-size + line-height vary per breakpoint; weight/letter-
        // spacing/transform/color stay constant across devices, which
        // covers the "Responsive Typography" requirement (separate desktop/
        // tablet/mobile control) without a combinatorial explosion of
        // per-breakpoint weight/color fields the admin would have to fill in.
        $css .= "@media(max-width:1024px){\n";
        $css .= self::responsiveBlock($t['elements'], 'size_tablet');
        $css .= ".sec{padding-top:{$sp['section_padding_tablet']}px;padding-bottom:{$sp['section_padding_tablet']}px}\n";
        $css .= "}\n";

        $css .= "@media(max-width:640px){\n";
        $css .= self::responsiveBlock($t['elements'], 'size_mobile');
        $css .= ".sec{padding-top:{$sp['section_padding_mobile']}px;padding-bottom:{$sp['section_padding_mobile']}px}\n";
        $css .= "}\n";

        return $css;
    }

    /**
     * Rollout stylesheet for the 10 marketing pages that render through
     * resources/views/layouts/website-header.php + website-footer.php
     * (About, Contact, Pricing, How-It-Works, Terms, Privacy, Login, All
     * Cities, City Page, Service). Served as /rto-design-site.css by
     * Router::designSiteCss(). See the class docblock's ROLLOUT note for
     * why this is a separate, deliberately smaller method rather than
     * reusing buildCss() — every rule below was individually checked
     * against resources/assets/css/website.css and resources/views/
     * layouts/website-header.php + website-footer.php for a selector
     * collision or a default-value mismatch before being included; rules
     * that didn't clear that check are documented as omitted, not silently
     * dropped.
     */
    public static function buildSiteCss(array $settings, array $colors): string
    {
        $hd = $settings['header'] ?? self::defaultSettings()['header'];
        $ft = $settings['footer'] ?? self::defaultSettings()['footer'];

        $colorFor = function (string $slot) use ($colors): string {
            return $colors[$slot] ?? $colors['body'];
        };

        $css = "/* Generated by DesignSettingsService::buildSiteCss() — Design & Typography Settings rollout (Colors/Header/Footer only) for the shared marketing-page layout. Do not hand-edit; changes are overwritten on next save. */\n";

        // Colors — website.css reads var(--primary) pervasively (headings,
        // nav-toggle icon, hover states, prices, city-link hover, FAQ
        // borders) across all 10 pages; its own :root default (#1B2A6B)
        // already equals this setting's default, so this is a real,
        // zero-delta-at-default override. --primary-light/--accent are
        // deliberately NOT touched — see class docblock ROLLOUT note.
        $css .= ":root{--primary:{$colorFor('primary')}}\n";

        // Header — background/sticky/topbar visibility only (see docblock
        // for why height/border are Home-only).
        $css .= ".rto-site-header{background:{$hd['background']}}\n";
        $css .= ".rto-site-header{position:" . ($hd['sticky'] === 'off' ? 'relative' : 'sticky') . "}\n";
        if ($hd['topbar_visible'] === 'off') {
            $css .= ".rto-topbar-strip{display:none}\n";
        }

        // Footer — its own background field (site_bg, not bg_dark — see
        // 'footer' default array comment), plus heading/link colors at
        // this layout's own current opacity (.65, not Home's .6).
        $css .= ".rto-footer{background:{$ft['site_bg']}}\n";
        $css .= ".rto-footer__col h3,.rto-footer__col h4{color:{$ft['heading_color']}}\n";
        $css .= ".rto-footer__col a{color:" . self::withAlpha($ft['link_color'], 0.65) . "}\n";

        return $css;
    }

    private static function typographyDecl(array $el, string $font, ?string $color): string
    {
        $decl = sprintf(
            "font-family:%s;font-size:%spx;font-weight:%d;line-height:%s;letter-spacing:%sem;text-transform:%s",
            $font, self::num($el['size_desktop']), (int) $el['weight'], self::num($el['line_height']), self::num($el['letter_spacing']), $el['transform']
        );
        if ($color !== null) $decl .= ";color:{$color}";
        return $decl;
    }

    private static function responsiveBlock(array $elements, string $sizeKey): string
    {
        $map = [
            'h1' => 'h1', 'h2' => 'h2,.hp-tt', 'h3' => 'h3', 'heading' => 'h4,h5,h6',
            'body' => 'body,p,.hp-st', 'label' => '.hp-ey,label', 'nav' => '.hp-nav a', 'button' => '.btn',
        ];
        $out = '';
        foreach ($map as $key => $selector) {
            $size = $elements[$key][$sizeKey] ?? $elements[$key]['size_desktop'];
            $out .= "{$selector}{font-size:" . self::num($size) . "px}\n";
        }
        return $out;
    }

    private static function num(float $n): string
    {
        // Trim trailing zeros (14.50 -> 14.5, 15.00 -> 15) so generated CSS stays clean.
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function withAlpha(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        if (strlen($hex) !== 6) return 'rgba(0,0,0,' . self::num($alpha) . ')';
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return "rgba({$r},{$g},{$b}," . self::num($alpha) . ")";
    }

    /** Cache-busting version string for /rto-design.css — changes whenever colors or design settings are saved. */
    public static function version(): string
    {
        $a = get_option('rtoflow_design_updated_at', '');
        if ($a !== '') return (string) $a;
        return (string) time();
    }

    public static function touchVersion(): void
    {
        update_option('rtoflow_design_updated_at', (string) time(), false);
    }
}
