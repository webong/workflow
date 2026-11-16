<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\ValueObjects;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parsed Open Workflow Specification document.
 * Supports both JSON and YAML input.
 */
final readonly class OwsDocument
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return is_array($this->data['document'] ?? null) ? $this->data['document'] : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function do(): array
    {
        $do = $this->data['do'] ?? [];
        return is_array($do) ? self::normalizeTaskItems($do) : [];
    }

    /**
     * Normalize an OWS task list: each item is an object keyed by task name.
     *
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    public static function normalizeTaskItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $taskItem) {
            if (! is_array($taskItem)) {
                continue;
            }
            // OWS format: each task item is an object with task name as key
            // e.g., [ 'taskName' => [ 'call' => '...', 'with' => ... ] ]
            foreach ($taskItem as $taskName => $taskDef) {
                if (is_array($taskDef)) {
                    $taskDef['name'] = $taskName;
                    $normalized[] = $taskDef;
                }
            }
        }

        return $normalized;
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? '1.0.3');
    }

    public function namespace(): string
    {
        return (string) ($this->document()['namespace'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->document()['name'] ?? '');
    }

    public function semver(): string
    {
        return (string) ($this->document()['version'] ?? '1.0.0');
    }

    public function compositeKey(): string
    {
        $ns = $this->namespace();
        $name = $this->name();
        $ver = $this->semver();
        if ($ns === '' || $name === '') {
            return '';
        }
        return "{$ns}/{$name}@{$ver}";
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return is_array($this->data['input'] ?? null) ? $this->data['input'] : [];
    }

    /** @return array<string, mixed> */
    public function output(): array
    {
        return is_array($this->data['output'] ?? null) ? $this->data['output'] : [];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return is_array($this->data['metadata'] ?? null) ? $this->data['metadata'] : [];
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON must decode to an object');
        }

        return new self($data);
    }

    public static function fromYaml(string $yaml): self
    {
        if (! class_exists(Yaml::class)) {
            throw new InvalidArgumentException(
                'YAML OWS documents require symfony/yaml; install it or supply JSON.',
            );
        }

        $data = Yaml::parse($yaml, Yaml::PARSE_DATETIME);

        if (! is_array($data)) {
            throw new InvalidArgumentException('YAML must parse to a mapping');
        }

        return new self($data);
    }

    public static function fromString(string $content): self
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Empty document');
        }

        // Heuristic: JSON starts with { or [, YAML typically doesn't
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            return self::fromJson($content);
        }

        return self::fromYaml($content);
    }
}
