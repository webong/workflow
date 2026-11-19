<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Jq;

/** Raw jq: the input is exactly '.', with no workflow context or variable rewriting. */
final readonly class Evaluator implements EvaluatorBackend
{
    public function __construct(private EvaluatorBackend $backend = new MultiBackendEvaluator()) {}

    public function evaluate(string $expression, mixed $inputData): mixed
    {
        try {
            return $this->normalize($this->backend->evaluate($expression, $inputData));
        } catch (\JsonException $exception) {
            throw ExpressionEvaluationException::evaluationError($expression, $exception->getMessage());
        }
    }

    /** @return array{supported: bool, unsupportedFeatures: list<string>} */
    public function analyze(string $expression): array { return $this->backend->analyze($expression); }

    public function getName(): string { return $this->backend->getName(); }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            return $this->normalize(json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        }
        return is_array($value) ? array_map($this->normalize(...), $value) : $value;
    }
}
