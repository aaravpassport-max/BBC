<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/** Public boot helpers (?s2nri_boot_diag=1, ?s2nri_import_map=1) — not caught by SPA router. */
final class BootPublic {

    public static function boot(): void {
        add_action( 'plugins_loaded', [ self::class, 'maybeServeLegacyViteCss' ], 0 );
        add_action( 'plugins_loaded', [ self::class, 'maybeRespond' ], 1 );
    }

    /**
     * Old app.js builds preloaded /app2.css at the site root (Layout Studio split CSS).
     * Serve merged app.css so cached JS still boots after upgrade.
     */
    public static function maybeServeLegacyViteCss(): void {
        if ( php_sapi_name() === 'cli' ) {
            return;
        }
        $uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
        $path = strtok( $uri, '?' ) ?: '';
        if ( $path === '' || ! preg_match( '#/(app2\.css)$#', $path ) ) {
            return;
        }

        $css_path = self::canonicalAppCssPath();
        if ( ! is_file( $css_path ) ) {
            status_header( 404 );
            exit;
        }

        status_header( 200 );
        header( 'Content-Type: text/css; charset=utf-8' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'X-S2NRI-Legacy-Vite-Css: app2' );
        readfile( $css_path );
        exit;
    }

    private static function canonicalAppCssPath(): string {
        if ( class_exists( AssetBuildStamp::class ) ) {
            AssetBuildStamp::ensureReleaseStaged();
            $stamp = AssetBuildStamp::publicVersion();
            if ( $stamp !== '' ) {
                $release = S2NRI_DIR . 'assets/release/' . $stamp . '/app.css';
                if ( is_file( $release ) ) {
                    return $release;
                }
            }
        }
        return S2NRI_DIR . 'assets/app.css';
    }

    public static function maybeRespond(): void {
        if ( isset( $_GET['s2nri_boot_diag'] ) ) {
            self::sendDiag();
        }
        if ( isset( $_GET['s2nri_import_map'] ) ) {
            self::sendImportMap();
        }
    }

    private static function sendDiag(): void {
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        $files = [
            'assets/BUILD_STAMP.txt',
            'assets/app.js',
            'assets/boot-config.js',
            'assets/boot-watchdog.js',
            'assets/chunks/booking.js',
        ];
        $exists = [];
        foreach ( $files as $rel ) {
            $exists[ $rel ] = is_file( S2NRI_DIR . $rel );
        }
        $stamp = class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::publicVersion() : '';
        if ( class_exists( AssetBuildStamp::class ) ) {
            AssetBuildStamp::ensureReleaseStaged();
        }
        $payload = [
            'ok'                    => true,
            'plugin_version'        => S2NRI_VERSION,
            'assets_url'            => S2NRI_ASSETS_URL,
            'release_base_url'      => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::releaseBaseUrl() : '',
            'build_stamp'           => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::readRecorded() : '',
            'build_computed'        => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::computeFromDisk() : '',
            'build_mismatch'        => class_exists( AssetBuildStamp::class ) && AssetBuildStamp::isMismatch(),
            'booking_export_ok'     => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::bookingExportCompatible() : null,
            'public_version'        => $stamp,
            'files'                 => $exists,
            'release_app_js'        => $stamp !== '' && is_file( S2NRI_DIR . 'assets/release/' . $stamp . '/app.js' ),
            'release_booking_js'    => $stamp !== '' && is_file( S2NRI_DIR . 'assets/release/' . $stamp . '/chunks/booking.js' ),
            'app_js_references_app2' => is_file( S2NRI_DIR . 'assets/app.js' )
                && str_contains( (string) file_get_contents( S2NRI_DIR . 'assets/app.js' ), 'app2.css' ),
            'legacy_app2_css_route' => home_url( '/app2.css' ),
            'php_version'             => PHP_VERSION,
        ];
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    private static function sendImportMap(): void {
        if ( ! class_exists( AssetBuildStamp::class ) ) {
            status_header( 503 );
            exit;
        }
        $stamp = AssetBuildStamp::publicVersion();
        $req   = isset( $_GET['v'] ) ? sanitize_text_field( wp_unslash( $_GET['v'] ) ) : '';
        if ( $req !== '' && $req !== $stamp ) {
            header( 'Cache-Control: no-store' );
        } else {
            header( 'Cache-Control: public, max-age=3600' );
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode(
            [ 'imports' => AssetBuildStamp::importMapEntries() ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
