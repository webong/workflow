<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Conformance;

/**
 * Outcome of running one CTK scenario through the adapter's
 * validator + translator, with any statically-checkable assertions applied.
 */
final readonly class CtkScenarioResult
{
    public const PARSE_FAILURE = 'parse-failure';
    public const VALIDATION_FAILURE = 'validation-failure';
    public const UNSUPPORTED = 'unsupported';
    public const STATIC_PASS = 'static-pass';
    public const STATIC_ASSERTION_FAILURE = 'static-assertion-failure';

    /**
     * @param list<string> $blockingFeatures Feature identifiers blocking execution
     * @param list<string> $staticChecks Checked static assertions ('name' => outcome)
     */
    public function __construct(
        public CtkScenario $scenario,
        public string $status,
        public bool $requiresRuntime,
        public array $blockingFeatures = [],
        public array $staticChecks = [],
        public string $detail = '',
    ) {}

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PARSE_FAILURE => 'parse failure',
            self::VALIDATION_FAILURE => 'validation failure',
            self::UNSUPPORTED => 'unsupported feature',
            self::STATIC_PASS => 'static pass',
            self::STATIC_ASSERTION_FAILURE => 'static assertion failure',
            default => $this->status,
        };
    }
}