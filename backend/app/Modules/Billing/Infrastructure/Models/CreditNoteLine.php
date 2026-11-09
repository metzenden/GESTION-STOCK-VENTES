<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string|null $sales_order_line_id
 * @property string $description
 * @property int|null $quantity
 * @property string|null $unit_price
 * @property string $tax_amount
 * @property string $line_total
 */
class CreditNoteLine extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'credit_note_lines';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
