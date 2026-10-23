<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Shell-based jq evaluator using the system jq binary.
 * Provides 100% jq compatibility when jq is installed.
 */
final class ShellJqEvaluator implements EvaluatorBackend
{
    public function __construct(
        private readonly string $jqBinary = 'jq',
    ) {}

    public function getName(): string
    {
        return 'shell-jq';
    }

    public function isAvailable(): bool
    {
        // Check if jq binary exists and works
        $output = [];
        $returnVar = 0;
        exec("{$this->jqBinary} --version 2>&1", $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * @param mixed $inputData Any JSON-encodable value (object, array, scalar)
     * @return mixed
     * @throws ExpressionEvaluationException
     */
    public function evaluate(string $expression, mixed $inputData): mixed
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('jq binary not available');
        }

        $json = json_encode($inputData, JSON_THROW_ON_ERROR);
        
        // Use -n to not read input from stdin, --argjson for input
        // Actually, we'll pass JSON via stdin
        $cmd = sprintf(
            '%s -n --argjson input %s \'%s\'',
            escapeshellcmd($this->jqBinary),
            escapeshellarg($json),
            $expression
        );

        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw ExpressionEvaluationException::evaluationError(
                $expression,
                'jq exited with code ' . $returnVar . ': ' . implode("\n", $output)
            );
        }

        $result = implode("\n", $output);
        if ($result === '') {
            return null;
        }

        // Try to decode as JSON
        try {
            return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Return raw string if not valid JSON
            return $result;
        }
    }

    public function analyze(string $expression): array
    {
        if (! $this->isAvailable()) {
            return ['supported' => false, 'unsupportedFeatures' => ['jq binary not available']];
        }

        // We can't easily statically analyze with shell jq, so assume supported
        return ['supported' => true, 'unsupportedFeatures' => []];
    }
}