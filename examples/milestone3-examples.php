<?php

declare(strict_types=1);

/**
 * Milestone 3: Scheduler, Event-Correlation, and Child Registry Examples
 *
 * This file provides concrete host-side examples for:
 * - Calling FlowStateTransition::deadlineReached() when an OWS wait expires
 * - Persisting and matching OWS listen subscriptions
 * - Authenticating and deduplicating incoming events
 * - Delivering event data through FlowDeferredCompletion
 * - Resolving child definitions by immutable flow key/version
 * - Dispatching child runs idempotently from the parent attempt
 * - Returning synchronous child output
 * - Completing an awaited parent after an asynchronous child finishes
 * - Fire-and-forget child execution
 *
 * These examples clearly distinguish reusable package contracts from
 * deployment-specific infrastructure.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Webong\WorkFlow\Contracts\FlowChildRunDispatcher;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWaitExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowChildRunReceipt;
use Webong\WorkFlow\ValueObjects\FlowChildRunRequest;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

echo "=== Milestone 3 Examples ===\n\n";

// ============================================================================
// EXAMPLE 1: Calling FlowStateTransition::deadlineReached() when OWS wait expires
// ============================================================================

echo "--- Example 1: Wait Deadline Transition ---\n";

$definition = new FlowDefinition('order-timeout', [
    new FlowStepDefinition('wait-approval', 'Wait for Approval', metadata: [
        'ows_type' => 'wait',
        'ows_wait' => 'PT10S', // 10 seconds
    ]),
    new FlowStepDefinition('notify-timeout', 'Notify Timeout', dependsOn: ['wait-approval']),
    new FlowStepDefinition('cancel-order', 'Cancel Order', dependsOn: ['notify-timeout']),
], version: 1, metadata: ['ows_dsl_version' => '1.0.3']);

$store = new InMemoryFlowStateStore();
$subject = new FlowStateSubject('order', 'order-123');
$run = new FlowRun('run-1', $definition);
$state = $run->initialState();
$store->put('run-1', $state);

echo "Initial state: {$state->status->value}\n";

// Simulate wait step starting and becoming deferred
$waitExecutor = new OwsWaitExecutor(clock: static fn (): int => 1000);
$context = new OwsWorkflowContext(['order_id' => 'order-123', 'customer' => 'acme-corp']);
$invocation = new FlowStepInvocation(
    step: $definition->step('wait-approval'),
    input: new \Webong\WorkFlow\ValueObjects\FlowStepInput([]),
    context: $context,
    previous: new StepState(),
    flowKey: $definition->key,
    runId: $run->id,
    attempt: 1,
);

$waitResult = $waitExecutor->execute($invocation);
echo "Wait step result: {$waitResult->status->value}, deadline_at: {$waitResult->deadlineAt}\n";
echo "Deadline transition: {$waitResult->metadata['deadline_transition']}\n";
echo "Deadline output: " . json_encode($waitResult->metadata['deadline_output']) . "\n";

// Apply the wait step's deferred result to state
$state = $state->withStep('wait-approval', new StepState(
    status: FlowStepStatus::PENDING,
    attempts: 1,
    retriable: false,
    updatedAt: (new DateTimeImmutable())->format(DATE_ATOM),
    metadata: $waitResult->metadata,
    deadlineAt: $waitResult->deadlineAt,
    
));
$store->put('run-1', $state);

// Simulate the deadline being reached (time advances to 1010 = 1000 + 2 seconds)
$deadlineState = $store->get('run-1');
if ($deadlineState !== null) {
    $transitionedState = (new FlowStateTransition(
        timestamp: static fn (): string => (new \DateTimeImmutable('@1010'))->format(DATE_ATOM),
        clock: static fn (): int => 1010,
    ))->deadlineReached($definition, $deadlineState, 'wait-approval');

    $store->put('run-1', $transitionedState);
    echo "After deadlineReached: {$transitionedState->steps['wait-approval']->status->value}\n";
    echo "Transitioned output: " . json_encode($transitionedState->steps['wait-approval']->output->value) . "\n";
}

// Now the flow should continue to notify-timeout
$runner = new FlowRunner();

// Simple executor for remaining steps
$notifyExecutor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool
    {
        return in_array($step->id, ['notify-timeout', 'cancel-order'], true);
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        return StepResult::completed("Executed {$invocation->step->id}");
    }
};

$completedState = $runner->run(
    $definition,
    $transitionedState,
    new OwsWorkflowContext($transitionedState->context ?? []),
    [new OwsWaitExecutor(clock: static fn (): int => 1010), $notifyExecutor],
);
echo "Final state: {$completedState->status->value}\n";
echo "Completed steps: " . implode(', ', array_keys(array_filter(
    $completedState->steps,
    static fn ($s) => $s->status->value === 'completed'
))) . "\n\n";

// ============================================================================
// EXAMPLE 2: Persisting and matching OWS listen subscriptions
// ============================================================================

echo "--- Example 2: Listen Subscription Persistence and Matching ---\n";

/**
 * Host-side subscription store for OWS listen tasks.
 * In production, this would be a database table or Redis store.
 */
