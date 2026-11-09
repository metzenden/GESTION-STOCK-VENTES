<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\AttributeController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\PricingController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\VariantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'company.context', 'company.writable'])->group(function () {
    Route::middleware('can:products.view')->group(function () {
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('attributes', [AttributeController::class, 'index'])->name('attributes.index');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::get('products/{product}/variants', [VariantController::class, 'index'])->name('products.variants.index');
        Route::get('product-variants/lookup', [VariantController::class, 'lookup'])->name('product-variants.lookup');
        Route::get('product-variants/{variant}/units', [VariantController::class, 'units'])->name('product-variants.units.index');
        Route::get('product-variants/{variant}/prices', [VariantController::class, 'prices'])->name('product-variants.prices.index');
        Route::get('product-images/{image}/file', [ProductController::class, 'imageFile'])->name('product-images.file');
        Route::get('product-images/{image}/thumbnail', [ProductController::class, 'imageThumbnail'])->name('product-images.thumbnail');
        Route::get('price-lists', [PricingController::class, 'index'])->name('price-lists.index');
        Route::get('pricing/quote', [PricingController::class, 'quote'])->name('pricing.quote');
    });

    Route::middleware('can:products.update')->group(function () {
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('attributes', [AttributeController::class, 'store'])->name('attributes.store');
        Route::post('attributes/{attribute}/values', [AttributeController::class, 'storeValue'])->name('attributes.values.store');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
        Route::post('products/{product}/activate', [ProductController::class, 'activate'])->name('products.activate');
        Route::post('products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
        Route::post('products/{product}/variants', [VariantController::class, 'store'])->name('products.variants.store');
        Route::post('products/{product}/images', [ProductController::class, 'uploadImage'])->name('products.images.store');
        Route::delete('product-images/{image}', [ProductController::class, 'deleteImage'])->name('product-images.destroy');
        Route::patch('product-variants/{variant}', [VariantController::class, 'update'])->name('product-variants.update');
        Route::post('product-variants/{variant}/units', [VariantController::class, 'storeUnit'])->name('product-variants.units.store');
        Route::patch('variant-units/{unit}', [VariantController::class, 'updateUnit'])->name('variant-units.update');
        Route::post('product-variants/{variant}/prices', [VariantController::class, 'storePrice'])->name('product-variants.prices.store');
        Route::post('price-lists', [PricingController::class, 'store'])->name('price-lists.store');
        Route::patch('price-lists/{priceList}', [PricingController::class, 'update'])->name('price-lists.update');
    });

    Route::post('products', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
});
