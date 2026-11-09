<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Database\Concerns;

use App\Modules\Shared\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Portée d'entreprise d'un modèle métier (docs/02 §3.1, couche 2).
 *
 * - Toute requête est filtrée sur l'entreprise active ; sans contexte, la requête échoue (fail closed).
 * - À la création, company_id est imposé par le contexte : une valeur fournie différente est refusée.
 * - company_id n'est jamais modifiable.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('company_id'), app(TenantContext::class)->requireCompanyId());
        });

        static::creating(function (Model $model): void {
            $companyId = app(TenantContext::class)->requireCompanyId();
            $given = $model->getAttribute('company_id');

            if ($given !== null && $given !== $companyId) {
                throw new LogicException('Création refusée : company_id différent de l\'entreprise active.');
            }

            $model->setAttribute('company_id', $companyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new LogicException('company_id n\'est pas modifiable.');
            }
        });
    }
}
