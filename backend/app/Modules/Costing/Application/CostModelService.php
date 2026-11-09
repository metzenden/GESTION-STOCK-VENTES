<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Costing\Domain\Expressions\Evaluator;
use App\Modules\Costing\Domain\Expressions\ExpressionError;
use App\Modules\Costing\Domain\Expressions\Parser;
use App\Modules\Costing\Domain\Services\LandedCostCalculator;
use App\Modules\Costing\Infrastructure\Models\LandedCostModel;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Domain\ValueObjects\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Modèles de formules (CR-005, CR-014) : brouillon → testé → actif ; une version active est immuable,
 * toute évolution crée une nouvelle version et l'ancienne est retirée à l'activation de la suivante.
 */
final class CostModelService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(string $code, string $name, string $expression, ?string $actorId): LandedCostModel
    {
        if (LandedCostModel::query()->where('code', $code)->exists()) {
            throw BusinessRuleViolation::invalidField('code', 'Ce code de modèle existe déjà ; créez une nouvelle version.', 'duplicate');
        }

        return $this->draft($code, $name, 1, $expression, $actorId);
    }

    public function newVersion(string $modelId, ?string $name, string $expression, ?string $actorId): LandedCostModel
    {
        return $this->db->transaction(function () use ($modelId, $name, $expression, $actorId) {
            $base = LandedCostModel::query()->findOrFail($modelId);
            $next = (int) LandedCostModel::query()->where('code', $base->code)->lockForUpdate()->max('version') + 1;

            return $this->draft($base->code, $name ?? $base->name, $next, $expression, $actorId);
        });
    }

    /**
     * Évalue la formule sur un jeu d'essai (valeurs fournies ou jeu par défaut) ; enregistre le test réussi.
     *
     * @param  array<string, string>  $sample
     * @return array{result: string, variables: array<string, string>}
     */
    public function test(string $modelId, array $sample, ?string $actorId): array
    {
        $model = LandedCostModel::query()->findOrFail($modelId);
        $values = self::defaultSample();

        foreach ($sample as $name => $value) {
            if (! in_array($name, LandedCostCalculator::VARIABLES, true) || ! Decimal::isValid($value)) {
                throw BusinessRuleViolation::invalidField("sample/{$name}", 'Variable inconnue ou valeur invalide.');
            }

            $values[$name] = Decimal::of($value);
        }

        try {
            $result = (new Evaluator)->evaluate((new Parser(LandedCostCalculator::VARIABLES))->parse($model->expression), $values);
        } catch (ExpressionError $e) {
            throw BusinessRuleViolation::invalidField('expression', $e->getMessage(), 'expression');
        }

        if ($model->status === 'draft') {
            $model->forceFill(['tested_at' => CarbonImmutable::now(), 'updated_by' => $actorId])->save();
        }

        return ['result' => (string) $result, 'variables' => array_map(fn (BigDecimal $v) => (string) $v, $values)];
    }

    public function activate(string $modelId, ?string $actorId): LandedCostModel
    {
        return $this->db->transaction(function () use ($modelId, $actorId) {
            $model = LandedCostModel::query()->lockForUpdate()->findOrFail($modelId);

            if ($model->status !== 'draft') {
                throw BusinessRuleViolation::invalidTransition('le modèle', $model->status, 'activer');
            }

            if ($model->tested_at === null) {
                throw new BusinessRuleViolation('model_not_tested', 'Modèle non testé', 'Testez la formule avant de l\'activer (CR-014).');
            }

            LandedCostModel::query()->where('code', $model->code)->where('status', 'active')->update(['status' => 'retired', 'updated_by' => $actorId]);
            $model->forceFill(['status' => 'active', 'active_from' => CarbonImmutable::now()->toDateString(), 'updated_by' => $actorId])->save();
            $this->audit->record('landed_cost_model.activated', 'landed_cost_model', $model->id, [], ['code' => $model->code, 'version' => $model->version, 'expression' => $model->expression]);

            return $model;
        });
    }

    private function draft(string $code, string $name, int $version, string $expression, ?string $actorId): LandedCostModel
    {
        try {
            $variables = (new Parser(LandedCostCalculator::VARIABLES))->parse($expression)->variables();
        } catch (ExpressionError $e) {
            throw new BusinessRuleViolation('validation_failed', 'Données invalides', $e->getMessage(), 422, [],
                [['pointer' => '/expression', 'code' => 'expression', 'message' => $e->getMessage(), 'meta' => ['position' => $e->position]]]);
        }

        $model = new LandedCostModel;
        $model->fill([
            'code' => $code,
            'name' => $name,
            'version' => $version,
            'is_standard' => false,
            'variables' => $variables,
            'expression' => trim($expression),
            'status' => 'draft',
        ])->forceFill(['created_by' => $actorId])->save();

        return $model->refresh();
    }

    /** @return array<string, BigDecimal> */
    private static function defaultSample(): array
    {
        return [
            'purchase_foreign' => BigDecimal::of('1000'),
            'exchange_rate' => BigDecimal::of('655.957'),
            'purchase_converted' => BigDecimal::of('655957'),
            'common_fees' => BigDecimal::of('200000'),
            'direct_fees' => BigDecimal::of('15000'),
            'cbm' => BigDecimal::of('2'),
            'cbm_ratio' => BigDecimal::of('0.4'),
            'qty' => BigDecimal::of('100'),
            'pieces_per_carton' => BigDecimal::of('10'),
        ];
    }
}
