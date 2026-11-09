<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\IndicatorsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::get('reports/indicators', [IndicatorsController::class, 'show'])->middleware('can:reports.financial')->name('reports.indicators');
    Route::get('dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('alerts', [DashboardController::class, 'alerts'])->name('alerts');
    Route::get('reports', [DashboardController::class, 'catalog'])->name('reports.index');
    Route::get('reports/{report}', [DashboardController::class, 'report'])->name('reports.show');
    Route::get('search', [DashboardController::class, 'search'])->name('search');
});

Route::middleware(['auth:sanctum', 'session.current', 'super-admin'])->group(function () {
    Route::get('admin/consolidated', [DashboardController::class, 'consolidated'])->name('admin.consolidated');
});
