<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

/** Persists an OWS wait as a deferred step with a successful wake deadline. */
final readonly class OwsWaitExecutor implements FlowStepExecutor
{
    /** @param null|Closure(): int $clock */
    public function __construct(
        private ?Closure $clock = null,
        private ExpressionEvaluator $expressions = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'wait';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS wait task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $input = OwsRouting::resolveChainInput($step, $invocation->context);
        $duration = $step->metadata['ows_wait'] ?? null;
        if (is_string($duration) && preg_match('/^\$\{(.*)\}$/s', trim($duration), $match) === 1) {
            $duration = OwsDataTaskPipeline::evaluate(trim($match[1]), $input, [], $this->expressions, $step->id);
        }
        $seconds = $this->seconds($duration, $step->id);
        $resumeAt = ($this->clock !== null ? ($this->clock)() : time()) + $seconds;
        $invocation->context->recordStepOutput($step->id, $input);

        return StepResult::deferred(
            'waiting until scheduled wakeup',
            metadata: [
                'ows_wait' => $duration,
                'deadline_transition' => 'complete',
                'deadline_output_present' => true,
                'deadline_output' => $input,
            ],
            deadlineAt: $resumeAt,
        );
    }

    private function seconds(mixed $duration, string $stepId): int
    {
        if (is_array($duration)) {
            $milliseconds = 0;
            foreach (['days' => 86_400_000, 'hours' => 3_600_000, 'minutes' => 60_000, 'seconds' => 1_000, 'milliseconds' => 1] as $unit => $factor) {
                $value = $duration[$unit] ?? 0;
                if (! is_int($value) || $value < 0) {
                    throw new \RuntimeException("OWS wait task '{$stepId}' has an invalid {$unit} duration.");
                }
                $milliseconds += $value * $factor;
            }

            return (int) ceil($milliseconds / 1_000);
        }

        if (is_string($duration) && $duration !== '') {
            try {
                $start = new DateTimeImmutable('@0');
                $end = $start->add(new DateInterval($duration));
                return max(0, $end->getTimestamp() - $start->getTimestamp());
            } catch (\Throwable $exception) {
                throw new \RuntimeException("OWS wait task '{$stepId}' has an invalid ISO 8601 duration.", 0, $exception);
            }
        }

        throw new \RuntimeException("OWS wait task '{$stepId}' requires a duration.");
    }
}
