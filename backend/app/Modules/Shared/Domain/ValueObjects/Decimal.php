<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use InvalidArgumentException;

/**
 * Conversion stricte vers BigDecimal. Les flottants sont interdits dans tout calcul
 * monétaire (D-013) : seules les chaînes décimales et les entiers sont acceptés.
 */
final class Decimal
{
    private const PATTERN = '/^-?\d{1,18}(\.\d{1,10})?$/';

    public static function of(BigNumber|int|string $value): BigDecimal
    {
        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }

        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        $value = trim($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Nombre décimal invalide : « {$value} ».");
        }

        return BigDecimal::of($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, trim($value)) === 1;
    }
}
