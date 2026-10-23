<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkParser;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\FlowState;

/**
 * Executes vendored pinned CTK scenarios end-to-end through the core
 * FlowRunner using the adapter's execution-aware data subset
 * (set tasks + input filtering, per ADR 0006).
 *
 * Each test loads the real pinned feature file, translates its definition,
 * runs it with the OWS runtime, and asserts the workflow data against the
 * CTK's expected output.
 */
class OwsRuntimeTest extends TestCase
{
    private const FEATURES_DIR = __DIR__ . '/../../ext/OpenWorkflow/Conformance/features';

    private OpenWorkflow $openWorkflow;

    private FlowRunner $runner;

    /** @var array<string, \Webong\WorkFlow\OpenWorkflow\Conformance\CtkScenario> */
    private array $scenarios = [];

    protected function setUp(): void
    {
        $this->openWorkflow = new OpenWorkflow();
        $this->runner = new FlowRunner();
        foreach ((new CtkParser())->parseDirectory(self::FEATURES_DIR) as $scenario) {
            $this->scenarios["{$scenario->feature}: {$scenario->name}"] = $scenario;
        }
    }

    public function testCtkSetTaskScenario(): void
    {
        $this->executeAndAssertOutput('set: Set Task');
    }

    public function testCtkImplicitSequenceFlowScenario(): void
    {
        $this->executeAndAssertOutput('flow: Implicit Sequence Flow');
    }

    public function testCtkExplicitSequenceFlowScenario(): void
    {
        $this->executeAndAssertOutput('flow: Explicit Sequence Flow');
    }

    public function testCtkCompositeTaskScenario(): void
    {
        // Nested composite `do` flattens into sequential set steps
        $this->executeAndAssertOutput('do: Task With Sequential Sub Tasks');
    }

    public function testCtkInputFilteringScenario(): void
    {
        // input.from filters the data scope before the set expressions run
        $this->executeAndAssertOutput('data-flow: Input Filtering');
    }

    public function testSetExecutorIgnoresNonOwsSteps(): void
    {
        $executor = new OwsSetStepExecutor();
        $definition = $this->openWorkflow->registerOwsDefinition(<<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: non-set
  version: "1.0.0"
do:
  - do-work:
      call: doSomething
YAML)->flowDefinition;

        self::assertNotNull($definition);
        $step = $definition->step('do-work');
        self::assertNotNull($step);
        $this->assertFalse($executor->supports($step));
    }

    public function testSetExecutorRejectsInvalidExpression(): void
    {
        $definition = $this->openWorkflow->registerOwsDefinition(<<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: bad-set
  version: "1.0.0"
do:
  - broken:
      set:
        value: "${ not valid jq !!! }"
YAML)->flowDefinition;

        self::assertNotNull($definition);
        $context = new OwsWorkflowContext([]);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, [new OwsSetStepExecutor()]);

        // The core execution wrapper converts executor exceptions into failed
        // steps (reported through the FlowFailureReporter), so the failure is
        // observable as step status, not as a thrown exception.
        $step = $state->steps['broken'] ?? null;
        self::assertNotNull($step, "Step 'broken' must be present in state");
        self::assertSame('failed', $step->status->value);
        self::assertSame('step_execution_failed', $step->metadata['error_code'] ?? null);
    }

    /**
     * Translate, execute, and assert the workflow data against the CTK's
     * expected output.
     */
    private function executeAndAssertOutput(string $key): void
    {
        $scenario = $this->scenarios[$key] ?? null;
        self::assertNotNull($scenario, "CTK scenario '{$key}' not found in vendored features");

        $report = $this->openWorkflow->registerOwsDefinition($scenario->definition);
        self::assertTrue($report->valid, "Definition for '{$key}' must validate: " . json_encode($report->toArray()));
        self::assertSame([], $report->unsupported, "Scenario '{$key}' must translate without unsupported constructs");
        self::assertNotNull($report->flowDefinition);

        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run(
            $report->flowDefinition,
            new FlowState(FlowStatus::PENDING),
            $context,
            [new OwsSetStepExecutor()],
        );

        $failed = array_filter($state->steps, static fn ($s) => $s->status->value === 'failed');
        self::assertSame([], $failed, "All steps of '{$key}' must complete: " . json_encode($failed));
        self::assertSame('completed', $state->status->value, "Run '{$key}' must complete");

        self::assertNotNull($scenario->expectedOutput, "Scenario '{$key}' must declare expected output");
        // OWS data flow: the workflow output is the last task's transformed
        // output (the context itself is untouched without export.as).
        self::assertSame(
            $scenario->expectedOutput,
            $context->lastOutput(),
            "Workflow output after executing '{$key}' must match the CTK expected output",
        );
    }
}