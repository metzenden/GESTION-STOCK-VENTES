<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property CarbonImmutable $start_date
 * @property string $status
 * @property list<array{account_id: string, amount: string}> $accounts
 * @property list<array{warehouse_id: string, variant_id: string, quantity: int, unit_cost: string}> $stock
 * @property CarbonImmutable|null $posted_at
 * @property array<string, mixed>|null $summary
 */
class CompanyOpening extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'company_openings';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'accounts' => 'array', 'stock' => 'array', 'posted_at' => 'immutable_datetime', 'summary' => 'array'];
    }
}
