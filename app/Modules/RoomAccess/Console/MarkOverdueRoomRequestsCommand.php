<?php

namespace App\Modules\RoomAccess\Console;

use App\Modules\RoomAccess\Actions\MarkOverdueRoomRequests;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 15 minutes: in every company, approved room requests nobody went in on before the planned
 * end become overdue.
 */
class MarkOverdueRoomRequestsCommand extends Command
{
    protected $signature = 'room-access:mark-overdue';

    protected $description = 'Mark approved server room requests past their planned end, never entered, as overdue';

    public function handle(TenantContext $context, MarkOverdueRoomRequests $mark): int
    {
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, $mark) {
            $count = $context->run($tenant, fn () => $mark->handle());
            if ($count > 0) {
                $this->info("{$tenant->name}: {$count}");
            }
        });

        return self::SUCCESS;
    }
}
