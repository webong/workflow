<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\App;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\App\FlowAuthoringCli;

final class FlowAuthoringCliTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    public function testHelpExplainsTheCommands(): void
    {
        [$code, $stdout] = $this->invoke([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('workflow validate', $stdout);
        self::assertStringContainsString('workflow operations', $stdout);
        self::assertStringContainsString('workflow inspect', $stdout);
        self::assertStringContainsString('Exit codes:', $stdout);
    }

    public function testValidateAcceptsAValidDefinition(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'validate',
            $this->definition($this->validDocument()),
            '--executors='.$this->executors(),
            '--capabilities=approval',
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('Valid: 0 error(s), 0 warning(s).'."\n", $stdout);
    }

    public function testValidateReportsDiagnosticsAndExitsNonZero(): void
    {
        [$code, $stdout] = $this->invoke([
            'validate',
            $this->definition(['steps' => [['id' => 'a', 'depends_on' => ['ghost']]]]),
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('error /key missing_key:', $stdout);
        self::assertStringContainsString('error /steps/0/depends_on/0 unknown_dependency:', $stdout);
        self::assertStringContainsString('Invalid: 2 error(s), 1 warning(s).', $stdout);
    }

    public function testValidateJsonEmitsTheSameShapeAsTheRpcMethod(): void
    {
        [$code, $stdout] = $this->invoke([
            'validate',
            $this->definition(['key' => 'cli', 'version' => 1, 'steps' => []]),
            '--json',
        ]);

        self::assertSame(0, $code);
        /** @var array{valid: bool, diagnostics: list<array{code: string}>} $data */
        $data = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($data['valid']);
        self::assertSame(['no_steps', 'operations_unchecked'], array_column($data['diagnostics'], 'code'));
    }

    public function testValidateRejectsAnUnreadableDefinition(): void
    {
        [$code, , $stderr] = $this->invoke(['validate', '/nonexistent/definition.json']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Cannot read definition', $stderr);
    }

    public function testMissingOrMalformedInputFailsWithUsageExitCode(): void
    {
        [$code, , $stderr] = $this->invoke(['validate']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Usage: workflow validate', $stderr);

        $file = $this->file('{not json');
        [$code, , $stderr] = $this->invoke(['validate', $file]);
        self::assertSame(2, $code);
        self::assertStringContainsString('is not valid JSON', $stderr);

        [$code, , $stderr] = $this->invoke(['unknown']);
        self::assertSame(2, $code);
        self::assertStringContainsString("Unknown command 'unknown'", $stderr);
    }

    public function testOperationsListsTheDirectoryWithoutADefinition(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'operations',
            '--executors='.$this->executors(),
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame(
            "catalog.approve\tApprove an order [approval]\n"
            ."catalog.notify\tNotify the customer\n",
            $stdout,
        );
    }

    public function testOperationsCanDescribeADefinition(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'operations',
            '--executors='.$this->executors(),
            '--definition='.$this->definition($this->validDocument()),
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame(
            "approve\tcatalog.approve\tApprove an order\n"
            ."notify\tcatalog.notify\tNotify the customer\n",
            $stdout,
        );
    }

    public function testOperationsFailsWithoutADirectoryOrDefinition(): void
    {
        [$code, , $stderr] = $this->invoke(['operations']);

        self::assertSame(2, $code);
        self::assertStringContainsString('No operation directory is bound', $stderr);
    }

    public function testOperationsJsonReturnsDescriptors(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'operations',
            '--executors='.$this->executors(),
            '--json',
        ]);

        self::assertSame(0, $code, $stderr);
        /** @var array{operations: array<string, array{key: string, capabilities: list<string>, input_schema: mixed}>} $data */
        $data = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(['catalog.approve', 'catalog.notify'], array_keys($data['operations']));
        self::assertSame(['approval'], $data['operations']['catalog.approve']['capabilities']);
        self::assertSame(
            ['type' => 'object', 'required' => ['order']],
            $data['operations']['catalog.notify']['input_schema'],
        );
    }

    public function testInspectSummarizesBindingsAndOperations(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'inspect',
            $this->definition($this->validDocument()),
            '--executors='.$this->executors(),
            '--capabilities=approval',
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Flow catalog_order (version 1, 2 step(s))', $stdout);
        self::assertStringContainsString('  approve  Approve an order  depends_on: -', $stdout);
        self::assertStringContainsString('  notify  Notify the customer  depends_on: approve', $stdout);
        self::assertStringContainsString("      input order <- output of step 'approve' pointer '/order_id'", $stdout);
        self::assertStringContainsString('      operation catalog.approve (Approve an order)', $stdout);
    }

    public function testInspectJsonIncludesTheDefinitionOperationsAndDiagnostics(): void
    {
        [$code, $stdout, $stderr] = $this->invoke([
            'inspect',
            $this->definition($this->validDocument()),
            '--executors='.$this->executors(),
            '--capabilities=approval',
            '--json',
        ]);

        self::assertSame(0, $code, $stderr);
        /** @var array{definition: array{key: string}, operations: array<string, array{key: string}>, valid: bool, diagnostics: list<mixed>} $data */
        $data = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('catalog_order', $data['definition']['key']);
        self::assertSame('catalog.approve', $data['operations']['approve']['key']);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['diagnostics']);
    }

    public function testInspectLoadsTheExecutorBootstrapOnlyOnce(): void
    {
        $counter = $this->file('');
        [$code, , $stderr] = $this->invoke([
            'inspect',
            $this->definition($this->validDocument()),
            '--executors='.$this->executors($counter),
            '--capabilities=approval',
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('x', file_get_contents($counter));
    }

    public function testInspectFailsForAnUnparseableDefinition(): void
    {
        [$code, $stdout] = $this->invoke([
            'inspect',
            $this->definition(['key' => 'broken', 'steps' => [], 'version' => -1]),
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('The definition could not be parsed:', $stdout);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(array $arguments): array
    {
        $stdout = '';
        $stderr = '';
        $cli = new FlowAuthoringCli(
            static function (string $text) use (&$stdout): void {
                $stdout .= $text;
            },
            static function (string $text) use (&$stderr): void {
                $stderr .= $text;
            },
        );

        return [$cli->run($arguments), $stdout, $stderr];
    }

    /** @param array<string, mixed> $document */
    private function definition(array $document): string
    {
        return $this->file(json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'workflow-cli-');
        if ($path === false) {
            self::fail('Cannot create a temporary file.');
        }
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function validDocument(): array
    {
        return [
            'key' => 'catalog_order',
            'version' => 1,
            'metadata' => ['required_capabilities' => ['approval']],
            'steps' => [
                ['id' => 'approve', 'label' => 'Approve an order'],
                ['id' => 'notify', 'label' => 'Notify the customer', 'depends_on' => ['approve'], 'input' => [
                    'order' => ['source' => 'output', 'step' => 'approve', 'pointer' => '/order_id'],
                ]],
            ],
        ];
    }

    private function executors(?string $counterFile = null): string
    {
        $code = <<<'PHP'
<?php

use Webong\WorkFlow\Contracts\FlowOperationDirectory;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

return [new class implements FlowStepExecutor, FlowOperationProvider, FlowOperationDirectory {
    public function supports(FlowStepDefinition $step): bool
    {
        return in_array($step->id, ['approve', 'notify'], true);
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        return StepResult::completed();
    }

    public function operations(): array
    {
        return [
            new FlowOperation(key: 'catalog.approve', label: 'Approve an order', capabilities: ['approval']),
            new FlowOperation(
                key: 'catalog.notify',
                label: 'Notify the customer',
                inputSchema: ['type' => 'object', 'required' => ['order']],
            ),
        ];
    }

    public function operation(FlowStepDefinition $step): ?FlowOperation
    {
        return $step->id === 'approve'
            ? new FlowOperation(key: 'catalog.approve', label: 'Approve an order', capabilities: ['approval'])
            : new FlowOperation(
                key: 'catalog.notify',
                label: 'Notify the customer',
                inputSchema: ['type' => 'object', 'required' => ['order']],
            );
    }
}];
PHP;

        if ($counterFile !== null) {
            $code = str_replace(
                "<?php\n",
                "<?php\nfile_put_contents(".var_export($counterFile, true).", 'x', FILE_APPEND);\n",
                $code,
            );
        }

        return $this->file($code);
    }
}
