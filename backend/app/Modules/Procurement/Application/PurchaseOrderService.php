<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrder;
use App\Modules\Procurement\Infrastructure\Models\PurchaseOrderLine;
use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Procurement\Infrastructure\Models\SupplierBill;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Commandes d'achat (L3-02, docs/04 §5) : brouillon → confirmée (engagement, PUR-001)
 * → partiellement reçue / reçue (PUR-005) → clôturée ; annulation sans réception (PUR-004).
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly VariantCatalog $catalog,
        private readonly SupplierBillService $bills,
        private readonly DocumentNumberAllocator $numbers,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{supplier_id: string, order_date?: string|null, supplier_reference?: string|null, estimated_rate?: string|null, notes?: string|null}  $header
     * @param  list<array{variant_id: string, unit_id?: string|null, qty_units: int, unit_price: string}>  $lines
     */
    public function create(array $header, array $lines, ?string $actorId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($header, $lines, $actorId) {
            $supplier = $this->activeSupplier($header['supplier_id']);
            $date = isset($header['order_date']) ? CarbonImmutable::parse($header['order_date']) : $this->today();

            $order = new PurchaseOrder;
            $order->fill([
                'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::PurchaseOrder, $date),
                'supplier_id' => $supplier->id,
                'supplier_reference' => $header['supplier_reference'] ?? null,
                'order_date' => $date->toDateString(),
                'currency' => $supplier->currency,
                'estimated_rate' => $header['estimated_rate'] ?? null,
                'notes' => $header['notes'] ?? null,
                'status' => 'draft',
            ])->forceFill(['created_by' => $actorId])->save();

            $this->replaceLines($order, $lines);

            return $order->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{variant_id: string, unit_id?: string|null, qty_units: int, unit_price: string}>|null  $lines
     */
    public function update(string $id, array $header, ?array $lines, ?int $version, ?string $actorId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($id, $header, $lines, $version, $actorId) {
            $order = $this->lock($id, ['draft'], 'modifier');

            if ($version !== null && $version !== $order->version) {
                throw BusinessRuleViolation::versionMismatch();
            }

            if (isset($header['supplier_id']) && $header['supplier_id'] !== $order->supplier_id) {
                $supplier = $this->activeSupplier((string) $header['supplier_id']);
                $order->forceFill(['supplier_id' => $supplier->id, 'currency' => $supplier->currency]);
            }

            foreach (['supplier_reference', 'order_date', 'estimated_rate', 'notes'] as $field) {
                if (array_key_exists($field, $header)) {
                    $order->setAttribute($field, $header[$field]);
                }
            }

            if ($lines !== null) {
                $this->replaceLines($order, $lines);
            } elseif ($order->isDirty('currency')) {
                $this->recalculate($order);
            }

            $order->forceFill(['version' => $order->version + 1, 'updated_by' => $actorId])->save();

            return $order->refresh();
        });
    }

    /** PUR-001 : la confirmation fige les conditionnements et crée l'engagement du montant total. */
    public function confirm(string $id, ?string $actorId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $order = $this->lock($id, ['draft'], 'confirmer');
            $lines = $order->lines()->get();

            if ($lines->isEmpty()) {
                throw BusinessRuleViolation::invalidField('lines', 'La commande ne contient aucune ligne.');
            }

            $this->activeSupplier($order->supplier_id);

            if (Decimal::of($order->total_amount)->isZero()) {
                throw BusinessRuleViolation::invalidField('lines', 'Le montant de la commande est nul.');
            }

            foreach ($lines as $line) {
                $this->catalog->lockUnit($line->unit_id);
            }

            $this->catalog->markHistory(array_values(array_map('strval', $lines->pluck('variant_id')->unique()->all())));
            $this->bills->commit($order->supplier_id, $order->currency, $order->total_amount, 'purchase_order', $order->id,
                $order->order_date, "Commande {$order->number}", $actorId);

            $order->forceFill(['confirmed_at' => CarbonImmutable::now()]);

            return $this->mark($order, 'confirmed', $actorId);
        });
    }

    /** PUR-005 : clôture d'une commande reçue ou partiellement reçue (reliquat abandonné). */
    public function close(string $id, bool $releaseRemaining, ?string $actorId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($id, $releaseRemaining, $actorId) {
            $order = $this->lock($id, ['partially_received', 'received'], 'clôturer');

            if ($releaseRemaining && $order->status === 'partially_received') {
                $remaining = BigDecimal::zero();

                foreach ($order->lines()->get() as $line) {
                    $missing = max($line->qty_base - $line->qty_received, 0);
                    $remaining = $remaining->plus(Decimal::of($line->unit_price)->multipliedBy($missing)->dividedBy($line->unit_factor, 8, RoundingMode::HalfUp));
                }

                $remaining = $remaining->toScale(4, RoundingMode::HalfUp);

                if ($remaining->isPositive()) {
                    $this->bills->commit($order->supplier_id, $order->currency, (string) $remaining->negated(), 'purchase_order', $order->id,
                        $this->today(), "Reliquat abandonné — commande {$order->number}", $actorId);
                }
            }

            $order->forceFill(['closed_at' => CarbonImmutable::now()]);

            return $this->mark($order, 'closed', $actorId);
        });
    }

    /** PUR-004 : annulation possible tant qu'aucune réception n'est validée ; l'engagement est compensé. */
    public function cancel(string $id, ?string $actorId): PurchaseOrder
    {
        return $this->db->transaction(function () use ($id, $actorId) {
            $order = $this->lock($id, ['draft', 'confirmed'], 'annuler');

            if ($order->status === 'confirmed') {
                if ($order->lines()->where('qty_received', '>', 0)->exists()) {
                    throw BusinessRuleViolation::invalidTransition('la commande', 'partially_received', 'annuler');
                }

                $bills = SupplierBill::query()->where('source_type', 'purchase_order')->where('source_id', $order->id)
                    ->whereNull('compensates_bill_id')->where('status', '<>', 'cancelled')->pluck('id');

                foreach ($bills as $billId) {
                    $this->bills->compensate((string) $billId, "Annulation de la commande {$order->number}", $actorId);
                }
            }

            return $this->mark($order, 'cancelled', $actorId);
        });
    }

    /** @param list<array{variant_id: string, unit_id?: string|null, qty_units: int, unit_price: string}> $lines */
    private function replaceLines(PurchaseOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw BusinessRuleViolation::invalidField('lines', 'La commande ne contient aucune ligne.');
        }

        $variants = $this->catalog->describe(array_values(array_unique(array_column($lines, 'variant_id'))));
        $unitIds = array_values(array_filter(array_map(fn (array $l) => $l['unit_id'] ?? null, $lines)));
        $units = $this->catalog->units($unitIds);

        PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->delete();
        $total = BigDecimal::zero();

        foreach ($lines as $index => $line) {
            $variant = $variants[$line['variant_id']] ?? throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Cette variante n\'existe pas.');

            if (! $variant->isStocked()) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/variant_id", 'Une prestation ne s\'achète pas en stock.');
            }

            $unit = isset($line['unit_id']) ? ($units[$line['unit_id']] ?? null) : $this->catalog->baseUnit($variant->id);

            if ($unit === null || $unit->variantId !== $variant->id) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/unit_id", 'Ce conditionnement n\'appartient pas à la variante.');
            }

            if ($line['qty_units'] <= 0) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/qty_units", 'La quantité doit être positive.');
            }

            if (! Decimal::isValid($line['unit_price']) || Decimal::of($line['unit_price'])->isNegative()) {
                throw BusinessRuleViolation::invalidField("lines/{$index}/unit_price", 'Le prix unitaire est invalide.');
            }

            $price = Decimal::of($line['unit_price'])->toScale(8, RoundingMode::HalfUp);
            $lineTotal = $price->multipliedBy($line['qty_units'])->toScale(4, RoundingMode::HalfUp);
            $total = $total->plus($lineTotal);

            (new PurchaseOrderLine)->fill([
                'purchase_order_id' => $order->id,
                'position' => $index + 1,
                'variant_id' => $variant->id,
                'unit_id' => $unit->id,
                'unit_factor' => $unit->factor,
                'qty_units' => $line['qty_units'],
                'qty_base' => $line['qty_units'] * $unit->factor,
                'unit_price' => (string) $price,
                'line_total' => (string) $lineTotal,
            ])->save();
        }

        $order->forceFill(['total_amount' => (string) $total->toScale(4)])->save();
    }

    private function recalculate(PurchaseOrder $order): void
    {
        $total = BigDecimal::zero();

        foreach ($order->lines()->get() as $line) {
            $total = $total->plus(Decimal::of($line->line_total));
        }

        $order->forceFill(['total_amount' => (string) $total->toScale(4)]);
    }

    private function activeSupplier(string $id): Supplier
    {
        $supplier = Supplier::query()->find($id);

        if ($supplier === null || $supplier->status !== 'active') {
            throw BusinessRuleViolation::invalidField('supplier_id', 'Ce fournisseur n\'existe pas ou est inactif.');
        }

        return $supplier;
    }

    private function mark(PurchaseOrder $order, string $status, ?string $actorId): PurchaseOrder
    {
        $previous = $order->status;
        $order->forceFill(['status' => $status, 'version' => $order->version + 1, 'updated_by' => $actorId])->save();
        $this->audit->record("purchase_order.{$status}", 'purchase_order', $order->id, ['status' => $previous], ['status' => $status, 'number' => $order->number]);

        return $order->refresh();
    }

    /** @param list<string> $expected */
    private function lock(string $id, array $expected, string $action): PurchaseOrder
    {
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($id);

        if (! in_array($order->status, $expected, true)) {
            throw BusinessRuleViolation::invalidTransition('la commande', $order->status, $action);
        }

        return $order;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->companies->find($this->tenant->requireCompanyId())->timezone ?? 'Africa/Dakar');
    }
}
