<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\AdjustmentController;
use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Controllers\StockQueryController;
use App\Modules\Inventory\Http\Controllers\TransferController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:stock.view')->name('warehouses.index');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('can:warehouses.manage')->name('warehouses.store');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:warehouses.manage')->name('warehouses.update');

    Route::middleware('can:stock.view')->group(function () {
        Route::get('stock/positions', [StockQueryController::class, 'positions'])->name('stock.positions');
        Route::get('stock/lots', [StockQueryController::class, 'lots'])->name('stock.lots');
        Route::get('stock/movements', [StockQueryController::class, 'movements'])->name('stock.movements');
    });
    Route::get('stock/valuation', [StockQueryController::class, 'valuation'])->middleware('can:products.view_sensitive')->name('stock.valuation');

    Route::middleware('can:stock.view')->group(function () {
        Route::get('stock-adjustments', [AdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::get('stock-adjustments/{adjustment}', [AdjustmentController::class, 'show'])->name('stock-adjustments.show');
        Route::get('inventories', [InventoryController::class, 'index'])->name('inventories.index');
        Route::get('inventories/{inventory}', [InventoryController::class, 'show'])->name('inventories.show');
        Route::get('inventories/{inventory}/sheet.pdf', [InventoryController::class, 'sheet'])->name('inventories.sheet');
        Route::get('stock-transfers', [TransferController::class, 'index'])->name('stock-transfers.index');
        Route::get('stock-transfers/{transfer}', [TransferController::class, 'show'])->name('stock-transfers.show');
    });

    Route::middleware('can:adjustments.create')->group(function () {
        Route::post('stock-adjustments', [AdjustmentController::class, 'store'])->name('stock-adjustments.store');
        Route::patch('stock-adjustments/{adjustment}', [AdjustmentController::class, 'update'])->name('stock-adjustments.update');
        Route::post('stock-adjustments/{adjustment}/submit', [AdjustmentController::class, 'submit'])->middleware('idempotent')->name('stock-adjustments.submit');
        Route::post('stock-adjustments/{adjustment}/cancel', [AdjustmentController::class, 'cancel'])->name('stock-adjustments.cancel');
    });

    Route::middleware('can:inventories.create')->group(function () {
        Route::post('inventories', [InventoryController::class, 'store'])->name('inventories.store');
        Route::post('inventories/{inventory}/start', [InventoryController::class, 'start'])->middleware('idempotent')->name('inventories.start');
        Route::put('inventories/{inventory}/lines/{line}', [InventoryController::class, 'count'])->name('inventories.lines.count');
        Route::post('inventories/{inventory}/close-counting', [InventoryController::class, 'closeCounting'])->name('inventories.close-counting');
        Route::post('inventories/{inventory}/reopen', [InventoryController::class, 'reopen'])->name('inventories.reopen');
        Route::post('inventories/{inventory}/validate', [InventoryController::class, 'validate'])->middleware(['idempotent', 'can:adjustments.create'])->name('inventories.validate');
        Route::post('inventories/{inventory}/cancel', [InventoryController::class, 'cancel'])->name('inventories.cancel');
    });

    Route::middleware('can:transfers.manage')->group(function () {
        Route::post('stock-transfers', [TransferController::class, 'store'])->name('stock-transfers.store');
        Route::patch('stock-transfers/{transfer}', [TransferController::class, 'update'])->name('stock-transfers.update');
        Route::post('stock-transfers/{transfer}/confirm', [TransferController::class, 'confirm'])->middleware('idempotent')->name('stock-transfers.confirm');
        Route::post('stock-transfers/{transfer}/ship', [TransferController::class, 'ship'])->middleware('idempotent')->name('stock-transfers.ship');
        Route::post('stock-transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('idempotent')->name('stock-transfers.receive');
        Route::post('stock-transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('stock-transfers.cancel');
    });
});
