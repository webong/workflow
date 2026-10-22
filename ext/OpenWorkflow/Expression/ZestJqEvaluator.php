<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

use Wikimedia\ZestJQ\JQ;
use Wikimedia\ZestJQ\JQError;

/**
 * Wrapper around wikimedia/zest-jq for jq expression evaluation.
 * Handles the subset of jq that zest-jq supports well.
 */
final class ZestJqEvaluator
{
    /**
     * Evaluate a jq expression against input data.
     *
     * @param array<string, mixed> $inputData
     * @return mixed
     * @throws ExpressionEvaluationException
     */
    public function evaluate(string $expression, array $inputData): mixed
    {
        $json = json_encode($inputData, JSON_THROW_ON_ERROR);

        try {
            $results = [];
            foreach (JQ::evalString($json, $expression, null, null) as $val) {
                $results[] = $val;
            }

            // For single results, return the value directly
            // For multiple results (e.g., from array iteration), return the array
            if (count($results) === 1) {
                return $results[0];
            }
            return $results;

        } catch (JQError $e) {
            throw ExpressionEvaluationException::evaluationError($expression, $e->getMessage());
        } catch (\JsonException $e) {
            throw ExpressionEvaluationException::evaluationError($expression, 'JSON encoding failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if an expression uses only features supported by zest-jq.
     *
     * @return array{supported: bool, unsupportedFeatures: list<string>}
     */
    public function analyze(string $expression): array
    {
        // zest-jq doesn't have a static analysis API, so we try to compile
        // and catch syntax errors. For runtime unsupported features, we'd need
        // to test against sample data, which is heavy.
        // For now, we assume valid syntax = supported.
        try {
            JQ::compile($expression);
            return ['supported' => true, 'unsupportedFeatures' => []];
        } catch (JQError $e) {
            return [
                'supported' => false,
                'unsupportedFeatures' => ['zest-jq: ' . $e->getMessage()],
            ];
        } catch (\Throwable $e) {
            return [
                'supported' => false,
                'unsupportedFeatures' => ['compile-error: ' . $e->getMessage()],
            ];
        }
    }
}