<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkParser;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSwitchStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
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

    public function testCtkSwitchWithMatchingCaseScenario(): void
    {
        // Only the matching case's branch runs; output is that branch's data
        $this->executeAndAssertOutput('switch: Switch task with matching case');
    }

    public function testCtkSwitchWithImplicitDefaultScenario(): void
    {
        // No case matches, the switch itself routes to end: the workflow
        // output is the unmodified input.
        $this->executeAndAssertOutput('switch: Switch task with implicit default case');
    }

    public function testCtkSwitchWithExplicitDefaultScenario(): void
    {
        // The case without `when` catches everything the earlier cases missed
        $this->executeAndAssertOutput('switch: Switch task with explicit default case');
    }

    public function testSwitchRoutingSkipsUnselectedBranches(): void
    {
        $scenario = $this->scenarios['switch: Switch task with matching case'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run(
            $definition,
            new FlowState(FlowStatus::PENDING),
            $context,
            [new OwsSetStepExecutor(), new OwsSwitchStepExecutor()],
        );

        self::assertSame('completed', $state->status->value);
        self::assertSame('completed', $state->steps['switchColor']->status->value);
        self::assertSame('completed', $state->steps['setRed']->status->value);
        self::assertSame('skipped', $state->steps['setGreen']->status->value);
        self::assertSame('skipped', $state->steps['setBlue']->status->value);
    }

    public function testSwitchExecutorRequiresOwsContext(): void
    {
        $scenario = $this->scenarios['switch: Switch task with matching case'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        $context = new ArrayFlowContext([]);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, [new OwsSwitchStepExecutor()]);

        // The core execution wrapper converts executor exceptions into failed
        // steps (reported through the FlowFailureReporter).
        $step = $state->steps['switchColor'] ?? null;
        self::assertNotNull($step, "Step 'switchColor' must be present in state");
        self::assertSame('failed', $step->status->value);
        self::assertSame('step_execution_failed', $step->metadata['error_code'] ?? null);
    }

    public function testCtkForTaskScenario(): void
    {
        // Iteration with named item and default index, accumulated per item
        $this->executeAndAssertOutput('for: For Task');
    }

    public function testCtkForkCompetingBranchesScenario(): void
    {
        // compete: true - the first completing (declared) branch wins; the
        // scenario pins the output shape ("colors containing 1 items") rather
        // than a full output document.
        $scenario = $this->scenarios['branch: Fork Task With Competing Concurrent Sub Tasks'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, $this->executors());
        self::assertSame('completed', $state->status->value);

        $output = $context->lastOutput();
        self::assertIsArray($output);
        self::assertArrayHasKey('colors', $output);
        self::assertCount(1, $output['colors']);
    }

    public function testCtkEmitTaskScenario(): void
    {
        $scenario = $this->scenarios['emit: Emit Task'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, $this->executors());
        self::assertSame('completed', $state->status->value);

        $output = $context->lastOutput();
        self::assertIsArray($output);
        // Generated CloudEvent envelope properties
        foreach (['id', 'specversion', 'time', 'source', 'type', 'data'] as $property) {
            self::assertArrayHasKey($property, $output, "Emitted event must have '{$property}'");
        }
        self::assertSame('1.0', $output['specversion']);
        self::assertSame('https://fake-source.com', $output['source']);
        self::assertSame('com.fake-source.user.greeted.v1', $output['type']);
        self::assertSame(['greetings' => 'Hello John Doe!'], $output['data']);
    }

    public function testCtkRaiseTaskScenario(): void
    {
        $scenario = $this->scenarios['raise: Raise task with inline error'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, $this->executors());

        $step = $state->steps['raiseError'] ?? null;
        self::assertNotNull($step);
        self::assertSame('failed', $step->status->value);
        self::assertFalse($step->retriable ?? true);
        self::assertSame(
            [
                'status' => 400,
                'type' => 'https://serverlessworkflow.io/errors/types/compliance',
                'title' => 'Compliance Error',
                'instance' => '/do/0/raiseError',
            ],
            $step->metadata['ows_error'] ?? null,
        );
        // The workflow faults: a critical non-retriable failure is blocked
        // (it cannot be retried and needs operator intervention).
        self::assertSame('blocked', $state->status->value);
    }

    public function testCtkTryHandleCaughtErrorScenario(): void
    {
        $scenario = $this->scenarios['try: Try Handle Caught Error'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        // The http call faults with a 404 communication error, which the
        // catch filters match; the handler runs with the error bound.
        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, $this->executors(404));

        self::assertSame('completed', $state->status->value);
        self::assertSame('failed', $state->steps['getPet']->status->value);
        self::assertSame('completed', $state->steps['setError']->status->value);

        $output = $context->lastOutput();
        self::assertIsArray($output);
        self::assertSame('https://serverlessworkflow.io/dsl/errors/types/communication', $output['error']['type'] ?? null);
        self::assertSame(404, $output['error']['status'] ?? null);
        self::assertSame('/do/0/tryGetPet/try/0/getPet', $output['error']['instance'] ?? null);
    }

    public function testCtkTryRaiseUncaughtErrorScenario(): void
    {
        $scenario = $this->scenarios['try: Try Raise Uncaught Error'] ?? null;
        self::assertNotNull($scenario);
        $definition = $this->openWorkflow->registerOwsDefinition($scenario->definition)->flowDefinition;
        self::assertNotNull($definition);

        // The catch filters expect 503; the fault is 404 - uncaught: the
        // workflow faults with the original error.
        $context = new OwsWorkflowContext($scenario->input ?? []);
        $state = $this->runner->run($definition, new FlowState(FlowStatus::PENDING), $context, $this->executors(404));

        self::assertSame('blocked', $state->status->value);
        $step = $state->steps['setError'] ?? null;
        self::assertNotNull($step);
        self::assertSame('failed', $step->status->value);
        self::assertSame(404, $step->metadata['ows_error']['status'] ?? null);
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
     * The full OWS runtime executor set. The fault-recording decorator wraps
     * a fake host executor standing in for `call: http`, so try/catch faults
     * route to their catch handlers.
     *
     * @return list<\Webong\WorkFlow\Contracts\FlowStepExecutor>
     */
    private function executors(int $httpStatus = 404): array
    {
        $http = new class($httpStatus) implements \Webong\WorkFlow\Contracts\FlowStepExecutor {
            public function __construct(private readonly int $status) {}

            public function supports(\Webong\WorkFlow\ValueObjects\FlowStepDefinition $step): bool
            {
                return ($step->metadata['ows_type'] ?? null) === 'call';
            }

            public function execute(
                \Webong\WorkFlow\ValueObjects\FlowStepDefinition $step,
                \Webong\WorkFlow\Contracts\FlowContext $context,
                \Webong\WorkFlow\ValueObjects\StepState $previous,
            ): \Webong\WorkFlow\ValueObjects\StepResult {
                throw new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsFaultException(
                    errorType: 'https://serverlessworkflow.io/dsl/errors/types/communication',
                    errorStatus: $this->status,
                    errorTitle: 'Communication Error',
                );
            }
        };

        return [
            new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsFaultRecordingExecutor($http),
            new OwsSetStepExecutor(),
            new OwsSwitchStepExecutor(),
            new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsForExecutor(),
            new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsForkExecutor(),
            new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsEmitExecutor(),
            new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsRaiseExecutor(),
        ];
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
            $this->executors(),
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