<?php

namespace App\Modules\Asset;

use App\Modules\Asset\Console\NotifyCheckoutDelaysCommand;
use App\Modules\Asset\Listeners\ReadyBackordersOnRestock;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Support\AssetKey;
use App\Modules\Inventory\Events\PartRestocked;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every asset gets the key of its QR code, however it is made (form, import, purchase).
        // (Returns nothing: a creating listener that returns a value stops the others.)
        Asset::creating(function (Asset $asset): void {
            $asset->public_key ??= AssetKey::make();
        });

        // Parts back in stock: backordered request lines are ready to hand out again.
        Event::listen(PartRestocked::class, ReadyBackordersOnRestock::class);

        if ($this->app->runningInConsole()) {
            $this->commands([NotifyCheckoutDelaysCommand::class]);
        }

        // Requests waiting too long, backorders getting late, loans past due (hourly, per tenant).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('checkouts:notify-delays')->hourly();
        });
    }
}
