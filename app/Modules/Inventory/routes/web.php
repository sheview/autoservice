<?php

use App\Modules\Inventory\Http\Controllers\PartController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use Illuminate\Support\Facades\Route;

// Parts used on a ticket are entered on the ticket page: see the Service module routes.
Route::middleware(['auth', 'verified', 'module:inventory'])->name('inventory.')->group(function () {
    Route::resource('parts', PartController::class);
    Route::post('parts/{part}/movements', [StockMovementController::class, 'store'])->name('parts.movements.store');

    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('movements.index');
});
