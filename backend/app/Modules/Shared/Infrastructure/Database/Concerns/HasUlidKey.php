<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Database\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * Clé primaire ULID en majuscules (alphabet Crockford), conforme au schéma OpenAPI `Ulid` (D-002).
 */
trait HasUlidKey
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @param mixed $value */
    protected function isValidUniqueId($value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }
}
