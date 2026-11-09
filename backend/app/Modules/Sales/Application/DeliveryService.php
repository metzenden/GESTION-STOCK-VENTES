<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Sales\Infrastructure\Models\Delivery;
use App\Modules\Sales\Infrastructure\Models\DeliveryLine;
use App\Modules\Sales\Infrastructure\Models\DeliveryLineLot;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Sales\Infrastructure\Models\SalesOrderLine;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Livraisons (L4-06, L4-07, docs/04 §8.3) : préparation → prête → en livraison → remise, ou retrait sur place.
 * L'expédition passe le réservé « en livraison » sur des lots affectés (FIFO proposé, D-011) ; la remise constate
 * la sortie sale_delivery au coût du lot ; un échec ramène la marchandise au réservé (DLV-005).
 */
final class DeliveryService
{
    public const SOURCE = 'delivery';

    private const ACTIVE = ['preparing', 'ready', 'in_delivery', 'failed'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly StockLedger $ledger,
        private readonly SalesOrderService $orders,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * DLV-001 : tout ou partie des quantités restantes ; plusieurs livraisons par commande.
     *
     * @param  array<string, int>|null  $quantities  ligne de commande → quantité (null = tout le restant)
     * @param  array<string, mixed>  $data
     */
    public function create(string $orderId, ?array $quantities, array $data, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($orderId, $quantities, $data, $actorId) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);

            if (! in_array($order->status, SalesOrderService::OPEN, true)) {
                throw BusinessRuleViolation::invalidTransition('la commande', $order->status, 'préparer une livraison');
            }

            $pending = $this->pendingQuantities($order->id);
            $lines = $order->lines()->get()->filter(fn (SalesOrderLine $l) => $l->isStocked())->keyBy('id');
            $plan = [];

            foreach ($quantities ?? $lines->map(fn (SalesOrderLine $l) => $l->remaining() - ($pending[$l->id] ?? 0))->all() as $lineId => $qty) {
                $line = $lines->get((string) $lineId) ?? throw BusinessRuleViolation::invalidField("lines/{$lineId}", 'Cette ligne n\'appartient pas à la commande ou n\'est pas un article stocké.');
                $free = $line->remaining() - ($pending[$line->id] ?? 0);

                if ($qty > $free) {
                    throw BusinessRuleViolation::invalidField("lines/{$line->id}", "Il reste {$free} à livrer sur la ligne {$line->position}.");
                }

                if ($qty > 0) {
                    $plan[$line->id] = [$line, (int) $qty];
                }
            }

            if ($plan === []) {
                throw new BusinessRuleViolation('nothing_to_deliver', 'Rien à livrer', 'Toutes les quantités de la commande sont déjà livrées ou en cours de livraison.');
            }

            $delivery = new Delivery;
            $delivery->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Delivery, $this->orders->today()),
                'sales_order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'mode' => $data['mode'] ?? $order->fulfilment_mode,
                'recipient_name' => $data['recipient_name'] ?? $order->recipient_name,
                'recipient_phone' => $data['recipient_phone'] ?? $order->recipient_phone,
                'address' => $data['address'] ?? $order->delivery_address,
                'courier' => $data['courier'] ?? null,
                'status' => 'preparing',
            ])->forceFill(['created_by' => $actorId])->save();

            foreach ($plan as [$line, $qty]) {
                (new DeliveryLine)->fill(['delivery_id' => $delivery->id, 'sales_order_line_id' => $line->id, 'variant_id' => $line->variant_id, 'quantity' => $qty])->save();
            }

            $this->audit->record('delivery.created', 'delivery', $delivery->id, [], ['number' => $delivery->number, 'order' => $order->number]);
            $this->orders->refreshStatus($order->id, $actorId);

            return $delivery->refresh();
        });
    }

    public function ready(string $id, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $delivery = $this->lock($id, ['preparing'], 'marquer prête');
            $delivery->forceFill(['prepared_at' => CarbonImmutable::now()]);

            return $this->mark($delivery, 'ready', $actorId);
        });
    }

    /**
     * DLV-002 : affectation des lots et passage « en livraison ».
     *
     * @param  array<string, array<string, int>>  $manual  ligne de livraison → (lot → quantité)
     */
    public function ship(string $id, array $manual, ?string $courier, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $manual, $courier, $actorId) {
            $delivery = $this->lock($id, ['ready'], 'expédier');

            if ($delivery->mode !== 'delivery') {
                throw BusinessRuleViolation::invalidTransition('la livraison', 'pickup', 'expédier (retrait sur place)');
            }

            foreach ($this->sortedLines($delivery) as $line) {
                $allocation = $this->allocate($delivery, $line, $manual[$line->id] ?? null);
                $this->ledger->ship(SalesOrderService::RESERVATION, $line->sales_order_line_id, $allocation);
                $this->recordLots($delivery, $line, $allocation, isset($manual[$line->id]) ? 'manual' : 'fifo', 'allocated');
            }

            $delivery->forceFill(['shipped_at' => CarbonImmutable::now(), 'courier' => $courier ?? $delivery->courier]);

            return $this->mark($delivery, 'in_delivery', $actorId);
        }, 3);
    }

    /**
     * DLV-003, DLV-004 : remise (totale ou partielle) ou retrait sur place ; sortie sale_delivery au coût du lot.
     *
     * @param  array<string, int>  $received  ligne de livraison → quantité remise (absente = tout)
     * @param  array<string, array<string, int>>  $manual  affectation manuelle pour un retrait
     */
    public function deliver(string $id, array $received, array $manual, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $received, $manual, $actorId) {
            $delivery = $this->lock($id, ['ready', 'in_delivery'], 'remettre');
            $now = CarbonImmutable::now();
            $pickup = $delivery->status === 'ready';

            if ($pickup && $delivery->mode !== 'pickup') {
                throw BusinessRuleViolation::invalidTransition('la livraison', 'ready', 'remettre avant expédition');
            }

            $total = 0;

            foreach ($this->sortedLines($delivery) as $line) {
                $qty = $received[$line->id] ?? $line->quantity;

                if ($qty < 0 || $qty > $line->quantity) {
                    throw BusinessRuleViolation::invalidField("received/{$line->id}", "La quantité remise doit être comprise entre 0 et {$line->quantity}.");
                }

                $source = new StockSource(self::SOURCE, $delivery->id, $line->id);

                if ($pickup) {
                    if ($qty > 0) {
                        $allocation = $this->allocate($delivery, $line, $manual[$line->id] ?? null, $qty);
                        $this->ledger->issueReserved(SalesOrderService::RESERVATION, $line->sales_order_line_id, $allocation, 'sale_delivery', $source, $now);
                        $this->recordLots($delivery, $line, $allocation, isset($manual[$line->id]) ? 'manual' : 'fifo', 'delivered');
                    }
                } else {
                    [$handed, $back] = $this->splitAllocated($delivery, $line, $qty);

                    if ($handed !== []) {
                        $this->ledger->deliver($delivery->warehouse_id, $line->variant_id, $handed, $source, $now);
                    }

                    if ($back !== []) {
                        $this->ledger->failDelivery(SalesOrderService::RESERVATION, $line->sales_order_line_id, $back);
                    }
                }

                $line->forceFill(['qty_delivered' => $qty])->save();
                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                $orderLine->forceFill(['qty_delivered' => $orderLine->qty_delivered + $qty])->save();
                $total += $qty;
            }

            if ($total === 0) {
                throw BusinessRuleViolation::invalidField('received', 'Aucune quantité remise : signalez plutôt un échec de livraison.');
            }

            // D-104 : les prestations de la commande sont rendues à la première remise.
            SalesOrderLine::query()->where('sales_order_id', $delivery->sales_order_id)->where('product_type', '<>', 'stock')
                ->where('qty_delivered', 0)->where('qty_cancelled', 0)->update(['qty_delivered' => $this->db->raw('qty_base')]);

            $delivery->forceFill(['delivered_at' => $now]);
            $this->mark($delivery, 'delivered', $actorId);
            $this->orders->refreshStatus($delivery->sales_order_id, $actorId);

            return $delivery->refresh();
        }, 3);
    }

    /** DLV-005 : échec signalé, motif obligatoire ; la marchandise reste en livraison jusqu'au retour au dépôt. */
    public function fail(string $id, string $reason, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId) {
            $delivery = $this->lock($id, ['in_delivery'], 'signaler un échec');
            $delivery->forceFill(['failed_at' => CarbonImmutable::now(), 'failure_reason' => $reason]);

            return $this->mark($delivery, 'failed', $actorId);
        });
    }

    /** D-053 : retour au dépôt, « en livraison » → « réservé », nouvelle tentative possible. */
    public function returnToWarehouse(string $id, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $delivery = $this->lock($id, ['failed'], 'retourner au dépôt');

            foreach ($this->sortedLines($delivery) as $line) {
                $lots = DeliveryLineLot::query()->where('delivery_line_id', $line->id)->where('attempt', $delivery->attempt)->where('status', 'allocated')->get();
                $allocation = $lots->mapWithKeys(fn (DeliveryLineLot $l) => [$l->lot_id => $l->quantity])->all();

                if ($allocation !== []) {
                    $this->ledger->failDelivery(SalesOrderService::RESERVATION, $line->sales_order_line_id, $allocation);
                }

                DeliveryLineLot::query()->whereKey($lots->pluck('id'))->update(['status' => 'returned']);
            }

            $delivery->forceFill(['attempt' => $delivery->attempt + 1, 'shipped_at' => null]);
            $this->mark($delivery, 'ready', $actorId);
            $this->orders->refreshStatus($delivery->sales_order_id, $actorId);

            return $delivery->refresh();
        }, 3);
    }

    public function cancel(string $id, ?string $actorId): Delivery
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $delivery = $this->lock($id, ['preparing', 'ready'], 'annuler');
            $this->mark($delivery, 'cancelled', $actorId);
            $this->orders->refreshStatus($delivery->sales_order_id, $actorId);

            return $delivery->refresh();
        });
    }

    /**
     * @param  array<string, int>|null  $manual
     * @return array<string, int>
     */
    private function allocate(Delivery $delivery, DeliveryLine $line, ?array $manual, ?int $qty = null): array
    {
        $qty ??= $line->quantity;
        $allocation = $manual ?? $this->ledger->allocateFifo($delivery->warehouse_id, $line->variant_id, $qty);

        if (array_sum($allocation) !== $qty) {
            throw BusinessRuleViolation::invalidField("allocations/{$line->id}", "L'affectation des lots doit totaliser {$qty}.");
        }

        return $allocation;
    }

    /** @param array<string, int> $allocation */
    private function recordLots(Delivery $delivery, DeliveryLine $line, array $allocation, string $method, string $status): void
    {
        $costs = $this->ledger->lotCosts(array_keys($allocation));

        foreach ($allocation as $lotId => $qty) {
            (new DeliveryLineLot)->fill([
                'delivery_line_id' => $line->id,
                'lot_id' => $lotId,
                'quantity' => $qty,
                'allocation_method' => $method,
                'unit_cost' => $costs[$lotId] ?? '0',
                'attempt' => $delivery->attempt,
                'status' => $status,
            ])->save();
        }
    }

    /**
     * Répartit les lots expédiés entre la quantité remise (premiers lots) et le reste, qui redevient réservé.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function splitAllocated(Delivery $delivery, DeliveryLine $line, int $qty): array
    {
        $handed = [];
        $back = [];
        $remaining = $qty;

        foreach (DeliveryLineLot::query()->where('delivery_line_id', $line->id)->where('attempt', $delivery->attempt)->where('status', 'allocated')->orderBy('id')->get() as $lot) {
            $take = min($remaining, $lot->quantity);
            $rest = $lot->quantity - $take;
            $remaining -= $take;

            if ($take > 0) {
                $handed[$lot->lot_id] = $take;
            }

            if ($rest > 0) {
                $back[$lot->lot_id] = $rest;
            }

            $lot->forceFill($take > 0 ? ['status' => 'delivered', 'quantity' => $take] : ['status' => 'returned'])->save();
        }

        return [$handed, $back];
    }

    /** @return array<string, int> ligne de commande → quantité dans les livraisons ouvertes */
    private function pendingQuantities(string $orderId): array
    {
        return DeliveryLine::query()
            ->whereIn('delivery_id', Delivery::query()->where('sales_order_id', $orderId)->whereIn('status', self::ACTIVE)->select('id'))
            ->get()->groupBy('sales_order_line_id')->map(fn ($g) => (int) $g->sum('quantity'))->all();
    }

    /** @return list<DeliveryLine> */
    private function sortedLines(Delivery $delivery): array
    {
        return array_values($delivery->lines()->get()->sortBy('variant_id')->all());
    }

    private function mark(Delivery $delivery, string $status, ?string $actorId): Delivery
    {
        $previous = $delivery->status;
        $delivery->forceFill(['status' => $status, 'version' => $delivery->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("delivery.{$status}", 'delivery', $delivery->id, ['status' => $previous], ['status' => $status, 'number' => $delivery->number]);

        return $delivery;
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): Delivery
    {
        $delivery = Delivery::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($delivery->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('la livraison', $delivery->status, $action);
        }

        return $delivery;
    }
}
