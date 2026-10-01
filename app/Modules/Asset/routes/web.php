<?php

use App\Modules\Asset\Http\Controllers\AssetAttachmentController;
use App\Modules\Asset\Http\Controllers\AssetCategoryController;
use App\Modules\Asset\Http\Controllers\AssetCheckoutController;
use App\Modules\Asset\Http\Controllers\AssetController;
use App\Modules\Asset\Http\Controllers\AssetImportController;
use App\Modules\Asset\Http\Controllers\AssetPhotoController;
use App\Modules\Asset\Http\Controllers\IpCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:asset'])->name('asset.')->group(function () {
    // Before the resource, so "export" / "imports" are not taken as an asset ulid.
    Route::get('assets/export', [AssetController::class, 'export'])->name('assets.export');
    Route::get('assets/imports', [AssetImportController::class, 'index'])->name('imports.index');
    Route::post('assets/imports', [AssetImportController::class, 'store'])->name('imports.store');
    Route::get('assets/imports/template', [AssetController::class, 'template'])->name('imports.template');

    Route::get('ip-check', IpCheckController::class)->name('ip-check');

    Route::resource('assets', AssetController::class);

    // Slot 0 is the main photo, 1-3 the extras.
    Route::post('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'store'])->where('slot', '[0-3]')->name('assets.photos.store');
    Route::get('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'show'])->where('slot', '[0-3]')->name('assets.photos.show');
    Route::delete('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'destroy'])->where('slot', '[0-3]')->name('assets.photos.destroy');

    // Issue / loan
    Route::get('asset-checkouts', [AssetCheckoutController::class, 'index'])->name('checkouts.index');
    Route::get('asset-checkouts/create', [AssetCheckoutController::class, 'create'])->name('checkouts.create');
    Route::post('assets/{asset}/checkouts', [AssetCheckoutController::class, 'store'])->name('checkouts.store');
    Route::post('asset-checkouts/{checkout}/approve', [AssetCheckoutController::class, 'approve'])->name('checkouts.approve');
    Route::post('asset-checkouts/{checkout}/reject', [AssetCheckoutController::class, 'reject'])->name('checkouts.reject');
    Route::post('asset-checkouts/{checkout}/return', [AssetCheckoutController::class, 'giveBack'])->name('checkouts.return');
    Route::post('asset-checkouts/{checkout}/cancel', [AssetCheckoutController::class, 'cancel'])->name('checkouts.cancel');
    Route::get('asset-checkouts/{checkout}/print', [AssetCheckoutController::class, 'print'])->name('checkouts.print');
    Route::get('asset-checkouts/{checkout}/pdf', [AssetCheckoutController::class, 'pdf'])->name('checkouts.pdf');

    Route::post('assets/{asset}/attachments', [AssetAttachmentController::class, 'store'])->name('assets.attachments.store');
    Route::get('assets/{asset}/attachments/{attachment}', [AssetAttachmentController::class, 'show'])
        ->whereNumber('attachment')->name('assets.attachments.show');
    Route::delete('assets/{asset}/attachments/{attachment}', [AssetAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')->name('assets.attachments.destroy');

    Route::resource('asset-categories', AssetCategoryController::class)
        ->except(['show'])
        ->parameters(['asset-categories' => 'category'])
        ->names('categories');
});
