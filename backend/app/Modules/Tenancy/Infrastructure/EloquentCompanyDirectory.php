<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure;

use App\Modules\Tenancy\Contracts\CompanyDirectory;
use App\Modules\Tenancy\Contracts\CompanySummary;
use App\Modules\Tenancy\Infrastructure\Models\Company;

final class EloquentCompanyDirectory implements CompanyDirectory
{
    public function find(string $companyId): ?CompanySummary
    {
        $company = Company::query()->find($companyId);

        return $company === null ? null : new CompanySummary(
            $company->id,
            $company->code,
            $company->trade_name,
            $company->status->value,
            $company->reference_currency,
            $company->timezone,
        );
    }

    public function identity(string $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);

        return ['code' => $company->code, 'reference_currency' => $company->reference_currency]
            + $company->only(Company::IDENTITY_FIELDS);
    }

    public function updateIdentity(string $companyId, array $fields): void
    {
        $company = Company::query()->findOrFail($companyId);
        $company->fill(array_intersect_key($fields, array_flip(Company::IDENTITY_FIELDS)));
        $company->save();
    }
}
