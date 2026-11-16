<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Recursive descent parser for the minimal jq-like expression subset.
 */
final class ExpressionParser
{
    private string $input;
    private int $position = 0;
    private int $length;

    public function __construct(string $expression)
    {
        $this->input = $expression;
        $this->length = strlen($expression);
    }

    public function parse(): ExpressionNode
    {
        $node = $this->parsePipeline();
        $this->skipWhitespace();
        if ($this->position < $this->length) {
            throw ExpressionEvaluationException::invalidSyntax(
                $this->input,
                "Unexpected character '{$this->input[$this->position]}' at position {$this->position}"
            );
        }
        return $node;
    }

    private function parsePipeline(): ExpressionNode
    {
        $left = $this->parseBoolean();

        $this->skipWhitespace();
        while ($this->match('|')) {
            $this->skipWhitespace();
            $right = $this->parseBoolean();
            $left = new ExpressionNode(
                type: ExpressionType::PIPE,
                left: $left,
                right: $right,
                raw: $this->input,
            );
            $this->skipWhitespace();
        }

        return $left;
    }

    private function parseBoolean(): ExpressionNode
    {
        $left = $this->parseComparison();

        $this->skipWhitespace();
        while (true) {
            if ($this->matchKeyword('and')) {
                $this->skipWhitespace();
                $right = $this->parseComparison();
                $left = new ExpressionNode(
                    type: ExpressionType::BOOLEAN,
                    left: $left,
                    right: $right,
                    operator: 'and',
                    raw: $this->input,
                );
            } elseif ($this->matchKeyword('or')) {
                $this->skipWhitespace();
                $right = $this->parseComparison();
                $left = new ExpressionNode(
                    type: ExpressionType::BOOLEAN,
                    left: $left,
                    right: $right,
                    operator: 'or',
                    raw: $this->input,
                );
            } elseif ($this->matchKeyword('not')) {
                $this->skipWhitespace();
                $child = $this->parseComparison();
                $left = new ExpressionNode(
                    type: ExpressionType::BOOLEAN,
                    child: $child,
                    operator: 'not',
                    raw: $this->input,
                );
            } else {
                break;
            }
            $this->skipWhitespace();
        }

        return $left;
    }

    private function parseComparison(): ExpressionNode
    {
        $left = $this->parsePrimary();

        $this->skipWhitespace();
        $operators = ['==', '!=', '<=', '>=', '<', '>'];
        foreach ($operators as $op) {
            if ($this->match($op)) {
                $this->skipWhitespace();
                $right = $this->parsePrimary();
                return new ExpressionNode(
                    type: ExpressionType::COMPARISON,
                    left: $left,
                    right: $right,
                    operator: $op,
                    raw: $this->input,
                );
            }
        }

        return $left;
    }

    private function parsePrimary(): ExpressionNode
    {
        $this->skipWhitespace();

        // Parenthesized expression
        if ($this->match('(')) {
            $this->skipWhitespace();
            $child = $this->parsePipeline();
            $this->skipWhitespace();
            if (! $this->match(')')) {
                throw ExpressionEvaluationException::invalidSyntax(
                    $this->input,
                    "Expected ')' at position {$this->position}"
                );
            }
            return new ExpressionNode(
                type: ExpressionType::PARENTHESIZED,
                child: $child,
                raw: $this->input,
            );
        }

        // Object construction: {key: expr, ...}
        if ($this->match('{')) {
            return $this->parseObjectConstruction();
        }

        // Array construction: [expr, ...]
        if ($this->match('[')) {
            return $this->parseArrayConstruction();
        }

        // Variable: $name
        if ($this->match('$')) {
            $name = $this->parseIdentifier();
            if ($name === '') {
                throw ExpressionEvaluationException::invalidSyntax(
                    $this->input,
                    "Expected variable name after '\$' at position {$this->position}"
                );
            }
            return new ExpressionNode(
                type: ExpressionType::VARIABLE,
                name: $name,
                raw: $this->input,
            );
        }

        // Property access: .field or .field.subfield or .field[]
        if ($this->match('.')) {
            return $this->parsePropertyAccess();
        }

        // Literal: string, number, true, false, null
        return $this->parseLiteral();
    }

