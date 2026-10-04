<?php

use App\Modules\Platform\Http\Controllers\ActivityLogController;
use App\Modules\Platform\Http\Controllers\AlertSettingsController;
use App\Modules\Platform\Http\Controllers\CompanyShareController;
use App\Modules\Platform\Http\Controllers\DashboardController;
use App\Modules\Platform\Http\Controllers\ImpersonationController;
use App\Modules\Platform\Http\Controllers\PlatformSettingsController;
use App\Modules\Platform\Http\Controllers\SharedSearchController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
use App\Modules\Platform\Http\Controllers\TenantShareController;
use Illuminate\Support\Facades\Route;

// The home page of every signed-in user.
Route::get('dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

// Of the company being worked in: its activity log, and where it is alerted (LINE, Telegram, e-mail).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('activity-log', ActivityLogController::class)->name('platform.activity-log');
    Route::get('settings/alerts', [AlertSettingsController::class, 'edit'])->name('platform.alerts.edit');
    Route::put('settings/alerts', [AlertSettingsController::class, 'update'])->name('platform.alerts.update');
    Route::post('settings/alerts/test', [AlertSettingsController::class, 'test'])->name('platform.alerts.test');

    // Sharing data with other companies: what the company shares and receives, and searching what others share.
    Route::get('settings/shares', [CompanyShareController::class, 'index'])->name('platform.company-shares.index');
    Route::post('settings/shares/{share}', [CompanyShareController::class, 'decide'])->whereNumber('share')->name('platform.company-shares.decide');
    Route::get('shared-search', SharedSearchController::class)->name('platform.shared-search');
});

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

    Route::get('tenants/{tenant:ulid}/shares', [TenantShareController::class, 'edit'])->name('tenants.shares.edit');
    Route::put('tenants/{tenant:ulid}/shares/{viewer:ulid}', [TenantShareController::class, 'update'])->withoutScopedBindings()->name('tenants.shares.update');
    Route::post('tenants/{tenant:ulid}/shares/{share}/revoke', [TenantShareController::class, 'revoke'])->whereNumber('share')->name('tenants.shares.revoke');

    Route::get('settings', [PlatformSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [PlatformSettingsController::class, 'update'])->name('settings.update');
});
