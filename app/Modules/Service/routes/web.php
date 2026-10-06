<?php

use App\Modules\Service\Http\Controllers\FieldLinkController;
use App\Modules\Service\Http\Controllers\HolidayController;
use App\Modules\Service\Http\Controllers\MyWorkController;
use App\Modules\Service\Http\Controllers\RepairPresetController;
use App\Modules\Service\Http\Controllers\TicketActionController;
use App\Modules\Service\Http\Controllers\TicketAttachmentController;
use App\Modules\Service\Http\Controllers\TicketCloseController;
use App\Modules\Service\Http\Controllers\TicketController;
use App\Modules\Service\Http\Controllers\TicketFieldLinkController;
use App\Modules\Service\Http\Controllers\TicketPartController;
use App\Modules\Service\Http\Controllers\TicketPrintController;
use App\Modules\Service\Http\Controllers\TicketSurveyController;
use App\Modules\Service\Http\Controllers\TrackController;
use App\Modules\Service\Http\Controllers\TrackTokenController;
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
    // Closing a job on a phone (one page).
    Route::get('tickets/{ticket}/close', [TicketCloseController::class, 'show'])->name('tickets.close');
    Route::post('tickets/{ticket}/close', [TicketCloseController::class, 'store'])->name('tickets.close.store');
    // The symptom and fix chips of the company.
    Route::get('settings/repair-presets', [RepairPresetController::class, 'edit'])->name('presets.edit');
    Route::put('settings/repair-presets', [RepairPresetController::class, 'update'])->name('presets.update');
    Route::post('tickets/{ticket}/review', [TicketActionController::class, 'review'])->name('tickets.review');
    Route::get('tickets/{ticket}/media/{media}', [TicketActionController::class, 'media'])->whereNumber('media')->name('tickets.media');
    Route::post('tickets/{ticket}/tracking-token', [TicketActionController::class, 'trackingToken'])->name('tickets.tracking-token');
    // Links to work on the job without an account (outside technicians, the customer's sign-off).
    Route::post('tickets/{ticket}/field-links', [TicketFieldLinkController::class, 'store'])->name('tickets.field-links.store');
    Route::post('tickets/{ticket}/field-links/{link}/revoke', [TicketFieldLinkController::class, 'revoke'])->whereNumber('link')->name('tickets.field-links.revoke');
    Route::post('tickets/{ticket}/field-review', [TicketFieldLinkController::class, 'review'])->name('tickets.field-review');
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

// A ticket's link for an outside technician or the customer, without signing in.
Route::get('job/{token}', [FieldLinkController::class, 'showOnHost'])->middleware('throttle:track-link')->name('service.field-link');
Route::get('t/{code}/job/{token}', [FieldLinkController::class, 'showOnPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('service.field-link.path');
Route::post('job/{token}', [FieldLinkController::class, 'submitOnHost'])->middleware('throttle:track-link')->name('service.field-link.submit');
Route::post('t/{code}/job/{token}', [FieldLinkController::class, 'submitOnPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('service.field-link.submit.path');
Route::get('job/{token}/print', [FieldLinkController::class, 'printOnHost'])->middleware('throttle:track-link')->name('service.field-link.print');
Route::get('t/{code}/job/{token}/print', [FieldLinkController::class, 'printOnPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('service.field-link.print.path');

// Track my repair: public, no sign-in (TrackController finds the company); limited against guessing.
Route::get('track', TrackController::class)->middleware('throttle:track')->name('service.track');
// A ticket's tracking link: on the company's host, or naming the company by its code on the shared one.
Route::get('track/{token}', [TrackTokenController::class, 'onHost'])->middleware('throttle:track-link')->name('service.track.token');
Route::get('t/{code}/track/{token}', [TrackTokenController::class, 'onPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('service.track.token.path');
