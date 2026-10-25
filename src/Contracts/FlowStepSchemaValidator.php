<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInput;
use Webong\WorkFlow\ValueObjects\StepOutput;

/**
 * Optional schema validation around a step's executor call.
 *
 * Hosts (or an optional extension module) supply the schemas and the
 * checking implementation — the core carries no schema language or JSON
 * Schema library. Where a schema comes from is the implementation's
 * decision: an operation catalog descriptor, step metadata, or host
 * configuration.
 *
 * Input violations are reported before the executor is invoked, so a
 * schema failure can never trigger an external effect. Output violations
 * are reported after the executor ran; the execution converts them into a
 * terminal failed step instead of persisting the violating output.
 */
interface FlowStepSchemaValidator
{
    /**
     * Descriptions of the resolved input's schema violations; empty means valid.
     *
     * @return list<string>
     */
    public function validateInput(FlowStepDefinition $step, FlowStepInput $input): array;

    /**
     * Descriptions of the output's schema violations; empty means valid.
     *
     * @return list<string>
     */
    public function validateOutput(FlowStepDefinition $step, StepOutput $output): array;
}
