<?php

namespace App\Modules\Service\Console;

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Jobs\NotifySlaBreachesJob;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled every 15 minutes (ServiceServiceProvider): queues one NotifySlaBreachesJob per active
 * customer tenant that has the service module on, dispatched inside that tenant.
 */
class NotifySlaBreachesCommand extends Command
{
    protected $signature = 'tickets:notify-sla-breaches';

    protected $description = 'E-mail about tickets that ran past their SLA (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                if (! $modules->enabled('service', $tenant)) {
                    return;
                }

                // Bus::dispatch sends the job right away, while run() still holds the tenant.
                $context->run($tenant, fn () => Bus::dispatch(new NotifySlaBreachesJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
