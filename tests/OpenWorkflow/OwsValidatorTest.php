<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use Webong\WorkFlow\OpenWorkflow\Validation\OwsValidator;
use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;
use PHPUnit\Framework\TestCase;

class OwsValidatorTest extends TestCase
{
    private OwsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OwsValidator();
    }

    public function testValidMinimalDocument(): void
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
YAML;

        $document = OwsDocument::fromYaml($yaml);
        $result = $this->validator->validate($document);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidDocumentWithSwitch(): void
    {
        // Switch test uses JSON to avoid YAML parsing edge cases with 'then:' key
        $json = <<<'JSON'
{
  "version": "1.0.3",
  "document": {
    "dsl": "1.0.3",
    "namespace": "com.example",
    "name": "order-processing",
    "version": "1.0.0"
  },
  "do": [
    {
      "check-status": {
        "call": "checkStatus",
        "switch": ".status == 'pending'",
        "cases": [
          {"case": ".status == 'pending'", "then": "process-order"},
          {"default": true, "then": "exit"}
        ]
      }
    },
    {
      "process-order": {
        "call": "processOrder"
      }
    }
  ]
}
JSON;

        $document = OwsDocument::fromJson($json);
        $result = $this->validator->validate($document);

        $this->assertTrue($result['valid']);
    }

    public function testInvalidDocumentMissingRequiredFields(): void
    {
        $yaml = <<<'YAML'
version: "1.0.3"
document:
  namespace: com.example
do: []
YAML;

        $document = OwsDocument::fromYaml($yaml);
        $result = $this->validator->validate($document);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidJsonDocument(): void
    {
        $json = <<<'JSON'
{
  "version": "1.0.3",
  "document": {
    "dsl": "1.0.3",
    "namespace": "com.example",
    "name": "test-flow",
    "version": "1.0.0"
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

        $document = OwsDocument::fromJson($json);
        $result = $this->validator->validate($document);

        $this->assertTrue($result['valid']);
    }

    public function testVersionMismatchWarning(): void
    {
        $yaml = <<<'YAML'
version: "1.0.4"
document:
  dsl: "1.0.4"
  namespace: com.example
  name: test
  version: "1.0.0"
do: []
YAML;

        $document = OwsDocument::fromYaml($yaml);
        $result = $this->validator->validate($document);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('1.0.4', $result['warnings'][0]);
        $this->assertStringContainsString('1.0.3', $result['warnings'][0]);
    }

    public function testPinnedVersion(): void
    {
        $this->assertEquals('1.0.3', $this->validator->pinnedVersion());
    }
}