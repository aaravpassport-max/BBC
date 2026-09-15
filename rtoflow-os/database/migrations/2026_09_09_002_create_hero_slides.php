<?php
/**
 * Migration: Home Hero Section → configurable Slider/Swiper
 *
 * USER REQUEST: "Convert the current Home Hero Section into a fully
 * responsive Slider/Swiper with separate image uploads and configuration
 * options for Desktop and Mobile." Full 7-section spec (image management,
 * per-device fit/position, per-device slider settings, per-slide content,
 * dedicated admin UI, correct frontend device targeting, "not a basic
 * carousel") — see HeroSliderController.php for the admin surface and
 * resources/assets/js/hero-slider.js for the frontend engine.
 *
 * This table replaces the previously hardcoded, single hero-desktop.jpg /
 * hero-mobile.jpg pair (resources/views/public/home.php's old static
 * <picture> block) with N admin-managed slides, each carrying its own
 * Desktop and Mobile image (WordPress Media Library attachment IDs +
 * resolved URLs, since Desktop and Mobile must never be forced to share
 * one image), independent fit/position per device, optional heading/
 * description/CTA, overlay, and per-device content placement.
 *
 * Device-level slider behaviour (height, transition, autoplay, loop, nav,
 * dots, swipe, pause-on-hover, breakpoint) is intentionally NOT modelled as
 * columns here — it is one JSON blob per device stored in the
 * 'rtoflow_hero_settings' wp_option, matching this codebase's existing
 * convention for site-wide configuration (rtoflow_color_primary/secondary/
 * accent are already plain options, not a table) since there is exactly one
 * such settings row for the whole site, not one per slide.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateHeroSlides extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_hero_slides (
                id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                desktop_image_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
                desktop_image_url         VARCHAR(500) NOT NULL DEFAULT '',
                mobile_image_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mobile_image_url          VARCHAR(500) NOT NULL DEFAULT '',
                desktop_fit               VARCHAR(20)  NOT NULL DEFAULT 'cover',
                desktop_position          VARCHAR(20)  NOT NULL DEFAULT 'center',
                mobile_fit                VARCHAR(20)  NOT NULL DEFAULT 'cover',
                mobile_position           VARCHAR(20)  NOT NULL DEFAULT 'center',
                heading                   VARCHAR(255) NOT NULL DEFAULT '',
                description               TEXT NULL,
                cta_text                  VARCHAR(100) NOT NULL DEFAULT '',
                cta_url                   VARCHAR(500) NOT NULL DEFAULT '',
                overlay_enabled           TINYINT(1) NOT NULL DEFAULT 0,
                overlay_color             VARCHAR(20)  NOT NULL DEFAULT '#0A1628',
                overlay_opacity           TINYINT UNSIGNED NOT NULL DEFAULT 30,
                content_position_desktop  VARCHAR(20)  NOT NULL DEFAULT 'center-left',
                content_position_mobile   VARCHAR(20)  NOT NULL DEFAULT 'bottom-center',
                is_active                 TINYINT(1) NOT NULL DEFAULT 1,
                display_order             INT NOT NULL DEFAULT 0,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_active_order(is_active, display_order)
            ) {$c}"
        );

        // Seed exactly one slide from the previous static hero images, so
        // sites upgrading from the old hardcoded hero never go from "hero
        // shows an image" to "hero shows nothing" the moment this ships —
        // the admin can then edit/replace/add slides from the new screen.
        $already = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_hero_slides");
        if ($already === 0) {
            $deskUrl = defined('RTOFLOW_URL') ? RTOFLOW_URL . 'resources/assets/images/hero-desktop.jpg' : '';
            $mobUrl  = defined('RTOFLOW_URL') ? RTOFLOW_URL . 'resources/assets/images/hero-mobile.jpg'  : '';
            $wpdb->insert($p . 'rto_hero_slides', [
                'desktop_image_id'  => 0,
                'desktop_image_url' => $deskUrl,
                'mobile_image_id'   => 0,
                'mobile_image_url'  => $mobUrl,
                'desktop_fit'       => 'cover',
                'desktop_position'  => 'center',
                'mobile_fit'        => 'cover',
                'mobile_position'   => 'center',
                'heading'           => '',
                'description'       => '',
                'cta_text'          => '',
                'cta_url'           => home_url('/rto-apply/'),
                'overlay_enabled'   => 0,
                'is_active'         => 1,
                'display_order'     => 0,
                'created_at'        => current_time('mysql'),
                'updated_at'        => current_time('mysql'),
            ]);
        }

        // Seed the settings option once with sensible defaults matching the
        // previous hardcoded behaviour (no autoplay-driven layout shift,
        // same 760px breakpoint the old <picture> used) — HeroSliderController
        // reads/writes this same key thereafter.
        if (get_option('rtoflow_hero_settings', null) === null) {
            add_option('rtoflow_hero_settings', wp_json_encode([
                'general' => ['breakpoint' => 760],
                'desktop' => [
                    'height' => 640, 'min_height' => 320, 'max_height' => 900,
                    'transition_effect' => 'slide', 'transition_speed' => 600,
                    'autoplay' => true, 'autoplay_interval' => 5000,
                    'loop' => true, 'nav_arrows' => true, 'pagination_dots' => true,
                    'swipe' => true, 'pause_on_hover' => true,
                ],
                'mobile' => [
                    'height' => 560, 'min_height' => 360, 'max_height' => 900,
                    'transition_effect' => 'slide', 'transition_speed' => 500,
                    'autoplay' => true, 'autoplay_interval' => 4500,
                    'loop' => true, 'nav_arrows' => false, 'pagination_dots' => true,
                    'swipe' => true, 'pause_on_hover' => false,
                ],
            ], JSON_UNESCAPED_SLASHES), '', false);
        }
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_hero_slides");
        delete_option('rtoflow_hero_settings');
    }
}
