<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;
use RTOFLOW\Services\DesignSettingsService as DSS;

if (!defined('ABSPATH')) exit;

/**
 * Design & Typography Settings — admin surface.
 *
 * See app/Services/DesignSettingsService.php for the storage design, the
 * generated-stylesheet mechanism, and the exact selector mapping ("MAPPING
 * NOTE") that makes every control here genuinely affect the live site.
 */
class DesignSettingsController
{
    private const ELEMENTS = ['h1', 'h2', 'h3', 'heading', 'body', 'label', 'button', 'nav'];

    public function index(): void
    {
        if (!rto_is_admin()) {
            wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        }

        $settings = DSS::loadSettings();
        $colors   = DSS::loadColors();
        rto_view('admin.design-settings.index', compact('settings', 'colors'));
    }

    // ── Colors ───────────────────────────────────────────────────────────

    public function saveColors(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $old = DSS::loadColors();
        $new = [];
        foreach (DSS::COLOR_KEYS as $key => $optionName) {
            $val = (string) ($_POST[$key] ?? '');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                rto_json_err("Invalid color value for \"{$key}\" — use a 6-digit hex code (e.g. #1B2A6B).");
            }
            $new[$key] = $val;
        }
        foreach ($new as $key => $val) {
            update_option(DSS::COLOR_KEYS[$key], $val, false);
        }
        DSS::touchVersion();

