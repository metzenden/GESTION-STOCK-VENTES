<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux daté : 1 unité de from_currency = rate unités de to_currency.
 *
 * @property string $id
 * @property string $company_id
 * @property string $from_currency
 * @property string $to_currency
 * @property string $rate
 * @property CarbonImmutable $valid_on
 * @property string|null $source
 */
class ExchangeRate extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['valid_on' => 'immutable_date'];
    }

    public function auditSubjectType(): string
    {
        return 'exchange_rate';
    }
}
