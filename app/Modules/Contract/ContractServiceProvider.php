<?php

namespace App\Modules\Contract;

use App\Modules\Contract\Console\NotifyExpiringContractsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ContractServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyExpiringContractsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('contracts:notify-expiring')->dailyAt('08:00')->timezone('Asia/Bangkok');
        });
    }
}
