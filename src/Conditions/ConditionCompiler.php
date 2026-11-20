<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use InvalidArgumentException;
use Throwable;
use Webong\WorkFlow\Contracts\ConditionDriver;

/** Compile the whole bounded document before any predicate can run. */
final readonly class ConditionCompiler
{
    public const MAX_DEPTH = 32;
    public const MAX_NODES = 256;
    public const MAX_CHILDREN = 64;

    /** @var array<string, ConditionDriver> */
    private array $drivers;

    /** @param iterable<ConditionDriver> $drivers Installed engines must be explicitly enabled. */
    public function __construct(iterable $drivers = [])
    {
        $registered = [];
        foreach ($drivers as $driver) {
            if (! $driver instanceof ConditionDriver) {
                throw new InvalidArgumentException('Condition drivers must implement ConditionDriver.');
            }
            $syntax = $driver->syntax();
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $syntax) !== 1 || in_array($syntax, ['all', 'any', 'not'], true)) {
                throw new InvalidArgumentException('Invalid or reserved condition driver syntax.');
            }
            if (isset($registered[$syntax])) {
                throw new InvalidArgumentException('Duplicate condition driver syntax: '.$syntax);
            }
            $registered[$syntax] = $driver;
        }
        $this->drivers = $registered;
    }

    /** @return array<string, mixed> Only explicitly enabled drivers contribute leaf schemas. */
    public function schema(): array
    {
        return ConditionSchema::document(array_values($this->drivers));
    }

    /** @param array<mixed> $document */
    public function compile(array $document): CompiledCondition
    {
        $remaining = self::MAX_NODES;
        return $this->node($document, 0, $remaining, '');
    }

    /** @param array<mixed> $document */
    private function node(array $document, int $depth, int &$remaining, string $path): CompiledCondition
    {
        if ($depth > self::MAX_DEPTH || --$remaining < 0) {
            throw new ConditionDefinitionException('condition_limit_exceeded', 'Condition tree exceeds its limits.', $path);
        }
        if (array_key_exists('version', $document) && $document['version'] !== 'v1') {
            throw new ConditionDefinitionException('invalid_condition_version', 'Unsupported condition version.', $path.'/version');
        }
        $operators = array_values(array_intersect(['all', 'any', 'not'], array_keys($document)));
        if ($operators !== []) {
            if (count($operators) !== 1) {
                throw new ConditionDefinitionException('invalid_condition_operator', 'A condition must contain exactly one operator.', $path);
            }
            $operator = $operators[0];
            try {
                ConditionDocument::properties($document, ['version', $operator]);
            } catch (ConditionDefinitionException $error) {
                throw new ConditionDefinitionException($error->diagnosticCode, $error->getMessage(), $path.$error->path);
            }
            $items = $operator === 'not' ? [$document[$operator]] : $document[$operator];
            if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_CHILDREN) {
                throw new ConditionDefinitionException('invalid_condition_children', 'Condition children must be a non-empty bounded list.', $path.'/'.$operator);
            }
            $children = [];
            foreach ($items as $index => $item) {
                $childPath = $path.'/'.$operator.($operator === 'not' ? '' : '/'.$index);
                if (! is_array($item)) {
                    throw new ConditionDefinitionException('condition_not_object', 'Condition child must be a document.', $childPath);
                }
                $children[] = $this->node($item, $depth + 1, $remaining, $childPath);
            }
            return new CompiledCondition(static function (mixed $input) use ($operator, $children): ConditionResult {
                foreach ($children as $child) {
                    $result = $child->evaluate($input);
                    if ($result->isError()) { return $result; }
                    if ($operator === 'not') { return ConditionResult::decided(! $result->matched()); }
                    if ($operator === 'all' && ! $result->matched()) { return $result; }
                    if ($operator === 'any' && $result->matched()) { return $result; }
                }
                return ConditionResult::decided($operator === 'all');
            });
        }
        $syntax = array_key_exists('syntax', $document) ? $document['syntax'] : (array_key_exists('rules', $document) ? 'rules' : null);
        if (! is_string($syntax) || $syntax === '') {
            throw new ConditionDefinitionException('invalid_condition_syntax', 'A condition leaf requires a syntax.', $path.'/syntax');
        }
        $driver = $this->drivers[$syntax] ?? null;
        if ($driver === null) {
            throw new ConditionDefinitionException('condition_driver_unavailable', 'No condition driver is registered for this syntax.', $path.(array_key_exists('syntax', $document) ? '/syntax' : '/rules'));
        }
        try {
            return $driver->compile($document);
        } catch (ConditionDefinitionException $error) {
            throw new ConditionDefinitionException($error->diagnosticCode, $error->getMessage(), $path.$error->path);
        } catch (Throwable) {
            throw new ConditionDefinitionException('condition_driver_failure', 'The condition driver could not compile this leaf.', $path);
        }
    }
}
