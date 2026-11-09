<?php

declare(strict_types=1);

namespace Tools\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Interdit les flottants (type, littéral, conversion) dans les couches Domain des modules :
 * tout calcul monétaire ou de quantité passe par BigDecimal ou des entiers (D-013).
 *
 * @implements Rule<Node>
 */
final class NoFloatInDomainRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';

        if (preg_match('/^App\\\\Modules\\\\[^\\\\]+\\\\Domain(\\\\|$)/', $namespace) !== 1) {
            return [];
        }

        $message = match (true) {
            $node instanceof Node\Scalar\Float_ => 'Littéral flottant interdit dans le domaine ; utilisez une chaîne décimale.',
            $node instanceof Node\Expr\Cast\Double => 'Conversion (float) interdite dans le domaine.',
            $node instanceof Node\Identifier && $node->toLowerString() === 'float' => 'Type float interdit dans le domaine ; utilisez BigDecimal ou int.',
            $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array($node->name->toLowerString(), ['floatval', 'round', 'floor', 'ceil'], true) => "Fonction {$node->name->toString()}() interdite dans le domaine ; utilisez BigDecimal.",
            default => null,
        };

        return $message === null ? [] : [RuleErrorBuilder::message($message)->identifier('fds.noFloatInDomain')->build()];
    }
}
