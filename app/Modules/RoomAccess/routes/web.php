<?php

use App\Modules\RoomAccess\Http\Controllers\RoomAccessAttachmentController;
use App\Modules\RoomAccess\Http\Controllers\RoomAccessRequestController;
use App\Modules\RoomAccess\Http\Controllers\RoomGuardController;
use App\Modules\RoomAccess\Http\Controllers\RoomPermitController;
use App\Modules\RoomAccess\Http\Controllers\RoomRuleController;
use App\Modules\RoomAccess\Http\Controllers\RoomVisitController;
use App\Modules\RoomAccess\Http\Controllers\ServerRoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:room_access'])->prefix('room-access')->name('room-access.')->group(function () {
    // Setting up rooms, their rules (versions) and the company's own terms.
    Route::put('settings', [ServerRoomController::class, 'settings'])->name('settings');
    Route::resource('rooms', ServerRoomController::class);
    Route::post('rooms/{room}/rules', [RoomRuleController::class, 'store'])->name('rooms.rules.store');
    Route::get('rooms/{room}/rules/{version}/file', [RoomRuleController::class, 'file'])->whereNumber('version')->name('rooms.rules.file');

    // Requests to enter a room. Before the resource, so these are not taken as a request.
    Route::get('requests/tickets', [RoomAccessRequestController::class, 'tickets'])->name('requests.tickets');
    Route::get('requests/rooms/{room}/rules', [RoomAccessRequestController::class, 'rules'])->name('requests.rules');
    Route::get('requests/rooms/{room}/rules/{version}/file', [RoomAccessRequestController::class, 'rulesFile'])->whereNumber('version')->name('requests.rules-file');
    Route::resource('requests', RoomAccessRequestController::class)->except(['destroy'])->parameters(['requests' => 'roomRequest']);
    Route::post('requests/{roomRequest}/submit', [RoomAccessRequestController::class, 'submit'])->name('requests.submit');
    Route::post('requests/{roomRequest}/cancel', [RoomAccessRequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('requests/{roomRequest}/decide', [RoomAccessRequestController::class, 'decide'])->name('requests.decide');
    // The permit: PDF, a page to print, and a new QR link.
    Route::get('requests/{roomRequest}/permit', [RoomPermitController::class, 'pdf'])->name('requests.permit.pdf');
    Route::get('requests/{roomRequest}/permit/print', [RoomPermitController::class, 'print'])->name('requests.permit.print');
    Route::post('requests/{roomRequest}/permit/renew', [RoomPermitController::class, 'renew'])->name('requests.permit.renew');
    // The visit: going in, coming out, the work summary afterwards, and the guards' link.
    Route::post('requests/{roomRequest}/enter', [RoomVisitController::class, 'enter'])->name('requests.enter');
    Route::post('requests/{roomRequest}/exit', [RoomVisitController::class, 'exit'])->name('requests.exit');
    Route::post('requests/{roomRequest}/finish', [RoomVisitController::class, 'finish'])->name('requests.finish');
    Route::post('requests/{roomRequest}/guard-link', [RoomVisitController::class, 'renewGuardLink'])->name('requests.guard-link');
    Route::post('requests/{roomRequest}/people/{person}/id', [RoomAccessRequestController::class, 'revealId'])->whereNumber('person')->name('requests.reveal-id');
    Route::post('requests/{roomRequest}/attachments', [RoomAccessAttachmentController::class, 'store'])->name('requests.attachments.store');
    Route::get('requests/{roomRequest}/attachments/{attachment}', [RoomAccessAttachmentController::class, 'show'])->whereNumber('attachment')->name('requests.attachments.show');
    Route::delete('requests/{roomRequest}/attachments/{attachment}', [RoomAccessAttachmentController::class, 'destroy'])->whereNumber('attachment')->name('requests.attachments.destroy');
});

// The permit at the guard's counter, without signing in: on the company's host, or naming the
// company by its code on the shared one.
Route::get('room-permit/{token}', [RoomPermitController::class, 'onHost'])->middleware('throttle:track-link')->name('room-access.permit.public');
Route::get('t/{code}/room-permit/{token}', [RoomPermitController::class, 'onPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('room-access.permit.public.path');

// The guards' link: record the team going in and coming out, without signing in.
Route::get('room-guard/{token}', [RoomGuardController::class, 'showOnHost'])->middleware('throttle:track-link')->name('room-access.guard');
Route::get('t/{code}/room-guard/{token}', [RoomGuardController::class, 'showOnPath'])->where('code', '[0-9]{1,10}')->middleware('throttle:track-link')->name('room-access.guard.path');
Route::post('room-guard/{token}/{action}', [RoomGuardController::class, 'recordOnHost'])->whereIn('action', ['enter', 'exit'])->middleware('throttle:track-link')->name('room-access.guard.record');
Route::post('t/{code}/room-guard/{token}/{action}', [RoomGuardController::class, 'recordOnPath'])->where('code', '[0-9]{1,10}')->whereIn('action', ['enter', 'exit'])->middleware('throttle:track-link')->name('room-access.guard.record.path');
