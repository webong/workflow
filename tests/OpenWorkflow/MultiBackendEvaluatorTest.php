<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\Expression\MultiBackendEvaluator;
use Webong\WorkFlow\OpenWorkflow\Expression\ShellJqEvaluator;
use Webong\WorkFlow\OpenWorkflow\Expression\ZestJqEvaluator;

class MultiBackendEvaluatorTest extends TestCase
{
    public function testFallsBackToZestJqWhenShellJqMissing(): void
    {
        // Point at a binary that does not exist
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $this->assertFalse($multi->getActiveBackend() === 'shell-jq');
        $this->assertSame('zest-jq', $multi->getActiveBackend());
        $this->assertSame('multi-backend (zest-jq)', $multi->getName());
    }

    public function testEvaluatesViaFallbackWhenShellJqMissing(): void
    {
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $result = $multi->evaluate('.order.id', ['order' => ['id' => 123]]);
        $this->assertSame(123, $result);

        $bool = $multi->evaluate('.order.status == "pending"', ['order' => ['status' => 'pending']]);
        $this->assertTrue($bool);
    }

    public function testAnalyzeViaFallback(): void
    {
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $supported = $multi->analyze('.items | map(.qty) | add');
        $this->assertTrue($supported['supported']);

        $unsupported = $multi->analyze('this is not valid jq !!!');
        $this->assertFalse($unsupported['supported']);
        $this->assertNotEmpty($unsupported['unsupportedFeatures']);
    }

    public function testExpressionEvaluatorUsesMultiBackendByDefault(): void
    {
        $evaluator = new ExpressionEvaluator(
            workflowData: ['order' => ['id' => 42]],
        );

        $this->assertSame('zest-jq', $evaluator->getActiveBackend());
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