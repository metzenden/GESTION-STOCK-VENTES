<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $purchase_order_id
 * @property int $position
 * @property string $variant_id
 * @property string $unit_id
 * @property int $unit_factor
 * @property int $qty_units
 * @property int $qty_base
 * @property string $unit_price
 * @property string $line_total
 * @property int $qty_received
 */
class PurchaseOrderLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'purchase_order_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'unit_factor' => 'integer', 'qty_units' => 'integer', 'qty_base' => 'integer', 'qty_received' => 'integer'];
    }
}
