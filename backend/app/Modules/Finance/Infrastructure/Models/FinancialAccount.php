<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $account_type
 * @property string $currency
 * @property bool $allow_negative
 * @property string $balance
 * @property string $status
 */
class FinancialAccount extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'financial_accounts';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'balance'];

    protected function casts(): array
    {
        return ['allow_negative' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'financial_account';
    }
}
