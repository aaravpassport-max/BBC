<?php
namespace S2NRI\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Ready-made design presets — merged into DesignSystem defaults on apply.
 */
class DesignPresets {

    public static function list(): array {
        return [
            'modern'        => [ 'label' => 'Modern', 'description' => 'Clean SaaS with vibrant primary and generous spacing.' ],
            'premium'       => [ 'label' => 'Premium', 'description' => 'Refined contrast, soft shadows, elegant serif accents.' ],
            'corporate'     => [ 'label' => 'Corporate', 'description' => 'Trustworthy blues, tight grids, accessible type.' ],
            'elegant'       => [ 'label' => 'Elegant', 'description' => 'Serif headings, muted palette, generous whitespace.' ],
            'vibrant'       => [ 'label' => 'Vibrant', 'description' => 'Energetic accent, bold headings, lively gradients.' ],
            'minimal'       => [ 'label' => 'Minimal', 'description' => 'Neutral surfaces, subtle borders, focus on content.' ],
            'professional'  => [ 'label' => 'Professional', 'description' => 'Balanced defaults suitable for service marketplaces.' ],
            'travel'        => [ 'label' => 'Travel', 'description' => 'Warm accent, friendly rounded UI, adventure CTAs.' ],
            'government'    => [ 'label' => 'Government / Documentation', 'description' => 'High readability, Public Sans–style clarity.' ],
            'marketplace'   => [ 'label' => 'Marketplace', 'description' => 'Card-heavy layout tokens tuned for service grids.' ],
        ];
    }

    /** Full theme slice for apply (defaults + preset patch). */
    public static function expanded( string $preset_id ): array {
        $defaults = DesignSystem::defaults();
        $patch    = self::patch( $preset_id );
        $merged   = self::deepMergePresets( $defaults, $patch );
        if ( ! empty( $merged['colors']['primary'] ) && empty( $patch['colors']['primary_hover'] ?? null ) ) {
            $merged['colors']['primary_hover'] = $merged['colors']['primary_hover'] ?? $merged['colors']['primary'];
        }
        if ( ! empty( $merged['colors']['primary'] ) ) {
            $merged['colors']['link']       = $merged['colors']['link'] ?? $merged['colors']['primary'];
            $merged['colors']['link_hover'] = $merged['colors']['link_hover'] ?? ( $merged['colors']['primary_hover'] ?? $merged['colors']['primary'] );
        }
        self::finalizePalette( $merged, $patch );
        return $merged;
    }

