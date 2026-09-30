<?php

use App\Modules\Labeling\Http\Controllers\LabelController;
use App\Modules\Labeling\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:labeling'])->name('labeling.')->group(function () {
    Route::get('labels', [LabelController::class, 'index'])->name('labels.index');
    Route::get('labels/print', [LabelController::class, 'print'])->name('labels.print');
    Route::post('labels/print', [LabelController::class, 'record'])->name('labels.record');
});

// The QR code on a label. Labels already stuck on assets keep working when labeling is switched off.
Route::middleware(['auth', 'verified', 'module:asset'])->group(function () {
    Route::get('a/{asset}', [ScanController::class, 'show'])->where('asset', '[0-9A-Za-z]{26}')->name('labeling.scan');
});
