<?php

use App\Modules\Reporting\Http\Controllers\PartsIssuedController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Reporting\Http\Controllers\SummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:reporting'])->name('reporting.')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Parts replaced / issued, with the serial numbers of the pieces.
    Route::get('reports/parts-issued', [PartsIssuedController::class, 'index'])->name('parts-issued.index');
    Route::get('reports/parts-issued/export', [PartsIssuedController::class, 'export'])->name('parts-issued.export');
    Route::get('reports/parts-issued/print', [PartsIssuedController::class, 'print'])->name('parts-issued.print');
    Route::get('reports/parts-issued/pdf', [PartsIssuedController::class, 'pdf'])->name('parts-issued.pdf');

    // What each person / project (MA contract) has been issued, lent or bought.
    Route::get('summary/people', [SummaryController::class, 'people'])->name('people.index');
    Route::get('summary/people/kpi', [SummaryController::class, 'kpi'])->name('people.kpi');
    Route::get('summary/people/view', [SummaryController::class, 'person'])->name('people.show');
    Route::get('summary/projects', [SummaryController::class, 'projects'])->name('projects.index');
    Route::get('summary/projects/{contract}', [SummaryController::class, 'project'])->whereNumber('contract')->name('projects.show');
});
