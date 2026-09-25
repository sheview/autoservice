<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Listeners\SeedRolesForNewTenant;
use App\Modules\Platform\CrossTenant\TenantAwareUserProvider;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Events\TenantCreated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('tenant-eloquent', fn ($app, array $config) => new TenantAwareUserProvider($app['hash'], $config['model']));

        Event::listen(TenantCreated::class, SeedRolesForNewTenant::class);

        // A superadmin working inside a customer tenant may do everything there.
        // Everything they do is logged with their real name (see Platform module).
        Gate::before(fn ($user) => $this->app->make(Impersonation::class)->active() ? true : null);
    }
}
