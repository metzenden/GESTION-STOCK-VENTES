<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Console;

use App\Modules\Inventory\Application\StockReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** inventory:reconcile — exécuté chaque nuit ; tout écart déclenche une alerte critique (INV-008). */
final class ReconcileStockCommand extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Rapproche les projections de stock des mouvements, réservations et blocages';

    public function handle(StockReconciler $reconciler): int
    {
        $issues = $reconciler->discrepancies();

        if ($issues === []) {
            $this->info('Stock cohérent : aucune divergence.');

            return self::SUCCESS;
        }

        Log::critical('Divergence de stock détectée', ['count' => count($issues), 'issues' => array_slice($issues, 0, 50)]);

        foreach ($issues as $issue) {
            $this->error("{$issue['kind']} {$issue['key']} (entreprise {$issue['company_id']}) : attendu {$issue['expected']}, constaté {$issue['actual']}");
        }

        return self::FAILURE;
    }
}
