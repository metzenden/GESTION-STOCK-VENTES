<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

/** Vue publique d'une entreprise pour les autres modules. */
final readonly class CompanySummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $tradeName,
        public string $status,
        public string $referenceCurrency,
        public string $timezone,
    ) {}

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** @return array{id: string, code: string, trade_name: string, status: string, reference_currency: string, timezone: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'trade_name' => $this->tradeName,
            'status' => $this->status,
            'reference_currency' => $this->referenceCurrency,
            'timezone' => $this->timezone,
        ];
    }
}
