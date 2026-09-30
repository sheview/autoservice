<?php

use App\Modules\Maintenance\Http\Controllers\PmChecklistController;
use App\Modules\Maintenance\Http\Controllers\PmPlanController;
use App\Modules\Maintenance\Http\Controllers\PmVisitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:maintenance'])->name('maintenance.')->group(function () {
    Route::resource('pm-checklists', PmChecklistController::class)->except(['show'])
        ->names('checklists')->parameters(['pm-checklists' => 'checklist']);

    Route::resource('pm-plans', PmPlanController::class)
        ->names('plans')->parameters(['pm-plans' => 'plan']);

    Route::resource('pm-visits', PmVisitController::class)->only(['index', 'show', 'update'])
        ->names('visits')->parameters(['pm-visits' => 'visit']);
    Route::post('pm-visits/{visit}/start', [PmVisitController::class, 'start'])->name('visits.start');
    Route::post('pm-visits/{visit}/complete', [PmVisitController::class, 'complete'])->name('visits.complete');
    Route::post('pm-visits/{visit}/cancel', [PmVisitController::class, 'cancel'])->name('visits.cancel');
    Route::scopeBindings()->group(function () {
        Route::put('pm-visits/{visit}/items/{item}', [PmVisitController::class, 'recordItem'])->name('visits.items.update');
        Route::post('pm-visits/{visit}/items/{item}/ticket', [PmVisitController::class, 'openTicket'])->name('visits.items.ticket');
    });
});
