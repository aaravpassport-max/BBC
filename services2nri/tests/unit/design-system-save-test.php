<?php
/**
 * DesignSystem::save width persistence (no WordPress bootstrap).
 * Run: php tests/unit/design-system-save-test.php
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);

require_once __DIR__ . '/../stubs/wp-stubs.php';
require_once __DIR__ . '/../../src/Design/DesignSystem.php';

use S2NRI\Models\Setting;
use S2NRI\Design\DesignSystem;

function assert_true(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK  $msg\n";
}

Setting::resetStore();

$heroLayer = [
    'content_max' => [
        'desktop' => '1320px',
        'laptop' => '1320px',
        'tablet' => '1320px',
        'mobile' => '1320px',
    ],
    'section_wide' => [
        'desktop' => '1320px',
        'laptop' => '1320px',
        'tablet' => '1320px',
        'mobile' => '1320px',
    ],
];

// Simulate admin publish with a partial widths payload (missing other stored layers).
DesignSystem::save([
    'widths' => [
        'page_types' => [
            'home' => [
                'sections' => [
                    'hero' => $heroLayer,
                ],
            ],
        ],
    ],
]);

$resolved = DesignSystem::resolve([]);
$storedHero = $resolved['widths']['page_types']['home']['sections']['hero'] ?? null;
assert_true(is_array($storedHero), 'home hero section stored');
assert_true(
    ($storedHero['content_max']['desktop'] ?? '') === '1320px',
    'home hero content_max persisted'
);

// Second save without hero in client payload must not drop stored hero.
DesignSystem::save([
    'colors' => ['primary' => '#112233'],
    'widths' => [
        'global' => [
            'page_max' => [
                'desktop' => '1200px',
                'laptop' => '1200px',
                'tablet' => '94%',
                'mobile' => '100%',
            ],
        ],
    ],
]);

$resolved2 = DesignSystem::resolve([]);
$storedHero2 = $resolved2['widths']['page_types']['home']['sections']['hero'] ?? null;
assert_true(is_array($storedHero2), 'home hero survives partial widths publish');
assert_true(
    ($storedHero2['content_max']['desktop'] ?? '') === '1320px',
    'home hero content_max still 1320px after partial publish'
);

echo "All design-system save tests passed.\n";
