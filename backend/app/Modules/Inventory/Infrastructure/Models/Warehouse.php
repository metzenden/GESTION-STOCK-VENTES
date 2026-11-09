<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string $status
 */
class Warehouse extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $guarded = ['id', 'company_id'];

    public function auditSubjectType(): string
    {
        return 'warehouse';
    }
}
