<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use ArrayObject;
use Webong\WorkFlow\Contracts\FlowMutableContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;

/**
 * Workflow context implementing the OWS 1.0.3 data flow model.
 *
 * Per the specification's Data Flow section:
 * - The first task's raw input is the (transformed) workflow input.
 * - Each task's raw input is the transformed output of the previous task.
 * - The workflow's output is the last task's transformed output (unless the
 *   workflow configures its own `output`).
 * - The workflow context (`$context` argument) only changes via `export.as`
 *   (not yet supported); set tasks do NOT accumulate into it.
 *
 * State lives in ArrayObject stores so `withOverlay()` views (handed to
 * executors by the core execution wrapper) share writes with this instance,
 * letting step outputs propagate between steps (ADR 0001, ADR 0006).
 */
final class OwsWorkflowContext implements FlowMutableContext
{
    /** @var ArrayObject<string, mixed> */
    private ArrayObject $data;

    /** @var ArrayObject<string, mixed> */
    private ArrayObject $outputs;

    /** @var ArrayObject<string, array{terminated: bool, chosen: ?string, skip: list<string>, switch_position: int}> */
    private ArrayObject $routing;

    /** @var ArrayObject<string, array<string, mixed>> */
    private ArrayObject $faults;

    /** @var array<string, mixed> */
    private array $overlay = [];

    /**
     * @param array<string, mixed> $input The workflow run input
     */
    public function __construct(array $input = [])
    {
        $this->data = new ArrayObject($input);
        $this->outputs = new ArrayObject([]);
        $this->routing = new ArrayObject([]);
        $this->faults = new ArrayObject([]);
    }

    /**
     * Rebuild the transient OWS data-flow view from durable step outputs and
     * routing metadata before resuming a persisted run.
     *
     * @param array<string, mixed> $input Original workflow input
     */
    public static function resume(array $input, FlowDefinition $definition, FlowState $state): self
    {
        $context = new self($input);
        foreach ($definition->executionSteps() as $step) {
            $stepState = $state->steps[$step->id] ?? null;
            if ($stepState === null) {
                continue;
            }
            if (OwsOutputs::has($stepState)) {
                $context->recordStepOutput($step->id, OwsOutputs::fromStep($stepState));
            }
            $routing = $stepState->metadata['ows_routing'] ?? null;
            if (is_array($routing)
                && is_bool($routing['terminated'] ?? null)
                && (is_string($routing['chosen'] ?? null) || ($routing['chosen'] ?? null) === null)
                && is_array($routing['skip'] ?? null)
                && is_int($routing['switch_position'] ?? null)) {
                $context->recordRouting($step->id, [
                    'terminated' => $routing['terminated'],
                    'chosen' => $routing['chosen'],
                    'skip' => array_values(array_filter($routing['skip'], 'is_string')),
                    'switch_position' => $routing['switch_position'],
                ]);
            }
        }

        return $context;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->overlay) || array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->overlay)) {
            return $this->overlay[$key];
        }
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [...$this->data->getArrayCopy(), ...$this->overlay];
    }

    public function merge(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
    }

    public function withOverlay(array $overlay): static
    {
        $view = clone $this;
        $view->overlay = [...$this->overlay, ...$overlay];
        return $view;
    }

    /**
     * The workflow input: the first task's raw input (identity transform;
     * workflow-level `input.from` is not yet supported).
     *
     * @return array<string, mixed>
     */
    public function workflowInput(): array
    {
        return $this->data->getArrayCopy();
    }

    /**
     * Record a completed task's output so the next task in the chain can
     * consume it as its raw input.
     */
    public function recordStepOutput(string $taskId, mixed $output): void
    {
        $this->outputs[$taskId] = $output;
    }

    public function stepOutput(string $taskId): mixed
    {
        return $this->outputs[$taskId] ?? null;
    }

    /**
     * Whether a step's output was recorded (distinct from an output that is
     * legitimately null).
     */
    public function hasOutput(string $taskId): bool
    {
        return isset($this->outputs[$taskId]) || array_key_exists($taskId, $this->outputs->getArrayCopy());
    }

    /**
     * The most recently recorded task output — the OWS workflow output for
     * the sequential subset (last executed task's transformed output).
     * ArrayObject preserves insertion order, shared across overlay views.
     */
    public function lastOutput(): mixed
    {
        $all = $this->outputs->getArrayCopy();
        return $all === [] ? null : end($all);
    }

    /**
     * Record a switch task's routing decision so subsequent chain steps know
     * whether to skip (see OwsRouting).
     *
     * @param array{terminated: bool, chosen: ?string, skip: list<string>, switch_position: int} $decision
     */
    public function recordRouting(string $switchId, array $decision): void
    {
        $this->routing[$switchId] = $decision;
    }

    /** @return array<string, array{terminated: bool, chosen: ?string, skip: list<string>, switch_position: int}> */
    public function routingDecisions(): array
    {
        return $this->routing->getArrayCopy();
    }

    /**
     * Record a task fault scoped to its enclosing try task (empty scope for
     * unscoped faults), consumed by catch handlers.
     *
     * @param array<string, mixed> $fault
     */
    public function recordFault(string $tryId, array $fault): void
    {
        $this->faults[$tryId] = $fault;
    }

    /** @return array<string, mixed>|null */
    public function faultFor(string $tryId): ?array
    {
        return $this->faults[$tryId] ?? null;
    }

    /**
     * Mark a fault handled so later catch tasks in the same scope skip.
     */
    public function consumeFault(string $tryId): void
    {
        unset($this->faults[$tryId]);
    }
}
