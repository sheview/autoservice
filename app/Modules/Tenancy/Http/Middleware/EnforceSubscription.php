<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Support\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the subscription of the current tenant (see Subscription) to every web request:
 *   - read only (not started yet, or in the grace period after the end): anything that is not a
 *     GET is sent back with a message, so the data can be looked at but not changed
 *   - locked (the grace period is over): the company's users only get the "expired" page
 * A superadmin working inside the tenant is not held back (to fix things); central staff see a
 * locked company but cannot change it. Signing out always works.
 */
class EnforceSubscription
{
    /** Routes that work whatever the subscription says. */
    private const ALWAYS_ALLOWED = ['logout', 'platform.impersonation.destroy'];

    public function __construct(
        private TenantContext $context,
        private Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();

        if ($tenant === null || $tenant->is_platform || $request->user() === null
            || $this->impersonation->fullAccess() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $subscription = Subscription::of($tenant);

        if ($subscription['locked'] && ! $this->impersonation->active()) {
            return Inertia::render('Subscription/Locked', [
                'company' => $tenant->name,
                'subscription' => $subscription,
            ])->toResponse($request)->setStatusCode(403);
        }

        if (($subscription['read_only'] || $subscription['locked']) && ! $request->isMethodSafe()) {
            $message = $subscription['state'] === Subscription::NOT_STARTED
                ? __('platform.subscription.not_started', ['date' => $subscription['starts_on']])
                : __('platform.subscription.read_only', ['date' => $subscription['read_only_until']]);

            return back()->with('error', $message);
        }

        return $next($request);
    }
}
