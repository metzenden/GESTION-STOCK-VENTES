<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $transfer_id
 * @property string $variant_id
 * @property string|null $lot_id
 * @property string|null $parent_line_id
 * @property int $qty_requested
 * @property int $qty_sent
 * @property int $qty_received
 */
class StockTransferLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'stock_transfer_lines';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['qty_requested' => 'integer', 'qty_sent' => 'integer', 'qty_received' => 'integer'];
    }
}
