<?php

use App\Modules\RoomAccess\Http\Controllers\RoomAccessAttachmentController;
use App\Modules\RoomAccess\Http\Controllers\RoomAccessRequestController;
use App\Modules\RoomAccess\Http\Controllers\RoomRuleController;
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
    Route::post('requests/{roomRequest}/people/{person}/id', [RoomAccessRequestController::class, 'revealId'])->whereNumber('person')->name('requests.reveal-id');
    Route::post('requests/{roomRequest}/attachments', [RoomAccessAttachmentController::class, 'store'])->name('requests.attachments.store');
    Route::get('requests/{roomRequest}/attachments/{attachment}', [RoomAccessAttachmentController::class, 'show'])->whereNumber('attachment')->name('requests.attachments.show');
    Route::delete('requests/{roomRequest}/attachments/{attachment}', [RoomAccessAttachmentController::class, 'destroy'])->whereNumber('attachment')->name('requests.attachments.destroy');
});
