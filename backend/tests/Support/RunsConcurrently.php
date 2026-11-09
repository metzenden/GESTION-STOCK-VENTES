<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lance N processus PHP indépendants (une connexion MySQL chacun) qui démarrent au même instant.
 */
final class RunsConcurrently
{
    /**
     * @param  array<string, mixed>  $args
     * @return list<mixed> résultats de chaque processus
     */
    public static function run(string $scenario, int $workers, array $args): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 2.0);
        $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.mysql.database')];
        $command = [PHP_BINARY, base_path('tests/Support/concurrency-worker.php'), $scenario, $startAt, json_encode($args, JSON_THROW_ON_ERROR)];

        $results = Process::pool(function ($pool) use ($workers, $command, $env) {
            for ($i = 0; $i < $workers; $i++) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start()->wait();

        $outputs = [];

        foreach ($results as $result) {
            if (! $result->successful()) {
                throw new RuntimeException("Processus de concurrence en échec :\n".$result->errorOutput().$result->output());
            }

            // Seule la dernière ligne est le résultat : d'éventuels avertissements PHP peuvent la précéder.
            $lines = preg_split('/\R/', trim($result->output())) ?: [];
            $last = (string) end($lines);

            if (! str_starts_with($last, '{"ok"')) {
                throw new RuntimeException("Sortie inattendue d'un processus de concurrence :\n".$result->output().$result->errorOutput());
            }

            $outputs[] = json_decode($last, true, flags: JSON_THROW_ON_ERROR)['result'];
        }

        return $outputs;
    }
}
