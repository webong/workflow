<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;

final class ExpressionBackendIntegrationTest extends TestCase
{
    public function testExpressionEvaluatorUsesMultiBackendByDefault(): void
    {
        $evaluator = new ExpressionEvaluator(
            workflowData: ['order' => ['id' => 42]],
        );

        $this->assertContains($evaluator->getActiveBackend(), ['shell-jq', 'zest-jq']);
        $this->assertSame(42, $evaluator->evaluate('.order.id'));
    }

    public function testVariablePreprocessingThroughBackend(): void
    {
        $evaluator = new ExpressionEvaluator(
            workflowData: ['input' => 'test'],
            taskData: ['param' => 'value'],
        );

        $this->assertSame('test', $evaluator->evaluate('$workflow.input'));
        $this->assertSame('value', $evaluator->evaluate('$task.param'));
    }
}
