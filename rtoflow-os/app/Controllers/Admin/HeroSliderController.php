<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

/**
 * Hero Slider Management
 *
 * Admin surface for the Home Hero Section slider (see database/migrations/
 * 2026_09_09_002_create_hero_slides.php for the full rationale). Gives the
 * admin: multiple slides each with independent Desktop/Mobile images, fit,
 * position, heading/description/CTA, overlay and content placement; plus
 * device-specific slider behaviour (height, transition, autoplay, loop,
 * nav, dots, swipe, pause-on-hover, breakpoint) stored in the
 * 'rtoflow_hero_settings' option. Uploads go through WordPress's own
 * media_handle_upload() so images land in the standard public
 * wp-content/uploads/ Media Library — FileUploadGuard was deliberately NOT
 * reused here because it stores files in a .htaccess-protected, non-public
 * directory meant for private KYC documents, which would make the hero
 * images unservable on the public homepage.
 */
class HeroSliderController
{
    private \wpdb $db;
    private string $p;

    private const FIT_VALUES      = ['cover', 'contain', 'fill', 'auto'];
    private const POSITION_VALUES = [
        'center', 'center-top', 'center-bottom', 'left', 'right',
        'top-left', 'top-right', 'bottom-left', 'bottom-right', 'custom',
    ];
    private const CONTENT_POSITION_VALUES = [
        'center', 'center-left', 'center-right',
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];
    private const TRANSITION_VALUES = ['slide', 'fade', 'cube', 'coverflow'];

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        if (!rto_is_admin()) {
            wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        }

        $slides   = $this->db->get_results(
            "SELECT * FROM {$this->p}rto_hero_slides ORDER BY display_order ASC, id ASC",
            ARRAY_A
        ) ?: [];
        $settings = self::defaultSettings();
        $stored   = json_decode((string) get_option('rtoflow_hero_settings', ''), true);
        if (is_array($stored)) {
            $settings = self::mergeSettings($settings, $stored);
        }

