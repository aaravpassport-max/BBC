<?php
/**
 * NAS Theme System — PHP side
 *
 * Mirrors the exact logic from marketplace-os/includes/class-mos-loader.php:
 *   build_theme_css(), lighten_hex(), hex_luminance(), theme_palette()
 *
 * Three-layer zero-flicker pattern:
 *  1. PHP build_theme_css() → :root{--color-primary:...} injected into <head>
 *     BEFORE any stylesheet → browser paints correct colours on first frame.
 *  2. PHP derivation of all surface shades from a single bg hex (lighten_hex).
 *  3. JS applyTheme() / applyPortalBg() mirrors the same logic at runtime for
 *     instant live switching without page reload.
 *
 * Admin-configurable keys (stored in nas_settings):
 *   pwa_theme        — named theme key  (default: 'green')
 *   pwa_portal_bg    — background hex   (default: '#0e1117')
 *   pwa_accent_color — primary hex      (overrides theme primary)
 *   pwa_button_color — button hex       (overrides theme primary for buttons)
 *
 * @package NAS\Modules\PWA
 */
namespace NAS\Modules\PWA;
if ( ! defined( 'ABSPATH' ) ) exit;

final class NASTheme {

    // ── Named theme palettes (matches JS NAS_THEMES object exactly) ───────────
    // Every change here must be mirrored in the JS NAS_THEMES constant in shell.php
    public static function palettes(): array {
        return [
            'green'  => ['name'=>'Emerald Green','primary'=>'#00d084','secondary'=>'#00b872','accent'=>'#33dda0','surface_950'=>'#0e1117','surface_900'=>'#141c26','surface_800'=>'#1a2535','surface_700'=>'#202e44','border'=>'#1e2e42','ink_primary'=>'#e8f4fc','ink_secondary'=>'#8ba8c8','ink_muted'=>'#4a6080'],
            'indigo' => ['name'=>'Indigo','primary'=>'#6366f1','secondary'=>'#4f46e5','accent'=>'#818cf8','surface_950'=>'#09090f','surface_900'=>'#111127','surface_800'=>'#1a1a38','surface_700'=>'#232348','border'=>'#2d2d60','ink_primary'=>'#eef0ff','ink_secondary'=>'#a5b4fc','ink_muted'=>'#6366b0'],
            'rose'   => ['name'=>'Rose','primary'=>'#f43f5e','secondary'=>'#e11d48','accent'=>'#fb7185','surface_950'=>'#0f080a','surface_900'=>'#1f1015','surface_800'=>'#2d1520','surface_700'=>'#3d1a2a','border'=>'#4d1f32','ink_primary'=>'#fff1f3','ink_secondary'=>'#fda4af','ink_muted'=>'#9f4459'],
            'amber'  => ['name'=>'Amber','primary'=>'#f59e0b','secondary'=>'#d97706','accent'=>'#fbbf24','surface_950'=>'#0f0a00','surface_900'=>'#1c1400','surface_800'=>'#2a1f00','surface_700'=>'#382900','border'=>'#4a3500','ink_primary'=>'#fffbeb','ink_secondary'=>'#fde68a','ink_muted'=>'#92713a'],
            'sky'    => ['name'=>'Sky Blue','primary'=>'#0ea5e9','secondary'=>'#0284c7','accent'=>'#38bdf8','surface_950'=>'#020d14','surface_900'=>'#071a26','surface_800'=>'#0d2537','surface_700'=>'#133047','border'=>'#1a3d58','ink_primary'=>'#f0f9ff','ink_secondary'=>'#7dd3fc','ink_muted'=>'#38657a'],
            'violet' => ['name'=>'Violet','primary'=>'#8b5cf6','secondary'=>'#7c3aed','accent'=>'#a78bfa','surface_950'=>'#09060f','surface_900'=>'#130e1f','surface_800'=>'#1d1530','surface_700'=>'#271b40','border'=>'#332250','ink_primary'=>'#f5f0ff','ink_secondary'=>'#c4b5fd','ink_muted'=>'#6b5a9e'],
            'custom' => ['name'=>'Custom','primary'=>'#00d084','secondary'=>'#00b872','accent'=>'#33dda0','surface_950'=>'#0e1117','surface_900'=>'#141c26','surface_800'=>'#1a2535','surface_700'=>'#202e44','border'=>'#1e2e42','ink_primary'=>'#e8f4fc','ink_secondary'=>'#8ba8c8','ink_muted'=>'#4a6080'],
        ];
    }

    /** Get a single palette by key (falls back to 'green') */
    public static function palette( string $key ): array {
        $ps = self::palettes();
        return $ps[$key] ?? $ps['green'];
    }

