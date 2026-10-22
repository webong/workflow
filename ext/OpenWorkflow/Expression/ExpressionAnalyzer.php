<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Static analyzer for expressions - detects unsupported features without evaluating.
 */
final class ExpressionAnalyzer
{
    /** @var list<string> */
    public array $unsupportedFeatures = [];

    public function analyze(ExpressionNode $node): void
    {
        $this->unsupportedFeatures = [];
        $this->analyzeNode($node);
    }

    private function analyzeNode(ExpressionNode $node): void
    {
        match ($node->type) {
            ExpressionType::LITERAL, ExpressionType::VARIABLE => null,
            ExpressionType::PROPERTY_ACCESS => $this->analyzePropertyAccess($node),
            ExpressionType::ARRAY_PROJECTION => $this->analyzeArrayProjection($node),
            ExpressionType::PIPE => $this->analyzePipe($node),
            ExpressionType::COMPARISON => $this->analyzeComparison($node),
            ExpressionType::BOOLEAN => $this->analyzeBoolean($node),
            ExpressionType::OBJECT_CONSTRUCTION => $this->analyzeObjectConstruction($node),
            ExpressionType::ARRAY_CONSTRUCTION => $this->analyzeArrayConstruction($node),
            ExpressionType::PARENTHESIZED => $this->analyzeNode($node->child),
            default => $this->addUnsupported("unknown-node-type: {$node->type->value}"),
        };
    }

    private function analyzePropertyAccess(ExpressionNode $node): void
    {
        if ($node->base !== null) {
            $this->analyzeNode($node->base);
        }
        // Property access with [] in path is array projection
        foreach ($node->path as $segment) {
            if ($segment === '[]') {
                $this->addUnsupported('array-projection-in-property-access');
            }
        }
    }

    private function analyzeArrayProjection(ExpressionNode $node): void
    {
        if ($node->base !== null) {
            $this->analyzeNode($node->base);
        }
        if ($node->projection !== null) {
            $this->analyzeNode($node->projection);
        }
    }

    private function analyzePipe(ExpressionNode $node): void
    {
        if ($node->left !== null) {
            $this->analyzeNode($node->left);
        }
        if ($node->right !== null) {
            $this->analyzeNode($node->right);
        }
    }

    private function analyzeComparison(ExpressionNode $node): void
    {
        if ($node->left !== null) {
            $this->analyzeNode($node->left);
        }
        if ($node->right !== null) {
            $this->analyzeNode($node->right);
        }
        // Check for unsupported comparison operators
        $supported = ['==', '!=', '<', '>', '<=', '>='];
        if (! in_array($node->operator, $supported, true)) {
            $this->addUnsupported("comparison-operator: {$node->operator}");
        }
    }

    private function analyzeBoolean(ExpressionNode $node): void
    {
        if ($node->left !== null) {
            $this->analyzeNode($node->left);
        }
        if ($node->right !== null) {
            $this->analyzeNode($node->right);
        }
        if ($node->child !== null) {
            $this->analyzeNode($node->child);
        }
        $supported = ['and', 'or', 'not'];
        if (! in_array($node->operator, $supported, true)) {
            $this->addUnsupported("boolean-operator: {$node->operator}");
        }
    }

    private function analyzeObjectConstruction(ExpressionNode $node): void
    {
        foreach ($node->properties as $valueNode) {
            $this->analyzeNode($valueNode);
        }
    }

    private function analyzeArrayConstruction(ExpressionNode $node): void
    {
        foreach ($node->elements as $elementNode) {
            $this->analyzeNode($elementNode);
        }
    }

    private function addUnsupported(string $feature): void
    {
        if (! in_array($feature, $this->unsupportedFeatures, true)) {
            $this->unsupportedFeatures[] = $feature;
        }
    }
}