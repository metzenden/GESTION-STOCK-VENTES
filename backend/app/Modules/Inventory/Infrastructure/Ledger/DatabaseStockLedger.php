<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Ledger;

use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\FifoAllocator;
use App\Modules\Inventory\Domain\ValueObjects\LotCandidate;
use App\Modules\Inventory\Domain\ValueObjects\StockBuckets;
use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * Grand livre de stock sur MySQL. Les projections (stock_positions, stock_lot_balances) sont mises à jour
 * sous verrou dans la même transaction que les mouvements ; les CHECK de la base restent la dernière défense.
 */
final class DatabaseStockLedger implements StockLedger
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
        private readonly DocumentNumberAllocator $numbers,
        private readonly VariantCatalog $catalog,
        private readonly FifoAllocator $fifo,
    ) {}

    public function createLot(string $variantId, string $originType, ?string $originId, DateTimeInterface $receivedAt, int $quantity, string $unitCost, string $currency, string $costStatus = 'final', ?string $shipmentId = null): string
    {
        $this->assertTransaction();
        $companyId = $this->tenant->requireCompanyId();
        $id = $this->ulid();

        $this->db->table('stock_lots')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'lot_number' => $this->numbers->next($companyId, DocumentType::StockLot, CarbonImmutable::instance($receivedAt)),
            'variant_id' => $variantId,
            'origin_type' => $originType,
            'origin_id' => $originId,
            'shipment_id' => $shipmentId,
            'received_at' => self::ts($receivedAt),
            'initial_qty' => $quantity,
            'unit_cost' => (string) BigDecimal::of($unitCost)->toScale(8, RoundingMode::HalfUp),
            'cost_currency' => $currency,
            'cost_status' => $costStatus,
            'created_by' => $this->actor(),
        ]);

        return $id;
    }

    public function receive(string $warehouseId, string $lotId, int $quantity, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string
    {
        $type = MovementType::from($movementType);

        if (! $type->isInbound()) {
            throw new LogicException("Le mouvement {$movementType} n'est pas une entrée.");
        }

        $lot = $this->lot($lotId);
        [$position] = $this->lockPositions([[$warehouseId, $lot->variant_id]]);
        $balance = $this->lockLotBalance($warehouseId, $lot);

        $this->savePosition($position, $this->buckets($position)->receive($quantity));
        $this->saveLotBalance($balance, (int) $balance->qty_on_hand + $quantity, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery);

        return $this->insertMovement($warehouseId, $lot, $type, $quantity, $source, $occurredAt);
    }

    public function issue(string $warehouseId, string $lotId, int $quantity, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string
    {
        $type = MovementType::from($movementType);

        if (! in_array($type, [MovementType::AdjustmentOut, MovementType::Loss], true)) {
            throw new LogicException("Le mouvement {$movementType} ne peut pas être prélevé sur le disponible.");
        }

        $lot = $this->lot($lotId);
        [$position] = $this->lockPositions([[$warehouseId, $lot->variant_id]]);
        $balance = $this->lockLotBalance($warehouseId, $lot);

        // INV-007 : le lot comme la position doivent avoir assez de disponible (non réservé, non bloqué, non en livraison).
        $this->assertLotFree($balance, $quantity, $lot);
        $this->savePosition($position, $this->buckets($position)->issue($quantity));
        $this->saveLotBalance($balance, (int) $balance->qty_on_hand - $quantity, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery);

        return $this->insertMovement($warehouseId, $lot, $type, -$quantity, $source, $occurredAt);
    }

    public function reserve(string $warehouseId, string $variantId, int $quantity, string $sourceType, string $sourceId, bool $provisional = false): string
    {
        [$position] = $this->lockPositions([[$warehouseId, $variantId]]);
        $buckets = $this->buckets($position);

        try {
            $after = $buckets->reserve($quantity);
        } catch (BusinessRuleViolation $e) {
            throw BusinessRuleViolation::insufficientStock($buckets->available(), $quantity, ['variant_id' => $variantId, 'warehouse_id' => $warehouseId]);
        }

        $id = $this->ulid();
        $this->db->table('stock_reservations')->insert([
            'id' => $id,
            'company_id' => $this->tenant->requireCompanyId(),
            'warehouse_id' => $warehouseId,
            'variant_id' => $variantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'qty_reserved' => $quantity,
            'is_provisional' => $provisional,
            'created_by' => $this->actor(),
        ]);
        $this->savePosition($position, $after);

        return $id;
    }

    public function release(string $sourceType, string $sourceId, ?int $quantity = null): void
    {
        $reservation = $this->reservation($sourceType, $sourceId);
        [$position] = $this->lockPositions([[$reservation->warehouse_id, $reservation->variant_id]]);
        $reservation = $this->reservation($sourceType, $sourceId, lock: true);
        $active = (int) $reservation->qty_active;
        $qty = $quantity ?? $active;

        if ($qty <= 0 || $qty > $active) {
            throw BusinessRuleViolation::invalidField('quantity', "Quantité à libérer invalide (réservation active : {$active}).");
        }

        $this->savePosition($position, $this->buckets($position)->release($qty));
        $this->updateReservation($reservation, released: $qty);
    }

    public function ship(string $sourceType, string $sourceId, array $allocations): void
    {
        $qty = $this->total($allocations);
        $reservation = $this->reservation($sourceType, $sourceId);
        [$position] = $this->lockPositions([[$reservation->warehouse_id, $reservation->variant_id]]);
        $reservation = $this->reservation($sourceType, $sourceId, lock: true);

        if ($qty > (int) $reservation->qty_active) {
            throw BusinessRuleViolation::invalidField('quantity', 'La quantité expédiée dépasse la quantité réservée.');
        }

        foreach ($this->lockLotBalances((string) $reservation->warehouse_id, (string) $reservation->variant_id, $allocations) as $lotId => [$lot, $balance]) {
            $this->assertLotFree($balance, $allocations[$lotId], $lot);
            $this->saveLotBalance($balance, (int) $balance->qty_on_hand, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery + $allocations[$lotId]);
        }

        $this->savePosition($position, $this->buckets($position)->ship($qty));
        $this->updateReservation($reservation, consumed: $qty);
    }

    public function deliver(string $warehouseId, string $variantId, array $allocations, StockSource $source, DateTimeInterface $occurredAt): void
    {
        $qty = $this->total($allocations);
        [$position] = $this->lockPositions([[$warehouseId, $variantId]]);

        foreach ($this->lockLotBalances($warehouseId, $variantId, $allocations) as $lotId => [$lot, $balance]) {
            $take = $allocations[$lotId];

            if ($take > (int) $balance->qty_in_delivery) {
                throw new LogicException("Lot {$lot->lot_number} : quantité remise supérieure à la quantité en livraison.");
            }

            $this->saveLotBalance($balance, (int) $balance->qty_on_hand - $take, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery - $take);
            $this->insertMovement($warehouseId, $lot, MovementType::SaleDelivery, -$take, $source, $occurredAt);
        }

        $this->savePosition($position, $this->buckets($position)->deliver($qty));
    }

    public function issueReserved(string $sourceType, string $sourceId, array $allocations, string $movementType, StockSource $source, DateTimeInterface $occurredAt): void
    {
        $qty = $this->total($allocations);
        $reservation = $this->reservation($sourceType, $sourceId);
        $warehouseId = (string) $reservation->warehouse_id;
        [$position] = $this->lockPositions([[$warehouseId, $reservation->variant_id]]);
        $reservation = $this->reservation($sourceType, $sourceId, lock: true);

        if ($qty > (int) $reservation->qty_active) {
            throw BusinessRuleViolation::invalidField('quantity', 'La quantité sortie dépasse la quantité réservée.');
        }

        foreach ($this->lockLotBalances($warehouseId, (string) $reservation->variant_id, $allocations) as $lotId => [$lot, $balance]) {
            $take = $allocations[$lotId];
            $this->assertLotFree($balance, $take, $lot);
            $this->saveLotBalance($balance, (int) $balance->qty_on_hand - $take, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery);
            $this->insertMovement($warehouseId, $lot, MovementType::from($movementType), -$take, new StockSource($source->type, $source->id, $source->lineId, $source->reason), $occurredAt);
        }

        $this->savePosition($position, $this->buckets($position)->issueReserved($qty));
        $this->updateReservation($reservation, consumed: $qty);
    }

    public function hold(string $warehouseId, string $lotId, int $quantity, string $reason, string $sourceType, string $sourceId, ?string $note = null): string
    {
        $lot = $this->lot($lotId);
        [$position] = $this->lockPositions([[$warehouseId, $lot->variant_id]]);
        $balance = $this->lockLotBalance($warehouseId, $lot);

        $this->assertLotFree($balance, $quantity, $lot);
        $this->savePosition($position, $this->buckets($position)->hold($quantity));
        $this->saveLotBalance($balance, (int) $balance->qty_on_hand, (int) $balance->qty_blocked + $quantity, (int) $balance->qty_in_delivery);

        $id = $this->ulid();
        $this->db->table('stock_holds')->insert([
            'id' => $id,
            'company_id' => $this->tenant->requireCompanyId(),
            'warehouse_id' => $warehouseId,
            'variant_id' => $lot->variant_id,
            'lot_id' => $lot->id,
            'reason' => $reason,
            'qty_held' => $quantity,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'created_by' => $this->actor(),
        ]);

        return $id;
    }

    public function releaseHold(string $holdId): void
    {
        [$hold, $lot, $position, $balance] = $this->lockHold($holdId);
        $qty = (int) $hold->qty_active;

        $this->savePosition($position, $this->buckets($position)->releaseHold($qty));
        $this->saveLotBalance($balance, (int) $balance->qty_on_hand, (int) $balance->qty_blocked - $qty, (int) $balance->qty_in_delivery);
        $this->db->table('stock_holds')->where('id', $hold->id)->update(['qty_released' => (int) $hold->qty_released + $qty, 'status' => 'closed']);
    }

    public function consumeHold(string $holdId, string $movementType, StockSource $source, DateTimeInterface $occurredAt): string
    {
        [$hold, $lot, $position, $balance] = $this->lockHold($holdId);
        $qty = (int) $hold->qty_active;

        $this->savePosition($position, $this->buckets($position)->issueHeld($qty));
        $this->saveLotBalance($balance, (int) $balance->qty_on_hand - $qty, (int) $balance->qty_blocked - $qty, (int) $balance->qty_in_delivery);
        $this->db->table('stock_holds')->where('id', $hold->id)->update(['qty_consumed' => (int) $hold->qty_consumed + $qty, 'status' => 'closed']);

        return $this->insertMovement((string) $hold->warehouse_id, $lot, MovementType::from($movementType), -$qty, $source, $occurredAt);
    }

    public function reverse(string $movementId, string $reason, DateTimeInterface $occurredAt): string
    {
        $original = $this->db->table('stock_movements')->where('company_id', $this->tenant->requireCompanyId())->where('id', $movementId)->first()
            ?? throw new LogicException('Mouvement introuvable.');

        if ($original->reverses_movement_id !== null) {
            throw BusinessRuleViolation::invalidField('movement_id', 'Une contre-écriture ne se contre-passe pas : enregistrez un nouveau mouvement.');
        }

        $lot = $this->lot((string) $original->lot_id);
        [$position] = $this->lockPositions([[$original->warehouse_id, $original->variant_id]]);
        $balance = $this->lockLotBalance((string) $original->warehouse_id, $lot);
        $qty = -(int) $original->quantity;

        if ($qty < 0) {
            $this->assertLotFree($balance, -$qty, $lot);
            $this->savePosition($position, $this->buckets($position)->issue(-$qty));
        } else {
            $this->savePosition($position, $this->buckets($position)->receive($qty));
        }

        $this->saveLotBalance($balance, (int) $balance->qty_on_hand + $qty, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery);

        $seq = 1 + (int) $this->db->table('stock_movements')->where('company_id', $original->company_id)->where('reverses_movement_id', $original->id)->max('reversal_seq');

        return $this->insertMovement((string) $original->warehouse_id, $lot, MovementType::Reversal, $qty,
            new StockSource((string) $original->source_type, (string) $original->source_id, (string) $original->source_line_id, $reason),
            $occurredAt, reversalSeq: $seq, reverses: (string) $original->id);
    }

    public function allocateFifo(string $warehouseId, string $variantId, int $quantity): array
    {
        return $this->fifo->allocate($quantity, $this->candidates($warehouseId, $variantId));
    }

    /** @return list<LotCandidate> */
    public function candidates(string $warehouseId, string $variantId): array
    {
        return array_values($this->db->table('stock_lot_balances as b')
            ->join('stock_lots as l', fn ($j) => $j->on('l.id', '=', 'b.lot_id')->on('l.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $this->tenant->requireCompanyId())
            ->where('b.warehouse_id', $warehouseId)
            ->where('b.variant_id', $variantId)
            ->whereRaw('b.qty_on_hand - b.qty_blocked - b.qty_in_delivery > 0')
            ->orderBy('l.received_at')->orderBy('l.lot_number')
            ->get(['l.id', 'l.lot_number', 'l.received_at', 'b.qty_on_hand', 'b.qty_blocked', 'b.qty_in_delivery'])
            ->map(fn (stdClass $r) => new LotCandidate((string) $r->id, (string) $r->lot_number, CarbonImmutable::parse((string) $r->received_at)->toIso8601ZuluString('microsecond'), (int) $r->qty_on_hand - (int) $r->qty_blocked - (int) $r->qty_in_delivery))
            ->all());
    }

    /**
     * Verrouille (et crée au besoin) les positions, toujours dans l'ordre (dépôt, variante) croissant (D-056).
     *
     * @param  list<array{0: string, 1: string}>  $keys
     * @return list<stdClass> dans l'ordre des clés demandées
     */
    private function lockPositions(array $keys): array
    {
        $this->assertTransaction();
        $companyId = $this->tenant->requireCompanyId();
        $sorted = $keys;
        usort($sorted, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $locked = [];

        foreach ($sorted as [$warehouseId, $variantId]) {
            $key = ['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'variant_id' => $variantId];
            // Verrou exclusif d'abord : un INSERT IGNORE sur une ligne existante poserait un verrou partagé
            // qui, sous contention, provoque un interblocage au moment de passer en exclusif.
            $position = $this->db->table('stock_positions')->where($key)->lockForUpdate()->first();

            if ($position === null) {
                $this->db->table('stock_positions')->insertOrIgnore(['id' => $this->ulid()] + $key);
                $position = $this->db->table('stock_positions')->where($key)->lockForUpdate()->first();
            }

            $locked["{$warehouseId}|{$variantId}"] = $position ?? throw new LogicException('Position de stock introuvable.');
        }

        return array_map(fn ($k) => $locked["{$k[0]}|{$k[1]}"], $keys);
    }

    private function lockLotBalance(string $warehouseId, stdClass $lot): stdClass
    {
        $key = ['company_id' => $this->tenant->requireCompanyId(), 'warehouse_id' => $warehouseId, 'lot_id' => $lot->id];
        $balance = $this->db->table('stock_lot_balances')->where($key)->lockForUpdate()->first();

        if ($balance === null) {
            $this->db->table('stock_lot_balances')->insertOrIgnore(['id' => $this->ulid(), 'variant_id' => $lot->variant_id] + $key);
            $balance = $this->db->table('stock_lot_balances')->where($key)->lockForUpdate()->first();
        }

        return $balance ?? throw new LogicException('Solde de lot introuvable.');
    }

    /**
     * @param  array<string, int>  $allocations
     * @return array<string, array{0: stdClass, 1: stdClass}> lot → [lot, solde verrouillé], ordre des lots croissant
     */
    private function lockLotBalances(string $warehouseId, string $variantId, array $allocations): array
    {
        $ids = array_keys($allocations);
        sort($ids);
        $result = [];

        foreach ($ids as $lotId) {
            $lot = $this->lot($lotId);

            if ($lot->variant_id !== $variantId) {
                throw BusinessRuleViolation::invalidField('allocations', "Le lot {$lot->lot_number} ne correspond pas à la variante.");
            }

            if ($allocations[$lotId] <= 0) {
                throw BusinessRuleViolation::invalidField('allocations', 'Chaque quantité affectée doit être positive.');
            }

            $result[$lotId] = [$lot, $this->lockLotBalance($warehouseId, $lot)];
        }

        return $result;
    }

    /** @return array{0: stdClass, 1: stdClass, 2: stdClass, 3: stdClass} */
    private function lockHold(string $holdId): array
    {
        $companyId = $this->tenant->requireCompanyId();
        $hold = $this->db->table('stock_holds')->where(['company_id' => $companyId, 'id' => $holdId])->first() ?? throw new LogicException('Blocage introuvable.');
        $lot = $this->lot((string) $hold->lot_id);
        [$position] = $this->lockPositions([[$hold->warehouse_id, $hold->variant_id]]);
        $balance = $this->lockLotBalance((string) $hold->warehouse_id, $lot);
        $hold = $this->db->table('stock_holds')->where(['company_id' => $companyId, 'id' => $holdId])->lockForUpdate()->first();

        if ($hold === null || $hold->status !== 'active' || (int) $hold->qty_active <= 0) {
            throw BusinessRuleViolation::alreadyProcessed('Ce blocage est déjà levé ou consommé.');
        }

        return [$hold, $lot, $position, $balance];
    }

    public function finalizeLotCost(string $lotId, string $unitCost, string $calculationId, array $breakdown): string
    {
        $this->assertTransaction();
        $lot = $this->db->table('stock_lots')->where('company_id', $this->tenant->requireCompanyId())->where('id', $lotId)->lockForUpdate()->first()
            ?? throw BusinessRuleViolation::invalidField('lot_id', 'Ce lot n\'existe pas.');

        if ($lot->cost_status !== 'provisional') {
            throw BusinessRuleViolation::alreadyProcessed("Le lot {$lot->lot_number} a déjà un coût définitif.");
        }

        $this->db->table('stock_lots')->where('company_id', $lot->company_id)->where('id', $lot->id)->update([
            'unit_cost' => (string) BigDecimal::of($unitCost)->toScale(8, RoundingMode::HalfUp),
            'cost_status' => 'final',
            'landed_cost_calculation_id' => $calculationId,
            'cost_breakdown' => json_encode($breakdown + ['provisional_unit_cost' => (string) $lot->unit_cost], JSON_THROW_ON_ERROR),
            'updated_at' => self::ts(CarbonImmutable::now()),
            'updated_by' => $this->actor(),
        ]);

        return (string) $lot->unit_cost;
    }

    public function valuedMovements(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return array_values($this->db->table('stock_movements')->where('company_id', $this->tenant->requireCompanyId())
            ->whereIn('lot_id', $lotIds)
            ->whereNotIn('movement_type', [MovementType::Receipt->value, MovementType::TransferIn->value, MovementType::TransferOut->value])
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'lot_id', 'movement_type', 'quantity', 'unit_cost'])
            ->map(fn (stdClass $m) => ['id' => (string) $m->id, 'lot_id' => (string) $m->lot_id, 'movement_type' => (string) $m->movement_type,
                'quantity' => (int) $m->quantity, 'unit_cost' => (string) $m->unit_cost])
            ->all());
    }

    public function failDelivery(string $sourceType, string $sourceId, array $allocations): void
    {
        $qty = $this->total($allocations);
        $reservation = $this->reservation($sourceType, $sourceId);
        [$position] = $this->lockPositions([[$reservation->warehouse_id, $reservation->variant_id]]);
        // Une réservation entièrement expédiée est close : l'échec de livraison la rouvre.
        $reservation = $this->reservation($sourceType, $sourceId, lock: true, includeClosed: true);

        if ($qty > (int) $reservation->qty_consumed) {
            throw new LogicException('Quantité retournée supérieure à la quantité expédiée.');
        }

        foreach ($this->lockLotBalances((string) $reservation->warehouse_id, (string) $reservation->variant_id, $allocations) as $lotId => [$lot, $balance]) {
            if ($allocations[$lotId] > (int) $balance->qty_in_delivery) {
                throw new LogicException("Lot {$lot->lot_number} : quantité supérieure à la quantité en livraison.");
            }

            $this->saveLotBalance($balance, (int) $balance->qty_on_hand, (int) $balance->qty_blocked, (int) $balance->qty_in_delivery - $allocations[$lotId]);
        }

        $this->savePosition($position, $this->buckets($position)->failDelivery($qty));
        $this->db->table('stock_reservations')->where('id', $reservation->id)->update([
            'qty_consumed' => (int) $reservation->qty_consumed - $qty,
            'status' => 'active',
            'updated_by' => $this->actor(),
        ]);
    }

    public function discardReservation(string $sourceType, string $sourceId): void
    {
        $reservation = $this->reservation($sourceType, $sourceId);

        if ((int) $reservation->qty_consumed > 0) {
            throw new LogicException('Une réservation déjà consommée ne peut pas être effacée.');
        }

        if ((int) $reservation->qty_active > 0) {
            $this->release($sourceType, $sourceId);
        }

        $this->db->table('stock_reservations')->where('id', $reservation->id)->delete();
    }

    public function confirmReservation(string $sourceType, string $sourceId): void
    {
        $this->db->table('stock_reservations')->where(['company_id' => $this->tenant->requireCompanyId(), 'source_type' => $sourceType, 'source_id' => $sourceId])
            ->update(['is_provisional' => false, 'updated_by' => $this->actor()]);
    }

    public function estimateCost(string $warehouseId, string $variantId, int $quantity): ?string
    {
        $candidates = $this->candidates($warehouseId, $variantId);

        if ($candidates === [] || $quantity <= 0) {
            return null;
        }

        $costs = $this->lotCosts(array_map(fn (LotCandidate $c) => $c->lotId, $candidates));
        $remaining = $quantity;
        $total = BigDecimal::zero();
        $counted = 0;

        foreach ($candidates as $candidate) {
            $take = min($remaining, $candidate->free);
            $total = $total->plus(BigDecimal::of($costs[$candidate->lotId] ?? '0')->multipliedBy($take));
            $counted += $take;
            $remaining -= $take;

            if ($remaining === 0) {
                break;
            }
        }

        return $counted === 0 ? null : (string) $total->dividedBy($counted, 8, RoundingMode::HalfUp);
    }

    public function lotCosts(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return $this->db->table('stock_lots')->where('company_id', $this->tenant->requireCompanyId())->whereIn('id', $lotIds)
            ->pluck('unit_cost', 'id')->map(fn ($c) => (string) $c)->all();
    }

    private function lot(string $lotId): stdClass
    {
        return $this->db->table('stock_lots')->where('company_id', $this->tenant->requireCompanyId())->where('id', $lotId)->first()
            ?? throw BusinessRuleViolation::invalidField('lot_id', 'Ce lot n\'existe pas.');
    }

    private function reservation(string $sourceType, string $sourceId, bool $lock = false, bool $includeClosed = false): stdClass
    {
        $query = $this->db->table('stock_reservations')
            ->where(['company_id' => $this->tenant->requireCompanyId(), 'source_type' => $sourceType, 'source_id' => $sourceId]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $reservation = $query->first();

        if ($reservation === null || ($lock && ! $includeClosed && $reservation->status !== 'active')) {
            throw BusinessRuleViolation::alreadyProcessed('Aucune réservation active pour ce document.');
        }

        return $reservation;
    }

    private function updateReservation(stdClass $reservation, int $consumed = 0, int $released = 0): void
    {
        $newConsumed = (int) $reservation->qty_consumed + $consumed;
        $newReleased = (int) $reservation->qty_released + $released;
        $closed = $newConsumed + $newReleased >= (int) $reservation->qty_reserved;

        $this->db->table('stock_reservations')->where('id', $reservation->id)->update([
            'qty_consumed' => $newConsumed,
            'qty_released' => $newReleased,
            'status' => $closed ? 'closed' : 'active',
            'updated_by' => $this->actor(),
        ]);
    }

    private function assertLotFree(stdClass $balance, int $quantity, stdClass $lot): void
    {
        $free = (int) $balance->qty_on_hand - (int) $balance->qty_blocked - (int) $balance->qty_in_delivery;

        if ($quantity > $free) {
            throw BusinessRuleViolation::insufficientStock($free, $quantity, ['lot_id' => $lot->id, 'lot_number' => $lot->lot_number]);
        }
    }

    private function buckets(stdClass $position): StockBuckets
    {
        return new StockBuckets((int) $position->qty_on_hand, (int) $position->qty_reserved, (int) $position->qty_blocked, (int) $position->qty_in_delivery);
    }

    private function savePosition(stdClass $position, StockBuckets $buckets, bool $moved = true): void
    {
        $changes = [
            'qty_on_hand' => $buckets->onHand,
            'qty_reserved' => $buckets->reserved,
            'qty_blocked' => $buckets->blocked,
            'qty_in_delivery' => $buckets->inDelivery,
        ];

        if ($moved && $buckets->onHand !== (int) $position->qty_on_hand) {
            $changes['last_movement_at'] = self::ts(CarbonImmutable::now());
        }

        $this->db->table('stock_positions')->where('id', $position->id)->update($changes);
    }

    private function saveLotBalance(stdClass $balance, int $onHand, int $blocked, int $inDelivery): void
    {
        $this->db->table('stock_lot_balances')->where('id', $balance->id)->update([
            'qty_on_hand' => $onHand, 'qty_blocked' => $blocked, 'qty_in_delivery' => $inDelivery,
        ]);
    }

    private function insertMovement(string $warehouseId, stdClass $lot, MovementType $type, int $quantity, StockSource $source, DateTimeInterface $occurredAt, int $reversalSeq = 0, ?string $reverses = null): string
    {
        $unitCost = BigDecimal::of((string) $lot->unit_cost);
        $id = $this->ulid();

        $this->db->table('stock_movements')->insert([
            'id' => $id,
            'company_id' => $this->tenant->requireCompanyId(),
            'warehouse_id' => $warehouseId,
            'variant_id' => $lot->variant_id,
            'lot_id' => $lot->id,
            'movement_type' => $type->value,
            'quantity' => $quantity,
            'unit_cost' => (string) $unitCost,
            'total_cost' => (string) $unitCost->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp),
            'source_type' => $source->type,
            'source_id' => $source->id,
            'source_line_id' => $source->lineId,
            'reversal_seq' => $reversalSeq,
            'reverses_movement_id' => $reverses,
            'reason' => $source->reason,
            'occurred_at' => self::ts($occurredAt),
            'created_by' => $this->actor(),
        ]);

        // CAT-006 : un produit qui a bougé ne peut plus être supprimé.
        $this->catalog->markHistory([(string) $lot->variant_id]);

        return $id;
    }

    /** @param array<string, int> $allocations */
    private function total(array $allocations): int
    {
        if ($allocations === []) {
            throw BusinessRuleViolation::invalidField('allocations', 'Aucune quantité affectée.');
        }

        return array_sum($allocations);
    }

    private function actor(): string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof CompanyMember) {
            throw new LogicException('Opération de stock sans utilisateur identifié.');
        }

        return $user->memberId();
    }

    private function assertTransaction(): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Les opérations de stock doivent s\'exécuter dans une transaction.');
        }
    }

    /** Horodatage UTC à la microseconde (le constructeur de requêtes tronquerait à la seconde). */
    private static function ts(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:s.u');
    }

    private function ulid(): string
    {
        return strtoupper((string) Str::ulid());
    }
}
