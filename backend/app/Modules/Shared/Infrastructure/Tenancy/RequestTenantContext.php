<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Tenancy;

use App\Modules\Shared\Contracts\TenantContext;
use LogicException;

final class RequestTenantContext implements TenantContext
{
    private ?string $companyId = null;

    public function companyId(): ?string
    {
        return $this->companyId;
    }

    public function requireCompanyId(): string
    {
        return $this->companyId ?? throw new LogicException(
            'Aucune entreprise active : opération métier refusée en dehors d\'un contexte d\'entreprise.'
        );
    }

    public function set(?string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function runAs(string $companyId, callable $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