    /**
     * Build :root{} CSS block from theme key + background hex override.
     * Exact port of MOS_Loader::build_theme_css() from marketplace-os.
     *
     * @param string $theme     Named theme key (see palettes())
     * @param string $bg        Portal background hex (e.g. '#0e1117')
     * @param string $accent    Optional: override primary colour hex
     * @param string $btn_color Optional: override button colour hex
     * @return string           :root{--color-primary:...; --surface-950:...; ...}
     */
    public static function build_theme_css(
        string $theme    = 'green',
        string $bg       = '#0e1117',
        string $accent   = '',
        string $btn_color= ''
    ): string {
        $p  = self::palette($theme);
        $bg = $bg ?: $p['surface_950'];

        // Override theme primary with custom accent if set
        if ( $accent ) { $p['primary'] = $accent; }

        // Derive all surface shades from the chosen background
        // (lighten_hex: exact port of marketplace-os PHP function)
        $s900 = self::lighten_hex( $bg, 0.06 );
        $s800 = self::lighten_hex( $bg, 0.12 );
        $s700 = self::lighten_hex( $bg, 0.18 );
        $bdr  = self::lighten_hex( $bg, 0.14 );

        // Luminance check: if background is light, swap ink to dark tones
        $lum  = self::hex_luminance( $bg );
        $ink1 = $p['ink_primary'];
        $ink2 = $p['ink_secondary'];
        $ink3 = $p['ink_muted'];

        if ( $lum > 0.4 ) {
            // Light mode overrides (exactly as in marketplace-os)
            $ink1 = '#0f172a';
            $ink2 = '#334155';
            $ink3 = '#64748b';
            $s900 = '#f1f5f9';
            $s800 = '#e8edf5';
            $s700 = '#dde3ee';
            $bdr  = '#cbd5e1';
        }

        // Button colour: can be overridden independently of primary
        $btn  = $btn_color ?: $p['primary'];

        return ":root{"
            . "--portal-bg:{$bg};"
            . "--color-primary:{$p['primary']};"
            . "--color-secondary:{$p['secondary']};"
            . "--color-accent:{$p['accent']};"
            . "--color-button:{$btn};"
            . "--surface-950:{$bg};"
            . "--surface-900:{$s900};"
            . "--surface-800:{$s800};"
            . "--surface-700:{$s700};"
            . "--border:{$bdr};"
            . "--ink-primary:{$ink1};"
            . "--ink-secondary:{$ink2};"
            . "--ink-muted:{$ink3};"
            . "}";
    }

    /**
     * Lighten a hex colour by mixing toward white at $pct (0.0–1.0).
     * Exact port of MOS_Loader::lighten_hex().
     */
    public static function lighten_hex( string $hex, float $pct ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec( substr($hex,0,2) );
        $g = hexdec( substr($hex,2,2) );
        $b = hexdec( substr($hex,4,2) );
        $r = (int) round( $r + (255 - $r) * $pct );
        $g = (int) round( $g + (255 - $g) * $pct );
        $b = (int) round( $b + (255 - $b) * $pct );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * Relative luminance of a hex colour (0 = black, 1 = white).
     * Exact port of MOS_Loader::hex_luminance().
     */
    public static function hex_luminance( string $hex ): float {
        $hex = ltrim( $hex, '#' );
        if ( strlen($hex) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec( substr($hex,0,2) ) / 255;
        $g = hexdec( substr($hex,2,2) ) / 255;
        $b = hexdec( substr($hex,4,2) ) / 255;
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Background presets (mirrors JS NAS_BG_PRESETS) */
    public static function bg_presets(): array {
        return [
            ['key'=>'dark',      'label'=>'Dark',       'value'=>'#0e1117'],
            ['key'=>'midnight',  'label'=>'Midnight',    'value'=>'#040608'],
            ['key'=>'charcoal',  'label'=>'Charcoal',    'value'=>'#0f0f0f'],
            ['key'=>'slate',     'label'=>'Slate',       'value'=>'#0d1117'],
            ['key'=>'navy',      'label'=>'Deep Navy',   'value'=>'#080d1a'],
            ['key'=>'warmblack', 'label'=>'Warm Black',  'value'=>'#0f0a08'],
            ['key'=>'light',     'label'=>'Light Mode',  'value'=>'#f8fafc'],
            ['key'=>'custom',    'label'=>'Custom',      'value'=>null],
        ];
    }

    /**
     * Build the NAS_CONFIG object injected into shell.php as window.NAS_CONFIG.
     * Contains all theme data, palette, and bg presets consumed by the JS theme system.
     */
    public static function build_config(
        string $theme    = 'green',
        string $bg       = '#0e1117',
        string $accent   = '',
        string $btn_color= ''
    ): array {
        $p = self::palette($theme);
        if ($accent) $p['primary'] = $accent;
        return [
            'theme'          => $theme,
            'themePalette'   => $p,
            'portalBg'       => $bg,
            'accentColor'    => $accent ?: $p['primary'],
            'buttonColor'    => $btn_color ?: $p['primary'],
            'themes'         => self::palettes(),
            'bgPresets'      => self::bg_presets(),
        ];
    }
}
