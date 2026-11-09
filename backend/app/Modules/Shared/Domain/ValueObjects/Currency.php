<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Devise ISO 4217 et sa précision d'affichage (XOF = 0 décimale).
 */
final class Currency
{
    private function __construct(
        public readonly string $code,
        /** @var int<0, 4> */
        public readonly int $decimals,
    ) {}

    public static function of(string $code, int $decimals): self
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("Code devise invalide : {$code}.");
        }

        if ($decimals < 0 || $decimals > Money::STORAGE_SCALE) {
            throw new InvalidArgumentException("Précision de devise invalide : {$decimals}.");
        }

        return new self($code, $decimals);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
