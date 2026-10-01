<?php

use App\Modules\Document\Http\Controllers\ManualController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->name('document.')->group(function () {
    // Manuals: links and files of how-tos, device manuals, procedures.
    Route::resource('manuals', ManualController::class);
    Route::post('manuals/{manual}/attachments', [ManualController::class, 'storeFile'])->name('manuals.attachments.store');
    Route::get('manuals/{manual}/attachments/{attachment}', [ManualController::class, 'file'])
        ->whereNumber('attachment')->name('manuals.attachments.show');
    Route::delete('manuals/{manual}/attachments/{attachment}', [ManualController::class, 'destroyFile'])
        ->whereNumber('attachment')->name('manuals.attachments.destroy');
});
