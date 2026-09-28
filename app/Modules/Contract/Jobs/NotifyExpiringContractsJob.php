<?php

namespace App\Modules\Contract\Jobs;

use App\Modules\Contract\Actions\NotifyExpiringContracts;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NotifyExpiringContracts for the tenant the job was dispatched in (one job per tenant).
 */
class NotifyExpiringContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(NotifyExpiringContracts $notify): void
    {
        $notify->handle();
    }
}
