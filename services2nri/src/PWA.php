<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * PWA — serves manifest.json and sw.js at root level.
 */
class PWA {

    public static function serveManifest(): void {
        $settings    = \S2NRI\Models\Setting::getPublic();
        $name        = $settings['platform_name'] ?? 'Services2NRI';
        $primary     = $settings['primary_color'] ?? '#4A6FA5';
        $logo_url = $settings['platform_logo_url'] ?? '';

        // Icon priority:
        // 1. Admin-uploaded raster logo (PNG/JPG/WebP)
        // 2. Shipped icon-192.png / icon-512.png (always present in plugin assets)
        // 3. Never SVG — browsers reject SVG as PWA manifest icons
        if ( $logo_url && preg_match( '/\.(png|jpg|jpeg|webp)$/i', $logo_url ) ) {
            $icon_192 = $logo_url;
            $icon_512 = $logo_url;
        } else {
            // Use static PNG files shipped with the plugin — CDN can serve these
            // directly without requiring WordPress to process the request.
            // Fallback to dynamic WP endpoint if static files don't exist.
            $static_192 = S2NRI_ASSETS_URL . 'icon-192.png';
            $static_512 = S2NRI_ASSETS_URL . 'icon-512.png';
            $icon_192 = file_exists( S2NRI_DIR . 'assets/icon-192.png' ) ? $static_192 : home_url( '/?s2nri_icon=1&size=192' );
            $icon_512 = file_exists( S2NRI_DIR . 'assets/icon-512.png' ) ? $static_512 : home_url( '/?s2nri_icon=1&size=512' );
        }

        $icons = [
            [ 'src' => $icon_192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ],
            [ 'src' => $icon_512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ],
        ];

        $manifest = [
            'name'             => $name,
            'short_name'       => 'S2NRI',
            'description'      => $settings['platform_tagline'] ?? 'Your Trusted NRI Service Partner',
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => '#ffffff',
            'theme_color'      => $primary,
            'icons'            => $icons,
        ];

        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo wp_json_encode( $manifest );
    }

    public static function serveServiceWorker(): void {
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        $sw_file = S2NRI_DIR . 'assets/sw.js';
        if ( file_exists( $sw_file ) ) {
            readfile( $sw_file );
        } else {
            // Fallback only reached if assets/sw.js was somehow deleted from
            // the plugin package — should not happen in normal deploys.
            // Kept intentionally minimal (no caching) so a missing file never
            // silently reintroduces stale-cache behaviour.
            echo "self.addEventListener('install',()=>self.skipWaiting());";
            echo "self.addEventListener('activate',()=>self.clients.claim());";
            echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;e.respondWith(fetch(e.request).catch(()=>new Response('Offline',{status:503})));});";
        }
    }
}
