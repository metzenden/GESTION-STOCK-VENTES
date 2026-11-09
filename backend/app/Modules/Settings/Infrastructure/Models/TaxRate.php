<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Taux de taxe (M-01 : aucun par défaut, à saisir après vérification réglementaire).
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $rate
 * @property bool $is_active
 */
class TaxRate extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'tax_rate';
    }
}
