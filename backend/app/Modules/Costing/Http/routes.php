<?php

declare(strict_types=1);

use App\Modules\Costing\Http\Controllers\CostingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:costing.view')->group(function () {
        Route::get('landed-cost-categories', [CostingController::class, 'categories'])->name('landed-cost-categories.index');
        Route::get('shipments/{shipment}/costs', [CostingController::class, 'costs'])->name('shipments.costs.index');
        Route::get('cost-models', [CostingController::class, 'models'])->name('cost-models.index');
        Route::get('shipments/{shipment}/landed-costs', [CostingController::class, 'calculations'])->name('shipments.landed-costs.index');
        Route::get('landed-costs/{calculation}', [CostingController::class, 'showCalculation'])->name('landed-costs.show');
    });

    Route::middleware('can:costing.manage')->group(function () {
        Route::post('landed-cost-categories', [CostingController::class, 'storeCategory'])->name('landed-cost-categories.store');
        Route::post('shipments/{shipment}/costs', [CostingController::class, 'storeCost'])->name('shipments.costs.store');
        Route::patch('shipments/{shipment}/costs/{cost}', [CostingController::class, 'updateCost'])->name('shipments.costs.update');
        Route::post('shipments/{shipment}/costs/{cost}/confirm', [CostingController::class, 'confirmCost'])->middleware('idempotent')->name('shipments.costs.confirm');
        Route::post('shipments/{shipment}/costs/{cost}/cancel', [CostingController::class, 'cancelCost'])->name('shipments.costs.cancel');
        Route::post('cost-models', [CostingController::class, 'storeModel'])->name('cost-models.store');
        Route::post('cost-models/{model}/versions', [CostingController::class, 'storeModelVersion'])->name('cost-models.versions.store');
        Route::post('cost-models/{model}/test', [CostingController::class, 'testModel'])->name('cost-models.test');
        Route::post('cost-models/{model}/activate', [CostingController::class, 'activateModel'])->name('cost-models.activate');
        Route::post('shipments/{shipment}/landed-costs', [CostingController::class, 'compute'])->middleware('idempotent')->name('shipments.landed-costs.store');
        Route::post('landed-costs/{calculation}/finalize', [CostingController::class, 'finalize'])->middleware('idempotent')->name('landed-costs.finalize');
    });
});
