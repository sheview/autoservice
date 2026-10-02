<?php

namespace App\Modules\Asset\Jobs;

use App\Modules\Asset\Actions\NotifyCheckoutDelays;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NotifyCheckoutDelays for the tenant the job was dispatched in (one job per tenant).
 */
class NotifyCheckoutDelaysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(NotifyCheckoutDelays $notify): void
    {
        $notify->handle();
    }
}
