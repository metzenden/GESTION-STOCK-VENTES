<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Costing\Infrastructure\Models\ShipmentCost;
use App\Modules\Logistics\Contracts\ProvisionalCostEstimator;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Logistics\Contracts\ShipmentCloseGuard;
use App\Modules\Settings\Contracts\ExchangeRates;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Coût d'entrée en stock à la réception (CR-013, D-012) :
 * - arrivage : coût provisoire = achat converti (dernier taux enregistré, sinon taux estimé du BC)
 *   + quote-part des frais connus au CBM attendu et des frais directs, par pièce attendue ;
 * - réception directe d'une commande (sans arrivage ni frais) : coût définitif au prix d'achat converti (D-089).
 */
final class ProvisionalCosts implements ProvisionalCostEstimator, ShipmentCloseGuard
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CompanyDirectory $companies,
        private readonly ExchangeRates $rates,
        private readonly ShipmentBook $shipments,
        private readonly LandedCostService $landedCosts,
    ) {}

    public function estimate(array $lines): array
    {
        $company = $this->companies->find($this->tenant->requireCompanyId());
        $reference = $company->referenceCurrency ?? 'XOF';
        $today = CarbonImmutable::now($company->timezone ?? 'Africa/Dakar');
        $feesPerPiece = [];
        $result = [];

        foreach ($lines as $index => $line) {
            if ($line->purchaseCurrency === $reference) {
                $rate = BigDecimal::one();
            } else {
                $known = $this->rates->latest($line->purchaseCurrency, $reference, $today)['rate'] ?? $line->estimatedRate;

                if ($known === null) {
                    throw new BusinessRuleViolation('missing_exchange_rate', 'Taux de change manquant',
                        "Aucun taux {$line->purchaseCurrency} → {$reference} n'est enregistré ni estimé sur la commande : enregistrez un taux avant de valider.", 422, [],
                        [['pointer' => "/lines/{$index}", 'code' => 'missing_rate', 'message' => "Taux {$line->purchaseCurrency} manquant."]]);
                }

                $rate = BigDecimal::of($known);
            }

            $unit = BigDecimal::of($line->purchaseUnitPrice)->multipliedBy($rate);

            if ($line->shipmentId !== null && $line->shipmentLineId !== null) {
                $feesPerPiece[$line->shipmentId] ??= $this->feesPerPiece($line->shipmentId);
                $unit = $unit->plus($feesPerPiece[$line->shipmentId][$line->shipmentLineId] ?? BigDecimal::zero());
            }

            $result[$line->receiptLineId] = [
                'unit_cost' => (string) $unit->toScale(8, RoundingMode::HalfUp),
                'currency' => $reference,
                'cost_status' => $line->shipmentId !== null ? 'provisional' : 'final',
            ];
        }

        return $result;
    }

    public function canClose(string $shipmentId): bool
    {
        return $this->landedCosts->hasFinal($shipmentId);
    }

    /** @return array<string, BigDecimal> ligne d'arrivage → frais estimés par pièce attendue */
    private function feesPerPiece(string $shipmentId): array
    {
        $lines = $this->shipments->costingLines($shipmentId);
        $totalCbm = BigDecimal::zero();

        foreach ($lines as $line) {
            $totalCbm = $totalCbm->plus(BigDecimal::of($line->cbmTotal ?? '0'));
        }

        $fees = array_fill_keys(array_map(fn ($l) => $l->id, $lines), BigDecimal::zero());

        foreach (ShipmentCost::query()->where('shipment_id', $shipmentId)->where('status', '<>', 'cancelled')->get() as $cost) {
            $amount = BigDecimal::of($cost->amount_reference);

            if ($cost->allocation_method === 'direct') {
                $target = (string) $cost->target_shipment_line_id;
                $fees[$target] = ($fees[$target] ?? BigDecimal::zero())->plus($amount);
            } elseif ($totalCbm->isPositive()) {
                foreach ($lines as $line) {
                    $fees[$line->id] = $fees[$line->id]->plus($amount->multipliedBy(BigDecimal::of($line->cbmTotal ?? '0'))->dividedBy($totalCbm, 8, RoundingMode::HalfUp));
                }
            }
        }

        $perPiece = [];

        foreach ($lines as $line) {
            $perPiece[$line->id] = $fees[$line->id]->dividedBy(max($line->qtyExpected, 1), 8, RoundingMode::HalfUp);
        }

        return $perPiece;
    }
}
