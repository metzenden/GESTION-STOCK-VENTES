<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\AdminCompanyController;
use App\Modules\Tenancy\Http\Controllers\CompanyContextController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context:optional', 'super-admin'])->group(function () {
    Route::post('context/company', CompanyContextController::class)->name('context.company');

    Route::prefix('admin/companies')->name('admin.companies.')->group(function () {
        Route::get('/', [AdminCompanyController::class, 'index'])->name('index');
        Route::post('/', [AdminCompanyController::class, 'store'])->middleware('idempotent')->name('store');
        Route::get('{company}', [AdminCompanyController::class, 'show'])->name('show');
        Route::patch('{company}', [AdminCompanyController::class, 'update'])->name('update');
        Route::post('{company}/suspend', [AdminCompanyController::class, 'suspend'])->name('suspend');
        Route::post('{company}/reactivate', [AdminCompanyController::class, 'reactivate'])->name('reactivate');
        Route::post('{company}/invitations', [AdminCompanyController::class, 'invite'])->name('invitations.store');
    });
});