        AuditService::log('design_settings.colors_saved', null, $new, $old);
        rto_json_ok(null, 'Colors saved. The live site now uses these colors.');
    }

    // ── Typography ───────────────────────────────────────────────────────

    public function saveTypography(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['typography'];

        $bodyFont = $_POST['body_font_family'] ?? 'system';
        $headFont = $_POST['heading_font_family'] ?? 'system';
        if (!isset(DSS::FONT_STACKS[$bodyFont])) rto_json_err('Invalid body font family.');
        if (!isset(DSS::FONT_STACKS[$headFont])) rto_json_err('Invalid heading font family.');

        $elements = [];
        foreach (self::ELEMENTS as $el) {
            $elements[$el] = [
                'size_desktop'   => $this->floatIn("{$el}_size_desktop", 8, 160, 2),
                'size_tablet'    => $this->floatIn("{$el}_size_tablet", 8, 160, 2),
                'size_mobile'    => $this->floatIn("{$el}_size_mobile", 8, 160, 2),
                'weight'         => in_array((int) ($_POST["{$el}_weight"] ?? 400), DSS::WEIGHT_VALUES, true) ? (int) $_POST["{$el}_weight"] : 400,
                'line_height'    => $this->floatIn("{$el}_line_height", 0.8, 3, 2),
                'letter_spacing' => $this->floatIn("{$el}_letter_spacing", -0.1, 0.5, 3),
                'transform'      => in_array($_POST["{$el}_transform"] ?? 'none', DSS::TRANSFORM_VALUES, true) ? $_POST["{$el}_transform"] : 'none',
                'color_slot'     => in_array($_POST["{$el}_color_slot"] ?? 'body', array_keys(DSS::COLOR_KEYS), true) ? $_POST["{$el}_color_slot"] : 'body',
            ];
        }

        $settings['typography'] = [
            'body_font_family'    => $bodyFont,
            'heading_font_family' => $headFont,
            'elements'            => $elements,
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save typography settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.typography_saved', null, $settings['typography'], $old);
        rto_json_ok(null, 'Typography saved. The live site now uses these settings.');
    }

    // ── Spacing ──────────────────────────────────────────────────────────

    public function saveSpacing(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['spacing'];

        $settings['spacing'] = [
            'section_padding_desktop' => Sanitiser::int($_POST['section_padding_desktop'] ?? 0, 0, 300),
            'section_padding_tablet'  => Sanitiser::int($_POST['section_padding_tablet'] ?? 0, 0, 300),
            'section_padding_mobile'  => Sanitiser::int($_POST['section_padding_mobile'] ?? 0, 0, 300),
            'section_gap'             => Sanitiser::int($_POST['section_gap'] ?? 0, 0, 200),
            'card_spacing'            => Sanitiser::int($_POST['card_spacing'] ?? 0, 0, 100),
            'container_width'         => Sanitiser::int($_POST['container_width'] ?? 0, 640, 1920),
            'content_width'           => Sanitiser::int($_POST['content_width'] ?? 0, 320, 1200),
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save spacing settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.spacing_saved', null, $settings['spacing'], $old);
        rto_json_ok(null, 'Spacing saved. The live site now uses these settings.');
    }

    // ── Buttons ──────────────────────────────────────────────────────────

    public function saveButtons(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['buttons'];

        $borderColor = (string) ($_POST['border_color'] ?? '#dbe1ee');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $borderColor)) $borderColor = '#dbe1ee';

        $settings['buttons'] = [
            'radius'           => Sanitiser::int($_POST['radius'] ?? 0, 0, 999),
            'padding_y'        => Sanitiser::int($_POST['padding_y'] ?? 0, 0, 60),
            'padding_x'        => Sanitiser::int($_POST['padding_x'] ?? 0, 0, 80),
            'font_weight'      => in_array((int) ($_POST['font_weight'] ?? 700), DSS::WEIGHT_VALUES, true) ? (int) $_POST['font_weight'] : 700,
            'border_width'     => Sanitiser::int($_POST['border_width'] ?? 0, 0, 10),
            'border_color'     => $borderColor,
            'shadow'           => in_array($_POST['shadow'] ?? 'none', DSS::SHADOW_VALUES, true) ? $_POST['shadow'] : 'none',
            'hover_effect'     => in_array($_POST['hover_effect'] ?? 'none', DSS::HOVER_VALUES, true) ? $_POST['hover_effect'] : 'none',
            'transition_speed' => Sanitiser::int($_POST['transition_speed'] ?? 180, 0, 2000),
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save button settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.buttons_saved', null, $settings['buttons'], $old);
        rto_json_ok(null, 'Buttons saved. The live site now uses these settings.');
    }

    // ── Cards & Components (Phase 2) ────────────────────────────────────

    public function saveCards(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['cards'];

        $borderColor = (string) ($_POST['border_color'] ?? '#eef1f6');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $borderColor)) $borderColor = '#eef1f6';
        $bg = (string) ($_POST['background'] ?? '#ffffff');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) $bg = '#ffffff';

        $settings['cards'] = [
            'radius'       => Sanitiser::int($_POST['radius'] ?? 0, 0, 60),
            'shadow'       => in_array($_POST['shadow'] ?? 'subtle', DSS::CARD_SHADOW_VALUES, true) ? $_POST['shadow'] : 'subtle',
            'border_width' => Sanitiser::int($_POST['border_width'] ?? 0, 0, 6),
            'border_color' => $borderColor,
            'background'   => $bg,
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save card settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.cards_saved', null, $settings['cards'], $old);
        rto_json_ok(null, 'Cards & Components saved. The live site now uses these settings.');
    }

    // ── Sections (Phase 2) ───────────────────────────────────────────────

    public function saveSections(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['sections'];

        $settings['sections'] = [
            'min_height'   => Sanitiser::int($_POST['min_height'] ?? 0, 0, 1200),
            'alt_pattern'  => in_array($_POST['alt_pattern'] ?? 'on', ['on', 'off'], true) ? $_POST['alt_pattern'] : 'on',
            'header_align' => in_array($_POST['header_align'] ?? 'left', DSS::SECTION_ALIGN_VALUES, true) ? $_POST['header_align'] : 'left',
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save section settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.sections_saved', null, $settings['sections'], $old);
        rto_json_ok(null, 'Sections saved. The live site now uses these settings.');
    }

    // ── Effects (Phase 2) ────────────────────────────────────────────────

    public function saveEffects(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['effects'];

        $settings['effects'] = [
            'card_hover_effect'  => in_array($_POST['card_hover_effect'] ?? 'lift', DSS::HOVER_VALUES, true) ? $_POST['card_hover_effect'] : 'lift',
            'card_hover_lift'    => Sanitiser::int($_POST['card_hover_lift'] ?? 5, 0, 30),
            'card_transition_ms' => Sanitiser::int($_POST['card_transition_ms'] ?? 200, 0, 2000),
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save effect settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.effects_saved', null, $settings['effects'], $old);
        rto_json_ok(null, 'Effects saved. The live site now uses these settings.');
    }

    // ── Header & Navigation (Phase 3) ───────────────────────────────────

    public function saveHeader(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['header'];

        $bg = (string) ($_POST['background'] ?? '#ffffff');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bg)) $bg = '#ffffff';
        $borderColor = (string) ($_POST['border_color'] ?? '#eef1f6');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $borderColor)) $borderColor = '#eef1f6';

        $settings['header'] = [
            'background'     => $bg,
            'height'         => Sanitiser::int($_POST['height'] ?? 70, 48, 140),
            'sticky'         => in_array($_POST['sticky'] ?? 'on', DSS::TOGGLE_VALUES, true) ? $_POST['sticky'] : 'on',
            'border_color'   => $borderColor,
            'topbar_visible' => in_array($_POST['topbar_visible'] ?? 'on', DSS::TOGGLE_VALUES, true) ? $_POST['topbar_visible'] : 'on',
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save header settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.header_saved', null, $settings['header'], $old);
        rto_json_ok(null, 'Header & Navigation saved. The live site now uses these settings.');
    }

    // ── Footer (Phase 3) ─────────────────────────────────────────────────

    public function saveFooter(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $settings = DSS::loadSettings();
        $old = $settings['footer'];

        $headingColor = (string) ($_POST['heading_color'] ?? '#ffffff');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $headingColor)) $headingColor = '#ffffff';
        $linkColor = (string) ($_POST['link_color'] ?? '#ffffff');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $linkColor)) $linkColor = '#ffffff';
        $siteBg = (string) ($_POST['site_bg'] ?? '#0F172A');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $siteBg)) $siteBg = '#0F172A';

        $settings['footer'] = [
            'heading_color'  => $headingColor,
            'link_color'     => $linkColor,
            'padding_top'    => Sanitiser::int($_POST['padding_top'] ?? 60, 0, 160),
            'padding_bottom' => Sanitiser::int($_POST['padding_bottom'] ?? 40, 0, 160),
            'site_bg'        => $siteBg,
        ];

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to save footer settings.', 500);
        DSS::touchVersion();

        AuditService::log('design_settings.footer_saved', null, $settings['footer'], $old);
        rto_json_ok(null, 'Footer saved. The live site now uses these settings.');
    }

    // ── Reset ────────────────────────────────────────────────────────────

    /**
     * scope=all            → everything (typography+spacing+buttons+cards+sections+effects+header+footer+colors) back to defaults
     * scope=section&section=typography|spacing|buttons|cards|sections|effects|header|footer|colors → just that section
     * scope=field&section=typography&element=h2&field=weight  → a single typography field
     */
    public function reset(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $scope   = Sanitiser::text($_POST['scope'] ?? '', 20);
        $section = Sanitiser::text($_POST['section'] ?? '', 20);

        $settings = DSS::loadSettings();
        $colors   = DSS::loadColors();
        $defaults = DSS::defaultSettings();
        $defaultColors = DSS::defaultColors();
        $old = ['settings' => $settings, 'colors' => $colors];

        if ($scope === 'all') {
            $settings = $defaults;
            $colors = $defaultColors;
        } elseif ($scope === 'section' && in_array($section, ['typography', 'spacing', 'buttons', 'cards', 'sections', 'effects', 'header', 'footer'], true)) {
            $settings[$section] = $defaults[$section];
        } elseif ($scope === 'section' && $section === 'colors') {
            $colors = $defaultColors;
        } elseif ($scope === 'field' && $section === 'typography') {
            $element = Sanitiser::text($_POST['element'] ?? '', 20);
            $field   = Sanitiser::text($_POST['field'] ?? '', 30);
            if (!in_array($element, self::ELEMENTS, true) || !array_key_exists($field, $defaults['typography']['elements']['body'])) {
                rto_json_err('Invalid field.');
            }
            $settings['typography']['elements'][$element][$field] = $defaults['typography']['elements'][$element][$field];
        } elseif ($scope === 'field' && in_array($section, ['spacing', 'buttons', 'cards', 'sections', 'effects', 'header', 'footer'], true)) {
            $field = Sanitiser::text($_POST['field'] ?? '', 30);
            if (!array_key_exists($field, $defaults[$section])) rto_json_err('Invalid field.');
            $settings[$section][$field] = $defaults[$section][$field];
        } elseif ($scope === 'field' && $section === 'colors') {
            $field = Sanitiser::text($_POST['field'] ?? '', 30);
            if (!array_key_exists($field, $defaultColors)) rto_json_err('Invalid field.');
            $colors[$field] = $defaultColors[$field];
        } else {
            rto_json_err('Invalid reset request.');
        }

        if (!DSS::saveSettings($settings)) rto_json_err('Failed to reset settings.', 500);
        foreach ($colors as $key => $val) {
            update_option(DSS::COLOR_KEYS[$key], $val, false);
        }
        DSS::touchVersion();

        AuditService::log('design_settings.reset', null, ['scope' => $scope, 'section' => $section], $old);
        rto_json_ok(['settings' => $settings, 'colors' => $colors], 'Reset to default.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function floatIn(string $key, float $min, float $max, int $decimals = 2): float
    {
        $val = isset($_POST[$key]) ? (float) $_POST[$key] : $min;
        $val = max($min, min($max, $val));
        return round($val, $decimals);
    }
}