    private function parsePropertyAccess(): ExpressionNode
    {
        $path = [];
        $base = null;

        // Check if this starts with a variable reference like $task.field
        // But we handle that in parsePrimary - here we're after a dot

        while (true) {
            $this->skipWhitespace();
            if ($this->position >= $this->length) {
                break;
            }

            $char = $this->input[$this->position];

            // Array projection: []
            if ($char === '[') {
                $this->position++; // consume [
                $this->skipWhitespace();
                if (! $this->match(']')) {
                    throw ExpressionEvaluationException::invalidSyntax(
                        $this->input,
                        "Expected ']' after '[' at position {$this->position}"
                    );
                }
                // This is an array projection marker - we'll handle it in the caller
                // by returning a special node type. For now, mark it in path.
                $path[] = '[]';
                continue;
            }

            // Property name
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*/', substr($this->input, $this->position), $matches)) {
                $path[] = $matches[0];
                $this->position += strlen($matches[0]);
                continue;
            }

            break;
        }

        if (empty($path)) {
            throw ExpressionEvaluationException::invalidSyntax(
                $this->input,
                "Expected property name after '.' at position {$this->position}"
            );
        }

        // Check if the last element is array projection marker
        $hasArrayProjection = false;
        $cleanPath = [];
        foreach ($path as $segment) {
            if ($segment === '[]') {
                $hasArrayProjection = true;
            } else {
                $cleanPath[] = $segment;
            }
        }

        if ($hasArrayProjection) {
            // Need to parse the projection expression after |
            // For now, we'll create the array projection node
            // The projection expression comes after | in the pipeline
            return new ExpressionNode(
                type: ExpressionType::ARRAY_PROJECTION,
                base: $base,
                path: $cleanPath,
                projection: new ExpressionNode(type: ExpressionType::LITERAL, value: null), // placeholder
                raw: $this->input,
            );
        }

