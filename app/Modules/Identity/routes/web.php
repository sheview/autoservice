<?php

use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Modules\Identity\Http\Controllers\UserImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->name('identity.')->group(function () {
    Route::get('users/import', [UserImportController::class, 'create'])->name('users.import');
    Route::post('users/import', [UserImportController::class, 'store'])->name('users.import.store');
    Route::get('users/import/template', [UserImportController::class, 'template'])->name('users.import.template');
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::put('roles-matrix', [RoleController::class, 'matrix'])->name('roles.matrix');
    Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
});
