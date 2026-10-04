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
        'mobile'  => 768,
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
                'tablet'  => '14px',
                'mobile'  => '12px',
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
    /**
     * Merged page-level width layer (global → page type → page), before section scope.
     *
     * @param array<string, mixed> $config
     * @param array{page_type?: string, page_slug?: string} $ctx
     * @return array<string, mixed>
     */
    public static function mergeContextLayer( array $config, array $ctx = [] ): array {
        $widths = is_array( $config['widths'] ?? null ) ? $config['widths'] : self::defaults();
        $widths = self::deepMerge( self::defaults(), $widths );

        $global = $widths['global'] ?? self::defaultGlobal();
        $merged = $global;

        $pt = sanitize_key( $ctx['page_type'] ?? '' );
        $slug = sanitize_title( $ctx['page_slug'] ?? '' );

        if ( $pt && ! empty( $widths['page_types'][ $pt ] ) && is_array( $widths['page_types'][ $pt ] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['page_types'][ $pt ] );
        }
        if ( $pt === 'page' && $slug !== '' && ! empty( $widths['page_types'][ $slug ] ) && is_array( $widths['page_types'][ $slug ] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['page_types'][ $slug ] );
        }
        if ( $pt === 'service' && ! empty( $widths['service_page'] ) && is_array( $widths['service_page'] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['service_page'] );
        }
        if ( $slug && ! empty( $widths['pages'][ $slug ] ) && is_array( $widths['pages'][ $slug ] ) ) {
            $merged = self::mergeWidthLayer( $merged, $widths['pages'][ $slug ] );
        }

        return $merged;
    }

    public static function resolveVars( array $config, array $ctx = [] ): array {
        $widths = is_array( $config['widths'] ?? null ) ? $config['widths'] : self::defaults();
        $widths = self::deepMerge( self::defaults(), $widths );

        $merged = self::mergeContextLayer( $config, $ctx );

        $pt = sanitize_key( $ctx['page_type'] ?? '' );
        $slug = sanitize_title( $ctx['page_slug'] ?? '' );

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
            $selector = '.s2-width-scope,.s2-page-wrap';
            if ( $pt ) {
                $selector .= '[data-s2-page-type="' . esc_attr( $pt ) . '"]';
            }
            if ( $slug ) {
                $selector .= '[data-s2-page-slug="' . esc_attr( $slug ) . '"]';
            }
            $css .= "{$selector}{\n" . self::varsBlock( $scoped ) . "}\n";
            $merged = self::mergeContextLayer( $config, $ctx );
            $css .= self::renderLayerResponsiveCss( $merged, $selector );
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
            $secSelector = '[data-s2-section="' . esc_attr( $sec ) . '"]';
            $css .= "{$secSelector}{\n" . self::varsBlock( $secVars ) . "}\n";
            $css .= self::renderLayerResponsiveCss( $secLayer, $secSelector, 'sec-' . $sec . '-' );
        }

        $css .= self::renderPageTypeSectionCss( $widths );

        return $css;
    }

    /** Homepage-only (and other template) section widths — beats global [data-s2-section]. */
    private static function renderPageTypeSectionCss( array $widths ): string {
        $css   = '';
        $types = is_array( $widths['page_types'] ?? null ) ? $widths['page_types'] : [];
        foreach ( $types as $ptKey => $ptConfig ) {
            if ( ! is_array( $ptConfig ) || empty( $ptConfig['sections'] ) || ! is_array( $ptConfig['sections'] ) ) {
                continue;
            }
            $ptKey = sanitize_key( (string) $ptKey );
            foreach ( $ptConfig['sections'] as $sec => $layer ) {
                if ( ! is_array( $layer ) ) {
                    continue;
                }
                $sec     = sanitize_key( (string) $sec );
                $secVars = self::layerToVars( $layer, 'sec-' . $sec . '-' );
                $secMax  = self::sectionMaxFromLayer( $layer );
                if ( $secMax !== null ) {
                    $secVars[ '--s2-width-sec-' . $sec . '-max' ] = $secMax;
                }
                if ( $secVars === [] ) {
                    continue;
                }
                $secSelector = '.s2-width-scope[data-s2-page-type="' . esc_attr( $ptKey ) . '"] [data-s2-section="' . esc_attr( $sec ) . '"],';
                $secSelector .= '.s2-page-wrap[data-s2-page-type="' . esc_attr( $ptKey ) . '"] [data-s2-section="' . esc_attr( $sec ) . '"]';
                $css .= "{$secSelector}{\n";
                $css .= self::varsBlock( $secVars );
                $css .= "}\n";
                $css .= self::renderLayerResponsiveCss( $layer, $secSelector, 'sec-' . $sec . '-' );
            }
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
        if (
            $pageType !== ''
            && ! empty( $widths['page_types'][ $pageType ]['sections'][ $section ] )
            && is_array( $widths['page_types'][ $pageType ]['sections'][ $section ] )
        ) {
            $layers[] = $widths['page_types'][ $pageType ]['sections'][ $section ];
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
            'padding_x_left'   => 'padding-x-left',
            'padding_x_right'  => 'padding-x-right',
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

    /**
     * @param array<string, mixed> $layer
     */
    private static function renderLayerResponsiveCss( array $layer, string $selector, string $prefix = '' ): string {
        $css = '';
        foreach ( self::BREAKPOINTS as $bp => $max ) {
            if ( $bp === 'desktop' ) {
                continue;
            }
            $flat = [];
            foreach ( $layer as $key => $val ) {
                if ( is_array( $val ) && self::isAssoc( $val ) && isset( $val[ $bp ] ) && (string) $val[ $bp ] !== '' ) {
                    $flat[ $key ] = $val[ $bp ];
                }
            }
            if ( $flat === [] ) {
                continue;
            }
            $bpVars = self::layerToVars( $flat, $prefix );
            if ( $bpVars === [] ) {
                continue;
            }
            $css .= "@media (max-width: {$max}px){\n{$selector}{\n";
            $css .= self::varsBlock( $bpVars );
            $css .= "}}\n";
        }
        return $css;
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

    /** @return list<string> */
    private static function inheritableWidthKeys(): array {
        return [
            'page_max', 'content_max', 'inner_max', 'full_bleed',
            'section_standard', 'section_wide', 'section_narrow', 'section_compact',
            'padding_x', 'padding_x_left', 'padding_x_right', 'min_width', 'max_width_cap',
        ];
    }

    /** @param mixed $v */
    private static function widthTokenNorm( $v ): string {
        if ( ! is_array( $v ) ) {
            return trim( (string) $v );
        }
        $parts = [];
        foreach ( [ 'desktop', 'laptop', 'tablet', 'mobile' ] as $bp ) {
            $parts[] = trim( (string) ( $v[ $bp ] ?? '' ) );
        }
        return implode( '|', $parts );
    }

    /** @param array<string, mixed> $layer @param array<string, mixed> $parent */
    private static function pruneLayerAgainstParent( array $layer, array $parent ): array {
        foreach ( self::inheritableWidthKeys() as $key ) {
            if ( ! array_key_exists( $key, $layer ) ) {
                continue;
            }
            if ( self::widthTokenNorm( $layer[ $key ] ) === self::widthTokenNorm( $parent[ $key ] ?? '' ) ) {
                unset( $layer[ $key ] );
            }
        }
        return $layer;
    }

    /** @param array<string, mixed> $layer */
    private static function isEmptyWidthLayer( array $layer ): bool {
        foreach ( self::inheritableWidthKeys() as $key ) {
            if ( isset( $layer[ $key ] ) && $layer[ $key ] !== null && $layer[ $key ] !== '' ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Drop redundant width overrides that match inherited parent values (sparse storage).
     *
     * @param array<string, mixed> $widths
     * @return array<string, mixed>
     */
    public static function pruneInheritedWidthLayers( array $widths ): array {
        $widths = self::deepMerge( self::defaults(), $widths );
        $global = is_array( $widths['global'] ?? null ) ? $widths['global'] : self::defaultGlobal();

        $page_types = is_array( $widths['page_types'] ?? null ) ? $widths['page_types'] : [];
        foreach ( array_keys( $page_types ) as $pt_key ) {
            if ( ! is_array( $page_types[ $pt_key ] ?? null ) ) {
                unset( $page_types[ $pt_key ] );
                continue;
            }
            $raw   = $page_types[ $pt_key ];
            $ctx   = [ 'page_type' => (string) $pt_key, 'page_slug' => $pt_key === 'home' ? 'home' : (string) $pt_key ];
            $below = $widths;
            $below['page_types'] = $page_types;
            unset( $below['page_types'][ $pt_key ] );
            $parent = self::resolveVars( [ 'widths' => $below ], $ctx );
            $parent_layer = [];
            foreach ( self::inheritableWidthKeys() as $k ) {
                $var = '--s2-width-' . str_replace( '_', '-', $k );
                if ( isset( $parent[ $var ] ) ) {
                    $parent_layer[ $k ] = $parent[ $var ];
                }
            }
            $sections = is_array( $raw['sections'] ?? null ) ? $raw['sections'] : [];
            unset( $raw['sections'] );
            $pruned = self::pruneLayerAgainstParent( $raw, $parent_layer );
            $pruned_sections = [];
            $pt_parent_cfg   = [ 'widths' => array_merge( $widths, [ 'page_types' => array_merge( $page_types, [ $pt_key => $pruned ] ) ] ) ];
            $pt_parent_vars  = self::resolveVars( $pt_parent_cfg, $ctx );
            $pt_parent_layer = [];
            foreach ( self::inheritableWidthKeys() as $k ) {
                $var = '--s2-width-' . str_replace( '_', '-', $k );
                if ( isset( $pt_parent_vars[ $var ] ) ) {
                    $pt_parent_layer[ $k ] = $pt_parent_vars[ $var ];
                }
            }
            foreach ( $sections as $sec => $layer ) {
                if ( ! is_array( $layer ) ) {
                    continue;
                }
                $p = self::pruneLayerAgainstParent( $layer, $pt_parent_layer );
                if ( ! self::isEmptyWidthLayer( $p ) ) {
                    $pruned_sections[ $sec ] = $p;
                }
            }
            $entry = $pruned;
            if ( $pruned_sections !== [] ) {
                $entry['sections'] = $pruned_sections;
            }
            if ( self::isEmptyWidthLayer( $entry ) && ! isset( $entry['sections'] ) ) {
                unset( $page_types[ $pt_key ] );
            } else {
                $page_types[ $pt_key ] = $entry;
            }
        }
        $widths['page_types'] = $page_types;

        $pages = is_array( $widths['pages'] ?? null ) ? $widths['pages'] : [];
        foreach ( array_keys( $pages ) as $slug ) {
            if ( ! is_array( $pages[ $slug ] ?? null ) ) {
                unset( $pages[ $slug ] );
                continue;
            }
            $ctx    = [ 'page_type' => 'page', 'page_slug' => (string) $slug ];
            $below  = $widths;
            $below['pages'] = [];
            $parent = self::resolveVars( [ 'widths' => $below ], $ctx );
            $parent_layer = [];
            foreach ( self::inheritableWidthKeys() as $k ) {
                $var = '--s2-width-' . str_replace( '_', '-', $k );
                if ( isset( $parent[ $var ] ) ) {
                    $parent_layer[ $k ] = $parent[ $var ];
                }
            }
            $pruned = self::pruneLayerAgainstParent( $pages[ $slug ], $parent_layer );
            if ( self::isEmptyWidthLayer( $pruned ) ) {
                unset( $pages[ $slug ] );
            } else {
                $pages[ $slug ] = $pruned;
            }
        }
        $widths['pages'] = $pages;

        $sections = is_array( $widths['sections'] ?? null ) ? $widths['sections'] : [];
        foreach ( array_keys( $sections ) as $sec ) {
            if ( ! is_array( $sections[ $sec ] ?? null ) ) {
                unset( $sections[ $sec ] );
                continue;
            }
            $pruned = self::pruneLayerAgainstParent( $sections[ $sec ], $global );
            if ( self::isEmptyWidthLayer( $pruned ) ) {
                unset( $sections[ $sec ] );
            } else {
                $sections[ $sec ] = $pruned;
            }
        }
        $widths['sections'] = $sections;

        if ( is_array( $widths['service_page'] ?? null ) ) {
            $sp = $widths['service_page'];
            $ctx = [ 'page_type' => 'service', 'page_slug' => 'service' ];
            $below = $widths;
            $below['service_page'] = [ 'sections' => [] ];
            $parent = self::resolveVars( [ 'widths' => $below ], $ctx );
            $parent_layer = [];
            foreach ( self::inheritableWidthKeys() as $k ) {
                $var = '--s2-width-' . str_replace( '_', '-', $k );
                if ( isset( $parent[ $var ] ) ) {
                    $parent_layer[ $k ] = $parent[ $var ];
                }
            }
            $sp_sections = is_array( $sp['sections'] ?? null ) ? $sp['sections'] : [];
            unset( $sp['sections'] );
            $sp = self::pruneLayerAgainstParent( $sp, $parent_layer );
            $pruned_sp_sec = [];
            foreach ( $sp_sections as $sec => $layer ) {
                if ( ! is_array( $layer ) ) {
                    continue;
                }
                $p = self::pruneLayerAgainstParent( $layer, $parent_layer );
                if ( ! self::isEmptyWidthLayer( $p ) ) {
                    $pruned_sp_sec[ $sec ] = $p;
                }
            }
            if ( $pruned_sp_sec !== [] ) {
                $sp['sections'] = $pruned_sp_sec;
            }
            if ( self::isEmptyWidthLayer( $sp ) && ! isset( $sp['sections'] ) ) {
                unset( $widths['service_page'] );
            } else {
                $widths['service_page'] = $sp;
            }
        }

        return $widths;
    }
}
