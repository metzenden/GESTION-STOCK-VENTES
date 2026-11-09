<?php

declare(strict_types=1);

use App\Modules\Imports\Http\Controllers\InitialImportController;
use App\Modules\Imports\Http\Controllers\MappingTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable', 'can:shipments.manage'])->group(function () {
    Route::get('import-mapping-templates', [MappingTemplateController::class, 'index'])->name('import-mapping-templates.index');
    Route::post('import-mapping-templates', [MappingTemplateController::class, 'store'])->name('import-mapping-templates.store');
});

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::post('imports/{type}', [InitialImportController::class, 'store'])->middleware('idempotent')->name('imports.store');
});
