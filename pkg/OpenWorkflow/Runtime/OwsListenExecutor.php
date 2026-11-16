<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

/** Persists an OWS event subscription; the host owns matching and delivery. */
final readonly class OwsListenExecutor implements FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'listen';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS listen task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }
        $listen = $step->metadata['ows_listen'] ?? null;
        if (! is_array($listen) || ! is_array($listen['to'] ?? null)) {
            throw new \RuntimeException("OWS listen task '{$step->id}' requires an event consumption strategy.");
        }

        return StepResult::deferred('waiting for matching event', metadata: [
            'ows_listen' => $listen,
        ]);
    }
}
