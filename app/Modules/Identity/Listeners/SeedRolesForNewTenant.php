<?php

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Actions\SeedDefaultRoles;
use App\Modules\Tenancy\Events\TenantCreated;

class SeedRolesForNewTenant
{
    public function __construct(private SeedDefaultRoles $seedDefaultRoles) {}

    public function handle(TenantCreated $event): void
    {
        $this->seedDefaultRoles->handle($event->tenant);
    }
}
