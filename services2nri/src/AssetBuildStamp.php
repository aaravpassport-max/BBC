<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * Unified cache-bust stamp for all SPA assets from one Vite build.
 */
final class AssetBuildStamp {

    private const STAMP_FILE = 'assets/BUILD_STAMP.txt';

    /** @return list<string> Relative paths under plugin root included in the stamp. */
    public static function assetPaths(): array {
        $paths = [
            'assets/app.js',
            'assets/app.css',
            'assets/boot-config.js',
            'assets/boot-watchdog.js',
            'assets/boot-sw-cleanup.js',
        ];
        $chunk_dir = S2NRI_DIR . 'assets/chunks/';
        if ( is_dir( $chunk_dir ) ) {
            $names = glob( $chunk_dir . '*.js' ) ?: [];
            sort( $names, SORT_STRING );
            foreach ( $names as $abs ) {
                $paths[] = 'assets/chunks/' . basename( $abs );
            }
        }
        return $paths;
    }

    /** Fingerprint currently on disk (same algorithm as scripts/write-build-stamp.mjs). */
    public static function computeFromDisk(): string {
        $parts = [];
        foreach ( self::assetPaths() as $rel ) {
            $abs = S2NRI_DIR . $rel;
            if ( ! is_file( $abs ) ) {
                return '';
            }
            $parts[] = md5_file( $abs );
        }
        if ( $parts === [] ) {
            return '';
        }
        return substr( md5( implode( ':', $parts ) ), 0, 12 );
    }

    /** Stamp written at build time, or empty if missing. */
    public static function readRecorded(): string {
        $file = S2NRI_DIR . self::STAMP_FILE;
        if ( ! is_file( $file ) ) {
            return '';
        }
        $stamp = trim( (string) file_get_contents( $file ) );
        return preg_match( '/^[a-f0-9]{12}$/', $stamp ) ? $stamp : '';
    }

    /**
     * Version query string for all public SPA assets.
     * Prefers BUILD_STAMP.txt; falls back to computing from disk.
     */
    public static function publicVersion(): string {
        $recorded = self::readRecorded();
        if ( $recorded !== '' ) {
            return $recorded;
        }
        $computed = self::computeFromDisk();
        if ( $computed !== '' ) {
            return $computed;
        }
        return S2NRI_VERSION;
    }

    /** @return array<string, string> Import map paths → absolute chunk URLs with ?v= stamp. */
    public static function importMapEntries(): array {
        $stamp      = self::publicVersion();
        $chunks_url = S2NRI_ASSETS_URL . 'chunks/';
        $entries    = [];
        $chunk_dir  = S2NRI_DIR . 'assets/chunks/';
        if ( ! is_dir( $chunk_dir ) ) {
            return $entries;
        }
        $names = glob( $chunk_dir . '*.js' ) ?: [];
        sort( $names, SORT_STRING );
        foreach ( $names as $abs ) {
            $cname   = basename( $abs );
            $abs_url = $chunks_url . $cname . '?v=' . $stamp;
            $entries[ './chunks/' . $cname ] = $abs_url;
            $entries[ 'chunks/' . $cname ]   = $abs_url;
        }
        return $entries;
    }

    /** True when on-disk files no longer match BUILD_STAMP.txt (mixed deploy). */
    public static function isMismatch(): bool {
        $recorded = self::readRecorded();
        if ( $recorded === '' ) {
            return false;
        }
        $computed = self::computeFromDisk();
        return $computed !== '' && $computed !== $recorded;
    }
}
