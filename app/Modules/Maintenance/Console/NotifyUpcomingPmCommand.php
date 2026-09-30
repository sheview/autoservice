<?php

namespace App\Modules\Maintenance\Console;

use App\Modules\Maintenance\Jobs\NotifyUpcomingPmJob;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled daily (MaintenanceServiceProvider): queues one NotifyUpcomingPmJob per active
 * customer tenant that has the maintenance module on. Each job is dispatched inside its tenant,
 * so InteractsWithTenant carries the tenant to the worker.
 */
class NotifyUpcomingPmCommand extends Command
{
    protected $signature = 'pm:notify-upcoming';

    protected $description = 'E-mail technicians about PM rounds coming up (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                if (! $modules->enabled('maintenance', $tenant)) {
                    return;
                }

                // Bus::dispatch sends the job right away (see NotifyExpiringContractsCommand).
                $context->run($tenant, fn () => Bus::dispatch(new NotifyUpcomingPmJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
