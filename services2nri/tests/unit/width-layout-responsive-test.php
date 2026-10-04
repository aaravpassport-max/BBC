<?php
/**
 * WidthLayout responsive CSS + asymmetric padding tokens.
 * Run: php tests/unit/width-layout-responsive-test.php
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);

require_once __DIR__ . '/../stubs/wp-stubs.php';
require_once __DIR__ . '/../../src/Design/WidthLayout.php';

use S2NRI\Design\WidthLayout;

function assert_true(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK  $msg\n";
}

$config = [
    'widths' => [
        'global' => [
            'padding_x' => [
                'desktop' => '20px',
                'mobile'  => '8px',
            ],
            'padding_x_left' => [
                'mobile' => '4px',
            ],
        ],
        'page_types' => [
            'home' => [
                'padding_x' => [
                    'mobile' => '6px',
                ],
            ],
        ],
    ],
];

$css = WidthLayout::renderScopeCss($config, [ 'page_type' => 'home', 'page_slug' => 'home' ]);

assert_true(str_contains($css, '--s2-width-padding-x-left:4px'), 'mobile left padding var emitted');
assert_true(str_contains($css, '@media (max-width: 768px)'), 'mobile breakpoint at 768px');
assert_true(
    str_contains($css, '[data-s2-page-type="home"]') && str_contains($css, '--s2-width-padding-x:6px'),
    'home page mobile gutter override in scoped media'
);

$merged = WidthLayout::mergeContextLayer($config, [ 'page_type' => 'home', 'page_slug' => 'home' ]);
assert_true(
    ($merged['padding_x']['mobile'] ?? '') === '6px',
    'mergeContextLayer applies home padding_x mobile override'
);

echo "All width-layout responsive tests passed.\n";
