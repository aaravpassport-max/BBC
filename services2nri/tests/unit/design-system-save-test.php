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

function responsive(string $px): array {
    return [
        'desktop' => $px,
        'laptop' => $px,
        'tablet' => $px,
        'mobile' => $px,
    ];
}

Setting::resetStore();

$heroLayer = [
    'content_max' => responsive('1320px'),
    'section_wide' => responsive('1320px'),
];

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

DesignSystem::save([
    'colors' => ['primary' => '#112233'],
    'widths' => [
        'global' => [
            'page_max' => responsive('1200px'),
        ],
        'page_types' => [],
    ],
]);

$resolved2 = DesignSystem::resolve([]);
$storedHero2 = $resolved2['widths']['page_types']['home']['sections']['hero'] ?? null;
assert_true(is_array($storedHero2), 'home hero survives empty page_types publish');
assert_true(
    ($storedHero2['content_max']['desktop'] ?? '') === '1320px',
    'home hero content_max after empty page_types publish'
);

DesignSystem::save([
    'widths' => [
        'sections' => [
            'newsletter' => [
                'section_standard' => responsive('980px'),
            ],
        ],
        'service_page' => [
            'sections' => [
                'hero' => [
                    'content_max' => responsive('1100px'),
                ],
            ],
        ],
        'pages' => [
            'about' => [
                'content_max' => responsive('900px'),
            ],
        ],
        'page_types' => [
            'blog' => [
                'page_max' => responsive('1280px'),
            ],
        ],
    ],
]);

$r3 = DesignSystem::resolve([]);
assert_true(
    ($r3['widths']['sections']['newsletter']['section_standard']['desktop'] ?? '') === '980px',
    'global marketing section width stored'
);
assert_true(
    ($r3['widths']['service_page']['sections']['hero']['content_max']['desktop'] ?? '') === '1100px',
    'service hero section stored'
);
assert_true(
    ($r3['widths']['pages']['about']['content_max']['desktop'] ?? '') === '900px',
    'single page slug width stored'
);
assert_true(
    ($r3['widths']['page_types']['blog']['page_max']['desktop'] ?? '') === '1280px',
    'page template width stored'
);
assert_true(
    ($r3['widths']['page_types']['home']['sections']['hero']['content_max']['desktop'] ?? '') === '1320px',
    'home hero preserved when saving other width layers'
);

DesignSystem::save([
    'widths' => [
        'page_types' => [
            'blog' => [
                'page_max' => responsive('1280px'),
            ],
        ],
    ],
]);

DesignSystem::save([
    'widths' => [
        'page_types' => [
            'blog' => [
                'page_max' => null,
            ],
        ],
    ],
]);

$r4 = DesignSystem::resolve([]);
$blogLayer = $r4['widths']['page_types']['blog'] ?? null;
assert_true(
    !is_array($blogLayer) || !isset($blogLayer['page_max']),
    'null page_max clears page template override (inherit foundation)'
);

echo "All design-system save tests passed.\n";
