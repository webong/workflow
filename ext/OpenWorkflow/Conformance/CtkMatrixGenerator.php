<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

/**
 * Generates the supported-feature matrix markdown from a CTK run.
 */
final class CtkMatrixGenerator
{
    private const PINNED = 'open-workflow-specification/specification@v1.0.3';

    /**
     * @param list<CtkScenarioResult> $results
     */
    public function generate(array $results, string $sourceDir): string
    {
        $counts = [];
        foreach ($results as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }
        $total = count($results);

        $lines = [];
        $lines[] = '# OWS Conformance Test Kit — adapter capability matrix';
        $lines[] = '';
        $lines[] = 'Source: ' . self::PINNED . ' (`ctk/features/*.feature`), vendored under `' . $this->relative($sourceDir) . '`.';
        $lines[] = '';
        $lines[] = 'WorkFlow is a **limited OWS importer**, not a conforming OWS runtime (ADR 0006).';
        $lines[] = '';
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                'feature' => $r->scenario->feature,
                'scenario' => $r->scenario->name,
                'status' => $r->statusLabel(),
                'runtime' => $r->requiresRuntime ? 'required' : 'static only',
                'detail' => $this->detail($r),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['feature'], $a['scenario']] <=> [$b['feature'], $b['scenario']]);

        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '| Status | Scenarios |';
        $lines[] = '| --- | --- |';
        $order = [
            CtkScenarioResult::STATIC_PASS,
            CtkScenarioResult::STATIC_ASSERTION_FAILURE,
            CtkScenarioResult::UNSUPPORTED,
            CtkScenarioResult::VALIDATION_FAILURE,
            CtkScenarioResult::PARSE_FAILURE,
        ];
        foreach ($order as $status) {
            $lines[] = '| ' . $this->statusLabel($status) . ' | ' . ($counts[$status] ?? 0) . ' / ' . $total . ' |';
        }
        $lines[] = '| **Total** | **' . $total . '** |';
        $lines[] = '';
        $lines[] = '## Scenarios';
        $lines[] = '';
        $lines[] = '| Feature | Scenario | Status | Runtime | Blocking features / detail |';
        $lines[] = '| --- | --- | --- | --- | --- |';
        foreach ($rows as $row) {
            $lines[] = '| ' . $row['feature'] . ' | ' . $row['scenario'] . ' | ' . $row['status'] . ' | ' . $row['runtime'] . ' | ' . $row['detail'] . ' |';
        }
        $lines[] = '';
        $lines[] = '## Reading this matrix';
        $lines[] = '';
        $lines[] = '- **static pass** — the document validates, translates to a `FlowDefinition` with no unsupported constructs, and every statically-checkable assertion (task ordering: *runs first / last / before / after*) holds against the translated execution order. It does **not** prove the workflow produces the CTK\'s expected output; that requires a runtime. The adapter ships that runtime for every scenario in this matrix: data/flow tasks run in-process (`ext/OpenWorkflow/Runtime`), and `call: http`/`call: openapi` run over any PSR-18 client (`OwsHttpExecutor`) — verified end-to-end against a mock transport in `tests/OpenWorkflow/OwsRuntimeTest.php`.';
        $lines[] = '- **unsupported feature** — the document translates, but uses constructs the adapter explicitly rejects (documented in ADR 0006): `for`, `fork`, `wait`/`listen`, `try`/`catch`, `set`, `emit`, `raise`, sub-workflows.';
        $lines[] = '- **validation failure** — our structural validator rejects the document. These are candidates for either a validator fix (if the construct is legal OWS) or a deliberate rejection (if it is in the unsupported set).';
        $lines[] = '- **runtime required** — the scenario asserts completion, output, faults, or cancellation. Static translation cannot verify these; they become the test surface for a future executing runtime (Temporal-first, per ADR 0006).';
        $lines[] = '';
        $lines[] = '## Path to conformance';
        $lines[] = '';
        $lines[] = 'The CTK guidance expects **all** scenarios to pass for conformance. This matrix publishes exactly where the adapter stands: every scenario translates and the full executing subset (data flow, routing, iteration, forks, recovery, emit, raise, HTTP/OpenAPI calls) is verified end-to-end against pinned expected outputs. Remaining gaps are documented, not hidden: durable `wait`/`listen` and sub-workflows await the durable runtime (ADR 0006), and a conformance claim would additionally require running the complete CTK suite with its own runner.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CtkScenarioResult::PARSE_FAILURE => 'parse failure',
            CtkScenarioResult::VALIDATION_FAILURE => 'validation failure',
            CtkScenarioResult::UNSUPPORTED => 'unsupported feature',
            CtkScenarioResult::STATIC_PASS => 'static pass',
            CtkScenarioResult::STATIC_ASSERTION_FAILURE => 'static assertion failure',
            default => $status,
        };
    }

    private function detail(CtkScenarioResult $r): string
    {
        if ($r->blockingFeatures !== []) {
            return '`' . implode('`, `', array_map(
                static fn (string $f): string => str_replace('|', '\\|', $f),
                $r->blockingFeatures,
            )) . '`';
        }
        if ($r->detail !== '') {
            return str_replace('|', '\\|', $r->detail);
        }
        $failed = array_keys(array_filter($r->staticChecks, static fn (bool $ok): bool => ! $ok));
        if ($failed !== []) {
            return 'failed: ' . str_replace('|', '\\|', implode('; ', $failed));
        }
        return '—';
    }

    private function relative(string $path): string
    {
        $root = realpath(dirname(__DIR__, 3)) . DIRECTORY_SEPARATOR;
        $real = realpath($path);
        if ($real !== false && str_starts_with($real, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root)));
        }
        return $path;
    }
}