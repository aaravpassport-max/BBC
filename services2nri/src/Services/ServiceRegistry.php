<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for service/category visibility across the public site.
 *
 * Flow: DB → normalize → surface rules → consumers (nav, API, SEO, sitemap, forms).
 */
class ServiceRegistry {

    public const CACHE_KEY = 'service_registry_catalog_v1';

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

    private static ?bool $schema_ready = null;

    public static function schemaReady(): bool {
        if ( self::$schema_ready !== null ) {
            return self::$schema_ready;
        }
        global $wpdb;
        $p   = $wpdb->prefix;
        $col = $wpdb->get_var( "SHOW COLUMNS FROM `{$p}s2nri_services` LIKE 'public_status'" );
        self::$schema_ready = ! empty( $col );
        return self::$schema_ready;
    }

    public static function bustCache(): void {
        CacheService::delete( self::CACHE_KEY );
        CacheService::bustPattern( 'cats_' );
    }

    /** Default visibility when rules column is empty. */
    public static function defaultVisibilityRules(): array {
        $rules = [];
        foreach ( array_keys( self::SURFACES ) as $surface ) {
            if ( $surface === 'direct_url' ) {
                continue;
            }
            $rules[ $surface ] = true;
        }
        $rules['direct_url'] = 'active';
        return $rules;
    }

