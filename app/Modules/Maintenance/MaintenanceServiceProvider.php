<?php

namespace App\Modules\Maintenance;

use App\Modules\Maintenance\Console\NotifyUpcomingPmCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class MaintenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyUpcomingPmCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('pm:notify-upcoming')->dailyAt('07:30')->timezone('Asia/Bangkok');
        });
    }
}
