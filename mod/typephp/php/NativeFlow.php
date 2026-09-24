<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Native;

use InvalidArgumentException;
use JsonException;
use Throwable;
use TypeError;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/** Stateless coordination only: the foreign host owns storage and side effects. */
final class NativeFlow implements FlowStepExecutor
{
    public function dispatch(string $json): string
    {
        try {
            if (strlen($json) > 1048576) {
                throw new InvalidArgumentException('Request exceeds 1 MiB.');
            }
            $request = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($request) || ($request['protocol'] ?? null) !== 1) {
                throw new InvalidArgumentException('Unsupported native protocol.');
            }

            return json_encode($this->apply($request), JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException|JsonException|TypeError) {
            return '{"ok":false,"error":{"code":"invalid_request","message":"Invalid native workflow request."}}';
        } catch (Throwable) {
            return '{"ok":false,"error":{"code":"internal_error","message":"Native workflow operation failed."}}';
        }
    }

    /**
     * @param array<array-key, mixed> $request
     * @return array<string, mixed>
     */
    private function apply(array $request): array
    {
        $operation = self::string($request, 'operation');
        if ($operation === 'start') {
            $run = new FlowRun(self::string($request, 'run_id'), FlowDefinition::fromArray(self::object($request, 'definition')));
            $state = (new FlowEvaluator())->evaluate($run->definition, $run->initialState());

            return ['ok' => true, 'state' => $state->toArray(), 'work' => []];
        }

        $previous = FlowState::fromArray(self::object($request, 'state'));
        $run = $previous->run;
        if ($run === null) {
            throw new InvalidArgumentException('A pinned run is required.');
        }
        if (array_key_exists('definition', $request)) {
            $run->assertDefinition(FlowDefinition::fromArray(self::object($request, 'definition')));
        }

        $state = match ($operation) {
            'evaluate' => (new FlowEvaluator())->evaluate($run->definition, $previous),
            'advance' => (new FlowRunner())->run($run->definition, $previous, new ArrayFlowContext(), [$this]),
            'complete' => (new FlowStateTransition())->complete($run->definition, $previous, $this->completion($request)),
            'cancel' => (new FlowStateTransition())->cancel($previous),
            default => throw new InvalidArgumentException('Unknown native operation.'),
        };

        $work = [];
        if ($operation === 'advance') {
            foreach ($run->definition->executionSteps() as $step) {
                $attempt = $state->steps[$step->id] ?? null;
                if ($attempt !== null && $attempt->attempts > ($previous->steps[$step->id]->attempts ?? 0)
                    && ($attempt->metadata['deferred'] ?? false) === true) {
                    $work[] = [
                        'flow_key' => $run->definition->key,
                        'run_id' => $run->id,
                        'step_id' => $step->id,
                        'attempt' => $attempt->attempts,
                    ];
                }
            }
        }

        return ['ok' => true, 'state' => $state->toArray(), 'work' => $work];
    }

    public function supports(FlowStepDefinition $step): bool
    {
        return true;
    }

    public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
    {
        return StepResult::deferred();
    }

    /** @param array<array-key, mixed> $request */
    private function completion(array $request): FlowDeferredCompletion
    {
        $data = self::object($request, 'completion');
        $result = self::object($data, 'result');
        $status = FlowStepStatus::tryFrom(self::string($result, 'status'));
        if (! in_array($status, [FlowStepStatus::COMPLETED, FlowStepStatus::FAILED, FlowStepStatus::SKIPPED], true)
            || ! is_int($data['attempt'] ?? null) || $data['attempt'] < 1) {
            throw new InvalidArgumentException('Invalid completion status or attempt.');
        }
        if (isset($result['retriable']) && ! is_bool($result['retriable'])) {
            throw new InvalidArgumentException('Invalid retry flag.');
        }

        return new FlowDeferredCompletion(
            flowKey: self::string($data, 'flow_key'),
            stepId: self::string($data, 'step_id'),
            idempotencyKey: self::string($data, 'idempotency_key'),
            runId: self::string($data, 'run_id'),
            attempt: $data['attempt'],
            result: new StepResult(
                status: $status,
                message: self::optionalString($result, 'message'),
                error: self::optionalString($result, 'error'),
                retriable: $result['retriable'] ?? null,
                metadata: isset($result['metadata']) ? self::object($result, 'metadata') : [],
            ),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Missing string parameter.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Invalid string parameter.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Missing object parameter.');
        }

        return $value;
    }
}
