<?php
/**
 * Compare configFromPreset() fingerprints — finds presets that share too much state.
 * Run: php scripts/audit-preset-looks.php
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);

require_once __DIR__ . '/../tests/stubs/wp-stubs.php';
require_once __DIR__ . '/../src/Design/WidthLayout.php';
require_once __DIR__ . '/../src/Design/DesignPresets.php';
require_once __DIR__ . '/../src/Design/DesignSystem.php';

use S2NRI\Design\DesignPresets;
use S2NRI\Design\DesignSystem;

function fingerprint(array $config): string {
    $colors = $config['colors'] ?? [];
    $fonts = $config['fonts'] ?? [];
    $spacing = $config['spacing'] ?? [];
    $radius = $config['radius'] ?? [];
    $shadow = $config['shadow'] ?? [];
    $chrome = ($config['chrome']['global'] ?? []);
    $comp = $config['components'] ?? [];
    $widthPage = ($config['widths']['global']['page_max']['desktop'] ?? '');

    $keys = [
        'primary' => $colors['primary'] ?? '',
        'secondary' => $colors['secondary'] ?? '',
        'background' => $colors['background'] ?? '',
        'heading' => $colors['heading'] ?? '',
        'fonts' => implode('|', array_values($fonts)),
        'section_y' => $spacing['section_y'] ?? '',
        'radius_md' => $radius['md'] ?? '',
        'radius_pill' => $radius['pill'] ?? '',
        'shadow_card' => $shadow['card'] ?? '',
        'btn_bg' => $comp['button_primary_bg'] ?? '',
        'btn_radius' => $comp['button_radius'] ?? '',
        'topbar' => $chrome['topbar_bg'] ?? '',
        'footer' => $chrome['footer_bg'] ?? '',
        'header_var' => $chrome['header_variant'] ?? '',
        'footer_var' => $chrome['footer_variant'] ?? '',
        'page_max' => $widthPage,
    ];
    return json_encode($keys, JSON_UNESCAPED_SLASHES);
}

$ids = array_keys(DesignPresets::list());
$fps = [];
foreach ($ids as $id) {
    $cfg = DesignSystem::configFromPreset($id);
    $fps[$id] = fingerprint($cfg);
}

echo "Preset look fingerprints (key visual tokens):\n\n";
foreach ($ids as $id) {
    $cfg = DesignSystem::configFromPreset($id);
    $c = $cfg['colors'];
    echo str_pad($id, 14) . " pri={$c['primary']} sec={$c['secondary']} footer={$cfg['chrome']['global']['footer_bg']} fonts={$cfg['fonts']['heading']}/{$cfg['fonts']['body']}\n";
}

echo "\nDuplicate fingerprints (same effective look bundle):\n";
$groups = [];
foreach ($fps as $id => $fp) {
    $groups[$fp][] = $id;
}
$dupes = 0;
foreach ($groups as $fp => $group) {
    if (count($group) > 1) {
        $dupes++;
        echo '  ' . implode(', ', $group) . "\n";
    }
}
if ($dupes === 0) {
    echo "  (none — all presets differ on tracked tokens)\n";
}

$fail = 0;
$defaultSecondary = ['professional'];
echo "\nAudit rules:\n";
foreach ($ids as $id) {
    $sec = DesignSystem::configFromPreset($id)['colors']['secondary'] ?? '';
    $footer = DesignSystem::configFromPreset($id)['chrome']['global']['footer_bg'] ?? '';
    if ($sec === '#1E2D40' && !in_array($id, $defaultSecondary, true)) {
        echo "FAIL $id still uses default secondary #1E2D40 (footer looks like Professional)\n";
        $fail = 1;
    }
    if ($footer !== $sec && $footer !== ($DesignSystem::configFromPreset($id)['colors']['heading'] ?? '')) {
        // footer should match secondary after sync
        if ($footer !== $sec) {
            echo "WARN $id footer_bg ($footer) != secondary ($sec)\n";
        }
    }
}
foreach ($groups as $group) {
    if (count($group) > 1) {
        echo 'FAIL duplicate fingerprint: ' . implode(', ', $group) . "\n";
        $fail = 1;
    }
}
if ($fail !== 0) {
    fwrite(STDERR, "Preset look audit failed.\n");
    exit(1);
}
echo "OK  all presets have distinct look bundles\n";
