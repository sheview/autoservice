<?php

namespace App\Modules\Inventory;

use App\Modules\Asset\Events\AssetCategoryDeleted;
use App\Modules\Asset\Events\PurchasedItemHandedOut;
use App\Modules\Asset\Events\StorageLocationDeleted;
use App\Modules\Inventory\Console\NotifyLowStockCommand;
use App\Modules\Inventory\Listeners\CountPurchaseIssued;
use App\Modules\Inventory\Listeners\DetachPartsFromCategory;
use App\Modules\Inventory\Listeners\DetachPartsFromLocation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Bought goods handed out on an issue/loan request: the purchase request counts them.
        Event::listen(PurchasedItemHandedOut::class, CountPurchaseIssued::class);
        // Parts share the asset categories: a removed one leaves its parts with none.
        Event::listen(AssetCategoryDeleted::class, DetachPartsFromCategory::class);
        Event::listen(StorageLocationDeleted::class, DetachPartsFromLocation::class);

        if ($this->app->runningInConsole()) {
            $this->commands([NotifyLowStockCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('inventory:notify-low-stock')->dailyAt('08:00')->timezone('Asia/Bangkok');
        });
    }
}
