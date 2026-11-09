<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialAccount;
use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;

/** L5a-01 : quatre comptes par défaut, dans la devise de référence (caisse, Wave, Orange Money, banque). */
final class ProvisionFinance implements CompanyProvisioningStep
{
    public const DEFAULTS = [
        ['CAISSE', 'Caisse', 'cash'],
        ['WAVE', 'Wave', 'wave'],
        ['OM', 'Orange Money', 'orange_money'],
        ['BANQUE', 'Banque', 'bank'],
    ];

    public const EXPENSE_CATEGORIES = ['Loyer', 'Salaires', 'Électricité et eau', 'Transport', 'Communication', 'Fournitures', 'Autres charges'];

    public function __construct(private readonly CompanyDirectory $companies) {}

    public function priority(): int
    {
        return 45;
    }

    public function provision(string $companyId): void
    {
        $currency = $this->companies->find($companyId)->referenceCurrency ?? 'XOF';

        foreach (self::DEFAULTS as [$code, $name, $type]) {
            FinancialAccount::query()->firstOrCreate(['code' => $code], ['name' => $name, 'account_type' => $type, 'currency' => $currency, 'status' => 'active']);
        }

        // Catégories de dépenses usuelles, modifiables (L5-02).
        foreach (self::EXPENSE_CATEGORIES as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
