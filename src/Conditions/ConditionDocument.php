<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

/** Shared leaf-document checks for driver implementations. */
final class ConditionDocument
{
    /**
     * @param array<mixed> $document
     * @param list<string> $allowed
     */
    public static function properties(array $document, array $allowed): void
    {
        foreach (array_keys($document) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new ConditionDefinitionException('unknown_condition_property', 'Unsupported condition property.', '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key));
            }
        }
    }
}
