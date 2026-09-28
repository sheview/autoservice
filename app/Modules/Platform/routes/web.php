<?php

use App\Modules\Platform\Http\Controllers\ImpersonationController;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('impersonation', [ImpersonationController::class, 'index'])->name('impersonation.index');
    Route::post('impersonation/{tenant:ulid}', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');

    Route::get('tenants/{tenant:ulid}/modules', [TenantModuleController::class, 'edit'])->name('tenants.modules.edit');
    Route::put('tenants/{tenant:ulid}/modules', [TenantModuleController::class, 'update'])->name('tenants.modules.update');
});
