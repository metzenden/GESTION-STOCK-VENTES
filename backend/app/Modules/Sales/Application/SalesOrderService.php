<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Billing\Contracts\DocumentLine;
use App\Modules\Billing\Contracts\InvoiceDraft;
use App\Modules\Billing\Contracts\Invoicing;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Sales\Infrastructure\Models\Customer;
use App\Modules\Sales\Infrastructure\Models\Delivery;
use App\Modules\Sales\Infrastructure\Models\Dispute;
use App\Modules\Sales\Infrastructure\Models\SalesOrder;
use App\Modules\Sales\Infrastructure\Models\SalesOrderLine;
use App\Modules\Settings\Contracts\CompanyPreferences;
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
 * Commandes clients (L4-03, L4-05, L4-09, L4-14) : brouillon modifiable, soumission (réservation complète
 * et facture dans la même transaction, CMD-004, SAL-002), annulation par avoir (SAL-004, SAL-005), litiges.
 */
final class SalesOrderService
{
    public const RESERVATION = 'sales_order_line';

    /** États où la commande attend ou a des livraisons. */
    public const OPEN = ['confirmed', 'preparing', 'ready', 'in_delivery', 'partially_delivered'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly LineBuilder $builder,
        private readonly VariantCatalog $catalog,
        private readonly StockLedger $ledger,
        private readonly WarehouseDirectory $warehouses,
        private readonly Invoicing $invoicing,
        private readonly ApprovalGate $approvals,
        private readonly CompanyPreferences $preferences,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $header, array $lines, ?string $actorId): SalesOrder
    {
        return $this->db->transaction(function () use ($header, $lines, $actorId) {
            $customer = $this->activeCustomer((string) $header['customer_id']);
            $list = $this->catalog->priceList(isset($header['price_list_id']) ? (string) $header['price_list_id'] : $customer->default_price_list_id)
                ?? throw BusinessRuleViolation::invalidField('price_list_id', 'Aucune grille tarifaire n\'est disponible.');
            $warehouse = isset($header['warehouse_id']) ? (string) $header['warehouse_id'] : ($this->preferences->defaultSalesWarehouseId() ?? '');
            $this->assertWarehouse($warehouse);

            $order = new SalesOrder;
            $order->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::SalesOrder, $this->today()),
                'customer_id' => $customer->id,
                'price_list_id' => $list['id'],
                'warehouse_id' => $warehouse,
                'currency' => $list['currency'],
                'channel' => $header['channel'] ?? 'standard',
                'fulfilment_mode' => $header['fulfilment_mode'] ?? 'pickup',
                'recipient_name' => $header['recipient_name'] ?? null,
                'recipient_phone' => isset($header['recipient_phone']) ? CustomerService::normalizePhone((string) $header['recipient_phone']) : null,
                'delivery_address' => $header['delivery_address'] ?? null,
                'quotation_id' => $header['quotation_id'] ?? null,
                'parent_order_id' => $header['parent_order_id'] ?? null,
                'notes' => $header['notes'] ?? null,
                'global_discount_type' => $header['global_discount']['type'] ?? null,
                'global_discount_value' => $header['global_discount']['value'] ?? null,
                'status' => 'draft',
            ])->forceFill(['created_by' => $actorId])->save();

            $this->replaceLines($order, $lines);

