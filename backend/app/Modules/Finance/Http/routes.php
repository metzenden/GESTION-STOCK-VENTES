<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\FinancialAccountController;
use App\Modules\Finance\Http\Controllers\TreasuryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    // Liste des comptes (choix du compte d'encaissement) : solde masqué sans permission sensible.
    Route::get('financial-accounts', [FinancialAccountController::class, 'index'])->middleware('can:any-finance-access')->name('financial-accounts.index');
    Route::get('financial-accounts/{account}/movements', [FinancialAccountController::class, 'movements'])->middleware('can:financial_accounts.view_sensitive')->name('financial-accounts.movements');
    Route::middleware('can:financial_accounts.manage')->group(function () {
        Route::post('financial-accounts', [FinancialAccountController::class, 'store'])->name('financial-accounts.store');
        Route::patch('financial-accounts/{account}', [FinancialAccountController::class, 'update'])->name('financial-accounts.update');
    });

    Route::middleware('can:financial_accounts.view_sensitive')->group(function () {
        Route::get('account-transfers', [TreasuryController::class, 'transfers'])->name('account-transfers.index');
        Route::get('financial-corrections', [TreasuryController::class, 'corrections'])->name('financial-corrections.index');
        Route::get('financial/pending', [TreasuryController::class, 'pending'])->name('financial.pending');
        Route::get('opening', [TreasuryController::class, 'opening'])->name('opening.show');
    });
    Route::middleware('can:financial_accounts.manage')->group(function () {
        Route::post('account-transfers', [TreasuryController::class, 'storeTransfer'])->middleware('idempotent')->name('account-transfers.store');
        Route::post('financial-corrections', [TreasuryController::class, 'storeCorrection'])->middleware('idempotent')->name('financial-corrections.store');
        Route::put('opening', [TreasuryController::class, 'saveOpening'])->name('opening.save');
        Route::post('opening/post', [TreasuryController::class, 'postOpening'])->middleware('idempotent')->name('opening.post');
    });
    Route::middleware('can:expenses.manage')->group(function () {
        Route::get('expense-categories', [TreasuryController::class, 'categories'])->name('expense-categories.index');
        Route::post('expense-categories', [TreasuryController::class, 'storeCategory'])->name('expense-categories.store');
        Route::patch('expense-categories/{category}', [TreasuryController::class, 'updateCategory'])->name('expense-categories.update');
        Route::get('expenses', [TreasuryController::class, 'expenses'])->name('expenses.index');
        Route::post('expenses', [TreasuryController::class, 'storeExpense'])->middleware('idempotent')->name('expenses.store');
        Route::post('expenses/{expense}/reverse', [TreasuryController::class, 'reverseExpense'])->middleware('idempotent')->name('expenses.reverse');
    });
});
