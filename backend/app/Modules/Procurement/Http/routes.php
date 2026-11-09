<?php

declare(strict_types=1);

use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\SupplierBillController;
use App\Modules\Procurement\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:suppliers.view')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    });
    Route::middleware('can:suppliers.manage')->group(function () {
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    });

    // Dettes et engagements : information financière sensible (GEN-005, D-088).
    Route::middleware('can:supplier_payments.manage')->group(function () {
        Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->name('suppliers.statement');
        Route::get('supplier-bills', [SupplierBillController::class, 'index'])->name('supplier-bills.index');
        Route::post('supplier-bills', [SupplierBillController::class, 'store'])->middleware('idempotent')->name('supplier-bills.store');
    });

    Route::middleware('can:purchase_orders.view')->group(function () {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    });
    Route::middleware('can:purchase_orders.manage')->group(function () {
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::patch('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::post('purchase-orders/{order}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('idempotent')->name('purchase-orders.confirm');
        Route::post('purchase-orders/{order}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
        Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    });
});
