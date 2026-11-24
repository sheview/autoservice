<?php

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\UserSaved;
use App\Modules\Identity\Jobs\SyncKeycloakAccountsJob;
use App\Modules\Identity\Support\KeycloakAdmin;

/**
 * A user added or changed in Governix: their Keycloak account follows, in the queue (after the
 * save is committed), so a slow or unreachable Keycloak never holds up or fails the save.
 */
class QueueKeycloakSync
{
    public function handle(UserSaved $event): void
    {
        if (KeycloakAdmin::enabled()) {
            SyncKeycloakAccountsJob::dispatch($event->user->id)->afterCommit();
        }
    }
}
