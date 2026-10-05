<?php

use App\Modules\RoomAccess\Http\Controllers\RoomRuleController;
use App\Modules\RoomAccess\Http\Controllers\ServerRoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:room_access'])->prefix('room-access')->name('room-access.')->group(function () {
    // Setting up rooms, their rules (versions) and the company's own terms.
    Route::put('settings', [ServerRoomController::class, 'settings'])->name('settings');
    Route::resource('rooms', ServerRoomController::class);
    Route::post('rooms/{room}/rules', [RoomRuleController::class, 'store'])->name('rooms.rules.store');
    Route::get('rooms/{room}/rules/{version}/file', [RoomRuleController::class, 'file'])->whereNumber('version')->name('rooms.rules.file');
});