class ListenSubscriptionStore
{
    /** @var array<string, array{event_type: string, run_id: string, step_id: string, filter: array, created_at: int}> */
    private array $subscriptions = [];

    public function subscribe(string $eventType, string $runId, string $stepId, array $filter): void
    {
        $key = "{$eventType}:{$runId}:{$stepId}";
        $this->subscriptions[$key] = [
            'event_type' => $eventType,
            'run_id' => $runId,
            'step_id' => $stepId,
            'filter' => $filter,
            'created_at' => time(),
        ];
    }

    /** @return array<int, array{event_type: string, run_id: string, step_id: string, filter: array}> */
    public function findMatching(string $eventType, array $eventData): array
    {
        $matches = [];
        foreach ($this->subscriptions as $sub) {
            if ($sub['event_type'] !== $eventType) {
                continue;
            }
            // Apply filter matching (simplified - in production use proper CloudEvents matching)
            if ($this->matchesFilter($sub['filter'], $eventData)) {
                $matches[] = $sub;
            }
        }
        return $matches;
    }

    private function matchesFilter(array $filter, array $eventData): bool
    {
        // Simplified filter matching - check if all filter conditions match
        foreach ($filter as $key => $expected) {
            $actual = $eventData[$key] ?? null;
            if ($actual !== $expected) {
                return false;
            }
        }
        return true;
    }

    public function unsubscribe(string $runId, string $stepId): void
    {
        $keysToRemove = [];
        foreach ($this->subscriptions as $key => $sub) {
            if ($sub['run_id'] === $runId && $sub['step_id'] === $stepId) {
                $keysToRemove[] = $key;
            }
        }
        foreach ($keysToRemove as $key) {
            unset($this->subscriptions[$key]);
        }
    }
}

$subscriptionStore = new ListenSubscriptionStore();

// Simulate a listen step execution
$listenDefinition = new FlowDefinition('event-listener', [
    new FlowStepDefinition('wait-for-event', 'Wait for Event', metadata: [
        'ows_type' => 'listen',
        'ows_listen' => [
            'to' => ['type' => 'com.example.order.created'],
            'filter' => ['customer' => 'acme-corp'],
        ],
    ]),
    new FlowStepDefinition('process-event', 'Process Event', dependsOn: ['wait-for-event']),
], version: 1, metadata: ['ows_dsl_version' => '1.0.3']);

$run = new FlowRun('listen-run-1', $listenDefinition);
$state = $run->initialState();

$store->put('listen-run-1', $state);

// Register subscription when listen step is reached
$listenStep = $listenDefinition->step('wait-for-event');
$listenConfig = $listenStep->metadata['ows_listen'] ?? [];

echo "Listen step config: " . json_encode($listenConfig) . "\n";

