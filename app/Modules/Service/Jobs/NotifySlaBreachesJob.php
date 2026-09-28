<?php

namespace App\Modules\Service\Jobs;

use App\Modules\Service\Actions\NotifySlaBreaches;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NotifySlaBreaches for the tenant the job was dispatched in (one job per tenant).
 */
class NotifySlaBreachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(NotifySlaBreaches $notify): void
    {
        $notify->handle();
    }
}
