<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use App\Modules\Tenancy\Domain\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Entreprise hébergée (table globale, pas de portée d'entreprise).
 *
 * @property string $id
 * @property string $code
 * @property string $trade_name
 * @property string|null $legal_name
 * @property string|null $ninea
 * @property string|null $rccm
 * @property string|null $address
 * @property string|null $city
 * @property string $country_code
 * @property string|null $phone
 * @property string|null $email
 * @property string $reference_currency
 * @property string $timezone
 * @property CompanyStatus $status
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property CarbonImmutable $created_at
 */
class Company extends Model
{
    use Audited;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUlidKey;

    /** Champs d'identité modifiables (super-admin ou paramètres de l'entreprise). */
    public const IDENTITY_FIELDS = ['trade_name', 'legal_name', 'ninea', 'rccm', 'address', 'city', 'country_code', 'phone', 'email'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'suspended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function auditSubjectType(): string
    {
        return 'company';
    }

    public function auditCompanyId(): string
    {
        return $this->id;
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
