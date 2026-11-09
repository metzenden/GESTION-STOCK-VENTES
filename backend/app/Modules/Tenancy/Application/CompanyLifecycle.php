<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Tenancy\Domain\Enums\CompanyStatus;
use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/** Suspension et réactivation (L1-12, D-041) ; journalisées par l'observateur d'audit de Company. */
final class CompanyLifecycle
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function suspend(string $companyId, string $reason, ?string $userId): Company
    {
        return $this->db->transaction(function () use ($companyId, $reason, $userId) {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);

            if ($company->status !== CompanyStatus::Active) {
                throw BusinessRuleViolation::invalidTransition('l\'entreprise', $company->status->value, 'suspendre');
            }

            $company->forceFill([
                'status' => CompanyStatus::Suspended,
                'suspended_at' => Carbon::now(),
                'suspension_reason' => $reason,
                'updated_by' => $userId,
            ])->save();

            return $company;
        });
    }

    public function reactivate(string $companyId, ?string $userId): Company
    {
        return $this->db->transaction(function () use ($companyId, $userId) {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);

            if ($company->status !== CompanyStatus::Suspended) {
                throw BusinessRuleViolation::invalidTransition('l\'entreprise', $company->status->value, 'réactiver');
            }

            $company->forceFill([
                'status' => CompanyStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
                'updated_by' => $userId,
            ])->save();

            return $company;
        });
    }
}
