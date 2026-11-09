<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Console;

use App\Modules\Shared\Infrastructure\Health\HealthChecker;
use Illuminate\Console\Command;

final class HealthCommand extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Vérifie la disponibilité de MySQL et Redis (utilisé par le healthcheck Docker).';

    public function handle(HealthChecker $checker): int
    {
        $results = $checker->check();

        foreach ($results as $service => $ok) {
            $this->line(sprintf('%-10s %s', $service, $ok ? 'OK' : 'ÉCHEC'));
        }

        return in_array(false, $results, true) ? self::FAILURE : self::SUCCESS;
    }
}
