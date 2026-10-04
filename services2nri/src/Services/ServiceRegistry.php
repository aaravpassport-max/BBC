<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for service/category visibility across the public site.
 *
 * Flow: DB row → public_status + visibility_rules → surface filters → UI components.
 */
class ServiceRegistry {

    public const SURFACES = [
        'nav_top'       => 'Top Navigation',
        'nav_dropdown'  => 'Dropdown / Mega Menu',
        'homepage'      => 'Homepage',
        'directory'     => 'Service Directory',
        'cards'         => 'Service Cards',
        'search'        => 'Search',
        'filters'       => 'Filters',
        'forms'         => 'Forms / Selectors',
        'footer'        => 'Footer Links',
        'related'       => 'Related Services',
        'sitemap'       => 'Sitemap',
        'seo_index'     => 'SEO Index',
        'direct_url'    => 'Direct URL Access',
    ];

    public const STATUSES = [ 'published', 'hidden', 'draft', 'disabled', 'coming_soon' ];

    /** Default visibility when rules column is empty — all surfaces on for published. */
    public static function defaultVisibilityRules(): array {
        $rules = [];
        foreach ( array_keys( self::SURFACES ) as $surface ) {
            $rules[ $surface ] = true;
        }
        $rules['direct_url'] = 'active'; // active | redirect_directory | redirect_home | not_found | unavailable_page
        return $rules;
    }

    /** @return array<int, array<string, mixed>> */
    public static function allServices( bool $include_non_public = false ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT s.*, c.slug AS category_slug, c.name AS category_name, c.sort_order AS category_sort,
                    c.image_url AS category_image_url, c.icon AS category_icon, c.color AS category_color,
                    c.public_status AS category_public_status, c.visibility_rules AS category_visibility_rules,
                    c.is_active AS category_is_active
             FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id
             ORDER BY COALESCE(c.sort_order, 99), s.sort_order, s.name",
            ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row ) {
            $row = self::normalizeServiceRow( $row );
        }
        unset( $row );

