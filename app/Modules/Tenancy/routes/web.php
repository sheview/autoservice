<?php

use App\Modules\Tenancy\Http\Controllers\CompanyProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->name('tenancy.')->group(function () {
    Route::get('company', [CompanyProfileController::class, 'edit'])->name('company.edit');
    Route::put('company', [CompanyProfileController::class, 'update'])->name('company.update');
    Route::get('company/logo', [CompanyProfileController::class, 'logo'])->name('company.logo');
});
