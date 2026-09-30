<?php

namespace App\Modules\Inventory\Jobs;

use App\Modules\Inventory\Actions\NotifyLowStock;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NotifyLowStock for the tenant the job was dispatched in (one job per tenant).
 */
class NotifyLowStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(NotifyLowStock $notify): void
    {
        $notify->handle();
    }
}
