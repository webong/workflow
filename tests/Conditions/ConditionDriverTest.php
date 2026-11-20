<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Conditions;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Conditions\CompiledCondition;
use Webong\WorkFlow\Conditions\ConditionAuthoringValidator;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Conditions\ConditionDefinitionException;
use Webong\WorkFlow\Conditions\ConditionResult;
use Webong\WorkFlow\Contracts\ConditionDriver;

final class ConditionDriverTest extends TestCase
{
    public function testCoreComposesACustomPhpDriverAndKeepsAuthoringSeparateFromEvaluation(): void
    {
        $driver = $this->driver();
        $compiler = new ConditionCompiler([$driver]);
        $document = ['all' => [['syntax' => 'test', 'value' => true], ['not' => ['syntax' => 'test', 'value' => false]]]];
        self::assertSame([], (new ConditionAuthoringValidator($compiler))->validate($document));
        self::assertSame(0, $driver->evaluations);
        $condition = $compiler->compile($document);
        self::assertTrue($condition->matches([]));
        self::assertSame(2, $driver->evaluations);
        self::assertSame(['test'], $compiler->schema()['x-condition-syntaxes']);
    }

    public function testMissingDriverFailsClosedEvenIfOptionalPackagesAreInstalled(): void
    {
        foreach ([['syntax' => 'jq', 'expression' => 'true'], ['rules' => []]] as $document) {
            $diagnostics = (new ConditionAuthoringValidator())->validate($document, '/guard');
            self::assertSame('condition_driver_unavailable', $diagnostics[0]->code);
        }
    }

    public function testDriverFailuresDoNotBecomeMatchesOrLeakPrivateAuthoringErrors(): void
    {
        $compiler = new ConditionCompiler([$this->driver()]);
        $diagnostics = (new ConditionAuthoringValidator($compiler))->validate(['not' => ['syntax' => 'test', 'value' => 'bad']]);
        self::assertSame('/not/value', $diagnostics[0]->path);
        $diagnostics = (new ConditionAuthoringValidator($compiler))->validate(['syntax' => 'test', 'fail_compile' => true]);
        self::assertSame('condition_driver_failure', $diagnostics[0]->code);
        self::assertStringNotContainsString('private', $diagnostics[0]->message);
        $result = $compiler->compile(['not' => ['syntax' => 'test', 'value' => true, 'fail_evaluate' => true]])->evaluate([]);
        self::assertTrue($result->isError());
        self::assertFalse($result->matched());
    }

    public function testDuplicateAndReservedDriverNamesAreRejected(): void
    {
        foreach ([[$this->driver(), $this->driver()], [$this->driver('all')], [$this->driver('')]] as $drivers) {
            try { new ConditionCompiler($drivers); self::fail('Invalid driver registry accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testCoreOwnsTheVersionEvenWhenADriverSchemaDisagrees(): void
    {
        $driver = new class implements ConditionDriver {
            public function syntax(): string { return 'custom'; }
            public function compile(array $document): CompiledCondition { return new CompiledCondition(static fn (mixed $data) => ConditionResult::decided(true)); }
            public function schema(): array { return ['type' => 'object', 'properties' => ['version' => ['enum' => ['v9']]]]; }
        };
        $compiler = new ConditionCompiler([$driver]);
        $variants = $compiler->schema()['definitions']['condition']['oneOf'];
        self::assertSame(['v1'], $variants[3]['properties']['version']['enum']);
        self::assertSame('invalid_condition_version', (new ConditionAuthoringValidator($compiler))->validate(['version' => 'v9', 'syntax' => 'custom'])[0]->code);
    }

    private function driver(string $syntax = 'test'): ConditionDriver
    {
        return new class($syntax) implements ConditionDriver {
            public int $evaluations = 0;
            public function __construct(private string $name) {}
            public function syntax(): string { return $this->name; }
            public function schema(): array { return ['type' => 'object', 'properties' => ['syntax' => ['enum' => [$this->name]], 'value' => ['type' => 'boolean']], 'required' => ['syntax', 'value'], 'additionalProperties' => false]; }
            public function compile(array $document): CompiledCondition
            {
                if ($document['fail_compile'] ?? false) { throw new \RuntimeException('private engine failure'); }
                if (! is_bool($document['value'] ?? null)) { throw new ConditionDefinitionException('invalid_test_value', 'Boolean required.', '/value'); }
                return new CompiledCondition(function (mixed $input) use ($document): ConditionResult {
                    $this->evaluations++;
                    if ($document['fail_evaluate'] ?? false) { throw new \RuntimeException('engine evaluation failed'); }
                    return ConditionResult::decided($document['value']);
                });
            }
        };
    }
}
