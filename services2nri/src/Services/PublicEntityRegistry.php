<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Public visibility for non-service catalog entities (§34).
 * Services remain authoritative via ServiceRegistry; this documents and gates aux entities.
 */
class PublicEntityRegistry {

    /** @return array<string, string> */
    public static function types(): array {
        return [
            'services'  => 'Services (ServiceRegistry)',
            'categories'=> 'Service categories',
            'cities'    => 'City landing pages',
            'visa'      => 'Visa / immigration services (category-scoped)',
            'vendors'   => 'Vendors (staff-only; never public catalog)',
        ];
    }

    /** Active cities for homepage / sitemap. */
    public static function publicCities(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, slug, name, image_url, sort_order FROM {$wpdb->prefix}s2nri_cities
             WHERE is_active = 1 ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
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
