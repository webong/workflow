<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

/**
 * A FlowContext that accepts data merges during execution.
 *
 * Core treats contexts as read-only by default and gives every executor an
 * isolated snapshot (see FlowStepExecution). Hosts that want step outputs to
 * propagate — e.g. Open Workflow Specification data flow (ADR 0006) — provide
 * a context implementing this contract; the execution wrapper then hands
 * executors an overlay view of THIS context instead of an isolated copy.
 * Opt-in by the host, never implied.
 */
interface FlowMutableContext extends FlowContext
{
    /**
     * Merge data into the context, overwriting existing keys.
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): void;

    /**
     * Return an execution view of this context with read-only overlay entries
     * layered over the data (e.g. the per-step `_workflow` metadata).
     * Implementations must keep data writes performed through the view shared
     * with the original context.
     *
     * @param array<string, mixed> $overlay
     */
    public function withOverlay(array $overlay): static;
}