<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "module:{key}": 404 when the current tenant has the module switched off,
 * as if the pages did not exist.
 */
class EnsureModuleEnabled
{
    public function __construct(private Modules $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->enabled($module), 404);

        return $next($request);
    }
}