    /**
     * Full catalog (admin + internal). Cached 15 minutes; bust on visibility updates.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadCatalog(): array {
        return CacheService::remember( self::CACHE_KEY, 900, function () {
            global $wpdb;
            $p = $wpdb->prefix;

            $extra = self::schemaReady()
                ? ', s.public_status, s.availability, s.visibility_rules, s.direct_url_behavior,
                   c.public_status AS category_public_status, c.visibility_rules AS category_visibility_rules,
                   c.hide_when_empty_children AS category_hide_when_empty'
                : '';

            $rows = $wpdb->get_results(
                "SELECT s.*, c.slug AS category_slug, c.name AS category_name, c.sort_order AS category_sort,
                        c.image_url AS category_image_url, c.icon AS category_icon, c.color AS category_color,
                        c.is_active AS category_is_active
                        {$extra}
                 FROM {$p}s2nri_services s
                 LEFT JOIN {$p}s2nri_categories c ON c.id = s.category_id
                 ORDER BY COALESCE(c.sort_order, 99), s.sort_order, s.name",
                ARRAY_A
            ) ?: [];

            foreach ( $rows as &$row ) {
                $row = self::normalizeServiceRow( $row );
            }
            unset( $row );
            return $rows;
        } );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allServices( bool $include_non_public = false ): array {
        $rows = self::loadCatalog();
        if ( $include_non_public ) {
            return $rows;
        }
        return array_values( array_filter( $rows, fn( $r ) => self::isSelectableStatus( (string) ( $r['public_status'] ?? 'published' ) ) ) );
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
        $direct = $row['direct_url_behavior'] ?? null;
        if ( ! $direct && is_array( $row['visibility_rules'] ) && isset( $row['visibility_rules']['direct_url'] ) ) {
            $direct = $row['visibility_rules']['direct_url'];
        }
        $row['direct_url_behavior'] = $direct ?: 'active';
        if ( empty( $row['category_public_status'] ) ) {
            $row['category_public_status'] = ! empty( $row['category_is_active'] ) ? 'published' : 'disabled';
        }
        return $row;
    }

    public static function isSelectableStatus( string $status ): bool {
        return in_array( $status, [ 'published', 'coming_soon' ], true );
    }

    public static function isVisibleOnSurface( array $service, string $surface ): bool {
        $surface = sanitize_key( $surface );
        $status  = (string) ( $service['public_status'] ?? 'published' );

        if ( in_array( $status, [ 'draft', 'disabled' ], true ) ) {
            return false;
        }

        if ( $status === 'hidden' ) {
            return false;
        }

        $cat_status = (string) ( $service['category_public_status'] ?? 'published' );
        if ( in_array( $cat_status, [ 'hidden', 'disabled', 'draft' ], true ) ) {
            return false;
        }

        $cat_rules = $service['category_visibility_rules'] ?? '';
        if ( is_string( $cat_rules ) && $cat_rules !== '' ) {
            $cat_rules = json_decode( $cat_rules, true );
        }
        if ( is_array( $cat_rules ) && array_key_exists( $surface, $cat_rules ) && $cat_rules[ $surface ] === false ) {
            return false;
        }

        $rules = $service['visibility_rules'] ?? self::defaultVisibilityRules();
        if ( ! is_array( $rules ) ) {
            $rules = self::defaultVisibilityRules();
        }

        if ( $surface === 'forms' && $status === 'coming_soon' ) {
            return false;
        }
        if ( $surface === 'forms' && ( $service['availability'] ?? 'available' ) === 'unavailable' ) {
            return false;
        }

        if ( array_key_exists( $surface, $rules ) && is_bool( $rules[ $surface ] ) ) {
            return (bool) $rules[ $surface ];
        }

        return $status === 'published'
            || ( $status === 'coming_soon' && ! in_array( $surface, [ 'forms', 'search' ], true ) );
    }

    /** @return array<int, array<string, mixed>> */
    public static function forSurface( string $surface, array $opts = [] ): array {
        $surface = sanitize_key( $surface );
        $out     = [];
        foreach ( self::allServices( false ) as $svc ) {
            if ( self::isVisibleOnSurface( $svc, $surface ) ) {
                $out[] = $svc;
            }
        }
        if ( ! empty( $opts['category_slug'] ) ) {
            $slug = sanitize_key( $opts['category_slug'] );
            $out  = array_values( array_filter( $out, fn( $s ) => ( $s['category_slug'] ?? '' ) === $slug ) );
        }
        if ( ! empty( $opts['featured_only'] ) ) {
            $out = array_values( array_filter( $out, fn( $s ) => ! empty( $s['is_featured'] ) ) );
        }
        if ( ! empty( $opts['popular_only'] ) ) {
            $out = array_values( array_filter( $out, fn( $s ) => ! empty( $s['is_popular'] ) ) );
        }

        usort( $out, function ( $a, $b ) {
            $fa = ! empty( $a['is_featured'] ) ? 1 : 0;
            $fb = ! empty( $b['is_featured'] ) ? 1 : 0;
            if ( $fa !== $fb ) {
                return $fb <=> $fa;
            }
            $pa = ! empty( $a['is_popular'] ) ? 1 : 0;
            $pb = ! empty( $b['is_popular'] ) ? 1 : 0;
            if ( $pa !== $pb ) {
                return $pb <=> $pa;
            }
            $so = ( (int) ( $a['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['sort_order'] ?? 0 ) );
            if ( $so !== 0 ) {
                return $so;
            }
            return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
        } );

        if ( ! empty( $opts['limit'] ) ) {
            $out = array_slice( $out, 0, (int) $opts['limit'] );
        }
        return $out;
    }

    /** Public categories with accurate service counts for a given surface. */
    public static function getPublicCategories( string $count_surface = 'directory' ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $cats = $wpdb->get_results(
            "SELECT c.id, c.slug, c.name, c.name_hi, c.description, c.icon, c.color, c.image_url, c.sort_order,
                    c.is_active, c.public_status, c.visibility_rules, c.hide_when_empty_children
             FROM {$p}s2nri_categories c
             WHERE c.is_active = 1
             ORDER BY c.sort_order ASC",
            ARRAY_A
        ) ?: [];

        $visible = self::forSurface( $count_surface );
        $counts  = [];
        foreach ( $visible as $svc ) {
            $cid = (int) ( $svc['category_id'] ?? 0 );
            $counts[ $cid ] = ( $counts[ $cid ] ?? 0 ) + 1;
        }

        $out = [];
        foreach ( $cats as $cat ) {
            if ( self::schemaReady() ) {
                $st = (string) ( $cat['public_status'] ?? 'published' );
                if ( in_array( $st, [ 'hidden', 'disabled', 'draft' ], true ) ) {
                    continue;
                }
            }
            $cid   = (int) $cat['id'];
            $count = $counts[ $cid ] ?? 0;
            if ( self::schemaReady() && ! empty( $cat['hide_when_empty_children'] ) && $count === 0 ) {
                continue;
            }
            $cat['service_count'] = $count;
            $out[] = $cat;
        }
        return $out;
    }

    /** Slugs visible on a surface (related services, manual lists). */
    public static function filterSlugs( array $slugs, string $surface = 'related' ): array {
        $by_slug = [];
        foreach ( self::loadCatalog() as $svc ) {
            $by_slug[ $svc['slug'] ] = $svc;
        }
        $out = [];
        foreach ( $slugs as $slug ) {
            $slug = sanitize_title( (string) $slug );
            if ( ! $slug || ! isset( $by_slug[ $slug ] ) ) {
                continue;
            }
            if ( self::isVisibleOnSurface( $by_slug[ $slug ], $surface ) ) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /**
     * Validate service can be selected in a public form/booking.
     *
     * @return array<string, mixed>|null Service row or null if not bookable.
     */
    public static function getBookableService( int $service_id ): ?array {
        if ( $service_id <= 0 ) {
            return null;
        }
        foreach ( self::loadCatalog() as $svc ) {
            if ( (int) $svc['id'] !== $service_id ) {
                continue;
            }
            if ( ! self::isVisibleOnSurface( $svc, 'forms' ) ) {
                return null;
            }
            if ( (string) ( $svc['public_status'] ?? '' ) !== 'published' ) {
                return null;
            }
            return $svc;
        }
        return null;
    }

    public static function findBySlug( string $slug ): ?array {
        $slug = sanitize_title( $slug );
        foreach ( self::loadCatalog() as $svc ) {
            if ( $svc['slug'] === $slug ) {
                return $svc;
            }
        }
        return null;
    }

    public static function buildNavigationMenu(): array {
        $structure = self::getNavMenuStructure();
        $by_slug   = [];
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

    /** @param array<int, mixed> $structure */
    public static function saveNavMenuStructure( array $structure ): bool {
        \S2NRI\Models\Setting::set( 'nav_menu_structure', wp_json_encode( array_values( $structure ) ), true );
        self::bustCache();
        return true;
    }

    public static function impactPreview( int $service_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $svc = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, c.name AS category_name, c.slug AS category_slug,
                    c.public_status AS category_public_status, c.visibility_rules AS category_visibility_rules
             FROM {$p}s2nri_services s
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
        return [
            'service'           => [ 'id' => (int) $svc['id'], 'name' => $svc['name'], 'slug' => $svc['slug'] ],
            'public_status'     => $svc['public_status'],
            'currently_visible' => $surfaces,
            'forms'             => self::getBookableService( (int) $svc['id'] ) ? 1 : 0,
            'after_hiding'      => [
                'navigation'  => 'Automatically removed from dynamic menus',
                'forms'       => 'Removed from selectors; past bookings preserved',
                'search'      => 'Excluded from search and filters',
                'historical'  => 'All records preserved',
            ],
        ];
    }

    /** @return array{0: int, 1: string, 2: string} [http, action, redirect_url] */
    public static function resolveDirectUrl( string $slug ): array {
        $svc = self::findBySlug( $slug );
        if ( ! $svc ) {
            return [ 404, 'not_found', '' ];
        }

        $behavior = (string) ( $svc['direct_url_behavior'] ?? 'active' );
        $status   = (string) ( $svc['public_status'] ?? 'published' );

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

    public static function shouldIndexInSeo( string $slug ): bool {
        $svc = self::findBySlug( $slug );
        if ( ! $svc ) {
            return false;
        }
        return self::isVisibleOnSurface( $svc, 'seo_index' );
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
        if ( array_key_exists( 'is_featured', $data ) ) {
            $update['is_featured'] = ! empty( $data['is_featured'] ) ? 1 : 0;
        }
        if ( array_key_exists( 'is_popular', $data ) ) {
            $update['is_popular'] = ! empty( $data['is_popular'] ) ? 1 : 0;
        }
        if ( empty( $update ) ) {
            return false;
        }
        $update['updated_at'] = current_time( 'mysql' );
        $ok = (bool) $wpdb->update( $p . 's2nri_services', $update, [ 'id' => $id ] );
        if ( $ok ) {
            self::bustCache();
        }
        return $ok;
    }

    public static function updateCategoryVisibility( int $id, array $data ): bool {
        if ( ! self::schemaReady() ) {
            return false;
        }
        global $wpdb;
        $update = [];
        if ( isset( $data['public_status'] ) ) {
            $st = sanitize_key( (string) $data['public_status'] );
            if ( in_array( $st, self::STATUSES, true ) ) {
                $update['public_status'] = $st;
                $update['is_active']     = in_array( $st, [ 'published', 'coming_soon', 'hidden' ], true ) ? 1 : 0;
            }
        }
        if ( isset( $data['visibility_rules'] ) && is_array( $data['visibility_rules'] ) ) {
            $update['visibility_rules'] = wp_json_encode( $data['visibility_rules'] );
        }
        if ( array_key_exists( 'hide_when_empty_children', $data ) ) {
            $update['hide_when_empty_children'] = ! empty( $data['hide_when_empty_children'] ) ? 1 : 0;
        }
        if ( empty( $update ) ) {
            return false;
        }
        $ok = (bool) $wpdb->update( $wpdb->prefix . 's2nri_categories', $update, [ 'id' => $id ] );
        if ( $ok ) {
            self::bustCache();
        }
        return $ok;
    }

    /** Sanitize section JSON — filter related service slugs. */
    public static function sanitizeSectionContent( array $content, string $section_type ): array {
        if ( $section_type === 'related' && ! empty( $content['slugs'] ) && is_array( $content['slugs'] ) ) {
            $content['slugs'] = self::filterSlugs( $content['slugs'], 'related' );
        }
        return $content;
    }
}
