<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Contracts;

final readonly class SupplierSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $type,
        public string $currency,
        public string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return array{id: string, code: string, name: string, supplier_type: string, currency: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'supplier_type' => $this->type, 'currency' => $this->currency];
    }
}
