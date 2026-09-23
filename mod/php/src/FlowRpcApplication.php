<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Mod;

use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;
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

        return ['state' => $this->stores->for($subject)->get($flowKey)?->toArray()];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function start(array $params): array
    {
        $subject = $this->subject($params);
        $flowKey = $this->flowKey($params);
        $definition = $this->definitions->definition($flowKey);
        $context = $this->object($params['context'] ?? [], 'context');

        if (($params['driver'] ?? 'inline') !== 'inline') {
            throw new RpcFailure(-32602, 'The standalone RPC starter currently supports the inline driver only');
        }

        $state = $this->stores->for($subject)->mutate($flowKey, function (?FlowState $current) use ($definition, $context, $subject): FlowState {
            if ($current !== null) {
                return $current;
            }

            $receipt = $this->execution->dispatch(new FlowExecutionRequest(
                definition: $definition,
                state: new FlowState(FlowStatus::PENDING),
                context: $context,
                subject: $subject,
            ));

            return $receipt->state ?? throw new RpcFailure(-32603, 'Inline execution did not return a flow state');
        });

        return ['state' => $state->toArray()];
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
        $result = $this->object($params['result'] ?? null, 'result');

        $status = FlowStepStatus::tryFrom($this->string($result, 'status'));
        if (! in_array($status, [FlowStepStatus::COMPLETED, FlowStepStatus::FAILED, FlowStepStatus::SKIPPED], true)) {
            throw new RpcFailure(-32602, 'Invalid completion status');
        }

        $message = $result['message'] ?? null;
        $error = $result['error'] ?? null;
        if (($message !== null && ! is_string($message)) || ($error !== null && ! is_string($error))) {
            throw new RpcFailure(-32602, 'Invalid completion message');
        }

        $completion = new FlowDeferredCompletion(
            flowKey: $flowKey,
            stepId: $stepId,
            idempotencyKey: $idempotencyKey,
            result: new StepResult($status, message: $message, error: $error),
        );
        $definition = $this->definitions->definition($flowKey);
        $state = $this->stores->for($subject)->mutate($flowKey, function (?FlowState $current) use ($definition, $completion): FlowState {
            if ($current === null) {
                throw new RpcFailure(-32004, 'Flow state not found');
            }

            return $this->transitions->complete($definition, $current, $completion);
        });

        return ['state' => $state->toArray()];
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
