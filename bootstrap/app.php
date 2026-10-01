<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Platform\Http\Middleware\LogImpersonatedRequests;
use App\Modules\Tenancy\Http\Middleware\EnforceSubscription;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            ResolveTenant::class,
            EnforceSubscription::class,
            LogImpersonatedRequests::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The tenant must be set before route model binding, or {asset} / {user} would be
        // looked up without a tenant (tenant scope = no rows) and every such URL would 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
