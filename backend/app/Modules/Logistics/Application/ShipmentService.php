<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Logistics\Contracts\ShipmentCloseGuard;
use App\Modules\Logistics\Infrastructure\Models\Container;
use App\Modules\Logistics\Infrastructure\Models\GoodsReceipt;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentLine;
use App\Modules\Procurement\Contracts\PurchaseOrderBook;
use App\Modules\Procurement\Contracts\SupplierDirectory;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Arrivages (L3-04, docs/04 §6) : préparation → expédié → en transit → arrivé → déchargement
 * → réception partielle / terminée → clôturé (coût de revient définitif). Le stock attendu est visible,
 * jamais vendable (ST-001) ; seul la validation d'une réception fait entrer la marchandise.
 */
final class ShipmentService
{
    /** États où les articles attendus restent modifiables (avant tout déchargement). */
    public const EDITABLE = ['preparing', 'shipped', 'in_transit', 'arrived'];

    /** Transitions manuelles autorisées (les états de réception sont pilotés par les réceptions). */
    private const TRANSITIONS = [
        'shipped' => ['preparing'],
        'in_transit' => ['shipped'],
        'arrived' => ['shipped', 'in_transit'],
        'received' => ['partially_received'],
        'closed' => ['received'],
        'cancelled' => ['preparing'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly VariantCatalog $catalog,
        private readonly WarehouseDirectory $warehouses,
        private readonly SupplierDirectory $suppliers,
        private readonly PurchaseOrderBook $orders,
        private readonly ShipmentCloseGuard $closeGuard,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?string $actorId): Shipment
    {
        return $this->db->transaction(function () use ($data, $actorId) {
            if (isset($data['default_warehouse_id'])) {
                $this->assertWarehouse((string) $data['default_warehouse_id'], 'default_warehouse_id');
            }

            $shipment = new Shipment;
            $shipment->fill($data + ['status' => 'preparing'])->forceFill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Shipment, $this->today()),
                'created_by' => $actorId,
            ])->save();
            $this->audit->record('shipment.created', 'shipment', $shipment->id, [], ['number' => $shipment->number]);

            return $shipment->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data, ?int $version, ?string $actorId): Shipment
    {
        return $this->db->transaction(function () use ($id, $data, $version, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($id);

            if (in_array($shipment->status, ['closed', 'cancelled'], true)) {
                throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, 'modifier');
            }

            if ($version !== null && $version !== $shipment->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            if (isset($data['default_warehouse_id'])) {
                $this->assertWarehouse((string) $data['default_warehouse_id'], 'default_warehouse_id');
            }

            $shipment->fill($data)->forceFill(['version' => $shipment->version + 1, 'updated_by' => $actorId])->save();

            return $shipment->refresh();
        });
    }

