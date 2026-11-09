<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'company'])
        ->middleware(['company.context', 'can:audit.view'])->name('audit-logs.index');

    Route::get('admin/audit-logs', [AuditLogController::class, 'platform'])
        ->middleware(['company.context:optional', 'super-admin'])->name('admin.audit-logs.index');
});
