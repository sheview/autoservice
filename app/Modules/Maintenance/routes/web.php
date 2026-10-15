<?php

use App\Modules\Maintenance\Http\Controllers\PmCalendarController;
use App\Modules\Maintenance\Http\Controllers\PmChecklistController;
use App\Modules\Maintenance\Http\Controllers\PmCustomerReportController;
use App\Modules\Maintenance\Http\Controllers\PmDashboardController;
use App\Modules\Maintenance\Http\Controllers\PmEquipmentController;
use App\Modules\Maintenance\Http\Controllers\PmFieldController;
use App\Modules\Maintenance\Http\Controllers\PmInstrumentController;
use App\Modules\Maintenance\Http\Controllers\PmPhotoController;
use App\Modules\Maintenance\Http\Controllers\PmPlanController;
use App\Modules\Maintenance\Http\Controllers\PmPublicController;
use App\Modules\Maintenance\Http\Controllers\PmReportShareController;
use App\Modules\Maintenance\Http\Controllers\PmRoundReportController;
use App\Modules\Maintenance\Http\Controllers\PmSettingsController;
use App\Modules\Maintenance\Http\Controllers\PmSharedReportController;
use App\Modules\Maintenance\Http\Controllers\PmVisitController;
use App\Modules\Maintenance\Http\Controllers\PmVisitPdfController;
use App\Modules\Maintenance\Http\Controllers\PmWorkOrderController;
use Illuminate\Support\Facades\Route;

// A customer's PM report link, without signing in (PmSharedReportController): on the company's
// host, or on the shared host with the company code in the path.
Route::middleware('throttle:track-link')->group(function () {
    Route::get('pm-report/{token}', [PmSharedReportController::class, 'showOnHost'])->name('maintenance.shared.show');
    Route::get('pm-report/{token}/pdf', [PmSharedReportController::class, 'pdfOnHost'])->name('maintenance.shared.pdf');
    Route::get('pm-report/{token}/equipment/{item}/pdf', [PmSharedReportController::class, 'pdfOnHost'])->whereNumber('item')->name('maintenance.shared.equipment');
    Route::get('t/{code}/pm-report/{token}', [PmSharedReportController::class, 'showOnPath'])->where('code', '[0-9]{1,10}')->name('maintenance.shared.show.path');
    Route::get('t/{code}/pm-report/{token}/pdf', [PmSharedReportController::class, 'pdfOnPath'])->where('code', '[0-9]{1,10}')->name('maintenance.shared.pdf.path');
    Route::get('t/{code}/pm-report/{token}/equipment/{item}/pdf', [PmSharedReportController::class, 'pdfOnPath'])
        ->where('code', '[0-9]{1,10}')->whereNumber('item')->name('maintenance.shared.equipment.path');

    // The customer signs on their own phone (a sign link), and the tracking QR on the sheet (PmPublicController).
    Route::get('pm-sign/{token}', [PmPublicController::class, 'signOnHost'])->name('maintenance.public.sign');
    Route::post('pm-sign/{token}', [PmPublicController::class, 'storeSignOnHost'])->name('maintenance.public.sign.store');
    Route::get('t/{code}/pm-sign/{token}', [PmPublicController::class, 'signOnPath'])->where('code', '[0-9]{1,10}')->name('maintenance.public.sign.path');
    Route::post('t/{code}/pm-sign/{token}', [PmPublicController::class, 'storeSignOnPath'])->where('code', '[0-9]{1,10}')->name('maintenance.public.sign.store.path');
    Route::get('pm-track/{token}', [PmPublicController::class, 'trackOnHost'])->name('maintenance.public.track');
    Route::get('t/{code}/pm-track/{token}', [PmPublicController::class, 'trackOnPath'])->where('code', '[0-9]{1,10}')->name('maintenance.public.track.path');
});

