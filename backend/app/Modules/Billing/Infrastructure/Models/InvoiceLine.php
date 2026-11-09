<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $sales_order_line_id
 * @property int $position
 * @property string $description
 * @property string $unit_label
 * @property int $qty_units
 * @property string $unit_price
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $line_total
 */
class InvoiceLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    public const UPDATED_AT = null;

    protected $table = 'invoice_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'qty_units' => 'integer'];
    }
}
