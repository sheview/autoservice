<?php

use App\Modules\Inventory\Http\Controllers\PartCheckoutController;
use App\Modules\Inventory\Http\Controllers\PartController;
use App\Modules\Inventory\Http\Controllers\PartImportController;
use App\Modules\Inventory\Http\Controllers\PartPhotoController;
use App\Modules\Inventory\Http\Controllers\PurchaseRequestController;
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

    // Issuing and lending parts (like assets)
    Route::get('part-checkouts', [PartCheckoutController::class, 'index'])->name('part-checkouts.index');
    Route::post('parts/{part}/checkouts', [PartCheckoutController::class, 'store'])->name('part-checkouts.store');
    Route::post('part-checkouts/{checkout}/approve', [PartCheckoutController::class, 'approve'])->name('part-checkouts.approve');
    Route::post('part-checkouts/{checkout}/reject', [PartCheckoutController::class, 'reject'])->name('part-checkouts.reject');
    Route::post('part-checkouts/{checkout}/return', [PartCheckoutController::class, 'giveBack'])->name('part-checkouts.return');
    Route::post('part-checkouts/{checkout}/cancel', [PartCheckoutController::class, 'cancel'])->name('part-checkouts.cancel');
    Route::get('part-checkouts/{checkout}/print', [PartCheckoutController::class, 'print'])->name('part-checkouts.print');
    Route::get('part-checkouts/{checkout}/pdf', [PartCheckoutController::class, 'pdf'])->name('part-checkouts.pdf');

    // Purchase requests
    Route::resource('purchase-requests', PurchaseRequestController::class)->except(['destroy']);
    Route::post('purchase-requests/{purchase_request}/move', [PurchaseRequestController::class, 'move'])->name('purchase-requests.move');
    Route::get('purchase-requests/{purchase_request}/print', [PurchaseRequestController::class, 'print'])->name('purchase-requests.print');
    Route::get('purchase-requests/{purchase_request}/pdf', [PurchaseRequestController::class, 'pdf'])->name('purchase-requests.pdf');
    Route::post('purchase-requests/{purchase_request}/attachments', [PurchaseRequestController::class, 'storeAttachment'])
        ->name('purchase-requests.attachments.store');
    Route::get('purchase-requests/{purchase_request}/attachments/{attachment}', [PurchaseRequestController::class, 'attachment'])
        ->whereNumber('attachment')->name('purchase-requests.attachments.show');
    Route::delete('purchase-requests/{purchase_request}/attachments/{attachment}', [PurchaseRequestController::class, 'removeAttachment'])
        ->whereNumber('attachment')->name('purchase-requests.attachments.destroy');
});
