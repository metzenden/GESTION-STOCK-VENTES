<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Approvals\Contracts\ApprovableOperation;
use App\Modules\ModuleServiceProvider;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Contracts\PaymentRecorder;
use App\Modules\Payments\Infrastructure\Approvals\PaymentApprovals;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRecorder::class, PaymentService::class);
        $this->app->tag([PaymentApprovals::class], ApprovableOperation::TAG);
    }

    protected function modulePath(): string
    {
        return __DIR__;
    }
}
