<?php

declare(strict_types=1);

use Webong\WorkFlow\App\FlowRpcApplication;
use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowOperationDirectory;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowInputBinding;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

require dirname(__DIR__).'/vendor/autoload.php';

// 1. A host executor that can be discovered, described, and executed. In a
//    real host these may be separate services; the contracts are the same.
$executor = new class implements FlowStepExecutor, FlowOperationProvider, FlowOperationDirectory {
    public function supports(FlowStepDefinition $step): bool
    {
        return in_array($step->id, ['approve', 'notify'], true);
    }

    /** @return list<FlowOperation> */
    public function operations(): array
    {
        return [
            new FlowOperation(
                key: 'catalog.approve',
                label: 'Approve an order',
                summary: 'Wait for a human decision, then record it.',
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'string'],
                        'decision' => ['type' => 'string'],
                    ],
                ],
                capabilities: ['approval'],
            ),
            new FlowOperation(
                key: 'catalog.notify',
                label: 'Notify the customer',
                inputSchema: [
                    'type' => 'object',
                    'required' => ['order'],
                    'properties' => [
                        'order' => ['type' => 'string'],
                        'decision' => ['type' => 'string'],
                    ],
                ],
            ),
        ];
    }

    public function operation(FlowStepDefinition $step): ?FlowOperation
    {
        foreach ($this->operations() as $operation) {
            if (str_ends_with($operation->key, '.'.$step->id)) {
                return $operation;
            }
        }

        return null;
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        return match ($invocation->step->id) {
            'approve' => StepResult::deferred('Waiting for approval'),
            'notify' => StepResult::completed(
                'Customer notified',
                output: StepOutput::from([
                    'order_id' => $invocation->input->get('order'),
                    'decision' => $invocation->input->get('decision', 'unknown'),
                ]),
            ),
            default => throw new InvalidArgumentException("Unknown step: {$invocation->step->id}"),
        };
    }
};

// 2. Discover what this host publishes before writing a definition.
$published = [];
foreach ($executor->operations() as $operation) {
    $published[] = $operation->key.' ('.$operation->label.')';
}
printf("Operations: %s\n", implode(', ', $published));

// 3. Construct a definition from the catalog, binding notify to approve's
//    structured output.
$definition = new FlowDefinition('catalog_order', [
    new FlowStepDefinition('approve', 'Approve the order'),
    new FlowStepDefinition('notify', 'Notify the customer', dependsOn: ['approve'], input: [
        'order' => FlowInputBinding::output('approve', '/order_id'),
        'decision' => FlowInputBinding::output('approve', '/decision', required: false),
    ]),
], metadata: ['required_capabilities' => ['approval']]);

// 4. Expose the definition and the catalog through the same RPC facade a
//    Node, Python, or Go client calls.
$definitions = new class($definition) implements FlowDefinitionProvider {
    public function __construct(private readonly FlowDefinition $flow)
    {
    }

    public function definition(string $flowKey): FlowDefinition
    {
        return $this->flow;
    }
};

$stores = new class implements FlowStateStoreFactory {
    /** @var array<string, InMemoryFlowStateStore> */
    private array $states = [];

    public function for(FlowStateSubject $subject): ForgettableFlowStateStore
    {
        return $this->states[$subject->type.':'.$subject->id] ??= new InMemoryFlowStateStore();
    }
};

$application = new FlowRpcApplication(
    $definitions,
    $stores,
    new FlowExecutionDispatcher([new InlineFlowExecutionDriver(new FlowRunner(), [$executor])]),
    operationProviders: [$executor],
);

// 5. Validate the document without registering it. The definition travels in
//    the request, and the host's granted capabilities are explicit.
$validation = $application->invoke('flow.validate', [
    'definition' => $definition->toArray(),
    'capabilities' => ['approval'],
]);
$diagnostics = is_array($validation['diagnostics'] ?? null) ? $validation['diagnostics'] : [];
$errors = 0;
$warnings = 0;
foreach ($diagnostics as $diagnostic) {
    $severity = is_array($diagnostic) ? ($diagnostic['severity'] ?? null) : null;
    if ($severity === 'error') {
        $errors++;
    } elseif ($severity === 'warning') {
        $warnings++;
    }
}
printf(
    "Validation: %s (%d errors, %d warnings)\n",
    ($validation['valid'] ?? false) === true ? 'valid' : 'invalid',
    $errors,
    $warnings,
);

/** @param array<string, mixed> $result
 *  @return array<string, mixed> */
$state = static function (array $result): array {
    $state = $result['state'] ?? null;
    if (! is_array($state)) {
        throw new RuntimeException('The RPC response did not contain flow state.');
    }

    /** @var array<string, mixed> $state */
    return $state;
};

/** @param array<string, mixed> $current */
$stepStatus = static function (array $current, string $stepId): string {
    $steps = $current['steps'] ?? null;
    $step = is_array($steps) ? ($steps[$stepId] ?? null) : null;
    $status = is_array($step) ? ($step['status'] ?? null) : null;

    return is_string($status) ? $status : 'missing';
};

/** @param array<string, mixed> $current */
$stepOutput = static function (array $current, string $stepId): mixed {
    $steps = $current['steps'] ?? null;
    $step = is_array($steps) ? ($steps[$stepId] ?? null) : null;

    return is_array($step) ? ($step['output'] ?? null) : null;
};

// 6. Start, observe the deferred step, complete it, and resume.
$params = [
    'flow_key' => 'catalog_order',
    'run_id' => 'catalog-order-1',
    'subject' => ['type' => 'order', 'id' => '42'],
];

$started = $state($application->invoke('flow.start', [...$params, 'context' => ['order_id' => 'order-42']]));
printf(
    "After start: %s; approve: %s; notify: %s\n",
    is_string($started['status'] ?? null) ? $started['status'] : 'unknown',
    $stepStatus($started, 'approve'),
    $stepStatus($started, 'notify'),
);

$completed = $state($application->invoke('flow.complete', [
    ...$params,
    'step_id' => 'approve',
    'attempt' => 1,
    'idempotency_key' => 'approval-event-1',
    'result' => [
        'status' => 'completed',
        'message' => 'Order approved',
        'output' => ['order_id' => 'order-42', 'decision' => 'approved'],
    ],
]));
printf(
    "After completion: %s; approve: %s\n",
    is_string($completed['status'] ?? null) ? $completed['status'] : 'unknown',
    $stepStatus($completed, 'approve'),
);

$resumed = $state($application->invoke('flow.resume', $params));
printf(
    "After resume: %s; notify: %s\n",
    is_string($resumed['status'] ?? null) ? $resumed['status'] : 'unknown',
    $stepStatus($resumed, 'notify'),
);

printf(
    "approve output: %s\n",
    json_encode($stepOutput($completed, 'approve'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
);
printf(
    "notify output: %s\n",
    json_encode($stepOutput($resumed, 'notify'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
);