    public function transition(string $id, string $to, ?string $date, ?string $actorId): Shipment
    {
        return $this->db->transaction(function () use ($id, $to, $date, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($id);

            if (! in_array($shipment->status, self::TRANSITIONS[$to] ?? [], true)) {
                throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, self::label($to));
            }

            $day = $date !== null ? CarbonImmutable::parse($date) : $this->today();

            match ($to) {
                'shipped' => $shipment->forceFill(['shipped_at' => $shipment->shipped_at ?? $day->toDateString()]),
                'arrived' => $shipment->forceFill(['arrived_at' => $day->toDateString()]),
                default => null,
            };

            if ($to === 'shipped' && ! $shipment->lines()->exists()) {
                throw BusinessRuleViolation::invalidField('lines', 'L\'arrivage ne contient aucun article attendu.');
            }

            if ($to === 'received') {
                // Clôture du reliquat : les quantités non reçues restent consultables comme manquants (IMP-005).
                if (GoodsReceipt::query()->where('shipment_id', $shipment->id)->whereIn('status', ['draft', 'counting'])->exists()) {
                    throw new BusinessRuleViolation('receipt_in_progress', 'Réception en cours', 'Une réception est encore ouverte sur cet arrivage.');
                }

                ShipmentLine::query()->where('shipment_id', $shipment->id)->where('is_closed', false)->update(['is_closed' => true]);
            }

            if ($to === 'closed') {
                if (! $this->closeGuard->canClose($shipment->id)) {
                    throw new BusinessRuleViolation('landed_cost_not_final', 'Coût de revient non définitif',
                        'L\'arrivage ne peut être clôturé qu\'après la finalisation de son coût de revient.');
                }

                $shipment->forceFill(['closed_at' => CarbonImmutable::now()]);
            }

            return $this->mark($shipment, $to, $actorId);
        });
    }

    /** Recalcule l'état de réception après l'ouverture, la validation ou l'annulation d'une réception. */
    public function refreshReceptionStatus(string $shipmentId, ?string $actorId): void
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

        if (! in_array($shipment->status, ['arrived', 'unloading', 'partially_received'], true)) {
            return;
        }

        $receipts = GoodsReceipt::query()->where('shipment_id', $shipment->id)->where('status', '<>', 'cancelled')->pluck('status');
        $open = $receipts->contains(fn ($s) => in_array($s, ['draft', 'counting'], true));
        $validated = $receipts->contains('validated');
        $done = $shipment->lines()->get()->every(fn (ShipmentLine $l) => $l->remaining() === 0);

        $status = match (true) {
            $done && ! $open && $validated => 'received',
            $open => 'unloading',
            $validated => 'partially_received',
            default => 'arrived',
        };

        if ($status !== $shipment->status) {
            $this->mark($shipment, $status, $actorId);
        }
    }

    /** @param array{container_number: string, container_type?: string|null, seal_number?: string|null} $data */
    public function addContainer(string $shipmentId, array $data, ?string $actorId): Container
    {
        return $this->db->transaction(function () use ($shipmentId, $data, $actorId) {
            $shipment = $this->lockEditable($shipmentId, 'ajouter un conteneur');
            $number = strtoupper(str_replace(' ', '', $data['container_number']));

            if (Container::query()->where('shipment_id', $shipment->id)->where('container_number', $number)->exists()) {
                throw BusinessRuleViolation::invalidField('container_number', 'Ce conteneur figure déjà dans l\'arrivage.', 'duplicate');
            }

            $container = new Container;
            $container->fill(['shipment_id' => $shipment->id, 'container_number' => $number] + $data)->forceFill(['container_number' => $number, 'created_by' => $actorId])->save();

            return $container;
        });
    }

    /**
     * Rattache une commande d'achat ; ses reliquats deviennent des articles attendus (option par défaut).
     */
    public function linkPurchaseOrder(string $shipmentId, string $purchaseOrderId, bool $withLines, ?string $actorId): Shipment
    {
        return $this->db->transaction(function () use ($shipmentId, $purchaseOrderId, $withLines, $actorId) {
            $shipment = $this->lockEditable($shipmentId, 'rattacher une commande');
            $lines = $this->orders->receivableLines($purchaseOrderId);
            $this->link($shipment->id, $purchaseOrderId);

            if ($withLines) {
                $already = ShipmentLine::query()->where('shipment_id', $shipment->id)->whereIn('purchase_order_line_id', array_map(fn ($l) => $l->id, $lines))
                    ->pluck('purchase_order_line_id')->all();

                foreach ($lines as $line) {
                    if ($line->remaining() > 0 && ! in_array($line->id, $already, true)) {
                        $this->insertLine($shipment, ['purchase_order_line_id' => $line->id], $actorId);
                    }
                }
            }

            return $shipment->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<ShipmentLine>
     */
    public function addLines(string $shipmentId, array $lines, ?string $actorId): array
    {
        return $this->db->transaction(function () use ($shipmentId, $lines, $actorId) {
            $shipment = $this->lockEditable($shipmentId, 'ajouter des articles');
            $created = [];

            foreach ($lines as $index => $line) {
                try {
                    $created[] = $this->insertLine($shipment, $line, $actorId);
                } catch (BusinessRuleViolation $e) {
                    throw self::prefixed($e, "lines/{$index}");
                }
            }

            return $created;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateLine(string $lineId, array $data, ?string $actorId): ShipmentLine
    {
        return $this->db->transaction(function () use ($lineId, $data, $actorId) {
            $line = ShipmentLine::query()->findOrFail($lineId);
            $shipment = $this->lockEditable($line->shipment_id, 'modifier un article');
            $line = ShipmentLine::query()->lockForUpdate()->findOrFail($line->id);
            $this->applyLineFields($shipment, $line, $data);
            $line->forceFill(['updated_by' => $actorId])->save();

            return $line;
        });
    }

    public function deleteLine(string $lineId): void
    {
        $this->db->transaction(function () use ($lineId) {
            $line = ShipmentLine::query()->findOrFail($lineId);
            $this->lockEditable($line->shipment_id, 'retirer un article');
            $line->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function insertLine(Shipment $shipment, array $data, ?string $actorId): ShipmentLine
    {
        $line = new ShipmentLine;
        $line->forceFill([
            'shipment_id' => $shipment->id,
            'position' => ((int) ShipmentLine::query()->where('shipment_id', $shipment->id)->max('position')) + 1,
            'created_by' => $actorId,
        ]);

        if (isset($data['purchase_order_line_id'])) {
            $poLine = $this->orders->lines([(string) $data['purchase_order_line_id']])[(string) $data['purchase_order_line_id']]
                ?? throw BusinessRuleViolation::invalidField('purchase_order_line_id', 'Cette ligne de commande n\'existe pas.');
            $order = $this->orders->summary($poLine->purchaseOrderId);

            if ($order === null || ! in_array($order['status'], ['confirmed', 'partially_received'], true)) {
                throw BusinessRuleViolation::invalidField('purchase_order_line_id', 'La commande doit être confirmée.');
            }

            $this->link($shipment->id, $poLine->purchaseOrderId);
            $line->forceFill([
                'purchase_order_line_id' => $poLine->id,
                'supplier_id' => $poLine->supplierId,
                'variant_id' => $poLine->variantId,
                'purchase_currency' => $poLine->currency,
                'purchase_unit_price' => $poLine->unitPricePerPiece,
                'qty_expected' => $poLine->remaining() > 0 ? $poLine->remaining() : $poLine->qtyOrdered,
                'pieces_per_carton' => $poLine->unitFactor > 1 ? $poLine->unitFactor : null,
                'cartons' => $poLine->unitFactor > 1 && $poLine->remaining() % $poLine->unitFactor === 0 ? intdiv($poLine->remaining(), $poLine->unitFactor) : null,
            ]);
            unset($data['purchase_order_line_id'], $data['variant_id'], $data['supplier_id'], $data['purchase_currency'], $data['purchase_unit_price']);
        } else {
            foreach (['variant_id', 'purchase_currency', 'purchase_unit_price'] as $required) {
                if (! isset($data[$required])) {
                    throw BusinessRuleViolation::invalidField($required, 'Ce champ est obligatoire pour un article sans commande.', 'required');
                }
            }
        }

        $line->forceFill(['destination_warehouse_id' => $data['destination_warehouse_id'] ?? $shipment->default_warehouse_id]);
        $this->applyLineFields($shipment, $line, $data);
        $line->save();

        return $line;
    }

    /** @param array<string, mixed> $data */
    private function applyLineFields(Shipment $shipment, ShipmentLine $line, array $data): void
    {
        if (isset($data['variant_id'])) {
            if ($line->purchase_order_line_id !== null) {
                throw BusinessRuleViolation::invalidField('variant_id', 'L\'article d\'une ligne de commande ne se modifie pas.');
            }

            $variant = $this->catalog->describe([(string) $data['variant_id']])[(string) $data['variant_id']] ?? null;

            if ($variant === null || ! $variant->isStocked()) {
                throw BusinessRuleViolation::invalidField('variant_id', 'Cette variante n\'existe pas ou n\'est pas stockée.');
            }

            $line->variant_id = $variant->id;
        }

        if (array_key_exists('supplier_id', $data) && $line->purchase_order_line_id === null) {
            if ($data['supplier_id'] !== null && $this->suppliers->find((string) $data['supplier_id']) === null) {
                throw BusinessRuleViolation::invalidField('supplier_id', 'Ce fournisseur n\'existe pas.');
            }

            $line->supplier_id = $data['supplier_id'] === null ? null : (string) $data['supplier_id'];
        }

        foreach (['purchase_currency', 'container_id', 'destination_warehouse_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $line->setAttribute($field, $data[$field]);
            }
        }

        if (isset($data['purchase_unit_price'])) {
            $line->purchase_unit_price = (string) Decimal::of((string) $data['purchase_unit_price'])->toScale(8, RoundingMode::HalfUp);
        }

        foreach (['cartons', 'pieces_per_carton', 'qty_expected'] as $field) {
            if (array_key_exists($field, $data)) {
                $line->setAttribute($field, $data[$field] === null ? null : (int) $data[$field]);
            }
        }

        if (! isset($data['qty_expected']) && isset($data['cartons'], $data['pieces_per_carton'])) {
            $line->qty_expected = (int) $data['cartons'] * (int) $data['pieces_per_carton'];
        }

        // CR-009 : le volume de la ligne est total ; un CBM par carton est converti explicitement.
        if (array_key_exists('cbm_total', $data)) {
            $line->cbm_total = $data['cbm_total'] === null ? null : (string) Decimal::of((string) $data['cbm_total'])->toScale(6, RoundingMode::HalfUp);
        } elseif (isset($data['cbm_per_carton'])) {
            $cartons = $line->cartons ?? throw BusinessRuleViolation::invalidField('cartons', 'Le nombre de cartons est requis pour convertir un CBM par carton.');
            $line->cbm_total = (string) Decimal::of((string) $data['cbm_per_carton'])->multipliedBy($cartons)->toScale(6, RoundingMode::HalfUp);
        }

        if ($line->qty_expected <= 0) {
            throw BusinessRuleViolation::invalidField('qty_expected', 'La quantité attendue doit être positive.');
        }

        if ($line->container_id !== null && ! Container::query()->whereKey($line->container_id)->where('shipment_id', $shipment->id)->exists()) {
            throw BusinessRuleViolation::invalidField('container_id', 'Ce conteneur n\'appartient pas à l\'arrivage.');
        }

        if ($line->getAttribute('destination_warehouse_id') === null) {
            throw BusinessRuleViolation::invalidField('destination_warehouse_id', 'Indiquez le dépôt de destination.', 'required');
        }

        $this->assertWarehouse($line->destination_warehouse_id, 'destination_warehouse_id');
        $line->purchase_amount = (string) Decimal::of($line->purchase_unit_price)->multipliedBy($line->qty_expected)->toScale(4, RoundingMode::HalfUp);
    }

    private function link(string $shipmentId, string $purchaseOrderId): void
    {
        $this->db->table('shipment_purchase_orders')->insertOrIgnore([
            'company_id' => $this->tenant->requireCompanyId(),
            'shipment_id' => $shipmentId,
            'purchase_order_id' => $purchaseOrderId,
        ]);
    }

    private function lockEditable(string $shipmentId, string $action): Shipment
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

        if (! in_array($shipment->status, self::EDITABLE, true)) {
            throw BusinessRuleViolation::invalidTransition('l\'arrivage', $shipment->status, $action);
        }

        return $shipment;
    }

    private function assertWarehouse(string $warehouseId, string $field): void
    {
        if (! $this->warehouses->isActive($warehouseId)) {
            throw BusinessRuleViolation::invalidField($field, 'Ce dépôt n\'existe pas ou est inactif.');
        }
    }

    private function mark(Shipment $shipment, string $status, ?string $actorId): Shipment
    {
        $previous = $shipment->status;
        $shipment->forceFill(['status' => $status, 'version' => $shipment->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("shipment.{$status}", 'shipment', $shipment->id, ['status' => $previous], ['status' => $status, 'number' => $shipment->number]);

        return $shipment->refresh();
    }

    private static function label(string $to): string
    {
        return match ($to) {
            'shipped' => 'marquer expédié',
            'in_transit' => 'marquer en transit',
            'arrived' => 'marquer arrivé',
            'received' => 'clôturer le reliquat',
            'closed' => 'clôturer',
            'cancelled' => 'annuler',
            default => $to,
        };
    }

    private static function prefixed(BusinessRuleViolation $e, string $prefix): BusinessRuleViolation
    {
        $errors = array_map(fn (array $err) => isset($err['pointer']) ? ['pointer' => "/{$prefix}".$err['pointer']] + $err : $err, $e->errors);

        return new BusinessRuleViolation($e->errorCode, $e->title, $e->getMessage(), $e->httpStatus, $e->meta, $errors);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
