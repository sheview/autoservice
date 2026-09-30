<?php

use App\Modules\Inventory\Http\Controllers\PartController;
use App\Modules\Inventory\Http\Controllers\PartImportController;
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
    Route::post('parts/{part}/movements', [StockMovementController::class, 'store'])->name('parts.movements.store');

    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('movements.index');
});
