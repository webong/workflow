<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Jq;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Jq\MultiBackendEvaluator;
use Webong\WorkFlow\Jq\ShellJqEvaluator;
use Webong\WorkFlow\Jq\ZestJqEvaluator;

class MultiBackendEvaluatorTest extends TestCase
{
    public function testFallsBackToZestJqWhenShellJqMissing(): void
    {
        // Point at a binary that does not exist
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $this->assertFalse($multi->getActiveBackend() === 'shell-jq');
        $this->assertSame('zest-jq', $multi->getActiveBackend());
        $this->assertSame('multi-backend (zest-jq)', $multi->getName());
    }

    public function testEvaluatesViaFallbackWhenShellJqMissing(): void
    {
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $result = $multi->evaluate('.order.id', ['order' => ['id' => 123]]);
        $this->assertSame(123, $result);

        $bool = $multi->evaluate('.order.status == "pending"', ['order' => ['status' => 'pending']]);
        $this->assertTrue($bool);
    }

    public function testAnalyzeViaFallback(): void
    {
        $multi = new MultiBackendEvaluator(
            shellJq: new ShellJqEvaluator('definitely-not-a-real-jq-binary-xyz'),
            zestJq: new ZestJqEvaluator(),
        );

        $supported = $multi->analyze('.items | map(.qty) | add');
        $this->assertTrue($supported['supported']);

        $unsupported = $multi->analyze('this is not valid jq !!!');
        $this->assertFalse($unsupported['supported']);
        $this->assertNotEmpty($unsupported['unsupportedFeatures']);
    }

    public function testShellJqEvaluatesTheProvidedJsonInputAndStreamsResults(): void
    {
        $shell = new ShellJqEvaluator();
        if (! $shell->isAvailable()) {
            self::markTestSkipped('The jq binary is not available.');
        }

        self::assertSame(123, $shell->evaluate('.order.id', ['order' => ['id' => 123]]));
        self::assertSame([1, 2, 3], $shell->evaluate('.items[]', ['items' => [1, 2, 3]]));
        self::assertSame("O'Reilly", $shell->evaluate('.author', ['author' => "O'Reilly"]));
    }

}
