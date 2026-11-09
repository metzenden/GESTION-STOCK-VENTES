<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Services;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\Services\LargestRemainderAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Totaux d'un devis ou d'une commande (SAL-007, BIL-005, D-013) — calcul pur, à la précision de la devise :
 *
 *   brut_i       = prix_unitaire_i × qté_i                       (arrondi HALF_UP)
 *   remise_i     = remise de ligne (% ou montant) + part de la remise globale (plus fort reste, au prorata du net)
 *   net_i        = brut_i − remise_i
 *   taxe_i       = arrondi(net_i × taux_i / 100)               (aucun taux par défaut)
 *   total_i      = net_i + taxe_i
 *
 * Les prix sont saisis hors taxes ; un taux à 0 n'ajoute aucune taxe.
 */
final class SalesPricing
{
    public function __construct(private readonly LargestRemainderAllocator $allocator = new LargestRemainderAllocator) {}

    /**
     * @param  array<int|string, array{qty_units: int, unit_price: string, discount_type: string|null, discount_value: string|null, tax_rate: string}>  $lines
     * @param  array{type: string, value: string}|null  $globalDiscount
     * @return array{lines: array<int|string, array{gross: string, discount_amount: string, net: string, tax_amount: string, line_total: string}>,
     *     subtotal: string, discount_total: string, tax_total: string, total: string}
     */
    public function compute(array $lines, ?array $globalDiscount, int $decimals): array
    {
        $decimals = max(0, $decimals);
        $gross = [];
        $net = [];
        $lineDiscount = [];

        foreach ($lines as $key => $line) {
            $g = BigDecimal::of($line['unit_price'])->multipliedBy($line['qty_units'])->toScale($decimals, RoundingMode::HalfUp);
            $d = $this->discount($g, $line['discount_type'], $line['discount_value'], $decimals, "lines/{$key}/discount_value");
            $gross[$key] = $g;
            $lineDiscount[$key] = $d;
            $net[$key] = $g->minus($d);
        }

        $netSum = array_reduce($net, fn (BigDecimal $c, BigDecimal $n) => $c->plus($n), BigDecimal::zero());
        $share = array_fill_keys(array_keys($lines), BigDecimal::zero());

        if ($globalDiscount !== null && $lines !== []) {
            $amount = $this->discount($netSum, $globalDiscount['type'], $globalDiscount['value'], $decimals, 'global_discount/value');

            if ($amount->isPositive()) {
                $share = $this->allocator->allocate($amount, array_map(fn (BigDecimal $n) => $n, $net), $decimals);
            }
        }

        $result = [];
        $totals = ['subtotal' => BigDecimal::zero(), 'discount' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'total' => BigDecimal::zero()];

        foreach ($lines as $key => $line) {
            $discount = $lineDiscount[$key]->plus($share[$key]);
            $lineNet = $gross[$key]->minus($discount);
            $tax = $lineNet->multipliedBy(BigDecimal::of($line['tax_rate']))->dividedBy(100, $decimals, RoundingMode::HalfUp);
            $total = $lineNet->plus($tax);

            $result[$key] = [
                'gross' => (string) $gross[$key],
                'discount_amount' => (string) $discount,
                'net' => (string) $lineNet,
                'tax_amount' => (string) $tax,
                'line_total' => (string) $total,
            ];
            $totals['subtotal'] = $totals['subtotal']->plus($gross[$key]);
            $totals['discount'] = $totals['discount']->plus($discount);
            $totals['tax'] = $totals['tax']->plus($tax);
            $totals['total'] = $totals['total']->plus($total);
        }

        return [
            'lines' => $result,
            'subtotal' => (string) $totals['subtotal']->toScale($decimals),
            'discount_total' => (string) $totals['discount']->toScale($decimals),
            'tax_total' => (string) $totals['tax']->toScale($decimals),
            'total' => (string) $totals['total']->toScale($decimals),
        ];
    }

    /** @param int<0, max> $decimals */
    private function discount(BigDecimal $base, ?string $type, ?string $value, int $decimals, string $pointer): BigDecimal
    {
        if ($type === null || $value === null || BigDecimal::of($value)->isZero()) {
            return BigDecimal::zero()->toScale($decimals);
        }

        $v = BigDecimal::of($value);

        if ($v->isNegative()) {
            throw BusinessRuleViolation::invalidField($pointer, 'Une remise ne peut pas être négative.');
        }

        $amount = match ($type) {
            'percent' => $v->isGreaterThan(100)
                ? throw BusinessRuleViolation::invalidField($pointer, 'Une remise ne peut pas dépasser 100 %.')
                : $base->multipliedBy($v)->dividedBy(100, $decimals, RoundingMode::HalfUp),
            'amount' => $v->toScale($decimals, RoundingMode::HalfUp),
            default => throw BusinessRuleViolation::invalidField($pointer, 'Type de remise inconnu.'),
        };

        if ($amount->isGreaterThan($base)) {
            throw BusinessRuleViolation::invalidField($pointer, 'La remise dépasse le montant de la ligne.');
        }

        return $amount;
    }
}
