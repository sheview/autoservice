<?php

namespace App\Modules\Maintenance;

use App\Modules\Asset\Events\AssetCategoriesMerged;
use App\Modules\Asset\Events\AssetImportRowSaved;
use App\Modules\Maintenance\Console\GeneratePmRoundsCommand;
use App\Modules\Maintenance\Console\NotifyUpcomingPmCommand;
use App\Modules\Maintenance\Listeners\AddImportedAssetToPmPlan;
use App\Modules\Maintenance\Listeners\MovePmToMergedCategory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MaintenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyUpcomingPmCommand::class, GeneratePmRoundsCommand::class]);
        }

        // Equipment imported from a PM plan joins the plan.
        Event::listen(AssetImportRowSaved::class, AddImportedAssetToPmPlan::class);
        // Two categories merged: their checklists follow.
        Event::listen(AssetCategoriesMerged::class, MovePmToMergedCategory::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Work orders first, then the reminders about them.
            $schedule->command('pm:generate-rounds')->dailyAt('06:00')->timezone('Asia/Bangkok');
            $schedule->command('pm:notify-upcoming')->dailyAt('07:30')->timezone('Asia/Bangkok');
        });
    }
}
