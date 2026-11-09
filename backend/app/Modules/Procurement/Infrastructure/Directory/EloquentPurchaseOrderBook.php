<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Directory;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Procurement\Contracts\PurchaseOrderBook;
use App\Modules\Procurement\Contracts\PurchaseOrderLineSummary;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrder;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrderLine;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

final class EloquentPurchaseOrderBook implements PurchaseOrderBook
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function summary(string $purchaseOrderId): ?array
    {
        $order = PurchaseOrder::query()->find($purchaseOrderId);

        return $order === null ? null : [
            'id' => $order->id,
            'number' => $order->number,
            'supplier_id' => $order->supplier_id,
            'currency' => $order->currency,
            'status' => $order->status,
            'estimated_rate' => $order->estimated_rate,
        ];
    }

    public function receivableLines(string $purchaseOrderId): array
    {
        $order = PurchaseOrder::query()->findOrFail($purchaseOrderId);

        if (! in_array($order->status, ['confirmed', 'partially_received'], true)) {
            throw BusinessRuleViolation::invalidTransition('la commande', $order->status, 'réceptionner');
        }

        return array_values($this->summarize($order->lines()->get(), [$order->id => $order])->all());
    }

    public function lines(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        $lines = PurchaseOrderLine::query()->whereKey(array_values(array_unique($lineIds)))->get();
        $orders = PurchaseOrder::query()->whereKey($lines->pluck('purchase_order_id')->unique()->values())->get()->keyBy('id')->all();

        return $this->summarize($lines, $orders)->all();
    }

    public function recordReceipt(array $quantities): void
    {
        if ($quantities === []) {
            return;
        }

        ksort($quantities);
        $lines = PurchaseOrderLine::query()->whereKey(array_keys($quantities))->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $line->forceFill(['qty_received' => $line->qty_received + $quantities[$line->id]])->save();
        }

        foreach ($lines->pluck('purchase_order_id')->unique()->sort() as $orderId) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail((string) $orderId);

            if (! in_array($order->status, ['confirmed', 'partially_received'], true)) {
                continue;
            }

            $complete = $order->lines()->get()->every(fn (PurchaseOrderLine $l) => $l->qty_received >= $l->qty_base);
            $status = $complete ? 'received' : 'partially_received';

            if ($status !== $order->status) {
                $previous = $order->status;
                $order->forceFill(['status' => $status, 'version' => $order->version + 1])->save();
                $this->audit->record("purchase_order.{$status}", 'purchase_order', $order->id, ['status' => $previous], ['status' => $status, 'number' => $order->number]);
            }
        }
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<string, PurchaseOrder>  $orders
     * @return Collection<string, PurchaseOrderLineSummary>
     */
    private function summarize(Collection $lines, array $orders): Collection
    {
        return $lines->mapWithKeys(function (PurchaseOrderLine $line) use ($orders) {
            $order = $orders[$line->purchase_order_id];
            $perPiece = Decimal::of($line->unit_price)->dividedBy($line->unit_factor, 8, RoundingMode::HalfUp);

            return [$line->id => new PurchaseOrderLineSummary(
                $line->id, $order->id, $order->number, $order->supplier_id, $line->variant_id, $line->unit_factor,
                $line->qty_base, $line->qty_received, (string) $perPiece, $order->currency,
            )];
        });
    }
}
