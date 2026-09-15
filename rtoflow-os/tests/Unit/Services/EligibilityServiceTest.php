<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RTOFLOW\Services\EligibilityService;

/**
 * Unit tests for EligibilityService's operator-evaluation logic.
 *
 * check()/normalize() are private and have no WordPress dependency (unlike
 * evaluate(), which needs $wpdb) — they're exercised directly via
 * reflection so this rule-matching logic gets real coverage without a
 * database.
 */
final class EligibilityServiceTest extends TestCase
{
    private EligibilityService $service;
    private ReflectionMethod $check;

    protected function setUp(): void
    {
        $this->service = new EligibilityService();
        $this->check   = new ReflectionMethod(EligibilityService::class, 'check');
        $this->check->setAccessible(true);
    }

    private function check(mixed $value, string $operator, mixed $expected): bool
    {
        return $this->check->invoke($this->service, $value, $operator, $expected);
    }

    public function testEqualsIsCaseAndWhitespaceInsensitiveForStrings(): void
    {
        $this->assertTrue($this->check(' Yes ', 'equals', 'yes'));
        $this->assertFalse($this->check('No', 'equals', 'yes'));
    }

    public function testNotEqualsTreatsMissingValueAsNotEqualToExpected(): void
    {
        // Documented edge case: answer key missing => treated as null,
        // which is not_equals to any non-null expected value.
        $this->assertTrue($this->check(null, 'not_equals', 'yes'));
    }

    public function testGreaterThanRequiresBothNumeric(): void
    {
        $this->assertTrue($this->check(20, 'gt', 18));
        $this->assertFalse($this->check(18, 'gt', 18));
        $this->assertFalse($this->check('not-a-number', 'gt', 18));
        $this->assertFalse($this->check(null, 'gt', 18));
    }

    public function testGreaterThanOrEqual(): void
    {
        $this->assertTrue($this->check(18, 'gte', 18));
        $this->assertTrue($this->check(19, 'gte', 18));
        $this->assertFalse($this->check(17, 'gte', 18));
    }

    public function testLessThan(): void
    {
        $this->assertTrue($this->check(10, 'lt', 15));
        $this->assertFalse($this->check(15, 'lt', 15));
    }

    public function testLessThanOrEqual(): void
    {
        $this->assertTrue($this->check(15, 'lte', 15));
        $this->assertTrue($this->check(10, 'lte', 15));
        $this->assertFalse($this->check(16, 'lte', 15));
    }

    public function testInMatchesNormalizedMembership(): void
    {
        $this->assertTrue($this->check('MH', 'in', ['mh', 'ka', 'tn']));
        $this->assertFalse($this->check('AP', 'in', ['mh', 'ka', 'tn']));
    }

    public function testInReturnsFalseWhenExpectedIsNotAnArray(): void
    {
        $this->assertFalse($this->check('MH', 'in', 'mh'));
    }

    public function testNotInIsInverseOfIn(): void
    {
        $this->assertTrue($this->check('AP', 'not_in', ['mh', 'ka', 'tn']));
        $this->assertFalse($this->check('MH', 'not_in', ['mh', 'ka', 'tn']));
    }

    public function testNotEmpty(): void
    {
        $this->assertTrue($this->check('some value', 'not_empty', null));
        $this->assertFalse($this->check('', 'not_empty', null));
        $this->assertFalse($this->check(null, 'not_empty', null));
        $this->assertFalse($this->check(0, 'not_empty', null));
    }

    public function testUnknownOperatorDefaultsToPassing(): void
    {
        $this->assertTrue($this->check('anything', 'made_up_operator', 'anything else'));
    }
}
