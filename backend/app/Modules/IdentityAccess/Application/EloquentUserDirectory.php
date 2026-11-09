<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Modules\IdentityAccess\Contracts\UserDirectory;
use App\Modules\IdentityAccess\Domain\Enums\UserStatus;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Shared\Contracts\TenantContext;

final class EloquentUserDirectory implements UserDirectory
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function withPermission(string $permission): array
    {
        $users = User::query()->where('company_id', $this->tenant->requireCompanyId())->where('status', UserStatus::Active->value)->orderBy('name')->get();

        return array_values($users->filter(fn (User $u) => $u->hasPermissionTo($permission))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all());
    }

    public function roleIds(string $userId): array
    {
        $user = User::query()->where('company_id', $this->tenant->requireCompanyId())->find($userId);

        return $user === null ? [] : array_values(array_map('strval', $user->roles()->pluck('id')->all()));
    }

    public function hasPermission(string $userId, string $permission): bool
    {
        $user = User::query()->where('company_id', $this->tenant->requireCompanyId())->find($userId);

        return $user !== null && $user->hasPermissionTo($permission);
    }

    public function find(string $userId): ?array
    {
        $user = User::query()->where('company_id', $this->tenant->requireCompanyId())->find($userId);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
