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
        return $merged;
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
                    'background' => '#F8FAFC', 'surface' => '#FFFFFF', 'heading' => '#0F172A', 'body' => '#334155',
                ],
                'fonts' => [ 'heading' => 'plus-jakarta-sans', 'body' => 'inter', 'ui' => 'inter', 'button' => 'plus-jakarta-sans' ],
                'radius' => [ 'md' => '12px', 'lg' => '16px' ],
                'shadow' => [ 'card' => '0 8px 30px rgba(15,23,42,.08)' ],
            ],
            'premium' => [
                'colors' => [
                    'primary' => '#1E3A5F', 'primary_hover' => '#152A45', 'accent' => '#C9A227',
                    'background' => '#FAFAF9', 'surface' => '#FFFFFF', 'heading' => '#1C1917', 'body' => '#44403C',
                ],
                'fonts' => [ 'heading' => 'playfair-display', 'body' => 'source-sans-3', 'ui' => 'source-sans-3', 'button' => 'montserrat' ],
                'radius' => [ 'md' => '10px', 'lg' => '20px' ],
            ],
            'corporate' => [
                'colors' => [
                    'primary' => '#004E89', 'primary_hover' => '#003D6B', 'accent' => '#00A8E8',
                    'background' => '#F4F6F8', 'heading' => '#1A1A2E', 'body' => '#3D3D56',
                ],
                'fonts' => [ 'heading' => 'ibm-plex-sans', 'body' => 'ibm-plex-sans', 'ui' => 'ibm-plex-sans', 'button' => 'ibm-plex-sans' ],
            ],
            'elegant' => [
                'colors' => [
                    'primary' => '#2C3E50', 'accent' => '#8E6C88', 'background' => '#FFFBF7',
                    'heading' => '#1A1A1A', 'body' => '#4A4A4A',
                ],
                'fonts' => [ 'heading' => 'cormorant-garamond', 'body' => 'lora', 'ui' => 'karla', 'button' => 'montserrat' ],
            ],
            'vibrant' => [
                'colors' => [
                    'primary' => '#7C3AED', 'primary_hover' => '#6D28D9', 'accent' => '#F97316',
                    'background' => '#FAF5FF', 'heading' => '#1E1B4B', 'body' => '#312E81',
                ],
                'fonts' => [ 'heading' => 'outfit', 'body' => 'figtree', 'ui' => 'figtree', 'button' => 'outfit' ],
            ],
            'minimal' => [
                'colors' => [
                    'primary' => '#18181B', 'primary_hover' => '#09090B', 'accent' => '#71717A',
                    'background' => '#FFFFFF', 'surface' => '#FAFAFA', 'border' => '#E4E4E7',
                ],
                'fonts' => [ 'heading' => 'inter', 'body' => 'inter', 'ui' => 'inter', 'button' => 'inter' ],
                'radius' => [ 'md' => '6px', 'lg' => '8px' ],
                'shadow' => [ 'card' => '0 1px 3px rgba(0,0,0,.06)' ],
            ],
            'professional' => [
                'colors' => [
                    'primary' => '#4A6FA5', 'primary_hover' => '#3D5D8A', 'accent' => '#E8A838',
                    'background' => '#EBF0F8', 'surface' => '#FFFFFF', 'heading' => '#1E2D40', 'body' => '#334155',
                ],
                'fonts' => [ 'heading' => 'montserrat', 'body' => 'open-sans', 'ui' => 'open-sans', 'button' => 'montserrat' ],
            ],
            'travel' => [
                'colors' => [
                    'primary' => '#0D9488', 'primary_hover' => '#0F766E', 'accent' => '#F59E0B',
                    'background' => '#F0FDFA', 'heading' => '#134E4A', 'body' => '#115E59',
                ],
                'fonts' => [ 'heading' => 'nunito', 'body' => 'nunito-sans', 'ui' => 'nunito-sans', 'button' => 'nunito' ],
            ],
            'government' => [
                'colors' => [
                    'primary' => '#005EA2', 'primary_hover' => '#004578', 'accent' => '#1DC2AE',
                    'background' => '#F0F0F0', 'heading' => '#1B1B1B', 'body' => '#3D4551',
                ],
                'fonts' => [ 'heading' => 'public-sans', 'body' => 'public-sans', 'ui' => 'public-sans', 'button' => 'public-sans' ],
            ],
            'marketplace' => [
                'colors' => [
                    'primary' => '#2563EB', 'accent' => '#10B981', 'card' => '#FFFFFF',
                    'background' => '#F1F5F9', 'heading' => '#0F172A',
                ],
                'spacing' => [ 'section_y' => '72px', 'grid_gap' => '20px', 'container_max' => '1200px', 'content_max' => '760px' ],
                'fonts' => [ 'heading' => 'poppins', 'body' => 'roboto', 'ui' => 'roboto', 'button' => 'poppins' ],
            ],
        ];
        return $patches[ $p ] ?? [];
    }
}
