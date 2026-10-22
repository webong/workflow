<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use Webong\WorkFlow\OpenWorkflow\Contracts\CapabilityReport;
use Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use PHPUnit\Framework\TestCase;

class OpenWorkflowTest extends TestCase
{
    private OpenWorkflow $openWorkflow;

    protected function setUp(): void
    {
        $this->openWorkflow = new OpenWorkflow();
    }

    public function testRegisterValidYamlDocument(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: order-processing
  version: "1.0.0"
do:
  - validate-order:
      call: validateOrder
  - process-order:
      call: processOrder
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertInstanceOf(CapabilityReport::class, $report);
        $this->assertTrue($report->valid);
        $this->assertEquals('1.0.3', $report->version);
        $this->assertNotNull($report->flowDefinition);
        $this->assertEquals('com.example/order-processing@1.0.0', $report->flowDefinition->key);
        $this->assertCount(2, $report->flowDefinition->steps);
    }

    public function testRegisterValidJsonDocument(): void
    {
        $json = <<<'JSON'
{
  "version": "1.0.3",
  "document": {
    "dsl": "1.0.3",
    "namespace": "com.example",
    "name": "json-flow",
    "version": "2.0.0"
  },
  "do": [
    {
      "step1": {
        "call": "doSomething"
      }
    }
  ]
}
JSON;

        $report = $this->openWorkflow->registerOwsDefinition($json);

        $this->assertTrue($report->valid);
        $this->assertEquals('com.example/json-flow@2.0.0', $report->flowDefinition->key);
        $this->assertCount(1, $report->flowDefinition->steps);
    }

    public function testRegisterInvalidYaml(): void
    {
        $yaml = 'invalid: yaml: content: [}';

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertFalse($report->valid);
        $this->assertNotEmpty($report->unsupported);
    }

    public function testRegisterMissingRequiredFields(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  namespace: com.example
do: []
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertFalse($report->valid);
    }

    public function testRegisterWithSwitch(): void
    {
        // Use JSON to avoid YAML parsing issues with 'then:' key
        $json = <<<'JSON'
{
  "version": "1.0.3",
  "document": {
    "dsl": "1.0.3",
    "namespace": "com.example",
    "name": "with-switch",
    "version": "1.0.0"
  },
  "do": [
    {
      "check": {
        "call": "checkStatus",
        "switch": ".status",
        "cases": [
          {"case": ".status == \"pending\"", "then": "process"},
          {"default": true, "then": "exit"}
        ]
      }
    },
    {
      "process": {
        "call": "processOrder"
      }
    }
  ]
}
JSON;

        $report = $this->openWorkflow->registerOwsDefinition($json);

        $this->assertTrue($report->valid);
        $this->assertNotNull($report->flowDefinition);
        // Switch is in the initial supported subset per ADR
        $this->assertContains('switch', $report->supported);
        $this->assertContains('case', $report->supported);
        $this->assertContains('default', $report->supported);
    }

    public function testRegisterWithForLoop(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: with-loop
  version: "1.0.0"
do:
  - iterate:
      for: ".items[]"
      do:
        - process-item:
            call: processItem
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        // for loop is unsupported
        $unsupportedFeatures = array_map(
            fn (UnsupportedFeature $u) => $u->feature,
            $report->unsupported
        );
        $this->assertContains('for', $unsupportedFeatures);
    }

    public function testRegisterWithFork(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: with-fork
  version: "1.0.0"
do:
  - parallel:
      fork:
        - branch1:
            call: task1
        - branch2:
            call: task2
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $unsupportedFeatures = array_map(
            fn ($u) => $u->feature,
            $report->unsupported
        );
        $this->assertContains('fork', $unsupportedFeatures);
    }

    public function testRegisterWithWait(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: with-wait
  version: "1.0.0"
do:
  - wait-step:
      wait: "PT1H"
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $unsupportedFeatures = array_map(
            fn ($u) => $u->feature,
            $report->unsupported
        );
        $this->assertContains('wait|listen', $unsupportedFeatures);
    }

    public function testRegisterWithTryCatch(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: with-try
  version: "1.0.0"
do:
  - risky:
      try:
        do:
          - attempt:
              call: riskyCall
        catch:
          - handle:
              call: handleError
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $unsupportedFeatures = array_map(
            fn ($u) => $u->feature,
            $report->unsupported
        );
        $this->assertContains('try', $unsupportedFeatures);
    }

