<?php

namespace App\Modules\Service;

use App\Modules\Service\Console\NotifySlaBreachesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ServiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([NotifySlaBreachesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('tickets:notify-sla-breaches')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
