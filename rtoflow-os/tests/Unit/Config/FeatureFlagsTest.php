<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RTOFLOW\Config\FeatureFlags;

/**
 * Unit tests for FeatureFlags's defaults and enable/disable/set logic.
 *
 * Uses the get_option()/update_option() stubs from tests/bootstrap.php.
 * FeatureFlags caches its loaded flags in a static property, so each test
 * resets that cache and the in-memory option store first.
 */
final class FeatureFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__rtoflow_test_options'] = [];
        $prop = new ReflectionProperty(FeatureFlags::class, 'flags');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testCoreModulesAreEnabledByDefault(): void
    {
        $this->assertTrue(FeatureFlags::is_enabled('lead_management'));
        $this->assertTrue(FeatureFlags::is_enabled('vendor_management'));
        $this->assertTrue(FeatureFlags::is_enabled('payment_collection'));
        $this->assertTrue(FeatureFlags::is_enabled('document_management'));
    }

    public function testOptionalModulesFollowDocumentedDefaults(): void
    {
        $this->assertFalse(FeatureFlags::is_enabled('whatsapp_notifications'));
        $this->assertFalse(FeatureFlags::is_enabled('ai_scoring'));
        $this->assertTrue(FeatureFlags::is_enabled('vendor_ratings'));
        $this->assertTrue(FeatureFlags::is_enabled('email_notifications'));
    }

    public function testUnknownFlagDefaultsToFalse(): void
    {
        $this->assertFalse(FeatureFlags::is_enabled('does_not_exist'));
    }

    public function testEnableTurnsFlagOn(): void
    {
        FeatureFlags::enable('ai_scoring');
        $this->assertTrue(FeatureFlags::is_enabled('ai_scoring'));
    }

    public function testDisableTurnsFlagOff(): void
    {
        FeatureFlags::disable('vendor_ratings');
        $this->assertFalse(FeatureFlags::is_enabled('vendor_ratings'));
    }

    public function testSetPersistsAcrossStaticCacheReset(): void
    {
        FeatureFlags::set('sms_notifications', true);

        // Force a reload from the (stubbed) stored option to prove set()
        // actually persisted, not just mutated the in-memory cache.
        $prop = new ReflectionProperty(FeatureFlags::class, 'flags');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->assertTrue(FeatureFlags::is_enabled('sms_notifications'));
    }

    public function testBulkSaveEnablesOnlySubmittedNonCoreFlagsAndLeavesCoreAlone(): void
    {
        FeatureFlags::bulk_save([
            'ai_scoring'             => '1',
            'whatsapp_notifications' => '1',
            // vendor_ratings intentionally omitted => should become disabled
            // core flags are never in the submitted form at all
        ]);

        $this->assertTrue(FeatureFlags::is_enabled('ai_scoring'));
        $this->assertTrue(FeatureFlags::is_enabled('whatsapp_notifications'));
        $this->assertFalse(FeatureFlags::is_enabled('vendor_ratings'));

        // Core modules are untouched by bulk_save regardless of submission.
        $this->assertTrue(FeatureFlags::is_enabled('lead_management'));
        $this->assertTrue(FeatureFlags::is_enabled('vendor_management'));
    }

    public function testAllMarksCoreModulesCorrectly(): void
    {
        $all = FeatureFlags::all();

        $this->assertTrue($all['lead_management']['core']);
        $this->assertFalse($all['ai_scoring']['core']);
        $this->assertArrayHasKey('label', $all['gst_invoicing']);
        $this->assertArrayHasKey('description', $all['gst_invoicing']);
    }
}
