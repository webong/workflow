<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Examples;

use PHPUnit\Framework\TestCase;

final class ChannelSetupExampleTest extends TestCase
{
    public function test_it_resumes_after_a_webhook_without_replaying_the_waiting_step(): void
    {
        $output = $this->runExample([]);
        self::assertStringContainsString('Resume while waiting: webhook attempts = 1', $output);
        self::assertStringContainsString('Webhook received: running', $output);
        self::assertStringContainsString('After resume: completed; verify: completed', $output);
    }

    public function test_it_explains_required_operator_intervention(): void
    {
        $output = $this->runExample(['--fail-verification']);
        self::assertStringContainsString('After resume: blocked; verify: failed', $output);
        self::assertStringContainsString('Operator action: Check the provider settings', $output);
    }

    /** @param list<string> $argv */
    private function runExample(array $argv): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 2).'/examples/channel-setup.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
