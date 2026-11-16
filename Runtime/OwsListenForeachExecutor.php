<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Executes OWS `listen.foreach` tasks: processes each matching event through
 * a configured pipeline, accumulating results until a completion condition.
 *
 * The pipeline runs for each matching event in deterministic order (FIFO).
 * Results can be accumulated via `collect` or terminated early via `until`.
 */
final readonly class OwsListenForeachExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'listen.foreach';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS listen.foreach task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $listen = $step->metadata['ows_listen'] ?? null;
        if (! is_array($listen) || ! is_array($listen['to'] ?? null)) {
            throw new \RuntimeException("OWS listen.foreach task '{$step->id}' requires an event consumption strategy.");
        }

        $foreach = $listen['foreach'] ?? null;
        if (! is_array($foreach)) {
            throw new \RuntimeException("OWS listen.foreach task '{$step->id}' requires a 'foreach' pipeline configuration.");
        }

        // The pipeline to run for each event
        $pipeline = $foreach['pipeline'] ?? [];
        if (! is_array($pipeline)) {
            throw new \RuntimeException("OWS listen.foreach task '{$step->id}' requires a 'foreach.pipeline' array.");
        }

        // Optional: collect results into an array under this key
        $collectKey = is_string($foreach['collect'] ?? null) ? $foreach['collect'] : null;

        // Optional: until condition - stop processing when expression evaluates truthy
        $untilExpr = is_string($foreach['until'] ?? null) && $foreach['until'] !== '' ? $foreach['until'] : null;

        // The host is responsible for delivering events to this step.
        // We store the configuration and mark as deferred, waiting for events.
        // Events are delivered via the existing listen completion mechanism.

        return StepResult::deferred('waiting for events to process', metadata: [
            'ows_listen_foreach' => [
                'to' => $listen['to'],
                'foreach' => [
                    'pipeline' => $pipeline,
                    'collect' => $collectKey,
                    'until' => $untilExpr,
                ],
            ],
        ]);
    }
}