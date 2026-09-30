<?php

use App\Modules\Service\Http\Controllers\HolidayController;
use App\Modules\Service\Http\Controllers\TicketActionController;
use App\Modules\Service\Http\Controllers\TicketController;
use App\Modules\Service\Http\Controllers\TicketPartController;
use App\Modules\Service\Http\Controllers\TicketPrintController;
use App\Modules\Service\Http\Controllers\TicketSurveyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:service'])->name('service.')->group(function () {
    Route::resource('tickets', TicketController::class)->except(['destroy']);
    Route::get('tickets/{ticket}/print', [TicketPrintController::class, 'show'])->name('tickets.print');
    Route::post('tickets/{ticket}/assign', [TicketActionController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/move', [TicketActionController::class, 'move'])->name('tickets.move');
    Route::post('tickets/{ticket}/comments', [TicketActionController::class, 'comment'])->name('tickets.comments.store');

    Route::middleware('module:inventory')->group(function () {
        Route::post('tickets/{ticket}/parts', [TicketPartController::class, 'store'])->name('tickets.parts.store');
        Route::post('tickets/{ticket}/parts/return', [TicketPartController::class, 'giveBack'])->name('tickets.parts.return');
    });

    Route::post('tickets/{ticket}/survey', [TicketSurveyController::class, 'store'])
        ->middleware('module:survey')->name('tickets.survey.store');

    Route::resource('holidays', HolidayController::class)->only(['index', 'store', 'destroy']);
});
