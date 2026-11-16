<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Types of expression AST nodes.
 */
enum ExpressionType: string
{
    case LITERAL = 'literal';
    case VARIABLE = 'variable';
    case PROPERTY_ACCESS = 'property_access';
    case ARRAY_PROJECTION = 'array_projection';
    case PIPE = 'pipe';
    case COMPARISON = 'comparison';
    case BOOLEAN = 'boolean';
    case OBJECT_CONSTRUCTION = 'object_construction';
    case ARRAY_CONSTRUCTION = 'array_construction';
    case PARENTHESIZED = 'parenthesized';
}