    /**
     * Fill companion color tokens so chrome, surfaces, and typography refs match each preset (not plugin defaults).
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $patch
     */
    private static function finalizePalette( array &$merged, array $patch ): void {
        $patchColors = is_array( $patch['colors'] ?? null ) ? $patch['colors'] : [];
        $colors      = &$merged['colors'];
        if ( ! is_array( $colors ) ) {
            return;
        }

        if ( empty( $patchColors['secondary'] ) ) {
            $colors['secondary'] = $colors['heading'] ?? $colors['primary'] ?? '#1E2D40';
        }
        if ( empty( $patchColors['secondary_hover'] ) && ! empty( $colors['secondary'] ) ) {
            $colors['secondary_hover'] = $colors['secondary_hover'] ?? $colors['secondary'];
        }
        if ( empty( $patchColors['surface'] ) && ! empty( $colors['background'] ) ) {
            $colors['surface'] = $colors['surface'] ?? '#FFFFFF';
        }
        if ( empty( $patchColors['surface_alt'] ) && ! empty( $colors['background'] ) ) {
            $colors['surface_alt'] = $colors['background'];
        }
        if ( empty( $patchColors['card'] ) ) {
            $colors['card'] = $colors['card'] ?? ( $colors['surface'] ?? '#FFFFFF' );
        }
        if ( empty( $patchColors['divider'] ) && ! empty( $colors['border'] ) ) {
            $colors['divider'] = $colors['border'];
        } elseif ( empty( $patchColors['divider'] ) && ! empty( $colors['surface_alt'] ) ) {
            $colors['divider'] = $colors['surface_alt'];
        }
        if ( empty( $patchColors['overlay'] ) && ! empty( $colors['heading'] ) ) {
            $colors['overlay'] = 'rgba(15, 23, 42, 0.55)';
        }
        if ( empty( $patchColors['shadow'] ) ) {
            $colors['shadow'] = $colors['shadow'] ?? 'rgba(15, 23, 42, 0.12)';
        }
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $patch */
    private static function deepMergePresets( array $base, array $patch ): array {
        $out = $base;
        foreach ( $patch as $k => $v ) {
            if ( is_array( $v ) && isset( $out[ $k ] ) && is_array( $out[ $k ] ) ) {
                $out[ $k ] = self::deepMergePresets( $out[ $k ], $v );
            } else {
                $out[ $k ] = $v;
            }
        }
        return $out;
    }

    /** Partial design config patches keyed by preset id. */
    public static function patch( string $preset_id ): array {
        $p = sanitize_key( $preset_id );
        $patches = [
            'modern' => [
                'colors' => [
                    'primary' => '#2563EB', 'primary_hover' => '#1D4ED8', 'accent' => '#06B6D4',
                    'background' => '#F8FAFC', 'surface' => '#FFFFFF', 'surface_alt' => '#EFF6FF',
                    'heading' => '#0F172A', 'body' => '#334155', 'muted' => '#64748B', 'border' => '#E2E8F0',
                    'secondary' => '#0F172A', 'secondary_hover' => '#020617',
                ],
                'fonts' => [ 'heading' => 'plus-jakarta-sans', 'body' => 'inter', 'ui' => 'inter', 'button' => 'plus-jakarta-sans' ],
                'spacing' => [ 'section_y' => '72px', 'grid_gap' => '24px' ],
                'radius' => [ 'md' => '12px', 'lg' => '16px', 'pill' => '999px' ],
                'shadow' => [ 'card' => '0 8px 30px rgba(15,23,42,.08)' ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '2.875rem', 'size_tablet' => '2.375rem', 'size_mobile' => '2rem' ],
                    'h1'         => [ 'size_desktop' => '2.875rem', 'size_tablet' => '2.375rem', 'size_mobile' => '2rem' ],
                ],
            ],
            'premium' => [
                'colors' => [
                    'primary' => '#1E3A5F', 'primary_hover' => '#152A45', 'accent' => '#C9A227',
                    'background' => '#FAFAF9', 'surface' => '#FFFFFF', 'surface_alt' => '#F5F5F4',
                    'heading' => '#1C1917', 'body' => '#44403C', 'muted' => '#78716C', 'border' => '#E7E5E4',
                    'secondary' => '#152A45', 'secondary_hover' => '#0F1F33',
                ],
                'fonts' => [ 'heading' => 'playfair-display', 'body' => 'source-sans-3', 'ui' => 'source-sans-3', 'button' => 'montserrat' ],
                'spacing' => [ 'section_y' => '80px', 'grid_gap' => '28px' ],
                'radius' => [ 'md' => '10px', 'lg' => '20px', 'pill' => '999px' ],
                'shadow' => [ 'card' => '0 12px 40px rgba(28,25,23,.10)' ],
                'chrome' => [
                    'global' => [
                        'footer_heading_text' => '#C9A227',
                    ],
                ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '3rem', 'size_tablet' => '2.5rem', 'size_mobile' => '2rem' ],
                    'h1'         => [ 'size_desktop' => '3rem', 'size_tablet' => '2.5rem', 'size_mobile' => '2rem' ],
                ],
            ],
            'corporate' => [
                'colors' => [
                    'primary' => '#004E89', 'primary_hover' => '#003D6B', 'accent' => '#00A8E8',
                    'background' => '#F4F6F8', 'surface' => '#FFFFFF', 'surface_alt' => '#E8EDF2',
                    'heading' => '#1A1A2E', 'body' => '#3D3D56', 'muted' => '#5C5C7A', 'border' => '#D1D9E0',
                    'secondary' => '#1A1A2E', 'secondary_hover' => '#0D0D16',
                ],
                'fonts' => [ 'heading' => 'ibm-plex-sans', 'body' => 'ibm-plex-sans', 'ui' => 'ibm-plex-sans', 'button' => 'ibm-plex-sans' ],
                'spacing' => [ 'section_y' => '56px', 'grid_gap' => '16px', 'container_max' => '1140px' ],
                'radius' => [ 'md' => '8px', 'lg' => '12px', 'pill' => '8px' ],
                'shadow' => [ 'card' => '0 4px 20px rgba(26,26,46,.08)' ],
                'chrome' => [ 'global' => [ 'header_variant' => 'compact', 'header_height_px' => '60' ] ],
                'typography' => [
                    'h1' => [ 'size_desktop' => '2.375rem', 'size_tablet' => '2.125rem', 'size_mobile' => '1.75rem' ],
                    'h2' => [ 'size_desktop' => '1.75rem', 'size_tablet' => '1.5rem', 'size_mobile' => '1.375rem' ],
                ],
            ],
            'elegant' => [
                'colors' => [
                    'primary' => '#2C3E50', 'primary_hover' => '#1A252F', 'accent' => '#8E6C88',
                    'background' => '#FFFBF7', 'surface' => '#FFFFFF', 'surface_alt' => '#F7F0EA',
                    'heading' => '#1A1A1A', 'body' => '#4A4A4A', 'muted' => '#6B6B6B', 'border' => '#E8DFD6',
                    'secondary' => '#2C3E50', 'secondary_hover' => '#1A252F',
                ],
                'fonts' => [ 'heading' => 'cormorant-garamond', 'body' => 'lora', 'ui' => 'karla', 'button' => 'montserrat' ],
                'spacing' => [ 'section_y' => '88px', 'grid_gap' => '28px' ],
                'radius' => [ 'md' => '12px', 'lg' => '18px', 'pill' => '999px' ],
                'shadow' => [ 'card' => '0 10px 36px rgba(44,62,80,.07)' ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '3.25rem', 'size_tablet' => '2.625rem', 'size_mobile' => '2.125rem' ],
                    'h1'         => [ 'size_desktop' => '3.25rem', 'size_tablet' => '2.625rem', 'size_mobile' => '2.125rem' ],
                    'section_heading' => [ 'size_desktop' => '2.125rem' ],
                ],
            ],
            'vibrant' => [
                'colors' => [
                    'primary' => '#7C3AED', 'primary_hover' => '#6D28D9', 'accent' => '#F97316',
                    'background' => '#FAF5FF', 'surface' => '#FFFFFF', 'surface_alt' => '#F3E8FF',
                    'heading' => '#1E1B4B', 'body' => '#312E81', 'muted' => '#6B7280', 'border' => '#E9D5FF',
                    'secondary' => '#1E1B4B', 'secondary_hover' => '#0F0D29',
                ],
                'fonts' => [ 'heading' => 'outfit', 'body' => 'figtree', 'ui' => 'figtree', 'button' => 'outfit' ],
                'spacing' => [ 'section_y' => '68px', 'grid_gap' => '22px' ],
                'radius' => [ 'md' => '14px', 'lg' => '22px', 'pill' => '999px' ],
                'shadow' => [ 'card' => '0 10px 40px rgba(124,58,237,.12)' ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '3rem', 'size_tablet' => '2.5rem', 'size_mobile' => '2rem' ],
                    'h1'         => [ 'size_desktop' => '3rem', 'size_tablet' => '2.5rem', 'size_mobile' => '2rem' ],
                ],
            ],
            'minimal' => [
                'colors' => [
                    'primary' => '#18181B', 'primary_hover' => '#09090B', 'accent' => '#71717A',
                    'background' => '#FFFFFF', 'surface' => '#FAFAFA', 'surface_alt' => '#F4F4F5',
                    'heading' => '#18181B', 'body' => '#3F3F46', 'muted' => '#71717A', 'border' => '#E4E4E7',
                    'secondary' => '#18181B', 'secondary_hover' => '#09090B',
                ],
                'fonts' => [ 'heading' => 'inter', 'body' => 'inter', 'ui' => 'inter', 'button' => 'inter' ],
                'spacing' => [ 'section_y' => '56px', 'grid_gap' => '16px' ],
                'radius' => [ 'md' => '6px', 'lg' => '8px', 'pill' => '8px' ],
                'shadow' => [ 'card' => '0 1px 3px rgba(0,0,0,.06)' ],
                'chrome' => [
                    'global' => [
                        'header_variant' => 'compact',
                        'footer_variant' => 'minimal',
                        'header_height_px' => '56',
                    ],
                ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '2.25rem', 'size_tablet' => '2rem', 'size_mobile' => '1.75rem' ],
                    'h1'         => [ 'size_desktop' => '2.25rem', 'size_tablet' => '2rem', 'size_mobile' => '1.75rem' ],
                    'section_heading' => [ 'size_desktop' => '1.625rem' ],
                ],
            ],
            'professional' => [
                'colors' => [
                    'primary' => '#4A6FA5', 'primary_hover' => '#3D5D8A', 'accent' => '#E8A838',
                    'background' => '#EBF0F8', 'surface' => '#FFFFFF', 'surface_alt' => '#EBF0F8',
                    'heading' => '#1E2D40', 'body' => '#334155', 'muted' => '#64748B', 'border' => '#E2E8F0',
                    'secondary' => '#1E2D40', 'secondary_hover' => '#152030',
                ],
                'fonts' => [ 'heading' => 'montserrat', 'body' => 'open-sans', 'ui' => 'open-sans', 'button' => 'montserrat' ],
                'spacing' => [ 'section_y' => '64px', 'grid_gap' => '20px' ],
                'radius' => [ 'md' => '10px', 'lg' => '16px', 'pill' => '999px' ],
            ],
            'travel' => [
                'colors' => [
                    'primary' => '#0D9488', 'primary_hover' => '#0F766E', 'accent' => '#F59E0B',
                    'background' => '#F0FDFA', 'surface' => '#FFFFFF', 'surface_alt' => '#CCFBF1',
                    'heading' => '#134E4A', 'body' => '#115E59', 'muted' => '#5F8A87', 'border' => '#99F6E4',
                    'secondary' => '#134E4A', 'secondary_hover' => '#0F3D38',
                ],
                'fonts' => [ 'heading' => 'nunito', 'body' => 'nunito-sans', 'ui' => 'nunito-sans', 'button' => 'nunito' ],
                'spacing' => [ 'section_y' => '72px', 'grid_gap' => '24px' ],
                'radius' => [ 'md' => '12px', 'lg' => '20px', 'pill' => '999px' ],
                'shadow' => [ 'card' => '0 8px 28px rgba(13,148,136,.10)' ],
                'typography' => [
                    'page_title' => [ 'size_desktop' => '2.625rem' ],
                    'button'     => [ 'size_desktop' => '1rem' ],
                ],
            ],
            'government' => [
                'colors' => [
                    'primary' => '#005EA2', 'primary_hover' => '#004578', 'accent' => '#1DC2AE',
                    'background' => '#F0F0F0', 'surface' => '#FFFFFF', 'surface_alt' => '#E6E6E6',
                    'heading' => '#1B1B1B', 'body' => '#3D4551', 'muted' => '#565C65', 'border' => '#D6D6D6',
                    'secondary' => '#1B1B1B', 'secondary_hover' => '#0F0F0F',
                ],
                'fonts' => [ 'heading' => 'public-sans', 'body' => 'public-sans', 'ui' => 'public-sans', 'button' => 'public-sans' ],
                'spacing' => [ 'section_y' => '56px', 'grid_gap' => '16px', 'container_max' => '1120px' ],
                'radius' => [ 'md' => '4px', 'lg' => '6px', 'pill' => '4px' ],
                'shadow' => [ 'card' => '0 2px 8px rgba(27,27,27,.08)' ],
                'chrome' => [ 'global' => [ 'header_variant' => 'standard', 'footer_variant' => 'full' ] ],
                'typography' => [
                    'body' => [ 'size_desktop' => '1.0625rem', 'line_height' => '1.65' ],
                    'h1'   => [ 'size_desktop' => '2.375rem', 'size_tablet' => '2.125rem', 'size_mobile' => '1.75rem' ],
                ],
            ],
            'marketplace' => [
                'colors' => [
                    'primary' => '#2563EB', 'primary_hover' => '#1D4ED8', 'accent' => '#10B981',
                    'card' => '#FFFFFF', 'background' => '#F1F5F9', 'surface' => '#FFFFFF', 'surface_alt' => '#E2E8F0',
                    'heading' => '#0F172A', 'body' => '#334155', 'muted' => '#64748B', 'border' => '#CBD5E1',
                    'secondary' => '#0F172A', 'secondary_hover' => '#020617',
                ],
                'spacing' => [ 'section_y' => '72px', 'grid_gap' => '20px', 'container_max' => '1200px', 'content_max' => '760px' ],
                'fonts' => [ 'heading' => 'poppins', 'body' => 'roboto', 'ui' => 'roboto', 'button' => 'poppins' ],
                'widths' => [
                    'global' => [
                        'page_max'         => [ 'desktop' => '1200px', 'laptop' => '1200px', 'tablet' => '94%', 'mobile' => '100%' ],
                        'section_standard' => [ 'desktop' => '1100px', 'laptop' => '1100px', 'tablet' => '94%', 'mobile' => '100%' ],
                    ],
                ],
                'typography' => [
                    'card_title' => [ 'size_desktop' => '1.0625rem' ],
                    'h2'         => [ 'size_desktop' => '1.875rem' ],
                ],
            ],
        ];
        return $patches[ $p ] ?? [];
    }
}
