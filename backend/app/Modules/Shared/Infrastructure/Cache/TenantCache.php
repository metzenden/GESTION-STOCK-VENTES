<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Cache;

use App\Modules\Shared\Contracts\TenantContext;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cache métier préfixé par l'entreprise active (c:{company_id}:) : une clé de A n'est jamais lue depuis B.
 */
final class TenantCache
{
    public function __construct(
        private readonly Repository $cache,
        private readonly TenantContext $tenant,
    ) {}

    public function key(string $key): string
    {
        return 'c:'.$this->tenant->requireCompanyId().':'.$key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->cache->put($this->key($key), $value, $ttl);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return $this->cache->remember($this->key($key), $ttl, $callback);
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($this->key($key));
    }
}
