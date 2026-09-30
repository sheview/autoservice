<?php

use App\Modules\Inventory\Http\Controllers\PartController;
use App\Modules\Inventory\Http\Controllers\PartImportController;
use App\Modules\Inventory\Http\Controllers\PartPhotoController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use Illuminate\Support\Facades\Route;

// Parts used on a ticket are entered on the ticket page: see the Service module routes.
Route::middleware(['auth', 'verified', 'module:inventory'])->name('inventory.')->group(function () {
    // Before the resource, so "export" / "import" are not taken as a part id.
    Route::get('parts/export', [PartController::class, 'export'])->name('parts.export');
    Route::get('parts/import', [PartImportController::class, 'create'])->name('parts.import');
    Route::post('parts/import', [PartImportController::class, 'store'])->name('parts.import.store');
    Route::get('parts/import/template', [PartImportController::class, 'template'])->name('parts.import.template');

    Route::resource('parts', PartController::class);
    // Slot 0 is the main photo, 1-3 the extras.
    Route::post('parts/{part}/photos/{slot}', [PartPhotoController::class, 'store'])->where('slot', '[0-3]')->name('parts.photos.store');
    Route::get('parts/{part}/photos/{slot}', [PartPhotoController::class, 'show'])->where('slot', '[0-3]')->name('parts.photos.show');
    Route::delete('parts/{part}/photos/{slot}', [PartPhotoController::class, 'destroy'])->where('slot', '[0-3]')->name('parts.photos.destroy');

    Route::post('parts/{part}/movements', [StockMovementController::class, 'store'])->name('parts.movements.store');

    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('movements.index');
});
