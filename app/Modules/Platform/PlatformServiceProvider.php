<?php

namespace App\Modules\Platform;

use App\Modules\Identity\Events\UserSaved;
use App\Modules\Platform\Console\InstallPlatformCommand;
use App\Modules\Platform\Console\LinkAccountsCommand;
use App\Modules\Platform\Console\PruneActivityLogCommand;
use App\Modules\Platform\Console\SyncPermissionsCommand;
use App\Modules\Platform\Console\SyncStaffPoolsCommand;
use App\Modules\Platform\Listeners\FollowForwardedTicket;
use App\Modules\Platform\Listeners\SyncStaffPoolsForUser;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Events\TicketStatusChanged;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Impersonation::class);
    }

    public function boot(): void
    {
        // A ticket another company forwarded to us moved: noted on theirs (cross-company sharing).
        Event::listen(TicketStatusChanged::class, FollowForwardedTicket::class);
        // A person of a company that shares its staff was added or changed: their linked accounts follow.
        Event::listen(UserSaved::class, SyncStaffPoolsForUser::class);

        // Module switches are per tenant, not per user.
        Feature::resolveScopeUsing(fn () => $this->app->make(TenantContext::class)->tenant());

        foreach (config('modules.toggleable', []) as $key => $module) {
            Feature::define(Modules::feature($key), fn (Tenant $tenant) => ! $tenant->is_platform && (bool) $module['default']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallPlatformCommand::class, LinkAccountsCommand::class, SyncPermissionsCommand::class, SyncStaffPoolsCommand::class, PruneActivityLogCommand::class]);
        }

        // The activity log keeps SearchActivityLog::KEEP_DAYS days.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('activitylog:prune')->dailyAt('03:00')->timezone('Asia/Bangkok');
            $schedule->command('platform:sync-staff-pools')->hourly();
        });
    }
}
