<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Support\Impersonation;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Impersonation::class);
    }

    public function boot(): void
    {
        // Module switches are per tenant, not per user.
        Feature::resolveScopeUsing(fn () => $this->app->make(TenantContext::class)->tenant());

        foreach (config('modules.toggleable', []) as $key => $module) {
            Feature::define(Modules::feature($key), fn (Tenant $tenant) => ! $tenant->is_platform && (bool) $module['default']);
        }
    }
}
