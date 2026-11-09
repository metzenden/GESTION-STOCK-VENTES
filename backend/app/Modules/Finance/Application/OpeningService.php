<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Contracts\VariantCatalog;
use App\Modules\Finance\Contracts\FinancialLedger;
use App\Modules\Finance\Infrastructure\Models\CompanyOpening;
use App\Modules\Finance\Infrastructure\Models\FinancialAccount;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Inventory\Contracts\StockSource;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Document d'ouverture (L5-04, FIN-007, §18.2) : soldes des comptes (mouvements « opening ») et stock initial
 * (lots « opening » au coût saisi), une seule fois par entreprise ; ni vente ni achat n'est créé.
 */
final class OpeningService
{
    public const SOURCE = 'opening';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly FinancialLedger $finance,
        private readonly StockLedger $stock,
        private readonly VariantCatalog $catalog,
        private readonly WarehouseDirectory $warehouses,
        private readonly CompanyDirectory $companies,
        private readonly AuditRecorder $audit,
    ) {}

    public function current(): ?CompanyOpening
    {
        return CompanyOpening::query()->first();
    }

    /**
     * @param  list<array{account_id: string, amount: string}>  $accounts
     * @param  list<array{warehouse_id: string, variant_id: string, quantity: int, unit_cost: string}>  $stock
     */
    public function save(CarbonImmutable $startDate, array $accounts, array $stock, ?string $actorId): CompanyOpening
    {
        return $this->db->transaction(function () use ($startDate, $accounts, $stock, $actorId) {
            $opening = CompanyOpening::query()->lockForUpdate()->first() ?? new CompanyOpening;

            if ($opening->exists && $opening->status === 'posted') {
                throw BusinessRuleViolation::alreadyProcessed('L\'ouverture est déjà comptabilisée : elle ne se modifie plus.');
            }

            $this->validate($accounts, $stock);
            $opening->fill(['start_date' => $startDate->toDateString(), 'accounts' => $accounts, 'stock' => $stock, 'status' => 'draft'])
                ->forceFill([$opening->exists ? 'updated_by' : 'created_by' => $actorId])->save();

            return $opening->refresh();
        });
    }

    public function post(?string $actorId): CompanyOpening
    {
        return $this->db->transaction(function () use ($actorId) {
            $opening = CompanyOpening::query()->lockForUpdate()->first() ?? throw new BusinessRuleViolation('opening_missing', 'Aucune ouverture', 'Enregistrez d\'abord le document d\'ouverture.');

            if ($opening->status === 'posted') {
                throw BusinessRuleViolation::alreadyProcessed('L\'ouverture est déjà comptabilisée.');
            }

            $this->validate($opening->accounts, $opening->stock);
            $at = $opening->start_date->startOfDay();
            $currency = $this->companies->find($this->tenant->requireCompanyId())->referenceCurrency ?? 'XOF';
            $lots = 0;

            foreach ($opening->accounts as $line) {
                if (Decimal::of($line['amount'])->isPositive()) {
                    $this->finance->post($line['account_id'], 'opening', 'in', $line['amount'], self::SOURCE, $opening->id, 'Solde d\'ouverture', $at);
                }
            }

            foreach ($opening->stock as $index => $line) {
                $lot = $this->stock->createLot($line['variant_id'], 'opening', $opening->id, $at, $line['quantity'], $line['unit_cost'], $currency);
                $this->stock->receive($line['warehouse_id'], $lot, $line['quantity'], 'opening', new StockSource(self::SOURCE, $opening->id, $lot), $at);
                $lots++;
            }

            $this->catalog->markHistory(array_values(array_unique(array_map(fn (array $l) => $l['variant_id'], $opening->stock))));
            $opening->forceFill(['status' => 'posted', 'posted_at' => CarbonImmutable::now(), 'posted_by' => $actorId,
                'summary' => ['accounts' => count($opening->accounts), 'lots' => $lots]])->save();
            $this->audit->record('opening.posted', 'company_opening', $opening->id, [], ['accounts' => count($opening->accounts), 'lots' => $lots]);

            return $opening->refresh();
        }, 3);
    }

    /**
     * @param  list<array{account_id: string, amount: string}>  $accounts
     * @param  list<array{warehouse_id: string, variant_id: string, quantity: int, unit_cost: string}>  $stock
     */
    private function validate(array $accounts, array $stock): void
    {
        foreach ($accounts as $i => $line) {
            if (! FinancialAccount::query()->whereKey($line['account_id'])->exists()) {
                throw BusinessRuleViolation::invalidField("accounts/{$i}/account_id", 'Ce compte n\'existe pas.');
            }

            if (Decimal::of($line['amount'])->isNegative()) {
                throw BusinessRuleViolation::invalidField("accounts/{$i}/amount", 'Un solde d\'ouverture ne peut pas être négatif.');
            }
        }

        $variants = $this->catalog->describe(array_values(array_unique(array_map(fn (array $l) => $l['variant_id'], $stock))));

        foreach ($stock as $i => $line) {
            if (! isset($variants[$line['variant_id']]) || ! $variants[$line['variant_id']]->isStocked()) {
                throw BusinessRuleViolation::invalidField("stock/{$i}/variant_id", 'Cet article n\'existe pas ou n\'est pas stocké.');
            }

            if (! $this->warehouses->isActive($line['warehouse_id'])) {
                throw BusinessRuleViolation::invalidField("stock/{$i}/warehouse_id", 'Ce dépôt n\'existe pas ou est inactif.');
            }

            if ($line['quantity'] <= 0 || Decimal::of($line['unit_cost'])->isNegative()) {
                throw BusinessRuleViolation::invalidField("stock/{$i}/quantity", 'Quantité positive et coût positif ou nul attendus.');
            }
        }
    }
}
