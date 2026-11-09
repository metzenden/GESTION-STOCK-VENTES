<?php

declare(strict_types=1);

/*
| Processus de test de concurrence : démarre l'application, attend l'instant de départ commun,
| exécute un scénario puis écrit ses résultats en JSON sur la sortie standard.
|
| Usage : php tests/Support/concurrency-worker.php <scénario> <départ-microtime> <arguments JSON>
*/

use App\Modules\Approvals\Application\ApprovalEngine;
use App\Modules\IdentityAccess\Infrastructure\Models\User;
use App\Modules\Inventory\Contracts\StockLedger;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Shared\Contracts\TenantContext;
use App\Modules\Shared\Domain\Enums\DocumentType;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Sequences\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $scenario, $startAt, $json] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$scenarios = [
    'allocate-numbers' => function (array $args): array {
        $numbers = [];

        for ($i = 0; $i < $args['count']; $i++) {
            $numbers[] = DB::transaction(
                fn () => app(DocumentNumberAllocator::class)->next($args['company_id'], DocumentType::from($args['type']), CarbonImmutable::parse($args['date'])),
                attempts: 3,
            );
        }

        return $numbers;
    },

    // TEST-006 : chaque processus tente de réserver la même quantité.
    'reserve-stock' => function (array $args): string {
        auth()->guard('web')->setUser(User::query()->findOrFail($args['user_id']));
        app(TenantContext::class)->set($args['company_id']);

        try {
            DB::transaction(fn () => app(StockLedger::class)->reserve(
                $args['warehouse_id'], $args['variant_id'], $args['quantity'], 'sales_order_line', (string) Str::ulid(),
            ), attempts: 3);

            return 'reserved';
        } catch (BusinessRuleViolation $e) {
            return $e->errorCode;
        }
    },

    // L7-01 : règlements simultanés sur une même facture — jamais de trop-perçu (PAY-003).
    'pay-invoice' => function (array $args): string {
        auth()->guard('web')->setUser(User::query()->findOrFail($args['user_id']));
        app(TenantContext::class)->set($args['company_id']);

        try {
            app(PaymentService::class)->create($args['invoice_id'], 'cash', $args['account_id'], $args['amount'], CarbonImmutable::now(), null, $args['user_id']);

            return 'paid';
        } catch (BusinessRuleViolation $e) {
            return $e->errorCode;
        }
    },

    // L7-01 : décisions simultanées sur une même demande — un seul effet (APR-005).
    'approve-request' => function (array $args): string {
        auth()->guard('web')->setUser(User::query()->findOrFail($args['user_id']));
        app(TenantContext::class)->set($args['company_id']);

        try {
            app(ApprovalEngine::class)->decide($args['request_id'], true, null, $args['user_id']);

            return 'approved';
        } catch (BusinessRuleViolation $e) {
            return $e->errorCode;
        }
    },
];

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

echo PHP_EOL.json_encode(['ok' => true, 'result' => $scenarios[$scenario]($args)], JSON_THROW_ON_ERROR);
