<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $packing_list_id
 * @property int $row_number
 * @property array<string, string|null> $raw
 * @property string|null $supplier_reference
 * @property string|null $description
 * @property int|null $cartons
 * @property int|null $pieces_per_carton
 * @property int|null $total_qty
 * @property string|null $cbm
 * @property string|null $unit_price
 * @property string|null $amount
 * @property string|null $matched_variant_id
 * @property string $match_status
 * @property list<array{field: string, message: string}>|null $errors
 */
class PackingListLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'packing_list_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'raw' => 'array', 'cartons' => 'integer', 'pieces_per_carton' => 'integer', 'total_qty' => 'integer', 'errors' => 'array'];
    }

    public function isResolved(): bool
    {
        return in_array($this->match_status, ['confirmed', 'new_product', 'ignored'], true)
            && ($this->match_status === 'ignored' || $this->matched_variant_id !== null);
    }
}
