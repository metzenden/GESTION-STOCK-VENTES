<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\ReturnController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.manage')->name('customers.store');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.manage')->name('customers.update');

    Route::middleware('can:sales.create')->group(function () {
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::patch('quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
        Route::post('quotations/{quotation}/send', [QuotationController::class, 'send'])->name('quotations.send');
        Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');
        Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
        Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware('idempotent')->name('quotations.convert');

        Route::post('sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
        Route::patch('sales-orders/{order}', [SalesOrderController::class, 'update'])->name('sales-orders.update');
        Route::post('sales-orders/{order}/disputes', [SalesOrderController::class, 'openDispute'])->name('sales-orders.disputes.store');
        Route::post('disputes/{dispute}/resolve', [SalesOrderController::class, 'resolveDispute'])->name('disputes.resolve');
    });

    Route::middleware('can:sales.read')->group(function () {
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
        Route::get('sales-orders/{order}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
        Route::get('sales-orders/{order}/deliveries', [DeliveryController::class, 'forOrder'])->name('sales-orders.deliveries.index');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
        Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('returns/{return}', [ReturnController::class, 'show'])->name('returns.show');
    });

    Route::middleware('can:sales.confirm')->group(function () {
        Route::post('sales-orders/{order}/submit', [SalesOrderController::class, 'submit'])->middleware('idempotent')->name('sales-orders.submit');
        Route::post('sales-orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->middleware('idempotent')->name('sales-orders.cancel');
        Route::post('sales-orders/{order}/cancel-remaining', [SalesOrderController::class, 'cancelRemaining'])->middleware('idempotent')->name('sales-orders.cancel-remaining');
    });
    Route::post('counter-sales', [SalesOrderController::class, 'counterSale'])->middleware(['can:counter.sell', 'idempotent'])->name('counter-sales.store');

    Route::post('sales-orders/{order}/deliveries', [DeliveryController::class, 'store'])->middleware('can:deliveries.prepare')->name('sales-orders.deliveries.store');
    Route::post('deliveries/{delivery}/ready', [DeliveryController::class, 'ready'])->middleware('can:deliveries.prepare')->name('deliveries.ready');
    Route::post('deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])->middleware('can:deliveries.prepare')->name('deliveries.cancel');
    Route::middleware('can:deliveries.ship')->group(function () {
        Route::post('deliveries/{delivery}/ship', [DeliveryController::class, 'ship'])->middleware('idempotent')->name('deliveries.ship');
        Route::post('deliveries/{delivery}/fail', [DeliveryController::class, 'fail'])->middleware('idempotent')->name('deliveries.fail');
        Route::post('deliveries/{delivery}/return-to-warehouse', [DeliveryController::class, 'returnToWarehouse'])->middleware('idempotent')->name('deliveries.return');
    });
    Route::post('deliveries/{delivery}/deliver', [DeliveryController::class, 'deliver'])->middleware(['can:deliveries.handover', 'idempotent'])->name('deliveries.deliver');

    Route::middleware('can:returns.manage')->group(function () {
        Route::post('returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::post('returns/{return}/receive', [ReturnController::class, 'receive'])->middleware('idempotent')->name('returns.receive');
        Route::post('returns/{return}/inspect', [ReturnController::class, 'inspect'])->middleware('idempotent')->name('returns.inspect');
        Route::post('returns/{return}/cancel', [ReturnController::class, 'cancel'])->name('returns.cancel');
    });
    Route::post('returns/{return}/close', [ReturnController::class, 'close'])->middleware(['can:credit_notes.create', 'idempotent'])->name('returns.close');
});
