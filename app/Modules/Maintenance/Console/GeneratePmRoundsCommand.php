<?php

namespace App\Modules\Maintenance\Console;

use App\Modules\Maintenance\Jobs\GeneratePmRoundsJob;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled daily (MaintenanceServiceProvider): the work orders of PM rounds coming near, made ahead
 * by each company's generate_days_ahead — one GeneratePmRoundsJob per active company with the
 * maintenance module on, dispatched inside its tenant (InteractsWithTenant).
 */
class GeneratePmRoundsCommand extends Command
{
    protected $signature = 'pm:generate-rounds';

    protected $description = 'Make the work orders of PM rounds coming near (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                if (! $modules->enabled('maintenance', $tenant) || Subscription::of($tenant)['locked']) {
                    return;
                }

                $context->run($tenant, fn () => Bus::dispatch(new GeneratePmRoundsJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
