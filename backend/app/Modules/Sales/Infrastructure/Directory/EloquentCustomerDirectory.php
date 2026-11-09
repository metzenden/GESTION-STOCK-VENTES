<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Directory;

use App\Modules\Sales\Application\CustomerService;
use App\Modules\Sales\Contracts\CustomerDirectory;
use App\Modules\Sales\Infrastructure\Models\Customer;

final class EloquentCustomerDirectory implements CustomerDirectory
{
    public function __construct(private readonly CustomerService $customers) {}

    public function find(string $customerId): ?array
    {
        $c = Customer::query()->find($customerId);

        return $c === null ? null : ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone];
    }

    public function create(array $data, ?string $actorId): string
    {
        return $this->customers->create($data, $actorId)->id;
    }
}
