<?php

namespace App\Modules\Maintenance\Jobs;

use App\Modules\Maintenance\Actions\NotifyUpcomingPm;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NotifyUpcomingPm for the tenant the job was dispatched in (one job per tenant).
 */
class NotifyUpcomingPmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(NotifyUpcomingPm $notify): void
    {
        $notify->handle();
    }
}
