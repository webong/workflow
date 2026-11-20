<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Webong\WorkFlow\Contracts\ConditionDriver;

/** Structural authoring schema. Compilation additionally checks rules, expressions and global limits. */
final class ConditionSchema
{
    /**
     * @param iterable<ConditionDriver> $drivers
     * @return array<string, mixed>
     */
    public static function document(iterable $drivers = []): array
    {
        $ref = ['$ref' => '#/definitions/condition'];
        $variants = [];
        foreach (['all', 'any', 'not'] as $variant) {
            $properties = ['version' => ['enum' => ['v1']]];
            $required = [$variant];
            if (in_array($variant, ['all', 'any'], true)) {
                $properties[$variant] = ['type' => 'array', 'minItems' => 1, 'maxItems' => ConditionCompiler::MAX_CHILDREN, 'items' => $ref];
            } elseif ($variant === 'not') {
                $properties['not'] = $ref;
            }

            $variants[] = ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        }
        $syntaxes = [];
        foreach ($drivers as $driver) {
            $schema = $driver->schema();
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $properties['version'] = ['enum' => ['v1']];
            $schema['properties'] = $properties;
            $variants[] = $schema;
            $syntaxes[] = $driver->syntax();
        }
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'WorkFlow condition v1',
            'description' => 'Structural shape only. Use condition authoring validation for rule parameters, jq support, byte limits and global tree limits. Predicates still require runtime data to produce a boolean.',
            '$ref' => '#/definitions/condition',
            'definitions' => ['condition' => ['oneOf' => $variants]],
            'x-condition-syntaxes' => $syntaxes,
            'x-max-depth' => ConditionCompiler::MAX_DEPTH,
            'x-max-nodes' => ConditionCompiler::MAX_NODES,
        ];
    }
}
