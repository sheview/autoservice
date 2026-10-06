<?php

namespace Tests\Fixtures;

use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Records what a queued job can see, for the tenancy tests.
 */
class RecordVisibleBranches implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public static ?array $seen = null;

    public function handle(): void
    {
        static::$seen = [
            'tenant' => $this->tenant()?->id,
            'eloquent' => Branch::orderBy('name')->pluck('name')->all(),
        ];
    }
}
