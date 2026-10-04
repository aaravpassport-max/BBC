<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * Unified cache-bust stamp for all SPA assets from one Vite build.
 */
final class AssetBuildStamp {

    private const STAMP_FILE = 'assets/BUILD_STAMP.txt';

    /** Relative path: assets/release/{stamp}/ */
    public static function releaseRelDir( ?string $stamp = null ): string {
        $stamp = $stamp ?? self::publicVersion();
        return 'assets/release/' . $stamp . '/';
    }

    public static function releaseAbsDir( ?string $stamp = null ): string {
        return S2NRI_DIR . self::releaseRelDir( $stamp );
    }

    public static function releaseBaseUrl( ?string $stamp = null ): string {
        $stamp = $stamp ?? self::publicVersion();
        return S2NRI_ASSETS_URL . 'release/' . $stamp . '/';
    }

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

    /**
     * Ensure assets/release/{stamp}/ exists (build artifact or copy from canonical assets/).
     */
    public static function ensureReleaseStaged(): bool {
        $stamp = self::publicVersion();
        $dest  = self::releaseAbsDir( $stamp );
        if ( is_file( $dest . 'app.js' ) && is_file( $dest . 'chunks/booking.js' ) ) {
            return true;
        }
        if ( ! is_dir( $dest ) ) {
            wp_mkdir_p( $dest );
            wp_mkdir_p( $dest . 'chunks/' );
        }
        $copy = static function ( string $rel ) use ( $dest, $stamp ): bool {
            $src = S2NRI_DIR . 'assets/' . $rel;
            $to  = $dest . $rel;
            if ( ! is_file( $src ) ) {
                return false;
            }
            $dir = dirname( $to );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            return copy( $src, $to );
        };
        foreach ( [ 'app.js', 'app.css', 'boot-config.js', 'boot-watchdog.js', 'boot-sw-cleanup.js' ] as $f ) {
            if ( ! $copy( $f ) ) {
                return false;
            }
        }
        $chunk_dir = S2NRI_DIR . 'assets/chunks/';
        if ( ! is_dir( $chunk_dir ) ) {
            return false;
        }
        foreach ( glob( $chunk_dir . '*.js' ) ?: [] as $abs ) {
            $name = basename( $abs );
            if ( ! copy( $abs, $dest . 'chunks/' . $name ) ) {
                return false;
            }
        }
        return is_file( $dest . 'app.js' );
    }

    /** @return array<string, string> Import map → release/{stamp}/chunks URLs (path-versioned, no ?v=). */
    public static function importMapEntries(): array {
        self::ensureReleaseStaged();
        $stamp   = self::publicVersion();
        $base    = self::releaseBaseUrl( $stamp );
        $entries = [];
        $dir     = self::releaseAbsDir( $stamp ) . 'chunks/';
        if ( ! is_dir( $dir ) ) {
            return $entries;
        }
        $names = glob( $dir . '*.js' ) ?: [];
        sort( $names, SORT_STRING );
        foreach ( $names as $abs ) {
            $cname   = basename( $abs );
            $abs_url = $base . 'chunks/' . $cname;
            $entries[ './chunks/' . $cname ] = $abs_url;
            $entries[ 'chunks/' . $cname ]   = $abs_url;
        }
        return $entries;
    }

    /** booking.js must export symbol `s` for current app.js — quick on-disk check. */
    public static function bookingExportCompatible(): ?bool {
        $stamp = self::publicVersion();
        $file  = self::releaseAbsDir( $stamp ) . 'chunks/booking.js';
        if ( ! is_file( $file ) ) {
            $file = S2NRI_DIR . 'assets/chunks/booking.js';
        }
        if ( ! is_file( $file ) ) {
            return null;
        }
        $tail = (string) file_get_contents( $file, false, null, max( 0, filesize( $file ) - 4096 ) );
        return str_contains( $tail, ' as s,' ) || str_contains( $tail, ' as s;' );
    }

    public static function isMismatch(): bool {
        $recorded = self::readRecorded();
        if ( $recorded === '' ) {
            return false;
        }
        $computed = self::computeFromDisk();
        return $computed !== '' && $computed !== $recorded;
    }
}
