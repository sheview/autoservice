<?php

namespace App\Modules\Service;

use App\Modules\Service\Console\AnonymizeReportersCommand;
use App\Modules\Service\Console\NotifySlaBreachesCommand;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TrackingToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ServiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every ticket, however it is opened, has a tracking link from the start.
        // (A creating listener that returns a value stops the others: this one returns nothing.)
        Ticket::creating(function (Ticket $ticket): void {
            $ticket->tracking_token ??= TrackingToken::make();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([NotifySlaBreachesCommand::class, AnonymizeReportersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('tickets:notify-sla-breaches')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('tickets:anonymize-reporters')->dailyAt('03:00')->timezone('Asia/Bangkok');
        });
    }
}
