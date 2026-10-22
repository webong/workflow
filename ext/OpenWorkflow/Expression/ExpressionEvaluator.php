<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

use Webong\WorkFlow\Contracts\FlowContext;

/**
 * Minimal jq-like expression evaluator for the OWS initial subset.
 *
 * Supported grammar:
 * - Property access: .field, .field.subfield
 * - Array iteration/projection: .items[] | {id, name}
 * - Pipe: expr1 | expr2
 * - Comparison: ==, !=, <, >, <=, >=
 * - Boolean: and, or, not
 * - Literals: strings, numbers, true, false, null
 * - Variable references: $workflow, $task, $context, $variableName
 * - Parentheses: (expr)
 * - Object construction: {key: expr, ...}
 * - Array construction: [expr, ...]
 *
 * NOT supported (throws UnsupportedExpressionException):
 * - Regex: match(), test(), capture()
 * - Arithmetic: +, -, *, /, %
 * - Functions: length, keys, map, reduce, group_by, etc.
 * - Recursion, modules, shell commands
 * - Alternative operators: //, ?
 */
final class ExpressionEvaluator
{
    /**
     * @param array<string, mixed> $workflowData
     * @param array<string, mixed> $taskData
     */
    public function __construct(
        private readonly array $workflowData = [],
        private readonly array $taskData = [],
        private readonly ?FlowContext $context = null,
    ) {}

    /**
     * Evaluate an expression against the current data context.
     *
     * @return mixed
     */
    public function evaluate(string $expression): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        $parser = new ExpressionParser($expression);
        $ast = $parser->parse();

