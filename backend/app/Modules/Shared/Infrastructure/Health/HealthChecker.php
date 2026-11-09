<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Health;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Redis\RedisManager;
use Throwable;

/**
 * Contrôles de santé des dépendances critiques (CDC §24.4).
 */
final class HealthChecker
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RedisManager $redis,
    ) {}

    /** @return array<string, bool> */
    public function check(): array
    {
        return [
            'database' => $this->probe(fn () => $this->db->selectOne('SELECT 1 AS ok') !== null),
            'redis' => $this->probe(fn () => (bool) $this->redis->connection()->ping()),
        ];
    }

    public function healthy(): bool
    {
        return ! in_array(false, $this->check(), true);
    }

    /** @param callable(): bool $probe */
    private function probe(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
