<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Montant exact dans une devise.
 *
 * - Les opérations arithmétiques sont exactes (aucun arrondi implicite).
 * - L'arrondi à la précision de la devise est explicite (rounded) et toujours HALF_UP (D-013).
 * - Le stockage se fait en DECIMAL(19,4) : toStorage() refuse toute perte de précision.
 */
final class Money implements JsonSerializable
{
    public const STORAGE_SCALE = 4;

    private function __construct(
        private readonly BigDecimal $amount,
        private readonly Currency $currency,
    ) {}

    public static function of(BigNumber|int|string $amount, Currency $currency): self
    {
        return new self(Decimal::of($amount), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(BigDecimal::zero(), $currency);
    }

    public function amount(): BigDecimal
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multipliedBy(BigNumber|int|string $factor): self
    {
        return new self($this->amount->multipliedBy(Decimal::of($factor)), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    /** Arrondi à la précision de la devise, demi supérieur (1 250,5 XOF → 1 251). */
    public function rounded(): self
    {
        return new self($this->amount->toScale($this->currency->decimals, RoundingMode::HalfUp), $this->currency);
    }

    public function isRounded(): bool
    {
        return $this->amount->isEqualTo($this->rounded()->amount);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amount->isEqualTo($other->amount);
    }

    /** Valeur pour une colonne DECIMAL(19,4). Lève une exception si la précision serait perdue. */
    public function toStorage(): string
    {
        if ($this->amount->getScale() > self::STORAGE_SCALE
            && ! $this->amount->isEqualTo($this->amount->toScale(self::STORAGE_SCALE, RoundingMode::Down))) {
            throw new InvalidArgumentException(
                "Le montant {$this->amount} dépasse la précision de stockage ; arrondissez-le explicitement."
            );
        }

        return (string) $this->amount->toScale(self::STORAGE_SCALE, RoundingMode::Unnecessary);
    }

    /**
     * Représentation API : chaîne décimale à la précision de la devise si le montant est arrondi,
     * sinon valeur exacte sans zéros superflus (coûts unitaires, calculs intermédiaires).
     */
    public function toDecimalString(): string
    {
        if ($this->amount->getScale() <= $this->currency->decimals || $this->isRounded()) {
            return (string) $this->amount->toScale($this->currency->decimals, RoundingMode::Unnecessary);
        }

        return (string) $this->amount->strippedOfTrailingZeros();
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimalString(), 'currency' => $this->currency->code];
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(
                "Opération impossible entre {$this->currency->code} et {$other->currency->code} sans conversion explicite."
            );
        }
    }
}
