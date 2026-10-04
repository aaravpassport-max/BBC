#!/usr/bin/env php
<?php
/**
 * Regenerate docs/DESIGN_SYSTEM_PAGE_MATRIX.generated.md from route + asset inventory.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$routes = [
    ['/', 'HomePage.tsx', 'homepage'],
    ['/services', 'ServicesPage.tsx', 'directory'],
    ['/service/:slug', 'ServiceDetailPage.tsx', 'direct_url'],
    ['/about', 'index.tsx AboutPage', 'page'],
    ['/contact', 'index.tsx ContactPage', 'page'],
    ['/faq', 'index.tsx FAQPage', 'page'],
    ['/pricing', 'index.tsx PricingPage', 'page'],
    ['/blog', 'Blog.php', 'blog'],
];

$css = glob($root . '/assets/public-*.css') ?: [];
$lines = ["# Generated audit index\n", "Generated: " . date('c') . "\n\n", "## Routes\n"];
foreach ($routes as [$path, $file, $type]) {
    $lines[] = "| `$path` | `$file` | page_type `$type` |\n";
}
$lines[] = "\n## Design asset bundles\n";
foreach ($css as $file) {
    $lines[] = '- `' . basename($file) . "` (" . filesize($file) . " bytes)\n";
}

$out = $root . '/docs/DESIGN_SYSTEM_PAGE_MATRIX.generated.md';
file_put_contents($out, implode('', $lines));
echo "Wrote $out\n";
