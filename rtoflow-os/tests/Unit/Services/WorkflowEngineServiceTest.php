<?php

declare(strict_types=1);

namespace RTOFLOW\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use RTOFLOW\Tests\FakeWpdb;
use RTOFLOW\Services\WorkflowEngineService;

/**
 * Unit tests for WorkflowEngineService::canTransition()/attemptTransition(),
 * covering the guard-condition enforcement and legacy-fallback behavior
 * built during the gap-fix engagement (guard_condition_json was previously
 * a schema-only stub that never actually restricted transitions). Ported
 * from the real-execution harnesses /tmp/verify_leadservice_workflow_wiring.php
 * and /tmp/harness3b_workflow_guard.php.
 */
final class WorkflowEngineServiceTest extends TestCase
{
    public function testGuardConditionBlocksTransitionWhenItFails(): void
    {
        $db = new FakeWpdb();
        $db->tables['wp_rto_workflow_definitions'] = [
            ['id' => 1, 'service_id' => 5, 'is_active' => 1, 'name' => 'Custom WF'],
        ];
        $db->tables['wp_rto_workflow_transitions'] = [[
            'id' => 10, 'workflow_definition_id' => 1,
            'from_status' => 'docs_pending', 'to_status' => 'docs_verified',
            'requires_role' => null,
            'guard_condition_json' => json_encode([['field' => 'documents_uploaded', 'op' => 'equals', 'value' => true]]),
            'side_effect_json' => null,
        ]];

        $engine = new WorkflowEngineService($db, 'wp_');
        $result = $engine->attemptTransition(5, 'docs_pending', 'docs_verified', null, ['documents_uploaded' => false]);

        $this->assertFalse($result['allowed']);
        $this->assertNotEmpty($result['reason'] ?? '');
    }

    public function testGuardConditionAllowsTransitionWhenItPasses(): void
    {
        $db = new FakeWpdb();
        $db->tables['wp_rto_workflow_definitions'] = [
            ['id' => 1, 'service_id' => 5, 'is_active' => 1, 'name' => 'Custom WF'],
        ];
        $db->tables['wp_rto_workflow_transitions'] = [[
            'id' => 10, 'workflow_definition_id' => 1,
            'from_status' => 'docs_pending', 'to_status' => 'docs_verified',
            'requires_role' => null,
            'guard_condition_json' => json_encode([['field' => 'documents_uploaded', 'op' => 'equals', 'value' => true]]),
            'side_effect_json' => null,
        ]];

        $engine = new WorkflowEngineService($db, 'wp_');
        $result = $engine->attemptTransition(5, 'docs_pending', 'docs_verified', null, ['documents_uploaded' => true]);

        $this->assertTrue($result['allowed']);
    }

    public function testNestedGuardConditionIsSupported(): void
    {
        $db = new FakeWpdb();
        $db->tables['wp_rto_workflow_definitions'] = [
            ['id' => 1, 'service_id' => 5, 'is_active' => 1, 'name' => 'Custom WF'],
        ];
        $db->tables['wp_rto_workflow_transitions'] = [[
            'id' => 11, 'workflow_definition_id' => 1,
            'from_status' => 'assigned', 'to_status' => 'in_progress',
            'requires_role' => null,
            'guard_condition_json' => json_encode([
                'operator' => 'AND',
                'conditions' => [
                    ['field' => 'role', 'op' => 'equals', 'value' => 'staff'],
                    ['field' => 'approval', 'op' => 'equals', 'value' => true],
                ],
            ]),
            'side_effect_json' => null,
        ]];

        $engine = new WorkflowEngineService($db, 'wp_');
        $this->assertFalse($engine->attemptTransition(5, 'assigned', 'in_progress', null, ['role' => 'staff', 'approval' => false])['allowed']);
        $this->assertTrue($engine->attemptTransition(5, 'assigned', 'in_progress', null, ['role' => 'staff', 'approval' => true])['allowed']);
    }

    public function testNoConfiguredWorkflowFallsThroughToLegacyDefaultTransitions(): void
    {
        $db = new FakeWpdb();
        $db->tables['wp_rto_workflow_definitions'] = []; // no active custom workflow for this service

        $engine = new WorkflowEngineService($db, 'wp_');

        // docs_pending -> docs_verified is a legal default transition
        $this->assertTrue($engine->attemptTransition(99, 'docs_pending', 'docs_verified', null, [])['allowed']);
        // completed is a terminal state in the default map
        $this->assertFalse($engine->attemptTransition(99, 'completed', 'in_progress', null, [])['allowed']);
    }
}
