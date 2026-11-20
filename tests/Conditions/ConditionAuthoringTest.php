<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Conditions;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Conditions\ConditionAuthoringValidator;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Conditions\ConditionSchema;
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\EvaluatorBackend;
use Webong\WorkFlow\Services\FlowDefinitionValidator;

final class ConditionAuthoringTest extends TestCase
{
    public function testNestedErrorsPointToTheSubmittedFieldAndListRule(): void
    {
        $condition = ['any' => [
            ['syntax' => 'jq', 'expression' => 'true'],
            ['not' => ['rules' => ['customer.email' => ['required', 'exists:private_users']]]],
        ]];
        $report = (new FlowDefinitionValidator(conditions: new ConditionAuthoringValidator()))->validate([
            'key' => 'guarded', 'steps' => [['id' => 'send', 'metadata' => ['when' => $condition]]],
        ]);
        self::assertFalse($report->isValid());
        $error = $report->errors()[0];
        self::assertSame('invalid_condition_rule', $error->code);
        self::assertSame('/steps/0/metadata/when/any/1/not/rules/customer.email/1', $error->path);
        self::assertSame('send', $error->stepId);
        self::assertStringNotContainsString('private_users', $error->message);
    }

    public function testMissingIntegrationNeverSilentlyValidatesAGuard(): void
    {
        $report = (new FlowDefinitionValidator())->validate(['key' => 'a', 'steps' => [['id' => 'a', 'metadata' => ['when' => ['rules' => []]]]]]);
        self::assertFalse($report->isValid());
        self::assertSame('condition_validation_unavailable', $report->errors()[0]->code);
        self::assertNull((new FlowDefinitionValidator())->conditionSchema());
    }

    public function testCompilationDoesNotEvaluateEvenAnUnvisitedPredicate(): void
    {
        $backend = new class implements EvaluatorBackend {
            public int $analyses = 0;
            public function evaluate(string $expression, mixed $inputData): mixed { throw new \LogicException('Must never evaluate while authoring'); }
            public function analyze(string $expression): array { $this->analyses++; return ['supported' => true, 'unsupportedFeatures' => []]; }
            public function getName(): string { return 'authoring-test'; }
        };
        $authoring = new ConditionAuthoringValidator(new ConditionCompiler(jq: new Evaluator($backend)));
        self::assertSame([], $authoring->validate(['all' => [['syntax' => 'jq', 'expression' => 'false'], ['syntax' => 'jq', 'expression' => 'error("never execute")']]]));
        self::assertSame(2, $backend->analyses);
    }

    public function testBackendFailuresHaveSafeDiagnosticsAndTreeLimitsAreReported(): void
    {
        $backend = new class implements EvaluatorBackend {
            public function evaluate(string $expression, mixed $inputData): mixed { throw new \LogicException('Do not evaluate'); }
            public function analyze(string $expression): array { throw new \RuntimeException('private backend credential'); }
            public function getName(): string { return 'unavailable'; }
        };
        $validator = new ConditionAuthoringValidator(new ConditionCompiler(jq: new Evaluator($backend)));
        $diagnostics = $validator->validate(['syntax' => 'jq', 'expression' => 'true']);
        self::assertSame('condition_backend_unavailable', $diagnostics[0]->code);
        self::assertSame('/expression', $diagnostics[0]->path);
        self::assertStringNotContainsString('credential', $diagnostics[0]->message);
        $condition = ['rules' => []];
        for ($i = 0; $i < 34; $i++) { $condition = ['not' => $condition]; }
        $diagnostics = (new ConditionAuthoringValidator())->validate($condition);
        self::assertSame('condition_limit_exceeded', $diagnostics[0]->code);
        self::assertSame(str_repeat('/not', 33), $diagnostics[0]->path);
    }

    public function testDiagnosticsEscapePointersAndPreservePipeRuleLocations(): void
    {
        $validator = new ConditionAuthoringValidator();
        foreach ([
            [['not' => ['extra/~' => true, 'rules' => []]], '/not/extra~1~0', 'unknown_condition_property'],
            [['rules' => ['invalid/~' => 'required']], '/rules/invalid~1~0', 'invalid_condition_rule'],
            [['rules' => ['id' => 'required|exists:users']], '/rules/id', 'invalid_condition_rule'],
            [['syntax' => 'jq', 'expression' => '.['], '/expression', 'unsupported_condition_expression'],
            [['all' => [true]], '/all/0', 'condition_not_object'],
            [['syntax' => 'jq', 'expression' => str_repeat('a', 8193)], '/expression', 'invalid_condition_expression'],
            [['version' => null, 'rules' => []], '/version', 'invalid_condition_version'],
        ] as [$document, $path, $code]) {
            $diagnostics = $validator->validate($document);
            self::assertCount(1, $diagnostics);
            self::assertSame($path, $diagnostics[0]->path);
            self::assertSame($code, $diagnostics[0]->code);
        }
    }

    public function testMultipleStepsProduceSeparateFindingsAndValidGuardsRemainValid(): void
    {
        $validator = new FlowDefinitionValidator(conditions: new ConditionAuthoringValidator());
        $report = $validator->validate(['key' => 'a', 'steps' => [
            ['id' => 'a', 'metadata' => ['when' => null]],
            ['id' => 'b', 'metadata' => ['when' => ['all' => []]]],
            ['id' => 'c', 'metadata' => ['when' => ['not' => ['rules' => ['id' => 'required']]]]],
        ]]);
        self::assertCount(2, $report->errors());
        self::assertSame(['a', 'b'], array_map(fn ($d) => $d->stepId, $report->errors()));
        self::assertSame(ConditionSchema::document(), $validator->conditionSchema());
    }

    public function testCheckedInSchemaMatchesItsRuntimeBoundsAndRejectsInvalidShapes(): void
    {
        $schema = json_decode(file_get_contents(__DIR__.'/../../pkg/Conditions/condition.schema.json'));
        self::assertSame(json_encode(ConditionSchema::document()), json_encode($schema));
        foreach ([
            [['rules' => []], true],
            [['any' => [['rules' => ['id' => ['required']]], ['syntax' => 'jq', 'expression' => 'true']]], true],
            [['all' => []], false],
            [['syntax' => 'jq'], false],
            [['not' => ['rules' => []], 'rules' => []], false],
            [['version' => 'v2', 'rules' => []], false],
        ] as [$document, $valid]) {
            $data = json_decode(json_encode($document));
            $jsonValidator = new \JsonSchema\Validator();
            $jsonValidator->validate($data, $schema);
            self::assertSame($valid, $jsonValidator->isValid(), json_encode($jsonValidator->getErrors()));
        }
    }
}
