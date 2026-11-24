<?php

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Actions\RunBackup;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * "Back up now" from the backup page. Of the whole platform, not of a company. Once at a time,
 * never retried by itself (a failure is alerted; running it again is a decision).
 */
class RunBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public int $tries = 1;

    /** A large database and many photos take a while. */
    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function handle(RunBackup $runBackup): void
    {
        $runBackup->handle();
    }
}
