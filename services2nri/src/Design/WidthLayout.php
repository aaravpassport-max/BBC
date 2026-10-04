<?php
namespace S2NRI\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized width tokens: global → page type → page → section inheritance.
 */
class WidthLayout {

    public const BREAKPOINTS = [
        'desktop' => 1280,
        'laptop'  => 1024,
        'tablet'  => 768,
        'mobile'  => 480,
    ];

    /** @return list<string> */
    public static function pageTypeKeys(): array {
        return [
            'home', 'page', 'service', 'services', 'category', 'listing',
            'blog', 'city', 'visa', 'country', 'faq', 'contact', 'pricing',
        ];
    }

    /** @return list<string> */
    public static function sectionKeys(): array {
        return [
            'hero', 'intro', 'description', 'features', 'benefits', 'process',
            'requirements', 'documents', 'pricing', 'faq', 'cta',
            'related_services', 'related_content', 'testimonials', 'wizard',
            'marquee', 'trust_badges', 'cms', 'compare', 'newsletter', 'directory',
            'cities', 'stats', 'partners', 'about', 'app', 'home_services',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaultGlobal(): array {
        $px = static fn( string $d, string $t = '94%', string $m = '100%' ) => [
            'desktop' => $d,
            'laptop'  => $d,
            'tablet'  => $t,
            'mobile'  => $m,
        ];
        return [
            'page_max'          => $px( '1200px' ),
            'content_max'       => $px( '960px' ),
            'inner_max'         => $px( '720px' ),
            'full_bleed'        => $px( '100%', '100%', '100%' ),
            'section_standard'  => $px( '1200px' ),
            'section_wide'      => $px( '1100px' ),
            'section_narrow'    => $px( '860px' ),
            'section_compact'   => $px( '640px' ),
            'padding_x'         => [
                'desktop' => '20px',
                'laptop'  => '20px',
                'tablet'  => '16px',
                'mobile'  => '16px',
            ],
            'min_width'         => '320px',
            'max_width_cap'     => '1920px',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array {
        return [
            'global'       => self::defaultGlobal(),
            'page_types'   => [],
            'pages'        => [],
            'sections'     => [],
            'service_page' => [
                'sections' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config Full resolved design config (includes widths).
     * @param array{page_type?: string, page_slug?: string, section?: string} $ctx
     * @return array<string, string> Flat CSS custom properties (desktop base).
     */
    public static function resolveVars( array $config, array $ctx = [] ): array {
        $widths = is_array( $config['widths'] ?? null ) ? $config['widths'] : self::defaults();
        $widths = self::deepMerge( self::defaults(), $widths );

        $global = $widths['global'] ?? self::defaultGlobal();
        $merged = $global;

        $pt = sanitize_key( $ctx['page_type'] ?? '' );
        $slug = sanitize_title( $ctx['page_slug'] ?? '' );

        if ( $pt && ! empty( $widths['page_types'][ $pt ] ) && is_array( $widths['page_types'][ $pt ] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['page_types'][ $pt ] );
        }
        if ( $pt === 'service' && ! empty( $widths['service_page'] ) && is_array( $widths['service_page'] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['service_page'] );
        }
        if ( $slug && ! empty( $widths['pages'][ $slug ] ) && is_array( $widths['pages'][ $slug ] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['pages'][ $slug ] );
        }

        $vars = self::layerToVars( $merged, '' );

        $section = sanitize_key( $ctx['section'] ?? '' );
        if ( $section ) {
            $sec = self::resolveSectionLayer( $widths, $pt, $section );
            if ( $sec ) {
                $vars = array_merge( $vars, self::layerToVars( $sec, 'sec-' . $section . '-' ) );
            }
        }

        return $vars;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{page_type?: string, page_slug?: string} $ctx
     */
    public static function renderScopeCss( array $config, array $ctx = [] ): string {
        $widths = is_array( $config['widths'] ?? null ) ? $config['widths'] : self::defaults();
        $widths = self::deepMerge( self::defaults(), $widths );
        $global = $widths['global'] ?? self::defaultGlobal();

        $css = ".s2-width-scope,.s2-page-wrap{\n";
        $css .= self::varsBlock( self::layerToVars( $global, '' ) );
        $css .= "}\n";

        foreach ( self::BREAKPOINTS as $bp => $max ) {
            if ( $bp === 'desktop' ) {
                continue;
            }
            $bpVars = self::responsiveVarsForBp( $global, $bp );
            if ( $bpVars === [] ) {
                continue;
            }
            $css .= "@media (max-width: {$max}px){\n.s2-width-scope,.s2-page-wrap{\n";
            $css .= self::varsBlock( $bpVars );
            $css .= "}}\n";
        }

        $pt = sanitize_key( $ctx['page_type'] ?? '' );
        $slug = sanitize_title( $ctx['page_slug'] ?? '' );
        if ( $pt || $slug ) {
            $scoped = self::resolveVars( $config, $ctx );
            $selector = '.s2-width-scope';
            if ( $pt ) {
                $selector .= '[data-s2-page-type="' . esc_attr( $pt ) . '"]';
            }
            if ( $slug ) {
                $selector .= '[data-s2-page-slug="' . esc_attr( $slug ) . '"]';
            }
            $css .= "{$selector}{\n" . self::varsBlock( $scoped ) . "}\n";
        }

        foreach ( self::sectionKeys() as $sec ) {
            $secLayer = self::resolveSectionLayer( $widths, $pt, $sec );
            if ( ! $secLayer ) {
                continue;
            }
            $secVars = self::layerToVars( $secLayer, 'sec-' . $sec . '-' );
            $secMax = self::sectionMaxFromLayer( $secLayer );
            if ( $secMax !== null ) {
                $secVars[ '--s2-width-sec-' . $sec . '-max' ] = $secMax;
            }
            $css .= "[data-s2-section=\"{$sec}\"]{\n" . self::varsBlock( $secVars ) . "}\n";
        }

        return $css;
    }

    /** @param array<string, mixed> $widths */
    private static function resolveSectionLayer( array $widths, string $pageType, string $section ): ?array {
        $section = sanitize_key( $section );
        $layers = [];
        if ( ! empty( $widths['sections'][ $section ] ) && is_array( $widths['sections'][ $section ] ) ) {
            $layers[] = $widths['sections'][ $section ];
        }
        if ( $pageType === 'service' && ! empty( $widths['service_page']['sections'][ $section ] ) ) {
            $layers[] = $widths['service_page']['sections'][ $section ];
        }
        if ( $layers === [] ) {
            return null;
        }
        $out = [];
        foreach ( $layers as $layer ) {
            $out = self::mergeWidthLayer( $out, $layer );
        }
        return $out ?: null;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $patch */
    private static function mergeWidthLayer( array $base, array $patch ): array {
        foreach ( $patch as $key => $val ) {
            if ( $key === 'sections' || $key === 'inherit' ) {
                continue;
            }
            if ( is_array( $val ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::isAssoc( $val ) ) {
                $base[ $key ] = array_merge( $base[ $key ], $val );
            } else {
                $base[ $key ] = $val;
            }
        }
        return $base;
    }

    /**
     * Shorthand used by public-width-layout.css (--s2-width-sec-{section}-max).
     *
     * @param array<string, mixed> $layer
     */
    public static function sectionMaxFromLayer( array $layer ): ?string {
        $priority = [ 'content_max', 'section_wide', 'section_standard', 'section_narrow', 'page_max', 'inner_max' ];
        foreach ( $priority as $key ) {
            if ( ! isset( $layer[ $key ] ) ) {
                continue;
            }
            $val = $layer[ $key ];
            if ( is_array( $val ) ) {
                $val = (string) ( $val['desktop'] ?? reset( $val ) ?: '' );
            }
            $val = trim( (string) $val );
            if ( $val !== '' && $val !== 'inherit' ) {
                return $val;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $layer */
    private static function layerToVars( array $layer, string $prefix ): array {
        $map = [
            'page_max'         => 'page-max',
            'content_max'      => 'content-max',
            'inner_max'        => 'inner-max',
            'full_bleed'       => 'full-bleed',
            'section_standard' => 'section-standard',
            'section_wide'     => 'section-wide',
            'section_narrow'   => 'section-narrow',
            'section_compact'  => 'section-compact',
            'padding_x'        => 'padding-x',
            'min_width'        => 'min',
            'max_width_cap'    => 'max-cap',
        ];
        $vars = [];
        foreach ( $map as $key => $cssKey ) {
            if ( ! isset( $layer[ $key ] ) ) {
                continue;
            }
            $val = $layer[ $key ];
            if ( is_array( $val ) ) {
                $val = (string) ( $val['desktop'] ?? reset( $val ) ?: '' );
            }
            if ( $val === '' || $val === 'inherit' ) {
                continue;
            }
            $vars[ '--s2-width-' . $prefix . $cssKey ] = (string) $val;
        }
        return $vars;
    }

    /** @param array<string, mixed> $layer */
    private static function responsiveVarsForBp( array $layer, string $bp ): array {
        $flat = [];
        foreach ( $layer as $key => $val ) {
            if ( is_array( $val ) && self::isAssoc( $val ) ) {
                if ( isset( $val[ $bp ] ) ) {
                    $flat[ $key ] = $val[ $bp ];
                }
            }
        }
        return self::layerToVars( $flat, '' );
    }

    /** @param array<string, string> $vars */
    private static function varsBlock( array $vars ): string {
        $lines = '';
        foreach ( $vars as $k => $v ) {
            $lines .= '  ' . esc_attr( $k ) . ':' . esc_attr( $v ) . ";\n";
        }
        return $lines;
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
