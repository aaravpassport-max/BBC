<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RTOFLOW\Config\MatchingConfig;

/**
 * Unit tests for MatchingConfig's defaults and save() clamping.
 *
 * Relies on the get_option()/update_option() stubs defined in
 * tests/bootstrap.php. MatchingConfig caches its loaded config in a static
 * property, so each test resets that cache and the in-memory option store
 * to avoid state leaking between tests.
 */
final class MatchingConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__rtoflow_test_options'] = [];
        $this->resetStaticCache();
    }

    private function resetStaticCache(): void
    {
        $prop = new ReflectionProperty(MatchingConfig::class, 'config');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testGetReturnsDefaultsWhenNoOptionStored(): void
    {
        $this->assertSame(30.0, MatchingConfig::get('rating_weight'));
        $this->assertSame(3.0, MatchingConfig::get('min_rating'));
        $this->assertSame(20.0, MatchingConfig::get('max_active_jobs'));
        $this->assertSame(10.0, MatchingConfig::get('candidate_pool_size'));
    }

    public function testGetUnknownKeyReturnsZero(): void
    {
        $this->assertSame(0.0, MatchingConfig::get('nonexistent_key'));
    }

    public function testAllReturnsLabelAndDescriptionForEveryDefaultKey(): void
    {
        $all = MatchingConfig::all();
        foreach (array_keys(MatchingConfig::DEFAULTS) as $key) {
            $this->assertArrayHasKey($key, $all);
            $this->assertArrayHasKey('value', $all[$key]);
            $this->assertArrayHasKey('label', $all[$key]);
            $this->assertArrayHasKey('description', $all[$key]);
            $this->assertSame(MatchingConfig::DEFAULTS[$key], $all[$key]['value']);
        }
    }

    public function testSavePersistsValuesWithinRange(): void
    {
        MatchingConfig::save([
            'rating_weight'       => 40,
            'completion_weight'   => 20,
            'acceptance_weight'   => 15,
            'load_weight'         => 25,
            'min_rating'          => 4,
            'max_active_jobs'     => 50,
            'candidate_pool_size' => 5,
        ]);

        $this->assertSame(40.0, MatchingConfig::get('rating_weight'));
        $this->assertSame(4.0, MatchingConfig::get('min_rating'));
        $this->assertSame(50.0, MatchingConfig::get('max_active_jobs'));
        $this->assertSame(5.0, MatchingConfig::get('candidate_pool_size'));
    }

    public function testSaveClampsPercentageWeightsToZeroAndHundred(): void
    {
        MatchingConfig::save([
            'rating_weight'     => 500,   // above max => clamped to 100
            'completion_weight' => -20,   // below min => clamped to 0
            'acceptance_weight' => 20,
            'load_weight'       => 25,
        ]);

        $this->assertSame(100.0, MatchingConfig::get('rating_weight'));
        $this->assertSame(0.0, MatchingConfig::get('completion_weight'));
    }

    public function testSaveClampsMinRatingToZeroThroughFive(): void
    {
        MatchingConfig::save(['min_rating' => 10]);
        $this->assertSame(5.0, MatchingConfig::get('min_rating'));

        MatchingConfig::save(['min_rating' => -3]);
        $this->assertSame(0.0, MatchingConfig::get('min_rating'));
    }

    public function testSaveClampsMaxActiveJobsToOneThroughOneThousand(): void
    {
        MatchingConfig::save(['max_active_jobs' => 0]);
        $this->assertSame(1.0, MatchingConfig::get('max_active_jobs'));

        MatchingConfig::save(['max_active_jobs' => 5000]);
        $this->assertSame(1000.0, MatchingConfig::get('max_active_jobs'));
    }

    public function testSaveClampsCandidatePoolSizeToOneThroughOneHundred(): void
    {
        MatchingConfig::save(['candidate_pool_size' => 0]);
        $this->assertSame(1.0, MatchingConfig::get('candidate_pool_size'));

        MatchingConfig::save(['candidate_pool_size' => 999]);
        $this->assertSame(100.0, MatchingConfig::get('candidate_pool_size'));
    }

    public function testSaveWithMissingKeysFallsBackToDefaults(): void
    {
        MatchingConfig::save([]);

        foreach (MatchingConfig::DEFAULTS as $key => $default) {
            $this->assertSame((float)$default, MatchingConfig::get($key));
        }
    }
}
