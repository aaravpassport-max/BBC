<?php
namespace S2NRI\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Central public-site design tokens — single source for CSS variables,
 * typography, colors, spacing, components, and page/section overrides.
 *
 * Inheritance: global → page_type → page → section → element (deepest wins).
 */
class DesignSystem {

    public const SETTING_KEY = 'design_system_v1';

    /** @return array<string, mixed> */
    public static function defaults(): array {
        return [
            'version'     => 1,
            'preset'      => 'professional',
            'fonts'       => [
                'heading'  => 'montserrat',
                'body'     => 'open-sans',
                'ui'       => 'open-sans',
                'button'   => 'montserrat',
                'fallback' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            ],
            'colors'      => [
                'primary'          => '#4A6FA5',
                'primary_hover'    => '#3D5D8A',
                'primary_active'   => '#2F4A6E',
                'secondary'        => '#1E2D40',
                'secondary_hover'  => '#152030',
                'accent'           => '#E8A838',
                'accent_hover'     => '#D4922A',
                'background'       => '#FFFFFF',
                'surface'          => '#FFFFFF',
                'surface_alt'      => '#EBF0F8',
                'card'             => '#FFFFFF',
                'border'           => '#E2E8F0',
                'divider'          => '#EBF0F8',
                'heading'          => '#1E2D40',
                'body'             => '#334155',
                'muted'            => '#64748B',
                'placeholder'      => '#94A3B8',
                'link'             => '#4A6FA5',
                'link_hover'       => '#3D5D8A',
                'success'          => '#059669',
                'warning'          => '#D97706',
                'error'            => '#DC2626',
                'info'             => '#0284C7',
                'disabled'         => '#CBD5E1',
                'overlay'          => 'rgba(30, 45, 64, 0.55)',
                'shadow'           => 'rgba(30, 45, 64, 0.12)',
            ],
            'widths'      => WidthLayout::defaults(),
            'spacing'     => [
                'container_max'    => '1200px',
                'content_max'      => '720px',
                'section_y'        => '64px',
                'section_y_mobile' => '40px',
                'element'          => '16px',
                'card'             => '24px',
                'grid_gap'         => '20px',
                'page_margin'      => '20px',
            ],
            'radius'      => [ 'sm' => '6px', 'md' => '10px', 'lg' => '16px', 'xl' => '24px', 'pill' => '999px' ],
            'shadow'      => [
                'sm'   => '0 1px 2px rgba(15,23,42,.06)',
                'md'   => '0 4px 14px rgba(15,23,42,.08)',
                'lg'   => '0 12px 40px rgba(15,23,42,.12)',
                'card' => '0 8px 30px rgba(30,45,64,.08)',
            ],
            'breakpoints' => [ 'mobile' => 480, 'tablet' => 768, 'laptop' => 1024, 'desktop' => 1280, 'wide' => 1536 ],
            'motion'      => [
                'duration'       => '200ms',
                'ease'           => 'cubic-bezier(.4,0,.2,1)',
                'reduce_motion'  => true,
            ],
            'typography'  => self::defaultTypography(),
            'components'  => [
                'button_primary_bg'     => '{colors.primary}',
                'button_primary_color'  => '#FFFFFF',
                'button_radius'         => '{radius.pill}',
                'card_radius'           => '{radius.lg}',
                'input_radius'          => '{radius.md}',
            ],
            'overrides'   => [
                'page_types' => [],
                'pages'      => [],
                'sections'   => [],
            ],
            'chrome'      => self::defaultChrome(),
        ];
    }

