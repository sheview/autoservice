<?php

use App\Modules\Contract\Http\Controllers\ContractAssetController;
use App\Modules\Contract\Http\Controllers\ContractController;
use App\Modules\Contract\Http\Controllers\ContractDocumentController;
use App\Modules\Contract\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:contract'])->name('contract.')->group(function () {
    Route::resource('customers', CustomerController::class)->except(['show']);
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
