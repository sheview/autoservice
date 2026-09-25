<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Support\Impersonation;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Impersonation::class);
    }
}
