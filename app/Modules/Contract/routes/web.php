<?php

use App\Modules\Contract\Http\Controllers\ContractAssetController;
use App\Modules\Contract\Http\Controllers\ContractController;
use App\Modules\Contract\Http\Controllers\ContractDocumentController;
use App\Modules\Contract\Http\Controllers\CustomerAttachmentController;
use App\Modules\Contract\Http\Controllers\CustomerController;
use App\Modules\Contract\Http\Controllers\CustomerSiteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:contract'])->name('contract.')->group(function () {
    Route::resource('customers', CustomerController::class)->except(['show']);
    Route::post('customers/{customer}/attachments', [CustomerAttachmentController::class, 'store'])->name('customers.attachments.store');
    Route::get('customers/{customer}/attachments/{attachment}', [CustomerAttachmentController::class, 'show'])
        ->whereNumber('attachment')->name('customers.attachments.show');
    Route::delete('customers/{customer}/attachments/{attachment}', [CustomerAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')->name('customers.attachments.destroy');

    Route::post('customers/{customer}/sites', [CustomerSiteController::class, 'store'])->name('customers.sites.store');
    Route::put('customers/{customer}/sites/{site}', [CustomerSiteController::class, 'update'])->whereNumber('site')->name('customers.sites.update');
    Route::delete('customers/{customer}/sites/{site}', [CustomerSiteController::class, 'destroy'])->whereNumber('site')->name('customers.sites.destroy');

    Route::resource('contracts', ContractController::class);

    Route::post('contracts/{contract}/assets', [ContractAssetController::class, 'store'])->name('contracts.assets.store');
    Route::delete('contracts/{contract}/assets/{asset}', [ContractAssetController::class, 'destroy'])
        ->whereNumber('asset')->name('contracts.assets.destroy');

    Route::post('contracts/{contract}/documents', [ContractDocumentController::class, 'store'])->name('contracts.documents.store');
    Route::get('contracts/{contract}/documents/{document}', [ContractDocumentController::class, 'show'])
        ->whereNumber('document')->name('contracts.documents.show');
    Route::delete('contracts/{contract}/documents/{document}', [ContractDocumentController::class, 'destroy'])
        ->whereNumber('document')->name('contracts.documents.destroy');
});
