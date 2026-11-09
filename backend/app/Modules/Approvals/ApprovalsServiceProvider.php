<?php

declare(strict_types=1);

namespace App\Modules\Approvals;

use App\Modules\Approvals\Application\ApprovalEngine;
use App\Modules\Approvals\Contracts\ApprovalGate;
use App\Modules\ModuleServiceProvider;

final class ApprovalsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Instance par requête ou par tâche : l'état (dernière demande déposée) ne doit jamais passer d'une requête à l'autre.
        $this->app->scoped(ApprovalGate::class, ApprovalEngine::class);
        $this->app->scoped(ApprovalEngine::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
