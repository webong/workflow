<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\FlowStepExecution;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\JsonPointer;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowInputBinding;
use Webong\WorkFlow\ValueObjects\FlowStepInput;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Milestone 1: structured dataflow. Step outputs are first-class values
 * (never metadata), bindings resolve declaratively, and required
 * unresolved bindings fail closed before an executor runs.
 */
final class StructuredDataflowTest extends TestCase
{
    public function test_output_absence_is_distinguishable_from_a_present_null(): void
    {
        $absent = new StepState(FlowStepStatus::COMPLETED);
        $presentNull = new StepState(FlowStepStatus::COMPLETED, output: StepOutput::null());

        self::assertNull($absent->output);
        self::assertNotNull($presentNull->output);
        self::assertNull($presentNull->output->value);

        $absentData = $absent->toArray();
        $presentNullData = $presentNull->toArray();

        self::assertArrayNotHasKey('output', $absentData);
        self::assertArrayHasKey('output', $presentNullData);
        self::assertNull($presentNullData['output']);

        // Round trips preserve the distinction.
        self::assertNull(StepState::fromArray($absentData)->output);
        self::assertNotNull(StepState::fromArray($presentNullData)->output);
    }

    public function test_input_get_distinguishes_a_present_null_from_an_absent_value(): void
    {
        $input = new FlowStepInput(['present' => null]);

        self::assertTrue($input->has('present'));
        self::assertNull($input->get('present', 'fallback'));
        self::assertFalse($input->has('absent'));
        self::assertSame('fallback', $input->get('absent', 'fallback'));
    }

    public function test_step_output_rejects_values_that_cannot_cross_json_boundaries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow payloads must contain only JSON-compatible values.');

