<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsRouting;
use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;
use Webong\WorkFlow\ValueObjects\FlowDefinition;

/**
 * Runs vendored CTK scenarios through the adapter's validator + translator.
 *
 * The adapter is a limited OWS importer, not an executing runtime (ADR 0006),
 * so scenario classification is:
 *
 * - parse-failure:         document could not be parsed
 * - validation-failure:    structural validation rejected the document
 * - unsupported:           translated, but blocked by unsupported constructs
 *                          (for / fork / wait / try / emit / raise ...)
 * - static-pass:           translated cleanly AND every statically-checkable
 *                          assertion (task ordering) holds against the
 *                          translated FlowDefinition's execution order
 * - static-assertion-failure: translated cleanly but ordering assertions fail
 *
 * Scenarios that assert completion/output/faults are additionally flagged
 * requiresRuntime; full conformance is a separate milestone.
 */
final class CtkRunner
{
    public function __construct(
        private readonly OpenWorkflow $openWorkflow = new OpenWorkflow(),
    ) {}

    /**
     * @param list<CtkScenario> $scenarios
     * @return list<CtkScenarioResult>
     */
    public function runAll(array $scenarios): array
    {
        return array_map(fn (CtkScenario $s): CtkScenarioResult => $this->run($s), $scenarios);
    }

    public function run(CtkScenario $scenario): CtkScenarioResult
    {
        // 1. Parse
        try {
            $document = OwsDocument::fromString($scenario->definition);
        } catch (\Throwable $e) {
            return new CtkScenarioResult(
                scenario: $scenario,
                status: CtkScenarioResult::PARSE_FAILURE,
                requiresRuntime: $scenario->requiresRuntime(),
                detail: $e->getMessage(),
            );
        }

        // 2. Validate
        $report = $this->openWorkflow->registerOwsDefinition($scenario->definition);
        if (! $report->valid) {
            return new CtkScenarioResult(
                scenario: $scenario,
                status: CtkScenarioResult::VALIDATION_FAILURE,
                requiresRuntime: $scenario->requiresRuntime(),
                detail: implode('; ', array_map(
                    static fn ($u): string => $u->path !== '' ? "{$u->path}: {$u->reason}" : $u->reason,
                    $report->unsupported,
                )),
            );
        }

        // 3. Unsupported constructs block execution semantics
        $blocking = array_map(
            static fn ($u): string => $u->feature . ($u->path !== '' ? " ({$u->path})" : ''),
            $report->unsupported,
        );
        if ($blocking !== []) {
            return new CtkScenarioResult(
                scenario: $scenario,
                status: CtkScenarioResult::UNSUPPORTED,
                requiresRuntime: $scenario->requiresRuntime(),
                blockingFeatures: $blocking,
            );
        }

        // 4. Cleanly translated: apply static assertions
        $definition = $report->flowDefinition;
        if ($definition === null) {
            return new CtkScenarioResult(
                scenario: $scenario,
                status: CtkScenarioResult::VALIDATION_FAILURE,
                requiresRuntime: $scenario->requiresRuntime(),
                detail: 'Valid report without a FlowDefinition',
            );
        }

        $checks = $this->checkStaticAssertions($scenario, $definition);
        $failed = array_filter($checks, static fn (bool $ok): bool => ! $ok);

        return new CtkScenarioResult(
            scenario: $scenario,
            status: $failed === [] ? CtkScenarioResult::STATIC_PASS : CtkScenarioResult::STATIC_ASSERTION_FAILURE,
            requiresRuntime: $scenario->requiresRuntime(),
            staticChecks: $checks,
        );
    }

    /**
     * Evaluate the CTK task-ordering assertions against the translated
     * FlowDefinition's execution order. When the chain starts with a switch
     * step, routing is simulated statically against the scenario input so
     * routing-dependent ordering assertions can be verified (or honestly
     * fail).
     *
     * @return array<string, bool> assertion => passed
     */
    private function checkStaticAssertions(CtkScenario $scenario, \Webong\WorkFlow\ValueObjects\FlowDefinition $definition): array
    {
        $checks = [];
        $order = $this->effectiveOrder($definition, $scenario);
        $indexOf = static function (string $id) use ($order): ?int {
            $found = array_search($id, $order, true);
            return $found === false ? null : (int) $found;
        };

        foreach ($scenario->assertSteps as $step) {
            // "<TASK> should run first"
            if (preg_match('/^(.+?)\s+should run first$/', $step, $m) === 1) {
                $task = trim($m[1]);
                $checks[$step] = ($order[0] ?? null) === $task;
                continue;
            }

            // "<TASK> should run last"
            if (preg_match('/^(.+?)\s+should run last$/', $step, $m) === 1) {
                $task = trim($m[1]);
                $checks[$step] = $task === ($order[count($order) - 1] ?? null);
                continue;
            }

            // "<TASK1> should run before <TASK2>"
            if (preg_match('/^(.+?)\s+should run before\s+(.+)$/', $step, $m) === 1) {
                $a = $indexOf(trim($m[1]));
                $b = $indexOf(trim($m[2]));
                $checks[$step] = $a !== null && $b !== null && $a < $b;
                continue;
            }

            // "<TASK2> should run after <TASK1>"
            if (preg_match('/^(.+?)\s+should run after\s+(.+)$/', $step, $m) === 1) {
                $a = $indexOf(trim($m[1]));
                $b = $indexOf(trim($m[2]));
                $checks[$step] = $a !== null && $b !== null && $a > $b;
                continue;
            }

            // Output/completion/fault assertions require a runtime: record as not statically checkable
            $checks[$step . ' [runtime]'] = true;
        }

        return $checks;
    }

    /**
     * The execution order after statically simulating switch routing against
     * the scenario's input. Simulation applies only when the chain starts
     * with a switch (its task input is then the workflow input); otherwise
     * the translated declaration order is returned unchanged.
     *
     * @return list<string>
     */
    private function effectiveOrder(FlowDefinition $definition, CtkScenario $scenario): array
    {
        $steps = $definition->executionSteps();
        $order = array_map(static fn ($step): string => $step->id, $steps);
        $positions = array_flip($order);

        foreach ($steps as $index => $step) {
            if (($step->metadata['ows_type'] ?? null) !== 'switch') {
                continue;
            }
            if ($index !== 0) {
                break; // task input would depend on runtime data — not statically resolvable
            }

            $cases = is_array($step->metadata['ows_switch_cases'] ?? null) ? $step->metadata['ows_switch_cases'] : [];
            $positionsMap = is_array($step->metadata['ows_chain_positions'] ?? null) ? $step->metadata['ows_chain_positions'] : [];
            if ($cases === [] || $positionsMap === []) {
                break;
            }

            $decision = OwsRouting::decide(
                cases: $cases,
                switchThen: is_string($step->metadata['ows_then'] ?? null) ? $step->metadata['ows_then'] : null,
                taskInput: $scenario->input ?? [],
                positions: $positionsMap,
                switchPosition: 0,
                evaluator: new ExpressionEvaluator(),
            );

            $excluded = $decision['skip'];
            if ($decision['terminated']) {
                foreach ($positionsMap as $id => $position) {
                    if (is_int($position) && $position > 0) {
                        $excluded[] = $id;
                    }
                }
            }

            return array_values(array_filter(
                $order,
                static fn (string $id): bool => ! in_array($id, $excluded, true),
            ));
        }

        return $order;
    }
}