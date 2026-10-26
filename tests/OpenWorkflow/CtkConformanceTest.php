<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkMatrixGenerator;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkParser;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkRunner;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkRuntimeCoverage;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkScenarioResult;

/**
 * Runs the vendored pinned CTK (specification@v1.0.3) scenarios through the
 * adapter's validator + translator and publishes the capability matrix.
 *
 * Per ADR 0006 this is NOT a conformance claim: the adapter is a limited
 * importer, and scenarios asserting execution output require a runtime.
 * This test pins behavior:
 *  - every vendored scenario must classify into a known status
 *  - every cleanly-translated scenario's static (ordering) assertions must hold
 *  - the committed matrix document must match the current run
 */
class CtkConformanceTest extends TestCase
{
    private const FEATURES_DIR = __DIR__ . '/../../ext/OpenWorkflow/Conformance/features';
    private const MATRIX_PATH = __DIR__ . '/../../docs/open-workflow-ctk-matrix.md';

    /** @var list<\Webong\WorkFlow\OpenWorkflow\Conformance\CtkScenario> */
    private array $scenarios;

    /** @var list<CtkScenarioResult> */
    private array $results;

    protected function setUp(): void
    {
        $scenarios = (new CtkParser())->parseDirectory(self::FEATURES_DIR);
        self::assertGreaterThan(0, count($scenarios), 'No CTK scenarios were parsed');
        $this->scenarios = $scenarios;
        $this->results = (new CtkRunner())->runAll($scenarios);
    }

    public function testParsesTheExpectedNumberOfPinnedScenarios(): void
    {
        // 11 feature files, 21 scenarios at v1.0.3
        $this->assertCount(21, $this->scenarios);
    }

    public function testEveryScenarioClassifiesIntoAKnownStatus(): void
    {
        foreach ($this->results as $result) {
            $this->assertContains(
                $result->status,
                [
                    CtkScenarioResult::PARSE_FAILURE,
                    CtkScenarioResult::VALIDATION_FAILURE,
                    CtkScenarioResult::UNSUPPORTED,
                    CtkScenarioResult::STATIC_PASS,
                    CtkScenarioResult::STATIC_ASSERTION_FAILURE,
                ],
                "Scenario '{$result->scenario->feature}: {$result->scenario->name}' has unknown status",
            );
        }
    }

    public function testCleanlyTranslatedScenariosHoldTheirStaticAssertions(): void
    {
        foreach ($this->results as $result) {
            if ($result->status !== CtkScenarioResult::STATIC_PASS) {
                continue;
            }
            foreach ($result->staticChecks as $assertion => $ok) {
                if (str_ends_with((string) $assertion, ' [runtime]')) {
                    continue;
                }
                $this->assertTrue(
                    $ok,
                    "Static assertion failed in '{$result->scenario->feature}: {$result->scenario->name}': {$assertion}",
                );
            }
        }
    }

    public function testEveryPinnedScenarioHasALiveRuntimeTest(): void
    {
        $scenarioKeys = array_map(
            static fn ($scenario): string => $scenario->feature.': '.$scenario->name,
            $this->scenarios,
        );
        sort($scenarioKeys);
        $coverageKeys = CtkRuntimeCoverage::scenarioKeys();
        sort($coverageKeys);
        self::assertSame($scenarioKeys, $coverageKeys);

        foreach ($this->scenarios as $scenario) {
            $method = CtkRuntimeCoverage::testMethod($scenario);
            self::assertNotNull($method);
            self::assertTrue(
                method_exists(OwsRuntimeTest::class, $method),
                "Runtime coverage method '{$method}' is missing for {$scenario->feature}: {$scenario->name}",
            );
        }
    }

    public function testUnsupportedScenariosAlwaysReportTheBlockingFeature(): void
    {
        foreach ($this->results as $result) {
            if ($result->status !== CtkScenarioResult::UNSUPPORTED) {
                continue;
            }
            $this->assertNotEmpty(
                $result->blockingFeatures,
                "Scenario '{$result->scenario->name}' is unsupported without naming the feature",
            );
        }
    }

    public function testCommittedMatrixMatchesTheCurrentRun(): void
    {
        $generated = (new CtkMatrixGenerator())->generate($this->results, self::FEATURES_DIR);
        $committed = file_get_contents(self::MATRIX_PATH);
        self::assertNotFalse($committed, 'Matrix document is missing at ' . self::MATRIX_PATH);
        $this->assertSame($generated, $committed, 'docs/open-workflow-ctk-matrix.md is stale; run php tools/generate-ctk-matrix.php');
    }
}
