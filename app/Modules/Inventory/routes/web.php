<?php

use App\Modules\Inventory\Http\Controllers\PartCategoryController;
use App\Modules\Inventory\Http\Controllers\PartController;
use App\Modules\Inventory\Http\Controllers\PartImportController;
use App\Modules\Inventory\Http\Controllers\PartPhotoController;
use App\Modules\Inventory\Http\Controllers\PartUnitController;
use App\Modules\Inventory\Http\Controllers\PurchaseReceiptController;
use App\Modules\Inventory\Http\Controllers\PurchaseRequestController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\StockReceiptController;
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

    // Pieces of parts followed by serial number.
    Route::get('parts/{part}/units/options', [PartUnitController::class, 'options'])->name('parts.units.options');
    Route::get('parts/{part}/serials/start', [PartUnitController::class, 'start'])->name('parts.serials.start');
    Route::post('parts/{part}/serials/start', [PartUnitController::class, 'storeStart'])->name('parts.serials.store-start');
    Route::post('parts/{part}/serials/stop', [PartUnitController::class, 'stop'])->name('parts.serials.stop');
    Route::get('part-units/{unit}/history', [PartUnitController::class, 'history'])->whereNumber('unit')->name('part-units.history');
    Route::put('part-units/{unit}', [PartUnitController::class, 'update'])->whereNumber('unit')->name('part-units.update');

    Route::get('part-categories', [PartCategoryController::class, 'index'])->name('part-categories.index');
    Route::post('part-categories', [PartCategoryController::class, 'store'])->name('part-categories.store');
    Route::put('part-categories/{category}', [PartCategoryController::class, 'update'])->whereNumber('category')->name('part-categories.update');
    Route::delete('part-categories/{category}', [PartCategoryController::class, 'destroy'])->whereNumber('category')->name('part-categories.destroy');

    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('movements.index');

    // Receiving goods without a purchase request (by serial for tracked parts).
    Route::get('stock-receipts/create', [StockReceiptController::class, 'create'])->name('stock-receipts.create');
    Route::get('stock-receipts/parts', [StockReceiptController::class, 'parts'])->name('stock-receipts.parts');
    Route::post('stock-receipts', [StockReceiptController::class, 'store'])->name('stock-receipts.store');

    // Purchase requests
    Route::resource('purchase-requests', PurchaseRequestController::class)->except(['destroy']);
    Route::post('purchase-requests/{purchase_request}/move', [PurchaseRequestController::class, 'move'])->name('purchase-requests.move');
    Route::post('purchase-requests/{purchase_request}/move-batch', [PurchaseRequestController::class, 'moveBatch'])->name('purchase-requests.move-batch');
    // Deliveries (some or all of it, several times) and registering what came as assets / parts.
    Route::post('purchase-requests/{purchase_request}/receipts', [PurchaseReceiptController::class, 'store'])->name('purchase-requests.receipts.store');
    Route::post('purchase-requests/{purchase_request}/hand-out', [PurchaseReceiptController::class, 'handOut'])->name('purchase-requests.hand-out');
    Route::post('purchase-requests/{purchase_request}/receipts/{receipt}/register', [PurchaseReceiptController::class, 'register'])
        ->whereNumber('receipt')->name('purchase-requests.receipts.register');
    Route::get('purchase-requests/{purchase_request}/print', [PurchaseRequestController::class, 'print'])->name('purchase-requests.print');
    Route::get('purchase-requests/{purchase_request}/pdf', [PurchaseRequestController::class, 'pdf'])->name('purchase-requests.pdf');
    Route::post('purchase-requests/{purchase_request}/attachments', [PurchaseRequestController::class, 'storeAttachment'])
        ->name('purchase-requests.attachments.store');
    Route::get('purchase-requests/{purchase_request}/attachments/{attachment}', [PurchaseRequestController::class, 'attachment'])
        ->whereNumber('attachment')->name('purchase-requests.attachments.show');
    Route::delete('purchase-requests/{purchase_request}/attachments/{attachment}', [PurchaseRequestController::class, 'removeAttachment'])
        ->whereNumber('attachment')->name('purchase-requests.attachments.destroy');
});
