<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $delivery_id
 * @property string $sales_order_line_id
 * @property string $variant_id
 * @property int $quantity
 * @property int $qty_delivered
 */
class DeliveryLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'delivery_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'qty_delivered' => 'integer'];
    }
}
