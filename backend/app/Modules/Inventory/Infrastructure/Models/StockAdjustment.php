<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $warehouse_id
 * @property string|null $inventory_id
 * @property string|null $transfer_id
 * @property string $status
 * @property string|null $reason
 * @property CarbonImmutable|null $posted_at
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class StockAdjustment extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'stock_adjustments';

    /** Horodatages TIMESTAMP(6) : la microseconde compte (figeage d'inventaire, INV-102). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id')->orderBy('id');
    }
}
