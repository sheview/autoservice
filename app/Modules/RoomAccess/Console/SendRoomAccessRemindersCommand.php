<?php

namespace App\Modules\RoomAccess\Console;

use App\Modules\RoomAccess\Actions\SendRoomAccessReminders;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 15 minutes, in every company: server room requests starting soon, teams still inside after
 * the planned end, and requests waiting too long for approval (each alert once).
 */
class SendRoomAccessRemindersCommand extends Command
{
    protected $signature = 'room-access:notify';

    protected $description = 'Send server room reminders: starting soon, still inside, approval overdue';

    public function handle(TenantContext $context, SendRoomAccessReminders $remind): int
    {
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, $remind) {
            $sent = $context->run($tenant, fn () => $remind->handle());
            if (array_sum($sent) > 0) {
                $this->info("{$tenant->name}: ".json_encode($sent));
            }
        });

        return self::SUCCESS;
    }
}
