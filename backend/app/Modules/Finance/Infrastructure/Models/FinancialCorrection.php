<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $financial_account_id
 * @property string $direction
 * @property string $amount
 * @property string $reason
 * @property string $status
 * @property string|null $financial_movement_id
 * @property CarbonImmutable|null $posted_at
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 */
class FinancialCorrection extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'financial_corrections';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
