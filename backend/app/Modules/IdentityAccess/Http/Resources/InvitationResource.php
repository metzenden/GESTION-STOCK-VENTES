<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Modules\IdentityAccess\Infrastructure\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invitation */
final class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => ['id' => $this->role_id, 'name' => $this->role?->name],
            'status' => $this->status(),
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
