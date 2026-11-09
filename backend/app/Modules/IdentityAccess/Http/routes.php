<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Controllers\AuthController;
use App\Modules\IdentityAccess\Http\Controllers\PasswordController;
use App\Modules\IdentityAccess\Http\Controllers\RoleController;
use App\Modules\IdentityAccess\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Routes publiques (limitées en débit).
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password-reset')->name('forgot-password');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password-reset')->name('reset-password');
    Route::post('accept-invitation', [AuthController::class, 'acceptInvitation'])->middleware('throttle:password-reset')->name('accept-invitation');
});

Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::prefix('auth')->name('auth.')->middleware('company.context:optional')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('me/preferences', [AuthController::class, 'preferences'])->name('preferences');
    });

    Route::middleware(['company.context', 'company.writable'])->group(function () {
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('can:users.manage')->name('users.update');
        Route::post('users/{user}/disable', [UserController::class, 'disable'])->middleware('can:users.manage')->name('users.disable');
        Route::post('users/{user}/enable', [UserController::class, 'enable'])->middleware('can:users.manage')->name('users.enable');

        Route::get('invitations', [UserController::class, 'invitations'])->middleware('can:users.view')->name('invitations.index');
        Route::post('invitations', [UserController::class, 'invite'])->middleware('can:users.manage')->name('invitations.store');
        Route::post('invitations/{invitation}/revoke', [UserController::class, 'revokeInvitation'])->middleware('can:users.manage')->name('invitations.revoke');

        Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('can:roles.view')->name('roles.show');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('roles.destroy');

        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('can:roles.view')->name('permissions.index');
    });
});
