<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Actions\DeleteStaffPool;
use App\Modules\Platform\Actions\SaveStaffPool;
use App\Modules\Platform\Models\StaffPool;
use App\Modules\Platform\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff shared between companies ("ช่างของ A ทำงานให้ B ได้ทุกคน"), on the "ช่างหลายบริษัท" page.
 */
class StaffPoolController extends Controller
{
    /** Roles a pool may share: the company roles, not the customer accounts'. */
    public static function roleOptions(): array
    {
        return collect(PermissionCatalog::DEFAULT_ROLES)
            ->except(PermissionCatalog::CUSTOMER_ROLE)
            ->map(fn (array $role, string $name) => ['name' => $name, 'label' => $role['label']])
            ->values()
            ->all();
    }

    public function store(Request $request, SaveStaffPool $save, Impersonation $impersonation): RedirectResponse
    {
        $this->authorizePlatform($request, $impersonation);

        $customerCompany = Rule::exists('tenants', 'id')->where('is_platform', false)->whereNull('deleted_at');
        $data = $request->validate([
            'from_tenant_id' => ['required', 'integer', $customerCompany],
            'to_tenant_id' => ['required', 'integer', 'different:from_tenant_id', $customerCompany],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_column(self::roleOptions(), 'name'))],
        ], attributes: __('platform.staff_pools.fields'));

        $save->handle(null, $data, $request->user()->name);

        return back()->with('success', __('platform.staff_pools.saved'));
    }

    public function update(Request $request, StaffPool $pool, SaveStaffPool $save, Impersonation $impersonation): RedirectResponse
    {
        $this->authorizePlatform($request, $impersonation);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_column(self::roleOptions(), 'name'))],
            'is_active' => ['required', 'boolean'],
        ], attributes: __('platform.staff_pools.fields'));

        $save->handle($pool, $data, $request->user()->name);

        return back()->with('success', __('platform.staff_pools.saved'));
    }

    public function destroy(Request $request, StaffPool $pool, DeleteStaffPool $delete, Impersonation $impersonation): RedirectResponse
    {
        $this->authorizePlatform($request, $impersonation);

        $delete->handle($pool);

        return back()->with('success', __('platform.staff_pools.deleted'));
    }

    private function authorizePlatform(Request $request, Impersonation $impersonation): void
    {
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
