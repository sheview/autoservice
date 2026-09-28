<?php

namespace App\Modules\Asset\Jobs;

use App\Modules\Asset\Actions\ImportAssets;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\Activitylog\CauserResolver;

/**
 * Runs an asset import in the tenant it was uploaded in.
 *
 * The uploader's branch scope is decided at upload time and passed in, because the uploader
 * may be an impersonating superadmin who is not a user of this tenant.
 */
class ImportAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $importId,
        public bool $allBranches,
        public ?int $ownBranchId,
    ) {}

    public function handle(ImportAssets $importAssets, CauserResolver $causer): void
    {
        $import = AssetImport::find($this->importId);
        if ($import === null || $import->isFinished()) {
            return;
        }

        // Log changes as made by the uploader when they are a user of this tenant.
        if ($import->user_id && $user = User::find($import->user_id)) {
            $causer->setCauser($user);
        }

        try {
            $importAssets->handle($import, $this->allBranches, $this->ownBranchId);
        } finally {
            // The resolver is a singleton; do not leak the causer into the worker's next job.
            $causer->setCauser(null);
        }
    }
}
