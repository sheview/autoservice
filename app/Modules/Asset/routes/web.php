<?php

use App\Modules\Asset\Http\Controllers\AssetAttachmentController;
use App\Modules\Asset\Http\Controllers\AssetCategoryController;
use App\Modules\Asset\Http\Controllers\AssetController;
use App\Modules\Asset\Http\Controllers\AssetImportController;
use App\Modules\Asset\Http\Controllers\AssetPhotoController;
use App\Modules\Asset\Http\Controllers\CheckoutItemController;
use App\Modules\Asset\Http\Controllers\CheckoutRequestController;
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

    // Issue / loan requests with lines (assets and parts)
    Route::get('checkout-requests/items', [CheckoutRequestController::class, 'items'])->name('requests.items');
    Route::get('checkout-requests/tickets', [CheckoutRequestController::class, 'tickets'])->name('requests.tickets');
    Route::get('checkout-requests', [CheckoutRequestController::class, 'index'])->name('requests.index');
    Route::get('checkout-requests/create', [CheckoutRequestController::class, 'create'])->name('requests.create');
    Route::post('checkout-requests', [CheckoutRequestController::class, 'store'])->name('requests.store');
    Route::get('checkout-requests/{checkout}', [CheckoutRequestController::class, 'show'])->name('requests.show');
    Route::get('checkout-requests/{checkout}/edit', [CheckoutRequestController::class, 'edit'])->name('requests.edit');
    Route::put('checkout-requests/{checkout}', [CheckoutRequestController::class, 'update'])->name('requests.update');
    foreach (['submit', 'approve', 'reject', 'cancel', 'close'] as $action) {
        Route::post("checkout-requests/{checkout}/{$action}", [CheckoutRequestController::class, $action])->name("requests.{$action}");
    }
    Route::get('checkout-requests/{checkout}/print', [CheckoutRequestController::class, 'print'])->name('requests.print');
    Route::get('checkout-requests/{checkout}/pdf', [CheckoutRequestController::class, 'pdf'])->name('requests.pdf');
    Route::post('checkout-items/{item}/fulfill', [CheckoutItemController::class, 'fulfill'])->whereNumber('item')->name('items.fulfill');
    Route::post('checkout-items/{item}/backorder', [CheckoutItemController::class, 'backorder'])->whereNumber('item')->name('items.backorder');
    Route::post('checkout-items/{item}/cancel', [CheckoutItemController::class, 'cancel'])->whereNumber('item')->name('items.cancel');
    Route::post('checkout-items/{item}/return', [CheckoutItemController::class, 'giveBack'])->whereNumber('item')->name('items.return');

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
