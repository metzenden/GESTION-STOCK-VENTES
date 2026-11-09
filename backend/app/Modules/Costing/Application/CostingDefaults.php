<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Costing\Domain\Services\LandedCostCalculator;
use App\Modules\Costing\Infrastructure\Models\LandedCostCategory;
use App\Modules\Costing\Infrastructure\Models\LandedCostModel;
use App\Modules\Tenancy\Contracts\CompanyProvisioningStep;
use Carbon\CarbonImmutable;

/**
 * Catégories de frais système et modèle standard (formule du CDC §9.3), créés avec l'entreprise
 * ou à la première utilisation pour les entreprises existantes. Les montants (douane comprise) sont
 * toujours saisis par l'utilisateur : aucun taux de droit n'est prédéfini (VR-08).
 */
final class CostingDefaults implements CompanyProvisioningStep
{
    public const STANDARD_CODE = 'STANDARD';

    /** @var array<string, string> */
    public const CATEGORIES = [
        'freight' => 'Fret et transport international',
        'customs' => 'Droits et taxes de douane',
        'handling' => 'Transit, manutention et frais portuaires',
        'local_transport' => 'Transport local',
        'other' => 'Autres frais d\'approche',
    ];

    public function priority(): int
    {
        return 50;
    }

    public function provision(string $companyId): void
    {
        $this->ensure();
    }

    public function ensure(): void
    {
        foreach (self::CATEGORIES as $code => $label) {
            LandedCostCategory::query()->firstOrCreate(['code' => $code], ['label' => $label, 'is_system' => true, 'default_allocation' => 'cbm']);
        }

        if (! LandedCostModel::query()->where('code', self::STANDARD_CODE)->exists()) {
            (new LandedCostModel)->fill([
                'code' => self::STANDARD_CODE,
                'name' => 'Formule standard (achat converti + frais au CBM + frais directs)',
                'version' => 1,
                'is_standard' => true,
                'variables' => LandedCostCalculator::VARIABLES,
                'expression' => LandedCostCalculator::STANDARD_EXPRESSION,
                'active_from' => CarbonImmutable::now()->toDateString(),
                'status' => 'active',
                'tested_at' => CarbonImmutable::now(),
            ])->save();
        }
    }

    public function activeStandard(): LandedCostModel
    {
        $this->ensure();

        return LandedCostModel::query()->where('code', self::STANDARD_CODE)->where('status', 'active')->orderByDesc('version')->firstOrFail();
    }
}
