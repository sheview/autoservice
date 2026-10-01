<?php

use App\Modules\Platform\Http\Controllers\DashboardController;
use App\Modules\Platform\Http\Controllers\ImpersonationController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
use Illuminate\Support\Facades\Route;

// The home page of every signed-in user.
Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('impersonation', [ImpersonationController::class, 'index'])->name('impersonation.index');
    Route::post('impersonation/{tenant:ulid}', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');

    Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant:ulid}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('tenants/{tenant:ulid}', [TenantController::class, 'update'])->name('tenants.update');

    Route::get('tenants/{tenant:ulid}/modules', [TenantModuleController::class, 'edit'])->name('tenants.modules.edit');
    Route::put('tenants/{tenant:ulid}/modules', [TenantModuleController::class, 'update'])->name('tenants.modules.update');
});