// Host registers subscription
$subscriptionStore->subscribe(
    $listenConfig['to']['type'] ?? 'unknown',
    $run->id,
    $listenStep->id,
    $listenConfig['filter'] ?? []
);

echo "Subscription registered for run {$run->id}, step {$listenStep->id}\n";

// Simulate event arriving
$incomingEvent = [
    'type' => 'com.example.order.created',
    'source' => 'order-service',
    'data' => [
        'order_id' => 'order-456',
        'customer' => 'acme-corp',
        'amount' => 99.99,
    ],
];

$matches = $subscriptionStore->findMatching($incomingEvent['type'], $incomingEvent['data']);
echo "Found " . count($matches) . " matching subscription(s)\n";

foreach ($matches as $match) {
    echo "  - Run: {$match['run_id']}, Step: {$match['step_id']}\n";
    
    // Host delivers event via deferred completion
    $completion = new FlowDeferredCompletion(
        flowKey: $listenDefinition->key,
        stepId: $match['step_id'],
        idempotencyKey: 'evt-' . bin2hex(random_bytes(8)),
        result: StepResult::completed('Event received', output: StepOutput::from($incomingEvent['data'])),
        runId: $match['run_id'],
        attempt: 1,
    );
    
    echo "  - Would deliver completion with idempotency key: {$completion->idempotencyKey}\n";
}

// Cleanup after delivery
$subscriptionStore->unsubscribe($run->id, $listenStep->id);
echo "Subscription removed after delivery\n\n";

// ============================================================================
// EXAMPLE 3: Authenticating and deduplicating incoming events
// ============================================================================

echo "--- Example 3: Event Authentication and Deduplication ---\n";

/**
 * Event authentication and deduplication service.
 * In production, this would integrate with your auth system and event store.
 */
class EventAuthenticator
{
    /** @var array<string, int> */
    private array $processedIds = [];

    public function authenticate(array $event, array $headers): ?string
    {
        // Verify signature, API key, or JWT
        $signature = $headers['ce-signature'] ?? '';
        $payload = json_encode($event);
        if ($payload === false) {
            return null;
        }
        $expected = hash_hmac('sha256', $payload, 'secret-key');
        
        if (! hash_equals($expected, $signature)) {
            return null; // Authentication failed
        }
        
        return $event['id'] ?? null;
    }

    public function checkAndMarkProcessed(string $eventId): bool
    {
        if (isset($this->processedIds[$eventId])) {
            return false; // Already processed
        }
        $this->processedIds[$eventId] = time();
        return true;
    }
}

$authenticator = new EventAuthenticator();

// Simulate incoming CloudEvent
$cloudEvent = [
    'id' => 'evt-001',
    'specversion' => '1.0',
    'type' => 'com.example.payment.received',
    'source' => 'payment-gateway',
    'time' => date(DATE_ATOM),
    'datacontenttype' => 'application/json',
    'data' => [
        'payment_id' => 'pay-123',
        'amount' => 50.00,
        'currency' => 'USD',
    ],
];

$headers = [
    'ce-signature' => hash_hmac('sha256', json_encode($cloudEvent), 'secret-key'),
];

$eventId = $authenticator->authenticate($cloudEvent, $headers);
if ($eventId === null) {
    echo "Authentication failed\n";
} else {
    echo "Event authenticated: {$eventId}\n";
    
    if ($authenticator->checkAndMarkProcessed($eventId)) {
        echo "Event is new, processing...\n";
        // Deliver to workflow...
    } else {
        echo "Event already processed, skipping\n";
    }
    
    // Simulate duplicate
    if ($authenticator->checkAndMarkProcessed($eventId)) {
        echo "Unexpected: duplicate not detected\n";
    } else {
        echo "Duplicate correctly rejected\n";
    }
}
echo "\n";

// ============================================================================
// EXAMPLE 4: Delivering event data through FlowDeferredCompletion
// ============================================================================

echo "--- Example 4: Delivering Event Data via FlowDeferredCompletion ---\n";

