<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant for the request:
 *   1. from the subdomain ({sub}.{central domain}) if there is one
 *   2. otherwise from the logged-in user's tenant_id
 *   3. otherwise no tenant (tenant tables return no rows)
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): ?Tenant
    {
        $userTenantId = $request->user()?->tenant_id;
        $subdomain = $this->subdomain($request->getHost());

        if ($subdomain !== null) {
            $tenant = Tenant::where('subdomain', $subdomain)->first();

            abort_if($tenant === null, 404);
            abort_unless($tenant->isActive(), 403);
            // A user of tenant A must never work inside tenant B's subdomain.
            abort_if($request->user() !== null && $userTenantId !== $tenant->id, 403);

            return $tenant;
        }

        if ($userTenantId === null) {
            return null;
        }

        $tenant = Tenant::find($userTenantId);
        abort_unless($tenant?->isActive(), 403);

        return $tenant;
    }

    private function subdomain(string $host): ?string
    {
        foreach (config('tenancy.central_domains') as $central) {
            if (Str::endsWith($host, '.'.$central)) {
                $sub = Str::beforeLast($host, '.'.$central);

                return Str::contains($sub, '.') ? null : $sub;
            }
        }

        return null;
    }
}
