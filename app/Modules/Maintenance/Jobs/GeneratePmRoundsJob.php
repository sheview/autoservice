<?php

namespace App\Modules\Maintenance\Jobs;

use App\Modules\Maintenance\Actions\GeneratePmRounds;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * GeneratePmRounds for the tenant the job was dispatched in (one job per tenant).
 */
class GeneratePmRoundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function handle(GeneratePmRounds $generate): void
    {
        $generate->handle();
    }
}
