<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Costing\Infrastructure\Models\LandedCostCalculation;
use App\Modules\Costing\Infrastructure\Models\LandedCostCategory;
use App\Modules\Costing\Infrastructure\Models\ShipmentCost;
use App\Modules\Logistics\Contracts\ShipmentBook;
use App\Modules\Procurement\Contracts\SupplierCommitments;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Settings\Contracts\ExchangeRates;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Frais d'approche d'un arrivage (L3-10) : brouillon → confirmé (engagement du transitaire, D-026) → annulé.
 * Ils entrent dans le coût des lots et ne sont jamais des dépenses du résultat (CR-012).
 */
final class ShipmentCostService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly ShipmentBook $shipments,
        private readonly SupplierDirectory $suppliers,
        private readonly SupplierCommitments $commitments,
        private readonly ExchangeRates $rates,
        private readonly CompanyDirectory $companies,
        private readonly CostingDefaults $defaults,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(string $shipmentId, array $data, ?string $actorId): ShipmentCost
    {
        return $this->db->transaction(function () use ($shipmentId, $data, $actorId) {
            $this->assertOpen($shipmentId);
            $this->defaults->ensure();
            $category = LandedCostCategory::query()->where('is_active', true)->find((string) $data['category_id'])
                ?? throw BusinessRuleViolation::invalidField('category_id', 'Cette catégorie de frais n\'existe pas ou est inactive.');

            $cost = new ShipmentCost;
            $cost->forceFill(['shipment_id' => $shipmentId, 'status' => 'draft', 'created_by' => $actorId]);
            $this->apply($cost, $data + ['allocation_method' => $category->default_allocation], $shipmentId);
            $cost->save();

            return $cost->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $costId, array $data, ?int $version, ?string $actorId): ShipmentCost
    {
        return $this->db->transaction(function () use ($costId, $data, $version, $actorId) {
            $cost = $this->lock($costId, ['draft'], 'modifier');

            if ($version !== null && $version !== $cost->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            $this->assertOpen($cost->shipment_id);

            if (isset($data['category_id']) && ! LandedCostCategory::query()->where('is_active', true)->whereKey((string) $data['category_id'])->exists()) {
                throw BusinessRuleViolation::invalidField('category_id', 'Cette catégorie de frais n\'existe pas ou est inactive.');
            }

            $this->apply($cost, $data, $cost->shipment_id);
            $cost->forceFill(['version' => $cost->version + 1, 'updated_by' => $actorId])->save();

            return $cost->refresh();
        });
    }

    /** Confirmation : le frais devient certain ; s'il a un prestataire, son engagement est créé (D-026). */
    public function confirm(string $costId, ?string $actorId): ShipmentCost
    {
        return $this->db->transaction(function () use ($costId, $actorId) {
            $cost = $this->lock($costId, ['draft'], 'confirmer');
            $this->assertOpen($cost->shipment_id);

            if ($cost->supplier_id !== null) {
                $shipment = $this->shipments->find($cost->shipment_id);
                $billId = $this->commitments->commit($cost->supplier_id, $cost->currency, $cost->amount, 'shipment_cost', $cost->id,
                    $this->today(), trim(($cost->description ?? 'Frais d\'approche').' — arrivage '.($shipment->number ?? '')), $actorId);
                $cost->forceFill(['supplier_bill_id' => $billId]);
            }

            return $this->mark($cost, 'confirmed', $actorId);
        });
    }

    public function cancel(string $costId, ?string $actorId): ShipmentCost
    {
        return $this->db->transaction(function () use ($costId, $actorId) {
            $cost = $this->lock($costId, ['draft', 'confirmed'], 'annuler');
            $this->assertOpen($cost->shipment_id);

            if ($cost->supplier_bill_id !== null) {
                $this->commitments->compensate($cost->supplier_bill_id, 'Annulation d\'un frais d\'approche', $actorId);
            }

            return $this->mark($cost, 'cancelled', $actorId);
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(ShipmentCost $cost, array $data, string $shipmentId): void
    {
        $reference = $this->referenceCurrency();

        foreach (['category_id', 'description', 'is_estimate'] as $field) {
            if (array_key_exists($field, $data)) {
                $cost->setAttribute($field, $data[$field]);
            }
        }

        if (array_key_exists('supplier_id', $data)) {
            if ($data['supplier_id'] !== null && $this->suppliers->find((string) $data['supplier_id']) === null) {
                throw BusinessRuleViolation::invalidField('supplier_id', 'Ce fournisseur n\'existe pas.');
            }

            $cost->supplier_id = $data['supplier_id'] === null ? null : (string) $data['supplier_id'];
        }

        if (isset($data['currency'])) {
            $cost->currency = (string) $data['currency'];
        }

        if (isset($data['amount'])) {
            $cost->amount = (string) Decimal::of((string) $data['amount'])->toScale(4, RoundingMode::HalfUp);
        }

        if (isset($data['allocation_method'])) {
            $cost->allocation_method = (string) $data['allocation_method'];
        }

        if (array_key_exists('target_shipment_line_id', $data)) {
            $cost->target_shipment_line_id = $data['target_shipment_line_id'] === null ? null : (string) $data['target_shipment_line_id'];
        }

        if ($cost->allocation_method === 'cbm') {
            $cost->target_shipment_line_id = null;
        } elseif ($cost->target_shipment_line_id === null || ! $this->shipments->hasLine($shipmentId, $cost->target_shipment_line_id)) {
            throw BusinessRuleViolation::invalidField('target_shipment_line_id', 'Un frais direct doit viser un article de cet arrivage.', 'required');
        }

        // Taux vers la devise de référence : saisi, sinon dernier taux enregistré ; jamais deviné (CR-003).
        if ($cost->currency === $reference) {
            $cost->exchange_rate = '1';
        } elseif (isset($data['exchange_rate'])) {
            $cost->exchange_rate = (string) $data['exchange_rate'];
        } elseif (isset($data['currency']) || $cost->getAttribute('exchange_rate') === null) {
            $known = $this->rates->latest($cost->currency, $reference, $this->today());
            $cost->exchange_rate = $known['rate'] ?? throw BusinessRuleViolation::invalidField('exchange_rate',
                "Aucun taux {$cost->currency} → {$reference} n'est enregistré : saisissez le taux.", 'required');
        }

        if (! Decimal::of($cost->amount)->isPositive() || ! Decimal::of($cost->exchange_rate)->isPositive()) {
            throw BusinessRuleViolation::invalidField('amount', 'Le montant et le taux doivent être positifs.');
        }

        $cost->amount_reference = (string) Decimal::of($cost->amount)->multipliedBy(Decimal::of($cost->exchange_rate))->toScale(4, RoundingMode::HalfUp);
    }

    private function assertOpen(string $shipmentId): void
    {
        $shipment = $this->shipments->find($shipmentId) ?? throw BusinessRuleViolation::invalidField('shipment_id', 'Cet arrivage n\'existe pas.');

        if (! $shipment->acceptsCosts()) {
            throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, 'modifier ses frais');
        }

        if (LandedCostCalculation::query()->where('shipment_id', $shipmentId)->where('status', 'final')->exists()) {
            throw new BusinessRuleViolation('landed_cost_final', 'Coût de revient définitif', 'Le coût de revient de cet arrivage est définitif : ses frais ne peuvent plus changer.', 409);
        }
    }

    private function mark(ShipmentCost $cost, string $status, ?string $actorId): ShipmentCost
    {
        $previous = $cost->status;
        $cost->forceFill(['status' => $status, 'version' => $cost->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("shipment_cost.{$status}", 'shipment_cost', $cost->id, ['status' => $previous],
            ['status' => $status, 'amount' => $cost->amount, 'currency' => $cost->currency, 'shipment_id' => $cost->shipment_id]);

        return $cost->refresh();
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): ShipmentCost
    {
        $cost = ShipmentCost::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($cost->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('le frais', $cost->status, $action);
        }

        return $cost;
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
