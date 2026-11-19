<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Jq;

/** Select once by availability; evaluation errors never switch semantics. */
final readonly class MultiBackendEvaluator implements EvaluatorBackend
{
    private EvaluatorBackend $active;

    public function __construct(
        ShellJqEvaluator $shellJq = new ShellJqEvaluator(),
        ZestJqEvaluator $zestJq = new ZestJqEvaluator(),
    ) {
        $this->active = $shellJq->isAvailable() ? $shellJq : $zestJq;
    }

    public function evaluate(string $expression, mixed $inputData): mixed
    {
        return $this->active->evaluate($expression, $inputData);
    }

    /** @return array{supported: bool, unsupportedFeatures: list<string>} */
    public function analyze(string $expression): array { return $this->active->analyze($expression); }

    public function getActiveBackend(): string { return $this->active->getName(); }

    public function getName(): string { return 'multi-backend (' . $this->getActiveBackend() . ')'; }
}
