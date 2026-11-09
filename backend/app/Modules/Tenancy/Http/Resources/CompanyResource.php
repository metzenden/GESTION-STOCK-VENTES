<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Infrastructure\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
final class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'trade_name' => $this->trade_name,
            'legal_name' => $this->legal_name,
            'ninea' => $this->ninea,
            'rccm' => $this->rccm,
            'address' => $this->address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'reference_currency' => $this->reference_currency,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'suspended_at' => $this->suspended_at?->toIso8601ZuluString(),
            'suspension_reason' => $this->suspension_reason,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
