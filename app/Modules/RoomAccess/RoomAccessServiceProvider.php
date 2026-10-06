<?php

namespace App\Modules\RoomAccess;

use App\Modules\RoomAccess\Console\MarkOverdueRoomRequestsCommand;
use App\Modules\RoomAccess\Console\PurgeEntrantIdNumbersCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class RoomAccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MarkOverdueRoomRequestsCommand::class, PurgeEntrantIdNumbersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('room-access:mark-overdue')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('room-access:purge-ids')->dailyAt('03:30')->timezone('Asia/Bangkok');
        });
    }
}