            return $order->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function update(string $id, array $header, ?array $lines, ?int $version, ?string $actorId): SalesOrder
    {
        return $this->db->transaction(function () use ($id, $header, $lines, $version, $actorId) {
            $order = $this->lock($id, ['draft'], 'modifier');

            if ($version !== null && $version !== $order->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            if (isset($header['customer_id'])) {
                $order->customer_id = $this->activeCustomer((string) $header['customer_id'])->id;
            }

            if (isset($header['warehouse_id'])) {
                $this->assertWarehouse((string) $header['warehouse_id']);
                $order->warehouse_id = (string) $header['warehouse_id'];
            }

            foreach (['fulfilment_mode', 'recipient_name', 'delivery_address', 'notes'] as $field) {
                if (array_key_exists($field, $header)) {
                    $order->setAttribute($field, $header[$field]);
                }
            }

            if (array_key_exists('recipient_phone', $header)) {
                $order->recipient_phone = CustomerService::normalizePhone($header['recipient_phone'] === null ? null : (string) $header['recipient_phone']);
            }

            if (array_key_exists('global_discount', $header)) {
                $order->forceFill(['global_discount_type' => $header['global_discount']['type'] ?? null, 'global_discount_value' => $header['global_discount']['value'] ?? null]);
            }

            $order->forceFill(['version' => $order->version + 1, 'updated_by' => $actorId])->save();
            $this->replaceLines($order, $lines ?? $this->currentLines($order));

            return $order->refresh();
        });
    }

    /**
     * SAL-001, SAL-002, CMD-004 : réservation complète (refus total sinon), puis facture ; approbation si une règle s'applique.
     *
     * @return array{order: SalesOrder, warnings: list<array<string, mixed>>}
     */
    public function submit(string $id, ?string $actorId): array
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $order = $this->lock($id, ['draft'], 'soumettre');
            $this->activeCustomer($order->customer_id);
            $lines = $order->lines()->get();
            $warnings = [];

            if ($lines->isEmpty()) {
                throw BusinessRuleViolation::invalidField('lines', 'La commande ne contient aucune ligne.');
            }

            $approval = $this->approvals->requiresApproval('sales_order', [
                'discount_total' => $order->discount_total,
                'subtotal' => $order->subtotal,
                'price_overridden' => $lines->contains(fn (SalesOrderLine $l) => $l->price_overridden),
                'total' => $order->total,
            ]);

            // Réservations dans l'ordre des variantes (verrous ordonnés, D-056).
            foreach ($lines->filter(fn (SalesOrderLine $l) => $l->isStocked())->sortBy('variant_id') as $line) {
                $estimated = $this->ledger->estimateCost($order->warehouse_id, $line->variant_id, $line->qty_base);
                $this->ledger->reserve($order->warehouse_id, $line->variant_id, $line->qty_base, self::RESERVATION, $line->id, provisional: $approval);
                $line->forceFill(['estimated_unit_cost' => $estimated])->save();

                // SAL-006 : prix net unitaire inférieur au coût estimé (avertissement, non bloquant).
                if ($estimated !== null) {
                    $netPerPiece = Decimal::of($line->line_total)->minus(Decimal::of($line->tax_amount))->dividedBy($line->qty_base, 8, RoundingMode::HalfUp);

                    if ($netPerPiece->isLessThan(Decimal::of($estimated))) {
                        $warnings[] = ['code' => 'negative_margin', 'line_id' => $line->id, 'message' => "Ligne {$line->position} : prix net inférieur au coût estimé."];
                    }
                }
            }

            if ($approval) {
                $this->mark($order, 'pending_approval', $actorId);
                $this->approvals->submit('sales_order', 'sales_order', $order->id, [], "Commande {$order->number} : remise ou prix modifié ({$order->total} {$order->currency})");

                return ['order' => $order->refresh(), 'warnings' => $warnings];
            }

            $this->confirm($order, array_values($lines->all()), $actorId);

            return ['order' => $order->refresh(), 'warnings' => $warnings];
        }, 3);
    }

    /**
     * SAL-004 : annulation d'une commande non livrée ; réservations libérées, avoir d'annulation total sur la facture.
     */
    public function cancel(string $id, string $reason, ?string $actorId, bool $approved = false): SalesOrder
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId, $approved) {
            $order = $this->lock($id, ['draft', 'pending_approval', 'confirmed', 'preparing', 'ready', 'in_delivery', 'partially_delivered'], 'annuler');
            $lines = $order->lines()->get();

            if ($lines->contains(fn (SalesOrderLine $l) => $l->qty_delivered > 0) || Delivery::query()->where('sales_order_id', $order->id)->where('status', 'in_delivery')->exists()) {
                throw new BusinessRuleViolation('order_partially_delivered', 'Commande déjà livrée en partie',
                    'Une partie de la commande est livrée ou en cours de livraison : annulez seulement le reliquat (SAL-005).', 409);
            }

            if (! $approved && $order->status !== 'draft' && $this->approvals->requiresApproval('sales_order_cancellation', ['total' => $order->total])) {
                // APR-004 : rien n'est annulé avant la décision ; la demande conserve le motif.
                $this->approvals->submit('sales_order_cancellation', 'sales_order', $order->id, ['reason' => $reason], "Annulation de la commande {$order->number} : {$reason}");

                return $order;
            }

            Delivery::query()->where('sales_order_id', $order->id)->whereIn('status', ['preparing', 'ready', 'failed'])->update(['status' => 'cancelled', 'updated_by' => $actorId]);

            if ($order->status !== 'draft') {
                foreach ($lines->filter(fn (SalesOrderLine $l) => $l->isStocked())->sortBy('variant_id') as $line) {
                    $this->ledger->release(self::RESERVATION, $line->id);
                }
            }

            foreach ($lines as $line) {
                $line->forceFill(['qty_cancelled' => $line->qty_base - $line->qty_delivered])->save();
            }

            if ($order->invoice_id !== null) {
                $this->invoicing->creditInvoice($order->invoice_id, 'cancellation', "Annulation de la commande {$order->number} : {$reason}",
                    array_values(array_map(fn (SalesOrderLine $l) => self::creditLine($l, $l->qty_base), $lines->all())), null, $actorId);
            }

            $order->forceFill(['cancelled_at' => CarbonImmutable::now(), 'cancellation_reason' => $reason]);

            return $this->mark($order, 'cancelled', $actorId);
        }, 3);
    }

    /** SAL-005 : annulation du reliquat d'une commande partiellement livrée, par avoir partiel. */
    public function cancelRemaining(string $id, string $reason, ?string $actorId): SalesOrder
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId) {
            $order = $this->lock($id, ['partially_delivered'], 'annuler le reliquat');

            if (Delivery::query()->where('sales_order_id', $order->id)->whereNotIn('status', ['delivered', 'cancelled'])->exists()) {
                throw new BusinessRuleViolation('delivery_in_progress', 'Livraison en cours', 'Terminez ou annulez la livraison en cours avant d\'annuler le reliquat.', 409);
            }

            $credit = [];

            foreach ($order->lines()->get()->sortBy('variant_id') as $line) {
                $remaining = $line->remaining();

                // Les prestations (frais de livraison) sont réputées rendues à la première remise (D-104).
                if ($remaining <= 0 || ! $line->isStocked()) {
                    continue;
                }

                $this->ledger->release(self::RESERVATION, $line->id, $remaining);

                $line->forceFill(['qty_cancelled' => $line->qty_cancelled + $remaining])->save();
                $credit[] = self::creditLine($line, $remaining);
            }

            if ($credit !== [] && $order->invoice_id !== null) {
                $this->invoicing->creditInvoice($order->invoice_id, 'cancellation', "Reliquat annulé — commande {$order->number} : {$reason}", $credit, null, $actorId);
            }

            $this->refreshStatus($order->id, $actorId);

            return $order->refresh();
        }, 3);
    }

    public function openDispute(string $id, string $reason, ?string $actorId): Dispute
    {
        return $this->db->transaction(function () use ($id, $reason, $actorId) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($id);

            if ($order->status === 'draft') {
                throw BusinessRuleViolation::invalidTransition('la commande', $order->status, 'ouvrir un litige');
            }

            $dispute = new Dispute;
            $dispute->fill(['sales_order_id' => $order->id, 'status' => 'open', 'reason' => $reason, 'opened_at' => CarbonImmutable::now()])
                ->forceFill(['created_by' => $actorId])->save();
            $order->forceFill(['has_dispute' => true])->save();
            $this->audit->record('sales_order.dispute_opened', 'sales_order', $order->id, [], ['dispute_id' => $dispute->id, 'reason' => $reason]);

            return $dispute;
        });
    }

    public function resolveDispute(string $disputeId, string $resolution, ?string $actorId): Dispute
    {
        return $this->db->transaction(function () use ($disputeId, $resolution, $actorId) {
            $dispute = Dispute::query()->lockForUpdate()->findOrFail($disputeId);

            if ($dispute->status !== 'open') {
                throw BusinessRuleViolation::alreadyProcessed('Ce litige est déjà résolu.');
            }

            $dispute->forceFill(['status' => 'resolved', 'resolution' => $resolution, 'resolved_at' => CarbonImmutable::now(), 'updated_by' => $actorId])->save();
            $stillOpen = Dispute::query()->where('sales_order_id', $dispute->sales_order_id)->where('status', 'open')->exists();
            SalesOrder::query()->whereKey($dispute->sales_order_id)->update(['has_dispute' => $stillOpen]);
            $this->audit->record('sales_order.dispute_resolved', 'sales_order', $dispute->sales_order_id, [], ['dispute_id' => $dispute->id]);

            return $dispute;
        });
    }

    /** APR-005 : approbation d'une commande en attente — réservations définitives et facture. */
    public function approvePending(string $id, ?string $actorId): SalesOrder
    {
        $order = $this->lock($id, ['pending_approval'], 'approuver');
        $lines = $order->lines()->get();

        foreach ($lines->filter(fn (SalesOrderLine $l) => $l->isStocked()) as $line) {
            $this->ledger->confirmReservation(self::RESERVATION, $line->id);
        }

        $this->confirm($order, array_values($lines->all()), $actorId);

        return $order->refresh();
    }

    /** APR-006 : rejet ou retrait — réservations provisoires libérées, retour en brouillon. */
    public function rejectPending(string $id, ?string $actorId): SalesOrder
    {
        $order = $this->lock($id, ['pending_approval'], 'rejeter');

        foreach ($order->lines()->get()->filter(fn (SalesOrderLine $l) => $l->isStocked())->sortBy('variant_id') as $line) {
            $this->ledger->discardReservation(self::RESERVATION, $line->id);
        }

        return $this->mark($order, 'draft', $actorId);
    }

    /** État de la commande déduit de ses livraisons et des quantités livrées ou annulées. */
    public function refreshStatus(string $orderId, ?string $actorId): void
    {
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);

        if (! in_array($order->status, self::OPEN, true)) {
            return;
        }

        $lines = $order->lines()->get()->filter(fn (SalesOrderLine $l) => $l->isStocked());
        $statuses = Delivery::query()->where('sales_order_id', $order->id)->pluck('status');
        $delivered = $lines->sum('qty_delivered') > 0;

        $status = match (true) {
            $lines->every(fn (SalesOrderLine $l) => $l->remaining() === 0) => 'delivered',
            $statuses->contains('in_delivery') => 'in_delivery',
            $statuses->contains('ready') || $statuses->contains('failed') => 'ready',
            $statuses->contains('preparing') => 'preparing',
            $delivered => 'partially_delivered',
            default => 'confirmed',
        };

        if ($status !== $order->status) {
            $this->mark($order, $status, $actorId);
        }
    }

    /** @param list<SalesOrderLine> $lines */
    private function confirm(SalesOrder $order, array $lines, ?string $actorId): void
    {
        $customer = Customer::query()->findOrFail($order->customer_id);
        $now = CarbonImmutable::now();

        foreach ($lines as $line) {
            $this->catalog->lockUnit($line->unit_id);
        }

        $this->catalog->markHistory(array_values(array_unique(array_map(fn (SalesOrderLine $l) => $l->variant_id, $lines))));
        $customer->forceFill(['has_history' => true])->save();

        $invoiceId = $this->invoicing->issue(new InvoiceDraft(
            $order->id,
            $customer->id,
            ['code' => $customer->code, 'name' => $customer->name, 'customer_type' => $customer->customer_type, 'phone' => $customer->phone, 'email' => $customer->email, 'address' => $customer->address],
            $order->currency,
            $this->today(),
            $order->channel === 'counter' ? 0 : ($customer->payment_terms_days ?? $this->preferences->defaultPaymentTermsDays()),
            array_map(fn (SalesOrderLine $l) => new DocumentLine($l->id, $l->description, $l->unit_label, $l->qty_units, $l->unit_price, $l->discount_amount, $l->tax_rate, $l->tax_amount, $l->line_total), $lines),
            $order->subtotal, $order->discount_total, $order->tax_total, $order->total,
        ), $actorId);

        $order->forceFill(['invoice_id' => $invoiceId, 'confirmed_at' => $now, 'confirmed_by' => $actorId]);
        $this->mark($order, 'confirmed', $actorId);
    }

    /** Ligne d'avoir pour une quantité (unités de base) d'une ligne de commande, au prorata du montant facturé. */
    public static function creditLine(SalesOrderLine $line, int $qtyBase): DocumentLine
    {
        $ratio = fn (string $amount) => (string) Decimal::of($amount)->multipliedBy($qtyBase)->dividedBy($line->qty_base, 4, RoundingMode::HalfUp);

        return new DocumentLine($line->id, $line->description, $line->unit_label, $line->qty_units, $line->unit_price, $ratio($line->discount_amount),
            $line->tax_rate, $ratio($line->tax_amount), $ratio($line->line_total), $qtyBase);
    }

    /** @param list<array<string, mixed>> $input */
    private function replaceLines(SalesOrder $order, array $input): void
    {
        $global = $order->global_discount_type !== null ? ['type' => $order->global_discount_type, 'value' => (string) $order->global_discount_value] : null;
        $built = $this->builder->build($input, $order->price_list_id, $order->currency, $this->today(), $global);
        SalesOrderLine::query()->where('sales_order_id', $order->id)->delete();

        foreach ($built['lines'] as $index => $line) {
            (new SalesOrderLine)->fill($line + ['sales_order_id' => $order->id, 'position' => $index + 1])->save();
        }

        $order->forceFill($built['totals'])->save();
    }

    /** @return list<array<string, mixed>> */
    private function currentLines(SalesOrder $order): array
    {
        return array_values($order->lines()->get()->map(fn (SalesOrderLine $l) => [
            'variant_id' => $l->variant_id, 'unit_id' => $l->unit_id, 'qty_units' => $l->qty_units, 'unit_price' => $l->unit_price,
            'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value, 'tax_rate_id' => $l->tax_rate_id, 'description' => $l->description,
        ])->all());
    }

    private function activeCustomer(string $id): Customer
    {
        $customer = Customer::query()->find($id);

        if ($customer === null || $customer->status !== 'active') {
            throw BusinessRuleViolation::invalidField('customer_id', 'Ce client n\'existe pas ou est inactif.');
        }

        return $customer;
    }

    private function assertWarehouse(string $id): void
    {
        if ($id === '' || ! $this->warehouses->isActive($id)) {
            throw BusinessRuleViolation::invalidField('warehouse_id', 'Choisissez un dépôt actif.');
        }
    }

    private function mark(SalesOrder $order, string $status, ?string $actorId): SalesOrder
    {
        $previous = $order->status;
        $order->forceFill(['status' => $status, 'version' => $order->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("sales_order.{$status}", 'sales_order', $order->id, ['status' => $previous], ['status' => $status, 'number' => $order->number]);

        return $order->refresh();
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): SalesOrder
    {
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($order->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('la commande', $order->status, $action);
        }

        return $order;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
