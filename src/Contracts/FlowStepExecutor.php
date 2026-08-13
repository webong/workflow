<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\StepDefinition;
use Zorvia\WebFlow\ValueObjects\StepResult;
use Zorvia\WebFlow\ValueObjects\StepState;

interface FlowStepExecutor
{
    public function supports(StepDefinition $step): bool;

    public function execute(
        StepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult;
}
