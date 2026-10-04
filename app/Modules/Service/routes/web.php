<?php

use App\Modules\Service\Http\Controllers\HolidayController;
use App\Modules\Service\Http\Controllers\MyWorkController;
use App\Modules\Service\Http\Controllers\TicketActionController;
use App\Modules\Service\Http\Controllers\TicketAttachmentController;
use App\Modules\Service\Http\Controllers\TicketController;
use App\Modules\Service\Http\Controllers\TicketPartController;
use App\Modules\Service\Http\Controllers\TicketPrintController;
use App\Modules\Service\Http\Controllers\TicketSurveyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:service'])->name('service.')->group(function () {
    // My work: calendar and to-do list of the user (assigned tickets, loans, own appointments).
    Route::get('my-work', [MyWorkController::class, 'index'])->name('my-work');
    Route::post('my-work/events', [MyWorkController::class, 'store'])->name('my-work.events.store');
    Route::put('my-work/events/{event}', [MyWorkController::class, 'update'])->whereNumber('event')->name('my-work.events.update');
    Route::delete('my-work/events/{event}', [MyWorkController::class, 'destroy'])->whereNumber('event')->name('my-work.events.destroy');
    Route::resource('tickets', TicketController::class)->except(['destroy']);
    Route::get('tickets/{ticket}/print', [TicketPrintController::class, 'show'])->name('tickets.print');
    Route::get('tickets/{ticket}/pdf', [TicketPrintController::class, 'pdf'])->name('tickets.pdf');
    Route::post('tickets/{ticket}/assign', [TicketActionController::class, 'assign'])->name('tickets.assign');
    Route::post('tickets/{ticket}/warranty', [TicketActionController::class, 'warranty'])->name('tickets.warranty');
    Route::post('tickets/{ticket}/report', [TicketActionController::class, 'report'])->name('tickets.report');
    Route::post('tickets/{ticket}/ip', [TicketActionController::class, 'ip'])->name('tickets.ip');
    Route::post('tickets/{ticket}/appointment', [TicketActionController::class, 'appointment'])->name('tickets.appointment');
    Route::post('tickets/{ticket}/forward', [TicketActionController::class, 'forward'])->name('tickets.forward');
    Route::post('tickets/{ticket}/move', [TicketActionController::class, 'move'])->name('tickets.move');
    Route::post('tickets/{ticket}/comments', [TicketActionController::class, 'comment'])->name('tickets.comments.store');

    Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])->name('tickets.attachments.store');
    Route::get('tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'show'])
        ->whereNumber('attachment')->name('tickets.attachments.show');
    Route::delete('tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')->name('tickets.attachments.destroy');

    Route::middleware('module:inventory')->group(function () {
        Route::post('tickets/{ticket}/parts', [TicketPartController::class, 'store'])->name('tickets.parts.store');
        Route::post('tickets/{ticket}/parts/return', [TicketPartController::class, 'giveBack'])->name('tickets.parts.return');
    });

    Route::post('tickets/{ticket}/survey', [TicketSurveyController::class, 'store'])
        ->middleware('module:survey')->name('tickets.survey.store');
    Route::post('tickets/{ticket}/survey/paper', [TicketSurveyController::class, 'paper'])
        ->middleware('module:survey')->name('tickets.survey.paper');

    Route::resource('holidays', HolidayController::class)->only(['index', 'store', 'destroy']);
});
