<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Public visibility for non-service catalog entities (§34).
 */
class PublicEntityRegistry {

    public const CITY_SURFACES = [
        'homepage'  => 'Homepage city grid',
        'directory' => 'City directory / API list',
        'sitemap'   => 'Sitemap',
        'seo_index' => 'SEO index',
        'direct_url'=> 'Direct city landing URL',
    ];

    /** @return array<string, string> */
    public static function types(): array {
        return [
            'services'   => 'Services (ServiceRegistry)',
            'categories' => 'Service categories',
            'cities'     => 'City landing pages',
            'visa'       => 'Visa / immigration services (category-scoped)',
            'vendors'    => 'Vendors (staff-only; never public catalog)',
        ];
    }

    public static function citySchemaReady(): bool {
        global $wpdb;
        static $ready = null;
        if ( $ready !== null ) {
            return $ready;
        }
        $col = $wpdb->get_var( "SHOW COLUMNS FROM `{$wpdb->prefix}s2nri_cities` LIKE 'public_status'" );
        $ready = ! empty( $col );
        return $ready;
    }

    /** @return array<string, bool|string> */
    public static function defaultCityVisibilityRules(): array {
        $rules = [];
        foreach ( array_keys( self::CITY_SURFACES ) as $surface ) {
            $rules[ $surface ] = $surface === 'direct_url' ? 'active' : true;
        }
        return $rules;
    }

    /** @param array<string, mixed> $row */
    public static function normalizeCityRow( array $row ): array {
        if ( empty( $row['public_status'] ) ) {
            $row['public_status'] = ! empty( $row['is_active'] ) ? 'published' : 'disabled';
        }
        $rules = $row['visibility_rules'] ?? '';
        if ( is_string( $rules ) && $rules !== '' ) {
            $decoded = json_decode( $rules, true );
            $row['visibility_rules'] = is_array( $decoded ) ? $decoded : self::defaultCityVisibilityRules();
        } elseif ( ! is_array( $rules ) ) {
            $row['visibility_rules'] = self::defaultCityVisibilityRules();
        }
        return $row;
    }

    /** @param array<string, mixed> $city */
    public static function isCityVisibleOnSurface( array $city, string $surface ): bool {
        $surface = sanitize_key( $surface );
        $city    = self::normalizeCityRow( $city );
        $status  = (string) ( $city['public_status'] ?? 'published' );
        if ( in_array( $status, [ 'draft', 'disabled', 'hidden' ], true ) ) {
            return false;
        }
        $rules = $city['visibility_rules'] ?? self::defaultCityVisibilityRules();
        if ( is_array( $rules ) && array_key_exists( $surface, $rules ) ) {
            $v = $rules[ $surface ];
            if ( is_bool( $v ) ) {
                return $v;
            }
        }
        return $status === 'published';
    }

    /** @return array<int, array<string, mixed>> */
    public static function publicCities( string $surface = 'directory' ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}s2nri_cities ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ( $rows as $row ) {
            $row = self::normalizeCityRow( $row );
            if ( self::isCityVisibleOnSurface( $row, $surface ) ) {
                unset( $row['visibility_rules'] );
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function updateCityVisibility( int $id, array $data ): bool {
        if ( ! self::citySchemaReady() ) {
            return false;
        }
        global $wpdb;
        $update = [];
        if ( isset( $data['public_status'] ) ) {
            $st = sanitize_key( (string) $data['public_status'] );
            if ( in_array( $st, ServiceRegistry::STATUSES, true ) ) {
                $update['public_status'] = $st;
                $update['is_active']     = in_array( $st, [ 'published', 'coming_soon', 'hidden' ], true ) ? 1 : 0;
            }
        }
        if ( isset( $data['visibility_rules'] ) && is_array( $data['visibility_rules'] ) ) {
            $update['visibility_rules'] = wp_json_encode(
                array_merge( self::defaultCityVisibilityRules(), $data['visibility_rules'] )
            );
        }
        if ( empty( $update ) ) {
            return false;
        }
        $ok = (bool) $wpdb->update( $wpdb->prefix . 's2nri_cities', $update, [ 'id' => $id ] );
        if ( $ok ) {
            CacheService::delete( 'cities_active' );
            ServiceRegistry::bustCache();
        }
        return $ok;
    }

    /** Immigration / visa-related services visible on forms. */
    public static function visaServices(): array {
        $rows = ServiceRegistry::forSurface( 'forms' );
        return array_values( array_filter( $rows, function ( $s ) {
            $slug = (string) ( $s['category_slug'] ?? '' );
            return in_array( $slug, [ 'immigration', 'visa', 'documentation' ], true )
                || str_contains( strtolower( (string) ( $s['name'] ?? '' ) ), 'visa' );
        } ) );
    }
}
