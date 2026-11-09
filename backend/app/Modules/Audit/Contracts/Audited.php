<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Observateur des données de référence (L1-08) : création, modification et suppression
 * sont journalisées avec les valeurs avant/après. Les attributs masqués ($hidden) ne sont jamais copiés.
 *
 * Le modèle peut définir auditSubjectType() pour le libellé du sujet (défaut : nom de table)
 * et auditCompanyId() lorsque l'entreprise ne se lit pas dans la colonne company_id.
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function (Model $model): void {
            self::writeAudit($model, 'created', [], self::auditable($model, $model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changes = self::auditable($model, $model->getChanges());
            unset($changes['updated_at'], $changes['updated_by']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key(self::auditable($model, $model->getOriginal()), $changes);
            self::writeAudit($model, 'updated', $old, $changes);
        });

        static::deleted(function (Model $model): void {
            self::writeAudit($model, 'deleted', self::auditable($model, $model->getOriginal()), []);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function auditable(Model $model, array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip($model->getHidden()));

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v), $values);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function writeAudit(Model $model, string $event, array $old, array $new): void
    {
        $type = method_exists($model, 'auditSubjectType') ? $model->auditSubjectType() : $model->getTable();
        $companyId = method_exists($model, 'auditCompanyId') ? $model->auditCompanyId() : ($model->getAttributes()['company_id'] ?? null);

        app(AuditRecorder::class)->record(
            action: "{$type}.{$event}",
            subjectType: $type,
            subjectId: (string) $model->getKey(),
            old: $old,
            new: $new,
            companyId: is_string($companyId) ? $companyId : null,
        );
    }
}
