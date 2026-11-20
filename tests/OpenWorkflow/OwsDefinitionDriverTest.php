<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\OwsDefinitionDriver;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\Services\FlowDefinitionImporter;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\FlowRun;

final class OwsDefinitionDriverTest extends TestCase
{
    public function testOwsJsonAndYamlImportThroughCoreAndExecuteWithCoreContracts(): void
    {
        $json = json_encode($this->document(), JSON_THROW_ON_ERROR);
        $yaml = "document:\n  dsl: '1.0.3'\n  namespace: com.example\n  name: hook\n  version: '1.0.0'\ndo:\n  - make:\n      set:\n        answer: 42\n";
        $importer = new FlowDefinitionImporter([new OwsDefinitionDriver()]);
        foreach ([$json, $yaml] as $source) {
            $result = $importer->import('ows', $source);
            self::assertTrue($result->isValid(), json_encode($result->toArray()));
            self::assertSame('com.example/hook@1.0.0', $result->definition->key);
            self::assertSame('1.0.3', $result->metadata['specification_version']);
            $run = new FlowRun('imported', $result->definition);
            $state = (new FlowRunner())->run($result->definition, $run->initialState(), new OwsWorkflowContext([]), [new OwsSetStepExecutor()]);
            self::assertSame(FlowStatus::COMPLETED, $state->status);
            self::assertSame(['answer' => 42], $state->steps['make']->output?->value);
        }
    }

    public function testInvalidDocumentsFailWithoutAUsableDefinition(): void
    {
        foreach (['invalid: yaml: content: [}', '{}'] as $source) {
            $result = (new FlowDefinitionImporter([new OwsDefinitionDriver()]))->import('ows', $source);
            self::assertFalse($result->isValid());
            self::assertNull($result->definition);
            self::assertSame('invalid_definition_document', $result->diagnostics[0]->code);
        }
    }

    public function testUnsupportedCapabilitiesCannotExposeAPartialRunnableDefinition(): void
    {
        $document = $this->document();
        $document['do'][] = ['unsafe' => ['run' => ['container' => ['image' => 'busybox:1']]]];
        $source = json_encode($document, JSON_THROW_ON_ERROR);
        $legacy = (new OpenWorkflow())->registerOwsDefinition($source);
        self::assertTrue($legacy->valid);
        self::assertNotNull($legacy->flowDefinition);
        self::assertNotEmpty($legacy->unsupported);
        $result = (new FlowDefinitionImporter([new OwsDefinitionDriver()]))->import('ows', $source);
        self::assertFalse($result->isValid());
        self::assertNull($result->definition);
        self::assertNull($result->toArray()['flow_definition']);
        self::assertSame('unsupported_definition_feature', $result->diagnostics[0]->code);
        self::assertSame('/do/1/run/container', $result->diagnostics[0]->path);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return ['document' => ['dsl' => '1.0.3', 'namespace' => 'com.example', 'name' => 'hook', 'version' => '1.0.0'], 'do' => [['make' => ['set' => ['answer' => 42]]]]];
    }
}
