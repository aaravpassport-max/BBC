<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/** Public boot helpers (?s2nri_boot_diag=1, ?s2nri_import_map=1) — not caught by SPA router. */
final class BootPublic {

    public static function boot(): void {
        add_action( 'plugins_loaded', [ self::class, 'maybeRespond' ], 1 );
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
        $payload = [
            'ok'              => true,
            'plugin_version'  => S2NRI_VERSION,
            'assets_url'      => S2NRI_ASSETS_URL,
            'build_stamp'     => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::readRecorded() : '',
            'build_computed'  => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::computeFromDisk() : '',
            'build_mismatch'  => class_exists( AssetBuildStamp::class ) && AssetBuildStamp::isMismatch(),
            'public_version'  => class_exists( AssetBuildStamp::class ) ? AssetBuildStamp::publicVersion() : S2NRI_VERSION,
            'files'           => $exists,
            'php_version'     => PHP_VERSION,
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
