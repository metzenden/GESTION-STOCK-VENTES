<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

/** Clients de l'entreprise active, vus par la facturation. */
interface CustomerDirectory
{
    /** @return array{id: string, code: string, name: string, phone: string|null}|null */
    public function find(string $customerId): ?array;

    /** @param array<string, mixed> $data  Création (import initial) ; renvoie l'identifiant. */
    public function create(array $data, ?string $actorId): string;
}
