<?php

use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\SsoController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Modules\Identity\Http\Controllers\UserImportController;
use Illuminate\Support\Facades\Route;

// Sign in with Keycloak (Identity\Support\Sso).
Route::middleware('guest')->group(function () {
    Route::get('auth/sso', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
});
// Keycloak itself calls this (no session, no CSRF token: bootstrap/app.php), checked by its signature.
Route::post('auth/sso/backchannel-logout', [SsoController::class, 'backchannelLogout'])
    ->middleware('throttle:60,1')
    ->name('sso.backchannel-logout');

Route::middleware(['auth', 'verified'])->name('identity.')->group(function () {
    Route::get('users/import', [UserImportController::class, 'create'])->name('users.import');
    Route::post('users/import', [UserImportController::class, 'store'])->name('users.import.store');
    Route::get('users/import/template', [UserImportController::class, 'template'])->name('users.import.template');
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::put('roles-matrix', [RoleController::class, 'matrix'])->name('roles.matrix');
    Route::resource('roles', RoleController::class)->except(['show']);
});
