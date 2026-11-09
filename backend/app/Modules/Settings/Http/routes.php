<?php

declare(strict_types=1);

use App\Modules\Settings\Http\Controllers\CompanySettingsController;
use App\Modules\Settings\Http\Controllers\DocumentSequenceController;
use App\Modules\Settings\Http\Controllers\ExchangeRateController;
use App\Modules\Settings\Http\Controllers\TaxRateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::get('settings/company', [CompanySettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.company.show');
    Route::patch('settings/company', [CompanySettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.company.update');

    Route::get('settings/document-sequences', [DocumentSequenceController::class, 'index'])->middleware('can:settings.view')->name('settings.sequences.index');
    Route::patch('settings/document-sequences', [DocumentSequenceController::class, 'update'])->middleware('can:settings.manage')->name('settings.sequences.update');

    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->middleware('can:settings.view')->name('exchange-rates.index');
    Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->middleware('can:settings.manage')->name('exchange-rates.store');

    Route::get('tax-rates', [TaxRateController::class, 'index'])->middleware('can:settings.view')->name('tax-rates.index');
    Route::post('tax-rates', [TaxRateController::class, 'store'])->middleware('can:settings.manage')->name('tax-rates.store');
    Route::patch('tax-rates/{taxRate}', [TaxRateController::class, 'update'])->middleware('can:settings.manage')->name('tax-rates.update');
});
