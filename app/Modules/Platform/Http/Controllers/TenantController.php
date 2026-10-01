<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\CreateTenant;
use App\Modules\Platform\Actions\UpdateTenant;
use App\Modules\Platform\Http\Requests\TenantRequest;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform adds and edits customer companies: name, subdomain, status and the paid period.
 * The list is the impersonation page (ImpersonationController). Needs platform.tenants, from the
 * platform tenant itself.
 */
class TenantController extends Controller
{
    public function create(Request $request, Impersonation $impersonation): Response
    {
        $this->authorizePlatform($request, $impersonation);

        return $this->form(null);
    }

    public function store(TenantRequest $request, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle($request->validated());

        return redirect()->route('platform.impersonation.index')->with('success', __('platform.tenants.created', ['tenant' => $tenant->name]));
    }

    public function edit(Request $request, Tenant $tenant, Impersonation $impersonation): Response
    {
        $this->authorizePlatform($request, $impersonation);
        abort_if($tenant->is_platform, 404);

        return $this->form($tenant);
    }

    public function update(TenantRequest $request, Tenant $tenant, UpdateTenant $updateTenant): RedirectResponse
    {
        abort_if($tenant->is_platform, 404);

        $updateTenant->handle($tenant, $request->validated(), $request->user());

        return redirect()->route('platform.impersonation.index')->with('success', __('platform.tenants.updated', ['tenant' => $tenant->name]));
    }

    private function form(?Tenant $tenant): Response
    {
        return Inertia::render('Platform/Tenants/Form', [
            'tenant' => $tenant ? [
                ...$tenant->only(['ulid', 'name', 'subdomain', 'status']),
                'subscription_starts_on' => $tenant->subscription_starts_on?->toDateString(),
                'subscription_ends_on' => $tenant->subscription_ends_on?->toDateString(),
                'subscription' => Subscription::of($tenant),
            ] : null,
            'statuses' => [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED],
            'warnDays' => Subscription::WARN_DAYS,
            'graceDays' => Subscription::GRACE_DAYS,
        ]);
    }

    private function authorizePlatform(Request $request, Impersonation $impersonation): void
    {
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
