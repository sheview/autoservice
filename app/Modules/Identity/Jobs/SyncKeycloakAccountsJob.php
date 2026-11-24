<?php

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Actions\SyncKeycloakAccount;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * SyncKeycloakAccount for one user, or for every staff member of the company the job was
 * dispatched in (when the company turns SSO on). Retried: Keycloak may be down for a moment.
 */
class SyncKeycloakAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public ?int $userId = null) {}

    public function handle(SyncKeycloakAccount $sync): void
    {
        // Users of this job's company only (tenant scope).
        $users = $this->userId !== null
            ? User::whereKey($this->userId)->get()
            : User::whereNull('login_user_id')->whereNull('customer_id')->get();

        $users->each(fn (User $user) => $sync->handle($user));
    }
}
