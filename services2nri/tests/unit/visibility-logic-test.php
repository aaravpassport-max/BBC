<?php
/**
 * Unit tests for visibility logic (no WordPress bootstrap required).
 * Run: php tests/unit/visibility-logic-test.php
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);

require_once __DIR__ . '/../stubs/wp-stubs.php';
require_once __DIR__ . '/../../src/Services/ServiceRegistry.php';
require_once __DIR__ . '/../../src/Services/PublicEntityRegistry.php';

use S2NRI\Services\ServiceRegistry;
use S2NRI\Services\PublicEntityRegistry;

function assert_true(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK  $msg\n";
}

$service = [
    'public_status' => 'published',
    'category_public_status' => 'published',
    'visibility_rules' => array_merge(ServiceRegistry::defaultVisibilityRules(), ['forms' => false]),
];
assert_true(!ServiceRegistry::isVisibleOnSurface($service, 'forms'), 'forms off in rules');
assert_true(ServiceRegistry::isVisibleOnSurface($service, 'homepage'), 'homepage on');

$coming = ['public_status' => 'coming_soon', 'category_public_status' => 'published', 'visibility_rules' => ServiceRegistry::defaultVisibilityRules()];
assert_true(!ServiceRegistry::isVisibleOnSurface($coming, 'forms'), 'coming_soon hidden on forms');
assert_true(ServiceRegistry::isVisibleOnSurface($coming, 'homepage'), 'coming_soon on homepage');

$city = ['is_active' => 1, 'public_status' => 'published', 'visibility_rules' => PublicEntityRegistry::defaultCityVisibilityRules()];
assert_true(PublicEntityRegistry::isCityVisibleOnSurface($city, 'homepage'), 'city homepage visible');

$cityHidden = ['is_active' => 1, 'public_status' => 'hidden', 'visibility_rules' => PublicEntityRegistry::defaultCityVisibilityRules()];
assert_true(!PublicEntityRegistry::isCityVisibleOnSurface($cityHidden, 'homepage'), 'hidden city');

echo "All visibility logic tests passed.\n";
