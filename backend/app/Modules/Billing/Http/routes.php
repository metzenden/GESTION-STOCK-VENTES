<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\CreditNoteController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\PublicDocumentController;
use Illuminate\Support\Facades\Route;

// Lien signé de partage (sans session) : la signature tient lieu d'autorisation.
Route::get('public/companies/{company}/invoices/{invoice}.pdf', [PublicDocumentController::class, 'invoice'])
    ->middleware('throttle:30,1')->name('public.invoices.pdf');

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:invoices.view')->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('receivables', [InvoiceController::class, 'receivables'])->name('receivables.index');
        Route::get('receivables/{invoice}/reminder', [InvoiceController::class, 'reminder'])->name('receivables.reminder');
        Route::get('customers/{customer}/statement', [InvoiceController::class, 'statement'])->name('customers.statement');
        Route::get('customers/{customer}/credits', [CreditNoteController::class, 'forCustomer'])->name('customers.credits');
        Route::get('credit-notes', [CreditNoteController::class, 'index'])->name('credit-notes.index');
        Route::get('credit-notes/{note}', [CreditNoteController::class, 'show'])->name('credit-notes.show');
    });

    Route::middleware('can:invoices.print')->group(function () {
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/share-link', [InvoiceController::class, 'shareLink'])->name('invoices.share-link');
        Route::get('invoices/{invoice}/whatsapp-message', [InvoiceController::class, 'whatsapp'])->name('invoices.whatsapp');
        Route::get('credit-notes/{note}/pdf', [CreditNoteController::class, 'pdf'])->name('credit-notes.pdf');
    });

    Route::middleware('can:credit_notes.create')->group(function () {
        Route::post('credit-notes', [CreditNoteController::class, 'store'])->name('credit-notes.store');
        Route::post('credit-notes/{note}/issue', [CreditNoteController::class, 'issue'])->middleware('idempotent')->name('credit-notes.issue');
        Route::post('credit-notes/{note}/apply', [CreditNoteController::class, 'apply'])->middleware('idempotent')->name('credit-notes.apply');
    });
});
