<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Examples;

use PHPUnit\Framework\TestCase;

final class OrderApprovalExampleTest extends TestCase
{
    public function testTheDocumentedFlowCanBeRunFromStartToFinish(): void
    {
        ob_start();

        try {
            require dirname(__DIR__, 2).'/examples/order-approval.php';
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(
            "After start: running; approve: pending\n"
            ."After callback: running; approve: completed\n"
            ."After resume: completed; notify: completed\n",
            $output,
        );
    }
}
