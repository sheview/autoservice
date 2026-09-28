<?php

namespace App\Modules\Contract\Console;

use App\Modules\Contract\Jobs\NotifyExpiringContractsJob;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled daily (ContractServiceProvider): queues one NotifyExpiringContractsJob per active
 * customer tenant that has the contract module on. Each job is dispatched inside its tenant,
 * so InteractsWithTenant carries the tenant to the worker.
 */
class NotifyExpiringContractsCommand extends Command
{
    protected $signature = 'contracts:notify-expiring';

    protected $description = 'E-mail users about contracts that are about to expire (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                if (! $modules->enabled('contract', $tenant)) {
                    return;
                }

                // Bus::dispatch sends the job right away. NotifyExpiringContractsJob::dispatch() would only
                // send it when its PendingDispatch is destroyed, i.e. after run() switched the tenant back.
                $context->run($tenant, fn () => Bus::dispatch(new NotifyExpiringContractsJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
