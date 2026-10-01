<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Jobs\NotifyLowStockJob;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Scheduled daily (InventoryServiceProvider): queues one NotifyLowStockJob per active customer
 * tenant that has the inventory module on. Each job is dispatched inside its tenant, so
 * InteractsWithTenant carries the tenant to the worker.
 */
class NotifyLowStockCommand extends Command
{
    protected $signature = 'inventory:notify-low-stock';

    protected $description = 'E-mail the staff who restock about parts at or below their reorder point (every tenant)';

    public function handle(TenantContext $context, Modules $modules): int
    {
        $count = 0;

        Tenant::query()
            ->where('is_platform', false)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->each(function (Tenant $tenant) use ($context, $modules, &$count) {
                // A company locked out (subscription over) gets no e-mails either.
                if (! $modules->enabled('inventory', $tenant) || Subscription::of($tenant)['locked']) {
                    return;
                }

                // Bus::dispatch sends the job right away (see NotifyExpiringContractsCommand).
                $context->run($tenant, fn () => Bus::dispatch(new NotifyLowStockJob));
                $count++;
            });

        $this->info("Queued for {$count} tenant(s).");

        return self::SUCCESS;
    }
}
