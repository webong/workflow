<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Jq;

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
        if (! function_exists('proc_open')) { return false; }
        $process = @proc_open([$this->jqBinary, '--version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) { return false; }
        fclose($pipes[0]);
        $version = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        return proc_close($process) === 0 && str_starts_with($version, 'jq-');
    }

    /**
     * @param mixed $inputData Any JSON-encodable value (object, array, scalar)
     * @return mixed
     * @throws ExpressionEvaluationException
     */
    public function evaluate(string $expression, mixed $inputData): mixed
    {
        if (! $this->isAvailable()) {
            throw ExpressionEvaluationException::evaluationError($expression, 'jq binary not available');
        }

        $json = json_encode($inputData, JSON_THROW_ON_ERROR);

        $output = $this->run($expression, $json);

        $lines = preg_split('/\R/', trim($output));
        if (! is_array($lines)) {
            throw ExpressionEvaluationException::evaluationError($expression, 'Unable to parse jq output');
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        if ($lines === []) {
            return null;
        }

        try {
            $results = array_map(
                static fn (string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                $lines,
            );
        } catch (\JsonException $exception) {
            throw ExpressionEvaluationException::evaluationError(
                $expression,
                'jq returned invalid JSON: '.$exception->getMessage(),
            );
        }

        return count($results) === 1 ? $results[0] : $results;
    }

    public function analyze(string $expression): array
    {
        if (! $this->isAvailable()) {
            return ['supported' => false, 'unsupportedFeatures' => ['jq binary not available']];
        }

        try {
            // No stdin values: jq compiles the query but never runs its filter.
            $this->run($expression, '');
            return ['supported' => true, 'unsupportedFeatures' => []];
        } catch (ExpressionEvaluationException $error) {
            return ['supported' => false, 'unsupportedFeatures' => [$error->getMessage()]];
        }
    }
    private function run(string $expression, string $json): string
    {
        $process = proc_open(
            [$this->jqBinary, '--compact-output', '--', $expression],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw ExpressionEvaluationException::evaluationError($expression, 'Unable to start jq');
        }

        $offset = 0;
        $writeFailed = false;
        while ($offset < strlen($json)) {
            // jq can reject an invalid expression and close stdin before PHP
            // writes the payload. That race is part of the process failure,
            // not a PHP notice that should leak into the host test/runtime.
            $written = @fwrite($pipes[0], substr($json, $offset));
            if ($written === false || $written === 0) {
                $writeFailed = true;
                break;
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw ExpressionEvaluationException::evaluationError(
                $expression,
                'jq exited with code '.$returnVar.': '.trim($error),
            );
        }
        if ($writeFailed) {
            throw ExpressionEvaluationException::evaluationError($expression, 'Unable to write jq input');
        }

        return $output;
    }
}
