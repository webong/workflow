<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStepSchemaValidator;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\CollectingFlowEventSink;
use Webong\WorkFlow\Services\FlowOperationCatalog;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\FlowStepExecution;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowInputBinding;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStepInput;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Milestone 2: observability, optional schemas, the operation catalog,
 * and deferred-wait deadlines — all built on the Milestone 1 dataflow
 * contract without coupling core to any implementation.
 */
final class StructuredDataflowObservabilityTest extends TestCase
{
    public function test_binding_resolution_events_carry_sources_but_never_values(): void
    {
        $events = new CollectingFlowEventSink();
        $definition = new FlowDefinition('signup', [
            new FlowStepDefinition('register', 'Register', input: [
                'email' => FlowInputBinding::context('/user/email'),
                'plan' => FlowInputBinding::context('/plan', required: false),
            ]),
        ]);

        (new FlowRunner(events: $events))->run(
            $definition,
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext([
                'user' => ['email' => 'secret@example.com'],
                'plan' => 'pro',
            ]),
            [$this->echoExecutor()],
        );

        $resolved = array_values(array_filter(
            $events->events(),
            static fn ($event) => $event->type->value === 'input_resolved',
        ));
        self::assertCount(2, $resolved);
        self::assertSame('register', $resolved[0]->stepId);
        self::assertSame(['input' => 'email', 'source' => "context pointer '/user/email'", 'required' => true, 'outcome' => 'resolved'], $resolved[0]->payload);
        self::assertStringNotContainsString('secret@example.com', json_encode($events->events(), JSON_THROW_ON_ERROR), 'Values must never reach the event sink.');
    }