$parentDefinition = new FlowDefinition('parent-flow', [
    new FlowStepDefinition('start', 'Start'),
    new FlowStepDefinition('wait-callback', 'Wait for Callback', metadata: [
        'ows_type' => 'listen',
        'ows_listen' => [
            'to' => ['type' => 'com.example.callback'],
        ],
    ]),
    new FlowStepDefinition('finish', 'Finish', dependsOn: ['wait-callback']),
], version: 1, metadata: ['ows_dsl_version' => '1.0.3']);

$run = new FlowRun('parent-run-1', $parentDefinition);
$state = $run->initialState();

$store->put('parent-run-1', $state);

// Simulate wait-callback step execution
$listenExecutor = new \Webong\WorkFlow\OpenWorkflow\Runtime\OwsListenExecutor();
$context = new OwsWorkflowContext($state->context ?? []);
$invocation = new FlowStepInvocation(
    step: $parentDefinition->step('wait-callback'),
    input: new \Webong\WorkFlow\ValueObjects\FlowStepInput([]),
    context: $context,
    previous: new StepState(),
    flowKey: $parentDefinition->key,
    runId: $run->id,
    attempt: 1,
);

$listenResult = $listenExecutor->execute($invocation);
echo "Listen step result: {$listenResult->status->value}\n";
echo "Deferred metadata: " . json_encode($listenResult->metadata) . "\n";

// Apply the listen step's deferred result to state
$state = $state->withStep('wait-callback', new StepState(
    status: FlowStepStatus::PENDING,
    attempts: 1,
    retriable: false,
    updatedAt: (new DateTimeImmutable())->format(DATE_ATOM),
    metadata: $listenResult->metadata,
));
$store->put('run-1', $state);

// Simulate external callback arriving
$callbackData = [
    'callback_id' => 'cb-789',
    'status' => 'approved',
    'details' => 'Manual approval granted',
];

$completion = new FlowDeferredCompletion(
    flowKey: $parentDefinition->key,
    stepId: 'wait-callback',
    idempotencyKey: 'callback-' . $callbackData['callback_id'],
    result: StepResult::completed('Callback received', output: StepOutput::from($callbackData)),
    runId: $run->id,
    attempt: 1,
);

// Serialize for transport (e.g., HTTP callback endpoint)
$serialized = json_encode($completion->toArray());
echo "Serialized completion: {$serialized}\n";

// Deserialize and apply (e.g., in a webhook handler)
$deserialized = FlowDeferredCompletion::fromArray(json_decode($serialized, true));
echo "Deserialized flowKey: {$deserialized->flowKey}, stepId: {$deserialized->stepId}\n";
echo "Result status: {$deserialized->result->status->value}\n";
echo "Result output: " . json_encode($deserialized->result->output?->value) . "\n";

// Apply via FlowStateTransition
$transition = new FlowStateTransition();
$updatedState = $transition->complete($parentDefinition, $state, $deserialized);
echo "After completion: step status = {$updatedState->steps['wait-callback']->status->value}\n";
echo "Step output: " . json_encode($updatedState->steps['wait-callback']->output->value) . "\n\n";

// ============================================================================
// EXAMPLE 5: Resolving child definitions by immutable flow key/version
// ============================================================================

echo "--- Example 5: Child Definition Resolution ---\n";

/**
 * Child definition resolver - resolves child flow key/version to definition.
 * In production, this might query a database, config service, or registry.
 */
class ChildDefinitionResolver
{
    /** @var array<string, FlowDefinition> */
    private array $registry = [];

    public function register(string $flowKey, int $version, FlowDefinition $definition): void
    {
        $key = "{$flowKey}:v{$version}";
        $this->registry[$key] = $definition;
    }

    public function resolve(string $flowKey, int $version): ?FlowDefinition
    {
        return $this->registry["{$flowKey}:v{$version}"] ?? null;
    }