        if ( $include_non_public ) {
            return $rows;
        }
        return array_values( array_filter( $rows, fn( $r ) => self::isSelectableStatus( $r['public_status'] ?? 'published' ) ) );
    }

    /** @param array<string, mixed> $row */
    public static function normalizeServiceRow( array $row ): array {
        if ( empty( $row['public_status'] ) ) {
            $row['public_status'] = ! empty( $row['is_active'] ) ? 'published' : 'disabled';
        }
        $rules = $row['visibility_rules'] ?? '';
        if ( is_string( $rules ) && $rules !== '' ) {
            $decoded = json_decode( $rules, true );
            $row['visibility_rules'] = is_array( $decoded ) ? $decoded : self::defaultVisibilityRules();
        } elseif ( ! is_array( $rules ) ) {
            $row['visibility_rules'] = self::defaultVisibilityRules();
        }
        $row['availability'] = $row['availability'] ?? 'available';
        $row['direct_url_behavior'] = $row['direct_url_behavior'] ?? ( $row['visibility_rules']['direct_url'] ?? 'active' );
        return $row;
    }

    public static function isSelectableStatus( string $status ): bool {
        return in_array( $status, [ 'published', 'coming_soon' ], true );
    }

    public static function isVisibleOnSurface( array $service, string $surface ): bool {
        $surface = sanitize_key( $surface );
        $status  = (string) ( $service['public_status'] ?? 'published' );

        if ( $status === 'draft' || $status === 'disabled' ) {
            return false;
        }
        if ( $status === 'hidden' ) {
            return false;
        }

        // Category cascade.
        $cat_status = (string) ( $service['category_public_status'] ?? 'published' );
        if ( $cat_status === 'hidden' || $cat_status === 'disabled' || $cat_status === 'draft' ) {
            return false;
        }
        $cat_rules = $service['category_visibility_rules'] ?? '';
        if ( is_string( $cat_rules ) && $cat_rules !== '' ) {
            $cat_rules = json_decode( $cat_rules, true );
        }
        if ( is_array( $cat_rules ) && isset( $cat_rules[ $surface ] ) && $cat_rules[ $surface ] === false ) {
            return false;
        }

        $rules = $service['visibility_rules'] ?? self::defaultVisibilityRules();
        if ( ! is_array( $rules ) ) {
            $rules = self::defaultVisibilityRules();
        }

        if ( $surface === 'forms' && $status === 'coming_soon' ) {
            return false;
        }
        if ( $surface === 'direct_url' ) {
            return true;
        }
        if ( array_key_exists( $surface, $rules ) && is_bool( $rules[ $surface ] ) ) {
            return (bool) $rules[ $surface ];
        }
        return $status === 'published' || ( $status === 'coming_soon' && ! in_array( $surface, [ 'forms', 'search' ], true ) );
    }

    /** @return array<int, array<string, mixed>> */
    public static function forSurface( string $surface, array $opts = [] ): array {
        $all = self::allServices( false );
        $out = [];
        foreach ( $all as $svc ) {
            if ( self::isVisibleOnSurface( $svc, $surface ) ) {
                $out[] = $svc;
            }
        }
        if ( ! empty( $opts['category_slug'] ) ) {
            $slug = sanitize_key( $opts['category_slug'] );
            $out = array_values( array_filter( $out, fn( $s ) => ( $s['category_slug'] ?? '' ) === $slug ) );
        }
        return $out;
    }

    /**
     * Build top navigation from stored menu structure + live registry.
     * Structure stored in setting nav_menu_structure (JSON); falls back to bundled default.
     *
     * @return array<int, mixed>
     */
    public static function buildNavigationMenu(): array {
        $structure = self::getNavMenuStructure();
        $by_slug = [];
        foreach ( self::allServices( false ) as $svc ) {
            $by_slug[ $svc['slug'] ] = $svc;
        }

        $menu = [];
        foreach ( $structure as $item ) {
            if ( ! empty( $item['link'] ) ) {
                $menu[] = $item;
                continue;
            }
            if ( empty( $item['cols'] ) ) {
                $menu[] = $item;
                continue;
            }
            $cols = [];
            foreach ( $item['cols'] as $col ) {
                $items = [];
                foreach ( $col['items'] ?? [] as $link ) {
                    $slug = sanitize_title( $link['slug'] ?? '' );
                    if ( ! $slug || ! isset( $by_slug[ $slug ] ) ) {
                        continue;
                    }
                    if ( ! self::isVisibleOnSurface( $by_slug[ $slug ], 'nav_dropdown' ) ) {
                        continue;
                    }
                    $items[] = [
                        'label'  => $link['label'] ?? $by_slug[ $slug ]['name'],
                        'slug'   => $slug,
                        'status' => $by_slug[ $slug ]['public_status'] ?? 'published',
                    ];
                }
                if ( empty( $items ) ) {
                    continue;
                }
                $cols[] = [
                    'heading' => $col['heading'] ?? '',
                    'items'   => $items,
                ];
            }
            if ( empty( $cols ) ) {
                continue;
            }
            $menu[] = [
                'label' => $item['label'] ?? '',
                'key'   => $item['key'] ?? '',
                'cols'  => $cols,
            ];
        }
        return $menu;
    }

    /** @return array<int, mixed> */
    public static function getNavMenuStructure(): array {
        $raw = \S2NRI\Models\Setting::get( 'nav_menu_structure', '' );
        if ( $raw ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                return $decoded;
            }
        }
        return self::defaultNavMenuStructure();
    }

    /** Mirrors src/lib/nav.ts — used only to seed settings once. */
    public static function defaultNavMenuStructure(): array {
        $path = S2NRI_DIR . 'data/default-nav-menu.json';
        if ( file_exists( $path ) ) {
            $d = json_decode( file_get_contents( $path ), true );
            if ( is_array( $d ) ) {
                return $d;
            }
        }
        return [];
    }

    public static function seedNavMenuStructureIfMissing(): void {
        if ( \S2NRI\Models\Setting::get( 'nav_menu_structure', '' ) ) {
            return;
        }
        $default = self::defaultNavMenuStructure();
        if ( $default ) {
            \S2NRI\Models\Setting::set( 'nav_menu_structure', wp_json_encode( $default ), false );
        }
    }

    /** Impact summary when changing visibility. */
    public static function impactPreview( int $service_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $svc = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, c.name AS category_name FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id WHERE s.id = %d",
            $service_id
        ), ARRAY_A );
        if ( ! $svc ) {
            return [ 'error' => 'Service not found' ];
        }
        $svc = self::normalizeServiceRow( $svc );
        $surfaces = [];
        foreach ( array_keys( self::SURFACES ) as $surface ) {
            if ( self::isVisibleOnSurface( $svc, $surface ) ) {
                $surfaces[] = self::SURFACES[ $surface ];
            }
        }
        $form_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT service_id) FROM {$p}s2nri_form_fields WHERE service_id = %d AND is_active = 1",
            $service_id
        ) );
        return [
            'service'           => [ 'id' => (int) $svc['id'], 'name' => $svc['name'], 'slug' => $svc['slug'] ],
            'public_status'     => $svc['public_status'],
            'currently_visible' => $surfaces,
            'forms'             => $form_count > 0 ? 1 : 0,
            'historical_note'   => 'Historical bookings and records are preserved when visibility changes.',
        ];
    }

    /** Handle direct URL access for a service slug. Returns [http_status, action, redirect_url?]. */
    public static function resolveDirectUrl( string $slug ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $svc = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, c.public_status AS category_public_status, c.visibility_rules AS category_visibility_rules
             FROM {$p}s2nri_services s
             LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id
             WHERE s.slug = %s LIMIT 1",
            sanitize_title( $slug )
        ), ARRAY_A );
        if ( ! $svc ) {
            return [ 404, 'not_found', '' ];
        }
        $svc = self::normalizeServiceRow( $svc );
        $behavior = (string) ( $svc['direct_url_behavior'] ?? 'active' );
        if ( is_array( $svc['visibility_rules'] ) && isset( $svc['visibility_rules']['direct_url'] ) ) {
            $behavior = (string) $svc['visibility_rules']['direct_url'];
        }

        $status = (string) ( $svc['public_status'] ?? 'published' );
        if ( in_array( $status, [ 'published', 'coming_soon' ], true ) ) {
            return [ 200, 'active', '' ];
        }

        switch ( $behavior ) {
            case 'redirect_directory':
                return [ 302, 'redirect', home_url( '/services' ) ];
            case 'redirect_home':
                return [ 302, 'redirect', home_url( '/' ) ];
            case 'redirect_category':
                $cat = $svc['category_slug'] ?? '';
                return [ 302, 'redirect', $cat ? home_url( '/services/' . $cat ) : home_url( '/services' ) ];
            case 'unavailable_page':
                return [ 200, 'unavailable', '' ];
            case 'active':
                return [ 200, 'active', '' ];
            case 'not_found':
            default:
                return [ 404, 'not_found', '' ];
        }
    }

    public static function updateServiceVisibility( int $id, array $data ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        $update = [];
        if ( isset( $data['public_status'] ) ) {
            $st = sanitize_key( (string) $data['public_status'] );
            if ( in_array( $st, self::STATUSES, true ) ) {
                $update['public_status'] = $st;
                $update['is_active']     = in_array( $st, [ 'published', 'coming_soon', 'hidden' ], true ) ? 1 : 0;
            }
        }
        if ( isset( $data['availability'] ) ) {
            $update['availability'] = sanitize_key( (string) $data['availability'] );
        }
        if ( isset( $data['direct_url_behavior'] ) ) {
            $update['direct_url_behavior'] = sanitize_key( (string) $data['direct_url_behavior'] );
        }
        if ( isset( $data['visibility_rules'] ) && is_array( $data['visibility_rules'] ) ) {
            $rules = array_merge( self::defaultVisibilityRules(), $data['visibility_rules'] );
            $update['visibility_rules'] = wp_json_encode( $rules );
        }
        if ( empty( $update ) ) {
            return false;
        }
        $update['updated_at'] = current_time( 'mysql' );
        return (bool) $wpdb->update( $p . 's2nri_services', $update, [ 'id' => $id ] );
    }
}