        return $this->evaluateNode($ast, $this->buildRootContext());
    }

    /**
     * Check if an expression uses only supported features (for validation).
     *
     * @return array{supported: bool, unsupportedFeatures: list<string>}
     */
    public function analyze(string $expression): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return ['supported' => true, 'unsupportedFeatures' => []];
        }

        try {
            $parser = new ExpressionParser($expression);
            $ast = $parser->parse();
            $analyzer = new ExpressionAnalyzer();
            $analyzer->analyze($ast);
            return [
                'supported' => count($analyzer->unsupportedFeatures) === 0,
                'unsupportedFeatures' => $analyzer->unsupportedFeatures,
            ];
        } catch (\Throwable $e) {
            return [
                'supported' => false,
                'unsupportedFeatures' => ['parse-error: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRootContext(): array
    {
        $ctx = [];

        // $workflow - the workflow input/document
        $ctx['$workflow'] = $this->workflowData;

        // $task - the current task's input
        $ctx['$task'] = $this->taskData;

        // $context - the FlowContext (if provided)
        if ($this->context !== null) {
            $ctx['$context'] = $this->context->all();
        }

        // Merge workflow data at root level for bare property access
        // e.g., .status reads from workflow input
        $ctx = array_merge($this->workflowData, $ctx);

        return $ctx;
    }

    /**
     * @param array<string, mixed> $context
     * @return mixed
     */
    private function evaluateNode(ExpressionNode $node, array $context): mixed
    {
        return match ($node->type) {
            ExpressionType::LITERAL => $node->value,
            ExpressionType::VARIABLE => $this->resolveVariable($node->name, $context),
            ExpressionType::PROPERTY_ACCESS => $this->evaluatePropertyAccess($node, $context),
            ExpressionType::ARRAY_PROJECTION => $this->evaluateArrayProjection($node, $context),
            ExpressionType::PIPE => $this->evaluatePipe($node, $context),
            ExpressionType::COMPARISON => $this->evaluateComparison($node, $context),
            ExpressionType::BOOLEAN => $this->evaluateBoolean($node, $context),
            ExpressionType::OBJECT_CONSTRUCTION => $this->evaluateObjectConstruction($node, $context),
            ExpressionType::ARRAY_CONSTRUCTION => $this->evaluateArrayConstruction($node, $context),
            ExpressionType::PARENTHESIZED => $this->evaluateNode($node->child, $context),
            default => throw new ExpressionEvaluationException("Unknown node type: {$node->type->value}"),
        };
    }

    private function resolveVariable(string $name, array $context): mixed
    {
        if (! isset($context[$name])) {
            throw ExpressionEvaluationException::evaluationError(
                "\${$name}",
                "Variable '{$name}' not found in context"
            );
        }
        return $context[$name];
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return mixed
     */
    private function evaluatePropertyAccess(ExpressionNode $node, array $context): mixed
    {
        $base = $node->base !== null
            ? $this->evaluateNode($node->base, $context)
            : $context;

        if (! is_array($base) && ! is_object($base)) {
            return null;
        }

        $path = $node->path; // e.g., ['field', 'subfield']
        $current = $base;

        foreach ($path as $segment) {
            if (is_array($current)) {
                if (! array_key_exists($segment, $current)) {
                    return null;
                }
                $current = $current[$segment];
            } elseif (is_object($current)) {
                if (! property_exists($current, $segment)) {
                    return null;
                }
                $current = $current->$segment;
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    private function evaluateArrayProjection(ExpressionNode $node, array $context): array
    {
        $source = $this->evaluateNode($node->base, $context);

        if (! is_array($source)) {
            throw ExpressionEvaluationException::evaluationError(
                $node->raw ?? '',
                'Array projection ([]) requires an array source'
            );
        }

        $results = [];
        foreach ($source as $item) {
            $itemContext = array_merge($context, ['$' => $item, '$item' => $item]);
            $result = $this->evaluateNode($node->projection, $itemContext);
            if (is_array($result)) {
                $results = array_merge($results, $result);
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return mixed
     */
    private function evaluatePipe(ExpressionNode $node, array $context): mixed
    {
        $left = $this->evaluateNode($node->left, $context);
        $rightContext = array_merge($context, ['$' => $left, '$it' => $left]);
        return $this->evaluateNode($node->right, $rightContext);
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return bool
     */
    private function evaluateComparison(ExpressionNode $node, array $context): bool
    {
        $left = $this->evaluateNode($node->left, $context);
        $right = $this->evaluateNode($node->right, $context);

        return match ($node->operator) {
            '==' => $this->looseEqual($left, $right),
            '!=' => ! $this->looseEqual($left, $right),
            '<' => $this->compare($left, $right) < 0,
            '>' => $this->compare($left, $right) > 0,
            '<=' => $this->compare($left, $right) <= 0,
            '>=' => $this->compare($left, $right) >= 0,
            default => throw new ExpressionEvaluationException("Unknown comparison operator: {$node->operator}"),
        };
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return bool
     */
    private function evaluateBoolean(ExpressionNode $node, array $context): bool
    {
        return match ($node->operator) {
            'and' => $this->toBool($this->evaluateNode($node->left, $context))
                && $this->toBool($this->evaluateNode($node->right, $context)),
            'or' => $this->toBool($this->evaluateNode($node->left, $context))
                || $this->toBool($this->evaluateNode($node->right, $context)),
            'not' => ! $this->toBool($this->evaluateNode($node->child, $context)),
            default => throw new ExpressionEvaluationException("Unknown boolean operator: {$node->operator}"),
        };
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function evaluateObjectConstruction(ExpressionNode $node, array $context): array
    {
        $result = [];
        foreach ($node->properties as $key => $valueNode) {
            $result[$key] = $this->evaluateNode($valueNode, $context);
        }
        return $result;
    }

    /**
     * @param ExpressionNode $node
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    private function evaluateArrayConstruction(ExpressionNode $node, array $context): array
    {
        $result = [];
        foreach ($node->elements as $elementNode) {
            $result[] = $this->evaluateNode($elementNode, $context);
        }
        return $result;
    }

    private function looseEqual(mixed $a, mixed $b): bool
    {
        if (is_null($a) || is_null($b)) {
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return (string) $a === (string) $b;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        return strcmp((string) $a, (string) $b);
    }

    private function toBool(mixed $value): bool
    {
        if (is_null($value)) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && strtolower($value) !== 'false';
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return true;
    }
}