    public function resolveLatest(string $flowKey): ?FlowDefinition
    {
        $versions = array_filter(array_keys($this->registry), fn ($k) => str_starts_with($k, "{$flowKey}:v"));
        if ($versions === []) {
            return null;
        }
        // Return highest version
        rsort($versions);
        return $this->registry[$versions[0]];
    }
}

$resolver = new ChildDefinitionResolver();

// Register child definitions
$childV1 = new FlowDefinition('process-payment', [
    new FlowStepDefinition('charge', 'Charge Card'),
    new FlowStepDefinition('confirm', 'Confirm Payment', dependsOn: ['charge']),
], version: 1);

$childV2 = new FlowDefinition('process-payment', [
    new FlowStepDefinition('validate', 'Validate Card'),
    new FlowStepDefinition('charge', 'Charge Card', dependsOn: ['validate']),
    new FlowStepDefinition('confirm', 'Confirm Payment', dependsOn: ['charge']),
], version: 2);

$resolver->register('process-payment', 1, $childV1);
$resolver->register('process-payment', 2, $childV2);

echo "Resolved v1: " . ($resolver->resolve('process-payment', 1)?->version ?? 'not found') . "\n";
echo "Resolved v2: " . ($resolver->resolve('process-payment', 2)?->version ?? 'not found') . "\n";
echo "Resolved latest: " . ($resolver->resolveLatest('process-payment')?->version ?? 'not found') . "\n";
echo "Resolved missing: " . ($resolver->resolve('unknown', 1)?->version ?? 'not found') . "\n\n";

// ============================================================================
// EXAMPLE 6: Dispatching child runs idempotently from parent attempt
// ============================================================================

echo "--- Example 6: Idempotent Child Run Dispatch ---\n";

/**
 * Idempotent child run dispatcher using the package contracts.
 */
class IdempotentChildDispatcher implements FlowChildRunDispatcher
{
    /** @var array<string, FlowChildRunReceipt> */
    private array $dispatched = [];

    public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt
    {
        // Idempotency key combines parent run, step, and attempt
        $idempotencyKey = "{$request->parentRunId}:{$request->parentStepId}:{$request->parentAttempt}:{$request->childFlowKey}";
        
        if (isset($this->dispatched[$idempotencyKey])) {
            echo "  - Returning cached receipt for {$idempotencyKey}\n";
            return $this->dispatched[$idempotencyKey];
        }

        echo "  - Dispatching new child run: {$request->childFlowKey} (run: {$request->childRunId})\n";
        echo "  - Parent: {$request->parentFlowKey}/{$request->parentRunId}, step: {$request->parentStepId}, attempt: {$request->parentAttempt}\n";
        echo "  - Await: " . ($request->await ? 'yes' : 'no') . "\n";
        echo "  - Input: " . json_encode($request->input) . "\n";

        // In production: start child run via FlowExecutionDriver or direct execution
        $receipt = new FlowChildRunReceipt(
            childFlowKey: $request->childFlowKey,
            childRunId: $request->childRunId,
            status: FlowExecutionStatus::DISPATCHED,
        );

        $this->dispatched[$idempotencyKey] = $receipt;
        return $receipt;
    }
}

$dispatcher = new IdempotentChildDispatcher();

$parentRun = new FlowRun('parent-123', new FlowDefinition('parent', []));
$parentState = $parentRun->initialState();

$childRequest = new FlowChildRunRequest(
    childFlowKey: 'process-payment',
    childRunId: 'child-pay-1',
    input: ['amount' => 100.00, 'currency' => 'USD'],
    parentFlowKey: 'order-processing',
    parentRunId: $parentRun->id,
    parentStepId: 'charge-payment',
    parentAttempt: 1,
    await: true,
);

// First dispatch
$receipt1 = $dispatcher->dispatch($childRequest);
echo "Receipt 1: {$receipt1->childRunId}, status: {$receipt1->status->value}\n";