    public function testValidateOnly(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: validate-only
  version: "1.0.0"
do:
  - step1:
      call: doSomething
YAML;

        $report = $this->openWorkflow->validateOnly($yaml);

        $this->assertTrue($report->valid);
        $this->assertNull($report->flowDefinition); // validateOnly doesn't translate
    }

    public function testEvaluateExpression(): void
    {
        $result = $this->openWorkflow->evaluateExpression(
            '.status == "pending"',
            ['status' => 'pending']
        );
        $this->assertTrue($result);

        $result = $this->openWorkflow->evaluateExpression(
            '.status == "pending"',
            ['status' => 'completed']
        );
        $this->assertFalse($result);
    }

    public function testAnalyzeExpression(): void
    {
        $result = $this->openWorkflow->analyzeExpression('.status == "pending"');
        $this->assertTrue($result['supported']);
        $this->assertEmpty($result['unsupportedFeatures']);
    }

    public function testPinnedVersion(): void
    {
        $this->assertEquals('1.0.3', $this->openWorkflow->pinnedVersion());
    }

    public function testMetadataInReport(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: metadata-test
  version: "1.0.0"
do:
  - step1:
      call: doSomething
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertArrayHasKey('ows_namespace', $report->metadata);
        $this->assertArrayHasKey('ows_name', $report->metadata);
        $this->assertArrayHasKey('ows_version', $report->metadata);
        $this->assertEquals('com.example', $report->metadata['ows_namespace']);
        $this->assertEquals('metadata-test', $report->metadata['ows_name']);
        $this->assertEquals('1.0.0', $report->metadata['ows_version']);
    }

    public function testSequentialDependencyInferred(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: sequential
  version: "1.0.0"
do:
  - first:
      call: firstTask
  - second:
      call: secondTask
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $steps = $report->flowDefinition->steps;
        $this->assertCount(2, $steps);
        // Second step should depend on first (implicit sequential dependency)
        $this->assertContains('first', $steps[1]->dependsOn);
    }

    public function testExplicitDependsOn(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: explicit-deps
  version: "1.0.0"
do:
  - first:
      call: firstTask
  - second:
      call: secondTask
  - third:
      call: thirdTask
      dependsOn:
        - first
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $steps = $report->flowDefinition->steps;
        $third = $report->flowDefinition->step('third');
        $this->assertNotNull($third);
        $this->assertContains('first', $third->dependsOn);
        // Should also have implicit dependency on second
        $this->assertContains('second', $third->dependsOn);
    }

    public function testRetryPolicyTranslation(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: retry-test
  version: "1.0.0"
do:
  - flaky:
      call: flakyService
      retry:
        maxAttempts: 5
        backoff: 10
        idempotent: false
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $step = $report->flowDefinition->step('flaky');
        $this->assertNotNull($step);
        $this->assertNotNull($step->retryPolicy);
        $this->assertEquals(5, $step->retryPolicy->maxAttempts);
        $this->assertEquals(10, $step->retryPolicy->backoffSeconds);
        $this->assertFalse($step->retryPolicy->idempotent);
    }

    public function testRunHttpTranslation(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: http-test
  version: "1.0.0"
do:
  - call-api:
      run: "https://api.example.com/endpoint"
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $step = $report->flowDefinition->step('call-api');
        $this->assertNotNull($step);
        $this->assertEquals('http', $step->metadata['ows_run_type']);
        $this->assertEquals('https://api.example.com/endpoint', $step->metadata['ows_run_target']);
    }

    public function testRunScriptTranslation(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: script-test
  version: "1.0.0"
do:
  - run-script:
      run: "echo 'hello'"
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $step = $report->flowDefinition->step('run-script');
        $this->assertNotNull($step);
        $this->assertEquals('script', $step->metadata['ows_run_type']);
    }

    public function testInputOutputExpressions(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  dsl: "1.0.3"
  namespace: com.example
  name: io-test
  version: "1.0.0"
do:
  - transform:
      call: transformData
      with: ".order | {id: .id, total: .amount}"
      export: ".result | {success: .ok, data: .payload}"
YAML;

        $report = $this->openWorkflow->registerOwsDefinition($yaml);

        $this->assertTrue($report->valid);
        $step = $report->flowDefinition->step('transform');
        $this->assertNotNull($step);
        $this->assertArrayHasKey('ows_input_expression', $step->metadata);
        $this->assertArrayHasKey('ows_output_expression', $step->metadata);
    }
}