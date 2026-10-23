<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;

/**
 * Mutable workflow context implementing OWS data semantics.
 *
 * The core runner treats FlowContext as read-only and passes the same
 * instance to every step, so OWS data-flow (set tasks merging results into
 * the workflow data) is provided by this richer context implementation
 * rather than by mutating the core contract (ADR 0001, ADR 0006).
 *
 * The workflow data starts as the run input; each executed `set` task
 * evaluates its jq expressions against this data and merges the results
 * back, so subsequent steps observe the updated data.
 */
final class OwsWorkflowContext implements FlowContext
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, mixed> */
    private array $stepOutputs = [];

    /**
     * @param array<string, mixed> $input The workflow run input
     */
    public function __construct(array $input = [])
    {
        $this->data = $input;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Merge executed `set` results into the workflow data (OWS `set` task).
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
    }

    /**
     * Record a completed task's output under its id, implementing the OWS
     * rule that a task's output is available to subsequent tasks.
     */
    public function recordStepOutput(string $taskId, mixed $output): void
    {
        $this->stepOutputs[$taskId] = $output;
    }

    /** @return array<string, mixed> */
    public function stepOutputs(): array
    {
        return $this->stepOutputs;
    }
}