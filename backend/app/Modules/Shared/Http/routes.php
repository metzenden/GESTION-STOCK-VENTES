<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('health');
