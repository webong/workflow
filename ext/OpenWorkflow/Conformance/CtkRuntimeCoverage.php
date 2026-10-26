<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

/**
 * Links every pinned CTK scenario to its end-to-end PHPUnit runtime test.
 *
 * The matrix generator uses this only as coverage evidence; translation and
 * static assertions still come from CtkRunner, while PHPUnit executes the
 * referenced methods through the real adapter/runtime seams.
 */
final class CtkRuntimeCoverage
{
    /** @var array<string, string> scenario key => PHPUnit method */
    private const array TESTS = [
        'branch: Fork Task With Competing Concurrent Sub Tasks' => 'testCtkForkCompetingBranchesScenario',
        'call: Call HTTP With Content Output' => 'testCtkCallHttpWithContentOutput',
        'call: Call HTTP With Response Output' => 'testCtkCallHttpWithResponseOutput',
        'call: Call HTTP Using Basic Authentication' => 'testCtkCallHttpUsingBasicAuthentication',
        'call: Call OpenAPI With Content Output' => 'testCtkCallOpenApiWithContentOutput',
        'call: Call OpenAPI With Response Output' => 'testCtkCallOpenApiWithResponseOutput',
        'data-flow: Input Filtering' => 'testCtkInputFilteringScenario',
        'data-flow: Output Filtering' => 'testCtkDataFlowOutputFilteringScenario',
        'data-flow: Use Non-object Output' => 'testCtkDataFlowUseNonObjectOutputScenario',
        'do: Task With Sequential Sub Tasks' => 'testCtkCompositeTaskScenario',
        'emit: Emit Task' => 'testCtkEmitTaskScenario',
        'flow: Explicit Sequence Flow' => 'testCtkExplicitSequenceFlowScenario',
        'flow: Implicit Sequence Flow' => 'testCtkImplicitSequenceFlowScenario',
        'for: For Task' => 'testCtkForTaskScenario',
        'raise: Raise task with inline error' => 'testCtkRaiseTaskScenario',
        'set: Set Task' => 'testCtkSetTaskScenario',
        'switch: Switch task with explicit default case' => 'testCtkSwitchWithExplicitDefaultScenario',
        'switch: Switch task with implicit default case' => 'testCtkSwitchWithImplicitDefaultScenario',
        'switch: Switch task with matching case' => 'testCtkSwitchWithMatchingCaseScenario',
        'try: Try Handle Caught Error' => 'testCtkTryHandleCaughtErrorScenario',
        'try: Try Raise Uncaught Error' => 'testCtkTryRaiseUncaughtErrorScenario',
    ];

    public static function has(CtkScenario $scenario): bool
    {
        return isset(self::TESTS[self::key($scenario)]);
    }

    public static function testMethod(CtkScenario $scenario): ?string
    {
        return self::TESTS[self::key($scenario)] ?? null;
    }

    /** @return list<string> */
    public static function scenarioKeys(): array
    {
        return array_keys(self::TESTS);
    }

    private static function key(CtkScenario $scenario): string
    {
        return $scenario->feature.': '.$scenario->name;
    }
}
