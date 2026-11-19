<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Jq;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\ExpressionEvaluationException;
use Webong\WorkFlow\Jq\ExpressionParser;
use Webong\WorkFlow\Jq\ExpressionAnalyzer;
use Webong\WorkFlow\Jq\MultiBackendEvaluator;
use Webong\WorkFlow\Jq\ShellJqEvaluator;
use Webong\WorkFlow\Jq\ZestJqEvaluator;

final class EvaluatorTest extends TestCase
{
    public function testPurePhpEvaluationNeedsNoWorkflowContext(): void
    {
        $jq = new Evaluator(new ZestJqEvaluator());
        self::assertSame(42, $jq->evaluate('.order.id', ['order' => ['id' => 42]]));
        self::assertTrue($jq->evaluate('.amount >= 100', ['amount' => 120]));
        self::assertSame(['answer' => 42], $jq->evaluate('{answer: .id}', ['id' => 42]));
        self::assertSame('$workflow.id', $jq->evaluate('"$workflow.id"', []));
    }

    public function testRawInputKeepsArrayObjectAndNullMeaning(): void
    {
        $jq = new Evaluator(new ZestJqEvaluator());
        self::assertSame('array', $jq->evaluate('type', []));
        self::assertSame('object', $jq->evaluate('type', new \stdClass()));
        self::assertSame('null', $jq->evaluate('type', null));
        self::assertSame([], $jq->evaluate('.', []));
    }

    public function testStreamsAndExplicitNullHaveConsistentConvenienceResults(): void
    {
        $backends = [new ZestJqEvaluator()];
        $shell = new ShellJqEvaluator();
        if ($shell->isAvailable()) { $backends[] = $shell; }
        foreach ($backends as $backend) {
            $jq = new Evaluator($backend);
            self::assertSame([1, 2, 3], $jq->evaluate('.[]', [1, 2, 3]), $backend->getName());
            self::assertNull($jq->evaluate('.[]', []), $backend->getName());
            self::assertNull($jq->evaluate('null', []), $backend->getName());
            self::assertSame([], $jq->evaluate('[]', []), $backend->getName());
            self::assertSame(-1, $jq->evaluate('-1', null), $backend->getName());
        }
    }

    public function testInvalidSyntaxIsReportedByThePurePhpBackend(): void
    {
        $this->expectException(ExpressionEvaluationException::class);
        (new Evaluator(new ZestJqEvaluator()))->evaluate('this is not jq !!!', []);
    }

    public function testNativeVariableBindingsAreNotRewrittenAsOwsFields(): void
    {
        $backend = new ShellJqEvaluator();
        if (! $backend->isAvailable()) { self::markTestSkipped('Native jq is unavailable'); }
        self::assertSame(42, (new Evaluator($backend))->evaluate('. as $workflow | $workflow.id', ['id' => 42]));
    }

    public function testParserAndAnalyzerAreIndependentOfOws(): void
    {
        $node = (new ExpressionParser('.amount >= 100'))->parse();
        $analyzer = new ExpressionAnalyzer();
        $analyzer->analyze($node);
        self::assertSame([], $analyzer->unsupportedFeatures);
    }

    public function testNativeAnalysisCompilesWithoutRunningTheQuery(): void
    {
        $backend = new ShellJqEvaluator();
        if (! $backend->isAvailable()) { self::markTestSkipped('Native jq is unavailable'); }
        self::assertTrue($backend->analyze('error("must not run")')['supported']);
        self::assertFalse($backend->analyze('this is not jq !!!')['supported']);
        self::assertFalse($backend->analyze('$undeclared')['supported']);
    }

    public function testConfiguredBinaryPathsAreLiteralAndBackendErrorsDoNotFallThrough(): void
    {
        $directory = sys_get_temp_dir() . '/workflow-jq-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $binary = $directory . '/jq with spaces';
        $marker = $directory . '/should-not-exist';
        file_put_contents($binary, "#!/bin/sh\nif [ \"\$1\" = '--version' ]; then echo jq-test; exit 0; fi\ncat >/dev/null\necho 'deliberate backend failure' >&2\nexit 7\n");
        chmod($binary, 0755);
        try {
            self::assertTrue((new ShellJqEvaluator($binary))->isAvailable());
            self::assertFalse((new ShellJqEvaluator($binary . '; touch ' . $marker))->isAvailable());
            self::assertFileDoesNotExist($marker);
            $this->expectException(ExpressionEvaluationException::class);
            $this->expectExceptionMessage('deliberate backend failure');
            (new MultiBackendEvaluator(shellJq: new ShellJqEvaluator($binary)))->evaluate('.id', ['id' => 42]);
        } finally {
            unlink($binary);
            if (is_file($marker)) { unlink($marker); }
            rmdir($directory);
        }
    }
}
