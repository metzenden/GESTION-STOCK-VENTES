<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:invoices.view')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::get('invoices/{invoice}/payments', [PaymentController::class, 'forInvoice'])->name('invoices.payments.index');
    });
    Route::get('payments/{payment}/receipt.pdf', [PaymentController::class, 'receipt'])->middleware('can:invoices.print')->name('payments.receipt');
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware(['can:customer_payments.create', 'idempotent'])->name('invoices.payments.store');
    Route::middleware(['can:customer_payments.reverse', 'idempotent'])->group(function () {
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');
        Route::post('invoices/{invoice}/change-payment-method', [PaymentController::class, 'changeMethod'])->name('invoices.change-payment-method');
    });
});
