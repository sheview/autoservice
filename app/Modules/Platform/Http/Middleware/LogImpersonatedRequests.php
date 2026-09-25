<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a superadmin impersonates a tenant, every request they make is written to that
 * tenant's activity log (the actor's real name is added by the Activity model).
 */
class LogImpersonatedRequests
{
    public function __construct(private Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->impersonation->active() && $request->user() !== null) {
            activity('impersonation')
                ->causedBy($request->user())
                ->event('request')
                ->withProperties([
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'route' => $request->route()?->getName(),
                    'status' => $response->getStatusCode(),
                    'ip' => $request->ip(),
                ])
                ->log($request->method().' /'.ltrim($request->path(), '/'));
        }

        return $response;
    }
}
