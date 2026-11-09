<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Modules\IdentityAccess\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'roles' => $this->roles->map(fn (Model $role) => ['id' => (string) $role->getKey(), 'name' => (string) $role->getAttribute('name')])->values()->all(),
        ];
    }
}
