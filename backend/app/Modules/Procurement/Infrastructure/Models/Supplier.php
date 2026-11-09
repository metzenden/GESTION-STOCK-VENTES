<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $supplier_type
 * @property string|null $country_code
 * @property string $currency
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $contact_name
 * @property string|null $notes
 * @property string $status
 * @property bool $has_history
 * @property CarbonImmutable $created_at
 */
class Supplier extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'suppliers';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['has_history' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function auditSubjectType(): string
    {
        return 'supplier';
    }
}
