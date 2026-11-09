<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $shipment_id
 * @property int $position
 * @property string|null $container_id
 * @property string|null $purchase_order_line_id
 * @property string|null $supplier_id
 * @property string $variant_id
 * @property string $destination_warehouse_id
 * @property int|null $cartons
 * @property int|null $pieces_per_carton
 * @property int $qty_expected
 * @property int $qty_received
 * @property string|null $cbm_total
 * @property string $purchase_currency
 * @property string $purchase_unit_price
 * @property string $purchase_amount
 * @property string|null $packing_list_line_id
 * @property bool $is_closed
 */
class ShipmentLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'shipment_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'cartons' => 'integer', 'pieces_per_carton' => 'integer', 'qty_expected' => 'integer', 'qty_received' => 'integer', 'is_closed' => 'boolean'];
    }

    public function remaining(): int
    {
        return $this->is_closed ? 0 : max($this->qty_expected - $this->qty_received, 0);
    }
}
