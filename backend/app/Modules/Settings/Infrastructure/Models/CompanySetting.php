<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres d'une entreprise (une ligne par entreprise).
 *
 * @property string $company_id
 * @property int $default_payment_terms_days
 * @property string $rounding_mode
 * @property int $low_stock_default_threshold
 * @property string|null $invoice_legal_mentions
 * @property string|null $invoice_footer
 * @property string|null $payment_instructions
 * @property CarbonImmutable|null $start_date
 * @property bool $allow_self_approval
 * @property string|null $default_sales_warehouse_id
 */
class CompanySetting extends Model
{
    use Audited;
    use BelongsToCompany;

    public const EDITABLE = [
        'default_payment_terms_days', 'low_stock_default_threshold', 'invoice_legal_mentions',
        'invoice_footer', 'payment_instructions', 'start_date', 'allow_self_approval',
    ];

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return [
            'default_payment_terms_days' => 'integer',
            'low_stock_default_threshold' => 'integer',
            'start_date' => 'immutable_date',
            'allow_self_approval' => 'boolean',
        ];
    }

    public function auditSubjectType(): string
    {
        return 'company_settings';
    }
}
