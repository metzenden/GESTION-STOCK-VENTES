<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Services;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Répartit un montant au prorata de poids (CBM, quantités…) de sorte que la somme des parts
 * arrondies soit exactement égale au montant d'origine (CR-008, D-014).
 *
 * Méthode du plus fort reste : chaque part est tronquée à l'échelle demandée, puis les unités
 * restantes sont attribuées aux parts ayant le plus fort reste (à égalité : ordre des clés).
 */
final class LargestRemainderAllocator
{
    private const EXTRA_PRECISION = 12;

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, BigNumber|int|string>  $weights
     * @return array<TKey, BigDecimal>
     */
    public function allocate(BigNumber|int|string $total, array $weights, int $scale): array
    {
        $total = Decimal::of($total);

        if ($scale < 0) {
            throw new InvalidArgumentException('Échelle négative.');
        }

        if ($weights === []) {
            throw new InvalidArgumentException('Aucun élément à répartir.');
        }

        if (! $total->isEqualTo($total->toScale($scale, RoundingMode::Down))) {
            throw new InvalidArgumentException("Le montant {$total} n'est pas représentable à l'échelle {$scale}.");
        }

        $parsed = [];
        $sum = BigDecimal::zero();

        foreach ($weights as $key => $weight) {
            $weight = Decimal::of($weight);

            if ($weight->isNegative()) {
                throw new InvalidArgumentException("Poids négatif pour l'élément « {$key} ».");
            }

            $parsed[$key] = $weight;
            $sum = $sum->plus($weight);
        }

        if (! $sum->isPositive()) {
            throw BusinessRuleViolation::invalidAllocationBasis();
        }

        $negative = $total->isNegative();
        $absTotal = $total->abs();
        $unit = BigDecimal::one()->withPointMovedLeft($scale);

        $shares = [];
        $remainders = [];
        $allocated = BigDecimal::zero();
        $position = 0;

        foreach ($parsed as $key => $weight) {
            $exact = $absTotal->multipliedBy($weight)->dividedBy($sum, $scale + self::EXTRA_PRECISION, RoundingMode::Down);
            $floor = $exact->toScale($scale, RoundingMode::Down);

            $shares[$key] = $floor;
            $remainders[] = ['key' => $key, 'remainder' => $exact->minus($floor), 'position' => $position++];
            $allocated = $allocated->plus($floor);
        }

        $leftoverUnits = $absTotal->minus($allocated)->dividedBy($unit, 0, RoundingMode::Unnecessary)->toInt();

        usort($remainders, static fn (array $a, array $b): int => $b['remainder']->compareTo($a['remainder']) ?: $a['position'] <=> $b['position']);

        for ($i = 0; $i < $leftoverUnits; $i++) {
            $key = $remainders[$i]['key'];
            $shares[$key] = $shares[$key]->plus($unit);
        }

        if ($negative) {
            foreach ($shares as $key => $share) {
                $shares[$key] = $share->negated();
            }
        }

        return $shares;
    }
}
