<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Infrastructure\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Shared\Infrastructure\Database\Concerns\BelongsToCompany;
use App\Modules\Shared\Infrastructure\Database\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $operation
 * @property string|null $role_id
 * @property string $threshold_type
 * @property string|null $threshold_value
 * @property string $approver_permission
 * @property int $priority
 * @property bool $is_active
 */
class ApprovalRule extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasUlidKey;

    protected $table = 'approval_rules';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean'];
    }

    public function auditSubjectType(): string
    {
        return 'approval_rule';
    }
}