        $pageTitle = 'Hero Slider';
        rto_view('admin.hero-slider.index', compact('slides', 'settings'));
    }

    public static function defaultSettings(): array
    {
        return [
            'general' => ['breakpoint' => 760],
            'desktop' => [
                'height' => 640, 'min_height' => 320, 'max_height' => 900,
                'default_fit' => 'cover', 'default_position' => 'center',
                'transition_effect' => 'slide', 'transition_speed' => 600,
                'autoplay' => true, 'autoplay_interval' => 5000,
                'loop' => true, 'nav_arrows' => true, 'pagination_dots' => true,
                'swipe' => true, 'pause_on_hover' => true,
            ],
            'mobile' => [
                'height' => 560, 'min_height' => 360, 'max_height' => 900,
                'default_fit' => 'cover', 'default_position' => 'center',
                'transition_effect' => 'slide', 'transition_speed' => 500,
                'autoplay' => true, 'autoplay_interval' => 4500,
                'loop' => true, 'nav_arrows' => false, 'pagination_dots' => true,
                'swipe' => true, 'pause_on_hover' => false,
            ],
        ];
    }

    private static function mergeSettings(array $defaults, array $stored): array
    {
        foreach (['general', 'desktop', 'mobile'] as $bucket) {
            if (!empty($stored[$bucket]) && is_array($stored[$bucket])) {
                $defaults[$bucket] = array_merge($defaults[$bucket], $stored[$bucket]);
            }
        }
        return $defaults;
    }

    /** Load settings for use by the public homepage renderer (Router.php). */
    public static function loadSettingsForFrontend(): array
    {
        $settings = self::defaultSettings();
        $stored   = json_decode((string) get_option('rtoflow_hero_settings', ''), true);
        if (is_array($stored)) {
            $settings = self::mergeSettings($settings, $stored);
        }
        return $settings;
    }

    // ── Settings AJAX ────────────────────────────────────────────────────

    public function saveSettings(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $old = self::loadSettingsForFrontend();

        $breakpoint = Sanitiser::int($_POST['breakpoint'] ?? 760, 320, 1400);

        $buildDevice = function (string $prefix, bool $allowPauseOnHover): array {
            return [
                'height'             => Sanitiser::int($_POST["{$prefix}_height"] ?? 0, 100, 2000),
                'min_height'         => Sanitiser::int($_POST["{$prefix}_min_height"] ?? 0, 80, 2000),
                'max_height'         => Sanitiser::int($_POST["{$prefix}_max_height"] ?? 0, 100, 3000),
                'default_fit'        => in_array($_POST["{$prefix}_default_fit"] ?? '', self::FIT_VALUES, true)
                                         ? $_POST["{$prefix}_default_fit"] : 'cover',
                'default_position'   => in_array($_POST["{$prefix}_default_position"] ?? '', self::POSITION_VALUES, true)
                                         ? $_POST["{$prefix}_default_position"] : 'center',
                'transition_effect'  => in_array($_POST["{$prefix}_transition_effect"] ?? '', self::TRANSITION_VALUES, true)
                                         ? $_POST["{$prefix}_transition_effect"] : 'slide',
                'transition_speed'   => Sanitiser::int($_POST["{$prefix}_transition_speed"] ?? 0, 100, 5000),
                'autoplay'           => Sanitiser::bool($_POST["{$prefix}_autoplay"] ?? ''),
                'autoplay_interval'  => Sanitiser::int($_POST["{$prefix}_autoplay_interval"] ?? 0, 1000, 20000),
                'loop'               => Sanitiser::bool($_POST["{$prefix}_loop"] ?? ''),
                'nav_arrows'         => Sanitiser::bool($_POST["{$prefix}_nav_arrows"] ?? ''),
                'pagination_dots'    => Sanitiser::bool($_POST["{$prefix}_pagination_dots"] ?? ''),
                'swipe'              => Sanitiser::bool($_POST["{$prefix}_swipe"] ?? ''),
                'pause_on_hover'     => $allowPauseOnHover ? Sanitiser::bool($_POST["{$prefix}_pause_on_hover"] ?? '') : false,
            ];
        };

        $new = [
            'general' => ['breakpoint' => $breakpoint],
            'desktop' => $buildDevice('desktop', true),
            'mobile'  => $buildDevice('mobile', false),
        ];

        // Guard against min > max producing a broken clamp() at render time.
        foreach (['desktop', 'mobile'] as $dev) {
            if ($new[$dev]['min_height'] > $new[$dev]['max_height']) {
                [$new[$dev]['min_height'], $new[$dev]['max_height']] = [$new[$dev]['max_height'], $new[$dev]['min_height']];
            }
            $new[$dev]['height'] = max($new[$dev]['min_height'], min($new[$dev]['max_height'], $new[$dev]['height']));
        }

        $saved = update_option('rtoflow_hero_settings', wp_json_encode($new, JSON_UNESCAPED_SLASHES), false);
        // update_option() returns false both on a real failure AND when the
        // new value is identical to the stored one (a legitimate no-op) —
        // so a real failure is only distinguishable by re-reading and
        // comparing, the same ghost-success guard technique used elsewhere.
        $reread = json_decode((string) get_option('rtoflow_hero_settings', ''), true);
        if (!$saved && $reread !== $new) {
            rto_json_err('Failed to save hero settings.', 500);
        }

        AuditService::log('hero_slider.settings_saved', null, $new, $old);
        rto_json_ok(null, 'Hero slider settings saved.');
    }

    // ── Slide AJAX ───────────────────────────────────────────────────────

    public function uploadImage(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        if (empty($_FILES['image']) || !is_array($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            rto_json_err('No valid image file received.');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $type = wp_check_filetype($_FILES['image']['name'] ?? '');
        if (empty($type['type']) || !in_array($type['type'], $allowedMimes, true)) {
            rto_json_err('Unsupported image type. Use JPG, PNG, WEBP or GIF.');
        }
        // 10MB cap, matching FileUploadGuard's existing precedent elsewhere
        // in this codebase.
        if (($_FILES['image']['size'] ?? 0) > 10 * 1024 * 1024) {
            rto_json_err('Image is too large. Maximum size is 10MB.');
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachmentId = media_handle_upload('image', 0);
        if (is_wp_error($attachmentId)) {
            rto_json_err('Upload failed: ' . $attachmentId->get_error_message(), 500);
        }

        $url = wp_get_attachment_url($attachmentId);
        if (!$url) {
            rto_json_err('Upload succeeded but the file URL could not be resolved.', 500);
        }

        rto_json_ok(['attachment_id' => (int) $attachmentId, 'url' => $url], 'Image uploaded.');
    }

    public function saveSlide(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['id'] ?? 0);

        $deskImgId = Sanitiser::int($_POST['desktop_image_id'] ?? 0);
        $deskImgUrl = Sanitiser::url($_POST['desktop_image_url'] ?? '');
        $mobImgId  = Sanitiser::int($_POST['mobile_image_id'] ?? 0);
        $mobImgUrl = Sanitiser::url($_POST['mobile_image_url'] ?? '');

        if ($deskImgUrl === '') rto_json_err('A Desktop image is required for every slide.');
        if ($mobImgUrl === '')  rto_json_err('A Mobile image is required for every slide.');

        $fit = fn($v) => in_array($v, self::FIT_VALUES, true) ? $v : 'cover';
        $pos = fn($v) => in_array($v, self::POSITION_VALUES, true) ? $v : 'center';
        $cpos = fn($v) => in_array($v, self::CONTENT_POSITION_VALUES, true) ? $v : 'center';

        $data = [
            'desktop_image_id'         => $deskImgId,
            'desktop_image_url'        => $deskImgUrl,
            'mobile_image_id'          => $mobImgId,
            'mobile_image_url'         => $mobImgUrl,
            'desktop_fit'              => $fit($_POST['desktop_fit'] ?? 'cover'),
            'desktop_position'         => $pos($_POST['desktop_position'] ?? 'center'),
            'mobile_fit'               => $fit($_POST['mobile_fit'] ?? 'cover'),
            'mobile_position'          => $pos($_POST['mobile_position'] ?? 'center'),
            'heading'                  => Sanitiser::text($_POST['heading'] ?? '', 255),
            'description'              => Sanitiser::text($_POST['description'] ?? '', 2000),
            'cta_text'                 => Sanitiser::text($_POST['cta_text'] ?? '', 100),
            'cta_url'                  => Sanitiser::url($_POST['cta_url'] ?? ''),
            'overlay_enabled'          => Sanitiser::bool($_POST['overlay_enabled'] ?? '') ? 1 : 0,
            'overlay_color'            => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($_POST['overlay_color'] ?? '')) ? $_POST['overlay_color'] : '#0A1628',
            'overlay_opacity'          => Sanitiser::int($_POST['overlay_opacity'] ?? 30, 0, 100),
            'content_position_desktop' => $cpos($_POST['content_position_desktop'] ?? 'center-left'),
            'content_position_mobile'  => $cpos($_POST['content_position_mobile'] ?? 'bottom-center'),
            'is_active'                => Sanitiser::bool($_POST['is_active'] ?? '1') ? 1 : 0,
            'updated_at'               => current_time('mysql'),
        ];

        // A CTA URL without CTA text (or vice versa) renders a dead/blank
        // button — reject rather than silently produce broken markup.
        if ($data['cta_text'] !== '' && $data['cta_url'] === '') rto_json_err('CTA button text was set but the CTA URL is missing or invalid.');

        if ($id > 0) {
            $old = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_hero_slides WHERE id=%d", $id), ARRAY_A);
            if (!$old) rto_json_err('Slide not found.', 404);

            $result = $this->db->update($this->p . 'rto_hero_slides', $data, ['id' => $id]);
            // Ghost-success guard: false is a real failure; 0 (no columns
            // actually changed) is a legitimate no-op, not a failure.
            if ($result === false) rto_json_err('Failed to update slide: ' . $this->db->last_error, 500);

            AuditService::log('hero_slider.slide_updated', null, $data, $old);
            rto_json_ok(['id' => $id], 'Slide updated.');
        }

        $maxOrder = (int) $this->db->get_var("SELECT COALESCE(MAX(display_order),-1) FROM {$this->p}rto_hero_slides");
        $data['display_order'] = $maxOrder + 1;
        $data['created_at'] = current_time('mysql');

        $inserted = $this->db->insert($this->p . 'rto_hero_slides', $data);
        if ($inserted === false) rto_json_err('Failed to create slide: ' . $this->db->last_error, 500);

        $newId = (int) $this->db->insert_id;
        AuditService::log('hero_slider.slide_created', null, array_merge($data, ['id' => $newId]));
        rto_json_ok(['id' => $newId], 'Slide added.');
    }

    public function deleteSlide(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['id'] ?? 0);
        if (!$id) rto_json_err('Invalid slide.');

        $old = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_hero_slides WHERE id=%d", $id), ARRAY_A);
        if (!$old) rto_json_err('Slide not found.', 404);

        $remaining = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->p}rto_hero_slides");
        if ($remaining <= 1) rto_json_err('At least one hero slide must remain — add a replacement slide before deleting the last one.');

        $result = $this->db->delete($this->p . 'rto_hero_slides', ['id' => $id]);
        if (!$result) rto_json_err('Failed to delete slide.', 500);

        AuditService::log('hero_slider.slide_deleted', null, [], $old);
        rto_json_ok(null, 'Slide deleted.');
    }

    public function toggleSlide(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['id'] ?? 0);
        if (!$id) rto_json_err('Invalid slide.');

        $old = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_hero_slides WHERE id=%d", $id), ARRAY_A);
        if (!$old) rto_json_err('Slide not found.', 404);

        $newActive = $old['is_active'] ? 0 : 1;
        if ($newActive === 0) {
            $otherActive = (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->p}rto_hero_slides WHERE is_active=1 AND id<>%d", $id
            ));
            if ($otherActive === 0) rto_json_err('At least one slide must stay active — enable another slide first.');
        }

        $result = $this->db->update($this->p . 'rto_hero_slides', ['is_active' => $newActive, 'updated_at' => current_time('mysql')], ['id' => $id]);
        if ($result === false) rto_json_err('Failed to update slide status.', 500);

        AuditService::log('hero_slider.slide_toggled', null, ['is_active' => $newActive], ['is_active' => (int) $old['is_active']]);
        rto_json_ok(['is_active' => $newActive], $newActive ? 'Slide enabled.' : 'Slide disabled.');
    }

    public function reorderSlides(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $order = $_POST['order'] ?? [];
        if (!is_array($order) || empty($order)) rto_json_err('Invalid order.');

        $ids = array_values(array_unique(array_map('intval', $order)));
        // Confirm every id actually belongs to this table before writing —
        // an attacker-controlled id list should not be able to touch rows
        // outside this table (there's nothing else it could reach here,
        // but the existence check also catches stale/removed slide ids).
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $validCount = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_hero_slides WHERE id IN ({$placeholders})", $ids
        ));
        if ($validCount !== count($ids)) rto_json_err('One or more slides in the new order no longer exist.');

        foreach ($ids as $i => $id) {
            $this->db->update($this->p . 'rto_hero_slides', ['display_order' => $i], ['id' => $id]);
        }

        AuditService::log('hero_slider.reordered', null, ['order' => $ids]);
        rto_json_ok(null, 'Slide order saved.');
    }
}
