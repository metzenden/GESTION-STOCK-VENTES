<?php

declare(strict_types=1);

use App\Modules\Approvals\Http\Controllers\ApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::get('approval-rules', [ApprovalController::class, 'rules'])->middleware('can:settings.view')->name('approval-rules.index');
    Route::post('approval-rules', [ApprovalController::class, 'storeRule'])->middleware('can:settings.manage')->name('approval-rules.store');
    Route::patch('approval-rules/{rule}', [ApprovalController::class, 'updateRule'])->middleware('can:settings.manage')->name('approval-rules.update');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware(['can:approvals.approve', 'idempotent'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware(['can:approvals.approve', 'idempotent'])->name('approvals.reject');
    Route::post('approvals/{approval}/withdraw', [ApprovalController::class, 'withdraw'])->middleware('idempotent')->name('approvals.withdraw');

    Route::get('notifications', [ApprovalController::class, 'notifications'])->name('notifications.index');
    Route::post('notifications/read-all', [ApprovalController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [ApprovalController::class, 'markRead'])->name('notifications.read');
});
