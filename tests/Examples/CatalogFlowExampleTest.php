<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Examples;

use PHPUnit\Framework\TestCase;

final class CatalogFlowExampleTest extends TestCase
{
    public function testTheCatalogBackedExampleDiscoversValidatesAndCompletesAFlow(): void
    {
        ob_start();

        try {
            require dirname(__DIR__, 2).'/examples/catalog-flow.php';
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $approveOutput = json_encode(
            ['order_id' => 'order-42', 'decision' => 'approved'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        self::assertSame(
            "Operations: catalog.approve (Approve an order), catalog.notify (Notify the customer)\n"
            ."Validation: valid (0 errors, 0 warnings)\n"
            ."After start: running; approve: pending; notify: pending\n"
            ."After completion: running; approve: completed\n"
            ."After resume: completed; notify: completed\n"
            ."approve output: {$approveOutput}\n"
            ."notify output: {$approveOutput}\n",
            $output,
        );
    }
}
