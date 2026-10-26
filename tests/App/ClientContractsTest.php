<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\App;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Tools\ClientContractGenerator;

require_once dirname(__DIR__, 2).'/tools/ClientContractGenerator.php';

/**
 * Node, Python, and Go contracts are generated from one manifest. This test
 * keeps the checked-in files honest: a stale file is a failing test, not a
 * documentation bug discovered later by a client author.
 */
final class ClientContractsTest extends TestCase
{
    public function testTheCheckedInContractsMatchTheManifest(): void
    {
        /** @var array{enums: array<string, mixed>, types: array<string, mixed>} $manifest */
        $manifest = require dirname(__DIR__, 2).'/tools/contracts.php';
        $rendered = ClientContractGenerator::render($manifest);

        foreach ([
            'ts' => 'types/work-flow.d.ts',
            'py' => 'types/work_flow.py',
            'go' => 'types/work_flow.go',
        ] as $language => $relative) {
            $path = dirname(__DIR__, 2).'/'.$relative;
            self::assertFileExists($path);
            self::assertSame(
                $rendered[$language],
                file_get_contents($path),
                "{$relative} is stale; run `composer types`.",
            );
        }
    }

    public function testTheManifestCoversTheAuthoringSurface(): void
    {
        /** @var array{enums: array<string, mixed>, types: array<string, mixed>} $manifest */
        $manifest = require dirname(__DIR__, 2).'/tools/contracts.php';

        self::assertSame(
            ['error', 'warning', 'info'],
            array_keys($manifest['enums']['FlowDiagnosticSeverity']['cases']),
        );

        foreach ([
            'FlowDefinition',
            'FlowStepDefinition',
            'FlowInputBinding',
            'FlowOperation',
            'FlowDiagnostic',
            'FlowValidationReport',
            'FlowValidateParams',
            'FlowState',
        ] as $type) {
            self::assertArrayHasKey($type, $manifest['types']);
        }

        $diagnosticFields = array_column($manifest['types']['FlowDiagnostic']['fields'], 'name');
        self::assertSame(['code', 'message', 'severity', 'path', 'step_id', 'details'], $diagnosticFields);
    }

    public function testTheGoStateContractPreservesAbsentAndExplicitNullOutputs(): void
    {
        /** @var array{enums: array<string, mixed>, types: array<string, mixed>} $manifest */
        $manifest = require dirname(__DIR__, 2).'/tools/contracts.php';
        $go = ClientContractGenerator::render($manifest)['go'];

        self::assertStringContainsString('import "encoding/json"', $go);
        self::assertStringContainsString('Output json.RawMessage `json:"output,omitempty"`', $go);
    }
}
