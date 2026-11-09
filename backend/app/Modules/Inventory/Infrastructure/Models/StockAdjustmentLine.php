<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $adjustment_id
 * @property string $variant_id
 * @property string|null $lot_id
 * @property int $quantity
 * @property string $kind
 * @property string|null $unit_cost
 * @property string|null $hold_id
 * @property string|null $created_lot_id
 */
class StockAdjustmentLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'stock_adjustment_lines';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
