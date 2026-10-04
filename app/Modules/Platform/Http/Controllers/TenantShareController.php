<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Role;
use App\Modules\Platform\Actions\DecideTenantShare;
use App\Modules\Platform\Actions\SaveTenantShare;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The superadmin sets what a company may see of each other company (cross-company sharing).
 * From the platform tenant only (not while impersonating), with platform.tenants.
 */
class TenantShareController extends Controller
{
    public function edit(Request $request, Tenant $tenant, Impersonation $impersonation, TenantContext $context): Response
    {
        $this->authorizePlatform($request, $tenant, $impersonation);

        $shares = TenantShare::where('from_tenant_id', $tenant->id)->get()->keyBy('to_tenant_id');
        $roles = $context->run($tenant, fn () => Role::orderBy('name')->get(['name', 'label']))
            ->reject(fn (Role $role) => $role->name === 'customer_it')
            ->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label ?? $role->name])
            ->values()
            ->concat(array_map(fn (string $name) => ['name' => $name, 'label' => __("platform.shares.central_roles.{$name}")], TenantShare::CENTRAL_ROLES));

        return Inertia::render('Platform/Tenants/Shares', [
            'tenant' => $tenant->only(['ulid', 'name']),
            'companies' => Tenant::query()
                ->where('is_platform', false)
                ->whereKeyNot($tenant->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $to) => [
                    ...$to->only(['ulid', 'name']),
                    'share' => ($share = $shares->get($to->id)) ? [
                        ...$share->only(['id', 'abilities', 'roles', 'status', 'reason', 'granted_by_name', 'accepted_by_name', 'revoked_by_name']),
                        'expires_on' => $share->expires_on?->toDateString(),
                        'accepted_at' => $share->accepted_at?->toIso8601String(),
                        'revoked_at' => $share->revoked_at?->toIso8601String(),
                    ] : null,
                ]),
            'roles' => $roles,
            'abilities' => TenantShare::ABILITIES,
        ]);
    }

    public function update(Request $request, Tenant $tenant, Tenant $to, Impersonation $impersonation, SaveTenantShare $save): RedirectResponse
    {
        $this->authorizePlatform($request, $tenant, $impersonation);
        abort_if($to->is_platform || $to->is($tenant), 404);

        $data = $request->validate([
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(TenantShare::ABILITIES)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'activate' => ['boolean'],
        ], attributes: __('platform.shares.fields'));

        $save->handle($tenant, $to, [...$data, 'activate' => (bool) ($data['activate'] ?? false)], $request->user());

        return back()->with('success', __('platform.shares.saved', ['from' => $tenant->name, 'to' => $to->name]));
    }

    public function revoke(Request $request, Tenant $tenant, TenantShare $share, Impersonation $impersonation, DecideTenantShare $decide): RedirectResponse
    {
        $this->authorizePlatform($request, $tenant, $impersonation);
        abort_unless($share->from_tenant_id === $tenant->id, 404);

        $decide->handle($share, 'revoke', $request->user());

        return back()->with('success', __('platform.shares.revoked'));
    }

    private function authorizePlatform(Request $request, Tenant $tenant, Impersonation $impersonation): void
    {
        abort_if($tenant->is_platform, 404);
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
