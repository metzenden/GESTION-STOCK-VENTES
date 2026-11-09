<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

/** Utilisateurs de l'entreprise active, vus par les autres modules (approbations, notifications). */
interface UserDirectory
{
    /** @return list<array{id: string, name: string, email: string}> utilisateurs actifs détenant la permission */
    public function withPermission(string $permission): array;

    /** @return list<string> identifiants des rôles de l'utilisateur dans l'entreprise active */
    public function roleIds(string $userId): array;

    public function hasPermission(string $userId, string $permission): bool;

    /** @return array{id: string, name: string, email: string}|null */
    public function find(string $userId): ?array;
}
