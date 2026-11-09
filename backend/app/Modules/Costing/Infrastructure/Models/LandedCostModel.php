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
 * @property string $code
 * @property string $name
 * @property int $version
 * @property bool $is_standard
 * @property list<string> $variables
 * @property string $expression
 * @property array<string, mixed>|null $conditions
 * @property CarbonImmutable|null $active_from
 * @property string $status
 * @property CarbonImmutable|null $tested_at
 * @property CarbonImmutable $created_at
 */
class LandedCostModel extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'landed_cost_models';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_standard' => 'boolean', 'variables' => 'array', 'conditions' => 'array', 'active_from' => 'immutable_date', 'tested_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
