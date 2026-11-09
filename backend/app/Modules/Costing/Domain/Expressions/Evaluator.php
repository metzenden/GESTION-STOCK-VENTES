<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Expressions;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Évaluation exacte (BigDecimal) d'un arbre produit par Parser ; divisions à 12 décimales, demi supérieur. */
final class Evaluator
{
    public const DIVISION_SCALE = 12;

    /** @param array<string, BigDecimal> $variables */
    public function evaluate(Node $node, array $variables): BigDecimal
    {
        return match ($node->type) {
            'number' => BigDecimal::of($node->value),
            'variable' => $variables[$node->value] ?? throw new ExpressionError("Valeur manquante pour « {$node->value} »."),
            'negate' => $this->evaluate(self::child($node->left), $variables)->negated(),
            'binary' => $this->binary($node, $variables),
            'call' => $this->call($node, $variables),
            default => throw new ExpressionError('Nœud inconnu.'),
        };
    }

    /** @param array<string, BigDecimal> $variables */
    private function binary(Node $node, array $variables): BigDecimal
    {
        $left = $this->evaluate(self::child($node->left), $variables);
        $right = $this->evaluate(self::child($node->right), $variables);

        return match ($node->value) {
            '+' => $left->plus($right),
            '-' => $left->minus($right),
            '*' => $left->multipliedBy($right),
            '/' => $right->isZero()
                ? throw new ExpressionError('Division par zéro.')
                : $left->dividedBy($right, self::DIVISION_SCALE, RoundingMode::HalfUp),
            default => throw new ExpressionError("Opérateur inconnu « {$node->value} »."),
        };
    }

    /** @param array<string, BigDecimal> $variables */
    private function call(Node $node, array $variables): BigDecimal
    {
        $values = array_map(fn (Node $a) => $this->evaluate($a, $variables), $node->arguments);

        return match ($node->value) {
            'min' => BigDecimal::min(...$values),
            'max' => BigDecimal::max(...$values),
            'round' => $values[0]->toScale(max(0, (int) $node->arguments[1]->value), RoundingMode::HalfUp),
            default => throw new ExpressionError("Fonction inconnue « {$node->value} »."),
        };
    }

    private static function child(?Node $node): Node
    {
        return $node ?? throw new ExpressionError('Formule incomplète.');
    }
}
