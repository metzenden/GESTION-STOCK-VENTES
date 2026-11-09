<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $return_id
 * @property string $sales_order_line_id
 * @property string $delivery_line_lot_id
 * @property string $lot_id
 * @property string $variant_id
 * @property int $quantity
 * @property string|null $disposition
 * @property string $unit_cost
 * @property string $credit_amount
 * @property string|null $hold_id
 */
class ReturnLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'return_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
