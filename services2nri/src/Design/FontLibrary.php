<?php
namespace S2NRI\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Curated Google Font library (100+ families) for admin typography pickers.
 */
class FontLibrary {

    private static ?array $cache = null;

    public static function all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        $path = S2NRI_DIR . 'data/font-library.json';
        if ( ! file_exists( $path ) ) {
            self::$cache = [];
            return self::$cache;
        }
        $raw = json_decode( file_get_contents( $path ), true );
        self::$cache = is_array( $raw['fonts'] ?? null ) ? $raw['fonts'] : [];
        return self::$cache;
    }

    public static function find( string $id ): ?array {
        foreach ( self::all() as $font ) {
            if ( ( $font['id'] ?? '' ) === $id ) {
                return $font;
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function search( string $query = '', ?string $category = null ): array {
        $q = strtolower( trim( $query ) );
        $out = [];
        foreach ( self::all() as $font ) {
            if ( $category && strcasecmp( (string) ( $font['category'] ?? '' ), $category ) !== 0 ) {
                continue;
            }
            if ( $q !== '' ) {
                $hay = strtolower( ( $font['name'] ?? '' ) . ' ' . ( $font['category'] ?? '' ) . ' ' . ( $font['pairing'] ?? '' ) );
                if ( strpos( $hay, $q ) === false ) {
                    continue;
                }
            }
            $out[] = $font;
        }
        return $out;
    }

    public static function categories(): array {
        $cats = [];
        foreach ( self::all() as $font ) {
            $c = (string) ( $font['category'] ?? 'Other' );
            $cats[ $c ] = true;
        }
        ksort( $cats );
        return array_keys( $cats );
    }

    public static function googleCssUrl( array $families, string $display = 'swap' ): string {
        $parts = [];
        foreach ( array_unique( array_filter( $families ) ) as $fam ) {
            $font = is_string( $fam ) && strpos( $fam, '+' ) !== false
                ? $fam
                : ( self::find( (string) $fam )['google_family'] ?? str_replace( ' ', '+', (string) $fam ) );
            if ( $font ) {
                $parts[] = 'family=' . rawurlencode( str_replace( '+', ' ', $font ) ) . ':wght@300;400;500;600;700';
            }
        }
        if ( empty( $parts ) ) {
            return '';
        }
        return 'https://fonts.googleapis.com/css2?' . implode( '&', $parts ) . '&display=' . rawurlencode( $display );
    }

    public static function stackFor( string $font_id, string $fallback = 'system-ui, sans-serif' ): string {
        $font = self::find( $font_id );
        if ( ! $font ) {
            return $fallback;
        }
        $name = $font['name'];
        $stack = (string) ( $font['stack'] ?? 'sans-serif' );
        $generic = in_array( $stack, [ 'serif', 'monospace', 'sans-serif' ], true ) ? $stack : 'sans-serif';
        return "'{$name}', {$generic}, {$fallback}";
    }
}
