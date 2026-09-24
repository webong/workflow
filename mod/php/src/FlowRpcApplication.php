<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Mod;

use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Trusted RPC facade. The host owns definitions, executors, and subject access.
 */
final readonly class FlowRpcApplication
{
    public function __construct(
        private FlowDefinitionProvider $definitions,
        private FlowStateStoreFactory $stores,
        private FlowExecutionDispatcher $execution,
        private FlowStateTransition $transitions = new FlowStateTransition(),
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function invoke(string $method, array $params): array
    {
        return match ($method) {
            'flow.definition' => $this->definition($params),
            'flow.get' => $this->get($params),
            'flow.start' => $this->start($params),
            'flow.complete' => $this->complete($params),
            'flow.resume' => $this->resume($params),
            'flow.cancel' => $this->cancel($params),
            default => throw new RpcFailure(-32601, 'Method not found'),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function definition(array $params): array
    {
        return ['definition' => $this->definitions->definition($this->flowKey($params))->toArray()];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function get(array $params): array
    {
        $subject = $this->subject($params);
        $flowKey = $this->flowKey($params);

        return ['state' => $this->publicState($this->store($subject, $params)->get($flowKey))];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function start(array $params): array
    {
        $subject = $this->subject($params);
        $flowKey = $this->flowKey($params);
        $context = $this->object($params['context'] ?? [], 'context');
        $store = $this->store($subject, $params);

        if (($params['driver'] ?? 'inline') !== 'inline') {
            throw new RpcFailure(-32602, 'The standalone RPC starter currently supports the inline driver only');
        }

        $created = false;
        $state = $store->mutate($flowKey, function (?FlowState $current) use ($flowKey, $context, $store, &$created): FlowState {
            if ($current !== null) {
                return $current;
            }

            $created = true;

            return (new FlowRun($store->runId, $this->definitions->definition($flowKey)))->initialState()->withMetadata([
                'execution_driver' => 'inline',
                '_rpc_context' => $context,
            ]);
        });

        return $created ? $this->resume($params) : ['state' => $this->publicState($state)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function complete(array $params): array
    {
        $subject = $this->subject($params);
        $flowKey = $this->flowKey($params);
        $stepId = $this->string($params, 'step_id');
        $idempotencyKey = $this->string($params, 'idempotency_key');
        $attempt = $params['attempt'] ?? null;
        if (! is_int($attempt) || $attempt < 1) {
            throw new RpcFailure(-32602, 'Invalid attempt');
        }
        $store = $this->store($subject, $params);
        $result = $this->object($params['result'] ?? null, 'result');

        $status = FlowStepStatus::tryFrom($this->string($result, 'status'));
        if (! in_array($status, [FlowStepStatus::COMPLETED, FlowStepStatus::FAILED, FlowStepStatus::SKIPPED], true)) {
            throw new RpcFailure(-32602, 'Invalid completion status');
        }

        $message = $result['message'] ?? null;
        $error = $result['error'] ?? null;
        $retriable = $result['retriable'] ?? null;
        $metadata = $this->object($result['metadata'] ?? [], 'result metadata');
        if (($message !== null && ! is_string($message)) || ($error !== null && ! is_string($error))) {
            throw new RpcFailure(-32602, 'Invalid completion message');
        }
        if ($retriable !== null && ! is_bool($retriable)) {
            throw new RpcFailure(-32602, 'Invalid retriable value');
        }

        $completion = new FlowDeferredCompletion(
            flowKey: $flowKey,
            stepId: $stepId,
            idempotencyKey: $idempotencyKey,
            result: new StepResult($status, message: $message, error: $error, retriable: $retriable, metadata: $metadata),
            runId: $store->runId,
            attempt: $attempt,
        );
        $state = $store->mutate($flowKey, function (?FlowState $current) use ($completion): FlowState {
            $current = $this->available($current);

            $run = $current->run ?? throw new RpcFailure(-32004, 'Flow run not found');

            return $this->transitions->complete($run->definition, $current, $completion);
        });

        return ['state' => $this->publicState($state)];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function resume(array $params): array
    {
        $subject = $this->subject($params);
        $flowKey = $this->flowKey($params);
        $store = $this->store($subject, $params);
        $claim = bin2hex(random_bytes(16));
        $state = $store->mutate($flowKey, function (?FlowState $current) use ($claim): FlowState {
            $current = $this->available($current);
            if (in_array($current->status, [FlowStatus::COMPLETED, FlowStatus::CANCELLED], true)) {
                return $current;
            }

            return $current->withMetadata(['_rpc_claim' => $claim]);
        });

        if (($state->metadata['_rpc_claim'] ?? null) !== $claim) {
            return ['state' => $this->publicState($state)];
        }

        // Deliberately outside mutate: a rollback cannot undo an external effect.
        // If dispatch or persistence fails, retain the claim for host reconciliation.
        $run = $state->run ?? throw new RpcFailure(-32004, 'Flow run not found');
        $receipt = $this->execution->dispatch(new FlowExecutionRequest(
            definition: $run->definition,
            state: $state,
            context: $this->object($state->metadata['_rpc_context'] ?? [], 'stored context'),
            executionId: $store->runId,
            subject: $subject,
        ));
        $next = $receipt->state ?? throw new RpcFailure(-32603, 'Inline execution did not return a flow state');
        $state = $store->mutate($flowKey, static function (?FlowState $current) use ($claim, $next): FlowState {
            if (($current?->metadata['_rpc_claim'] ?? null) !== $claim) {
                throw new RpcFailure(-32009, 'Flow execution claim has changed');
            }

            return $next->withMetadata(['_rpc_claim' => null]);
        });

        return ['state' => $this->publicState($state)];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function cancel(array $params): array
    {
        $state = $this->store($this->subject($params), $params)->mutate(
            $this->flowKey($params),
            fn (?FlowState $current): FlowState => $this->transitions->cancel($this->available($current)),
        );

        return ['state' => $this->publicState($state)];
    }

    private function available(?FlowState $state): FlowState
    {
        if ($state?->run === null) {
            throw new RpcFailure(-32004, 'Flow run not found');
        }
        if (($state->metadata['_rpc_claim'] ?? null) !== null) {
            throw new RpcFailure(-32009, 'Flow run has an active or interrupted execution claim');
        }

        return $state;
    }

    /** @param array<string, mixed> $params */
    private function store(FlowStateSubject $subject, array $params): RunScopedFlowStateStore
    {
        return new RunScopedFlowStateStore($this->stores->for($subject), $this->string($params, 'run_id'));
    }

    /** @return array<string, mixed>|null */
    private function publicState(?FlowState $state): ?array
    {
        if ($state === null) {
            return null;
        }
        $data = $state->toArray();
        $metadata = $state->metadata;
        unset($metadata['_rpc_context'], $metadata['_rpc_claim']);
        $data['metadata'] = $metadata;
        $data['execution_claim'] = $state->metadata['_rpc_claim'] ?? null;

        return $data;
    }

    /** @param array<string, mixed> $params */
    private function flowKey(array $params): string
    {
        return $this->string($params, 'flow_key');
    }

    /** @param array<string, mixed> $params */
    private function subject(array $params): FlowStateSubject
    {
        $subject = $this->object($params['subject'] ?? null, 'subject');

        return new FlowStateSubject(
            type: $this->string($subject, 'type'),
            id: $this->string($subject, 'id'),
        );
    }

    /** @param array<string, mixed> $params */
    private function string(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RpcFailure(-32602, "Invalid {$key}");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $name): array
    {
        if (! is_array($value)) {
            throw new RpcFailure(-32602, "Invalid {$name}");
        }

        foreach ($value as $key => $_) {
            if (! is_string($key)) {
                throw new RpcFailure(-32602, "Invalid {$name}");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
