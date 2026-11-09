<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

interface CompanyDirectory
{
    public function find(string $companyId): ?CompanySummary;

    /**
     * Identité modifiable par l'entreprise elle-même (L1-14).
     *
     * @return array<string, mixed>
     */
    public function identity(string $companyId): array;

    /** @param array<string, mixed> $fields */
    public function updateIdentity(string $companyId, array $fields): void;
}
