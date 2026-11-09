<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property int $position
 * @property string $variant_id
 * @property string $product_type
 * @property string $unit_id
 * @property int $unit_factor
 * @property string $unit_label
 * @property string $description
 * @property int $qty_units
 * @property int $qty_base
 * @property string $list_unit_price
 * @property string $unit_price
 * @property bool $price_overridden
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string $discount_amount
 * @property string|null $tax_rate_id
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $line_total
 * @property string $quotation_id
 */
class QuotationLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'quotation_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'price_overridden'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'unit_factor' => 'integer', 'qty_units' => 'integer', 'qty_base' => 'integer', 'price_overridden' => 'boolean'];
    }
}
