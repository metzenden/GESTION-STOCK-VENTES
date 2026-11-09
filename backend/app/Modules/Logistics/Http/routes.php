<?php

declare(strict_types=1);

use App\Modules\Logistics\Http\Controllers\GoodsReceiptController;
use App\Modules\Logistics\Http\Controllers\PackingListController;
use App\Modules\Logistics\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:shipments.view')->group(function () {
        Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
        Route::get('shipments/{shipment}/containers', [ShipmentController::class, 'containers'])->name('shipments.containers.index');
        Route::get('shipments/{shipment}/lines', [ShipmentController::class, 'lines'])->name('shipments.lines.index');
        Route::get('shipments/{shipment}/packing-lists', [PackingListController::class, 'index'])->name('shipments.packing-lists.index');
        Route::get('packing-lists/{list}', [PackingListController::class, 'show'])->name('packing-lists.show');
        Route::get('packing-lists/{list}/lines', [PackingListController::class, 'lines'])->name('packing-lists.lines.index');
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
        Route::get('goods-receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');
    });

    Route::middleware('can:shipments.manage')->group(function () {
        Route::post('shipments', [ShipmentController::class, 'store'])->name('shipments.store');
        Route::patch('shipments/{shipment}', [ShipmentController::class, 'update'])->name('shipments.update');
        Route::post('shipments/{shipment}/transition', [ShipmentController::class, 'transition'])->middleware('idempotent')->name('shipments.transition');
        Route::post('shipments/{shipment}/containers', [ShipmentController::class, 'storeContainer'])->name('shipments.containers.store');
        Route::post('shipments/{shipment}/purchase-orders', [ShipmentController::class, 'linkPurchaseOrder'])->name('shipments.purchase-orders.store');
        Route::post('shipments/{shipment}/lines', [ShipmentController::class, 'storeLines'])->name('shipments.lines.store');
        Route::patch('shipments/{shipment}/lines/{line}', [ShipmentController::class, 'updateLine'])->name('shipments.lines.update');
        Route::delete('shipments/{shipment}/lines/{line}', [ShipmentController::class, 'destroyLine'])->name('shipments.lines.destroy');

        Route::post('shipments/{shipment}/packing-lists', [PackingListController::class, 'store'])->name('shipments.packing-lists.store');
        Route::patch('packing-lists/{list}/lines/{line}', [PackingListController::class, 'updateLine'])->name('packing-lists.lines.update');
        Route::post('packing-lists/{list}/validate', [PackingListController::class, 'validate'])->middleware('idempotent')->name('packing-lists.validate');
        Route::post('packing-lists/{list}/reject', [PackingListController::class, 'reject'])->name('packing-lists.reject');

        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
        Route::post('goods-receipts/{receipt}/start', [GoodsReceiptController::class, 'start'])->name('goods-receipts.start');
        Route::put('goods-receipts/{receipt}/lines/{line}', [GoodsReceiptController::class, 'count'])->name('goods-receipts.lines.count');
        Route::post('goods-receipts/{receipt}/scans', [GoodsReceiptController::class, 'scan'])->name('goods-receipts.scans.store');
        Route::post('goods-receipts/{receipt}/validate', [GoodsReceiptController::class, 'validate'])->middleware('idempotent')->name('goods-receipts.validate');
        Route::post('goods-receipts/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('goods-receipts.cancel');
    });
});
