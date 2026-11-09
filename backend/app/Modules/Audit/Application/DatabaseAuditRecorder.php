<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Shared\Contracts\CompanyMember;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DatabaseAuditRecorder implements AuditRecorder
{
    /** Clés jamais journalisées, même hors $hidden. */
    private const SECRET_KEYS = ['password', 'remember_token', 'token', 'token_hash', 'two_factor_secret'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TenantContext $tenant,
        private readonly AuthFactory $auth,
        private readonly Container $container,
    ) {}

    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?string $companyId = null,
        ?string $userId = null,
        bool $platform = false,
    ): void {
        $user = $this->auth->guard()->user();
        $member = $user instanceof CompanyMember ? $user : null;
        $activeCompany = $this->tenant->companyId();

        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        $request = $request instanceof Request ? $request : null;

        $this->db->table('audit_logs')->insert([
            'id' => strtoupper((string) Str::ulid()),
            'company_id' => $platform ? null : ($companyId ?? $activeCompany),
            'user_id' => $userId ?? $member?->memberId(),
            'impersonated_company_id' => $member?->isSuperAdmin() === true ? $activeCompany : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $old === [] ? null : json_encode($this->scrub($old), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => $new === [] ? null : json_encode($this->scrub($new), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null ? Str::limit($reason, 497) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 252) : null,
            'request_id' => $request?->attributes->get(AssignRequestId::ATTRIBUTE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scrub(array $values): array
    {
        return array_diff_key($values, array_flip(self::SECRET_KEYS));
    }
}
