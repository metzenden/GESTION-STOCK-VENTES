<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Services;

use App\Modules\Costing\Domain\Expressions\Evaluator;
use App\Modules\Costing\Domain\Expressions\Node;
use App\Modules\Shared\Domain\Services\LargestRemainderAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Formule de coût de revient (docs/04 §7.1) — calcul pur et reproductible :
 *
 *   achat_converti_i = montant_achat_devise_i × taux_i
 *   part_frais_i     = Σ frais communs répartis au CBM (plus fort reste à 4 décimales, CR-008)
 *   coût_total_i     = formule du modèle (standard : achat_converti + part_frais + frais_directs)
 *   coût_pièce_i     = coût_total_i / qté_i ; coût_carton_i = coût_pièce_i × pièces_par_carton_i
 *
 * Calculs internes en 8 décimales (CR-008), aucun arrondi d'affichage ici (CR-007).
 */
final class LandedCostCalculator
{
    public const SCALE = 8;

    public const FEE_SCALE = 4;

    /** Variables offertes aux formules (liste blanche, CR-014). */
    public const VARIABLES = ['purchase_foreign', 'exchange_rate', 'purchase_converted', 'common_fees', 'direct_fees', 'cbm', 'cbm_ratio', 'qty', 'pieces_per_carton'];

    public const STANDARD_EXPRESSION = 'purchase_converted + common_fees + direct_fees';

    public function __construct(
        private readonly LargestRemainderAllocator $allocator = new LargestRemainderAllocator,
        private readonly Evaluator $evaluator = new Evaluator,
    ) {}

    /**
     * @param  array<string, array{qty: int, cbm: string, purchase_foreign: string, rate: string, pieces_per_carton: int|null}>  $lines  ordonnées par position
     * @param  array<string, array{amount: string, method: string, target: string|null}>  $fees  montants en devise de référence
     * @return array{lines: array<string, array<string, mixed>>, total_cbm: string, total_common: string, total_direct: string, total_purchase: string, total_cost: string}
     */
    public function compute(array $lines, array $fees, Node $expression): array
    {
        $totalCbm = BigDecimal::zero();
        $cbm = [];

        foreach ($lines as $id => $line) {
            $cbm[$id] = BigDecimal::of($line['cbm']);
            $totalCbm = $totalCbm->plus($cbm[$id]);
        }

        $common = array_fill_keys(array_keys($lines), BigDecimal::zero());
        $exactCommon = $common;
        $direct = $common;
        $allocations = array_fill_keys(array_keys($lines), []);
        $totalCommon = BigDecimal::zero();
        $totalDirect = BigDecimal::zero();

        foreach ($fees as $feeId => $fee) {
            $amount = BigDecimal::of($fee['amount'])->toScale(self::FEE_SCALE, RoundingMode::HalfUp);

            if ($fee['method'] === 'direct') {
                $target = (string) $fee['target'];
                $direct[$target] = $direct[$target]->plus($amount);
                $allocations[$target][$feeId] = $amount;
                $totalDirect = $totalDirect->plus($amount);

                continue;
            }

            // CR-002, CR-008 : chaque frais commun est réparti une fois, Σ parts = frais exactement.
            $shares = $this->allocator->allocate($amount, $cbm, self::FEE_SCALE);
            $totalCommon = $totalCommon->plus($amount);

            foreach ($shares as $lineId => $share) {
                $common[$lineId] = $common[$lineId]->plus($share);
                $exactCommon[$lineId] = $exactCommon[$lineId]->plus($amount->multipliedBy($cbm[$lineId])->dividedBy($totalCbm, self::SCALE, RoundingMode::HalfUp));
                $allocations[$lineId][$feeId] = $share;
            }
        }

        $result = [];
        $totalPurchase = BigDecimal::zero();
        $totalCost = BigDecimal::zero();

        foreach ($lines as $id => $line) {
            $foreign = BigDecimal::of($line['purchase_foreign']);
            $rate = BigDecimal::of($line['rate']);
            $converted = $foreign->multipliedBy($rate)->toScale(self::SCALE, RoundingMode::HalfUp);
            $variables = [
                'purchase_foreign' => $foreign,
                'exchange_rate' => $rate,
                'purchase_converted' => $converted,
                'common_fees' => $common[$id],
                'direct_fees' => $direct[$id],
                'cbm' => $cbm[$id],
                'cbm_ratio' => $cbm[$id]->dividedBy($totalCbm, self::SCALE, RoundingMode::HalfUp),
                'qty' => BigDecimal::of($line['qty']),
                'pieces_per_carton' => BigDecimal::of($line['pieces_per_carton'] ?? 0),
            ];
            $total = $this->evaluator->evaluate($expression, $variables)->toScale(self::SCALE, RoundingMode::HalfUp);
            $piece = $total->dividedBy($line['qty'], self::SCALE, RoundingMode::HalfUp);

            $result[$id] = [
                'purchase_converted' => $converted,
                'common_fees' => $common[$id],
                'direct_fees' => $direct[$id],
                'rounding_adjustment' => $common[$id]->minus($exactCommon[$id])->toScale(self::SCALE),
                'total_cost' => $total,
                'unit_cost_piece' => $piece,
                'unit_cost_carton' => $line['pieces_per_carton'] !== null ? $piece->multipliedBy($line['pieces_per_carton'])->toScale(self::SCALE) : null,
                'allocations' => $allocations[$id],
            ];
            $totalPurchase = $totalPurchase->plus($converted);
            $totalCost = $totalCost->plus($total);
        }

        return [
            'lines' => $result,
            'total_cbm' => (string) $totalCbm,
            'total_common' => (string) $totalCommon->toScale(self::FEE_SCALE),
            'total_direct' => (string) $totalDirect->toScale(self::FEE_SCALE),
            'total_purchase' => (string) $totalPurchase->toScale(self::FEE_SCALE, RoundingMode::HalfUp),
            'total_cost' => (string) $totalCost->toScale(self::FEE_SCALE, RoundingMode::HalfUp),
        ];
    }
}