    /** @return array<string, mixed> */
    public static function defaultChrome(): array {
        return [
            'global'     => [
                'show_topbar'          => true,
                'topbar_bg'            => '{colors.primary}',
                'topbar_text'          => '#FFFFFF',
                'header_bg'            => '#FFFFFF',
                'header_text'          => '{colors.heading}',
                'header_height_px'     => '64',
                'header_variant'       => 'standard',
                'footer_bg'            => '{colors.secondary}',
                'footer_text'          => '#94A3B8',
                'footer_heading_text'  => '#FFFFFF',
                'footer_variant'       => 'full',
            ],
            'page_types' => [],
            'pages'      => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultTypography(): array {
        $base = [
            'font_weight'   => '400',
            'line_height'   => '1.5',
            'letter_spacing'=> '0',
            'transform'     => 'none',
            'color'         => '{colors.body}',
            'max_width'     => 'none',
            'align'         => 'inherit',
            'size_desktop'  => '1rem',
            'size_tablet'   => '1rem',
            'size_mobile'   => '0.9375rem',
        ];
        $roles = [
            'page_title'       => [ 'size_desktop' => '2.75rem', 'size_tablet' => '2.25rem', 'size_mobile' => '1.875rem', 'font_weight' => '700', 'line_height' => '1.15', 'color' => '{colors.heading}' ],
            'h1'               => [ 'size_desktop' => '2.75rem', 'size_tablet' => '2.25rem', 'size_mobile' => '1.875rem', 'font_weight' => '700', 'line_height' => '1.15', 'color' => '{colors.heading}' ],
            'h2'               => [ 'size_desktop' => '2rem', 'size_tablet' => '1.75rem', 'size_mobile' => '1.5rem', 'font_weight' => '700', 'line_height' => '1.2', 'color' => '{colors.heading}' ],
            'h3'               => [ 'size_desktop' => '1.5rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'h4'               => [ 'size_desktop' => '1.25rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'h5'               => [ 'size_desktop' => '1.125rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'h6'               => [ 'size_desktop' => '1rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'section_heading'  => [ 'size_desktop' => '2rem', 'font_weight' => '700', 'color' => '{colors.heading}' ],
            'section_subheading'=> [ 'size_desktop' => '1.125rem', 'color' => '{colors.muted}' ],
            'eyebrow'          => [ 'size_desktop' => '0.75rem', 'font_weight' => '600', 'letter_spacing' => '0.08em', 'transform' => 'uppercase', 'color' => '{colors.accent}' ],
            'body'             => [ 'size_desktop' => '1rem', 'line_height' => '1.65' ],
            'body_lg'          => [ 'size_desktop' => '1.125rem', 'line_height' => '1.65' ],
            'body_sm'          => [ 'size_desktop' => '0.875rem' ],
            'caption'          => [ 'size_desktop' => '0.8125rem', 'color' => '{colors.muted}' ],
            'meta'             => [ 'size_desktop' => '0.75rem', 'color' => '{colors.muted}' ],
            'label'            => [ 'size_desktop' => '0.875rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'form_label'       => [ 'size_desktop' => '0.875rem', 'font_weight' => '600' ],
            'helper'           => [ 'size_desktop' => '0.8125rem', 'color' => '{colors.muted}' ],
            'placeholder'      => [ 'color' => '{colors.placeholder}' ],
            'nav'              => [ 'size_desktop' => '0.9375rem', 'font_weight' => '500' ],
            'button'           => [ 'size_desktop' => '0.9375rem', 'font_weight' => '600', 'letter_spacing' => '0.02em' ],
            'card_title'       => [ 'size_desktop' => '1.125rem', 'font_weight' => '600', 'color' => '{colors.heading}' ],
            'card_desc'        => [ 'size_desktop' => '0.9375rem', 'color' => '{colors.muted}' ],
            'breadcrumb'       => [ 'size_desktop' => '0.8125rem', 'color' => '{colors.muted}' ],
            'badge'            => [ 'size_desktop' => '0.75rem', 'font_weight' => '600' ],
            'cta_heading'      => [ 'size_desktop' => '2rem', 'font_weight' => '700', 'color' => '#FFFFFF' ],
            'cta_desc'         => [ 'size_desktop' => '1.0625rem', 'color' => 'rgba(255,255,255,.9)' ],
            'footer_heading'   => [ 'size_desktop' => '0.875rem', 'font_weight' => '700', 'color' => '#FFFFFF' ],
            'footer_content'   => [ 'size_desktop' => '0.875rem', 'color' => 'rgba(255,255,255,.75)' ],
            'legal'            => [ 'size_desktop' => '0.75rem', 'color' => '{colors.muted}' ],
        ];
        $out = [];
        foreach ( $roles as $key => $patch ) {
            $out[ $key ] = array_merge( $base, $patch );
            if ( in_array( $key, [ 'page_title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section_heading', 'cta_heading', 'footer_heading', 'card_title' ], true ) ) {
                $out[ $key ]['font_role'] = 'heading';
            } elseif ( in_array( $key, [ 'button', 'nav', 'badge', 'label', 'form_label' ], true ) ) {
                $out[ $key ]['font_role'] = 'ui';
            } else {
                $out[ $key ]['font_role'] = 'body';
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function loadStored(): array {
        $raw = \S2NRI\Models\Setting::get( self::SETTING_KEY, '' );
        if ( ! $raw ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** Seed stored design config on first activate so public site matches defaults without manual Publish. */
    public static function ensureSeeded(): void {
        if ( self::loadStored() !== [] ) {
            return;
        }
        $defaults = self::defaults();
        $defaults['published_at'] = current_time( 'mysql' );
        \S2NRI\Models\Setting::set( self::SETTING_KEY, wp_json_encode( $defaults ), true );
        if ( ! empty( $defaults['colors']['primary'] ) ) {
            \S2NRI\Models\Setting::set( 'primary_color', sanitize_hex_color( (string) $defaults['colors']['primary'] ) ?: '#4A6FA5', true );
        }
        if ( ! empty( $defaults['colors']['accent'] ) ) {
            \S2NRI\Models\Setting::set( 'accent_color', sanitize_hex_color( (string) $defaults['colors']['accent'] ) ?: '#E8A838', true );
        }
    }

    /** @param array<string, mixed> $patch */
    public static function save( array $patch ): void {
        // Admin publishes the full config tree; merge only with defaults (not stored) so cleared overrides inherit correctly.
        $merged = self::deepMerge( self::defaults(), $patch );
        $merged = self::normalizeConfig( $merged );
        $desktopPage = $merged['widths']['global']['page_max']['desktop'] ?? null;
        if ( is_string( $desktopPage ) && $desktopPage !== '' ) {
            $merged['spacing']['container_max'] = $desktopPage;
        }
        \S2NRI\Models\Setting::set( self::SETTING_KEY, wp_json_encode( $merged ), true );
        // Keep legacy primary_color in sync for existing components.
        if ( ! empty( $merged['colors']['primary'] ) ) {
            \S2NRI\Models\Setting::set( 'primary_color', sanitize_hex_color( (string) $merged['colors']['primary'] ) ?: '#4A6FA5', true );
        }
        if ( ! empty( $merged['colors']['accent'] ) ) {
            \S2NRI\Models\Setting::set( 'accent_color', sanitize_hex_color( (string) $merged['colors']['accent'] ) ?: '#E8A838', true );
        }
    }

    /**
     * @param string $preset_id Preset key.
     * @param string $mode      theme = colors/fonts/spacing/radius/shadow only; factory = reset to defaults + preset.
     */
    public static function applyPreset( string $preset_id, string $mode = 'theme' ): array {
        $id    = sanitize_key( $preset_id );
        $theme = DesignPresets::expanded( $id );
        if ( $mode === 'factory' ) {
            $base = self::defaults();
            $patch = DesignPresets::patch( $id );
            $config = self::deepMerge( $base, $patch );
            $config['preset'] = $id;
            $config['overrides'] = $base['overrides'];
            self::save( $config );
            return self::resolve( [] );
        }
        $current = self::resolve( [] );
        foreach ( [ 'colors', 'fonts', 'radius', 'shadow', 'spacing' ] as $section ) {
            if ( isset( $theme[ $section ] ) && is_array( $theme[ $section ] ) ) {
                $current[ $section ] = $theme[ $section ];
            }
        }
        $current['preset'] = $id;
        self::save( $current );
        return self::resolve( [] );
    }

    /**
     * @param array{page_type?: string, page_slug?: string, section?: string} $ctx
     * @return array<string, mixed>
     */
    public static function resolve( array $ctx = [] ): array {
        $config = self::deepMerge( self::defaults(), self::loadStored() );
        $pt = sanitize_key( $ctx['page_type'] ?? '' );
        $slug = sanitize_title( $ctx['page_slug'] ?? '' );
        $section = sanitize_key( $ctx['section'] ?? '' );

        if ( $pt && ! empty( $config['overrides']['page_types'][ $pt ] ) ) {
            $config = self::deepMerge( $config, $config['overrides']['page_types'][ $pt ] );
        }
        if ( $pt === 'page' && $slug && ! empty( $config['overrides']['page_types'][ $slug ] ) ) {
            $config = self::deepMerge( $config, $config['overrides']['page_types'][ $slug ] );
        }
        if ( $slug && ! empty( $config['overrides']['pages'][ $slug ] ) ) {
            $config = self::deepMerge( $config, $config['overrides']['pages'][ $slug ] );
        }
        if ( $section && ! empty( $config['overrides']['sections'][ $section ] ) ) {
            $config = self::deepMerge( $config, $config['overrides']['sections'][ $section ] );
        }

        // Legacy platform colors override when design_system not customized yet.
        $legacy_primary = \S2NRI\Models\Setting::get( 'primary_color', '' );
        if ( $legacy_primary && empty( self::loadStored() ) ) {
            $config['colors']['primary'] = $legacy_primary;
            $config['colors']['link']      = $legacy_primary;
        }

        return $config;
    }

    /** Public payload for S2NRI_CONFIG (compact). */
    public static function getPublicPayload(): array {
        $resolved = self::resolve( [] );
        return [
            'preset'      => $resolved['preset'] ?? 'professional',
            'colors'      => $resolved['colors'] ?? [],
            'fonts'       => $resolved['fonts'] ?? [],
            'spacing'     => $resolved['spacing'] ?? [],
            'widths'      => $resolved['widths'] ?? WidthLayout::defaults(),
            'typography'  => $resolved['typography'] ?? [],
            'radius'      => $resolved['radius'] ?? [],
            'shadow'      => $resolved['shadow'] ?? [],
            'components'  => $resolved['components'] ?? [],
            'motion'      => $resolved['motion'] ?? [],
            'breakpoints' => $resolved['breakpoints'] ?? [],
            'overrides'   => $resolved['overrides'] ?? [ 'page_types' => [], 'pages' => [], 'sections' => [] ],
            'chrome'      => $resolved['chrome'] ?? self::defaultChrome(),
        ];
    }

    public static function pageContextFromPath( string $path ): array {
        $path = '/' . trim( $path, '/' );
        if ( $path === '/' || $path === '' ) {
            return [ 'page_type' => 'home', 'page_slug' => 'home' ];
        }
        if ( preg_match( '#^/service/([^/]+)#', $path, $m ) ) {
            return [ 'page_type' => 'service', 'page_slug' => $m[1] ];
        }
        if ( str_starts_with( $path, '/services' ) ) {
            return [ 'page_type' => 'services', 'page_slug' => trim( $path, '/' ) ];
        }
        if ( str_starts_with( $path, '/blog' ) ) {
            return [ 'page_type' => 'blog', 'page_slug' => trim( $path, '/' ) ];
        }
        if ( str_starts_with( $path, '/cities/' ) ) {
            return [ 'page_type' => 'city', 'page_slug' => trim( $path, '/' ) ];
        }
        if ( preg_match( '#^/services/([^/]+)#', $path, $m ) ) {
            return [ 'page_type' => 'category', 'page_slug' => $m[1] ];
        }
        $static = [
            '/about'         => 'about',
            '/contact'       => 'contact',
            '/how-it-works'  => 'how-it-works',
            '/faq'           => 'faq',
            '/pricing'       => 'pricing',
            '/terms'         => 'terms',
            '/privacy'       => 'privacy',
        ];
        if ( isset( $static[ $path ] ) ) {
            return [ 'page_type' => 'page', 'page_slug' => $static[ $path ] ];
        }
        if ( str_starts_with( $path, '/visa' ) ) {
            return [ 'page_type' => 'visa', 'page_slug' => trim( $path, '/' ) ];
        }
        if ( str_starts_with( $path, '/country' ) ) {
            return [ 'page_type' => 'country', 'page_slug' => trim( $path, '/' ) ];
        }
        $slug = trim( $path, '/' );
        return [ 'page_type' => 'page', 'page_slug' => $slug ?: 'page' ];
    }

    public static function renderFontLinks( array $config ): string {
        $fonts = $config['fonts'] ?? [];
        $ids = array_unique( array_filter( [
            $fonts['heading'] ?? '',
            $fonts['body'] ?? '',
            $fonts['ui'] ?? '',
            $fonts['button'] ?? '',
        ] ) );
        $url = FontLibrary::googleCssUrl( $ids );
        if ( ! $url ) {
            return '';
        }
        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link rel="stylesheet" href="' . esc_url( $url ) . '">' . "\n";
    }

    public static function renderInlineCss( ?string $request_path = null ): string {
        $ctx = $request_path ? self::pageContextFromPath( $request_path ) : [];
        $config = self::resolve( $ctx );
        $vars = self::buildCssVariables( $config, $ctx );
        $css = ":root{\n" . $vars . "\n}\n";
        $css .= self::typographyUtilityCss( $config );
        $css .= file_exists( S2NRI_DIR . 'assets/public-design-system.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-design-system.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-page-utilities.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-page-utilities.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-home-sections.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-home-sections.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-marketing-sections.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-marketing-sections.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-pages-layout.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-pages-layout.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-marketing-pages.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-marketing-pages.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-service-detail.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-service-detail.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-services-directory.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-services-directory.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-width-layout.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-width-layout.css' )
            : '';
        $css .= file_exists( S2NRI_DIR . 'assets/public-site-chrome.css' )
            ? file_get_contents( S2NRI_DIR . 'assets/public-site-chrome.css' )
            : '';
        $css .= WidthLayout::renderScopeCss( $config, $ctx );
        $css .= self::renderSectionOverrideCss( $config );
        if ( ! empty( $config['motion']['reduce_motion'] ) ) {
            $css .= "@media (prefers-reduced-motion: reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important;}}\n";
        }
        return $css;
    }

    /**
     * @param array{page_type?: string, page_slug?: string} $ctx
     * @return array<string, string>
     */
    private static function resolveChromeLayer( array $config, array $ctx = [] ): array {
        $tree = is_array( $config['chrome'] ?? null ) ? $config['chrome'] : self::defaultChrome();
        $global = is_array( $tree['global'] ?? null ) ? $tree['global'] : [];
        $merged = $global;
        $pt     = sanitize_key( $ctx['page_type'] ?? '' );
        $slug   = sanitize_title( $ctx['page_slug'] ?? '' );
        $types  = is_array( $tree['page_types'] ?? null ) ? $tree['page_types'] : [];
        $pages  = is_array( $tree['pages'] ?? null ) ? $tree['pages'] : [];
        if ( $pt && ! empty( $types[ $pt ] ) && is_array( $types[ $pt ] ) ) {
            $merged = array_merge( $merged, $types[ $pt ] );
        }
        if ( $pt === 'page' && $slug && ! empty( $types[ $slug ] ) && is_array( $types[ $slug ] ) ) {
            $merged = array_merge( $merged, $types[ $slug ] );
        }
        if ( $slug && ! empty( $pages[ $slug ] ) && is_array( $pages[ $slug ] ) ) {
            $merged = array_merge( $merged, $pages[ $slug ] );
        }
        return $merged;
    }

    /** @param array<string, mixed> $layer */
    private static function chromeLayerToCssVars( array $layer, array $config ): array {
        $map = [
            'topbar_bg'           => '--s2-chrome-topbar-bg',
            'topbar_text'         => '--s2-chrome-topbar-text',
            'header_bg'           => '--s2-chrome-header-bg',
            'header_text'         => '--s2-chrome-header-text',
            'footer_bg'           => '--s2-chrome-footer-bg',
            'footer_text'         => '--s2-chrome-footer-text',
            'footer_heading_text' => '--s2-chrome-footer-heading-text',
        ];
        $vars = [];
        foreach ( $map as $key => $cssVar ) {
            if ( empty( $layer[ $key ] ) || ! is_string( $layer[ $key ] ) ) {
                continue;
            }
            $vars[ $cssVar ] = self::resolveTokenRef( (string) $layer[ $key ], $config );
        }
        if ( ! empty( $layer['header_height_px'] ) ) {
            $h = (string) $layer['header_height_px'];
            if ( preg_match( '/^\d+(\.\d+)?$/', trim( $h ) ) ) {
                $h .= 'px';
            }
            $vars['--s2-chrome-header-height'] = $h;
        }
        if ( ! empty( $layer['header_variant'] ) && $layer['header_variant'] === 'compact' ) {
            $vars['--s2-chrome-header-height'] = $vars['--s2-chrome-header-height'] ?? '56px';
        }
        return $vars;
    }

    /**
     * @param array{page_type?: string, page_slug?: string} $ctx
     */
    private static function buildCssVariables( array $config, array $ctx = [] ): string {
        $lines = [];
        $colors = $config['colors'] ?? [];
        foreach ( $colors as $k => $v ) {
            $lines[] = '  --s2-color-' . sanitize_key( $k ) . ':' . esc_attr( (string) $v ) . ';';
            if ( $k === 'primary' ) {
                $lines[] = '  --s2-primary:' . esc_attr( (string) $v ) . ';';
            }
        }
        $fonts = $config['fonts'] ?? [];
        $fallback = (string) ( $fonts['fallback'] ?? 'system-ui, sans-serif' );
        $lines[] = '  --s2-font-heading:' . FontLibrary::stackFor( (string) ( $fonts['heading'] ?? 'montserrat' ), $fallback ) . ';';
        $lines[] = '  --s2-font-body:' . FontLibrary::stackFor( (string) ( $fonts['body'] ?? 'open-sans' ), $fallback ) . ';';
        $lines[] = '  --s2-font-ui:' . FontLibrary::stackFor( (string) ( $fonts['ui'] ?? 'open-sans' ), $fallback ) . ';';
        $lines[] = '  --s2-font-button:' . FontLibrary::stackFor( (string) ( $fonts['button'] ?? 'montserrat' ), $fallback ) . ';';
        $lines[] = '  --s2-font-fallback:' . esc_attr( $fallback ) . ';';

        $widthVars = WidthLayout::resolveVars( $config, [] );
        foreach ( $widthVars as $k => $v ) {
            $lines[] = '  ' . $k . ':' . esc_attr( $v ) . ';';
        }
        $pageMax = $widthVars['--s2-width-page-max'] ?? ( $config['spacing']['container_max'] ?? '1200px' );
        $lines[] = '  --s2-space-container_max:' . esc_attr( (string) $pageMax ) . ';';
        foreach ( ( $config['spacing'] ?? [] ) as $k => $v ) {
            if ( $k === 'container_max' ) {
                continue;
            }
            $lines[] = '  --s2-space-' . sanitize_key( $k ) . ':' . esc_attr( (string) $v ) . ';';
        }
        foreach ( ( $config['radius'] ?? [] ) as $k => $v ) {
            $lines[] = '  --s2-radius-' . sanitize_key( $k ) . ':' . esc_attr( (string) $v ) . ';';
        }
        foreach ( ( $config['shadow'] ?? [] ) as $k => $v ) {
            $lines[] = '  --s2-shadow-' . sanitize_key( $k ) . ':' . esc_attr( (string) $v ) . ';';
        }
        $bp = $config['breakpoints'] ?? [];
        foreach ( $bp as $k => $v ) {
            $lines[] = '  --s2-bp-' . sanitize_key( $k ) . ':' . (int) $v . 'px;';
        }
        $motion = $config['motion'] ?? [];
        $lines[] = '  --s2-motion-duration:' . esc_attr( (string) ( $motion['duration'] ?? '200ms' ) ) . ';';
        $lines[] = '  --s2-motion-ease:' . esc_attr( (string) ( $motion['ease'] ?? 'ease' ) ) . ';';

        // Legacy aliases consumed across the codebase.
        $lines[] = '  --s2-dark:' . esc_attr( (string) ( $colors['secondary'] ?? '#1E2D40' ) ) . ';';
        $lines[] = '  --s2-light-bg:' . esc_attr( (string) ( $colors['surface_alt'] ?? '#EBF0F8' ) ) . ';';
        $lines[] = '  --brand:var(--s2-primary);';

        $comp = $config['components'] ?? [];
        $comp_map = [
            'button_primary_bg'    => '--s2-btn-primary-bg',
            'button_primary_color' => '--s2-btn-primary-color',
            'button_radius'        => '--s2-btn-radius',
            'card_radius'          => '--s2-card-radius',
            'input_radius'         => '--s2-input-radius',
        ];
        foreach ( $comp_map as $key => $var ) {
            if ( ! empty( $comp[ $key ] ) ) {
                $lines[] = '  ' . $var . ':' . self::resolveTokenRef( (string) $comp[ $key ], $config ) . ';';
            }
        }

        $chromeVars = self::chromeLayerToCssVars( self::resolveChromeLayer( $config, $ctx ), $config );
        foreach ( $chromeVars as $k => $v ) {
            $lines[] = '  ' . $k . ':' . esc_attr( $v ) . ';';
        }

        return implode( "\n", $lines );
    }

    /** Section-scoped color overrides from overrides.sections */
    private static function renderSectionOverrideCss( array $config ): string {
        $sections = $config['overrides']['sections'] ?? [];
        if ( ! is_array( $sections ) || $sections === [] ) {
            return '';
        }
        $css = '';
        foreach ( $sections as $sec => $ov ) {
            if ( ! is_array( $ov ) || empty( $ov['colors'] ) || ! is_array( $ov['colors'] ) ) {
                continue;
            }
            $sec = sanitize_key( (string) $sec );
            $block = '';
            foreach ( $ov['colors'] as $k => $v ) {
                if ( ! is_string( $v ) || $v === '' ) {
                    continue;
                }
                $block .= '  --s2-color-' . sanitize_key( $k ) . ':' . self::resolveTokenRef( $v, $config ) . ";\n";
            }
            if ( $block !== '' ) {
                $css .= '[data-s2-section="' . esc_attr( $sec ) . '"]{' . $block . "}\n";
            }
        }
        return $css;
    }

    private static function typographyUtilityCss( array $config ): string {
        $css = "\n.s2-ds{font-family:var(--s2-font-body);color:var(--s2-color-body);}\n";
        $css .= ".s2-ds h1,.s2-ds .s2-t-page-title,.s2-ds .s2-t-h1{font-family:var(--s2-font-heading);}\n";
        $typo = $config['typography'] ?? [];
        foreach ( $typo as $role => $t ) {
            if ( ! is_array( $t ) ) {
                continue;
            }
            $class = '.s2-t-' . sanitize_key( str_replace( '_', '-', $role ) );
            $sizeD = esc_attr( (string) ( $t['size_desktop'] ?? '1rem' ) );
            $sizeT = esc_attr( (string) ( $t['size_tablet'] ?? $sizeD ) );
            $sizeM = esc_attr( (string) ( $t['size_mobile'] ?? $sizeT ) );
            $weight = esc_attr( (string) ( $t['font_weight'] ?? '400' ) );
            $lh = esc_attr( (string) ( $t['line_height'] ?? '1.5' ) );
            $color = self::resolveTokenRef( (string) ( $t['color'] ?? '{colors.body}' ), $config );
            $fontRole = (string) ( $t['font_role'] ?? 'body' );
            $ff = $fontRole === 'heading' ? 'var(--s2-font-heading)' : ( $fontRole === 'ui' ? 'var(--s2-font-ui)' : 'var(--s2-font-body)' );
            $css .= "{$class}{font-family:{$ff};font-size:{$sizeD};font-weight:{$weight};line-height:{$lh};color:{$color};";
            if ( ! empty( $t['letter_spacing'] ) ) {
                $css .= 'letter-spacing:' . esc_attr( (string) $t['letter_spacing'] ) . ';';
            }
            if ( ! empty( $t['transform'] ) && $t['transform'] !== 'none' ) {
                $css .= 'text-transform:' . esc_attr( (string) $t['transform'] ) . ';';
            }
            if ( ! empty( $t['max_width'] ) && $t['max_width'] !== 'none' ) {
                $css .= 'max-width:' . esc_attr( (string) $t['max_width'] ) . ';';
            }
            $css .= "}\n";
            $css .= "@media(max-width:1024px){{$class}{font-size:{$sizeT};}}\n";
            $css .= "@media(max-width:768px){{$class}{font-size:{$sizeM};}}\n";
        }
        return $css;
    }

    private static function resolveTokenRef( string $value, array $config ): string {
        if ( preg_match( '/^\{colors\.([a-z0-9_]+)\}$/', $value, $m ) ) {
            return 'var(--s2-color-' . sanitize_key( $m[1] ) . ')';
        }
        if ( preg_match( '/^\{radius\.([a-z0-9_]+)\}$/', $value, $m ) ) {
            return 'var(--s2-radius-' . sanitize_key( $m[1] ) . ')';
        }
        return esc_attr( $value );
    }

    /** Normalize admin-published values (hex colors, px spacing). */
    private static function normalizeConfig( array $config ): array {
        if ( ! empty( $config['colors'] ) && is_array( $config['colors'] ) ) {
            foreach ( $config['colors'] as $k => $v ) {
                if ( ! is_string( $v ) ) {
                    continue;
                }
                $v = trim( $v );
                if ( str_starts_with( $v, '#' ) ) {
                    $hex = sanitize_hex_color( $v );
                    if ( $hex ) {
                        $config['colors'][ $k ] = strtoupper( $hex );
                    }
                }
            }
            if ( ! empty( $config['colors']['primary'] ) ) {
                $config['colors']['link'] = $config['colors']['link'] ?? $config['colors']['primary'];
            }
        }
        if ( ! empty( $config['spacing'] ) && is_array( $config['spacing'] ) ) {
            $config['spacing'] = self::normalizePxMap( $config['spacing'] );
        }
        if ( ! empty( $config['radius'] ) && is_array( $config['radius'] ) ) {
            $config['radius'] = self::normalizePxMap( $config['radius'] );
        }
        if ( ! empty( $config['widths'] ) && is_array( $config['widths'] ) ) {
            $config['widths'] = self::normalizeWidthTree( $config['widths'] );
        }
        return $config;
    }

    /** @param array<string, mixed> $map */
    private static function normalizePxMap( array $map ): array {
        foreach ( $map as $k => $v ) {
            if ( is_string( $v ) && preg_match( '/^\d+(\.\d+)?$/', trim( $v ) ) ) {
                $map[ $k ] = trim( $v ) . 'px';
            }
        }
        return $map;
    }

    /** @param array<string, mixed> $widths */
    private static function normalizeWidthTree( array $widths ): array {
        $bps = [ 'desktop', 'laptop', 'tablet', 'mobile' ];
        foreach ( $widths as $k => $v ) {
            if ( ! is_array( $v ) ) {
                if ( is_string( $v ) && preg_match( '/^\d+(\.\d+)?$/', trim( $v ) ) ) {
                    $widths[ $k ] = trim( $v ) . 'px';
                }
                continue;
            }
            $isBp = array_intersect( array_keys( $v ), $bps ) !== [];
            if ( $isBp ) {
                foreach ( $v as $bp => $val ) {
                    if ( is_string( $val ) && preg_match( '/^\d+(\.\d+)?$/', trim( $val ) ) ) {
                        $v[ $bp ] = trim( $val ) . 'px';
                    }
                }
                $widths[ $k ] = $v;
            } else {
                $widths[ $k ] = self::normalizeWidthTree( $v );
            }
        }
        return $widths;
    }

    /** @param array<string, mixed> ...$layers */
    private static function deepMerge( array ...$layers ): array {
        $out = [];
        foreach ( $layers as $layer ) {
            foreach ( $layer as $k => $v ) {
                if ( is_array( $v ) && isset( $out[ $k ] ) && is_array( $out[ $k ] ) && self::isAssoc( $v ) && self::isAssoc( $out[ $k ] ) ) {
                    $out[ $k ] = self::deepMerge( $out[ $k ], $v );
                } else {
                    $out[ $k ] = $v;
                }
            }
        }
        return $out;
    }

    private static function isAssoc( array $arr ): bool {
        if ( $arr === [] ) {
            return true;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }
}