        return new ExpressionNode(
            type: ExpressionType::PROPERTY_ACCESS,
            base: $base,
            path: $cleanPath,
            raw: $this->input,
        );
    }

    private function parseObjectConstruction(): ExpressionNode
    {
        $properties = [];

        $this->skipWhitespace();
        if ($this->peek() === '}') {
            $this->position++;
            return new ExpressionNode(
                type: ExpressionType::OBJECT_CONSTRUCTION,
                properties: [],
                raw: $this->input,
            );
        }

        while (true) {
            $this->skipWhitespace();

            // Key (can be bare identifier or quoted string)
            $key = $this->parseObjectKey();
            $this->skipWhitespace();

            if (! $this->match(':')) {
                throw ExpressionEvaluationException::invalidSyntax(
                    $this->input,
                    "Expected ':' after object key '{$key}' at position {$this->position}"
                );
            }

            $this->skipWhitespace();
            $value = $this->parsePipeline();
            $properties[$key] = $value;

            $this->skipWhitespace();
            if ($this->match(',')) {
                continue;
            }
            if ($this->match('}')) {
                break;
            }
            throw ExpressionEvaluationException::invalidSyntax(
                $this->input,
                "Expected ',' or '}' at position {$this->position}"
            );
        }

        return new ExpressionNode(
            type: ExpressionType::OBJECT_CONSTRUCTION,
            properties: $properties,
            raw: $this->input,
        );
    }

    private function parseObjectKey(): string
    {
        $this->skipWhitespace();

        // Quoted string
        if ($this->peek() === '"' || $this->peek() === "'") {
            return $this->parseString();
        }

        // Bare identifier
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*/', substr($this->input, $this->position), $matches)) {
            $this->position += strlen($matches[0]);
            return $matches[0];
        }

        throw ExpressionEvaluationException::invalidSyntax(
            $this->input,
            "Expected object key at position {$this->position}"
        );
    }

    private function parseArrayConstruction(): ExpressionNode
    {
        $elements = [];

        $this->skipWhitespace();
        if ($this->peek() === ']') {
            $this->position++;
            return new ExpressionNode(
                type: ExpressionType::ARRAY_CONSTRUCTION,
                elements: [],
                raw: $this->input,
            );
        }

        while (true) {
            $this->skipWhitespace();
            $elements[] = $this->parsePipeline();
            $this->skipWhitespace();

            if ($this->match(',')) {
                continue;
            }
            if ($this->match(']')) {
                break;
            }
            throw ExpressionEvaluationException::invalidSyntax(
                $this->input,
                "Expected ',' or ']' at position {$this->position}"
            );
        }

        return new ExpressionNode(
            type: ExpressionType::ARRAY_CONSTRUCTION,
            elements: $elements,
            raw: $this->input,
        );
    }

    private function parseLiteral(): ExpressionNode
    {
        $this->skipWhitespace();

        // String
        if ($this->peek() === '"' || $this->peek() === "'") {
            $value = $this->parseString();
            return new ExpressionNode(
                type: ExpressionType::LITERAL,
                value: $value,
                raw: $this->input,
            );
        }

        // Number
        if (preg_match('/^-?\d+(\.\d+)?/', substr($this->input, $this->position), $matches)) {
            $this->position += strlen($matches[0]);
            $value = str_contains($matches[0], '.') ? (float) $matches[0] : (int) $matches[0];
            return new ExpressionNode(
                type: ExpressionType::LITERAL,
                value: $value,
                raw: $this->input,
            );
        }

        // true, false, null
        foreach (['true', 'false', 'null'] as $literal) {
            if ($this->matchKeyword($literal)) {
                $value = match ($literal) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                };
                return new ExpressionNode(
                    type: ExpressionType::LITERAL,
                    value: $value,
                    raw: $this->input,
                );
            }
        }

        throw ExpressionEvaluationException::invalidSyntax(
            $this->input,
            "Expected literal at position {$this->position}"
        );
    }

    private function parseString(): string
    {
        $quote = $this->input[$this->position];
        $this->position++; // consume opening quote
        $result = '';

        while ($this->position < $this->length) {
            $char = $this->input[$this->position];

            if ($char === $quote) {
                $this->position++; // consume closing quote
                return $result;
            }

            if ($char === '\\' && $this->position + 1 < $this->length) {
                $this->position++;
                $next = $this->input[$this->position];
                $result .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    default => $next,
                };
                $this->position++;
                continue;
            }

            $result .= $char;
            $this->position++;
        }

        throw ExpressionEvaluationException::invalidSyntax(
            $this->input,
            "Unterminated string starting at position {$this->position}"
        );
    }

    private function parseIdentifier(): string
    {
        $this->skipWhitespace();
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*/', substr($this->input, $this->position), $matches)) {
            $this->position += strlen($matches[0]);
            return $matches[0];
        }
        return '';
    }

    private function match(string $str): bool
    {
        if (substr($this->input, $this->position, strlen($str)) === $str) {
            $this->position += strlen($str);
            return true;
        }
        return false;
    }

    private function matchKeyword(string $keyword): bool
    {
        $pos = $this->position;
        if (substr($this->input, $pos, strlen($keyword)) === $keyword) {
            // Ensure it's a word boundary
            $after = $pos + strlen($keyword);
            if ($after >= $this->length || ! preg_match('/^[a-zA-Z0-9_]/', $this->input[$after] ?? '')) {
                $this->position = $after;
                return true;
            }
        }
        return false;
    }

    private function peek(): string
    {
        return $this->input[$this->position] ?? '';
    }

    private function skipWhitespace(): void
    {
        while ($this->position < $this->length && ctype_space($this->input[$this->position])) {
            $this->position++;
        }
    }
}