<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Payments\Contracts\PaymentRecorder;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Vente comptoir (L4-13, SAL-008, D-063) : commande, confirmation et facture, retrait et paiement
 * en une seule transaction ; si une étape échoue, rien n'est écrit.
 */
final class CounterSaleService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SalesOrderService $orders,
        private readonly DeliveryService $deliveries,
        private readonly PaymentRecorder $payments,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{method: string, financial_account_id: string, amount?: string|null, external_reference?: string|null}|null  $payment
     * @param  array{type: string, value: string}|null  $globalDiscount
     * @return array{order: SalesOrder, payment: array{id: string, number: string}|null, warnings: list<array<string, mixed>>}
     */
    public function sell(?string $customerId, ?string $warehouseId, array $lines, ?array $globalDiscount, ?array $payment, ?string $actorId): array
    {
        return $this->db->transaction(function () use ($customerId, $warehouseId, $lines, $globalDiscount, $payment, $actorId) {
            $order = $this->orders->create(array_filter([
                'customer_id' => $customerId ?? ProvisionSales::walkIn()->id,
                'warehouse_id' => $warehouseId,
                'channel' => 'counter',
                'fulfilment_mode' => 'pickup',
                'global_discount' => $globalDiscount,
            ], fn ($v) => $v !== null), $lines, $actorId);

            $submitted = $this->orders->submit($order->id, $actorId);

            if ($submitted['order']->status !== 'confirmed') {
                throw new BusinessRuleViolation('approval_required', 'Approbation requise',
                    'Cette vente demande une approbation (remise ou prix modifié) : enregistrez-la comme commande classique.', 409);
            }

            $delivery = $this->deliveries->create($order->id, null, ['mode' => 'pickup'], $actorId);
            $this->deliveries->ready($delivery->id, $actorId);
            $this->deliveries->deliver($delivery->id, [], [], $actorId);
            $order = SalesOrder::query()->findOrFail($order->id);
            $recorded = null;

            if ($payment !== null && $order->getAttribute('invoice_id') !== null && Decimal::of($order->total)->isPositive()) {
                $amount = $payment['amount'] ?? $order->total;
                $recorded = $this->payments->record((string) $order->invoice_id, $payment['method'], $payment['financial_account_id'], $amount,
                    CarbonImmutable::now(), $payment['external_reference'] ?? null, $actorId);
            }

            return ['order' => $order->refresh(), 'payment' => $recorded, 'warnings' => $submitted['warnings']];
        }, 3);
    }
}
