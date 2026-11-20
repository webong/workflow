<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Webong\WorkFlow\Rules\RuleSet;

/** Structural authoring schema. Compilation additionally checks rules, expressions and global limits. */
final class ConditionSchema
{
    /** @return array<string, mixed> */
    public static function document(): array
    {
        $ref = ['$ref' => '#/definitions/condition'];
        $variants = [];
        foreach (['all', 'any', 'not', 'rules', 'jq'] as $variant) {
            $properties = ['version' => ['enum' => ['v1']]];
            $required = [$variant];
            if (in_array($variant, ['all', 'any'], true)) {
                $properties[$variant] = ['type' => 'array', 'minItems' => 1, 'maxItems' => ConditionCompiler::MAX_CHILDREN, 'items' => $ref];
            } elseif ($variant === 'not') {
                $properties['not'] = $ref;
            } elseif ($variant === 'rules') {
                $properties['syntax'] = ['enum' => ['rules']];
                $properties['rules'] = RuleSet::schema();
            } else {
                $required = ['syntax', 'expression'];
                $properties['syntax'] = ['enum' => ['jq']];
                $properties['expression'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => ConditionCompiler::MAX_EXPRESSION_BYTES];
            }
            $variants[] = ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        }
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'WorkFlow condition v1',
            'description' => 'Structural shape only. Use condition authoring validation for rule parameters, jq support, byte limits and global tree limits. Predicates still require runtime data to produce a boolean.',
            '$ref' => '#/definitions/condition',
            'definitions' => ['condition' => ['oneOf' => $variants]],
            'x-max-depth' => ConditionCompiler::MAX_DEPTH,
            'x-max-nodes' => ConditionCompiler::MAX_NODES,
        ];
    }
}
