<?php

use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->name('identity.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::put('roles-matrix', [RoleController::class, 'matrix'])->name('roles.matrix');
    Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
});
