<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowDefinitionDriver;
use Webong\WorkFlow\Enums\FlowDiagnosticSeverity;
use Webong\WorkFlow\Services\FlowDefinitionImporter;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDefinitionImportResult;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;

final class FlowDefinitionImporterTest extends TestCase
{
    public function testCoreImportsThroughAnExplicitCustomDriverWithoutAnyEngine(): void
    {
        $driver = $this->driver('custom');
        $importer = new FlowDefinitionImporter([$driver]);
        self::assertSame(['custom'], $importer->formats());
        $result = $importer->import('custom', 'my-definition');
        self::assertTrue($result->isValid());
        self::assertSame('my-definition', $result->definition->key);
        self::assertSame(1, $driver->calls);
        self::assertSame('my-definition', $result->toArray()['flow_definition']['key']);
    }

    public function testMissingDriversAreNotDiscoveredImplicitly(): void
    {
        $result = (new FlowDefinitionImporter())->import('ows', 'anything');
        self::assertFalse($result->isValid());
        self::assertNull($result->definition);
        self::assertSame('definition_driver_unavailable', $result->diagnostics[0]->code);
    }

    public function testUnexpectedDriverErrorsFailClosedWithoutPrivateDetails(): void
    {
        $driver = new class implements FlowDefinitionDriver {
            public function format(): string { return 'broken'; }
            public function import(string $document): FlowDefinitionImportResult { throw new \RuntimeException('private credential'); }
        };
        $result = (new FlowDefinitionImporter([$driver]))->import('broken', 'secret input');
        self::assertFalse($result->isValid());
        self::assertSame('definition_driver_failure', $result->diagnostics[0]->code);
        self::assertStringNotContainsString('private', json_encode($result->toArray()));
        self::assertStringNotContainsString('secret', json_encode($result->toArray()));
    }

    public function testBlockingErrorsDiscardPartialDefinitionsAndWarningsRemainNonBlocking(): void
    {
        $definition = new FlowDefinition('candidate', []);
        $warning = new FlowDiagnostic('warning', 'Review this.', FlowDiagnosticSeverity::WARNING);
        $error = new FlowDiagnostic('unsupported_definition_feature', 'Cannot execute this construct.', path: '/do/0');
        $result = new FlowDefinitionImportResult($definition, [$warning, $error]);
        self::assertNull($result->definition);
        self::assertFalse($result->isValid());
        self::assertNull($result->toArray()['flow_definition']);
        self::assertTrue((new FlowDefinitionImportResult($definition, [$warning]))->isValid());
        $missing = new FlowDefinitionImportResult(null, [$warning]);
        self::assertSame('definition_import_failed', $missing->diagnostics[1]->code);
    }

    public function testDriverFormatsMustBeValidAndUnique(): void
    {
        foreach ([[$this->driver('ows'), $this->driver('ows')], [$this->driver('')], [$this->driver('bad format')]] as $drivers) {
            try { new FlowDefinitionImporter($drivers); self::fail('Invalid registry accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    private function driver(string $format): FlowDefinitionDriver
    {
        return new class($format) implements FlowDefinitionDriver {
            public int $calls = 0;
            public function __construct(private string $name) {}
            public function format(): string { return $this->name; }
            public function import(string $document): FlowDefinitionImportResult
            {
                $this->calls++;
                return new FlowDefinitionImportResult(new FlowDefinition($document, []));
            }
        };
    }
}
