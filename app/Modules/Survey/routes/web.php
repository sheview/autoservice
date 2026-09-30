<?php

use App\Modules\Survey\Http\Controllers\PublicSurveyController;
use App\Modules\Survey\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

// Answering from the ticket page (signed-in users) is in the Service module routes.
Route::middleware(['auth', 'verified', 'module:survey'])->name('survey.')->group(function () {
    Route::get('surveys', [SurveyController::class, 'index'])->name('surveys.index');
});

// The public link: no sign-in, the tenant and the module are checked by the controller.
Route::middleware('throttle:30,1')->name('survey.public.')->group(function () {
    Route::get('s/{tenant}/{token}', [PublicSurveyController::class, 'show'])
        ->where(['tenant' => '[0-9A-Za-z]{26}', 'token' => '[0-9A-Za-z]{40}'])->name('show');
    Route::post('s/{tenant}/{token}', [PublicSurveyController::class, 'store'])
        ->where(['tenant' => '[0-9A-Za-z]{26}', 'token' => '[0-9A-Za-z]{40}'])->name('store');
});
