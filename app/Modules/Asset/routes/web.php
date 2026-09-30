<?php

use App\Modules\Asset\Http\Controllers\AssetCategoryController;
use App\Modules\Asset\Http\Controllers\AssetController;
use App\Modules\Asset\Http\Controllers\AssetImportController;
use App\Modules\Asset\Http\Controllers\AssetPhotoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:asset'])->name('asset.')->group(function () {
    // Before the resource, so "export" / "imports" are not taken as an asset ulid.
    Route::get('assets/export', [AssetController::class, 'export'])->name('assets.export');
    Route::get('assets/imports', [AssetImportController::class, 'index'])->name('imports.index');
    Route::post('assets/imports', [AssetImportController::class, 'store'])->name('imports.store');
    Route::get('assets/imports/template', [AssetController::class, 'template'])->name('imports.template');

    Route::resource('assets', AssetController::class);

    // Slot 0 is the main photo, 1-3 the extras.
    Route::post('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'store'])->where('slot', '[0-3]')->name('assets.photos.store');
    Route::get('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'show'])->where('slot', '[0-3]')->name('assets.photos.show');
    Route::delete('assets/{asset}/photos/{slot}', [AssetPhotoController::class, 'destroy'])->where('slot', '[0-3]')->name('assets.photos.destroy');
    Route::resource('asset-categories', AssetCategoryController::class)
        ->except(['show'])
        ->parameters(['asset-categories' => 'category'])
        ->names('categories');
});