// Retry (same parent attempt) - should return cached
$receipt2 = $dispatcher->dispatch($childRequest);
echo "Receipt 2: {$receipt2->childRunId}, status: {$receipt2->status->value}\n";
echo "Same receipt: " . ($receipt1 === $receipt2 ? 'yes' : 'no') . "\n";

// New attempt - new dispatch
$childRequest2 = new FlowChildRunRequest(
    childFlowKey: 'process-payment',
    childRunId: 'child-pay-1-retry',
    input: ['amount' => 100.00, 'currency' => 'USD'],
    parentFlowKey: 'order-processing',
    parentRunId: $parentRun->id,
    parentStepId: 'charge-payment',
    parentAttempt: 2, // Different attempt
    await: true,
);

$receipt3 = $dispatcher->dispatch($childRequest2);
echo "Receipt 3: {$receipt3->childRunId}, status: {$receipt3->status->value}\n";
echo "Different from receipt 1: " . ($receipt3 !== $receipt1 ? 'yes' : 'no') . "\n\n";

// ============================================================================
// EXAMPLE 7: Returning synchronous child output
// ============================================================================

echo "--- Example 7: Synchronous Child Output ---\n";

/**
 * Synchronous child execution - runs inline and returns output directly.
 */
class SynchronousChildExecutor implements FlowChildRunDispatcher
{
    private FlowRunner $runner;
    private ChildDefinitionResolver $resolver;

    public function __construct(FlowRunner $runner, ChildDefinitionResolver $resolver)
    {
        $this->runner = $runner;
        $this->resolver = $resolver;
    }

    public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt
    {
        $definition = $this->resolver->resolve($request->childFlowKey, 1);
        if ($definition === null) {
            return new FlowChildRunReceipt(
                childFlowKey: $request->childFlowKey,
                childRunId: $request->childRunId,
                status: FlowExecutionStatus::FAILED,
            );
        }

        // Run child synchronously
        $childRun = new FlowRun($request->childRunId, $definition);
        $childState = $childRun->initialState();

        $finalState = $this->runner->run(
            $definition,
            $childState,
            new ArrayFlowContext($request->input),
            [] // executors would be injected
        );

        $output = $finalState->output?->value ?? null;
        $status = match ($finalState->status->value) {
            'completed' => FlowExecutionStatus::COMPLETED,
            'failed', 'blocked' => FlowExecutionStatus::FAILED,
            default => FlowExecutionStatus::DISPATCHED,
        };

        return new FlowChildRunReceipt(
            childFlowKey: $request->childFlowKey,
            childRunId: $request->childRunId,
            status: $status,
            output: $output !== null ? StepOutput::from($output) : null,
        );
    }
}

// Demonstrate the pattern (without actual runner execution)
echo "Synchronous child executor pattern:\n";
echo "1. Resolve child definition by key/version\n";
echo "2. Create child run with input as context\n";
echo "3. Run child to completion using FlowRunner\n";
echo "4. Return FlowChildRunReceipt with COMPLETED/FAILED status and output\n";
echo "5. Parent continues with child output in its context\n\n";

// ============================================================================
// EXAMPLE 8: Completing awaited parent after async child finishes
// ============================================================================

echo "--- Example 8: Async Child Completion Resumes Parent ---\n";

/**
 * Async child completion handler.
 * Called when an async child workflow finishes (via webhook, polling, or event).
 */
class AsyncChildCompletionHandler
{
    private FlowStateTransition $transition;
    private InMemoryFlowStateStore $store;

    public function __construct(InMemoryFlowStateStore $store)
    {
        $this->store = $store;
        $this->transition = new FlowStateTransition();
    }

