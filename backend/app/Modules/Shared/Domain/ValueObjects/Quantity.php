<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/**
 * Quantité entière exprimée dans l'unité de base de la variante (la pièce), D-007.
 * Signée : les mouvements de stock utilisent des quantités négatives pour les sorties.
 */
final class Quantity implements JsonSerializable
{
    private function __construct(public readonly int $value) {}

    public static function of(int $value): self
    {
        return new self($value);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Convertit une quantité saisie dans un conditionnement (2 cartons × 6 = 12 pièces). */
    public static function fromUnits(int $units, int $factor): self
    {
        if ($factor < 1) {
            throw new InvalidArgumentException("Facteur de conditionnement invalide : {$factor}.");
        }

        if ($units !== 0 && abs($units) > intdiv(PHP_INT_MAX, $factor)) {
            throw new OverflowException('Quantité trop grande.');
        }

        return new self($units * $factor);
    }

    public function plus(self $other): self
    {
        return new self($this->checkedAdd($this->value, $other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->checkedAdd($this->value, -$other->value));
    }

    public function negated(): self
    {
        return new self(-$this->value);
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function isPositive(): bool
    {
        return $this->value > 0;
    }

    public function isNegative(): bool
    {
        return $this->value < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    private function checkedAdd(int $a, int $b): int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new OverflowException('Dépassement de capacité sur une quantité.');
        }

        return $a + $b;
    }
}
