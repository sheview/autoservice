<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\SearchActivityLog;
use App\Modules\Platform\Models\Activity;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled daily (PlatformServiceProvider): deletes activity log entries older than
 * SearchActivityLog::KEEP_DAYS days, tenant by tenant (RLS lets each delete only its own;
 * spatie's activitylog:clean would see no rows without a tenant).
 */
class PruneActivityLogCommand extends Command
{
    protected $signature = 'activitylog:prune';

    protected $description = 'Delete activity log entries older than '.SearchActivityLog::KEEP_DAYS.' days (every tenant)';

    public function handle(TenantContext $context): int
    {
        $cutoff = now()->subDays(SearchActivityLog::KEEP_DAYS);
        $deleted = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($context, $cutoff, &$deleted) {
            $deleted += $context->run($tenant, fn () => Activity::query()->where('created_at', '<', $cutoff)->delete());
        });

        $this->info("Deleted {$deleted} entr(y/ies).");

        return self::SUCCESS;
    }
}
