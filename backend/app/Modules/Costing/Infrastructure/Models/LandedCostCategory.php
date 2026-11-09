<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $label
 * @property bool $is_system
 * @property string $default_allocation
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 */
class LandedCostCategory extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'landed_cost_categories';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
