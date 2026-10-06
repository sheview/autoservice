<?php

namespace App\Modules\RoomAccess\Console;

use App\Modules\RoomAccess\Actions\PurgeEntrantIdNumbers;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: in every company, delete entrants' ID card numbers kept past the company's period.
 */
class PurgeEntrantIdNumbersCommand extends Command
{
    protected $signature = 'room-access:purge-ids';

    protected $description = 'Delete server room entrants\' ID numbers past each company\'s retention period';

    public function handle(TenantContext $context, PurgeEntrantIdNumbers $purge): int
    {
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, $purge) {
            $count = $context->run($tenant, fn () => $purge->handle($tenant));
            if ($count > 0) {
                $this->info("{$tenant->name}: {$count}");
            }
        });

        return self::SUCCESS;
    }
}
