<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\FlowDefinitionValidator;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;
use Webong\WorkFlow\ValueObjects\FlowInputBinding;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\FlowValidationReport;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * The shared validation module behind `flow.validate`, the `workflow` CLI,
 * and host tooling. It reports data, never throws for a bad document, and
 * never reports valid a document the runtime refuses to construct.
 */
final class FlowDefinitionValidatorTest extends TestCase
{
    public function testItAcceptsACompleteDefinitionAndChecksEverySurface(): void
    {
        $report = $this->validator()->validate($this->document());

        self::assertTrue($report->isValid(), $this->describe($report));
        self::assertSame([], $report->diagnostics);
    }

    public function testItReportsTopologyProblemsWithJsonPointers(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'version' => 0,
            'steps' => [
                ['id' => 'a', 'depends_on' => ['b']],
                ['id' => 'b', 'depends_on' => ['a']],
                ['id' => 'a'],
                ['id' => 'c', 'depends_on' => ['c']],
                ['depends_on' => []],
            ],
        ]);

        self::assertFalse($report->isValid());
        $codes = $this->codes($report);
        self::assertContains('invalid_version', $codes);
        self::assertContains('duplicate_step_id', $codes);
        self::assertContains('self_dependency', $codes);
        self::assertContains('dependency_cycle', $codes);
        self::assertContains('missing_step_id', $codes);

        $cycle = $this->first($report, 'dependency_cycle');
        self::assertSame('/steps/0', $cycle->path);
        self::assertSame('a', $cycle->stepId);
        self::assertSame(['a', 'b'], $cycle->details['cycle']);
    }

    public function testItRejectsUnknownDependenciesAndMalformedStepObjects(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => [
                'not-a-step',
                ['id' => 'a', 'depends_on' => 'b'],
                ['id' => 'b', 'depends_on' => ['missing']],
            ],
        ]);

        self::assertSame('/steps/0', $this->first($report, 'step_not_object')->path);
        self::assertSame('/steps/1/depends_on', $this->first($report, 'invalid_dependencies')->path);
        self::assertSame('/steps/2/depends_on/0', $this->first($report, 'unknown_dependency')->path);
        self::assertSame('b', $this->first($report, 'unknown_dependency')->stepId);
    }

    public function testItChecksBindingSourcesPointersAndDeclaredDependencies(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => [
                ['id' => 'a'],
                ['id' => 'b', 'depends_on' => ['a'], 'input' => [
                    'from_context' => ['source' => 'context', 'pointer' => '/order/id'],
                    'context_with_step' => ['source' => 'context', 'step' => 'a'],
                    'output_without_step' => ['source' => 'output'],
                    'output_unknown_step' => ['source' => 'output', 'step' => 'nope'],
                    'output_not_dependency' => ['source' => 'output', 'step' => 'c'],
                    'bad_source' => ['source' => 'database'],
                    'bad_pointer' => ['source' => 'context', 'pointer' => 'order/id'],
                    'not_object' => 'order-42',
                ]],
                ['id' => 'c', 'depends_on' => ['a'], 'input' => [
                    'ok' => ['source' => 'output', 'step' => 'a', 'pointer' => '/order_id'],
                ]],
            ],
        ]);

        self::assertSame('/steps/1/input/context_with_step/step', $this->first($report, 'binding_context_has_step')->path);
        self::assertSame('/steps/1/input/output_without_step/step', $this->first($report, 'binding_source_missing_step')->path);
        self::assertSame('/steps/1/input/output_unknown_step/step', $this->first($report, 'binding_source_step_unknown')->path);
        self::assertSame('/steps/1/input/output_not_dependency/step', $this->first($report, 'binding_source_step_not_dependency')->path);
        self::assertSame('/steps/1/input/bad_source/source', $this->first($report, 'invalid_binding_source')->path);
        self::assertSame('/steps/1/input/bad_pointer/pointer', $this->first($report, 'invalid_pointer')->path);
        self::assertSame('/steps/1/input/not_object', $this->first($report, 'invalid_binding')->path);
    }

    public function testItEscapesJsonPointerTokensInBindingPaths(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => [
                ['id' => 'a', 'input' => [
                    'order/id' => 'not-a-binding',
                    'tilde~name' => 'not-a-binding',
                ]],
            ],
        ]);

        self::assertSame(
            ['/steps/0/input/order~1id', '/steps/0/input/tilde~0name'],
            array_map(static fn (FlowDiagnostic $diagnostic): string => $diagnostic->path, $report->errors()),
        );
    }

    public function testItReportsUnsupportedStepsMissingCapabilitiesAndMissingInputs(): void
    {
        // This host only supports step a, grants no capabilities, and step a
        // binds none of the input its operation requires.
        $report = (new FlowDefinitionValidator([$this->executor(['a'])], []))->validate([
            'key' => 'orders',
            'metadata' => ['required_capabilities' => ['approval']],
            'steps' => [
                ['id' => 'a'],
                ['id' => 'b'],
            ],
        ]);

        $codes = $this->codes($report);
        self::assertContains('missing_capability', $codes);
        self::assertContains('missing_required_input', $codes);
        self::assertContains('unsupported_step', $codes);
        self::assertSame('/steps/1', $this->first($report, 'unsupported_step')->path);
        self::assertSame('b', $this->first($report, 'unsupported_step')->stepId);
        self::assertSame('/metadata/required_capabilities/0', $this->first($report, 'missing_capability')->path);
    }

    public function testItWarnsWhenOperationAndCapabilityChecksWereNotRun(): void
    {
        $report = (new FlowDefinitionValidator())->validate([
            'key' => 'orders',
            'metadata' => ['required_capabilities' => ['approval']],
            'steps' => [
                ['id' => 'a', 'input' => ['order' => ['source' => 'context', 'pointer' => '/order_id']]],
            ],
        ]);

        self::assertTrue($report->isValid(), $this->describe($report));
        self::assertSame(['capabilities_unchecked', 'operations_unchecked'], $this->codes($report));
    }

    public function testItDoesNotClaimCapabilityChecksWhenNothingRequiresOne(): void
    {
        // Step b publishes no capability requirement, so null capabilities
        // means there was nothing to check rather than that checks were lost.
        $report = (new FlowDefinitionValidator([$this->executor(['b'])], null))->validate([
            'key' => 'orders',
            'steps' => [
                ['id' => 'b', 'input' => ['order' => ['source' => 'context', 'pointer' => '/order_id']]],
            ],
        ]);

        self::assertTrue($report->isValid(), $this->describe($report));
        self::assertNotContains('capabilities_unchecked', $this->codes($report));
    }

    public function testItWarnsAboutUnknownOutputPropertiesButNotKnownOnes(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'metadata' => ['required_capabilities' => ['approval']],
            'steps' => [
                ['id' => 'a', 'input' => ['order' => ['source' => 'context', 'pointer' => '/order_id']]],
                ['id' => 'b', 'depends_on' => ['a'], 'input' => [
                    'order' => ['source' => 'output', 'step' => 'a', 'pointer' => '/order_id'],
                    'known' => ['source' => 'output', 'step' => 'a', 'pointer' => '/order_id'],
                    'unknown' => ['source' => 'output', 'step' => 'a', 'pointer' => '/missing'],
                ]],
            ],
        ]);

        self::assertTrue($report->isValid(), $this->describe($report));
        $warnings = $report->warnings();
        self::assertCount(1, $warnings);
        self::assertSame('unknown_output_property', $warnings[0]->code);
        self::assertSame('/steps/1/input/unknown/pointer', $warnings[0]->path);
        self::assertSame('a', $warnings[0]->details['source_step']);
        self::assertSame('missing', $warnings[0]->details['property']);
    }

    public function testItRejectsADocumentTheRuntimeWouldAlsoReject(): void
    {
        // The validator reports the specific binding problem, so the generic
        // fromArray backstop never contradicts it.
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => [
                ['id' => 'a', 'input' => ['x' => ['source' => 'output', 'step' => 'a', 'pointer' => '']]],
            ],
        ]);

        self::assertFalse($report->isValid());
        self::assertContains('binding_source_step_not_dependency', $this->codes($report));
        self::assertNotContains('invalid_definition', $this->codes($report));
    }

    public function testItRejectsDocumentsThatAreNotObjects(): void
    {
        self::assertSame(
            'definition_not_object',
            $this->validator()->validate('orders')->errors()[0]->code,
        );
        self::assertSame(
            'definition_not_object',
            $this->validator()->validate(['not', 'an', 'object'])->errors()[0]->code,
        );
        self::assertFalse($this->validator()->validate(null)->isValid());
        self::assertFalse($this->validator()->validate(42)->isValid());
    }

    public function testItRejectsAJsonObjectWhereTheCanonicalDocumentRequiresAStepList(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => ['first' => ['id' => 'a']],
        ]);

        self::assertFalse($report->isValid());
        self::assertSame('/steps', $this->first($report, 'invalid_steps')->path);
    }

    public function testItRejectsPointersWithInvalidRfc6901Escapes(): void
    {
        $report = $this->validator()->validate([
            'key' => 'orders',
            'steps' => [[
                'id' => 'a',
                'input' => ['bad' => ['source' => 'context', 'pointer' => '/bad~2pointer']],
            ]],
        ]);

        self::assertFalse($report->isValid());
        self::assertSame('/steps/0/input/bad/pointer', $this->first($report, 'invalid_pointer')->path);
    }

    public function testItCanReuseAnExecutorGeneratorAcrossValidations(): void
    {
        $executor = $this->executor(['a']);
        $executors = (static function () use ($executor): \Generator {
            yield $executor;
        })();
        $validator = new FlowDefinitionValidator($executors, ['approval']);
        $document = [
            'key' => 'orders',
            'steps' => [['id' => 'a', 'input' => ['order' => ['source' => 'context', 'pointer' => '/order_id']]]],
        ];

        self::assertTrue($validator->validate($document)->isValid());
        self::assertTrue($validator->validate($document)->isValid());
    }

    public function testAnEmptyStepListIsValidButWarned(): void
    {
        $report = (new FlowDefinitionValidator())->validate(['key' => 'orders', 'steps' => []]);

        self::assertTrue($report->isValid());
        self::assertSame(['no_steps', 'operations_unchecked'], $this->codes($report));
    }

    public function testTheReportSerializesToTheJsonRpcShape(): void
    {
        $report = $this->validator()->validate(['steps' => []]);
        $data = $report->toArray();

        self::assertFalse($data['valid']);
        self::assertIsArray($data['diagnostics']);
        self::assertSame('missing_key', $data['diagnostics'][0]['code']);
        self::assertSame('error', $data['diagnostics'][0]['severity']);
        self::assertSame('/key', $data['diagnostics'][0]['path']);
        self::assertNull($data['diagnostics'][0]['step_id']);
        self::assertSame([], $data['diagnostics'][0]['details']);

        $parsed = FlowValidationReport::fromArray($data);
        self::assertFalse($parsed->isValid());
        self::assertSame('missing_key', $parsed->errors()[0]->code);
    }

    public function testItAcceptsAFlowDefinitionValueObject(): void
    {
        $report = $this->validator()->validate(new FlowDefinition('orders', [
            new FlowStepDefinition('a', 'A', input: [
                'order' => FlowInputBinding::context('/order_id'),
            ]),
        ]));

        self::assertTrue($report->isValid(), $this->describe($report));
    }

    private function validator(): FlowDefinitionValidator
    {
        return new FlowDefinitionValidator([$this->executor()], ['approval']);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'key' => 'orders',
            'version' => 1,
            'metadata' => ['required_capabilities' => ['approval']],
            'steps' => [
                ['id' => 'a', 'input' => ['order' => ['source' => 'context', 'pointer' => '/order_id']]],
                ['id' => 'b', 'depends_on' => ['a'], 'input' => [
                    'order' => ['source' => 'output', 'step' => 'a', 'pointer' => '/order_id'],
                ]],
            ],
        ];
    }

    /** @param list<string> $supports */
    private function executor(array $supports = ['a', 'b', 'c']): FlowStepExecutor
    {
        return new class ($supports) implements FlowStepExecutor, FlowOperationProvider {
            /** @param list<string> $supports */
            public function __construct(private readonly array $supports)
            {
            }

            public function supports(FlowStepDefinition $step): bool
            {
                return in_array($step->id, $this->supports, true);
            }

            public function execute(FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed();
            }

            public function operation(FlowStepDefinition $step): ?FlowOperation
            {
                return new FlowOperation(
                    key: 'orders.'.$step->id,
                    label: $step->label,
                    inputSchema: ['type' => 'object', 'required' => ['order']],
                    outputSchema: ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']]],
                    capabilities: $step->id === 'a' ? ['approval'] : [],
                );
            }
        };
    }

    /** @return list<string> */
    private function codes(FlowValidationReport $report): array
    {
        return array_map(static fn (FlowDiagnostic $diagnostic): string => $diagnostic->code, $report->diagnostics);
    }

    private function first(FlowValidationReport $report, string $code): FlowDiagnostic
    {
        foreach ($report->diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return $diagnostic;
            }
        }

        self::fail("No '{$code}' diagnostic in: ".$this->describe($report));
    }

    private function describe(FlowValidationReport $report): string
    {
        return implode(', ', array_map(
            static fn (FlowDiagnostic $diagnostic): string => $diagnostic->severity->value.' '.$diagnostic->code.' @ '.$diagnostic->path,
            $report->diagnostics,
        ));
    }
}
