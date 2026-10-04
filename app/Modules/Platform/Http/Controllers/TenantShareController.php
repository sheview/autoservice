<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Actions\DecideTenantShare;
use App\Modules\Platform\Actions\SaveTenantShare;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The superadmin sets, for one company's data (the owner), which other companies may see it,
 * who in them (roles and/or named people), and which of the owner's branches (assets).
 * From the platform tenant only (not while impersonating), with platform.tenants.
 */
class TenantShareController extends Controller
{
    public function edit(Request $request, Tenant $tenant, Impersonation $impersonation, TenantContext $context): Response
    {
        $this->authorizePlatform($request, $tenant, $impersonation);

        $shares = TenantShare::where('to_tenant_id', $tenant->id)->get()->keyBy('from_tenant_id');
        $platform = Tenant::where('is_platform', true)->first();
        // Central staff work inside any company: offered to every one of them.
        $central = $platform ? $context->run($platform, fn () => User::role(TenantShare::CENTRAL_ROLES)->where('is_active', true)->orderBy('name')->get(['id', 'name'])) : collect();

        return Inertia::render('Platform/Tenants/Shares', [
            'tenant' => $tenant->only(['ulid', 'name']),
            'branches' => $context->run($tenant, fn () => Branch::orderBy('name')->get(['id', 'name'])),
            'abilities' => TenantShare::ABILITIES,
            'companies' => Tenant::query()
                ->where('is_platform', false)
                ->whereKeyNot($tenant->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $viewer) => [
                    ...$viewer->only(['ulid', 'name']),
                    'share' => ($share = $shares->get($viewer->id)) ? [
                        ...$share->only(['id', 'abilities', 'roles', 'user_ids', 'branch_ids', 'status', 'reason', 'granted_by_name', 'accepted_by_name', 'revoked_by_name']),
                        'expires_on' => $share->expires_on?->toDateString(),
                    ] : null,
                    ...$context->run($viewer, fn () => [
                        'roles' => Role::where('name', '!=', 'customer_it')->orderBy('name')->get(['name', 'label'])
                            ->map(fn (Role $role) => ['name' => $role->name, 'label' => $role->label ?? $role->name])
                            ->concat(array_map(fn (string $name) => ['name' => $name, 'label' => __("platform.shares.central_roles.{$name}")], TenantShare::CENTRAL_ROLES))
                            ->values(),
                        'people' => User::whereNull('customer_id')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                            ->map(fn (User $user) => $user->only(['id', 'name']))
                            ->concat($central->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name.' ('.__('platform.shares.central').')']))
                            ->values(),
                    ]),
                ]),
        ]);
    }

    /** Lets company $viewer see $tenant's data. */
    public function update(Request $request, Tenant $tenant, Tenant $viewer, Impersonation $impersonation, SaveTenantShare $save): RedirectResponse
    {
        $this->authorizePlatform($request, $tenant, $impersonation);
        abort_if($viewer->is_platform || $viewer->is($tenant), 404);

        $data = $request->validate([
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(TenantShare::ABILITIES)],
            // Who: roles, people, or both; at least one of them.
            'roles' => ['array', 'required_without:user_ids'],
            'roles.*' => ['string', 'max:100'],
            'user_ids' => ['array', 'required_without:roles'],
            'user_ids.*' => ['integer'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'activate' => ['boolean'],
        ], attributes: __('platform.shares.fields'));

        $save->handle($viewer, $tenant, [
            ...$data,
            'roles' => $data['roles'] ?? [],
            'user_ids' => $data['user_ids'] ?? [],
            'branch_ids' => $data['branch_ids'] ?? [],
            'activate' => (bool) ($data['activate'] ?? false),
        ], $request->user());

        return back()->with('success', __('platform.shares.saved', ['from' => $viewer->name, 'to' => $tenant->name]));
    }

    public function revoke(Request $request, Tenant $tenant, TenantShare $share, Impersonation $impersonation, DecideTenantShare $decide): RedirectResponse
    {
        $this->authorizePlatform($request, $tenant, $impersonation);
        abort_unless($share->to_tenant_id === $tenant->id, 404);

        $decide->handle($share, 'revoke', $request->user());

        return back()->with('success', __('platform.shares.revoked'));
    }

    private function authorizePlatform(Request $request, Tenant $tenant, Impersonation $impersonation): void
    {
        abort_if($tenant->is_platform, 404);
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
