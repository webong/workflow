<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

/**
 * One parsed CTK scenario.
 */
final readonly class CtkScenario
{
    /**
     * @param list<string> $assertSteps Raw assert-step lines (Then/And after When)
     * @param array<string, mixed>|null $input
     * @param array<string, mixed>|null $expectedOutput
     */
    public function __construct(
        public string $feature,
        public string $name,
        public string $definition,
        public ?array $input,
        public array $assertSteps,
        public ?array $expectedOutput = null,
    ) {}

    /**
     * True when the scenario asserts execution results (completion, output,
     * faults) that a static translator cannot verify.
     */
    public function requiresRuntime(): bool
    {
        foreach ($this->assertSteps as $step) {
            if (preg_match('/workflow should (complete|fault|cancel)/', $step) === 1) {
                return true;
            }
            if (str_contains($step, 'workflow output should have')) {
                return true;
            }
            if (preg_match('/\bshould (complete|fault|cancel) with output/', $step) === 1) {
                return true;
            }
        }
        return false;
    }
}