Route::middleware(['auth', 'verified', 'module:maintenance'])->name('maintenance.')->group(function () {
    Route::post('pm-checklists/standard', [PmChecklistController::class, 'addStandard'])->name('checklists.standard');
    Route::post('pm-checklists/{checklist}/publish', [PmChecklistController::class, 'publish'])->name('checklists.publish');
    Route::post('pm-checklists/{checklist}/new-version', [PmChecklistController::class, 'newVersion'])->name('checklists.new-version');
    Route::post('pm-checklists/{checklist}/copy', [PmChecklistController::class, 'copy'])->name('checklists.copy');
    Route::resource('pm-checklists', PmChecklistController::class)->except(['show'])
        ->names('checklists')->parameters(['pm-checklists' => 'checklist']);

    Route::resource('pm-plans', PmPlanController::class)
        ->names('plans')->parameters(['pm-plans' => 'plan']);

    Route::get('pm-calendar', [PmCalendarController::class, 'index'])->name('calendar');
    Route::get('pm-dashboard', [PmDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('pm-reports', [PmDashboardController::class, 'reports'])->name('reports.index');
    Route::get('pm-reports/round', PmRoundReportController::class)->name('reports.round');
    Route::get('pm-equipment', [PmEquipmentController::class, 'index'])->name('equipment.index');
    Route::get('pm-customer-reports', [PmCustomerReportController::class, 'index'])->name('customer-reports.index');
    Route::resource('pm-instruments', PmInstrumentController::class)->only(['index', 'store', 'update', 'destroy'])
        ->names('instruments')->parameters(['pm-instruments' => 'instrument']);

    // A work order on the technician's phone (PmFieldController).
    Route::get('pm-work/{visit}', [PmFieldController::class, 'visit'])->name('field.visit');
    Route::post('pm-work/{visit}/arrive', [PmFieldController::class, 'arrive'])->name('field.arrive');
    Route::post('pm-work/{visit}/depart', [PmFieldController::class, 'depart'])->name('field.depart');
    Route::get('pm-work/{visit}/sign', [PmFieldController::class, 'sign'])->name('field.sign');
    Route::scopeBindings()->group(function () {
        Route::get('pm-work/{visit}/items/{item}', [PmFieldController::class, 'item'])->name('field.item');
        Route::put('pm-work/{visit}/items/{item}/draft', [PmFieldController::class, 'draft'])->name('field.draft');
    });

    Route::get('pm-settings', [PmSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('pm-settings', [PmSettingsController::class, 'update'])->name('settings.update');

    // Before the resource, or "create" would be taken for a work order.
    Route::get('pm-visits/create', [PmWorkOrderController::class, 'create'])->name('visits.create');
    Route::post('pm-visits', [PmWorkOrderController::class, 'store'])->name('visits.store');
    Route::post('pm-visits/{visit}/items', [PmWorkOrderController::class, 'addItems'])->name('visits.items.store');
    Route::delete('pm-visits/{visit}/items/{item}', [PmWorkOrderController::class, 'removeItem'])->scopeBindings()->name('visits.items.destroy');
    Route::resource('pm-visits', PmVisitController::class)->only(['index', 'show', 'update'])
        ->names('visits')->parameters(['pm-visits' => 'visit']);
    Route::get('pm-visits/{visit}/pdf', [PmVisitPdfController::class, 'consolidated'])->name('visits.pdf');
    Route::get('pm-visits/{visit}/sheet', [PmVisitPdfController::class, 'sheet'])->name('visits.sheet');
    Route::post('pm-visits/{visit}/sign-link', [PmVisitController::class, 'signLink'])->name('visits.sign-link');
    Route::post('pm-visits/{visit}/revise', [PmVisitController::class, 'revise'])->name('visits.revise');
    Route::post('pm-visits/{visit}/customer-sign', [PmVisitController::class, 'customerSign'])->name('visits.customer-sign');
    Route::get('pm-visits/{visit}/equipment/{item}/pdf', [PmVisitPdfController::class, 'equipment'])->whereNumber('item')->name('visits.equipment.pdf');
    Route::post('pm-visits/{visit}/start', [PmVisitController::class, 'start'])->name('visits.start');
    Route::post('pm-visits/{visit}/submit', [PmVisitController::class, 'submit'])->name('visits.submit');
    Route::post('pm-visits/{visit}/move', [PmVisitController::class, 'move'])->name('visits.move');
    Route::post('pm-visits/{visit}/shares', [PmReportShareController::class, 'store'])->name('visits.shares.store');
    Route::scopeBindings()->group(function () {
        Route::delete('pm-visits/{visit}/shares/{share}', [PmReportShareController::class, 'destroy'])->whereNumber('share')->name('visits.shares.destroy');
        Route::put('pm-visits/{visit}/items/{item}', [PmVisitController::class, 'recordItem'])->name('visits.items.update');
        Route::post('pm-visits/{visit}/items/{item}/ticket', [PmVisitController::class, 'openTicket'])->name('visits.items.ticket');
        Route::post('pm-visits/{visit}/items/{item}/photos', [PmPhotoController::class, 'store'])->name('visits.items.photos.store');
        Route::get('pm-visits/{visit}/items/{item}/photos/{photo}', [PmPhotoController::class, 'show'])
            ->whereNumber('photo')->name('visits.items.photos.show');
        Route::delete('pm-visits/{visit}/items/{item}/photos/{photo}', [PmPhotoController::class, 'destroy'])
            ->whereNumber('photo')->name('visits.items.photos.destroy');
    });
});
