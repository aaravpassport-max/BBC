<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use RTOFLOW\Services\ConditionGroupEvaluator;

/**
 * Unit tests for ConditionGroupEvaluator — the shared nested AND/OR
 * condition-group evaluator built to close the "condition builder is
 * flat, AND-only" gap in WorkflowEngineService::evaluateGuard(). Ported
 * from the real-execution harness /tmp/harness3_condition_group.php that
 * verified this logic directly against WorkflowEngineService during the
 * gap-fix engagement; assertions here reproduce the exact same scenarios.
 *
 * Pure logic, no database dependency — evaluate() takes a condition tree
 * and a context array and returns bool.
 */
final class ConditionGroupEvaluatorTest extends TestCase
{
    public function testFlatLegacyListIsTreatedAsImplicitAnd(): void
    {
        $legacy = [
            ['field' => 'documents_uploaded', 'op' => 'equals', 'value' => true],
            ['field' => 'amount', 'op' => 'gt', 'value' => 100],
        ];
        $this->assertTrue(ConditionGroupEvaluator::evaluate($legacy, ['documents_uploaded' => true, 'amount' => 150]));
        $this->assertFalse(ConditionGroupEvaluator::evaluate($legacy, ['documents_uploaded' => true, 'amount' => 50]));
    }

    public function testEmptyConditionsAlwaysPass(): void
    {
        $this->assertTrue(ConditionGroupEvaluator::evaluate([], ['anything' => 'x']));
        $this->assertTrue(ConditionGroupEvaluator::evaluate('', ['anything' => 'x']));
    }

    public function testNestedAndOfOrGroupWithThirdCondition(): void
    {
        // AND( OR(a, b), c ) — amount too low should fail even if a or b pass
        $tree = [
            'operator' => 'AND',
            'conditions' => [
                [
                    'operator' => 'OR',
                    'conditions' => [
                        ['field' => 'city', 'op' => 'equals', 'value' => 'Mumbai'],
                        ['field' => 'city', 'op' => 'equals', 'value' => 'Delhi'],
                    ],
                ],
                ['field' => 'amount', 'op' => 'gt', 'value' => 1000],
            ],
        ];
        $this->assertFalse(ConditionGroupEvaluator::evaluate($tree, ['city' => 'Mumbai', 'amount' => 500]));
        $this->assertTrue(ConditionGroupEvaluator::evaluate($tree, ['city' => 'Mumbai', 'amount' => 1500]));
        $this->assertFalse(ConditionGroupEvaluator::evaluate($tree, ['city' => 'Pune', 'amount' => 1500]));
    }

    public function testDeepOrOfTwoAndBranches(): void
    {
        // OR( AND(a,b), AND(c,d) )
        $tree = [
            'operator' => 'OR',
            'conditions' => [
                [
                    'operator' => 'AND',
                    'conditions' => [
                        ['field' => 'role', 'op' => 'equals', 'value' => 'admin'],
                        ['field' => 'active', 'op' => 'equals', 'value' => true],
                    ],
                ],
                [
                    'operator' => 'AND',
                    'conditions' => [
                        ['field' => 'role', 'op' => 'equals', 'value' => 'staff'],
                        ['field' => 'approved', 'op' => 'equals', 'value' => true],
                    ],
                ],
            ],
        ];
        $this->assertTrue(ConditionGroupEvaluator::evaluate($tree, ['role' => 'admin', 'active' => true, 'approved' => false]));
        $this->assertTrue(ConditionGroupEvaluator::evaluate($tree, ['role' => 'staff', 'active' => false, 'approved' => true]));
        $this->assertFalse(ConditionGroupEvaluator::evaluate($tree, ['role' => 'staff', 'active' => false, 'approved' => false]));
    }
}
