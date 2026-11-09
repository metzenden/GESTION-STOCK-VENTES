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
 * @property string $category_id
 * @property string|null $supplier_id
 * @property string|null $description
 * @property string $currency
 * @property string $amount
 * @property string $exchange_rate
 * @property string $amount_reference
 * @property string $allocation_method
 * @property string|null $target_shipment_line_id
 * @property bool $is_estimate
 * @property string|null $supplier_bill_id
 * @property string $status
 * @property int $version
 * @property CarbonImmutable $created_at
 */
class ShipmentCost extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'shipment_costs';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_estimate' => 'boolean', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