        StepOutput::from(new \stdClass());
    }

    public function test_scalar_object_and_list_outputs_round_trip(): void
    {
        $serializer = new DefaultFlowStateSerializer();

        $outputs = [
            'scalar' => 100,
            'string' => 'approved',
            'null' => null,
            'object' => ['total' => 100, 'nested' => ['deep' => true]],
            'list' => [1, 2, 3],
        ];

        foreach ($outputs as $name => $value) {
            $state = new FlowState(FlowStatus::RUNNING, [
                'emit' => new StepState(FlowStepStatus::COMPLETED, output: StepOutput::from($value)),
            ]);

            $roundTrip = $serializer->deserialize($serializer->serialize($state));

            self::assertNotNull($roundTrip->steps['emit']->output, "Output '{$name}' must survive");
            self::assertSame($value, $roundTrip->steps['emit']->output->value, "Output '{$name}' must be identical");
        }
    }

    public function test_old_state_documents_without_output_still_load(): void
    {
        $legacy = [
            'status' => 'running',
            'steps' => [
                'authorize' => [
                    'status' => 'completed',
                    'message' => 'Authorized before outputs existed',
                    'attempts' => 1,
                    'metadata' => ['deferred' => false],
                ],
            ],
            'version' => 1,
        ];

        $state = (new DefaultFlowStateSerializer())->deserialize($legacy);

        self::assertSame(FlowStatus::RUNNING, $state->status);
        self::assertNull($state->steps['authorize']->output);
        self::assertSame('Authorized before outputs existed', $state->steps['authorize']->message);

        // Re-serializing an output-less state must not invent an output key.
        $serialized = $state->toArray();
        self::assertArrayNotHasKey('output', $serialized['steps']['authorize']);
    }

    public function test_a_malformed_output_value_is_ignored_on_load(): void
    {
        $state = StepState::fromArray([
            'status' => 'completed',
            'output' => ['ok' => true],
        ]);
        self::assertNotNull($state->output);
    }

    public function test_two_step_flow_emits_output_persists_and_resolves_it(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
            ]),
        ]);

        $invocations = [];
        $executor = new class($invocations) implements FlowStepExecutor {
            /** @param list<array<string, mixed>> $log */
            public function __construct(public array &$log) {}

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->log[] = ['step' => $invocation->step->id, 'input' => $invocation->input->all()];

                return match ($invocation->step->id) {
                    'total' => StepResult::completed('computed', output: StepOutput::from(['total' => 100])),
                    // The first charge attempt waits for the payment provider.
                    'charge' => $invocation->previous->attempts === 0
                        ? StepResult::deferred('waiting for the payment provider')
                        : StepResult::completed('charged', output: StepOutput::from([
                            'charged' => $invocation->input->get('amount'),
                        ])),
                    default => throw new \RuntimeException('unexpected step'),
                };
            }
        };

        $run = new FlowRun('invoice-1', $definition);
        $store = new RunScopedFlowStateStore(new InMemoryFlowStateStore(), $run->id);
        $runner = new FlowRunner();

        // First pass: total emits {"total": 100}; charge reads it through
        // /total and defers to the payment provider.
        $state = $runner->run($definition, $run->initialState(), new ArrayFlowContext(), [$executor]);
        $store->put($definition->key, $state);

        self::assertSame(100, $state->steps['total']->output?->value['total']);
        self::assertCount(2, $invocations);
        self::assertSame(100, $invocations[1]['input']['amount']);
        self::assertTrue((bool) ($state->steps['charge']->metadata['deferred'] ?? false));

        // Reload the persisted state: the structured outputs survived.
        $reloaded = $store->get($definition->key);
        self::assertNotNull($reloaded);
        self::assertSame(['total' => 100], $reloaded->steps['total']->toArray()['output']);

        // Complete the waiting attempt with a structured result.
        $state = $store->mutate($definition->key, static fn (?FlowState $current): FlowState => (new FlowStateTransition())->complete(
            $definition,
            $current ?? throw new \RuntimeException('state missing'),
            new FlowDeferredCompletion(
                flowKey: 'invoice',
                stepId: 'charge',
                idempotencyKey: 'charge-event-1',
                result: StepResult::completed('charged', output: StepOutput::from(['charged' => 100])),
                runId: $run->id,
                attempt: 1,
            ),
        ));
        self::assertSame(['charged' => 100], $state->steps['charge']->output?->value);

        // Resume from a fresh reload: completed steps are not repeated and
        // the flow finishes without metadata transport.
        $reloaded = $store->get($definition->key) ?? throw new \RuntimeException('state missing');
        $state = $runner->run($definition, $reloaded, new ArrayFlowContext(), [$executor]);
        $store->put($definition->key, $state);

        self::assertSame(FlowStatus::COMPLETED, $state->status);
        self::assertCount(2, $invocations, 'Completed steps must not run again');
        self::assertSame(['total' => 100], $state->toArray()['steps']['total']['output']);
        self::assertSame(['charged' => 100], $state->toArray()['steps']['charge']['output']);
    }

    public function test_context_bindings_resolve_from_the_run_context(): void
    {
        $definition = new FlowDefinition('signup', [
            new FlowStepDefinition('register', 'Register', input: [
                'email' => FlowInputBinding::context('/user/email'),
                'plan' => FlowInputBinding::context('/plan'),
            ]),
        ]);

        $executor = new class implements FlowStepExecutor {
            public ?FlowStepInput $seen = null;

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->seen = $invocation->input;

                return StepResult::completed();
            }
        };

        (new FlowRunner())->run(
            $definition,
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext([
                'user' => ['email' => 'user@example.com'],
                'plan' => null,
            ]),
            [$executor],
        );

        self::assertNotNull($executor->seen);
        self::assertTrue($executor->seen->has('email'));
        self::assertSame('user@example.com', $executor->seen->get('email'));
        // A context null is a present null, not a missing binding.
        self::assertTrue($executor->seen->has('plan'));
        self::assertNull($executor->seen->get('plan'));
    }

    public function test_json_pointer_escapes_and_list_indexes(): void
    {
        // RFC 6901: ~1 decodes to '/', then ~0 decodes to '~'.
        $document = [
            'a/b' => 'slash',
            'm~n' => 'tilde',
            'items' => ['first', ['deep' => 'value'], 3],
            'c//d' => 'double',
        ];

        self::assertSame('slash', JsonPointer::resolve($document, '/a~1b')[1]);
        self::assertSame('tilde', JsonPointer::resolve($document, '/m~0n')[1]);
        self::assertSame('double', JsonPointer::resolve($document, '/c~1~1d')[1]);
        self::assertSame('first', JsonPointer::resolve($document, '/items/0')[1]);
        self::assertSame('value', JsonPointer::resolve($document, '/items/1/deep')[1]);
        self::assertSame(3, JsonPointer::resolve($document, '/items/2')[1]);
        self::assertSame($document, JsonPointer::resolve($document, '')[1]);

        // Unresolved stays unresolved (and a resolved null is present).
        self::assertFalse(JsonPointer::resolve($document, '/items/9')[0]);
        self::assertFalse(JsonPointer::resolve($document, '/missing')[0]);
        self::assertFalse(JsonPointer::resolve($document, '/items/0/deep')[0], 'Scalars have no children');
        self::assertTrue(JsonPointer::resolve(['k' => null], '/k')[0]);
        self::assertSame([false, null], JsonPointer::resolve($document, '/items/x'));
    }

    public function test_json_pointer_rejects_invalid_tilde_escapes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonPointer::resolve(['bad~2key' => 'value'], '/bad~2key');
    }

    public function test_a_required_unresolved_binding_fails_before_the_executor_runs(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
            ]),
        ]);

        $executor = new class implements FlowStepExecutor {
            public int $calls = 0;

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->calls++;

                return StepResult::completed(output: StepOutput::from(['total' => 100]));
            }
        };

        $state = new FlowState(FlowStatus::RUNNING, [
            'total' => new StepState(FlowStepStatus::COMPLETED, output: StepOutput::from(['other' => 1])),
        ]);

        try {
            (new FlowRunner())->run($definition, $state, new ArrayFlowContext(), [$executor]);
            self::fail('An unresolved required binding must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("unresolved required input 'amount'", $exception->getMessage());
            self::assertStringContainsString("output of step 'total' pointer '/total'", $exception->getMessage());
        }

        // The executor never ran and the step never executed.
        self::assertSame(0, $executor->calls);
        self::assertArrayNotHasKey('charge', $state->steps);
    }

    public function test_a_missing_output_source_fails_closed(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
            ]),
        ]);

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

        // Dependency completed before outputs existed: absent output.
        $state = new FlowState(FlowStatus::RUNNING, [
            'total' => new StepState(FlowStepStatus::COMPLETED),
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new FlowRunner())->run($definition, $state, new ArrayFlowContext(), [$executor]);
    }

    public function test_optional_unresolved_bindings_are_omitted(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
                'coupon' => FlowInputBinding::output('total', '/coupon', required: false),
                'note' => FlowInputBinding::context('/note', required: false),
            ]),
        ]);

        $executor = new class implements FlowStepExecutor {
            public ?FlowStepInput $seen = null;

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->seen = $invocation->input;

                return StepResult::completed();
            }
        };

        $state = new FlowState(FlowStatus::RUNNING, [
            'total' => new StepState(FlowStepStatus::COMPLETED, output: StepOutput::from(['total' => 100])),
        ]);

        (new FlowRunner())->run($definition, $state, new ArrayFlowContext(), [$executor]);

        self::assertNotNull($executor->seen);
        self::assertTrue($executor->seen->has('amount'));
        self::assertSame(100, $executor->seen->get('amount'));
        // Optional unresolved bindings are absent, not null.
        self::assertFalse($executor->seen->has('coupon'));
        self::assertFalse($executor->seen->has('note'));
    }

    public function test_output_references_to_non_dependencies_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("reads output of step 'later', which is not a declared dependency");

        new FlowDefinition('bad', [
            new FlowStepDefinition('later', 'Later'),
            new FlowStepDefinition('early', 'Early', input: [
                'value' => FlowInputBinding::output('later', '/value'),
            ]),
        ]);
    }

    public function test_output_references_to_unknown_steps_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("reads output of unknown step 'ghost'");

        new FlowDefinition('bad', [
            new FlowStepDefinition('solo', 'Solo', input: [
                'value' => FlowInputBinding::output('ghost', '/value'),
            ]),
        ]);
    }

    public function test_binding_definitions_round_trip_through_the_serialized_definition(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
                'note' => FlowInputBinding::context('/note', required: false),
            ]),
        ]);

        $roundTrip = FlowDefinition::fromArray($definition->toArray());

        self::assertSame($definition->toArray(), $roundTrip->toArray());
        self::assertTrue($roundTrip->steps[1]->input['amount']->required);
        self::assertFalse($roundTrip->steps[1]->input['note']->required);

        // A definition without bindings serializes exactly like before.
        $plain = new FlowDefinition('plain', [new FlowStepDefinition('only', 'Only')]);
        self::assertArrayNotHasKey('input', $plain->toArray()['steps'][0]);
    }

    public function test_deferred_completion_can_provide_structured_output(): void
    {
        $definition = new FlowDefinition('approval', [
            new FlowStepDefinition('approve', 'Approve'),
            new FlowStepDefinition('notify', 'Notify', dependsOn: ['approve'], input: [
                'decision' => FlowInputBinding::output('approve', '/decision'),
            ]),
        ]);

        $run = new FlowRun('approval-1', $definition);
        $state = $run->initialState()->withStep('approve', new StepState(
            FlowStepStatus::PENDING,
            attempts: 1,
            metadata: ['deferred' => true],
        ));

        $state = (new FlowStateTransition())->complete($definition, $state, new FlowDeferredCompletion(
            flowKey: 'approval',
            stepId: 'approve',
            idempotencyKey: 'event-1',
            result: StepResult::completed('Approved', output: StepOutput::from(['decision' => 'approved'])),
            runId: $run->id,
            attempt: 1,
        ));

        self::assertSame(FlowStepStatus::COMPLETED, $state->steps['approve']->status);
        self::assertSame('approved', $state->steps['approve']->output?->value['decision']);

        // A duplicate with the same output stays idempotent...
        $duplicate = (new FlowStateTransition())->complete($definition, $state, new FlowDeferredCompletion(
            flowKey: 'approval',
            stepId: 'approve',
            idempotencyKey: 'event-1',
            result: StepResult::completed('Approved', output: StepOutput::from(['decision' => 'approved'])),
            runId: $run->id,
            attempt: 1,
        ));
        self::assertSame($state->toArray(), $duplicate->toArray());

        // ...but reusing the event id with a different output is a conflict.
        $this->expectException(InvalidArgumentException::class);
        (new FlowStateTransition())->complete($definition, $state, new FlowDeferredCompletion(
            flowKey: 'approval',
            stepId: 'approve',
            idempotencyKey: 'event-1',
            result: StepResult::completed('Approved', output: StepOutput::from(['decision' => 'declined'])),
            runId: $run->id,
            attempt: 1,
        ));
    }

    public function test_retries_replace_the_output_with_the_latest_attempt(): void
    {
        $definition = new FlowDefinition('setup', [
            new FlowStepDefinition('authorize', 'Authorize', retriable: true),
        ]);

        $attempt = 0;
        $executor = new class($attempt) implements FlowStepExecutor {
            public function __construct(public int &$attempt) {}

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->attempt++;

                return $this->attempt === 1
                    ? StepResult::failed('try again', retriable: true)
                    : StepResult::completed('ok', output: StepOutput::from(['token' => 't2']));
            }
        };

        $state = (new FlowRunner())->run(
            $definition,
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext(),
            [$executor],
        );

        self::assertSame(FlowStepStatus::FAILED, $state->steps['authorize']->status);
        self::assertNull($state->steps['authorize']->output);

        // Elapse the backoff and retry: the completed output replaces the
        // failed attempt's (absent) output.
        $state = new FlowState(
            $state->status,
            ['authorize' => new StepState(FlowStepStatus::FAILED, retriable: true, attempts: 1, nextRetryAt: 1)],
            version: $state->version,
        );
        $state = (new FlowRunner())->run($definition, $state, new ArrayFlowContext(), [$executor]);

        self::assertSame(FlowStepStatus::COMPLETED, $state->steps['authorize']->status);
        self::assertSame('t2', $state->steps['authorize']->output?->value['token']);
    }

    public function test_resolved_input_travels_only_through_the_invocation(): void
    {
        $definition = new FlowDefinition('invoice', [
            new FlowStepDefinition('total', 'Compute total'),
            new FlowStepDefinition('charge', 'Charge customer', dependsOn: ['total'], input: [
                'amount' => FlowInputBinding::output('total', '/total'),
            ]),
        ]);

        $executor = new class implements FlowStepExecutor {
            public ?array $contextKeys = null;

            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                $this->contextKeys = array_keys($invocation->context->all());

                return StepResult::completed();
            }
        };

        $state = new FlowState(FlowStatus::RUNNING, [
            'total' => new StepState(FlowStepStatus::COMPLETED, output: StepOutput::from(['total' => 100])),
        ]);

        (new FlowRunner())->run($definition, $state, new ArrayFlowContext(['channel' => 'web']), [$executor]);

        self::assertNotNull($executor->contextKeys);
        self::assertSame(['channel'], array_values(array_diff($executor->contextKeys, ['_workflow'])));
    }

    public function test_inline_driver_round_trips_output_through_the_execution_receipt(): void
    {
        $definition = new FlowDefinition('invoice', [new FlowStepDefinition('total', 'Compute total')]);
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed('done', output: StepOutput::from(['total' => 100]));
            }
        };

        $receipt = (new FlowExecutionDispatcher([
            new InlineFlowExecutionDriver(new FlowRunner(), [$executor]),
        ]))->dispatch(new FlowExecutionRequest(
            definition: $definition,
            state: new FlowState(FlowStatus::PENDING),
        ));

        self::assertSame(['total' => 100], $receipt->state?->steps['total']->output?->value);
    }
}
