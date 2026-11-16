<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Expression AST node.
 */
final readonly class ExpressionNode
{
    /**
     * @param array<string, ExpressionNode>|null $properties
     * @param list<ExpressionNode>|null $elements
     */
    public function __construct(
        public ExpressionType $type,
        public mixed $value = null,           // For LITERAL
        public ?string $name = null,          // For VARIABLE
        public ?ExpressionNode $base = null,  // For PROPERTY_ACCESS, ARRAY_PROJECTION, PIPE (left)
        public array $path = [],              // For PROPERTY_ACCESS
        public ?ExpressionNode $projection = null, // For ARRAY_PROJECTION
        public ?ExpressionNode $left = null,  // For PIPE (right), COMPARISON, BOOLEAN
        public ?ExpressionNode $right = null, // For COMPARISON, BOOLEAN
        public ?string $operator = null,      // For COMPARISON, BOOLEAN
        public ?ExpressionNode $child = null, // For BOOLEAN (not), PARENTHESIZED
        public array $properties = [],        // For OBJECT_CONSTRUCTION
        public array $elements = [],          // For ARRAY_CONSTRUCTION
        public ?string $raw = null,           // Original expression string (for errors)
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'value' => $this->value,
            'name' => $this->name,
            'base' => $this->base?->toArray(),
            'path' => $this->path,
            'projection' => $this->projection?->toArray(),
            'left' => $this->left?->toArray(),
            'right' => $this->right?->toArray(),
            'operator' => $this->operator,
            'child' => $this->child?->toArray(),
            'properties' => array_map(
                static fn (ExpressionNode $n): array => $n->toArray(),
                $this->properties,
            ),
            'elements' => array_map(
                static fn (ExpressionNode $n): array => $n->toArray(),
                $this->elements,
            ),
            'raw' => $this->raw,
        ], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '');
    }
}