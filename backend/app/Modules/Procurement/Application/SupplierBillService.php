<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Procurement\Contracts\SupplierCommitments;
use App\Modules\Procurement\Infrastructure\Models\Supplier;
use App\Modules\Procurement\Infrastructure\Models\SupplierBill;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Engagements fournisseurs (D-026, PUR-001 à PUR-004) : la dette d'un fournisseur est, par devise,
 * Σ engagements − Σ règlements. Un engagement n'est jamais modifié ni supprimé : il est compensé.
 */
final class SupplierBillService implements SupplierCommitments
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly DocumentNumberAllocator $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function commit(string $supplierId, string $currency, string $amount, string $sourceType, string $sourceId, DateTimeInterface $date, ?string $description, ?string $actorId): string
    {
        return $this->create($supplierId, $currency, $amount, $sourceType, $sourceId, CarbonImmutable::instance($date), null, $description, null, null, $actorId)->id;
    }

    public function compensate(string $billId, string $description, ?string $actorId): void
    {
        $this->db->transaction(function () use ($billId, $description, $actorId) {
            $bill = SupplierBill::query()->lockForUpdate()->findOrFail($billId);

            if ($bill->status === 'cancelled') {
                return;
            }

            if (Decimal::of($bill->amount_paid)->isGreaterThan(0)) {
                // PUR-004 : les règlements doivent d'abord être traités (avoir fournisseur).
                throw new BusinessRuleViolation('commitment_paid', 'Engagement déjà réglé', 'Cet engagement a reçu des règlements : enregistrez d\'abord un avoir fournisseur.');
            }

            $compensation = $this->create($bill->supplier_id, $bill->currency, (string) Decimal::of($bill->amount)->negated(), $bill->source_type, $bill->source_id,
                CarbonImmutable::now(), null, $description, null, $bill->id, $actorId);

            // Engagement et compensation se neutralisent : ils sortent ensemble des engagements ouverts.
            $bill->forceFill(['status' => 'cancelled', 'updated_by' => $actorId])->save();
            $compensation->forceFill(['status' => 'cancelled'])->save();
        });
    }

    /** Engagement manuel, ou avoir fournisseur si le montant est négatif. */
    public function createManual(string $supplierId, string $amount, CarbonImmutable $billDate, ?CarbonImmutable $dueDate, ?string $invoiceRef, ?string $description, ?string $actorId): SupplierBill
    {
        $supplier = Supplier::query()->findOrFail($supplierId);
        $value = Decimal::of($amount);

        if ($value->isZero()) {
            throw BusinessRuleViolation::invalidField('amount', 'Le montant ne peut pas être nul.');
        }

        return $this->create($supplier->id, $supplier->currency, $amount, $value->isNegative() ? 'supplier_credit' : 'manual', null,
            $billDate, $dueDate, $description, $invoiceRef, null, $actorId);
    }

    /**
     * Relevé fournisseur par devise (PUR-002).
     *
     * @return list<array{currency: string, committed: string, paid: string, balance: string}>
     */
    public function balances(string $supplierId): array
    {
        $rows = $this->db->table('supplier_bills')
            ->where('company_id', $this->tenant->requireCompanyId())
            ->where('supplier_id', $supplierId)
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw('currency, SUM(amount) AS committed, SUM(amount_paid) AS paid')
            ->get();

        return array_values($rows->map(function (\stdClass $row): array {
            $committed = BigDecimal::of((string) $row->committed)->toScale(4);
            $paid = BigDecimal::of((string) $row->paid)->toScale(4);

            return ['currency' => (string) $row->currency, 'committed' => (string) $committed, 'paid' => (string) $paid, 'balance' => (string) $committed->minus($paid)];
        })->all());
    }

    private function create(string $supplierId, string $currency, string $amount, string $sourceType, ?string $sourceId, CarbonImmutable $billDate,
        ?CarbonImmutable $dueDate, ?string $description, ?string $invoiceRef, ?string $compensates, ?string $actorId): SupplierBill
    {
        $value = Decimal::of($amount)->toScale(4);

        if ($value->isZero()) {
            throw BusinessRuleViolation::invalidField('amount', 'Un engagement ne peut pas être nul.');
        }

        $bill = new SupplierBill;
        $bill->fill([
            'number' => $this->numbers->next($this->tenant->requireCompanyId(), DocumentType::SupplierBill, $billDate),
            'supplier_id' => $supplierId,
            'supplier_invoice_ref' => $invoiceRef,
            'description' => $description,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'compensates_bill_id' => $compensates,
            'currency' => $currency,
            'amount' => (string) $value,
            'bill_date' => $billDate->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'status' => 'open',
        ])->forceFill(['created_by' => $actorId])->save();

        Supplier::query()->whereKey($supplierId)->where('has_history', false)->update(['has_history' => true]);
        $this->audit->record('supplier_bill.created', 'supplier_bill', $bill->id, [], [
            'number' => $bill->number, 'supplier_id' => $supplierId, 'currency' => $currency, 'amount' => (string) $value, 'source_type' => $sourceType,
        ]);

        return $bill->refresh();
    }
}
