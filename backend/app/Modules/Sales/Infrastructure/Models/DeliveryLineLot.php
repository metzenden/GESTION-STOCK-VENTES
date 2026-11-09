<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $delivery_line_id
 * @property string $lot_id
 * @property int $quantity
 * @property string $allocation_method
 * @property string $unit_cost
 * @property int $attempt
 * @property string $status
 */
class DeliveryLineLot extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'delivery_line_lots';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'attempt' => 'integer'];
    }
}
