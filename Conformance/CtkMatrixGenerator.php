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
        $lines[] = 'WorkFlow is a **limited OWS runtime**; this matrix is implementation evidence, not an external certification claim (ADR 0006).';
        $lines[] = '';
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                'feature' => $r->scenario->feature,
                'scenario' => $r->scenario->name,
                'status' => $r->statusLabel(),
                'runtime' => CtkRuntimeCoverage::has($r->scenario) ? 'verified' : ($r->requiresRuntime ? 'required' : 'static only'),
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
        $lines[] = '- **static pass** — the document validates, translates to a `FlowDefinition` with no unsupported constructs, and every statically-checkable assertion (task ordering: *runs first / last / before / after*) holds against the translated execution order. This status alone does **not** prove the workflow produces the CTK\'s expected output; the separate runtime suite exercises the delivered execution behavior.';
        $lines[] = '- **runtime verified** — a named PHPUnit test translates and executes that exact pinned scenario through the adapter and asserts its runtime outcome. `CtkConformanceTest` prevents missing scenarios or stale test-method links.';
        $lines[] = '- **unsupported feature** — the document uses a construct the adapter explicitly rejects. Current deliberate gaps are expression forms unsupported by the selected jq backend and host capabilities that were not bound; the detail column names the blocker.';
        $lines[] = '- **validation failure** — our structural validator rejects the document. These are candidates for either a validator fix (if the construct is legal OWS) or a deliberate rejection (if it is in the unsupported set).';
        $lines[] = '- **runtime required** — the scenario asserts completion, output, faults, or cancellation but has no linked runtime test.';
        $lines[] = '';
        $lines[] = '## Path to conformance';
        $lines[] = '';
        $lines[] = 'All 21 scenarios in the complete pinned v1.0.3 CTK are linked to end-to-end runtime tests. WorkFlow still describes itself as a limited importer/runtime rather than claiming certification: the upstream project does not publish a separate executable runner or certification result, and host-bound capabilities remain deployment-specific. WorkFlow extensions for durable `wait`/`listen` and `run: workflow` are tested separately because v1.0.3 has no CTK scenarios for them.';
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
