<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $shipment_id
 * @property string $model_id
 * @property int $revision
 * @property string $status
 * @property string $reference_currency
 * @property array<string, mixed> $rates_snapshot
 * @property string $inputs_hash
 * @property string $total_cbm
 * @property string $total_common_fees
 * @property string $total_direct_fees
 * @property string $total_purchase
 * @property string $total_cost
 * @property list<array<string, mixed>>|null $blocking_issues
 * @property list<array<string, mixed>>|null $discrepancies
 * @property CarbonImmutable|null $computed_at
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $finalized_by
 * @property CarbonImmutable $created_at
 */
class LandedCostCalculation extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'landed_cost_calculations';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'final_flag'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'rates_snapshot' => 'array', 'blocking_issues' => 'array', 'discrepancies' => 'array', 'computed_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
