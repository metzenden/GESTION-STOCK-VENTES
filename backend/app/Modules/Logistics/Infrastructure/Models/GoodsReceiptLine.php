<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $goods_receipt_id
 * @property string|null $shipment_line_id
 * @property string|null $purchase_order_line_id
 * @property string $variant_id
 * @property int $qty_expected
 * @property int $qty_accepted
 * @property int $qty_damaged
 * @property int $qty_counted
 * @property int $qty_missing
 * @property int $qty_surplus
 * @property string $damaged_disposition
 * @property bool $is_counted
 * @property string|null $lot_id
 * @property string|null $note
 */
class GoodsReceiptLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'goods_receipt_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['qty_expected' => 'integer', 'qty_accepted' => 'integer', 'qty_damaged' => 'integer', 'qty_counted' => 'integer', 'qty_missing' => 'integer', 'qty_surplus' => 'integer', 'is_counted' => 'boolean'];
    }

    /** Quantité entrant en stock : acceptée, plus l'endommagé conservé (bloqué, IMP-003). */
    public function stockQuantity(): int
    {
        return $this->qty_accepted + ($this->damaged_disposition === 'hold' ? $this->qty_damaged : 0);
    }
}
