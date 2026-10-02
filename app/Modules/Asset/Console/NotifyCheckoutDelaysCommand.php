<?php

namespace App\Modules\Asset\Console;

use App\Modules\Asset\Jobs\NotifyCheckoutDelaysJob;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled hourly (AssetServiceProvider): queues one NotifyCheckoutDelaysJob per active customer
 * tenant with the asset module on, dispatched inside the tenant (InteractsWithTenant).
 */
class NotifyCheckoutDelaysCommand extends Command
{
    protected $signature = 'checkouts:notify-delays';

    protected $description = 'Alert about late issue/loan requests: approval, backorders, loans past due (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                if (! $modules->enabled('asset', $tenant) || Subscription::of($tenant)['locked']) {
                    return;
                }

                $context->run($tenant, fn () => Bus::dispatch(new NotifyCheckoutDelaysJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
