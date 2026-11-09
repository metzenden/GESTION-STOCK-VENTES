<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Expressions;

/**
 * Nœud de l'arbre syntaxique. Types : number (value), variable (name), negate (operand),
 * binary (operator, left, right), call (name, arguments).
 */
final readonly class Node
{
    /** @param list<Node> $arguments */
    private function __construct(
        public string $type,
        public string $value = '',
        public ?Node $left = null,
        public ?Node $right = null,
        public array $arguments = [],
    ) {}

    public static function number(string $value): self
    {
        return new self('number', $value);
    }

    public static function variable(string $name): self
    {
        return new self('variable', $name);
    }

    public static function negate(Node $operand): self
    {
        return new self('negate', '', $operand);
    }

    public static function binary(string $operator, Node $left, Node $right): self
    {
        return new self('binary', $operator, $left, $right);
    }

    /** @param list<Node> $arguments */
    public static function call(string $name, array $arguments): self
    {
        return new self('call', $name, null, null, $arguments);
    }

    /** @return list<string> variables utilisées, sans doublon */
    public function variables(): array
    {
        $names = match ($this->type) {
            'variable' => [$this->value],
            'negate' => $this->left?->variables() ?? [],
            'binary' => array_merge($this->left?->variables() ?? [], $this->right?->variables() ?? []),
            'call' => array_merge(...array_map(fn (Node $a) => $a->variables(), $this->arguments ?: [self::number('0')])),
            default => [],
        };

        return array_values(array_unique($names));
    }
}
