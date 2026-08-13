<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\StepDefinition;
use Webong\WebFlow\ValueObjects\StepResult;
use Webong\WebFlow\ValueObjects\StepState;

interface FlowStepExecutor
{
    public function supports(StepDefinition $step): bool;

    public function execute(
        StepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult;
}
