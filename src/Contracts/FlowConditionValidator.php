<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDiagnostic;

/** Optional authoring integration; validates configuration without evaluating runtime data. */
interface FlowConditionValidator
{
    /** @return list<FlowDiagnostic> */
    public function validate(mixed $condition, string $path = '', ?string $stepId = null): array;

    /** @return array<string, mixed> Structural JSON Schema; semantic validation remains authoritative. */
    public function schema(): array;
}
