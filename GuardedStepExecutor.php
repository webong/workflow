<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Throwable;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

/** Explicit opt-in decorator; does not change the runner or serialized core schema. */
final readonly class GuardedStepExecutor implements FlowStepExecutor
{
    public function __construct(private FlowStepExecutor $inner, private ConditionCompiler $compiler = new ConditionCompiler()) {}

    public function supports(FlowStepDefinition $step): bool { return $this->inner->supports($step); }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        if (! array_key_exists('when', $invocation->step->metadata)) { return $this->inner->execute($invocation); }
        try {
            $definition = $invocation->step->metadata['when'];
            if (! is_array($definition)) { return StepResult::failed('Invalid step condition.'); }
            $condition = $this->compiler->compile($definition);
            $result = $condition->evaluate(['context' => $invocation->context->all(), 'input' => $invocation->input->all()]);
            if ($result->isError()) { return StepResult::failed('Step condition evaluation failed.'); }
            if (! $result->matched()) { return StepResult::skipped('Step condition did not match.'); }
        } catch (Throwable) { return StepResult::failed('Invalid step condition.'); }
        return $this->inner->execute($invocation);
    }
}
