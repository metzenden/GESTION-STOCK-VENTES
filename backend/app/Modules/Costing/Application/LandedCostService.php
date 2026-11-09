<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Costing\Domain\Expressions\ExpressionError;
use App\Modules\Costing\Domain\Expressions\Parser;
use App\Modules\Costing\Domain\Services\LandedCostCalculator;
use App\Modules\Costing\Infrastructure\Models\LandedCostCalculation;
use App\Modules\Costing\Infrastructure\Models\LandedCostModel;
use App\Modules\Costing\Infrastructure\Models\ShipmentCost;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Logistics\Contracts\CostingLine;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Settings\Contracts\ExchangeRates;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Calcul (L3-12) et finalisation (L3-13) du coût de revient d'un arrivage.
 *
 * - Chaque calcul est une révision figée : modèle (version), taux (CR-003), entrées hachées (reproductibilité).
 * - Les anomalies (CR-006) n'empêchent pas le calcul mais bloquent la finalisation.
 * - La finalisation passe les lots en coût définitif et valorise l'écart des sorties déjà valorisées (CR-013).
 */
final class LandedCostService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly ShipmentBook $shipments,
        private readonly StockLedger $ledger,
        private readonly ExchangeRates $rates,
        private readonly CompanyDirectory $companies,
        private readonly CostingDefaults $defaults,
        private readonly ApprovalGate $approvals,
        private readonly AuditRecorder $audit,
        private readonly LandedCostCalculator $calculator = new LandedCostCalculator,
    ) {}

    /** @param array<string, string> $rateOverrides devise → taux vers la devise de référence */
    public function compute(string $shipmentId, ?string $modelId, array $rateOverrides, ?string $actorId): LandedCostCalculation
    {
        return $this->db->transaction(function () use ($shipmentId, $modelId, $rateOverrides, $actorId) {
            $shipment = $this->shipments->lock($shipmentId);

            if (in_array($shipment->status, ['closed', 'cancelled'], true)) {
                throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, 'calculer le coût de revient');
            }

            if (LandedCostCalculation::query()->where('shipment_id', $shipmentId)->where('status', 'final')->exists()) {
                throw new BusinessRuleViolation('landed_cost_final', 'Coût de revient définitif', 'Le coût de revient de cet arrivage est déjà définitif.', 409);
            }

            $model = $this->model($modelId);
            $inputs = $this->inputs($shipmentId, $shipment->isFullyReceived(), $model, $rateOverrides);

            if ($inputs['lines'] === [] || ! Decimal::of($inputs['total_cbm'])->isPositive()) {
                // CR-001 : sans volume éligible, aucun calcul ne peut être enregistré.
                throw new BusinessRuleViolation('landed_cost_blocked', 'Calcul impossible',
                    'Aucune ligne réceptionnée ne porte de volume (CBM) : le coût de revient ne peut pas être calculé.', 422,
                    ['issues' => $inputs['issues']]);
            }

            $result = $this->calculator->compute($inputs['calc_lines'], $inputs['calc_fees'], (new Parser(LandedCostCalculator::VARIABLES))->parse($model->expression));

            LandedCostCalculation::query()->where('shipment_id', $shipmentId)->where('status', 'computed')->update(['status' => 'superseded', 'updated_by' => $actorId]);
            $revision = (int) LandedCostCalculation::query()->where('shipment_id', $shipmentId)->max('revision') + 1;

            $calculation = new LandedCostCalculation;
            $calculation->fill([
                'shipment_id' => $shipmentId,
                'model_id' => $model->id,
                'revision' => $revision,
                'status' => 'computed',
                'reference_currency' => $inputs['reference'],
                'rates_snapshot' => $inputs['rates'],
                'inputs_hash' => $inputs['hash'],
                'total_cbm' => $result['total_cbm'],
                'total_common_fees' => $result['total_common'],
                'total_direct_fees' => $result['total_direct'],
                'total_purchase' => $result['total_purchase'],
                'total_cost' => $result['total_cost'],
                'blocking_issues' => $inputs['issues'],
                'discrepancies' => $inputs['discrepancies'],
                'computed_at' => CarbonImmutable::now(),
            ])->forceFill(['created_by' => $actorId])->save();

            $companyId = $this->tenant->requireCompanyId();

            foreach ($inputs['lines'] as $line) {
                $computed = $result['lines'][$line->id];
                $lineId = (string) Str::ulid();
                $this->db->table('landed_cost_lines')->insert([
                    'id' => $lineId,
                    'company_id' => $companyId,
                    'calculation_id' => $calculation->id,
                    'shipment_line_id' => $line->id,
                    'variant_id' => $line->variantId,
                    'qty_accepted' => $line->qtyStocked,
                    'pieces_per_carton' => $line->piecesPerCarton,
                    'line_cbm' => $inputs['calc_lines'][$line->id]['cbm'],
                    'purchase_currency' => $line->purchaseCurrency,
                    'purchase_amount_foreign' => $inputs['calc_lines'][$line->id]['purchase_foreign'],
                    'exchange_rate' => $inputs['calc_lines'][$line->id]['rate'],
                    'purchase_amount_converted' => (string) $computed['purchase_converted'],
                    'allocated_common_fees' => (string) $computed['common_fees'],
                    'direct_fees' => (string) $computed['direct_fees'],
                    'rounding_adjustment' => (string) $computed['rounding_adjustment'],
                    'total_cost' => (string) $computed['total_cost'],
                    'unit_cost_piece' => (string) $computed['unit_cost_piece'],
                    'unit_cost_carton' => $computed['unit_cost_carton'] === null ? null : (string) $computed['unit_cost_carton'],
                ]);

                /** @var array<string, BigDecimal> $allocations */
                $allocations = $computed['allocations'];

                foreach ($allocations as $costId => $amount) {
                    $this->db->table('landed_cost_allocations')->insert([
                        'id' => (string) Str::ulid(),
                        'company_id' => $companyId,
                        'calculation_line_id' => $lineId,
                        'shipment_cost_id' => $costId,
                        'amount' => (string) $amount,
                    ]);
                }
            }

            $this->audit->record('landed_cost.computed', 'landed_cost_calculation', $calculation->id, [], [
                'shipment_id' => $shipmentId, 'revision' => $revision, 'total_cost' => $result['total_cost'], 'issues' => count($inputs['issues']),
            ]);

            return $calculation->refresh();
        }, 3);
    }

    /** CR-004, CR-006, CR-013 : coût définitif des lots et ajustements de valorisation des sorties déjà valorisées. */
    public function finalize(string $calculationId, ?string $actorId): LandedCostCalculation
    {
        return $this->db->transaction(function () use ($calculationId, $actorId) {
            $calculation = LandedCostCalculation::query()->findOrFail($calculationId);
            $shipment = $this->shipments->lock($calculation->shipment_id);
            $calculation = LandedCostCalculation::query()->lockForUpdate()->findOrFail($calculation->id);

            if ($calculation->status === 'final') {
                throw BusinessRuleViolation::alreadyProcessed('Ce coût de revient est déjà définitif.');
            }

            if ($calculation->status !== 'computed') {
                throw BusinessRuleViolation::invalidTransition('le calcul', $calculation->status, 'finaliser');
            }

            if (($calculation->blocking_issues ?? []) !== []) {
                throw new BusinessRuleViolation('landed_cost_blocked', 'Finalisation bloquée',
                    'Le calcul comporte des anomalies à corriger avant la finalisation.', 422, ['issues' => $calculation->blocking_issues]);
            }

            // Reproductibilité : les entrées doivent être identiques à celles du calcul (mêmes taux figés).
            $model = LandedCostModel::query()->findOrFail($calculation->model_id);
            $overrides = array_map(fn (array $r) => (string) $r['rate'], $calculation->rates_snapshot['purchase'] ?? []);
            $inputs = $this->inputs($calculation->shipment_id, $shipment->isFullyReceived(), $model, $overrides);

            if ($inputs['hash'] !== $calculation->inputs_hash || $inputs['issues'] !== []) {
                throw new BusinessRuleViolation('landed_cost_stale', 'Calcul périmé',
                    'Les réceptions, les frais ou les lignes ont changé depuis ce calcul : relancez le calcul.', 409);
            }

            if ($this->approvals->requiresApproval('landed_cost_finalization', ['shipment_id' => $shipment->id, 'total_cost' => $calculation->total_cost])) {
                throw new BusinessRuleViolation('approval_required', 'Approbation requise', 'La finalisation de ce coût de revient doit être approuvée.', 409);
            }

            $lots = [];

            foreach ($inputs['lines'] as $line) {
                foreach ($line->lotIds as $lotId) {
                    $lots[$lotId] = $line->id;
                }
            }

            $costLines = $this->db->table('landed_cost_lines')->where('company_id', $calculation->company_id)
                ->where('calculation_id', $calculation->id)->get()->keyBy('shipment_line_id');
            $newCosts = [];
            ksort($lots);

            foreach ($lots as $lotId => $shipmentLineId) {
                $row = $costLines[$shipmentLineId] ?? throw new \LogicException('Ligne de calcul absente.');
                $newCosts[$lotId] = (string) $row->unit_cost_piece;
                $this->ledger->finalizeLotCost($lotId, (string) $row->unit_cost_piece, $calculation->id, [
                    'calculation_id' => $calculation->id,
                    'revision' => $calculation->revision,
                    'purchase_converted' => (string) $row->purchase_amount_converted,
                    'common_fees' => (string) $row->allocated_common_fees,
                    'direct_fees' => (string) $row->direct_fees,
                    'qty' => (int) $row->qty_accepted,
                ]);
            }

            $delta = BigDecimal::zero();

            foreach ($this->ledger->valuedMovements(array_keys($lots)) as $movement) {
                $quantity = -$movement['quantity'];
                $old = BigDecimal::of($movement['unit_cost']);
                $new = BigDecimal::of($newCosts[$movement['lot_id']]);

                if ($old->isEqualTo($new)) {
                    continue;
                }

                $amount = $new->minus($old)->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp);
                $delta = $delta->plus($amount);
                $this->db->table('stock_valuation_adjustments')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $calculation->company_id,
                    'lot_id' => $movement['lot_id'],
                    'calculation_id' => $calculation->id,
                    'stock_movement_id' => $movement['id'],
                    'movement_type' => $movement['movement_type'],
                    'quantity' => $quantity,
                    'old_unit_cost' => (string) $old,
                    'new_unit_cost' => (string) $new,
                    'delta_amount' => (string) $amount,
                    'created_by' => $actorId,
                ]);
            }

            $calculation->forceFill(['status' => 'final', 'finalized_at' => CarbonImmutable::now(), 'finalized_by' => $actorId, 'updated_by' => $actorId])->save();
            $this->audit->record('landed_cost.finalized', 'landed_cost_calculation', $calculation->id, ['status' => 'computed'], [
                'status' => 'final', 'shipment_id' => $shipment->id, 'lots' => count($lots), 'valuation_delta' => (string) $delta,
            ]);

            return $calculation->refresh();
        }, 3);
    }

    public function hasFinal(string $shipmentId): bool
    {
        return LandedCostCalculation::query()->where('shipment_id', $shipmentId)->where('status', 'final')->exists();
    }

    /**
     * Rassemble et contrôle les entrées d'un calcul (CR-006, CR-009, CR-010).
     *
     * @param  array<string, string>  $rateOverrides
     * @return array{reference: string, lines: list<CostingLine>, calc_lines: array<string, array{qty: int, cbm: string, purchase_foreign: string, rate: string, pieces_per_carton: int|null}>,
     *     calc_fees: array<string, array{amount: string, method: string, target: string|null}>, rates: array<string, mixed>, issues: list<array<string, mixed>>,
     *     discrepancies: list<array<string, mixed>>, total_cbm: string, hash: string}
     */
    private function inputs(string $shipmentId, bool $fullyReceived, LandedCostModel $model, array $rateOverrides): array
    {
        $reference = $this->referenceCurrency();
        $today = $this->today();
        $issues = [];
        $discrepancies = [];
        $rates = ['purchase' => [], 'fees' => []];

        if (! $fullyReceived) {
            $issues[] = ['code' => 'reception_incomplete', 'message' => 'Toutes les réceptions de l\'arrivage ne sont pas terminées.'];
        }

        $all = $this->shipments->costingLines($shipmentId);
        $eligible = [];
        $calcLines = [];
        $totalCbm = BigDecimal::zero();

        foreach ($all as $line) {
            $price = BigDecimal::of($line->purchaseUnitPrice);

            if ($line->qtyStocked < $line->qtyExpected) {
                $missing = $line->qtyExpected - $line->qtyStocked;
                // CR-010 : les manquants ne sont pas imputés au stock ; leur valeur est un écart à réclamer.
                $discrepancies[] = [
                    'shipment_line_id' => $line->id, 'position' => $line->position, 'variant_id' => $line->variantId, 'kind' => 'missing',
                    'quantity' => $missing, 'currency' => $line->purchaseCurrency, 'amount' => (string) $price->multipliedBy($missing)->toScale(4, RoundingMode::HalfUp),
                ];
            } elseif ($line->qtyStocked > $line->qtyExpected) {
                $discrepancies[] = ['shipment_line_id' => $line->id, 'position' => $line->position, 'variant_id' => $line->variantId, 'kind' => 'surplus',
                    'quantity' => $line->qtyStocked - $line->qtyExpected];
            }

            if ($line->qtyStocked <= 0) {
                continue;
            }

            $pointer = ['shipment_line_id' => $line->id, 'position' => $line->position];

            if ($line->cbmTotal === null) {
                $issues[] = ['code' => 'missing_cbm', 'message' => "Ligne {$line->position} : volume (CBM) manquant."] + $pointer;
            }

            if ($price->isZero()) {
                $issues[] = ['code' => 'missing_purchase_price', 'message' => "Ligne {$line->position} : prix d'achat manquant."] + $pointer;
            }

            $rate = $this->resolveRate($line->purchaseCurrency, $reference, $rateOverrides, $line->estimatedRate, $today);
            $rates['purchase'][$line->purchaseCurrency] ??= $rate;

            if ($rate['source'] === 'missing') {
                $issues[] = ['code' => 'missing_rate', 'message' => "Aucun taux {$line->purchaseCurrency} → {$reference}."] + $pointer;
            } elseif ($rate['source'] === 'estimated') {
                $issues[] = ['code' => 'rate_estimated', 'message' => "Le taux {$line->purchaseCurrency} → {$reference} est le taux estimé de la commande : enregistrez le taux réel."] + $pointer;
            }

            $cbm = $line->cbmTotal ?? '0';
            $totalCbm = $totalCbm->plus(BigDecimal::of($cbm));
            $eligible[] = $line;
            $calcLines[$line->id] = [
                'qty' => $line->qtyStocked,
                'cbm' => (string) BigDecimal::of($cbm)->toScale(6),
                'purchase_foreign' => (string) $price->multipliedBy($line->qtyStocked)->toScale(4, RoundingMode::HalfUp),
                'rate' => (string) BigDecimal::of($rate['rate'])->toScale(10, RoundingMode::HalfUp),
                'pieces_per_carton' => $line->piecesPerCarton,
            ];
        }

        $issues = array_values(array_unique($issues, SORT_REGULAR));
        $fees = [];

        foreach (ShipmentCost::query()->where('shipment_id', $shipmentId)->where('status', '<>', 'cancelled')->orderBy('created_at')->orderBy('id')->get() as $cost) {
            if ($cost->status === 'draft') {
                $issues[] = ['code' => 'fee_not_confirmed', 'message' => 'Frais non confirmé : '.($cost->description ?? $cost->amount.' '.$cost->currency).'.', 'shipment_cost_id' => $cost->id];
            }

            if ($cost->is_estimate) {
                $issues[] = ['code' => 'fee_estimated', 'message' => 'Frais estimé : '.($cost->description ?? $cost->amount.' '.$cost->currency).'.', 'shipment_cost_id' => $cost->id];
            }

            if ($cost->allocation_method === 'direct' && ! isset($calcLines[(string) $cost->target_shipment_line_id])) {
                $issues[] = ['code' => 'direct_fee_without_stock', 'message' => 'Un frais direct vise une ligne sans quantité reçue.', 'shipment_cost_id' => $cost->id];

                continue;
            }

            $rates['fees'][$cost->id] = ['currency' => $cost->currency, 'rate' => $cost->exchange_rate];
            $fees[$cost->id] = ['amount' => $cost->amount_reference, 'method' => $cost->allocation_method, 'target' => $cost->target_shipment_line_id];
        }

        $hash = hash('sha256', json_encode([
            'reference' => $reference,
            'model' => [$model->id, $model->version, $model->expression],
            'lines' => $calcLines,
            'fees' => $fees,
            'fee_status' => ShipmentCost::query()->where('shipment_id', $shipmentId)->orderBy('id')->pluck('status', 'id')->all(),
        ], JSON_THROW_ON_ERROR));

        return [
            'reference' => $reference,
            'lines' => $eligible,
            'calc_lines' => $calcLines,
            'calc_fees' => $fees,
            'rates' => $rates,
            'issues' => $issues,
            'discrepancies' => $discrepancies,
            'total_cbm' => (string) $totalCbm,
            'hash' => $hash,
        ];
    }

    /**
     * Taux d'une devise d'achat : forcé pour ce calcul, sinon dernier taux enregistré, sinon taux estimé du BC.
     *
     * @param  array<string, string>  $overrides
     * @return array{rate: string, source: string, valid_on?: string}
     */
    private function resolveRate(string $currency, string $reference, array $overrides, ?string $estimated, CarbonImmutable $today): array
    {
        if ($currency === $reference) {
            return ['rate' => '1', 'source' => 'reference'];
        }

        if (isset($overrides[$currency]) && Decimal::isValid($overrides[$currency]) && Decimal::of($overrides[$currency])->isPositive()) {
            return ['rate' => $overrides[$currency], 'source' => 'manual'];
        }

        $known = $this->rates->latest($currency, $reference, $today);

        if ($known !== null) {
            return ['rate' => $known['rate'], 'source' => 'exchange_rates', 'valid_on' => $known['valid_on']];
        }

        if ($estimated !== null) {
            return ['rate' => $estimated, 'source' => 'estimated'];
        }

        return ['rate' => '0', 'source' => 'missing'];
    }

    private function model(?string $modelId): LandedCostModel
    {
        if ($modelId === null) {
            return $this->defaults->activeStandard();
        }

        $model = LandedCostModel::query()->find($modelId);

        if ($model === null || $model->status !== 'active') {
            throw BusinessRuleViolation::invalidField('model_id', 'Ce modèle n\'existe pas ou n\'est pas actif.');
        }

        try {
            (new Parser(LandedCostCalculator::VARIABLES))->parse($model->expression);
        } catch (ExpressionError $e) {
            throw BusinessRuleViolation::invalidField('model_id', $e->getMessage());
        }

        return $model;
    }

    private function referenceCurrency(): string
    {
        return $this->companies->find($this->tenant->requireCompanyId())->referenceCurrency ?? 'XOF';
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
