<?php

use App\Modules\Labeling\Http\Controllers\LabelController;
use App\Modules\Labeling\Http\Controllers\QrController;
use App\Modules\Labeling\Http\Controllers\QrPublicController;
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

// An asset's QR page: staff signed in get the full page, anyone else the public one (QrController).
Route::get('q/{code}', [QrController::class, 'onHost'])->where('code', '[A-Za-z0-9_-]{1,50}')->middleware('throttle:qr-page')->name('labeling.qr');
Route::get('t/{company}/q/{code}', [QrController::class, 'onPath'])->where(['company' => '[0-9]{1,10}', 'code' => '[A-Za-z0-9_-]{1,50}'])
    ->middleware('throttle:qr-page')->name('labeling.qr.path');
// Reporting a problem from the public page (no sign-in).
Route::post('q/{code}/report', [QrPublicController::class, 'reportOnHost'])->where('code', '[A-Za-z0-9_-]{1,50}')->middleware('throttle:qr-report')->name('labeling.qr.report');
Route::post('t/{company}/q/{code}/report', [QrPublicController::class, 'reportOnPath'])->where(['company' => '[0-9]{1,10}', 'code' => '[A-Za-z0-9_-]{1,50}'])
    ->middleware('throttle:qr-report')->name('labeling.qr.path.report');
// "Staff sign in" on the public page: sign in, then back to the same device.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('q/{code}/staff', [QrController::class, 'signInOnHost'])
        ->where('code', '[A-Za-z0-9_-]{1,50}')->name('labeling.qr.staff');
    Route::get('t/{company}/q/{code}/staff', [QrController::class, 'signInOnPath'])
        ->where(['company' => '[0-9]{1,10}', 'code' => '[A-Za-z0-9_-]{1,50}'])->name('labeling.qr.path.staff');
});
