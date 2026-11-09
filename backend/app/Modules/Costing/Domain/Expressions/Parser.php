<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Expressions;

/**
 * Analyseur d'expressions restreint (CR-014, D-015) : nombres décimaux, variables de la liste blanche,
 * + − × ÷, parenthèses et fonctions min, max, round. Aucune autre construction n'est reconnue :
 * pas d'appel de code, de chaîne, d'accès mémoire ni de boucle — l'évaluation est un simple parcours d'arbre.
 */
final class Parser
{
    public const MAX_LENGTH = 2000;

    public const MAX_DEPTH = 32;

    public const MAX_TOKENS = 500;

    /** Fonctions autorisées et nombre d'arguments (min, max). */
    public const FUNCTIONS = ['min' => [2, 16], 'max' => [2, 16], 'round' => [2, 2]];

    /** @var list<array{type: string, value: string, pos: int}> */
    private array $tokens = [];

    private int $index = 0;

    private int $depth = 0;

    /** @param list<string> $allowedVariables */
    public function __construct(private readonly array $allowedVariables) {}

    public function parse(string $expression): Node
    {
        if (strlen($expression) > self::MAX_LENGTH) {
            throw new ExpressionError('La formule est trop longue ('.self::MAX_LENGTH.' caractères au plus).');
        }

        $this->tokens = $this->tokenize($expression);
        $this->index = 0;
        $this->depth = 0;

        if ($this->tokens === []) {
            throw new ExpressionError('La formule est vide.');
        }

        $node = $this->expression();

        $leftover = $this->current();

        if ($leftover !== null) {
            throw new ExpressionError("Élément inattendu « {$leftover['value']} ».", $leftover['pos']);
        }

        return $node;
    }

    /** @return list<array{type: string, value: string, pos: int}> */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)/', $source, $m, 0, $i);
                $value = $m[0] ?? '';
                $normalized = str_starts_with($value, '.') ? '0'.$value : $value;

                if (preg_match('/^\d{1,18}(\.\d{1,10})?$/', $normalized) !== 1) {
                    throw new ExpressionError("Nombre invalide « {$value} ».", $i);
                }

                $tokens[] = ['type' => 'number', 'value' => $normalized, 'pos' => $i];
                $i += strlen($value);
            } elseif (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $i);
                $name = $m[0] ?? '';
                $tokens[] = ['type' => 'identifier', 'value' => $name, 'pos' => $i];
                $i += strlen($name);
            } elseif (str_contains('+-*/(),', $char)) {
                $tokens[] = ['type' => 'symbol', 'value' => $char, 'pos' => $i];
                $i++;
            } else {
                throw new ExpressionError("Caractère non autorisé « {$char} ».", $i);
            }

            if (count($tokens) > self::MAX_TOKENS) {
                throw new ExpressionError('La formule est trop complexe.');
            }
        }

        return $tokens;
    }

    private function expression(): Node
    {
        $this->enter();
        $node = $this->term();

        while ($this->peekSymbol('+') || $this->peekSymbol('-')) {
            $operator = $this->tokens[$this->index++]['value'];
            $node = Node::binary($operator, $node, $this->term());
        }

        $this->depth--;

        return $node;
    }

    private function term(): Node
    {
        $node = $this->unary();

        while ($this->peekSymbol('*') || $this->peekSymbol('/')) {
            $operator = $this->tokens[$this->index++]['value'];
            $node = Node::binary($operator, $node, $this->unary());
        }

        return $node;
    }

    private function unary(): Node
    {
        if ($this->peekSymbol('-')) {
            $this->index++;
            $this->enter();
            $node = Node::negate($this->unary());
            $this->depth--;

            return $node;
        }

        if ($this->peekSymbol('+')) {
            $this->index++;

            return $this->unary();
        }

        return $this->primary();
    }

    private function primary(): Node
    {
        $token = $this->tokens[$this->index] ?? throw new ExpressionError('La formule est incomplète.');
        $this->index++;

        if ($token['type'] === 'number') {
            return Node::number($token['value']);
        }

        if ($token['type'] === 'identifier') {
            $name = $token['value'];

            if ($this->peekSymbol('(')) {
                return $this->call($name, $token['pos']);
            }

            if (! in_array($name, $this->allowedVariables, true)) {
                throw new ExpressionError("Variable inconnue « {$name} ».", $token['pos']);
            }

            return Node::variable($name);
        }

        if ($token['value'] === '(') {
            $node = $this->expression();
            $this->expect(')');

            return $node;
        }

        throw new ExpressionError("Élément inattendu « {$token['value']} ».", $token['pos']);
    }

    private function call(string $name, int $position): Node
    {
        $lower = strtolower($name);

        if (! isset(self::FUNCTIONS[$lower])) {
            throw new ExpressionError("Fonction non autorisée « {$name} ».", $position);
        }

        $this->expect('(');
        $arguments = [$this->expression()];

        while ($this->peekSymbol(',')) {
            $this->index++;
            $arguments[] = $this->expression();
        }

        $this->expect(')');
        [$min, $max] = self::FUNCTIONS[$lower];

        if (count($arguments) < $min || count($arguments) > $max) {
            throw new ExpressionError("Nombre d'arguments incorrect pour {$lower}.", $position);
        }

        if ($lower === 'round' && ($arguments[1]->type !== 'number' || preg_match('/^[0-8]$/', $arguments[1]->value) !== 1)) {
            throw new ExpressionError('round attend un nombre de décimales entier entre 0 et 8.', $position);
        }

        return Node::call($lower, $arguments);
    }

    private function expect(string $symbol): void
    {
        if (! $this->peekSymbol($symbol)) {
            $token = $this->tokens[$this->index] ?? null;
            throw new ExpressionError("« {$symbol} » attendu.", $token['pos'] ?? null);
        }

        $this->index++;
    }

    private function peekSymbol(string $symbol): bool
    {
        $token = $this->tokens[$this->index] ?? null;

        return $token !== null && $token['type'] === 'symbol' && $token['value'] === $symbol;
    }

    /** @return array{type: string, value: string, pos: int}|null */
    private function current(): ?array
    {
        return $this->tokens[$this->index] ?? null;
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new ExpressionError('La formule est trop imbriquée.');
        }
    }
}