    /**
     * Handle child completion callback.
     */
    public function handleChildCompletion(
        string $parentRunId,
        string $parentStepId,
        FlowDefinition $parentDefinition,
        int $parentAttempt,
        array $childOutput,
        string $childStatus
    ): FlowState {
        $parentState = $this->store->get($parentRunId);
        if ($parentState === null) {
            throw new \RuntimeException("Parent run {$parentRunId} not found");
        }

        // Create completion for the awaited child step
        $completion = new FlowDeferredCompletion(
            flowKey: $parentDefinition->key,
            stepId: $parentStepId,
            idempotencyKey: "child-completion-{$parentRunId}-{$parentStepId}-{$parentAttempt}",
            result: StepResult::completed(
                $childStatus === 'completed' ? 'Child completed' : 'Child failed',
                output: StepOutput::from($childOutput)
            ),
            runId: $parentRunId,
            attempt: $parentAttempt,
        );

        // Apply completion to parent state
        $updatedState = $this->transition->complete(
            $parentDefinition,
            $parentState,
            $completion
        );

        $this->store->put($parentRunId, $updatedState);
        return $updatedState;
    }
}

echo "Async child completion pattern:\n";
echo "1. Child workflow runs independently (different execution context)\n";
echo "2. Child finishes and calls parent's completion endpoint\n";
echo "3. Parent receives FlowDeferredCompletion with child output\n";
echo "4. FlowStateTransition::complete() applies child output to parent step\n";
echo "5. Parent's FlowRunner continues with next ready steps\n";
echo "6. Child output available in parent's context for dependent steps\n\n";

// ============================================================================
// EXAMPLE 9: Fire-and-forget child execution
// ============================================================================

echo "--- Example 9: Fire-and-Forget Child Execution ---\n";

/**
 * Fire-and-forget dispatcher - parent doesn't wait for child completion.
 */
class FireAndForgetDispatcher implements FlowChildRunDispatcher
{
    public function __construct(
        private readonly ChildDefinitionResolver $resolver,
        private readonly FlowRunner $runner,
    ) {
    }

    public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt
    {
        if (!$request->await) {
            echo "  - Fire-and-forget: launching child {$request->childFlowKey} without waiting\n";
            
            // In production: queue the child for async execution
            // $this->queue->push('execute-child', [
            //     'definition' => $this->resolver->resolve($request->childFlowKey, 1),
            //     'input' => $request->input,
            //     'runId' => $request->childRunId,
            // ]);
            
            return new FlowChildRunReceipt(
                childFlowKey: $request->childFlowKey,
                childRunId: $request->childRunId,
                status: FlowExecutionStatus::DISPATCHED,
            );
        }

        // Fall back to awaited execution
        return new FlowChildRunReceipt(
            childFlowKey: $request->childFlowKey,
            childRunId: $request->childRunId,
            status: FlowExecutionStatus::DISPATCHED,
        );
    }
}

$fireAndForget = new FireAndForgetDispatcher($resolver, new FlowRunner());

$ffRequest = new FlowChildRunRequest(
    childFlowKey: 'send-notification',
    childRunId: 'notif-1',
    input: ['to' => 'user@example.com', 'template' => 'order-confirmation'],
    parentFlowKey: 'order-processing',
    parentRunId: 'parent-1',
    parentStepId: 'notify-customer',
    parentAttempt: 1,
    await: false, // Fire-and-forget!
);

$receipt = $fireAndForget->dispatch($ffRequest);
echo "Fire-and-forget receipt: {$receipt->childRunId}, status: {$receipt->status->value}\n";
echo "Parent continues immediately without waiting for notification to send\n\n";

// ============================================================================
echo "=== All Milestone 3 Examples Completed ===\n";
echo "\nKey patterns demonstrated:\n";
echo "1. deadlineReached() - Advances workflow when wait timer expires\n";
echo "2. Listen subscriptions - Persisted and matched by host infrastructure\n";
echo "3. Event auth/dedup - Signature verification + idempotency keys\n";
echo "4. FlowDeferredCompletion - Structured callback delivery\n";
echo "5. Child resolver - Immutable key/version resolution\n";
echo "6. Idempotent dispatch - Parent attempt-scoped deduplication\n";
echo "7. Synchronous child - Inline execution with output\n";
echo "8. Async completion - Child callback resumes parent\n";
echo "9. Fire-and-forget - Parent decoupled from child completion\n";