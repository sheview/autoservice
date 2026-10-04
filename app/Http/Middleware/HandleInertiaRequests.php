<?php

namespace App\Http\Middleware;

use App\Modules\Platform\Support\Impersonation;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Support\Subscription;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $impersonation = app(Impersonation::class);
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();
        $permissions = fn () => match (true) {
            $user === null => [],
            $impersonation->active() => $impersonation->permissions(),
            default => $user->getAllPermissions()->pluck('name')->values()->all(),
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                // The UI uses these only to show or hide things; the server always checks again.
                'permissions' => $permissions,
            ],
            // Sidebar from config/modules.php, filtered by permission and the tenant's modules.
            'navigation' => fn () => $user ? app(Modules::class)->navigation($permissions(), $user->customer_id !== null, $user) : [],
            'tenant' => $tenant ? ['name' => $tenant->name, 'is_platform' => $tenant->is_platform] : null,
            'impersonation' => $impersonation->active() ? ['tenant' => ['name' => $impersonation->tenant()->name]] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // The company's subscription, for the warning popup and banner. Customer accounts are
            // not told about their MA company's contract with the platform.
            'subscription' => fn () => $tenant && ! $tenant->is_platform && $user && $user->customer_id === null
                ? Subscription::of($tenant)
                : null,
            'locale' => app()->getLocale(),
            'translations' => fn () => trans('ui'),
        ];
    }
}
