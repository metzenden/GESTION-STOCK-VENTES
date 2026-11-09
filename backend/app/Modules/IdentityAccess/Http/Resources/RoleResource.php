<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Modules\IdentityAccess\Domain\Enums\RoleTemplate;
use App\Modules\IdentityAccess\Infrastructure\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'template_code' => $this->template_code,
            'locked' => $this->template_code === RoleTemplate::CompanyAdmin->value,
            'permissions' => $this->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
