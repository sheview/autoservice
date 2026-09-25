<?php

use App\Modules\Platform\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('impersonation', [ImpersonationController::class, 'index'])->name('impersonation.index');
    Route::post('impersonation/{tenant:ulid}', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');
});