    public function test_unresolved_optional_and_required_bindings_produce_distinct_outcomes(): void
    {
        $events = new CollectingFlowEventSink();
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
                'coupon' => FlowInputBinding::output('total', '/coupon', required: false),
            ]),
        ]);

        $run = new FlowRun('invoice-obs', $definition);
        $state = $run->initialState()->withStep('total', new StepState(
            FlowStepStatus::COMPLETED,
            output: StepOutput::from(['total' => 100]),
        ));

        (new FlowRunner(events: $events))->run($definition, $state, new ArrayFlowContext(), [$this->echoExecutor()]);

        $unresolved = array_values(array_filter(
            $events->events(),
            static fn ($event) => $event->type->value === 'input_unresolved',
        ));
        self::assertCount(1, $unresolved);
        self::assertSame(['input' => 'coupon', 'source' => "output of step 'total' pointer '/coupon'", 'required' => false, 'outcome' => 'omitted'], $unresolved[0]->payload);
    }

    public function test_input_schema_violations_fail_closed_before_the_executor(): void
    {
        $executor = new class implements FlowStepExecutor {
            public int $calls = 0;

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->calls++;

                return StepResult::completed();
            }
        };

        $validator = new class implements FlowStepSchemaValidator {
            public function validateInput(FlowStepDefinition $step, FlowStepInput $input): array
            {
                return $input->has('amount') && is_int($input->get('amount'))
                    ? []
                    : ['amount must be an integer'];
            }

            public function validateOutput(FlowStepDefinition $step, StepOutput $output): array
            {
                return [];
            }
        };

        $definition = new FlowDefinition('invoice', [new FlowStepDefinition('charge', 'Charge')]);
        $execution = new FlowStepExecution(schemas: $validator);

        try {
            $execution->execute($executor, new FlowStepInvocation(
                step: $definition->steps[0],
                input: new FlowStepInput(['amount' => 'not-an-int']),
                context: new ArrayFlowContext(),
                previous: new StepState(),
                flowKey: 'invoice',
                runId: 'run-1',
                attempt: 1,
            ));
            self::fail('Input schema violations must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("input violates its schema: amount must be an integer", $exception->getMessage());
        }

        self::assertSame(0, $executor->calls, 'The executor must not run for invalid input.');
    }

    public function test_output_schema_violations_fail_the_step_instead_of_persisting(): void
    {
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed('done', output: StepOutput::from(['total' => 'hundred']));
            }
        };

        $validator = new class implements FlowStepSchemaValidator {
            public function validateInput(FlowStepDefinition $step, FlowStepInput $input): array
            {
                return [];
            }

            public function validateOutput(FlowStepDefinition $step, StepOutput $output): array
            {
                return is_int($output->value['total'] ?? null) ? [] : ['total must be an integer'];
            }
        };

        $definition = new FlowDefinition('invoice', [new FlowStepDefinition('charge', 'Charge')]);
        $state = (new FlowStepExecution(schemas: $validator))->execute($executor, new FlowStepInvocation(
            step: $definition->steps[0],
            input: new FlowStepInput(),
            context: new ArrayFlowContext(),
            previous: new StepState(),
            flowKey: 'invoice',
            runId: 'run-1',
            attempt: 1,
        ));

        self::assertSame(FlowStepStatus::FAILED, $state->status);
        self::assertFalse($state->retriable ?? true);
        self::assertSame('output_schema_violation', $state->metadata['error_code']);
        self::assertSame(['total must be an integer'], $state->metadata['schema_violations']);
        self::assertNull($state->output, 'A violating output must not persist as the business result.');
    }

    public function test_operation_catalog_collects_descriptors_from_supporting_executors(): void
    {
        $provider = new class implements FlowStepExecutor, FlowOperationProvider {
            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'charge';
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed();
            }

            public function operation(FlowStepDefinition $step): ?FlowOperation
            {
                return new FlowOperation(
                    key: 'invoice.charge',
                    label: 'Charge the customer',
                    summary: 'Charges the resolved amount.',
                    inputSchema: ['type' => 'object', 'required' => ['amount']],
                    outputSchema: ['type' => 'object', 'required' => ['charged']],
                    capabilities: ['money'],
                );
            }
        };
        $plain = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed();
            }
        };

        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge', dependsOn: ['total']),
        ]);

        $operations = (new FlowOperationCatalog())->describe($definition, [$provider, $plain]);

        // The first supporting executor is the authority: the plain
        // executor backs 'total' and publishes nothing.
        self::assertSame(['charge'], array_keys($operations));
        self::assertSame('invoice.charge', $operations['charge']->key);
        self::assertSame(['type' => 'object', 'required' => ['amount']], $operations['charge']->inputSchema);
        self::assertSame(['money'], $operations['charge']->capabilities);

        // Descriptors round-trip through their serialized form.
        $roundTrip = FlowOperation::fromArray($operations['charge']->toArray());
        self::assertSame($operations['charge']->toArray(), $roundTrip->toArray());
    }

    public function test_deferred_steps_carry_a_wait_deadline(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
        $deadline = time() + 600;

        $executor = new class($deadline) implements FlowStepExecutor {
            public function __construct(private readonly int $deadline) {}

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::deferred('waiting', deadlineAt: $this->deadline);
            }
        };

        $state = (new FlowRunner())->run($definition, new FlowState(FlowStatus::PENDING), new ArrayFlowContext(), [$executor]);

        $step = $state->steps['authorize'];
        self::assertSame($deadline, $step->deadlineAt);
        self::assertSame($deadline, $step->toArray()['deadline_at']);

        // Steps without a deadline serialize no key at all.
        $plain = new StepState(FlowStepStatus::PENDING, metadata: ['deferred' => true]);
        self::assertArrayNotHasKey('deadline_at', $plain->toArray());
    }

    public function test_timed_out_transitions_a_waiting_step_to_terminal_failure(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
        $run = new FlowRun('setup-1', $definition);
        $state = $run->initialState()->withStep('authorize', new StepState(
            FlowStepStatus::PENDING,
            attempts: 1,
            metadata: ['deferred' => true],
            deadlineAt: 1,
        ));

        $state = (new FlowStateTransition())->timedOut($definition, $state, 'authorize');

        $step = $state->steps['authorize'];
        self::assertSame(FlowStepStatus::FAILED, $step->status);
        self::assertFalse($step->retriable ?? true);
        self::assertSame('Wait deadline exceeded.', $step->error);
        self::assertSame(1, $step->attempts);
        self::assertSame(FlowStatus::BLOCKED, $state->status, 'A critical timed-out step blocks the run.');
    }

    public function test_timed_out_rejects_steps_that_are_not_waiting(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
        $state = new FlowState(FlowStatus::RUNNING, ['authorize' => new StepState(FlowStepStatus::COMPLETED)]);

        $this->expectException(InvalidArgumentException::class);
        (new FlowStateTransition())->timedOut($definition, $state, 'authorize');
    }

    public function test_a_late_completion_after_the_deadline_is_still_accepted(): void
    {
        // The deadline is advisory: it never rejects data. The host
        // reconciles with the external provider; core does not guess.
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
        $run = new FlowRun('setup-1', $definition);
        $state = $run->initialState()->withStep('authorize', new StepState(
            FlowStepStatus::PENDING,
            attempts: 1,
            metadata: ['deferred' => true],
            deadlineAt: 1,
        ));

        $state = (new FlowStateTransition())->complete($definition, $state, new \Webong\WorkFlow\ValueObjects\FlowDeferredCompletion(
            flowKey: 'setup',
            stepId: 'authorize',
            idempotencyKey: 'late-event',
            result: StepResult::completed('authorized', output: StepOutput::from(['token' => 't'])),
            runId: $run->id,
            attempt: 1,
        ));

        self::assertSame(FlowStepStatus::COMPLETED, $state->steps['authorize']->status);
        self::assertSame('t', $state->steps['authorize']->output?->value['token']);
    }

    private function echoExecutor(): FlowStepExecutor
    {
        return new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed();
            }
        };
    }
}
