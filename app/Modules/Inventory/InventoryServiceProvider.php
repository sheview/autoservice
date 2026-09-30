<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Console\NotifyLowStockCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyLowStockCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('inventory:notify-low-stock')->dailyAt('08:00')->timezone('Asia/Bangkok');
        });
    }
}
