<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property string $operation
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $rule_id
 * @property string $approver_permission
 * @property string $summary
 * @property array<string, mixed> $context
 * @property string $status
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $comment
 * @property bool $self_approved
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 */
class ApprovalRequest extends Model
{
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'approval_requests';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id', 'open_key'];

    protected function casts(): array
    {
        return ['context' => 'array', 'decided_at' => 'immutable_datetime', 'self_approved' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
