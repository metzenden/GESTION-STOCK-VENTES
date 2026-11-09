<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $number
 * @property string $category_id
 * @property string $financial_account_id
 * @property CarbonImmutable $expense_date
 * @property string $label
 * @property string $amount
 * @property string $currency
 * @property string|null $attachment_file_id
 * @property string $status
 * @property string|null $financial_movement_id
 * @property string|null $reversal_reason
 * @property CarbonImmutable $created_at
 */
class Expense extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'expenses';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['expense_date' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }
}
