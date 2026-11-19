<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Rules;

use InvalidArgumentException;

/**
 * Portable, bounded Laravel-shaped data matching rules.
 *
 * The JSON representation is the canonical contract. Only a deliberately
 * bounded rule subset is accepted because remote subscribers must never be
 * able to resolve custom rules or trigger database, filesystem, or network IO.
 */
final readonly class RuleSet
{
    public const VERSION = 'v1';

    private const MAX_FIELDS = 64;

    private const MAX_FIELD_LENGTH = 512;

    private const MAX_RULES_PER_FIELD = 16;

    private const MAX_RULE_LENGTH = 1024;

    /** @var array<string, true> */
    private const ALLOWED_RULES = [
        'accepted' => true,
        'array' => true,
        'bail' => true,
        'between' => true,
        'boolean' => true,
        'declined' => true,
        'ends_with' => true,
        'filled' => true,
        'in' => true,
        'integer' => true,
        'list' => true,
        'max' => true,
        'min' => true,
        'not_in' => true,
        'nullable' => true,
        'numeric' => true,
        'present' => true,
        'required' => true,
        'size' => true,
        'starts_with' => true,
        'string' => true,
    ];

    /** @param array<string, list<string>> $rules */
    private function __construct(
        public array $rules,
        public string $version = self::VERSION,
    ) {
    }

    /** @param array<mixed> $rules */
    public static function make(array $rules): self
    {
        return new self(self::normalizeRules($rules));
    }

    public static function any(): self
    {
        return new self([]);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (array_diff(array_keys($payload), ['version', 'rules']) !== []) {
            throw new InvalidArgumentException('Match rules contain unsupported properties.');
        }

        $version = $payload['version'] ?? null;
        $rules = $payload['rules'] ?? null;

        if ($version !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported match-rules version.');
        }

        if (! is_array($rules)) {
            throw new InvalidArgumentException('Match rules must contain a rules object.');
        }

        return new self(self::normalizeRules($rules), self::VERSION);
    }

    public function matchesEverything(): bool
    {
        return $this->rules === [];
    }

    /** @return array{version: string, rules: array<string, list<string>>} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'rules' => $this->rules,
        ];
    }

    public static function normalizeFieldName(string $field): string
    {
        return self::normalizeField($field);
    }

    /**
     * @param array<mixed> $rules
     * @return array<string, list<string>>
     */
    private static function normalizeRules(array $rules): array
    {
        if (count($rules) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('Match rules contain too many fields.');
        }

        $normalized = [];

        foreach ($rules as $field => $fieldRules) {
            if (! is_string($field)) {
                throw new InvalidArgumentException('Match-rule field names must be strings.');
            }

            $field = self::normalizeField($field);
            $items = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_RULES_PER_FIELD) {
                throw new InvalidArgumentException("Match-rule field [{$field}] has an invalid rule list.");
            }

            if (array_key_exists($field, $normalized)) {
                throw new InvalidArgumentException("Match-rule field [{$field}] is defined more than once after normalization.");
            }

            $normalized[$field] = array_map(
                static function (mixed $rule) use ($field): string {
                    if (! is_string($rule) || $rule === '' || strlen($rule) > self::MAX_RULE_LENGTH) {
                        throw new InvalidArgumentException("Match-rule field [{$field}] contains an invalid rule.");
                    }

                    $parts = explode(':', $rule, 2);
                    $rawName = $parts[0];
                    $parameters = $parts[1] ?? null;
                    $name = strtolower($rawName);
                    if (! isset(self::ALLOWED_RULES[$name])) {
                        throw new InvalidArgumentException("Match rule [{$name}] is not allowed.");
                    }

                    self::validateParameters($field, $name, $parameters);

                    return $parameters === null ? $name : $name.':'.$parameters;
                },
                $items,
            );
        }

        return $normalized;
    }

    private static function validateParameters(string $field, string $name, ?string $parameters): void
    {
        $valid = match ($name) {
            'between' => self::validRange($parameters),
            'max', 'min', 'size' => $parameters !== null && self::boundedDecimal($parameters),
            'array' => $parameters === null || self::nonEmptyList($parameters),
            'ends_with', 'in', 'not_in', 'starts_with' => self::nonEmptyList($parameters),
            default => $parameters === null,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Match-rule field [{$field}] has invalid [{$name}] parameters.");
        }
    }

    private static function validRange(?string $parameters): bool
    {
        if ($parameters === null) {
            return false;
        }

        $bounds = explode(',', $parameters);

        return count($bounds) === 2
            && self::boundedDecimal($bounds[0])
            && self::boundedDecimal($bounds[1])
            && (float) $bounds[0] <= (float) $bounds[1];
    }

    private static function boundedDecimal(string $value): bool
    {
        return strlen($value) <= 32
            && preg_match('/\A[+-]?\d+(?:\.\d+)?\z/D', $value) === 1
            && is_finite((float) $value);
    }

    private static function nonEmptyList(?string $parameters): bool
    {
        return $parameters !== null && $parameters !== ''
            && ! in_array('', str_getcsv($parameters, escape: '\\'), true);
    }

    private static function normalizeField(string $field): string
    {
        $field = trim($field);
        if ($field === '' || strlen($field) > self::MAX_FIELD_LENGTH) {
            throw new InvalidArgumentException('Match-rule field name is empty or too long.');
        }
        foreach (explode('.', $field) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9_*\-]+$/D', $segment) !== 1) {
                throw new InvalidArgumentException("Match-rule field [{$field}] is invalid.");
            }
        }
        return $field;
    }
}
