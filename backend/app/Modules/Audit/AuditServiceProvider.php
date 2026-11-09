<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Application\DatabaseAuditRecorder;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditRecorder::class, DatabaseAuditRecorder::class);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
