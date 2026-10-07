<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SetStaffCompany;
use App\Modules\Platform\CrossTenant\CompanyStaff;
use App\Modules\Platform\Models\StaffPool;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "ช่างหลายบริษัท": the platform sets which companies each person can work in (LinkedAccounts).
 */
class LinkedStaffController extends Controller
{
    public function index(Request $request, CompanyStaff $staff, Impersonation $impersonation): Response
    {
        $this->authorizePlatform($request, $impersonation);

        $tenants = Tenant::where('is_platform', false)->orderBy('name')->get(['id', 'ulid', 'name']);
        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'tenant_id' => $tenants->contains('id', $request->integer('tenant_id')) ? $request->integer('tenant_id') : null,
        ];

        $roleLabels = array_column(StaffPoolController::roleOptions(), 'label', 'name');

        return Inertia::render('Platform/LinkedStaff/Index', [
            'pools' => StaffPool::with('fromTenant:id,name', 'toTenant:id,name')->orderBy('id')->get()
                ->map(fn (StaffPool $pool) => [
                    'id' => $pool->id,
                    'from' => $pool->fromTenant?->name,
                    'to' => $pool->toTenant?->name,
                    'roles' => $pool->roles,
                    'role_labels' => array_map(fn (string $role) => $roleLabels[$role] ?? $role, $pool->roles),
                    'is_active' => $pool->is_active,
                ]),
            'roleOptions' => StaffPoolController::roleOptions(),
            'staff' => $staff->directory($filters['search'], $filters['tenant_id']),
            'tenants' => $tenants,
            'filters' => $filters,
        ]);
    }

    public function update(Request $request, int $user, Tenant $tenant, CompanyStaff $staff, SetStaffCompany $setCompany, Impersonation $impersonation): RedirectResponse
    {
        $this->authorizePlatform($request, $impersonation);

        $main = $staff->main($user);
        abort_if($main === null, 404);

        $on = $request->validate(['active' => ['required', 'boolean']])['active'];
        $setCompany->handle($main, $tenant, (bool) $on);

        return back()->with('success', __($on ? 'platform.linked_staff.enabled' : 'platform.linked_staff.disabled', [
            'name' => $main->name, 'company' => $tenant->name,
        ]));
    }

    private function authorizePlatform(Request $request, Impersonation $impersonation): void
    {
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
