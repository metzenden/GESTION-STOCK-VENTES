<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Sales\Infrastructure\Models\DeliveryLine;
use App\Modules\Sales\Infrastructure\Models\DeliveryLineLot;
use App\Modules\Sales\Infrastructure\Models\ReturnLine;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Sales\Infrastructure\Models\SalesOrderLine;
use App\Modules\Sales\Infrastructure\Models\SalesReturn;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Retours clients (L4-12, docs/04 §8.4) : réception au lot d'origine (return_in, RET-003) sous blocage « contrôle »,
 * décision explicite par ligne (remise en stock ou endommagé, RET-001, RET-002), puis avoir de retour (RET-005).
 */
final class ReturnService
{
    public const SOURCE = 'return';

    public const HOLD_SOURCE = 'return_line';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly StockLedger $ledger,
        private readonly WarehouseDirectory $warehouses,
        private readonly Invoicing $invoicing,
        private readonly SalesOrderService $orders,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, int> $quantities ligne de commande → quantité retournée (unités de base) */
    public function create(string $orderId, string $type, ?string $warehouseId, array $quantities, ?string $reason, ?string $actorId): SalesReturn
    {
        return $this->db->transaction(function () use ($orderId, $type, $warehouseId, $quantities, $reason, $actorId) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $warehouse = $warehouseId ?? $order->warehouse_id;

            if (! $this->warehouses->isActive($warehouse)) {
                throw BusinessRuleViolation::invalidField('warehouse_id', 'Ce dépôt n\'existe pas ou est inactif.');
            }

            if ($quantities === []) {
                throw BusinessRuleViolation::invalidField('lines', 'Le retour ne contient aucune ligne.');
            }

            $return = new SalesReturn;
            $return->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::Return, $this->orders->today()),
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $warehouse,
                'return_type' => $type,
                'reason' => $reason,
                'status' => 'draft',
            ])->forceFill(['created_by' => $actorId])->save();

            foreach ($quantities as $lineId => $qty) {
                $line = SalesOrderLine::query()->where('sales_order_id', $order->id)->find((string) $lineId)
                    ?? throw BusinessRuleViolation::invalidField("lines/{$lineId}", 'Cette ligne n\'appartient pas à la commande.');
                $this->allocateToLots($return, $line, (int) $qty);
            }

            return $return->refresh();
        });
    }

    /** Réception : entrée return_in au lot d'origine, au coût d'origine, bloquée pour contrôle. */
    public function receive(string $id, ?string $actorId): SalesReturn
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $return = $this->lock($id, ['draft'], 'réceptionner');
            $now = CarbonImmutable::now();

            foreach ($return->lines()->get() as $line) {
                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);

                if ($orderLine->qty_returned + $line->quantity > $orderLine->qty_delivered) {
                    throw BusinessRuleViolation::invalidField("lines/{$line->id}", 'La quantité retournée dépasse la quantité livrée (RET-004).');
                }

                $this->ledger->receive($return->warehouse_id, $line->lot_id, $line->quantity, 'return_in', new StockSource(self::SOURCE, $return->id, $line->id), $now);
                $hold = $this->ledger->hold($return->warehouse_id, $line->lot_id, $line->quantity, 'return_inspection', self::HOLD_SOURCE, $line->id, "Retour {$return->number}");
                $line->forceFill(['hold_id' => $hold])->save();
                $orderLine->forceFill(['qty_returned' => $orderLine->qty_returned + $line->quantity])->save();
            }

            $return->forceFill(['received_at' => $now]);

            return $this->mark($return, 'received', $actorId);
        }, 3);
    }

    /**
     * RET-001, RET-002 : remise en stock vendable ou stock bloqué « endommagé », par ligne.
     *
     * @param  array<string, string>  $decisions  ligne de retour → restock|damaged
     */
    public function inspect(string $id, array $decisions, ?string $actorId): SalesReturn
    {
        return $this->db->transaction(function () use ($id, $decisions, $actorId) {
            $return = $this->lock($id, ['received'], 'contrôler');

            foreach ($return->lines()->get() as $line) {
                $decision = $decisions[$line->id] ?? throw BusinessRuleViolation::invalidField("decisions/{$line->id}", 'Décidez pour chaque ligne : remise en stock ou endommagé.', 'required');
                $this->ledger->releaseHold((string) $line->hold_id);
                $hold = $decision === 'damaged'
                    ? $this->ledger->hold($return->warehouse_id, $line->lot_id, $line->quantity, 'damaged', self::HOLD_SOURCE, $line->id, "Retour {$return->number} endommagé")
                    : null;
                $line->forceFill(['disposition' => $decision, 'hold_id' => $hold])->save();
            }

            return $this->mark($return, 'inspected', $actorId);
        }, 3);
    }

    /** RET-005 : avoir de retour imputé sur la facture ; un échange crée une commande brouillon de remplacement. */
    public function close(string $id, ?string $actorId): SalesReturn
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $return = $this->lock($id, ['inspected'], 'clôturer');
            $order = SalesOrder::query()->findOrFail($return->sales_order_id);
            $credit = [];
            $exchange = [];

            foreach ($return->lines()->get() as $line) {
                $orderLine = SalesOrderLine::query()->findOrFail($line->sales_order_line_id);
                $creditLine = SalesOrderService::creditLine($orderLine, $line->quantity);
                $credit[] = $creditLine;
                $line->forceFill(['credit_amount' => $creditLine->lineTotal])->save();
                $exchange[] = ['variant_id' => $orderLine->variant_id, 'qty_units' => $line->quantity];
            }

            if ($order->invoice_id !== null) {
                $note = $this->invoicing->creditInvoice($order->invoice_id, 'return', "Retour {$return->number}", $credit, $return->id, $actorId);
                $return->forceFill(['credit_note_id' => $note['id']]);
            }

            if ($return->return_type === 'exchange') {
                $replacement = $this->orders->create(['customer_id' => $order->customer_id, 'warehouse_id' => $return->warehouse_id, 'price_list_id' => $order->price_list_id,
                    'parent_order_id' => $order->id, 'notes' => "Échange — retour {$return->number}"], $exchange, $actorId);
                $return->forceFill(['exchange_order_id' => $replacement->id]);
            }

            $return->forceFill(['closed_at' => CarbonImmutable::now()]);

            return $this->mark($return, 'closed', $actorId);
        }, 3);
    }

    public function cancel(string $id, ?string $actorId): SalesReturn
    {
        return $this->db->transaction(fn () => $this->mark($this->lock($id, ['draft'], 'annuler'), 'cancelled', $actorId));
    }

    /** RET-003, RET-004 : répartit la quantité sur les lots livrés de la ligne, hors quantités déjà retournées. */
    private function allocateToLots(SalesReturn $return, SalesOrderLine $line, int $qty): void
    {
        if ($qty <= 0) {
            throw BusinessRuleViolation::invalidField("lines/{$line->id}", 'La quantité retournée doit être positive.');
        }

        $lots = DeliveryLineLot::query()->where('status', 'delivered')
            ->whereIn('delivery_line_id', DeliveryLine::query()->where('sales_order_line_id', $line->id)->select('id'))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $taken = ReturnLine::query()->whereIn('delivery_line_lot_id', $lots->pluck('id'))
            ->whereIn('return_id', SalesReturn::query()->where('status', '<>', 'cancelled')->select('id'))
            ->get()->groupBy('delivery_line_lot_id')->map(fn ($g) => (int) $g->sum('quantity'));
        $remaining = $qty;

        foreach ($lots as $lot) {
            $free = $lot->quantity - ($taken[$lot->id] ?? 0);
            $take = min($free, $remaining);

            if ($take <= 0) {
                continue;
            }

            (new ReturnLine)->fill([
                'return_id' => $return->id,
                'sales_order_line_id' => $line->id,
                'delivery_line_lot_id' => $lot->id,
                'lot_id' => $lot->lot_id,
                'variant_id' => $line->variant_id,
                'quantity' => $take,
                'unit_cost' => $lot->unit_cost,
            ])->save();
            $remaining -= $take;

            if ($remaining === 0) {
                return;
            }
        }

        throw BusinessRuleViolation::invalidField("lines/{$line->id}", "La quantité retournée dépasse la quantité livrée et non encore retournée sur la ligne {$line->position} (RET-004).");
    }

    private function mark(SalesReturn $return, string $status, ?string $actorId): SalesReturn
    {
        $previous = $return->status;
        $return->forceFill(['status' => $status, 'version' => $return->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("return.{$status}", 'return', $return->id, ['status' => $previous], ['status' => $status, 'number' => $return->number]);

        return $return->refresh();
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): SalesReturn
    {
        $return = SalesReturn::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($return->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('le retour', $return->status, $action);
        }

        return $return;
    }
}
